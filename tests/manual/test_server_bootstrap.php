<?php

declare(strict_types=1);

/**
 * tests/Manual/test_server_bootstrap.php
 *
 * EPIC-10.0 Protocol router — end-to-end верификация server.php через
 * реальный WebSocket-клиент (без внешних библиотек — ручной RFC6455
 * handshake + фрейминг). В отличие от остальных tests/Manual/*.php,
 * этот тест действительно поднимает Workerman-воркер как отдельный
 * процесс (proc_open) и общается с ним по настоящему TCP-сокету —
 * это единственный способ верифицировать server.php, поскольку его
 * содержимое (onWorkerStart/onWebSocketConnected/onMessage/onClose)
 * не вызывается напрямую нигде в кодовой базе.
 *
 * FIX (после зависания на VPS): stdout/stderr дочернего процесса
 * ЗАПРЕЩЕНО подключать через ['pipe', ...] без немедленного вычитывания —
 * классический deadlock proc_open: ОС-буфер пайпа (обычно 64KB)
 * заполняется выводом Workerman (баннер, таблица воркеров), дочерний
 * процесс блокируется на write() ДО того, как успевает забиндить порт,
 * и fsockopen() родителя ждёт вечно то, что никогда не будет отправлено.
 * Исправлено: вывод дочернего процесса идёт в файлы (['file', ...]) —
 * запись в файл никогда не блокируется по объёму. Дополнительно —
 * жёсткий watchdog по SIGALRM: скрипт физически не может выполняться
 * дольше HARD_TIMEOUT_SECONDS ни при каких обстоятельствах.
 *
 * Покрывает:
 *   - onWebSocketConnected: hello сразу после рукопожатия
 *   - onMessage: ping не даёт ответа (ANCHOR_PROTOCOL.md § Heartbeat)
 *   - onMessage: невалидный JSON → error.invalid_json (провизорно,
 *     финальная policy — EPIC-10.1)
 *   - onMessage: неизвестный/ещё не подключённый action → error
 *   - onMessage: отсутствующее поле action → error
 *   - Соединение переживает штатный обмен (watchdog не рвёт активную сессию)
 *   - EPIC-10.2 (ADR-005): (MAX_TOTAL_PLAYERS+1)-е соединение →
 *     error.server_full + WS close code 4001, без hello
 *   - EPIC-10.2 continuation (ADR-006): неаутентифицированный
 *     неизвестный action → error.auth_required (не error.invalid_json);
 *     register/login/reconnect не блокируются guard'ом
 *
 * НЕ покрывает (намеренно, вне scope EPIC-10.0/10.2): rate limiting уже
 * покрыт отдельно (test_packet_validation.php), маршрутизацию
 * auth/lobby/game/admin-пакетов к реальным хендлерам — эти проверки
 * появятся вместе с соответствующими EPIC-10.3-10.6.
 *
 * Запуск: php tests/Manual/test_server_bootstrap.php
 */

// Импорт ТОЛЬКО значения константы (не бизнес-логики) — единый источник
// истины для MAX_TOTAL_PLAYERS вместо дублирования числа 150 в тесте.
// Не нарушает философию файла ("тест только через реальный сокет") —
// никакой код приложения не вызывается, читается только public const.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Lotto\Core\Constants;

const HARD_TIMEOUT_SECONDS = 40; // увеличено под TEST 7 (EPIC-10.2): до 150 реальных TCP+WS хендшейков

// =============================================================================
// Жёсткий watchdog: гарантирует, что скрипт никогда не зависнет насовсем,
// даже если что-то пойдёт не так способом, который мы не предвидели.
// =============================================================================

$GLOBALS['__serverProcess'] = null;
$GLOBALS['__serverPipes']   = null;

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
// Minimal RFC6455 WebSocket client (без внешних зависимостей)
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

    /** @return string|null Возвращает payload или null при таймауте/закрытии */
    public function recvOrNull(float $timeout = 2.0): ?string
    {
        $frame = $this->recvFrameOrNull($timeout);
        return $frame['payload'] ?? null;
    }

    /**
     * Как recvOrNull(), но также возвращает WS-опкод фрейма — нужно,
     * чтобы отличить close-фрейм (0x8, EPIC-10.2/ADR-005) от текстового
     * (0x1). recvOrNull() остаётся для обратной совместимости с TEST 1-6.
     *
     * @return array{opcode: int, payload: string}|null
     */
    public function recvFrameOrNull(float $timeout = 2.0): ?array
    {
        stream_set_timeout($this->sock, (int)$timeout, (int)(($timeout - (int)$timeout) * 1000000));
        $hdr = fread($this->sock, 2);
        if ($hdr === false || strlen($hdr) < 2) {
            return null;
        }
        $opcode = ord($hdr[0]) & 0x0F;
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
        return ['opcode' => $opcode, 'payload' => $payload];
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
// Поднимаем server.php как отдельный процесс. Вывод — В ФАЙЛЫ, не в pipes
// (см. пояснение в шапке файла — предотвращает deadlock).
// =============================================================================

$projectRoot = dirname(__DIR__, 2);

// -----------------------------------------------------------------------
// Self-healing: если с прошлого (например, зависшего/прерванного Ctrl+C)
// прогона остался осиротевший процесс server.php, всё ещё держащий порт
// 8080 — new proc_open ниже откажется стартовать ("already running",
// см. Workerman PID-файл), а наш клиент по ошибке подключится к ЧУЖОМУ
// старому процессу с непредсказуемым поведением. Поэтому ПЕРЕД стартом
// принудительно гасим любой существующий экземпляр через собственную
// команду Workerman 'stop' (по PID-файлу) — идемпотентно, безопасно
// вызывать даже если ничего не запущено (просто ничего не сделает).
// -----------------------------------------------------------------------

$stopDescriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$stopProcess = @proc_open(
    ['php', $projectRoot . '/server.php', 'stop'],
    $stopDescriptors,
    $stopPipes,
    $projectRoot
);
if (is_resource($stopProcess)) {
    // 'stop' короткоживущий и сам себя завершает — ждём с разумным
    // потолком, вычитывая пайпы, чтобы не словить тот же deadlock, от
    // которого чинили основной запуск.
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
    usleep(300_000); // дать ОС время реально освободить порт
}

$stdoutFile = tempnam(sys_get_temp_dir(), 'lotto_srv_out_');
$stderrFile = tempnam(sys_get_temp_dir(), 'lotto_srv_err_');

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['file', $stdoutFile, 'w'],
    2 => ['file', $stderrFile, 'w'],
];

$process = proc_open(
    ['php', $projectRoot . '/server.php', 'start'],
    $descriptors,
    $pipes,
    $projectRoot
);

if (!is_resource($process)) {
    fwrite(STDERR, "Failed to start server.php subprocess\n");
    exit(1);
}

$GLOBALS['__serverProcess'] = $process;

// stdin дочернего процесса нам не нужен — закрываем сразу, чтобы Workerman
// точно не пытался что-то из него читать/ждать.
if (isset($pipes[0]) && is_resource($pipes[0])) {
    fclose($pipes[0]);
}

// Ждём биндинга порта, опрашивая вместо фиксированного sleep (быстрее и
// надёжнее на медленных VPS).
$bound = false;
for ($i = 0; $i < 50; $i++) { // до 5 секунд (50 * 100ms)
    $status = proc_get_status($process);
    if (!$status['running']) {
        break; // процесс уже упал — незачем ждать дальше
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
    $status = proc_get_status($process);
    $stdoutContent = @file_get_contents($stdoutFile);
    fwrite(STDERR, "server.php did not bind port 8080 in time (running=" .
        ($status['running'] ? 'yes' : 'no') . ")\n");
    if (str_contains($stdoutContent ?? '', 'already running')) {
        fwrite(STDERR,
            "\n!!! Обнаружен осиротевший процесс server.php, всё ещё держащий порт 8080,\n" .
            "!!! и self-healing 'stop' его не погасил. Погасите вручную:\n" .
            "!!!   cd {$projectRoot} && php server.php stop\n" .
            "!!! или если это не помогает:\n" .
            "!!!   pkill -f 'server.php start'\n\n"
        );
    }
    fwrite(STDERR, "--- stdout ---\n" . $stdoutContent . "\n");
    fwrite(STDERR, "--- stderr ---\n" . @file_get_contents($stderrFile) . "\n");
    proc_terminate($process, 9);
    proc_close($process);
    @unlink($stdoutFile);
    @unlink($stderrFile);
    exit(1);
}

try {
    $c = new MiniWSClient('127.0.0.1', 8080);

    echo "TEST 1: hello сразу после WS handshake\n";
    $msg = $c->recvOrNull();
    $data = json_decode($msg ?? '', true);
    check(($data['type'] ?? null) === 'hello', 'type=hello');
    check(($data['protocol_version'] ?? null) === 1, 'protocol_version=1 (Constants::PROTOCOL_VERSION)');

    echo "\nTEST 2: ping не даёт ответа (ANCHOR_PROTOCOL.md § Heartbeat)\n";
    $c->send(json_encode(['action' => 'ping']));
    $msg2 = $c->recvOrNull(1.0);
    check($msg2 === null, 'нет пакета после ping (таймаут ожидаем)');

    echo "\nTEST 3: невалидный JSON -> error.invalid_json (провизорно, EPIC-10.1)\n";
    $c->send('{not valid json!!!');
    $msg3 = $c->recvOrNull();
    $data3 = json_decode($msg3 ?? '', true);
    check(($data3['type'] ?? null) === 'error', 'type=error');
    check(($data3['code'] ?? null) === 'error.invalid_json', 'code=error.invalid_json');

    echo "\nTEST 4: неаутентифицированный неизвестный action -> error.auth_required (guard срабатывает раньше диспетчера, EPIC-10.2 continuation/ADR-006)\n";
    $c->send(json_encode(['action' => 'nonexistent_action_xyz']));
    $msg4 = $c->recvOrNull();
    $data4 = json_decode($msg4 ?? '', true);
    check(($data4['type'] ?? null) === 'error', 'type=error для неизвестного action');
    check(($data4['code'] ?? null) === 'error.auth_required', 'code=error.auth_required (guard блокирует раньше диспетчера, не error.invalid_json)');

    echo "\nTEST 5: отсутствующее поле action -> error\n";
    $c->send(json_encode(['foo' => 'bar']));
    $msg5 = $c->recvOrNull();
    $data5 = json_decode($msg5 ?? '', true);
    check(($data5['type'] ?? null) === 'error', 'type=error при отсутствии action');

    echo "\nTEST 6: соединение переживает штатный обмен (watchdog не рвёт активную сессию)\n";
    $c->send(json_encode(['action' => 'ping']));
    $msg6 = $c->recvOrNull(1.0);
    check($msg6 === null, 'соединение всё ещё живо после предыдущего обмена');

    echo "\nTEST 7: глобальный лимит MAX_TOTAL_PLAYERS -> error.server_full + WS close code 4001 (EPIC-10.2/ADR-005)\n";
    // $c — уже 1 живое соединение; добираем до Constants::MAX_TOTAL_PLAYERS
    // реальными TCP+WS хендшейками.
    $warmClients = [$c];
    for ($i = 2; $i <= Constants::MAX_TOTAL_PLAYERS; $i++) {
        $extra = new MiniWSClient('127.0.0.1', 8080);
        $extra->recvOrNull(); // проглатываем hello
        $warmClients[] = $extra;
    }
    check(
        count($warmClients) === Constants::MAX_TOTAL_PLAYERS,
        'ровно Constants::MAX_TOTAL_PLAYERS соединений установлено (' . Constants::MAX_TOTAL_PLAYERS . ')'
    );

    // (MAX_TOTAL_PLAYERS + 1)-е соединение — WS handshake на уровне
    // протокола пройдёт (это делает сам Workerman до onWebSocketConnected),
    // но приложение обязано отклонить его прежде hello.
    $rejected = new MiniWSClient('127.0.0.1', 8080);

    $frame1 = $rejected->recvFrameOrNull();
    $data7  = json_decode($frame1['payload'] ?? '', true);
    check(($frame1['opcode'] ?? null) === 0x1, 'первый фрейм текстовый (opcode 0x1) — JSON error, не close');
    check(($data7['type'] ?? null) === 'error', 'type=error');
    check(($data7['code'] ?? null) === 'error.server_full', 'code=error.server_full (ADR-004: глобальный код, не room_full)');

    $frame2 = $rejected->recvFrameOrNull();
    check(($frame2['opcode'] ?? null) === 0x8, 'второй фрейм — close (opcode 0x8)');
    $closeCode = ($frame2 !== null && strlen($frame2['payload']) >= 2)
        ? unpack('n', substr($frame2['payload'], 0, 2))[1]
        : null;
    check($closeCode === 4001, 'WS close status code = 4001 (получено: ' . var_export($closeCode, true) . ')');

    foreach ($warmClients as $wc) {
        $wc->close();
    }
    $rejected->close();

    echo "\nTEST 8: register/login/reconnect НЕ блокируются auth_required guard'ом (EPIC-10.2 continuation/ADR-006)\n";
    $c2 = new MiniWSClient('127.0.0.1', 8080);
    $c2->recvOrNull(); // hello
    foreach (['register', 'login', 'reconnect'] as $exemptAction) {
        $c2->send(json_encode(['action' => $exemptAction]));
        $msg8 = $c2->recvOrNull();
        $data8 = json_decode($msg8 ?? '', true);
        // Хендлеры ещё не подключены (EPIC-10.3) — ожидаем, что guard
        // ПРОПУСТИЛ действие и запрос дошёл до пустого диспетчера
        // (error.invalid_json "not-yet-wired"), а НЕ error.auth_required.
        check(
            ($data8['code'] ?? null) !== 'error.auth_required',
            "action={$exemptAction}: не заблокирован guard'ом (code=" . ($data8['code'] ?? 'null') . ')'
        );
    }
    $c2->close();

    $c->close();
} catch (\Throwable $e) {
    fwrite(STDERR, "Exception during WS test: " . $e->getMessage() . "\n");
    fwrite(STDERR, "--- server stdout ---\n" . @file_get_contents($stdoutFile) . "\n");
    fwrite(STDERR, "--- server stderr ---\n" . @file_get_contents($stderrFile) . "\n");
    $failed++;
} finally {
    // Останавливаем сервер (SIGTERM -> Workerman graceful shutdown)
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
    pcntl_alarm(0); // снимаем watchdog — дошли до конца штатно
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "RESULT: {$passed} passed, {$failed} failed\n";
echo str_repeat('=', 60) . "\n";

exit($failed > 0 ? 1 : 0);
