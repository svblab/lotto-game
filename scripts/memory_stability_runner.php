#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * scripts/memory_stability_runner.php
 *
 * EPIC-11.1 — Long-duration memory stability test (VPS / Linux).
 *
 * Starts server.php with LOTTO_MEMORY_AUDIT=1, drives WebSocket activity,
 * and relies on periodic snapshots (every 30 min in server.php) plus
 * connection/room lifecycle events in memory_audit.log.
 *
 * Usage (VPS):
 *   php scripts/memory_stability_runner.php [--duration=21600] [--players=50] [--games=10]
 *
 * Defaults:
 *   --duration=21600  (6 hours, per EPIC-11.1 spec)
 *   --players=50
 *   --games=10
 *   --host=127.0.0.1
 *   --port=8080
 *
 * After completion, runs scripts/analyze_memory_log.php automatically.
 *
 * NOTE: Requires Linux (Workerman). On Windows, use test_memory_audit.php only.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Lotto\Core\Constants;

if (DIRECTORY_SEPARATOR === '\\') {
    fwrite(STDERR, "SKIP: memory_stability_runner requires Linux/VPS (Workerman).\n");
    fwrite(STDERR, "Run: php tests/Manual/test_memory_audit.php for mock-based regression.\n");
    exit(0);
}

if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "WARNING: running as root will create root-owned log files.\n");
    fwrite(STDERR, "Run as the same user as lotto-server.service (e.g. www-data) instead.\n");
}

$duration = 21600;
$targetPlayers = 50;
$targetGames = 10;
$host = '127.0.0.1';
$port = 8080;

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--duration=(\d+)$/', $arg, $m)) {
        $duration = (int) $m[1];
    } elseif (preg_match('/^--players=(\d+)$/', $arg, $m)) {
        $targetPlayers = (int) $m[1];
    } elseif (preg_match('/^--games=(\d+)$/', $arg, $m)) {
        $targetGames = (int) $m[1];
    } elseif (preg_match('/^--host=(.+)$/', $arg, $m)) {
        $host = $m[1];
    } elseif (preg_match('/^--port=(\d+)$/', $arg, $m)) {
        $port = (int) $m[1];
    }
}

$projectRoot = dirname(__DIR__);
$serverScript = $projectRoot . '/server.php';
$logOut = $projectRoot . '/logs/memory_stability_server.out';
$logErr = $projectRoot . '/logs/memory_stability_server.err';
$auditLog = $projectRoot . '/logs/memory_audit.log';

@mkdir($projectRoot . '/logs', 0755, true);
if (is_file($auditLog)) {
    unlink($auditLog);
}

$env = array_merge($_ENV, [
    'LOTTO_MEMORY_AUDIT'     => '1',
    'LOTTO_MEMORY_AUDIT_LOG' => $auditLog,
]);

$cmd = 'LOTTO_MEMORY_AUDIT=1 LOTTO_MEMORY_AUDIT_LOG=' . escapeshellarg($auditLog)
    . ' ' . PHP_BINARY . ' ' . escapeshellarg($serverScript) . ' start';
$descriptor = [
    0 => ['file', '/dev/null', 'r'],
    1 => ['file', $logOut, 'a'],
    2 => ['file', $logErr, 'a'],
];

echo "Starting server with memory audit enabled...\n";
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

echo "Server ready. Running stability test for {$duration}s ";
echo "({$targetPlayers} players, {$targetGames} games target)...\n";

$startTime = time();
$endTime = $startTime + $duration;
$cycle = 0;

while (time() < $endTime) {
    $cycle++;
    $activeClients = [];

    for ($i = 0; $i < min($targetPlayers, Constants::MAX_TOTAL_PLAYERS - 1); $i++) {
        $client = @fsockopen($host, $port, $errno, $errstr, 2.0);
        if (!$client) {
            continue;
        }
        stream_set_timeout($client, 2);
        $key = base64_encode(random_bytes(16));
        $headers = "GET / HTTP/1.1\r\n"
            . "Host: {$host}:{$port}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n";
        fwrite($client, $headers);
        fread($client, 4096);

        $username = 'memtest_' . $cycle . '_' . $i;
        $register = json_encode([
            'action'   => 'register',
            'username' => $username,
            'password' => 'testpass123',
        ]);
        fwrite($client, wsFrame($register));
        fread($client, 4096);

        $activeClients[] = $client;
    }

    foreach ($activeClients as $client) {
        $ping = json_encode(['action' => 'ping']);
        @fwrite($client, wsFrame($ping));
        fclose($client);
    }

    if ($cycle % 10 === 0) {
        $elapsed = time() - $startTime;
        $remaining = max(0, $endTime - time());
        echo sprintf(
            "[%s] cycle=%d clients=%d elapsed=%ds remaining=%ds\n",
            date('H:i:s'),
            $cycle,
            count($activeClients),
            $elapsed,
            $remaining
        );
    }

    sleep(5);
}

echo "Stopping server...\n";
proc_terminate($proc, 15);
proc_close($proc);

echo "Analyzing memory_audit.log...\n";
passthru(PHP_BINARY . ' ' . escapeshellarg($projectRoot . '/scripts/analyze_memory_log.php'));
exit(0);

function wsFrame(string $payload): string
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

    return $frame;
}
