<?php

declare(strict_types=1);

/**
 * tests/Manual/test_protocol_audit.php
 *
 * EPIC-11.5 — Protocol Audit (live WebSocket acceptance tests).
 *
 * Complements the static registry cross-reference in
 * test_protocol_completeness.php and the per-module routing tests
 * (test_auth/lobby/game/admin_packet_routing.php) with EPIC-11.5
 * acceptance criteria not explicitly covered elsewhere:
 *
 *   1. hello with protocol_version immediately after connect
 *   2. Extra unknown fields on valid packets are ignored
 *   3. Authenticated unknown action → error.invalid_json (not auth_required)
 *   4. Missing required fields → structured error, connection stays open
 *   5. error.room_full on live join when room is at max_players
 *   6. Protocol errors never close the connection (ADR-003)
 *
 * Deliberately does NOT re-test rate limiting (test_packet_validation.php),
 * server_full + WS 4001 (test_server_bootstrap.php), or full routing
 * chains (test_*_packet_routing.php).
 *
 * Run: php tests/Manual/test_protocol_audit.php
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Lotto\Core\Constants;

const HARD_TIMEOUT_SECONDS = 45;

$GLOBALS['__serverProcess'] = null;

function hardKillAndExit(string $reason): void
{
    fwrite(STDERR, "\n!!! HARD TIMEOUT: {$reason} (>" . HARD_TIMEOUT_SECONDS . "s)\n");
    if (is_resource($GLOBALS['__serverProcess'] ?? null)) {
        proc_terminate($GLOBALS['__serverProcess'], 9);
        proc_close($GLOBALS['__serverProcess']);
    }
    exit(2);
}

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, function () {
        hardKillAndExit('script exceeded hard timeout');
    });
    pcntl_alarm(HARD_TIMEOUT_SECONDS);
}

final class MiniWSClient
{
    private $sock;
    private bool $closed = false;

    public function __construct(string $host, int $port, float $connectTimeout = 5.0)
    {
        $this->sock = @fsockopen($host, $port, $errno, $errstr, $connectTimeout);
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
            if ($line === false) {
                break;
            }
            $resp .= $line;
            if ($line === "\r\n") {
                break;
            }
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
        $frame = $this->recvFrameOrNull($timeout);
        if ($frame === null || ($frame['opcode'] ?? null) === 0x8) {
            return null;
        }

        return $frame['payload'];
    }

    /** @return array{opcode: int, payload: string}|null */
    public function recvFrameOrNull(float $timeout = 2.0): ?array
    {
        stream_set_timeout($this->sock, (int)$timeout, (int)(($timeout - (int)$timeout) * 1000000));
        $hdr = fread($this->sock, 2);
        if ($hdr === false || strlen($hdr) < 2) {
            return null;
        }
        $opcode = ord($hdr[0]) & 0x0F;
        $b1 = ord($hdr[1]);
        $len = $b1 & 0x7F;
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

        if ($opcode === 0x8) {
            $this->closed = true;
        }

        return ['opcode' => $opcode, 'payload' => $payload];
    }

    public function isClosed(): bool
    {
        return $this->closed || feof($this->sock);
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

function registerAndAuth(MiniWSClient $client, string $username, string $password): void
{
    $client->send(json_encode([
        'action'   => 'register',
        'username' => $username,
        'password' => $password,
    ]));
    $client->recvOrNull();
}

require_once __DIR__ . '/ws_test_harness.php';

$projectRoot = dirname(__DIR__, 2);
wsTestEnsureDatabase($projectRoot);
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

try {
    // =========================================================================
    // TEST 1: hello with protocol_version immediately after connect
    // =========================================================================
    echo "TEST 1: hello with protocol_version on connect\n";
    $c1 = new MiniWSClient('127.0.0.1', $wsPort);
    $data1 = json_decode($c1->recvOrNull() ?? '', true);
    check(($data1['type'] ?? null) === 'hello', 'type=hello');
    check(
        ($data1['protocol_version'] ?? null) === Constants::PROTOCOL_VERSION,
        'protocol_version=' . Constants::PROTOCOL_VERSION
    );
    $c1->close();

    // =========================================================================
    // TEST 2: extra unknown fields on register are ignored (extensibility)
    // =========================================================================
    echo "\nTEST 2: extra unknown fields on valid packet are ignored\n";
    $c2 = new MiniWSClient('127.0.0.1', $wsPort);
    $c2->recvOrNull(); // hello
    $c2->send(json_encode([
        'action'       => 'register',
        'username'     => 'e115_ext_' . bin2hex(random_bytes(4)),
        'password'     => 'e115pass123',
        'future_field' => 'should_be_ignored',
        '_meta'        => ['client' => 'audit-test'],
    ]));
    $data2 = json_decode($c2->recvOrNull() ?? '', true);
    check(($data2['type'] ?? null) === 'auth_result', 'type=auth_result despite extra fields');
    check(($data2['success'] ?? null) === true, 'register succeeded with extra fields');
    check(!$c2->isClosed(), 'connection open after register with extra fields');
    $c2->close();

    // =========================================================================
    // TEST 3: authenticated unknown action → error.invalid_json
    // =========================================================================
    echo "\nTEST 3: authenticated unknown action -> error.invalid_json\n";
    $c3 = new MiniWSClient('127.0.0.1', $wsPort);
    $c3->recvOrNull(); // hello
    registerAndAuth($c3, 'e115_unk_' . bin2hex(random_bytes(4)), 'e115pass123');
    $c3->send(json_encode(['action' => 'totally_unknown_action_xyz']));
    $data3 = json_decode($c3->recvOrNull() ?? '', true);
    check(($data3['type'] ?? null) === 'error', 'type=error');
    check(($data3['code'] ?? null) === 'error.invalid_json', 'code=error.invalid_json (not auth_required)');
    check(str_contains($data3['message'] ?? '', 'Unknown'), 'message mentions unknown action');
    check(!$c3->isClosed(), 'connection open after unknown action error');
    $c3->close();

    // =========================================================================
    // TEST 4: missing action field → error.invalid_json, connection stays open
    // =========================================================================
    echo "\nTEST 4: missing action field -> error.invalid_json, connection stays open\n";
    $c4 = new MiniWSClient('127.0.0.1', $wsPort);
    $c4->recvOrNull(); // hello
    $c4->send(json_encode(['username' => 'orphan_field']));
    $data4 = json_decode($c4->recvOrNull() ?? '', true);
    check(($data4['code'] ?? null) === 'error.invalid_json', 'code=error.invalid_json');
    check(!$c4->isClosed(), 'connection open after missing action');
    $c4->close();

    // =========================================================================
    // TEST 5: register missing username → auth error, connection stays open
    // =========================================================================
    echo "\nTEST 5: register without username -> auth error, connection stays open\n";
    $c5 = new MiniWSClient('127.0.0.1', $wsPort);
    $c5->recvOrNull(); // hello
    $c5->send(json_encode(['action' => 'register', 'password' => 'e115pass123']));
    $data5 = json_decode($c5->recvOrNull() ?? '', true);
    check(($data5['type'] ?? null) === 'error', 'type=error');
    check(
        in_array($data5['code'] ?? '', ['error.auth_invalid_username', 'error.invalid_json'], true),
        'code is auth or validation error (got: ' . ($data5['code'] ?? 'null') . ')'
    );
    check(!$c5->isClosed(), 'connection open after validation error');
    $c5->close();

    // =========================================================================
    // TEST 6: error.room_full on live join when room at max_players
    // =========================================================================
    echo "\nTEST 6: join_room on full room -> error.room_full\n";
    $host = new MiniWSClient('127.0.0.1', $wsPort);
    $host->recvOrNull(); // hello
    registerAndAuth($host, 'e115_host_' . bin2hex(random_bytes(3)), 'e115pass123');
    $host->send(json_encode([
        'action'      => 'create_room',
        'max_players' => 2,
        'password'    => '',
        'cards_count' => 1,
    ]));
    $roomData = json_decode($host->recvOrNull() ?? '', true);
    $roomId = (int)($roomData['room_id'] ?? 0);
    check($roomId > 0, 'room created for room_full test');

    $joiner1 = new MiniWSClient('127.0.0.1', $wsPort);
    $joiner1->recvOrNull();
    registerAndAuth($joiner1, 'e115_j1_' . bin2hex(random_bytes(3)), 'e115pass123');
    $joiner1->send(json_encode([
        'action'      => 'join_room',
        'room_id'     => $roomId,
        'password'    => '',
        'cards_count' => 1,
    ]));
    $joiner1->recvOrNull(); // room_joined
    $host->recvOrNull();    // player_joined

    $joiner2 = new MiniWSClient('127.0.0.1', $wsPort);
    $joiner2->recvOrNull();
    registerAndAuth($joiner2, 'e115_j2_' . bin2hex(random_bytes(3)), 'e115pass123');
    $joiner2->send(json_encode([
        'action'      => 'join_room',
        'room_id'     => $roomId,
        'password'    => '',
        'cards_count' => 1,
    ]));
    $data6 = json_decode($joiner2->recvOrNull() ?? '', true);
    check(($data6['code'] ?? null) === 'error.room_full', 'code=error.room_full');
    check(!$joiner2->isClosed(), 'connection open after room_full error');

    $host->close();
    $joiner1->close();
    $joiner2->close();

    // =========================================================================
    // TEST 7: unauthenticated lobby action → error.auth_required (EPIC-11.5 §3)
    // =========================================================================
    echo "\nTEST 7: unauthenticated create_room -> error.auth_required\n";
    $c7 = new MiniWSClient('127.0.0.1', $wsPort);
    $c7->recvOrNull(); // hello
    $c7->send(json_encode(['action' => 'create_room', 'max_players' => 4, 'password' => '', 'cards_count' => 1]));
    $data7 = json_decode($c7->recvOrNull() ?? '', true);
    check(($data7['code'] ?? null) === 'error.auth_required', 'code=error.auth_required');
    check(!$c7->isClosed(), 'connection open after auth_required');
    $c7->close();

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
    wsTestCleanupDatabase();
}

if (function_exists('pcntl_alarm')) {
    pcntl_alarm(0);
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "RESULT: {$passed} passed, {$failed} failed\n";
echo str_repeat('=', 60) . "\n";

exit($failed > 0 ? 1 : 0);
