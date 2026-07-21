<?php

declare(strict_types=1);

/**
 * tests/Manual/test_packet_validation.php
 *
 * EPIC-10.1 Packet validation — верификация ADR-003
 * (docs/ADR/003-rate-limiting-and-invalid-json-policy.md):
 *
 *   1. Rate limiting: > RATE_LIMIT_PACKETS_PER_WINDOW (15) пакетов за
 *      RATE_LIMIT_WINDOW_SECONDS (1) секунду от одного соединения →
 *      немедленное закрытие БЕЗ error-пакета.
 *   2. Rate limiting считает КАЖДЫЙ входящий пакет (валидный JSON,
 *      невалидный JSON, ping — всё без разбора), а не только ошибочные.
 *   3. error.invalid_json НЕ закрывает соединение (пока не превышен
 *      rate limit) — клиент остаётся на связи и может продолжать работу.
 *   4. Окно (1s) действительно сбрасывается — burst ровно на границе
 *      лимита в двух последовательных окнах не закрывает соединение.
 *
 * Полностью самодостаточный (как и test_server_bootstrap.php): сам
 * поднимает server.php через proc_open (вывод — в файлы, не в pipes,
 * во избежание deadlock), сам гасит осиротевшие процессы с прошлых
 * прогонов через 'stop' перед стартом, сам себя останавливает по
 * жёсткому SIGALRM-watchdog при любом непредвиденном зависании.
 *
 * Запуск: php tests/Manual/test_packet_validation.php
 */

const HARD_TIMEOUT_SECONDS = 25;
const RATE_LIMIT = 15; // должно совпадать с Constants::RATE_LIMIT_PACKETS_PER_WINDOW

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
// Minimal RFC6455 WebSocket client
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

    /** @return string|null payload, или null при таймауте/закрытии — см. isClosed() для различения */
    public function recvOrNull(float $timeout = 1.5): ?string
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

    /** Различает "таймаут, соединение живо" от "соединение реально закрыто сервером" */
    public function isClosed(): bool
    {
        return feof($this->sock);
    }

    public function close(): void
    {
        fclose($this->sock);
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
    // TEST 1: ровно RATE_LIMIT (15) невалидных пакетов подряд — все получают
    // error.invalid_json, соединение остаётся открытым.
    // =========================================================================
    echo "TEST 1: ровно " . RATE_LIMIT . " невалидных пакетов — все получают ответ, соединение живо\n";
    $c1 = new MiniWSClient('127.0.0.1', 8080);
    $c1->recvOrNull(); // hello

    $received = 0;
    for ($i = 0; $i < RATE_LIMIT; $i++) {
        $c1->send('{not valid json ' . $i);
        $resp = $c1->recvOrNull(1.0);
        if ($resp !== null) {
            $data = json_decode($resp, true);
            if (($data['type'] ?? null) === 'error' && ($data['code'] ?? null) === 'error.invalid_json') {
                $received++;
            }
        }
    }
    check($received === RATE_LIMIT, "все {$received}/" . RATE_LIMIT . " невалидных пакетов получили error.invalid_json");
    check(!$c1->isClosed(), 'соединение НЕ закрыто после ровно ' . RATE_LIMIT . ' пакетов (ADR-003: лимит — "более", не "от")');
    $c1->close();

    // =========================================================================
    // TEST 2: RATE_LIMIT+1 (16-й) пакет в том же окне -> закрытие БЕЗ error-пакета
    // =========================================================================
    echo "\nTEST 2: " . (RATE_LIMIT + 1) . "-й пакет в том же окне -> закрытие без error-пакета\n";
    $c2 = new MiniWSClient('127.0.0.1', 8080);
    $c2->recvOrNull(); // hello

    for ($i = 0; $i < RATE_LIMIT; $i++) {
        $c2->send('{not valid json ' . $i);
        $c2->recvOrNull(1.0); // вычитываем error-пакеты, не мешаем следующим
    }
    // 16-й пакет — превышение лимита
    $c2->send('{not valid json overflow');
    $respOverflow = $c2->recvOrNull(1.5);
    check($respOverflow === null, 'на 16-й пакет НЕТ error-пакета (соединение закрыто, а не отвечено)');
    check($c2->isClosed(), 'соединение реально закрыто сервером после превышения rate limit (не просто таймаут)');
    $c2->close();

    // =========================================================================
    // TEST 3: rate limit считает ЛЮБЫЕ пакеты, включая валидные ping —
    // не только ошибочные (ADR-003 п.2).
    // =========================================================================
    echo "\nTEST 3: rate limit считает ping-пакеты наравне с прочими\n";
    $c3 = new MiniWSClient('127.0.0.1', 8080);
    $c3->recvOrNull(); // hello

    for ($i = 0; $i < RATE_LIMIT; $i++) {
        $c3->send(json_encode(['action' => 'ping']));
    }
    // ping не отвечает, поэтому дальше просто ждём — соединение должно
    // быть ещё живо (ровно на лимите, не превышен).
    check(!$c3->isClosed(), 'соединение живо после ровно ' . RATE_LIMIT . ' ping (лимит не превышен)');

    // 16-й ping — превышение
    $c3->send(json_encode(['action' => 'ping']));
    usleep(300_000); // даём серверу время обработать и закрыть
    check($c3->isClosed(), 'соединение закрыто после ' . (RATE_LIMIT + 1) . "-го ping (rate limit не делает исключения для валидных action)");
    $c3->close();

    // =========================================================================
    // TEST 4: окно действительно сбрасывается — RATE_LIMIT пакетов, пауза
    // >1s, ещё RATE_LIMIT пакетов -> суммарно 2×RATE_LIMIT, но в разных
    // окнах, соединение не должно закрываться.
    // =========================================================================
    echo "\nTEST 4: окно сбрасывается — burst в двух окнах не суммируется\n";
    $c4 = new MiniWSClient('127.0.0.1', 8080);
    $c4->recvOrNull(); // hello

    for ($i = 0; $i < RATE_LIMIT; $i++) {
        $c4->send('{not valid json a' . $i);
        $c4->recvOrNull(1.0);
    }
    check(!$c4->isClosed(), 'соединение живо после первого burst (' . RATE_LIMIT . ' пакетов)');

    sleep(2); // гарантированно новое окно (RATE_LIMIT_WINDOW_SECONDS=1)

    $received4 = 0;
    for ($i = 0; $i < RATE_LIMIT; $i++) {
        $c4->send('{not valid json b' . $i);
        $resp = $c4->recvOrNull(1.0);
        if ($resp !== null) {
            $data = json_decode($resp, true);
            if (($data['code'] ?? null) === 'error.invalid_json') {
                $received4++;
            }
        }
    }
    check($received4 === RATE_LIMIT, "второй burst после сброса окна: {$received4}/" . RATE_LIMIT . ' получили ответ (окно реально сбрасывается)');
    check(!$c4->isClosed(), 'соединение всё ещё живо после двух burst в разных окнах');
    $c4->close();

    // =========================================================================
    // TEST 5: одиночный невалидный пакет не закрывает соединение (базовый
    // ADR-003 сценарий, без rate limit в игре вообще).
    // =========================================================================
    echo "\nTEST 5: одиночный невалидный JSON не закрывает соединение\n";
    $c5 = new MiniWSClient('127.0.0.1', 8080);
    $c5->recvOrNull(); // hello
    $c5->send('totally not json at all {{{');
    $resp5 = $c5->recvOrNull();
    $data5 = json_decode($resp5 ?? '', true);
    check(($data5['type'] ?? null) === 'error' && ($data5['code'] ?? null) === 'error.invalid_json', 'получен error.invalid_json');
    check(!$c5->isClosed(), 'соединение НЕ закрыто после одного невалидного пакета (ADR-003 п.1)');
    $c5->close();
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
}

if (function_exists('pcntl_alarm')) {
    pcntl_alarm(0);
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "RESULT: {$passed} passed, {$failed} failed\n";
echo str_repeat('=', 60) . "\n";

exit($failed > 0 ? 1 : 0);
