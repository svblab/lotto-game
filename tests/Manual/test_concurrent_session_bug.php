<?php

declare(strict_types=1);

/**
 * EPIC-028.0 — reproduces manual QA dual-browser concurrent-session bug.
 *
 * Sequence (matches 2026-08-07 QA report):
 *   1. Window A: login user X
 *   2. Window B: login user Y (different account)
 *   3. Window A: disconnect (onClose cleanup)
 *   4. Window B: fresh login as user X (revokes A's token)
 *   5. Window A: reconnect with A's ORIGINAL stale token
 *   6. Assert: stale reconnect rejected; at most one live auth for user X
 *   7. Assert: cannot occupy two seats in the same room as user X
 *
 * Run: php tests/Manual/test_concurrent_session_bug.php
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';
require_once __DIR__ . '/ws_test_harness.php';

use Lotto\Infrastructure\Database;

const CSB_HARD_TIMEOUT = 60;
$GLOBALS['__serverProcess'] = null;

function csbHardKill(string $reason): void
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
    pcntl_signal(SIGALRM, fn() => csbHardKill('script exceeded hard timeout'));
    pcntl_alarm(CSB_HARD_TIMEOUT);
}

final class CsbClient
{
    private $sock;

    public function __construct(string $host, int $port)
    {
        $this->sock = @fsockopen($host, $port, $errno, $errstr, 5.0);
        if (!$this->sock) {
            throw new RuntimeException("connect failed: {$errstr}");
        }
        $key = base64_encode(random_bytes(16));
        $req = "GET / HTTP/1.1\r\nHost: {$host}:{$port}\r\nUpgrade: websocket\r\n" .
               "Connection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n";
        fwrite($this->sock, $req);
        stream_set_timeout($this->sock, 5);
        $resp = '';
        while (!feof($this->sock)) {
            $line = fgets($this->sock);
            if ($line === false) {
                break;
            }
            $resp .= $line;
            if ($line === "\r\n") {
                break;
            }
        }
        if (strpos($resp, '101') === false) {
            throw new RuntimeException('WS handshake failed');
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
        stream_set_timeout($this->sock, (int) $timeout, (int) (($timeout - (int) $timeout) * 1000000));
        $hdr = fread($this->sock, 2);
        if ($hdr === false || strlen($hdr) < 2) {
            return null;
        }
        $len = ord($hdr[1]) & 0x7F;
        if ($len === 126) {
            $ext = fread($this->sock, 2);
            if ($ext === false || strlen($ext) < 2) {
                return null;
            }
            $len = unpack('n', $ext)[1];
        } elseif ($len === 127) {
            $ext = fread($this->sock, 8);
            if ($ext === false || strlen($ext) < 8) {
                return null;
            }
            $len = unpack('J', $ext)[1];
        }
        $payload = '';
        while (strlen($payload) < $len) {
            $chunk = fread($this->sock, $len - strlen($payload));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $payload .= $chunk;
        }
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function drain(float $timeout = 0.3): void
    {
        while ($this->recv($timeout) !== null) {
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

function csbCheck(bool $cond, string $label): void
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

function csbRegister(CsbClient $c, string $user, string $pass): ?array
{
    $c->send(json_encode(['action' => 'register', 'username' => $user, 'password' => $pass]));

    return $c->recv();
}

function csbLogin(CsbClient $c, string $user, string $pass): ?array
{
    $c->send(json_encode(['action' => 'login', 'username' => $user, 'password' => $pass]));

    return $c->recv();
}

function csbReconnect(CsbClient $c, string $token): ?array
{
    $c->send(json_encode(['action' => 'reconnect', 'token' => $token]));

    return $c->recv(2.0);
}

$projectRoot = dirname(__DIR__, 2);
wsTestEnsureDatabase($projectRoot);

$db = new Database();
$pdo = $db->getPdo();
$pdo->exec("DELETE FROM users WHERE username LIKE 'csb\\_%' ESCAPE '\\'");

$wsPort = wsTestPort();
$password = 'csbpass123';

// This test's purpose is session eviction, not IP limiting. It legitimately
// opens more than MAX_ACCOUNTS_PER_IP distinct accounts from 127.0.0.1 with
// no X-Forwarded-For. Raise the cap for this subprocess only.
$csbEnv = wsTestApplyServerEnv($projectRoot);
$csbEnv['LOTTO_MAX_ACCOUNTS_PER_IP'] = '50';
putenv('LOTTO_MAX_ACCOUNTS_PER_IP=50');
$_ENV['LOTTO_MAX_ACCOUNTS_PER_IP'] = '50';
$_SERVER['LOTTO_MAX_ACCOUNTS_PER_IP'] = '50';
$GLOBALS['__wsTestEnv'] = $csbEnv;
$csbConfigPath = $GLOBALS['__wsTestConfigPath'] ?? null;
if (is_string($csbConfigPath) && $csbConfigPath !== '') {
    file_put_contents($csbConfigPath, json_encode($csbEnv, JSON_UNESCAPED_SLASHES));
}

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

try {
    echo "SCENARIO 1: QA sequence — stale reconnect after fresh login elsewhere\n";

    $winA = new CsbClient('127.0.0.1', $wsPort);
    $winA->recv();
    $regA = csbRegister($winA, 'csb_userx', $password);
    $tokenA = $regA['session_token'] ?? null;
    csbCheck(($regA['type'] ?? null) === 'auth_result' && is_string($tokenA) && $tokenA !== '', 'A registers user X');

    $winB = new CsbClient('127.0.0.1', $wsPort);
    $winB->recv();
    $regB = csbRegister($winB, 'csb_usery', $password);
    csbCheck(($regB['type'] ?? null) === 'auth_result', 'B registers user Y');

    $winA->close();
    usleep(1_000_000);

    $loginB = csbLogin($winB, 'csb_userx', $password);
    $tokenB = $loginB['session_token'] ?? null;
    csbCheck(($loginB['type'] ?? null) === 'auth_result' && is_string($tokenB) && $tokenB !== $tokenA, 'B fresh-login as user X issues new token');

    $winA2 = new CsbClient('127.0.0.1', $wsPort);
    $winA2->recv();
    $staleRc = csbReconnect($winA2, $tokenA);
    csbCheck(
        ($staleRc['type'] ?? null) === 'error' && ($staleRc['code'] ?? null) === 'error.auth_invalid_token',
        'A stale-token reconnect rejected after B fresh-login'
    );

    echo "\nSCENARIO 2: valid reconnect must evict prior live login (shared-token browsers)\n";

    $live = new CsbClient('127.0.0.1', $wsPort);
    $live->recv();
    $liveReg = csbRegister($live, 'csb_dual', $password);
    $liveToken = $liveReg['session_token'] ?? null;
    csbCheck(is_string($liveToken) && $liveToken !== '', 'live client registers');

    $other = new CsbClient('127.0.0.1', $wsPort);
    $other->recv();
    $otherLogin = csbLogin($other, 'csb_dual', $password);
    csbCheck(($otherLogin['type'] ?? null) === 'auth_result', 'second client fresh-login succeeds');
    $evicted = null;
    for ($i = 0; $i < 6; $i++) {
        $pkt = $live->recv(0.5);
        if (is_array($pkt) && ($pkt['type'] ?? null) === 'error') {
            $evicted = $pkt;
            break;
        }
    }
    csbCheck(
        ($evicted['code'] ?? null) === 'error.auth_invalid_token',
        'prior live client receives superseded error on fresh login'
    );

    $reconn = new CsbClient('127.0.0.1', $wsPort);
    $reconn->recv();
    $reconnPkt = csbReconnect($reconn, $liveToken);
    csbCheck(
        ($reconnPkt['type'] ?? null) === 'error' && ($reconnPkt['code'] ?? null) === 'error.auth_invalid_token',
        'stale token reconnect rejected while other client owns session'
    );

    echo "\nSCENARIO 3: same-browser shared token — reconnect must evict live login\n";

    $tab1 = new CsbClient('127.0.0.1', $wsPort);
    $tab1->recv();
    $tab1Reg = csbRegister($tab1, 'csb_shared', $password);
    $sharedToken = $tab1Reg['session_token'] ?? null;
    csbCheck(is_string($sharedToken) && $sharedToken !== '', 'tab1 registers user X');
    $tab1->close();
    usleep(1_000_000);

    $tab2 = new CsbClient('127.0.0.1', $wsPort);
    $tab2->recv();
    csbRegister($tab2, 'csb_other', $password);
    $tab2LoginX = csbLogin($tab2, 'csb_shared', $password);
    $currentToken = $tab2LoginX['session_token'] ?? null;
    csbCheck(
        ($tab2LoginX['type'] ?? null) === 'auth_result'
        && is_string($currentToken)
        && $currentToken !== $sharedToken,
        'tab2 fresh-login as X updates to current token'
    );

    $tab1b = new CsbClient('127.0.0.1', $wsPort);
    $tab1b->recv();
    $tab1bRc = csbReconnect($tab1b, $currentToken);
    csbCheck(
        in_array($tab1bRc['type'] ?? '', ['auth_result', 'reconnect_state'], true),
        'tab1 reconnect with CURRENT token succeeds'
    );
    $tab2Evicted = null;
    for ($i = 0; $i < 6; $i++) {
        $pkt = $tab2->recv(0.5);
        if (is_array($pkt) && ($pkt['type'] ?? null) === 'error') {
            $tab2Evicted = $pkt;
            break;
        }
    }
    csbCheck(
        ($tab2Evicted['code'] ?? null) === 'error.auth_invalid_token',
        'tab2 live login evicted when tab1 reconnects with same current token'
    );

    $tab1b->send(json_encode(['action' => 'create_room', 'max_players' => 4, 'password' => '', 'cards_count' => 1]));
    $tab1bRoom = $tab1b->recv();
    $roomId = (int) ($tab1bRoom['room_id'] ?? 0);
    csbCheck(($tab1bRoom['type'] ?? null) === 'room_joined' && $roomId > 0, 'tab1b creates room after winning session');

    $tab2->send(json_encode(['action' => 'join_room', 'room_id' => $roomId, 'password' => '', 'cards_count' => 2]));
    $tab2Join = null;
    for ($i = 0; $i < 8; $i++) {
        $pkt = $tab2->recv(1.0);
        if (is_array($pkt) && in_array($pkt['type'] ?? '', ['room_joined', 'error'], true)) {
            $tab2Join = $pkt;
            break;
        }
    }
    $dupSeats = 0;
    if (is_array($tab2Join)) {
        foreach ($tab2Join['players'] ?? [] as $p) {
            if (($p['username'] ?? '') === 'csb_shared') {
                $dupSeats++;
            }
        }
    }
    csbCheck(
        ($tab2Join['type'] ?? null) === 'error' || $dupSeats <= 1,
        'evicted tab2 cannot add duplicate seat (type=' . ($tab2Join['type'] ?? 'null') . ', dupSeats=' . $dupSeats . ')'
    );

    echo "\nSCENARIO 5: production log sequence (fresh login + shared-token reconnect, create+join)\n";

    $prodA = new CsbClient('127.0.0.1', $wsPort);
    $prodA->recv();
    csbRegister($prodA, 'csb_prod2', $password);
    $prodA->close();
    usleep(500_000);

    $prodB = new CsbClient('127.0.0.1', $wsPort);
    $prodB->recv();
    csbRegister($prodB, 'csb_prod1', $password);
    csbLogin($prodB, 'csb_prod2', $password);
    $prodB->close();
    usleep(500_000);

    $prodFresh = new CsbClient('127.0.0.1', $wsPort);
    $prodFresh->recv();
    $freshLogin = csbLogin($prodFresh, 'csb_prod2', $password);
    $currentToken = $freshLogin['session_token'] ?? null;
    csbCheck(
        ($freshLogin['type'] ?? null) === 'auth_result' && is_string($currentToken) && $currentToken !== '',
        'fresh login after both windows closed'
    );

    $prodRc = new CsbClient('127.0.0.1', $wsPort);
    $prodRc->recv();
    $rcOk = csbReconnect($prodRc, $currentToken);
    csbCheck(
        in_array($rcOk['type'] ?? '', ['auth_result', 'reconnect_state'], true),
        'reconnect with current shared token succeeds'
    );
    $freshEvicted = null;
    for ($i = 0; $i < 8; $i++) {
        $pkt = $prodFresh->recv(0.4);
        if (is_array($pkt) && ($pkt['type'] ?? null) === 'error') {
            $freshEvicted = $pkt;
            break;
        }
    }
    csbCheck(
        ($freshEvicted['code'] ?? null) === 'error.auth_invalid_token',
        'fresh-login socket evicted when reconnect claims shared token'
    );

    $prodRc->send(json_encode(['action' => 'create_room', 'max_players' => 4, 'password' => '', 'cards_count' => 1]));
    $prodCreate = $prodRc->recv(2.0);
    $prodRoomId = (int) ($prodCreate['room_id'] ?? 0);
    csbCheck(($prodCreate['type'] ?? null) === 'room_joined' && $prodRoomId > 0, 'reconnect socket creates room');

    $prodFresh2 = new CsbClient('127.0.0.1', $wsPort);
    $prodFresh2->recv();
    $staleJoinLogin = csbLogin($prodFresh2, 'csb_prod2', $password);
    $joinToken = $staleJoinLogin['session_token'] ?? $currentToken;
    csbCheck(($staleJoinLogin['type'] ?? null) === 'auth_result', 'second fresh login for join attempt');
    $prodFresh2->send(json_encode([
        'action' => 'join_room',
        'room_id' => $prodRoomId,
        'password' => '',
        'cards_count' => 2,
    ]));
    $joinPkt = null;
    for ($i = 0; $i < 8; $i++) {
        $pkt = $prodFresh2->recv(1.0);
        if (is_array($pkt) && in_array($pkt['type'] ?? '', ['room_joined', 'error'], true)) {
            $joinPkt = $pkt;
            break;
        }
    }
    $prodSeatCount = 0;
    if (is_array($joinPkt) && ($joinPkt['type'] ?? null) === 'room_joined') {
        foreach ($joinPkt['players'] ?? [] as $p) {
            if (($p['username'] ?? '') === 'csb_prod2') {
                $prodSeatCount++;
            }
        }
    }
    csbCheck(
        $prodSeatCount <= 1,
        'same user_id cannot occupy two seats after create+join (seats=' . $prodSeatCount . ')'
    );

    echo "\nSCENARIO 4: reconnect-before-login — fresh login must evict reconnect session\n";

    $early = new CsbClient('127.0.0.1', $wsPort);
    $early->recv();
    $earlyReg = csbRegister($early, 'csb_order', $password);
    $earlyToken = $earlyReg['session_token'] ?? null;
    $early->close();
    usleep(1_000_000);

    $rc = new CsbClient('127.0.0.1', $wsPort);
    $rc->recv();
    $rcPkt = csbReconnect($rc, $earlyToken);
    csbCheck(
        in_array($rcPkt['type'] ?? '', ['auth_result', 'reconnect_state'], true),
        'reconnect succeeds while token still valid (no intervening login)'
    );

    $late = new CsbClient('127.0.0.1', $wsPort);
    $late->recv();
    $lateLogin = csbLogin($late, 'csb_order', $password);
    csbCheck(($lateLogin['type'] ?? null) === 'auth_result', 'fresh login after reconnect succeeds');
    $rcEvicted = null;
    for ($i = 0; $i < 6; $i++) {
        $pkt = $rc->recv(0.5);
        if (is_array($pkt) && ($pkt['type'] ?? null) === 'error') {
            $rcEvicted = $pkt;
            break;
        }
    }
    csbCheck(
        ($rcEvicted['code'] ?? null) === 'error.auth_invalid_token',
        'reconnect session evicted when fresh login claims account'
    );

    $rc->send(json_encode(['action' => 'create_room', 'max_players' => 4, 'password' => '', 'cards_count' => 1]));
    $rcCreate = $rc->recv(1.0);
    csbCheck(
        $rcCreate === null
        || (
            ($rcCreate['type'] ?? null) === 'error'
            && in_array($rcCreate['code'] ?? '', ['error.auth_invalid_token', 'error.auth_required'], true)
        ),
        'evicted reconnect socket cannot create room afterward'
    );

    $prodFresh->close();
    $prodFresh2->close();
    $prodRc->close();
    $tab1->close();
    $tab2->close();
    $tab1b->close();
    $late->close();
    $live->close();
    $other->close();
    $winB->close();
    $winA2->close();
    $rc->close();
} catch (Throwable $e) {
    fwrite(STDERR, 'Exception: ' . $e->getMessage() . "\n");
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
    $pdo->exec("DELETE FROM users WHERE username LIKE 'csb\\_%' ESCAPE '\\'");
    wsTestCleanupDatabase();
}

if (function_exists('pcntl_alarm')) {
    pcntl_alarm(0);
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "RESULT: {$passed} passed, {$failed} failed\n";
echo str_repeat('=', 60) . "\n";

exit($failed > 0 ? 1 : 0);
