<?php

declare(strict_types=1);

/**
 * Shared harness for live WebSocket subprocess tests.
 *
 * - Dynamic free TCP port (or LOTTO_WS_PORT override) — avoids 8080/18080 clashes
 * - Isolated SQLite DB (LOTTO_DB_PATH) — avoids production game.db locks
 * - Temp-dir logs/pid files — safe for www-data when logs/ is root-owned
 *
 * IMPORTANT: writes LOTTO_TEST_CONFIG JSON and passes a full proc_open env
 * (PATH + test vars). On Linux, proc_open with a partial env replaces the
 * entire environment; putenv() alone is not always visible inside forked
 * Workerman workers.
 */

/** @var int|null Port picked for the current test process */
$GLOBALS['__wsTestPort'] = null;

/** @var array<string, string>|null Env vars applied via putenv for this test */
$GLOBALS['__wsTestEnv'] = null;

/** @var string|null Path to isolated temp SQLite DB for this test */
$GLOBALS['__wsTestDbPath'] = null;

/** @var string|null Path to LOTTO_TEST_CONFIG JSON for server subprocess */
$GLOBALS['__wsTestConfigPath'] = null;

function wsTestPhpBinary(): string
{
    return PHP_BINARY;
}

function wsTestPickFreePort(): int
{
    $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($sock === false) {
        return 18080;
    }
    $name = stream_socket_get_name($sock, false);
    fclose($sock);
    if (is_string($name) && preg_match('/:(\d+)$/', $name, $m)) {
        return (int) $m[1];
    }

    return 18080;
}

function wsTestPort(): int
{
    if ($GLOBALS['__wsTestPort'] !== null) {
        return $GLOBALS['__wsTestPort'];
    }

    $env = getenv('LOTTO_WS_PORT');
    if (is_string($env) && $env !== '') {
        $GLOBALS['__wsTestPort'] = (int) $env;
        return $GLOBALS['__wsTestPort'];
    }

    $GLOBALS['__wsTestPort'] = wsTestPickFreePort();
    return $GLOBALS['__wsTestPort'];
}

/**
 * Create (or reuse) an isolated SQLite database for this test process.
 *
 * Ignores a pre-set LOTTO_DB_PATH from systemd/production — WS tests must
 * never touch production game.db.
 */
function wsTestEnsureDatabase(string $projectRoot): string
{
    if (isset($GLOBALS['__wsTestDbPath']) && is_file($GLOBALS['__wsTestDbPath'])) {
        return $GLOBALS['__wsTestDbPath'];
    }

    $path = sys_get_temp_dir() . '/lotto_test_db_' . getmypid() . '.db';
    foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL;');
    $pdo->exec('PRAGMA foreign_keys=ON;');
    $pdo->exec('PRAGMA busy_timeout=5000;');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            coins INTEGER NOT NULL DEFAULT 500,
            is_admin INTEGER NOT NULL DEFAULT 0,
            banned_until INTEGER NOT NULL DEFAULT 0,
            last_daily_bonus INTEGER NOT NULL DEFAULT 0
        );
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_username ON users (username);');

    putenv('LOTTO_DB_PATH=' . $path);
    $_ENV['LOTTO_DB_PATH']    = $path;
    $_SERVER['LOTTO_DB_PATH'] = $path;
    $GLOBALS['__wsTestDbPath'] = $path;

    return $path;
}

function wsTestCleanupDatabase(): void
{
    $path = $GLOBALS['__wsTestDbPath'] ?? getenv('LOTTO_DB_PATH');
    if (!is_string($path) || $path === '') {
        return;
    }
    foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    putenv('LOTTO_DB_PATH');
    unset($_ENV['LOTTO_DB_PATH'], $_SERVER['LOTTO_DB_PATH']);
    $GLOBALS['__wsTestDbPath'] = null;

    $configPath = $GLOBALS['__wsTestConfigPath'] ?? null;
    if (is_string($configPath) && is_file($configPath)) {
        @unlink($configPath);
    }
    putenv('LOTTO_TEST_CONFIG');
    unset($_ENV['LOTTO_TEST_CONFIG'], $_SERVER['LOTTO_TEST_CONFIG']);
    $GLOBALS['__wsTestConfigPath'] = null;
}

/**
 * Build a complete environment array for proc_open (Linux replaces entire env).
 *
 * @param array<string, string> $vars
 * @return array<string, string>
 */
function wsTestBuildProcEnv(array $vars): array
{
    $env = [];
    foreach (['PATH', 'HOME', 'USER', 'LANG', 'LC_ALL', 'TMPDIR', 'TEMP', 'TMP', 'SYSTEMROOT'] as $key) {
        $val = getenv($key);
        if (is_string($val) && $val !== '') {
            $env[$key] = $val;
        }
    }

    return array_merge($env, $vars);
}

/**
 * @param array<string, string> $env
 * @return list<string>
 */
function wsTestServerArgv(string $projectRoot, string $command, array $env): array
{
    $configPath = $env['LOTTO_TEST_CONFIG'] ?? '';
    $argv = [wsTestPhpBinary(), $projectRoot . '/server.php', $command];
    if ($configPath !== '') {
        $argv[] = '--lotto-config=' . $configPath;
    }

    return $argv;
}

/**
 * @param array<string, string> $env
 * @return list<string>
 */
function wsTestServerCommand(string $projectRoot, string $command, array $env): array
{
    $argv = wsTestServerArgv($projectRoot, $command, $env);
    if (PHP_OS_FAMILY === 'Windows') {
        return $argv;
    }

    $cmd = ['env'];
    foreach ($env as $key => $value) {
        $cmd[] = "{$key}={$value}";
    }
    array_push($cmd, ...$argv);

    return $cmd;
}

function wsTestPrepareLogFiles(array $env): void
{
    foreach ([
        'LOTTO_WORKERMAN_LOG_FILE',
        'LOTTO_WORKERMAN_PID_FILE',
        'LOTTO_SERVER_LOG',
        'LOTTO_MEMORY_AUDIT_LOG',
    ] as $key) {
        if (empty($env[$key])) {
            continue;
        }
        $path = $env[$key];
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        if (!is_file($path)) {
            @touch($path);
            @chmod($path, 0666);
        }
    }
}

/**
 * Apply test-server environment via putenv (child inherits via proc_open null env).
 *
 * @return array<string, string>
 */
function wsTestApplyServerEnv(string $projectRoot): array
{
    if ($GLOBALS['__wsTestEnv'] !== null) {
        return $GLOBALS['__wsTestEnv'];
    }

    $tmpdir = sys_get_temp_dir();
    $suffix = (string) getmypid();
    $port   = wsTestPort();
    $dbPath = wsTestEnsureDatabase($projectRoot);

    $vars = [
        'LOTTO_WS_PORT'            => (string) $port,
        'LOTTO_DB_PATH'            => $dbPath,
        'LOTTO_WORKERMAN_LOG_FILE' => "{$tmpdir}/lotto_wm_test_{$suffix}.log",
        'LOTTO_WORKERMAN_PID_FILE' => "{$tmpdir}/lotto_wm_test_{$suffix}.pid",
        'LOTTO_SERVER_LOG'         => "{$tmpdir}/lotto_srv_test_{$suffix}.log",
        'LOTTO_MEMORY_AUDIT_LOG'   => "{$tmpdir}/lotto_mem_audit_test_{$suffix}.log",
    ];

    $configPath = "{$tmpdir}/lotto_test_config_{$suffix}.json";
    file_put_contents($configPath, json_encode($vars, JSON_UNESCAPED_SLASHES));
    $vars['LOTTO_TEST_CONFIG'] = $configPath;
    $GLOBALS['__wsTestConfigPath'] = $configPath;

    foreach ($vars as $key => $value) {
        putenv("{$key}={$value}");
        $_ENV[$key]    = $value;
        $_SERVER[$key] = $value;
    }

    $GLOBALS['__wsTestEnv'] = $vars;
    wsTestPrepareLogFiles($vars);
    return $vars;
}

function wsTestStopServer(string $projectRoot): void
{
    $env = wsTestApplyServerEnv($projectRoot);

    $stopDescriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $stopProcess = @proc_open(
        wsTestServerCommand($projectRoot, 'stop', $env),
        $stopDescriptors,
        $stopPipes,
        $projectRoot,
        PHP_OS_FAMILY === 'Windows' ? wsTestBuildProcEnv($env) : null
    );

    if (!is_resource($stopProcess)) {
        return;
    }

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

    foreach ($stopPipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }

    proc_close($stopProcess);
    usleep(300_000);
}

/**
 * @return array{
 *   process: resource,
 *   stdoutFile: string,
 *   stderrFile: string,
 *   port: int,
 *   env: array<string, string>
 * }
 */
function wsTestStartServer(string $projectRoot): array
{
    wsTestStopServer($projectRoot);

    $env        = wsTestApplyServerEnv($projectRoot);
    $port       = wsTestPort();
    $stdoutFile = tempnam(sys_get_temp_dir(), 'lotto_srv_out_');
    $stderrFile = tempnam(sys_get_temp_dir(), 'lotto_srv_err_');
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $stdoutFile, 'w'],
        2 => ['file', $stderrFile, 'w'],
    ];

    $process = proc_open(
        wsTestServerCommand($projectRoot, 'start', $env),
        $descriptors,
        $pipes,
        $projectRoot,
        PHP_OS_FAMILY === 'Windows' ? wsTestBuildProcEnv($env) : null
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Failed to start server.php subprocess');
    }

    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }

    $bound = false;
    for ($i = 0; $i < 100; $i++) {
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($probe) {
            fclose($probe);
            $bound = true;
            break;
        }
        usleep(100_000);
    }

    if (!$bound) {
        $status = proc_get_status($process);
        $stdoutContent = @file_get_contents($stdoutFile);
        $stderrContent = @file_get_contents($stderrFile);
        proc_terminate($process, 9);
        proc_close($process);
        @unlink($stdoutFile);
        @unlink($stderrFile);

        throw new RuntimeException(
            "server.php did not bind port {$port} in time (running="
            . ($status['running'] ? 'yes' : 'no') . ")\n"
            . "--- stdout ---\n{$stdoutContent}\n"
            . "--- stderr ---\n{$stderrContent}"
        );
    }

    return [
        'process'    => $process,
        'stdoutFile' => $stdoutFile,
        'stderrFile' => $stderrFile,
        'port'       => $port,
        'env'        => $env,
    ];
}

/**
 * @param array{process: resource, stdoutFile?: string, stderrFile?: string, env?: array<string, string>} $ctx
 */
function wsTestShutdownServer(array $ctx): void
{
    $process = $ctx['process'];
    if (!is_resource($process)) {
        return;
    }

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

    if (isset($ctx['stdoutFile'])) {
        @unlink($ctx['stdoutFile']);
    }
    if (isset($ctx['stderrFile'])) {
        @unlink($ctx['stderrFile']);
    }

    if (isset($ctx['env'])) {
        foreach ([
            'LOTTO_WORKERMAN_LOG_FILE',
            'LOTTO_WORKERMAN_PID_FILE',
            'LOTTO_SERVER_LOG',
            'LOTTO_MEMORY_AUDIT_LOG',
        ] as $key) {
            if (!empty($ctx['env'][$key]) && is_file($ctx['env'][$key])) {
                @unlink($ctx['env'][$key]);
            }
        }
    }

    wsTestCleanupDatabase();
    $GLOBALS['__wsTestPort'] = null;
    $GLOBALS['__wsTestEnv']  = null;
}
