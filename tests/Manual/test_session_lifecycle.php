<?php

declare(strict_types=1);

/**
 * tests/Manual/test_session_lifecycle.php
 *
 * FIX-10 — end-to-end verification via a real WS client against a live
 * server.php, NOT unit tests with MockConnection. This is deliberate:
 * the bug this guards against (see IMPLEMENTATION_STATUS.md FIX-10) was
 * invisible to tests/Manual/test_single_session.php because that file
 * manually performs the exact cleanup step
 * (`unset($worker->userConnections[$userId])`) that production code never
 * did anywhere — masking the real gap instead of catching it. This file
 * exercises the actual onClose -> AuthHandler::handleReconnect() code
 * paths end to end.
 *
 * Covers:
 *   TEST 1: disconnect while NOT in any room -> login again succeeds
 *           (was: permanently blocked with "User already logged in").
 *   TEST 2: disconnect while NOT in any room -> reconnect ALONE (no
 *           login) authenticates the new connection, proven by a
 *           successful create_room immediately after.
 *   TEST 3: concurrent double-login is STILL rejected while the first
 *           connection remains open (regression guard: FIX-10 must not
 *           weaken the single-active-session policy, ADR-001 — it should
 *           only release the slot on genuine disconnect, not allow
 *           simultaneous sessions).
 *
 * Run: php tests/Manual/test_session_lifecycle.php
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Lotto\Infrastructure\Database;

const HARD_TIMEOUT_SECONDS = 30;
$GLOBALS['__serverProcess'] = null;

function hardKillAndExit(string $reason): void
{
    fwrite(STDERR, "\n!!! HARD TIMEOUT: {$reason}\n");
    if (is_resource($GLOBALS['__serverProcess'] ?? null)) {
        proc_terminate($GLOBALS['__serverProcess'], 9);
        proc_close($GLOBALS['__serverProcess']);
    }
    exit(2);
}

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, fn() => hardKillAndExit('script exceeded hard timeout'));
    pcntl_alarm(HARD_TIMEOUT_SECONDS);
}

final class SessionLifecycleClient
{
    private $sock;

    public function __construct(string $host, int $port)
    {
        $this->sock = @fsockopen($host, $port, $errno, $errstr, 5.0);
        if (!$this->sock) {
            throw new \RuntimeException("connect failed: {$errstr} (errno={$errno})");
        }
        $key = base64_encode(random_bytes(16));
        $req = "GET / HTTP/1.1\r\nHost: {$host}:{$port}\r\nUpgrade: websocket\r\n" .
               "Connection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n";
        fwrite($this->sock, $req);
        stream_set_timeout($this->sock, 5);
        $resp = '';
        while (!feof($this->sock)) {
            $line = fgets($this->sock);
            if ($line === false) break;
            $resp .= $line;
            if ($line === "\r\n") break;
        }
        if (strpos($resp, '101') === false) {
            throw new \RuntimeException("WS handshake failed: {$resp}");
        }
    }

    public function send(string $msg): void
    {
        $len = strlen($msg);
        $frame = chr(0x81);
        $maskBit = 0x80;
        if ($len <= 125) {
            $frame .= chr($len | $maskBit);
        } elseif ($len <= 65535) {
            $frame .= chr(126 | $maskBit) . pack('n', $len);
        } else {
            $frame .= chr(127 | $maskBit) . pack('J', $len);
        }
        $mask = random_bytes(4);
        $frame .= $mask;
        for ($i = 0; $i < $len; $i++) {
            $frame .= $msg[$i] ^ $mask[$i % 4];
        }
        fwrite($this->sock, $frame);
    }

    public function recvOrNull(float $timeout = 2.0): ?string
    {
        stream_set_timeout($this->sock, (int)$timeout, (int)(($timeout - (int)$timeout) * 1000000));
        $hdr = fread($this->sock, 2);
        if ($hdr === false || strlen($hdr) < 2) {
            return null;
        }
        $len = ord($hdr[1]) & 0x7F;
        if ($len === 126) {
            $ext = fread($this->sock, 2);
            if ($ext === false || strlen($ext) < 2) return null;
            $len = unpack('n', $ext)[1];
        } elseif ($len === 127) {
            $ext = fread($this->sock, 8);
            if ($ext === false || strlen($ext) < 8) return null;
            $len = unpack('J', $ext)[1];
        }
        $payload = '';
        while (strlen($payload) < $len) {
            $chunk = fread($this->sock, $len - strlen($payload));
            if ($chunk === false || $chunk === '') break;
            $payload .= $chunk;
        }
        return $payload;
    }

    public function close(): void
    {
        if (is_resource($this->sock)) {
            fclose($this->sock);
        }
    }
}

$passed = 0;
$failed = 0;

function check(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  [PASS] {$label}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$label}\n";
    }
}

$db  = new Database();
$pdo = $db->getPdo();
$pdo->exec("DELETE FROM users WHERE username LIKE 'fix10\\_%' ESCAPE '\\'");

$projectRoot = dirname(__DIR__, 2);

$stopDescriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$stopProcess = @proc_open(['php', $projectRoot . '/server.php', 'stop'], $stopDescriptors, $stopPipes, $projectRoot);
if (is_resource($stopProcess)) {
    stream_set_blocking($stopPipes[1], false);
    stream_set_blocking($stopPipes[2], false);
    $stopWaited = 0;
    while ($stopWaited < 5_000_000) {
        @fread($stopPipes[1], 65536);
        @fread($stopPipes[2], 65536);
        if (!proc_get_status($stopProcess)['running']) break;
        usleep(100_000);
        $stopWaited += 100_000;
    }
    foreach ($stopPipes as $p) {
        if (is_resource($p)) fclose($p);
    }
    proc_close($stopProcess);
}

$stdoutFile = sys_get_temp_dir() . '/lotto_session_lifecycle_stdout_' . getmypid() . '.log';
$stderrFile = sys_get_temp_dir() . '/lotto_session_lifecycle_stderr_' . getmypid() . '.log';
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['file', $stdoutFile, 'w'],
    2 => ['file', $stderrFile, 'w'],
];

$process = proc_open(['php', $projectRoot . '/server.php', 'start'], $descriptors, $pipes, $projectRoot);
if (!is_resource($process)) {
    fwrite(STDERR, "Failed to start server.php subprocess\n");
    exit(1);
}
$GLOBALS['__serverProcess'] = $process;
if (isset($pipes[0]) && is_resource($pipes[0])) {
    fclose($pipes[0]);
}

$bound = false;
for ($i = 0; $i < 50; $i++) {
    $status = proc_get_status($process);
    if (!$status['running']) break;
    $probe = @fsockopen('127.0.0.1', 8080, $errno, $errstr, 0.1);
    if ($probe) {
        fclose($probe);
        $bound = true;
        break;
    }
    usleep(100_000);
}

if (!$bound) {
    fwrite(STDERR, "server.php did not bind port 8080 in time\n");
    fwrite(STDERR, "--- stdout ---\n" . @file_get_contents($stdoutFile) . "\n");
    fwrite(STDERR, "--- stderr ---\n" . @file_get_contents($stderrFile) . "\n");
    proc_terminate($process, 9);
    proc_close($process);
    @unlink($stdoutFile);
    @unlink($stderrFile);
    exit(1);
}

try {
    // =========================================================================
    // TEST 1: disconnect while NOT in any room -> login again succeeds
    // =========================================================================
    echo "TEST 1: disconnect (no room) -> login again succeeds (FIX-10)\n";
    $a1 = new SessionLifecycleClient('127.0.0.1', 8080);
    $a1->recvOrNull(); // hello
    $a1->send(json_encode(['action' => 'register', 'username' => 'fix10_user1', 'password' => 'fix10pass123']));
    $reg1 = json_decode($a1->recvOrNull() ?? '', true);
    check(($reg1['type'] ?? null) === 'auth_result', 'register succeeds');
    $a1->close();
    usleep(400_000); // let onClose process

    $b1 = new SessionLifecycleClient('127.0.0.1', 8080);
    $b1->recvOrNull(); // hello
    $b1->send(json_encode(['action' => 'login', 'username' => 'fix10_user1', 'password' => 'fix10pass123']));
    $login1 = json_decode($b1->recvOrNull() ?? '', true);
    check(
        ($login1['type'] ?? null) === 'auth_result',
        'login succeeds after disconnect (was: permanently blocked pre-FIX-10)'
    );
    $b1->close();

    // =========================================================================
    // TEST 2: disconnect while NOT in any room -> reconnect ALONE
    // authenticates the connection (proven via a subsequent create_room).
    // =========================================================================
    echo "\nTEST 2: disconnect (no room) -> reconnect alone authenticates (FIX-10)\n";
    $a2 = new SessionLifecycleClient('127.0.0.1', 8080);
    $a2->recvOrNull();
    $a2->send(json_encode(['action' => 'register', 'username' => 'fix10_user2', 'password' => 'fix10pass123']));
    $reg2 = json_decode($a2->recvOrNull() ?? '', true);
    $token2 = $reg2['session_token'] ?? null;
    check(is_string($token2) && $token2 !== '', 'register: session_token present');
    $a2->close();
    usleep(400_000);

    $b2 = new SessionLifecycleClient('127.0.0.1', 8080);
    $b2->recvOrNull();
    $b2->send(json_encode(['action' => 'reconnect', 'token' => $token2]));
    $b2->recvOrNull(1.0); // no room -> ReconnectService sends nothing; just draining

    $b2->send(json_encode(['action' => 'create_room', 'max_players' => 4, 'password' => '', 'cards_count' => 1]));
    $created2 = json_decode($b2->recvOrNull() ?? '', true);
    check(
        ($created2['type'] ?? null) === 'room_joined',
        'create_room succeeds after reconnect-only (proves $connection->userId was bound, not just userConnections)'
    );
    $b2->close();

    // =========================================================================
    // TEST 3: regression guard — concurrent double-login still rejected
    // while the FIRST connection remains open (FIX-10 must not weaken
    // ADR-001's single-active-session policy).
    // =========================================================================
    echo "\nTEST 3: concurrent double-login still rejected (ADR-001 regression guard)\n";
    $c1 = new SessionLifecycleClient('127.0.0.1', 8080);
    $c1->recvOrNull();
    $c1->send(json_encode(['action' => 'register', 'username' => 'fix10_user3', 'password' => 'fix10pass123']));
    $reg3 = json_decode($c1->recvOrNull() ?? '', true);
    check(($reg3['type'] ?? null) === 'auth_result', 'register succeeds');

    $c2 = new SessionLifecycleClient('127.0.0.1', 8080);
    $c2->recvOrNull();
    $c2->send(json_encode(['action' => 'login', 'username' => 'fix10_user3', 'password' => 'fix10pass123']));
    $dupLogin = json_decode($c2->recvOrNull() ?? '', true);
    check(
        ($dupLogin['type'] ?? null) === 'error',
        'second login rejected while first connection c1 is still open'
    );
    $c1->close();
    $c2->close();
} catch (\Throwable $e) {
    fwrite(STDERR, "Exception during test: " . $e->getMessage() . "\n");
    fwrite(STDERR, "--- server stdout ---\n" . @file_get_contents($stdoutFile) . "\n");
    fwrite(STDERR, "--- server stderr ---\n" . @file_get_contents($stderrFile) . "\n");
    $failed++;
} finally {
    proc_terminate($process, 15);
    $waited = 0;
    while (proc_get_status($process)['running'] && $waited < 3_000_000) {
        usleep(100_000);
        $waited += 100_000;
    }
    if (proc_get_status($process)['running']) {
        proc_terminate($process, 9);
    }
    proc_close($process);
    @unlink($stdoutFile);
    @unlink($stderrFile);

    $pdo->exec("DELETE FROM users WHERE username LIKE 'fix10\\_%' ESCAPE '\\'");
}

if (function_exists('pcntl_alarm')) {
    pcntl_alarm(0);
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "RESULT: {$passed} passed, {$failed} failed\n";
echo str_repeat('=', 60) . "\n";

exit($failed > 0 ? 1 : 0);
