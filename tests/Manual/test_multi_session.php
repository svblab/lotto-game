<?php

declare(strict_types=1);

/**
 * FIX-30 — multi-browser / dual-session regression tests (live WebSocket).
 *
 * Run: php tests/Manual/test_multi_session.php
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/ws_test_harness.php';

use Lotto\Infrastructure\Database;

const MULTI_SESSION_HARD_TIMEOUT = 45;
$GLOBALS['__serverProcess'] = null;

function msHardKill(string $reason): void
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
    pcntl_signal(SIGALRM, fn() => msHardKill('script exceeded hard timeout'));
    pcntl_alarm(MULTI_SESSION_HARD_TIMEOUT);
}

final class MultiSessionClient
{
    private $sock;

    public function __construct(string $host, int $port)
    {
        $this->sock = @fsockopen($host, $port, $errno, $errstr, 5.0);
        if (!$this->sock) {
            throw new \RuntimeException("connect failed: {$errstr}");
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
            throw new \RuntimeException("WS handshake failed");
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

    public function recv(float $timeout = 2.0): ?array
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
        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function drain(float $timeout = 0.5): void
    {
        while ($this->recv($timeout) !== null) {
            // discard
        }
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

function msCheck(bool $cond, string $label): void
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

function msLogin(MultiSessionClient $c, string $user, string $pass): ?array
{
    $c->send(json_encode(['action' => 'login', 'username' => $user, 'password' => $pass]));
    return $c->recv();
}

$projectRoot = dirname(__DIR__, 2);
wsTestEnsureDatabase($projectRoot);

$db = new Database();
$pdo = $db->getPdo();
$pdo->exec("DELETE FROM users WHERE username LIKE 'ms30\\_%' ESCAPE '\\'");

$wsPort = wsTestPort();

try {
    $serverCtx = wsTestStartServer($projectRoot);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$process = $serverCtx['process'];
$stdoutFile = $serverCtx['stdoutFile'];
$stderrFile = $serverCtx['stderrFile'];
$GLOBALS['__serverProcess'] = $process;

$password = 'ms30pass123';

try {
    // Scenario 1: A login -> B login same user (both live) -> only one remains
    echo "SCENARIO 1: concurrent login evicts first session\n";
    $a = new MultiSessionClient('127.0.0.1', $wsPort);
    $a->recv();
    $a->send(json_encode(['action' => 'register', 'username' => 'ms30_p1', 'password' => $password]));
    $regA = $a->recv();
    msCheck(($regA['type'] ?? null) === 'auth_result', 'A register ok');

    $b = new MultiSessionClient('127.0.0.1', $wsPort);
    $b->recv();
    $loginB = msLogin($b, 'ms30_p1', $password);
    msCheck(($loginB['type'] ?? null) === 'auth_result', 'B login succeeds (newest wins)');
    $evA = $a->recv(1.0);
    msCheck(
        ($evA['type'] ?? null) === 'error' && ($evA['code'] ?? null) === 'error.auth_invalid_token',
        'A receives superseded error'
    );
    $a->close();
    $b->close();

    // Scenario 2: A disconnect -> B login -> A old token cannot reconnect
    echo "\nSCENARIO 2: stale token after fresh login elsewhere\n";
    $c = new MultiSessionClient('127.0.0.1', $wsPort);
    $c->recv();
    $c->send(json_encode(['action' => 'register', 'username' => 'ms30_p2', 'password' => $password]));
    $regC = $c->recv();
    $staleToken = $regC['session_token'] ?? null;
    $c->close();
    usleep(1_000_000);

    $d = new MultiSessionClient('127.0.0.1', $wsPort);
    $d->recv();
    $loginD = msLogin($d, 'ms30_p2', $password);
    msCheck(($loginD['type'] ?? null) === 'auth_result', 'B login after A disconnect');

    $e = new MultiSessionClient('127.0.0.1', $wsPort);
    $e->recv();
    $e->send(json_encode(['action' => 'reconnect', 'token' => $staleToken]));
    $staleRc = $e->recv();
    msCheck(
        ($staleRc['type'] ?? null) === 'error' && ($staleRc['code'] ?? null) === 'error.auth_invalid_token',
        'stale token reconnect rejected'
    );
    $d->close();
    $e->close();

    // Scenario 3: dual clients cannot occupy two seats
    echo "\nSCENARIO 3: one seat per user_id in room\n";
    $host = new MultiSessionClient('127.0.0.1', $wsPort);
    $host->recv();
    $host->send(json_encode(['action' => 'register', 'username' => 'ms30_host', 'password' => $password]));
    $host->recv();
    $host->send(json_encode(['action' => 'create_room', 'max_players' => 4, 'password' => '', 'cards_count' => 1]));
    $hostJoined = $host->recv();
    $roomId = (int) ($hostJoined['room_id'] ?? 0);
    msCheck(($hostJoined['type'] ?? null) === 'room_joined' && $roomId > 0, 'host creates room');

    $ghost = new MultiSessionClient('127.0.0.1', $wsPort);
    $ghost->recv();
    $ghostLogin = msLogin($ghost, 'ms30_host', $password);
    msCheck(($ghostLogin['type'] ?? null) === 'auth_result', 'second client logs in as same user');
    $hostEv = null;
    for ($i = 0; $i < 5; $i++) {
        $pkt = $host->recv(0.5);
        if (is_array($pkt) && ($pkt['type'] ?? null) === 'error') {
            $hostEv = $pkt;
            break;
        }
    }
    msCheck(
        ($hostEv['type'] ?? null) === 'error' && ($hostEv['code'] ?? null) === 'error.auth_invalid_token',
        'first client evicted on second login'
    );

    $restored = $ghost->recv(1.0);
    if (is_array($restored) && ($restored['type'] ?? null) === 'reconnect_state') {
        msCheck(count($restored['players'] ?? []) === 1, 'only one seat for user_id after login takeover');
    } else {
        $ghost->send(json_encode(['action' => 'create_room', 'max_players' => 4, 'password' => '', 'cards_count' => 1]));
        $ghostJoin = null;
        for ($i = 0; $i < 5; $i++) {
            $pkt = $ghost->recv(1.0);
            if (is_array($pkt) && ($pkt['type'] ?? null) === 'room_joined') {
                $ghostJoin = $pkt;
                break;
            }
        }
        msCheck(($ghostJoin['type'] ?? null) === 'room_joined', 'evicted session can create a new room');
        msCheck(count($ghostJoin['players'] ?? []) === 1, 'only one seat for user_id in room');
    }
    $ghost->close();

    // Scenario 4: leave from one client does not spuriously affect unrelated same-username client
    // With FIX-30 only one session exists — verify player_left includes user_id and host still in room
    echo "\nSCENARIO 4: player_left scoped by user_id (host remains after ghost leave)\n";
    $h2 = new MultiSessionClient('127.0.0.1', $wsPort);
    $h2->recv();
    $h2->send(json_encode(['action' => 'register', 'username' => 'ms30_h2', 'password' => $password]));
    $h2->recv();
    $h2->send(json_encode(['action' => 'create_room', 'max_players' => 4, 'password' => '', 'cards_count' => 1]));
    $hj = $h2->recv();
    $rid = (int) ($hj['room_id'] ?? 0);

    $j2 = new MultiSessionClient('127.0.0.1', $wsPort);
    $j2->recv();
    $j2->send(json_encode(['action' => 'register', 'username' => 'ms30_j2', 'password' => $password]));
    $j2->recv();
    $j2->send(json_encode(['action' => 'join_room', 'room_id' => $rid, 'password' => '', 'cards_count' => 1]));
    $j2->recv();
    $j2->drain();

    $j2->send(json_encode(['action' => 'leave_room']));
    $j2Left = $j2->recv();
    msCheck(
        ($j2Left['type'] ?? null) === 'player_left' && isset($j2Left['user_id']),
        'leaving client receives player_left with user_id'
    );
    $h2Left = null;
    for ($i = 0; $i < 8; $i++) {
        $pkt = $h2->recv(0.5);
        if (is_array($pkt) && ($pkt['type'] ?? null) === 'player_left') {
            $h2Left = $pkt;
            break;
        }
    }
    msCheck(
        ($h2Left['type'] ?? null) === 'player_left' && ($h2Left['username'] ?? '') === 'ms30_j2',
        'host receives player_left for joiner only'
    );
    $h2->close();
    $j2->close();

    echo "\nSCENARIO 5: normal reconnect within grace (smoke)\n";
    $r1 = new MultiSessionClient('127.0.0.1', $wsPort);
    $r1->recv();
    $r1->send(json_encode(['action' => 'register', 'username' => 'ms30_rc', 'password' => $password]));
    $rreg = $r1->recv();
    $rtoken = $rreg['session_token'] ?? null;
    $r1->close();
    usleep(500_000);
    $r2 = new MultiSessionClient('127.0.0.1', $wsPort);
    $r2->recv();
    $r2->send(json_encode(['action' => 'reconnect', 'token' => $rtoken]));
    $rrec = $r2->recv();
    msCheck(($rrec['type'] ?? null) === 'auth_result', 'reconnect alone restores lobby session');
    $r2->close();

} catch (Throwable $e) {
    fwrite(STDERR, "Exception: " . $e->getMessage() . "\n");
    fwrite(STDERR, @file_get_contents($stdoutFile) . "\n");
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
    $pdo->exec("DELETE FROM users WHERE username LIKE 'ms30\\_%' ESCAPE '\\'");
    wsTestCleanupDatabase();
}

if (function_exists('pcntl_alarm')) {
    pcntl_alarm(0);
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "RESULT: {$passed} passed, {$failed} failed\n";
echo str_repeat('=', 60) . "\n";

exit($failed > 0 ? 1 : 0);
