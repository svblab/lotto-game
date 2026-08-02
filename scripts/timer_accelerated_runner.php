#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * scripts/timer_accelerated_runner.php
 *
 * EPIC-11.2 — Accelerated timer audit on Linux/VPS with shortened timeouts.
 *
 * Starts server.php with LOTTO_TIMER_AUDIT=1 and reduced timeout env vars,
 * drives disconnect/reconnect scenarios, then runs analyze_timer_log.php.
 *
 * Usage (VPS):
 *   php scripts/timer_accelerated_runner.php [--host=127.0.0.1] [--port=8080]
 *
 * NOTE: Requires Linux (Workerman). On Windows, use test_timer_audit.php.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (DIRECTORY_SEPARATOR === '\\') {
    fwrite(STDERR, "SKIP: timer_accelerated_runner requires Linux/VPS (Workerman).\n");
    fwrite(STDERR, "Run: php tests/Manual/test_timer_audit.php for mock-based regression.\n");
    exit(0);
}

$host = '127.0.0.1';
$port = 8080;
$reconnectTimeout = 5;

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--host=(.+)$/', $arg, $m)) {
        $host = $m[1];
    } elseif (preg_match('/^--port=(\d+)$/', $arg, $m)) {
        $port = (int) $m[1];
    } elseif (preg_match('/^--reconnect-timeout=(\d+)$/', $arg, $m)) {
        $reconnectTimeout = (int) $m[1];
    }
}

$projectRoot = dirname(__DIR__);
$serverScript = $projectRoot . '/server.php';
$logOut = $projectRoot . '/logs/timer_accel_server.out';
$logErr = $projectRoot . '/logs/timer_accel_server.err';
$auditLog = $projectRoot . '/logs/timer_audit.log';

@mkdir($projectRoot . '/logs', 0755, true);
if (is_file($auditLog)) {
    unlink($auditLog);
}

$accelEnv = [
    'LOTTO_TIMER_AUDIT'          => '1',
    'LOTTO_TIMER_AUDIT_LOG'      => $auditLog,
    'LOTTO_RECONNECT_TIMEOUT'    => (string) $reconnectTimeout,
    'LOTTO_LOBBY_HOST_TIMEOUT'   => '5',
    'LOTTO_UNAUTHORIZED_TIMEOUT' => '5',
    'LOTTO_AUTHORIZED_TIMEOUT'   => '5',
    'LOTTO_APARTMENT_TIMEOUT'    => '5',
    'LOTTO_GAME_AFK_WARN1'       => '2',
    'LOTTO_GAME_AFK_WARN2'       => '3',
    'LOTTO_GAME_AFK_AUTO'        => '5',
    'LOTTO_WATCHDOG_INTERVAL'    => '2',
    'LOTTO_AFK_TICK_INTERVAL'    => '1',
];

$env = array_merge($_ENV, $accelEnv);
$envPrefix = '';
foreach ($accelEnv as $key => $value) {
    $envPrefix .= $key . '=' . escapeshellarg($value) . ' ';
}

$cmd = trim($envPrefix) . ' ' . PHP_BINARY . ' ' . escapeshellarg($serverScript) . ' start';
$descriptor = [
    0 => ['file', '/dev/null', 'r'],
    1 => ['file', $logOut, 'a'],
    2 => ['file', $logErr, 'a'],
];

echo "Starting server with accelerated timer audit (reconnect={$reconnectTimeout}s)...\n";
$proc = proc_open($cmd, $descriptor, $pipes, $projectRoot, $env);
if (!is_resource($proc)) {
    fwrite(STDERR, "FAIL: could not start server.php\n");
    exit(2);
}

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

echo "Server ready. Running accelerated timer scenarios...\n";

$client = wsAccelConnect($host, $port);
if ($client === null) {
    fwrite(STDERR, "FAIL: could not open WebSocket client\n");
    proc_terminate($proc, 9);
    proc_close($proc);
    exit(2);
}

$username = 'timer_audit_' . bin2hex(random_bytes(4));
wsAccelSend($client, [
    'action'   => 'register',
    'username' => $username,
    'password' => 'testpass123',
]);
wsAccelRead($client);

wsAccelSend($client, [
    'action'      => 'create_room',
    'max_players' => 4,
    'cards_count' => 1,
]);
wsAccelRead($client);

echo "Disconnecting client to arm reconnect timer ({$reconnectTimeout}s)...\n";
fclose($client);

$waitSeconds = $reconnectTimeout + 2;
echo "Waiting {$waitSeconds}s for reconnect timer fire...\n";
sleep($waitSeconds);

echo "Stopping server...\n";
proc_terminate($proc, 15);
$waited = 0;
while (proc_get_status($proc)['running'] && $waited < 5_000_000) {
    usleep(100_000);
    $waited += 100_000;
}
proc_close($proc);

echo "Analyzing timer_audit.log...\n";
passthru(PHP_BINARY . ' ' . escapeshellarg($projectRoot . '/scripts/analyze_timer_log.php') . ' ' . escapeshellarg($auditLog));
exit(0);

/**
 * @return resource|null
 */
function wsAccelConnect(string $host, int $port)
{
    $client = @fsockopen($host, $port, $errno, $errstr, 3.0);
    if (!$client) {
        return null;
    }

    stream_set_timeout($client, 3);
    $key = base64_encode(random_bytes(16));
    $headers = "GET / HTTP/1.1\r\n"
        . "Host: {$host}:{$port}\r\n"
        . "Upgrade: websocket\r\n"
        . "Connection: Upgrade\r\n"
        . "Sec-WebSocket-Key: {$key}\r\n"
        . "Sec-WebSocket-Version: 13\r\n\r\n";
    fwrite($client, $headers);
    fread($client, 4096);

    return $client;
}

/**
 * @param resource $client
 * @param array<string, mixed> $payload
 */
function wsAccelSend($client, array $payload): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return;
    }

    $len = strlen($json);
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
        $frame .= $json[$i] ^ $maskKey[$i % 4];
    }

    fwrite($client, $frame);
}

/**
 * @param resource $client
 */
function wsAccelRead($client): void
{
    @fread($client, 8192);
}
