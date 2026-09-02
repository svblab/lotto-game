#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * scripts/load_test_runner.php
 *
 * EPIC-11.6 — Load testing on Linux/VPS with realistic WebSocket scenarios.
 *
 * Starts server.php with LOTTO_LOAD_AUDIT=1, drives client load, records
 * client RTT metrics to logs/load_client.log, then runs analyze_load_log.php.
 *
 * Usage (VPS):
 *   php scripts/load_test_runner.php [--scenario=ramp|steady|storm|long]
 *       [--players=100] [--games=10] [--duration=300]
 *       [--host=127.0.0.1] [--port=8080]
 *
 * Scenarios (EPIC-11.6 spec):
 *   ramp   — ramp connections 0→target over duration (default 5 min)
 *   steady — maintain target load for duration (default 30 min)
 *   storm  — simultaneous actions from all clients
 *   long   — 50% capacity with continuous activity (default 1 hour)
 *
 * NOTE: Requires Linux (Workerman). On Windows, use test_load_audit.php.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Lotto\Core\Constants;

if (DIRECTORY_SEPARATOR === '\\') {
    fwrite(STDERR, "SKIP: load_test_runner requires Linux/VPS (Workerman).\n");
    fwrite(STDERR, "Run: php tests/Manual/test_load_audit.php for mock-based regression.\n");
    exit(0);
}

$scenario = 'ramp';
$targetPlayers = 100;
$targetGames = 10;
$duration = 300;
$host = '127.0.0.1';
$port = 8080;

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--scenario=(.+)$/', $arg, $m)) {
        $scenario = $m[1];
    } elseif (preg_match('/^--players=(\d+)$/', $arg, $m)) {
        $targetPlayers = (int) $m[1];
    } elseif (preg_match('/^--games=(\d+)$/', $arg, $m)) {
        $targetGames = (int) $m[1];
    } elseif (preg_match('/^--duration=(\d+)$/', $arg, $m)) {
        $duration = (int) $m[1];
    } elseif (preg_match('/^--host=(.+)$/', $arg, $m)) {
        $host = $m[1];
    } elseif (preg_match('/^--port=(\d+)$/', $arg, $m)) {
        $port = (int) $m[1];
    }
}

$validScenarios = ['ramp', 'steady', 'storm', 'long'];
if (!in_array($scenario, $validScenarios, true)) {
    fwrite(STDERR, "Invalid --scenario={$scenario}. Use: " . implode('|', $validScenarios) . "\n");
    exit(2);
}

$targetPlayers = min($targetPlayers, Constants::MAX_TOTAL_PLAYERS);
$targetGames = min($targetGames, Constants::MAX_ROOMS);

$projectRoot = dirname(__DIR__);
$serverScript = $projectRoot . '/server.php';
$logOut = $projectRoot . '/logs/load_test_server.out';
$logErr = $projectRoot . '/logs/load_test_server.err';
$auditLog = $projectRoot . '/logs/load_audit.log';
$clientLog = $projectRoot . '/logs/load_client.log';
$resourceLog = $projectRoot . '/logs/load_resource.log';

@mkdir($projectRoot . '/logs', 0755, true);
if (is_file($auditLog)) {
    unlink($auditLog);
}
if (is_file($clientLog)) {
    unlink($clientLog);
}
if (is_file($resourceLog)) {
    unlink($resourceLog);
}

$auditEnv = [
    'LOTTO_LOAD_AUDIT'     => '1',
    'LOTTO_LOAD_AUDIT_LOG' => $auditLog,
];
$env = array_merge($_ENV, $auditEnv);
$envPrefix = '';
foreach ($auditEnv as $key => $value) {
    $envPrefix .= $key . '=' . escapeshellarg($value) . ' ';
}

$cmd = trim($envPrefix) . ' ' . PHP_BINARY . ' ' . escapeshellarg($serverScript) . ' start';
$descriptor = [
    0 => ['file', '/dev/null', 'r'],
    1 => ['file', $logOut, 'a'],
    2 => ['file', $logErr, 'a'],
];

echo "EPIC-11.6 load test: scenario={$scenario} players={$targetPlayers} games={$targetGames} duration={$duration}s\n";
echo "Starting server with load audit enabled...\n";

$proc = proc_open($cmd, $descriptor, $pipes, $projectRoot, $env);
if (!is_resource($proc)) {
    fwrite(STDERR, "FAIL: could not start server.php\n");
    exit(2);
}

$serverPid = proc_get_status($proc)['pid'] ?? null;

$deadline = time() + 30;
while (time() < $deadline) {
    $sock = @fsockopen($host, $port, $errno, $errstr, 1.0);
    if ($sock) {
        fclose($sock);
        break;
    }
    usleep(200_000);
}

if (!@fsockopen($host, $port, $errno, $errstr, 1.0)) {
    fwrite(STDERR, "FAIL: server did not become ready on {$host}:{$port}\n");
    proc_terminate($proc, 9);
    proc_close($proc);
    exit(2);
}

echo "Server ready.\n";

// proc_open PID is often the shell wrapper; resolve the Workerman listener PID for CPU/mem samples.
$workerPid = resolveWorkerPid($host, $port) ?? $serverPid;
if ($workerPid !== null && $workerPid !== $serverPid) {
    echo "Resolved worker PID {$workerPid} (proc_open PID was " . ($serverPid ?? 'null') . ")\n";
    $serverPid = $workerPid;
}

match ($scenario) {
    'ramp'   => runRamp($host, $port, $targetPlayers, $targetGames, $duration, $clientLog, $resourceLog, $serverPid),
    'steady' => runSteady($host, $port, $targetPlayers, $targetGames, $duration, $clientLog, $resourceLog, $serverPid),
    'storm'  => runStorm($host, $port, $targetPlayers, $targetGames, $clientLog, $resourceLog, $serverPid),
    'long'   => runLong($host, $port, $targetPlayers, $targetGames, $duration, $clientLog, $resourceLog, $serverPid),
};

echo "Stopping server...\n";
proc_terminate($proc, 15);
$waited = 0;
while (proc_get_status($proc)['running'] && $waited < 5_000_000) {
    usleep(100_000);
    $waited += 100_000;
}
proc_close($proc);

echo "Analyzing load logs...\n";
$analyzeExit = 0;
passthru(
    PHP_BINARY . ' ' . escapeshellarg($projectRoot . '/scripts/analyze_load_log.php')
    . ' ' . escapeshellarg($auditLog)
    . ' --client-log=' . escapeshellarg($clientLog)
    . ' --resource-log=' . escapeshellarg($resourceLog),
    $analyzeExit
);
exit($analyzeExit === 0 ? 0 : 1);

/**
 * Best-effort resolve of the process listening on the game port.
 */
function resolveWorkerPid(string $host, int $port): ?int
{
    $ss = @shell_exec('ss -tlnp 2>/dev/null | grep -E \':' . (int) $port . '\\b\'');
    if (is_string($ss) && preg_match('/pid=(\\d+)/', $ss, $m)) {
        return (int) $m[1];
    }

    $pgrep = @shell_exec("pgrep -n -f '[s]erver\\.php' 2>/dev/null");
    if (is_string($pgrep) && preg_match('/^(\\d+)/', trim($pgrep), $m)) {
        return (int) $m[1];
    }

    return null;
}

/**
 * @return list<LoadWsClient>
 */
function connectClients(string $host, int $port, int $count, string $prefix, string $clientLog): array
{
    $clients = [];
    for ($i = 0; $i < $count; $i++) {
        $client = LoadWsClient::connect($host, $port);
        if ($client === null) {
            continue;
        }

        $username = $prefix . '_' . $i;
        $rtt = $client->sendAction([
            'action'   => 'register',
            'username' => $username,
            'password' => 'testpass123',
        ], ['auth_result', 'error', 'banned']);
        if ($rtt !== null) {
            logClientMetric($clientLog, 'register', $rtt);
        }

        $clients[] = $client;
    }

    return $clients;
}

/**
 * @param list<LoadWsClient> $clients
 */
function setupGameRooms(
    array $clients,
    int $targetGames,
    string $clientLog
): array {
    $rooms = [];
    $idx = 0;
    $playersPerRoom = 2;

    for ($g = 0; $g < $targetGames && $idx + $playersPerRoom <= count($clients); $g++) {
        $hostClient = $clients[$idx];
        $rtt = $hostClient->sendAction([
            'action'      => 'create_room',
            'max_players' => 4,
            'cards_count' => 1,
        ], ['room_joined', 'error']);
        if ($rtt !== null) {
            logClientMetric($clientLog, 'create_room', $rtt);
        }

        $roomId = $hostClient->lastRoomId();
        if ($roomId === null) {
            $idx += $playersPerRoom;
            continue;
        }

        $roomClients = [$hostClient];
        for ($p = 1; $p < $playersPerRoom; $p++) {
            $joiner = $clients[$idx + $p];
            $rtt = $joiner->sendAction([
                'action'  => 'join_room',
                'room_id' => $roomId,
            ], ['room_joined', 'error']);
            if ($rtt !== null) {
                logClientMetric($clientLog, 'join_room', $rtt);
            }
            $roomClients[] = $joiner;
        }

        $rtt = $hostClient->sendAction(['action' => 'start_game'], ['game_started', 'error']);
        if ($rtt !== null) {
            logClientMetric($clientLog, 'start_game', $rtt);
        }

        $rooms[] = ['id' => $roomId, 'host' => $hostClient, 'clients' => $roomClients];
        $idx += $playersPerRoom;
    }

    return $rooms;
}

function runRamp(
    string $host,
    int $port,
    int $targetPlayers,
    int $targetGames,
    int $duration,
    string $clientLog,
    string $resourceLog,
    ?int $serverPid
): void {
    $clients = [];
    $interval = $targetPlayers > 0 ? max(1, (int) floor($duration / $targetPlayers)) : 1;
    $start = time();
    $end = $start + $duration;

    while (count($clients) < $targetPlayers && time() < $end) {
        $batch = min(5, $targetPlayers - count($clients));
        $newClients = connectClients($host, $port, $batch, 'ramp_' . count($clients), $clientLog);
        $clients = array_merge($clients, $newClients);

        sampleResources($resourceLog, $serverPid, count($clients), 0);
        echo sprintf("[%s] ramp clients=%d\n", date('H:i:s'), count($clients));

        sleep($interval);
    }

    setupGameRooms($clients, $targetGames, $clientLog);
    sampleResources($resourceLog, $serverPid, count($clients), $targetGames);
}

function runSteady(
    string $host,
    int $port,
    int $targetPlayers,
    int $targetGames,
    int $duration,
    string $clientLog,
    string $resourceLog,
    ?int $serverPid
): void {
    $clients = connectClients($host, $port, $targetPlayers, 'steady', $clientLog);
    $rooms = setupGameRooms($clients, $targetGames, $clientLog);
    $end = time() + $duration;

    while (time() < $end) {
        foreach ($clients as $client) {
            $rtt = $client->sendAction(['action' => 'room_list'], ['room_list', 'error']);
            if ($rtt !== null) {
                logClientMetric($clientLog, 'room_list', $rtt);
            }
        }

        foreach ($rooms as $room) {
            $rtt = $room['host']->sendAction(
                ['action' => 'draw_barrel'],
                ['barrels_drawn', 'your_turn', 'game_over', 'apartment_alert', 'error']
            );
            if ($rtt !== null) {
                logClientMetric($clientLog, 'draw_barrel', $rtt);
            }
        }

        sampleResources($resourceLog, $serverPid, count($clients), count($rooms));
        sleep(2);
    }
}

function runStorm(
    string $host,
    int $port,
    int $targetPlayers,
    int $targetGames,
    string $clientLog,
    string $resourceLog,
    ?int $serverPid
): void {
    $players = min($targetPlayers, 50);
    $clients = connectClients($host, $port, $players, 'storm', $clientLog);
    $rooms = setupGameRooms($clients, min($targetGames, 10), $clientLog);

    foreach ($clients as $client) {
        $rtt = $client->sendAction(['action' => 'room_list'], ['room_list', 'error']);
        if ($rtt !== null) {
            logClientMetric($clientLog, 'room_list', $rtt);
        }
    }

    foreach ($rooms as $room) {
        $rtt = $room['host']->sendAction(
            ['action' => 'draw_barrel'],
            ['barrels_drawn', 'your_turn', 'game_over', 'apartment_alert', 'error']
        );
        if ($rtt !== null) {
            logClientMetric($clientLog, 'draw_barrel', $rtt);
        }
    }

    sampleResources($resourceLog, $serverPid, count($clients), count($rooms));
    echo "Storm burst complete: clients=" . count($clients) . " rooms=" . count($rooms) . "\n";
}

function runLong(
    string $host,
    int $port,
    int $targetPlayers,
    int $targetGames,
    int $duration,
    string $clientLog,
    string $resourceLog,
    ?int $serverPid
): void {
    $halfPlayers = max(2, (int) floor($targetPlayers / 2));
    $halfGames = max(1, (int) floor($targetGames / 2));
    runSteady($host, $port, $halfPlayers, $halfGames, $duration, $clientLog, $resourceLog, $serverPid);
}

function logClientMetric(string $clientLog, string $action, float $rttMs): void
{
    $line = sprintf(
        "[%s] action=%s rtt_ms=%.2f\n",
        date('Y-m-d H:i:s'),
        $action,
        $rttMs
    );
    file_put_contents($clientLog, $line, FILE_APPEND | LOCK_EX);
}

function sampleResources(string $resourceLog, ?int $serverPid, int $clients, int $rooms): void
{
    $cpu = 0.0;
    $memMb = 0.0;

    if ($serverPid !== null && is_readable('/proc/' . $serverPid . '/stat')) {
        $stat = file_get_contents('/proc/' . $serverPid . '/stat');
        if ($stat !== false && preg_match('/\d+\s+\S+\s+\S+\s+\S+\s+\S+\s+\S+\s+\S+\s+\S+\s+\S+\s+\S+\s+\S+\s+\S+\s+\S+\s+(\d+)\s+(\d+)/', $stat, $m)) {
            $memMb = ((int) $m[2]) / 1024;
        }
    }

    if ($serverPid !== null) {
        $ps = @shell_exec('ps -p ' . (int) $serverPid . ' -o %cpu= 2>/dev/null');
        if ($ps !== null && trim($ps) !== '') {
            $cpu = (float) trim($ps);
        }
    }

    $line = sprintf(
        "[%s] cpu_pct=%.1f mem_mb=%.1f clients=%d rooms=%d\n",
        date('Y-m-d H:i:s'),
        $cpu,
        $memMb,
        $clients,
        $rooms
    );
    file_put_contents($resourceLog, $line, FILE_APPEND | LOCK_EX);
}

final class LoadWsClient
{
    /** @var resource */
    private $sock;
    private ?int $roomId = null;
    private string $buffer = '';

    private function __construct($sock, string $buffer = '')
    {
        $this->sock = $sock;
        $this->buffer = $buffer;
    }

    public static function connect(string $host, int $port): ?self
    {
        $sock = @fsockopen($host, $port, $errno, $errstr, 3.0);
        if (!$sock) {
            return null;
        }

        stream_set_timeout($sock, 3);
        $key = base64_encode(random_bytes(16));
        $headers = "GET / HTTP/1.1\r\n"
            . "Host: {$host}:{$port}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n";
        fwrite($sock, $headers);

        // Read HTTP upgrade response only; keep any trailing WS bytes in buffer.
        $http = '';
        while (!str_contains($http, "\r\n\r\n")) {
            $chunk = fread($sock, 1024);
            if ($chunk === false || $chunk === '') {
                fclose($sock);
                return null;
            }
            $http .= $chunk;
            if (strlen($http) > 8192) {
                fclose($sock);
                return null;
            }
        }

        $pos = strpos($http, "\r\n\r\n");
        $leftover = $pos === false ? '' : substr($http, $pos + 4);
        $client = new self($sock, $leftover);

        // Server sends `hello` immediately after upgrade — drain it so the next
        // readFrame belongs to the first client action (auth_result, etc.).
        $hello = $client->readFrameBuffered();
        if ($hello !== null) {
            $data = json_decode($hello, true);
            if (!is_array($data) || ($data['type'] ?? '') !== 'hello') {
                // Unexpected first frame; keep socket usable for actions anyway.
            }
        }

        return $client;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $acceptTypes packet types that complete this action
     */
    public function sendAction(array $payload, array $acceptTypes = []): ?float
    {
        $start = hrtime(true);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return null;
        }

        self::sendFrame($this->sock, $json);

        // Read until an accepted response type (skip broadcasts / hello).
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $frame = $this->readFrameBuffered();
            if ($frame === null) {
                break;
            }
            $data = json_decode($frame, true);
            if (!is_array($data)) {
                continue;
            }
            $type = (string) ($data['type'] ?? '');
            if ($type === 'hello') {
                continue;
            }
            if (isset($data['room_id'])) {
                $this->roomId = (int) $data['room_id'];
            }
            if ($type === 'room_joined' && isset($data['room_id'])) {
                $this->roomId = (int) $data['room_id'];
            }
            if ($acceptTypes === [] || in_array($type, $acceptTypes, true)) {
                break;
            }
            // Discard unexpected broadcast frames (e.g. player_joined on host).
        }

        return (hrtime(true) - $start) / 1_000_000;
    }

    public function lastRoomId(): ?int
    {
        return $this->roomId;
    }

    public function close(): void
    {
        if (is_resource($this->sock)) {
            fclose($this->sock);
        }
    }

    /**
     * @param resource $sock
     */
    private static function sendFrame($sock, string $payload): void
    {
        $len = strlen($payload);
        $frame = chr(0x81);
        $maskKey = random_bytes(4);

        if ($len <= 125) {
            $frame .= chr(0x80 | $len);
        } elseif ($len <= 65535) {
            $frame .= chr(0x80 | 126) . pack('n', $len);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $len);
        }

        $frame .= $maskKey;
        for ($i = 0; $i < $len; $i++) {
            $frame .= $payload[$i] ^ $maskKey[$i % 4];
        }

        fwrite($sock, $frame);
    }

    private function readFrameBuffered(): ?string
    {
        stream_set_timeout($this->sock, 3);

        while (strlen($this->buffer) < 2) {
            $chunk = fread($this->sock, 4096);
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $this->buffer .= $chunk;
        }

        $hdr = substr($this->buffer, 0, 2);
        $opcode = ord($hdr[0]) & 0x0F;
        if ($opcode === 0x8) {
            return null;
        }

        $b1 = ord($hdr[1]);
        $len = $b1 & 0x7F;
        $offset = 2;
        if ($len === 126) {
            while (strlen($this->buffer) < 4) {
                $chunk = fread($this->sock, 4096);
                if ($chunk === false || $chunk === '') {
                    return null;
                }
                $this->buffer .= $chunk;
            }
            $len = unpack('n', substr($this->buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($len === 127) {
            while (strlen($this->buffer) < 10) {
                $chunk = fread($this->sock, 4096);
                if ($chunk === false || $chunk === '') {
                    return null;
                }
                $this->buffer .= $chunk;
            }
            $len = unpack('J', substr($this->buffer, 2, 8))[1];
            $offset = 10;
        }

        $need = $offset + $len;
        while (strlen($this->buffer) < $need) {
            $chunk = fread($this->sock, 4096);
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $this->buffer .= $chunk;
        }

        $payload = substr($this->buffer, $offset, $len);
        $this->buffer = substr($this->buffer, $need);

        return $payload !== '' ? $payload : null;
    }
}
