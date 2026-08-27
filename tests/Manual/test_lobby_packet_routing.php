<?php

declare(strict_types=1);

/**
 * tests/Manual/test_lobby_packet_routing.php
 *
 * EPIC-10.4 Lobby packet routing — верификация того, что room_list/
 * create_room/join_room/leave_room реально подключены к LobbyHandler через
 * живой server.php (не юнит-тест LobbyService — это уже покрыто
 * test_lobby_integration.php через MockConnection).
 *
 * Критическая проверка: guard «Already in a room» в router'е (server.php)
 * блокирует повторный create_room/join_room для соединения, уже сидящего
 * в комнате — делегировано из LobbyService::handleCreateRoom().
 *
 * Запуск: php tests/Manual/test_lobby_packet_routing.php
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

function registerAndAuth(MiniWSClient $client, string $username, string $password): void
{
    $client->recvOrNull(); // hello
    $client->send(json_encode(['action' => 'register', 'username' => $username, 'password' => $password]));
    $data = json_decode($client->recvOrNull() ?? '', true);
    if (($data['type'] ?? null) !== 'auth_result') {
        throw new \RuntimeException("register failed for {$username}: " . json_encode($data));
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

require_once __DIR__ . '/ws_test_harness.php';

$projectRoot = dirname(__DIR__, 2);
wsTestEnsureDatabase($projectRoot);

$db  = new Database();
$pdo = $db->getPdo();
$pdo->exec("DELETE FROM users WHERE username LIKE 'e104\\_%' ESCAPE '\\'");

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
    // TEST 1: register + create_room -> room_joined
    // =========================================================================
    echo "TEST 1: register + create_room -> room_joined (EPIC-10.4)\n";
    $host = new MiniWSClient('127.0.0.1', $wsPort);
    registerAndAuth($host, 'e104_host', 'e104pass123');
    $host->send(json_encode(['action' => 'create_room', 'max_players' => 4, 'password' => '', 'cards_count' => 1]));
    $data1 = wsRecvOfType($host, 'room_joined');
    check(($data1['type'] ?? null) === 'room_joined', 'type=room_joined');
    check(isset($data1['room_id']), 'room_id присутствует');
    check(($data1['status'] ?? null) === 'waiting', 'status=waiting');
    check(($data1['bank'] ?? null) === 0, 'bank=0');
    // Lobby host username is client-visible only when ≥2 players are seated
    // (LobbyHostService::resolveLobbyHostUsername / ADR lobby-AFK host).
    check(($data1['host'] ?? null) === '', 'host empty with 1 player (assigned at ≥2)');
    check(count($data1['players'] ?? []) === 1, 'players count=1');
    $roomId = (int)($data1['room_id'] ?? 0);
    wsDrainBrief($host);

    // =========================================================================
    // TEST 2: room_list содержит созданную комнату
    // =========================================================================
    echo "\nTEST 2: room_list -> комната в списке\n";
    $host->send(json_encode(['action' => 'room_list']));
    $data2 = wsRecvOfType($host, 'room_list');
    check(($data2['type'] ?? null) === 'room_list', 'type=room_list');
    $found = false;
    foreach ($data2['rooms'] ?? [] as $entry) {
        if (($entry['room_id'] ?? null) === $roomId) {
            $found = true;
            check(($entry['players'] ?? null) === 1, 'room_list entry players=1');
            check(($entry['status'] ?? null) === 'waiting', 'room_list entry status=waiting');
            break;
        }
    }
    check($found, 'созданная комната найдена в room_list');

    // =========================================================================
    // TEST 3: второй игрок join_room -> room_joined
    // =========================================================================
    echo "\nTEST 3: join_room -> room_joined для второго игрока\n";
    $joiner = new MiniWSClient('127.0.0.1', $wsPort);
    registerAndAuth($joiner, 'e104_joiner', 'e104pass123');
    $joiner->send(json_encode(['action' => 'join_room', 'room_id' => $roomId, 'password' => '', 'cards_count' => 2]));
    $data3 = wsRecvOfType($joiner, 'room_joined');
    check(($data3['type'] ?? null) === 'room_joined', 'joiner type=room_joined');
    check(count($data3['players'] ?? []) === 2, 'joiner видит 2 игроков');

    $hostMsg = wsRecvOfType($host, 'player_joined');
    check(($hostMsg['type'] ?? null) === 'player_joined', 'host получил player_joined');
    check(($hostMsg['username'] ?? null) === 'e104_joiner', 'player_joined username верный');
    check(($hostMsg['cards_count'] ?? null) === 2, 'player_joined cards_count=2');
    wsDrainBrief($host);
    wsDrainBrief($joiner);

    // =========================================================================
    // TEST 4: guard «Already in a room» — повторный create_room
    // =========================================================================
    echo "\nTEST 4: create_room при уже сидящем в комнате -> error.invalid_json\n";
    $host->send(json_encode(['action' => 'create_room', 'max_players' => 4, 'password' => '', 'cards_count' => 1]));
    $data4 = wsRecvIgnoringSync($host);
    check(($data4['code'] ?? null) === 'error.invalid_json', 'code=error.invalid_json');
    check(str_contains($data4['message'] ?? '', 'Already in a room'), 'message содержит Already in a room');

    // =========================================================================
    // TEST 5: guard «Already in a room» — join другой комнаты
    // =========================================================================
    echo "\nTEST 5: join_room при уже сидящем в комнате -> error.invalid_json\n";
    $joiner->send(json_encode(['action' => 'join_room', 'room_id' => $roomId + 999, 'password' => '', 'cards_count' => 1]));
    $data5 = wsRecvIgnoringSync($joiner);
    check(($data5['code'] ?? null) === 'error.invalid_json', 'code=error.invalid_json (already in room)');

    // =========================================================================
    // TEST 6: join несуществующей комнаты -> error.room_not_found
    // =========================================================================
    echo "\nTEST 6: join_room несуществующей комнаты -> error.room_not_found\n";
    $outsider = new MiniWSClient('127.0.0.1', $wsPort);
    registerAndAuth($outsider, 'e104_outsider', 'e104pass123');
    $outsider->send(json_encode(['action' => 'join_room', 'room_id' => 99999, 'password' => '', 'cards_count' => 1]));
    $data6 = wsRecvIgnoringSync($outsider);
    check(($data6['code'] ?? null) === 'error.room_not_found', 'code=error.room_not_found');
    $outsider->close();

    // =========================================================================
    // TEST 7: leave_room — joiner выходит, host получает player_left
    // =========================================================================
    echo "\nTEST 7: leave_room -> player_left для оставшихся\n";
    $joiner->send(json_encode(['action' => 'leave_room']));
    $hostLeft = wsRecvOfType($host, 'player_left');
    check(($hostLeft['type'] ?? null) === 'player_left', 'host получил player_left');
    check(($hostLeft['username'] ?? null) === 'e104_joiner', 'player_left username верный');
    check(($hostLeft['reason'] ?? null) === 'leave', 'player_left reason=leave');
    $joiner->close();

    // =========================================================================
    // TEST 8: room_list без auth -> error.auth_required (guard EPIC-10.2)
    // =========================================================================
    echo "\nTEST 8: room_list без auth -> error.auth_required\n";
    $anon = new MiniWSClient('127.0.0.1', $wsPort);
    $anon->recvOrNull(); // hello
    $anon->send(json_encode(['action' => 'room_list']));
    $data8 = wsRecvIgnoringSync($anon);
    check(($data8['code'] ?? null) === 'error.auth_required', 'code=error.auth_required');
    $anon->close();

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

    $pdo->exec("DELETE FROM users WHERE username LIKE 'e104\\_%' ESCAPE '\\'");
    wsTestCleanupDatabase();
}

if (function_exists('pcntl_alarm')) {
    pcntl_alarm(0);
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "RESULT: {$passed} passed, {$failed} failed\n";
echo str_repeat('=', 60) . "\n";

exit($failed > 0 ? 1 : 0);
