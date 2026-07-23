<?php

declare(strict_types=1);

/**
 * tests/Manual/test_auth_packet_routing.php
 *
 * EPIC-10.3 Auth packet routing — верификация того, что register/login/
 * reconnect реально подключены к AuthHandler через живой server.php (не
 * юнит-тест AuthHandler самого по себе — это уже покрыто
 * test_auth_integration.php через MockConnection).
 *
 * Критическая проверка (FIX-8): после успешного register/login клиент
 * должен реально перестать получать error.auth_required на следующих
 * действиях — не только в юнит-тесте на MockConnection, а через настоящий
 * протокол end-to-end (реальный AuthService::login() → реальный
 * AuthHandler::bindConnection() → реальный router'а guard в server.php).
 * До FIX-8 это было бы сломано: $connection->userId никогда не
 * устанавливался, и auth_required guard (ADR-006) блокировал бы вообще
 * всё после успешного логина.
 *
 * НЕ покрывает (уже покрыто test_auth_integration.php через MockConnection):
 * все ветвления бизнес-логики AuthService (daily bonus, hashing, etc).
 * Здесь — только routing wiring + FIX-8 сквозной эффект + базовые коды
 * ошибок на уровне протокола.
 *
 * Полностью самодостаточный (как test_server_bootstrap.php/
 * test_packet_validation.php): сам поднимает server.php через proc_open,
 * сам гасит осиротевшие процессы, сам себя останавливает по SIGALRM.
 *
 * Запуск: php tests/Manual/test_auth_packet_routing.php
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Lotto\Infrastructure\Database;

const HARD_TIMEOUT_SECONDS = 25;

// =============================================================================
// Жёсткий watchdog
// =============================================================================

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

// =============================================================================
// Minimal RFC6455 WebSocket client (тот же код, что в test_packet_validation.php
// и test_server_bootstrap.php — намеренное дублирование, каждый тестовый файл
// в проекте самодостаточен, см. их докблоки)
// =============================================================================

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

// =============================================================================
// Test harness
// =============================================================================

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

// =============================================================================
// Подготовка тестовых данных: тот же паттерн, что test_login.php/test_register.php
// — реальная game.db, изолированные префиксом e103_ имена, очистка до/после.
// =============================================================================

$db  = new Database();
$pdo = $db->getPdo();
$pdo->exec("DELETE FROM users WHERE username LIKE 'e103\\_%' ESCAPE '\\'");

$loginPasswordHash = password_hash('e103pass123', PASSWORD_DEFAULT);
$pdo->prepare(
    "INSERT INTO users (username, password_hash, coins, is_admin, banned_until, last_daily_bonus) VALUES (?, ?, 500, 0, 0, ?)"
)->execute(['e103_loginuser', $loginPasswordHash, time()]);

// =============================================================================
// Поднимаем server.php (self-healing + вывод в файлы, см. test_server_bootstrap.php)
// =============================================================================

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
    usleep(300_000);
}

$stdoutFile = tempnam(sys_get_temp_dir(), 'lotto_srv_out_');
$stderrFile = tempnam(sys_get_temp_dir(), 'lotto_srv_err_');
$descriptors = [0 => ['pipe', 'r'], 1 => ['file', $stdoutFile, 'w'], 2 => ['file', $stderrFile, 'w']];

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
    // TEST 1: register -> auth_result
    // =========================================================================
    echo "TEST 1: register -> auth_result (EPIC-10.3)\n";
    $c1 = new MiniWSClient('127.0.0.1', 8080);
    $c1->recvOrNull(); // hello
    $c1->send(json_encode(['action' => 'register', 'username' => 'e103_reguser', 'password' => 'e103pass123']));
    $msg1 = $c1->recvOrNull();
    $data1 = json_decode($msg1 ?? '', true);
    check(($data1['type'] ?? null) === 'auth_result', 'type=auth_result');
    check(($data1['success'] ?? null) === true, 'success=true');
    check(($data1['username'] ?? null) === 'e103_reguser', 'username совпадает');
    check(($data1['coins'] ?? null) === 500, 'coins=500 (стартовый баланс)');
    check(($data1['is_admin'] ?? null) === false, 'is_admin=false');
    check(is_string($data1['session_token'] ?? null) && strlen($data1['session_token']) === 32, 'session_token — 32-символьный hex');

    // =========================================================================
    // TEST 2 (FIX-8, критично): та же самая связь — после успешного register
    // не-exempt действие больше НЕ получает error.auth_required. До FIX-8
    // здесь навсегда оставался бы error.auth_required, несмотря на валидный
    // auth_result выше.
    // =========================================================================
    echo "\nTEST 2 (FIX-8): после register не-exempt action НЕ блокируется auth_required guard'ом\n";
    $c1->send(json_encode(['action' => 'create_room']));
    $msg2 = $c1->recvOrNull();
    $data2 = json_decode($msg2 ?? '', true);
    check(
        ($data2['code'] ?? null) !== 'error.auth_required',
        'code != error.auth_required (получено: ' . ($data2['code'] ?? 'null') . ') — FIX-8 подтверждён end-to-end'
    );
    check(
        ($data2['code'] ?? null) === 'error.invalid_json',
        'code=error.invalid_json (lobby-действия ещё не подключены — ожидаемо, EPIC-10.4)'
    );
    $c1->close();

    // =========================================================================
    // TEST 3: register с уже занятым именем -> error.auth_username_taken
    // =========================================================================
    echo "\nTEST 3: register с занятым username -> error.auth_username_taken\n";
    $c3 = new MiniWSClient('127.0.0.1', 8080);
    $c3->recvOrNull();
    $c3->send(json_encode(['action' => 'register', 'username' => 'e103_reguser', 'password' => 'anotherpass']));
    $data3 = json_decode($c3->recvOrNull() ?? '', true);
    check(($data3['type'] ?? null) === 'error', 'type=error');
    check(($data3['code'] ?? null) === 'error.auth_username_taken', 'code=error.auth_username_taken');
    $c3->close();

    // =========================================================================
    // TEST 4: register с невалидным именем -> error.auth_invalid_username
    // =========================================================================
    echo "\nTEST 4: register с невалидным username -> error.auth_invalid_username\n";
    $c4 = new MiniWSClient('127.0.0.1', 8080);
    $c4->recvOrNull();
    $c4->send(json_encode(['action' => 'register', 'username' => 'x', 'password' => 'e103pass123']));
    $data4 = json_decode($c4->recvOrNull() ?? '', true);
    check(($data4['code'] ?? null) === 'error.auth_invalid_username', 'code=error.auth_invalid_username');
    $c4->close();

    // =========================================================================
    // TEST 5: login с верными данными -> auth_result (+FIX-8 на пути login,
    // не только register)
    // =========================================================================
    echo "\nTEST 5: login с верными данными -> auth_result\n";
    $c5 = new MiniWSClient('127.0.0.1', 8080);
    $c5->recvOrNull();
    $c5->send(json_encode(['action' => 'login', 'username' => 'e103_loginuser', 'password' => 'e103pass123']));
    $data5 = json_decode($c5->recvOrNull() ?? '', true);
    check(($data5['type'] ?? null) === 'auth_result', 'type=auth_result');
    check(($data5['username'] ?? null) === 'e103_loginuser', 'username совпадает');

    echo "\nTEST 6 (FIX-8): после login не-exempt action НЕ блокируется guard'ом\n";
    $c5->send(json_encode(['action' => 'draw_barrel']));
    $data6 = json_decode($c5->recvOrNull() ?? '', true);
    check(
        ($data6['code'] ?? null) !== 'error.auth_required',
        'code != error.auth_required (получено: ' . ($data6['code'] ?? 'null') . ') — FIX-8 подтверждён для login'
    );
    $c5->close();

    // =========================================================================
    // TEST 7: login с неверным паролем -> error.auth_invalid_credentials
    // =========================================================================
    echo "\nTEST 7: login с неверным паролем -> error.auth_invalid_credentials\n";
    $c7 = new MiniWSClient('127.0.0.1', 8080);
    $c7->recvOrNull();
    $c7->send(json_encode(['action' => 'login', 'username' => 'e103_loginuser', 'password' => 'wrongpass']));
    $data7 = json_decode($c7->recvOrNull() ?? '', true);
    check(($data7['type'] ?? null) === 'error', 'type=error');
    check(($data7['code'] ?? null) === 'error.auth_invalid_credentials', 'code=error.auth_invalid_credentials');
    $c7->close();

    // =========================================================================
    // TEST 8: reconnect с некорректным форматом токена -> error.auth_invalid_token
    // =========================================================================
    echo "\nTEST 8: reconnect с некорректным форматом токена -> error.auth_invalid_token\n";
    $c8 = new MiniWSClient('127.0.0.1', 8080);
    $c8->recvOrNull();
    $c8->send(json_encode(['action' => 'reconnect', 'token' => 'not-a-valid-token']));
    $data8 = json_decode($c8->recvOrNull() ?? '', true);
    check(($data8['code'] ?? null) === 'error.auth_invalid_token', 'code=error.auth_invalid_token (формат)');
    $c8->close();

    // =========================================================================
    // TEST 9: reconnect с корректным форматом, но неизвестным токеном ->
    // error.auth_invalid_token
    // =========================================================================
    echo "\nTEST 9: reconnect с валидным форматом, но неизвестным токеном -> error.auth_invalid_token\n";
    $c9 = new MiniWSClient('127.0.0.1', 8080);
    $c9->recvOrNull();
    $c9->send(json_encode(['action' => 'reconnect', 'token' => bin2hex(random_bytes(16))]));
    $data9 = json_decode($c9->recvOrNull() ?? '', true);
    check(($data9['code'] ?? null) === 'error.auth_invalid_token', 'code=error.auth_invalid_token (неизвестный токен)');
    $c9->close();
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

    // Очистка тестовых данных
    $pdo->exec("DELETE FROM users WHERE username LIKE 'e103\\_%' ESCAPE '\\'");
}

if (function_exists('pcntl_alarm')) {
    pcntl_alarm(0);
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "RESULT: {$passed} passed, {$failed} failed\n";
echo str_repeat('=', 60) . "\n";

exit($failed > 0 ? 1 : 0);
