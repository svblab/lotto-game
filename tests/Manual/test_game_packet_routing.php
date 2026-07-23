<?php

declare(strict_types=1);

/**
 * tests/Manual/test_game_packet_routing.php
 *
 * EPIC-10.5 Game packet routing — верификация того, что start_game/
 * draw_barrel/apartment_choice реально подключены к GameHandler через живой
 * server.php (не юнит-тест GameService — это уже покрыто
 * test_game_start.php/test_turn_system.php через MockConnection).
 *
 * Дополнительно покрывает две вещи, впервые подключённые в этом же Epic'е:
 *   - onClose -> ReconnectService::handleDisconnect() (реальный TCP-разрыв,
 *     не MockConnection).
 *   - 'reconnect' action -> ReconnectService::handleReconnect() восстановление
 *     игрового состояния (reconnect_state) И, критически, что реконнекчённое
 *     соединение способно совершить ДЕЙСТВИЕ ПОСЛЕ reconnect (FIX-9 — до
 *     этого фикса запись оставалась под старым conn_id и последующие
 *     хендлеры не находили игрока).
 *
 * Запуск: php tests/Manual/test_game_packet_routing.php
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Lotto\Infrastructure\Database;

const HARD_TIMEOUT_SECONDS = 30;

$GLOBALS['__serverProcess'] = null;

function hardKillAndExit(string $reason): void
{
    fwrite(STDERR, "\n!!! HARD TIMEOUT: {$reason} (>" . HARD_TIMEOUT_SECONDS . "s) — принудительное завершение\n");
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
        stream_set_timeout($this->sock, (int)$timeout, (int)(($timeout - (int)$timeout) * 1000000));
        $hdr = fread($this->sock, 2);
        if ($hdr === false || strlen($hdr) < 2) {
            return null;
        }
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
        return $payload;
    }

    public function close(): void
    {
        if (is_resource($this->sock)) {
            fclose($this->sock);
        }
    }
}

function registerAndAuth(MiniWSClient $client, string $username, string $password): array
{
    $client->recvOrNull(); // hello
    $client->send(json_encode(['action' => 'register', 'username' => $username, 'password' => $password]));
    $data = json_decode($client->recvOrNull() ?? '', true);
    if (($data['type'] ?? null) !== 'auth_result') {
        throw new \RuntimeException("register failed for {$username}: " . json_encode($data));
    }
    return $data;
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
$pdo->exec("DELETE FROM users WHERE username LIKE 'e105\\_%' ESCAPE '\\'");

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
        if (!proc_get_status($stopProcess)['running']) {
            break;
        }
        usleep(100_000);
        $stopWaited += 100_000;
    }
    foreach ($stopPipes as $p) {
        if (is_resource($p)) {
            fclose($p);
        }
    }
    proc_close($stopProcess);
}

$stdoutFile = sys_get_temp_dir() . '/lotto_game_routing_stdout_' . getmypid() . '.log';
$stderrFile = sys_get_temp_dir() . '/lotto_game_routing_stderr_' . getmypid() . '.log';
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
    if (!$status['running']) {
        break;
    }
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
    // Setup: host + p2 register, host creates a 2-player room, p2 joins.
    // =========================================================================
    echo "SETUP: register host + p2, create_room, join_room\n";
    $host = new MiniWSClient('127.0.0.1', 8080);
    registerAndAuth($host, 'e105_host', 'e105pass123');
    $host->send(json_encode(['action' => 'create_room', 'max_players' => 2, 'password' => '', 'cards_count' => 1]));
    $created = json_decode($host->recvOrNull() ?? '', true);
    check(($created['type'] ?? null) === 'room_joined', 'setup: room_joined');
    $roomId = $created['room_id'] ?? null;
    check($roomId !== null, 'setup: room_id present');

    $p2 = new MiniWSClient('127.0.0.1', 8080);
    $p2Auth = registerAndAuth($p2, 'e105_p2', 'e105pass123');
    $p2Token = $p2Auth['session_token'] ?? null;
    check(is_string($p2Token) && $p2Token !== '', 'setup: p2 session_token present');

    $p2->send(json_encode(['action' => 'join_room', 'room_id' => $roomId, 'password' => '', 'cards_count' => 1]));
    $joined = json_decode($p2->recvOrNull() ?? '', true);
    check(($joined['type'] ?? null) === 'room_joined', 'setup: p2 room_joined');
    $host->recvOrNull(); // consume player_joined broadcast to host

    // =========================================================================
    // TEST 1: non-host start_game -> error.not_your_turn
    // =========================================================================
    echo "\nTEST 1: non-host start_game -> error.not_your_turn (EPIC-10.5)\n";
    $p2->send(json_encode(['action' => 'start_game']));
    $data1 = json_decode($p2->recvOrNull() ?? '', true);
    check(($data1['code'] ?? null) === 'error.not_your_turn', 'code=error.not_your_turn');

    // =========================================================================
    // TEST 2: host start_game -> both receive game_started
    // =========================================================================
    echo "\nTEST 2: host start_game -> game_started broadcast to both players\n";
    $host->send(json_encode(['action' => 'start_game']));
    $started1 = json_decode($host->recvOrNull() ?? '', true);
    $started2 = json_decode($p2->recvOrNull() ?? '', true);
    check(($started1['type'] ?? null) === 'game_started', 'host receives game_started');
    check(($started2['type'] ?? null) === 'game_started', 'p2 receives game_started');
    check(($started1['bank'] ?? null) === 20, 'bank=20 (2 players x 1 card x 10 coins)');
    check(($started1['drawer_order'] ?? null) === ['e105_host', 'e105_p2'], 'drawer_order = [host, p2] (host first)');

    // =========================================================================
    // TEST 3: non-drawer draw_barrel -> error.not_your_turn
    // =========================================================================
    echo "\nTEST 3: p2 draw_barrel out of turn -> error.not_your_turn\n";
    $p2->send(json_encode(['action' => 'draw_barrel']));
    $data3 = json_decode($p2->recvOrNull() ?? '', true);
    check(($data3['code'] ?? null) === 'error.not_your_turn', 'code=error.not_your_turn');

    // =========================================================================
    // TEST 4: host (active drawer) draw_barrel -> barrels_drawn to both,
    // then your_turn to p2 (turn rotation).
    // =========================================================================
    echo "\nTEST 4: host draw_barrel -> barrels_drawn broadcast + your_turn to p2\n";
    $host->send(json_encode(['action' => 'draw_barrel']));
    $drawn1 = json_decode($host->recvOrNull() ?? '', true);
    $drawn2 = json_decode($p2->recvOrNull() ?? '', true);
    check(($drawn1['type'] ?? null) === 'barrels_drawn', 'host receives barrels_drawn');
    check(($drawn2['type'] ?? null) === 'barrels_drawn', 'p2 receives barrels_drawn');
    check(count($drawn1['numbers'] ?? []) === 1, 'exactly 1 number drawn');
    check(($drawn1['next_drawer'] ?? null) === 'e105_p2', 'next_drawer=e105_p2');
    $yourTurn = json_decode($p2->recvOrNull() ?? '', true);
    check(($yourTurn['type'] ?? null) === 'your_turn', 'p2 receives your_turn after rotation');

    // =========================================================================
    // TEST 5: apartment_choice with no apartment in progress -> error
    // =========================================================================
    echo "\nTEST 5: apartment_choice без активной 'Квартиры' -> error\n";
    $p2->send(json_encode(['action' => 'apartment_choice', 'choice' => 'agree']));
    $data5 = json_decode($p2->recvOrNull() ?? '', true);
    check(($data5['type'] ?? null) === 'error', 'type=error (apartment not active)');

    // =========================================================================
    // TEST 6: apartment_choice missing 'choice' field -> error.invalid_json
    // (GameHandler router-level validation, EPIC-10.5)
    // =========================================================================
    echo "\nTEST 6: apartment_choice без поля choice -> error.invalid_json\n";
    $p2->send(json_encode(['action' => 'apartment_choice']));
    $data6 = json_decode($p2->recvOrNull() ?? '', true);
    check(($data6['code'] ?? null) === 'error.invalid_json', 'code=error.invalid_json');

    // =========================================================================
    // TEST 7: unauthenticated connection -> draw_barrel blocked generically
    // (spot check of the pre-existing EPIC-10.2 auth_required guard, now
    // that game actions are wired behind it)
    // =========================================================================
    echo "\nTEST 7: unauth draw_barrel -> error.auth_required\n";
    $anon = new MiniWSClient('127.0.0.1', 8080);
    $anon->recvOrNull(); // hello
    $anon->send(json_encode(['action' => 'draw_barrel']));
    $data7 = json_decode($anon->recvOrNull() ?? '', true);
    check(($data7['code'] ?? null) === 'error.auth_required', 'code=error.auth_required');
    $anon->close();

    // =========================================================================
    // TEST 8 (EPIC-10.5 wiring + FIX-9): real TCP disconnect during 'playing'
    // -> onClose delegates ReconnectService::handleDisconnect() -> player
    // marked 'disconnected'. Then a NEW connection sends {action: reconnect,
    // token} -> ReconnectService::handleReconnect() restores state AND
    // (FIX-9) re-keys the room entry so the reconnected connection can
    // actually act afterwards — it is p2's turn at this point (TEST 4 above
    // rotated the turn to p2), so a successful draw_barrel after reconnect
    // proves the new connection id was wired into active_drawer_conn_id/
    // drawer_order/players, not left stranded under the old id.
    // =========================================================================
    echo "\nTEST 8: real disconnect + reconnect mid-game (onClose + FIX-9)\n";
    $p2->close();
    usleep(300_000); // дать серверу время обработать onClose

    $p2New = new MiniWSClient('127.0.0.1', 8080);
    $p2New->recvOrNull(); // hello
    $p2New->send(json_encode(['action' => 'reconnect', 'token' => $p2Token]));
    $reconnectMsg = json_decode($p2New->recvOrNull() ?? '', true);
    check(($reconnectMsg['type'] ?? null) === 'reconnect_state', 'reconnect_state received');
    check(($reconnectMsg['status'] ?? null) === 'playing', 'reconnect_state: status=playing');

    // Всё ещё ход p2 (после TEST 4) — теперь с НОВОГО соединения.
    $p2New->send(json_encode(['action' => 'draw_barrel']));
    $afterReconnectDraw = json_decode($p2New->recvOrNull() ?? '', true);
    check(
        ($afterReconnectDraw['type'] ?? null) === 'barrels_drawn',
        'draw_barrel после reconnect успешен (FIX-9: новый conn_id корректно найден в комнате)'
    );
    $host->recvOrNull(); // consume the same barrels_drawn broadcast on host's socket
    $p2New->close();

    $host->close();
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

    $pdo->exec("DELETE FROM users WHERE username LIKE 'e105\\_%' ESCAPE '\\'");
}

if (function_exists('pcntl_alarm')) {
    pcntl_alarm(0);
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "RESULT: {$passed} passed, {$failed} failed\n";
echo str_repeat('=', 60) . "\n";

exit($failed > 0 ? 1 : 0);
