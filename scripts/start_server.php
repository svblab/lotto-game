#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Cross-platform server launcher (dev / manual frontend testing).
 *
 * On Windows, enables SQLite PDO extensions before Workerman bootstrap.
 * Usage: php scripts/start_server.php [start|stop|restart|status|reload]
 */

$projectRoot = dirname(__DIR__);
$command = $argv[1] ?? 'start';
$valid = ['start', 'stop', 'restart', 'status', 'reload'];

if (!in_array($command, $valid, true)) {
    fwrite(STDERR, "Usage: php scripts/start_server.php [start|stop|restart|status|reload]\n");
    exit(2);
}

require_once $projectRoot . '/vendor/autoload.php';
require_once $projectRoot . '/src/Core/Helpers.php';

use function Lotto\Core\lottoBootstrapPhpExtensions;
use function Lotto\Core\lottoPhpIniArgs;

lottoBootstrapPhpExtensions();

$cmd = array_merge(
    [PHP_BINARY],
    lottoPhpIniArgs(),
    [$projectRoot . '/server.php', $command]
);

$proc = proc_open(
    $cmd,
    [0 => STDIN, 1 => STDOUT, 2 => STDERR],
    $pipes,
    $projectRoot
);

if (!is_resource($proc)) {
    fwrite(STDERR, "Failed to launch server.php {$command}\n");
    exit(1);
}

exit(proc_close($proc));
