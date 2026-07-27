<?php

declare(strict_types=1);

/**
 * Cross-platform test runner for tests/Manual/test_*.php
 *
 * On Windows, enables SQLite extensions when php.ini is absent.
 * Ubuntu/VPS: plain `php` is sufficient (see LOCAL_ENVIRONMENT.md).
 */

$projectRoot = __DIR__;
$manualDir = $projectRoot . '/tests/Manual';
$isWindows = DIRECTORY_SEPARATOR === '\\';

$skipAlways = [
    'test_logger.php',
];

$skipOnWindows = [
    'test_admin_packet_routing.php',
    'test_auth_packet_routing.php',
    'test_game_packet_routing.php',
    'test_lobby_packet_routing.php',
    'test_packet_validation.php',
    'test_server_bootstrap.php',
    'test_session_lifecycle.php',
];

$phpBinary = PHP_BINARY;
$args = [];

if ($isWindows) {
    $extDir = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'ext';
    if (is_dir($extDir)) {
        $args[] = '-d';
        $args[] = 'extension_dir=' . $extDir;
        $args[] = '-d';
        $args[] = 'extension=php_sqlite3.dll';
        $args[] = '-d';
        $args[] = 'extension=php_pdo_sqlite.dll';
    }
}

$files = glob($manualDir . '/test_*.php');
sort($files);

$failures = [];
$total = 0;

foreach ($files as $file) {
    $basename = basename($file);
    echo "=== {$basename} ===\n";

    if (in_array($basename, $skipAlways, true)) {
        echo "SKIP: superseded / removed (see FIX-12 in IMPLEMENTATION_STATUS.md)\n\n";
        continue;
    }

    if ($isWindows && in_array($basename, $skipOnWindows, true)) {
        echo "SKIP: requires Linux/VPS (live Workerman WebSocket server)\n\n";
        continue;
    }

    $cmd = array_merge([$phpBinary], $args, [$file]);
    $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptor, $pipes, $projectRoot);
    if (!is_resource($proc)) {
        echo "FAIL: could not spawn php for {$basename}\n\n";
        $failures[] = $basename;
        continue;
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);

    $output = trim($stdout . "\n" . $stderr);
    $lines  = explode("\n", $output);
    $tail   = implode("\n", array_slice($lines, $exitCode !== 0 ? -30 : -15));
    echo $tail . "\n";

    if ($exitCode !== 0) {
        $failures[] = $basename;
    }
    $total++;
    echo "\n";
}

echo str_repeat('=', 60) . "\n";
echo "SUMMARY: " . ($total - count($failures)) . "/{$total} test files passed\n";
if ($failures !== []) {
    echo "FAILED:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
}
echo str_repeat('=', 60) . "\n";

exit($failures === [] ? 0 : 1);
