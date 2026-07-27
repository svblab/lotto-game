<?php

namespace Lotto\Core;

use Exception;

/**
 * Отправка JSON-пакета клиенту.
 * Любые ошибки кодирования приводят к выбросу исключения.
 *
 * @param object $connection Экземпляр соединения Workerman
 * @param array $payload Данные для отправки
 * @return void
 * @throws Exception Если json_encode завершился ошибкой
 */
function sendJson(object $connection, array $payload): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    
    if ($json === false) {
        throw new Exception('JSON encoding failed: ' . json_last_error_msg());
    }
    
    $connection->send($json);
}

/**
 * Унифицированная отправка ошибки протокола.
 * Контракт пакета error зафиксирован в ANCHOR_PROTOCOL.md и обязан
 * содержать поле code (см. реестр допустимых кодов в этом документе).
 *
 * @param object $connection Экземпляр соединения Workerman
 * @param string $code Код ошибки из реестра ANCHOR_PROTOCOL.md (например error.invalid_json)
 * @param string $message Необязательный текст ошибки
 * @return void
 */
function sendError(object $connection, string $code, string $message = ''): void
{
    sendJson($connection, [
        'type' => 'error',
        'code' => $code,
        'message' => $message
    ]);
}

/**
 * Закрывает WS-соединение с явным WebSocket close-статус-кодом
 * (RFC 6455 §5.5.1). Используемая версия Workerman не предоставляет
 * built-in API для отправки close-фрейма с произвольным статус-кодом —
 * TcpConnection::close($data, true) отправляет $data как raw-байты перед
 * закрытием сокета, поэтому фрейм собирается вручную здесь (см. ADR-005).
 *
 * @param object $connection Экземпляр соединения Workerman
 * @param int $code WebSocket close-статус-код. Диапазон 4000-4999 —
 *                   private use (RFC 6455 §7.4.2), в этом проекте
 *                   зарезервирован под application-specific коды
 *                   (реестр — ANCHOR_PROTOCOL.md § WebSocket Close Codes).
 * @param string $reason Необязательная короткая UTF-8 причина
 * @return void
 */
function closeWithCode(object $connection, int $code, string $reason = ''): void
{
    // Payload = 2-byte big-endian статус-код + опциональная причина.
    // Однобайтовое поле длины во фрейме валидно только для 0-125 байт
    // (RFC 6455 §5.2) — причина обрезается защитно, на практике не
    // ожидается длиннее пары слов.
    $payload = pack('n', $code) . $reason;
    if (strlen($payload) > 125) {
        $payload = substr($payload, 0, 125);
    }
    $frame = "\x88" . chr(strlen($payload)) . $payload;
    $connection->close($frame, true);
}

/**
 * Вещание пакета на всю комнату только для активных игроков.
 * Игроки со статусом 'disconnected' игнорируются.
 *
 * @param array $room Структура комнаты из памяти RAM
 * @param array $payload Данные для отправки
 * @return void
 */
function broadcastToRoom(array $room, array $payload): void
{
    if (!isset($room['players']) || !is_array($room['players'])) {
        return;
    }

    foreach ($room['players'] as $player) {
        if (isset($player['status']) && $player['status'] === 'active' && isset($player['connection'])) {
            sendJson($player['connection'], $payload);
        }
    }
}

/**
 * Обёртка над системным логгером для ведения системных записей.
 *
 * @param Logger $logger Экземпляр класса логгера
 * @param string $level Уровень лога (INFO, WARNING, ERROR)
 * @param string $message Сообщение на английском языке
 * @return void
 */
function serverLog(Logger $logger, string $level, string $message): void
{
    $logger->write($level, $message);
}

/**
 * FIX-14: load isolated test-server paths from LOTTO_TEST_CONFIG JSON.
 *
 * Live WS tests write a temp config file and pass its path via env so the
 * Workerman worker subprocess reliably uses the isolated SQLite DB and temp
 * log files (putenv alone is not always visible inside forked workers).
 */
function lottoRuntimeEnv(string $key): ?string
{
    if (isset($GLOBALS['__lotto_runtime_config'][$key])) {
        $fromGlobals = $GLOBALS['__lotto_runtime_config'][$key];
        if (is_string($fromGlobals) && $fromGlobals !== '') {
            return $fromGlobals;
        }
    }

    $val = getenv($key);
    if (is_string($val) && $val !== '') {
        return $val;
    }

    if (isset($_ENV[$key]) && is_string($_ENV[$key]) && $_ENV[$key] !== '') {
        return $_ENV[$key];
    }

    if (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return $_SERVER[$key];
    }

    return null;
}

/**
 * EPIC-11.2: register a Workerman timer with optional audit logging.
 *
 * @param array<string, scalar|null> $context
 */
function lottoTimerAdd(
    float $interval,
    callable $cb,
    array $args = [],
    bool $persistent = true,
    string $label = '',
    array $context = []
): int {
    $timerId = 0;
    $wrapped = function (...$passedArgs) use ($cb, $label, &$timerId, $context, $args) {
        $audit = $GLOBALS['__lotto_timer_audit'] ?? null;
        if ($audit instanceof TimerAudit) {
            $audit->recordFire($label !== '' ? $label : 'anonymous', $timerId, $context);
        }

        if (!empty($args)) {
            return $cb(...$args);
        }

        return $cb(...$passedArgs);
    };

    $timerId = \Workerman\Timer::add($interval, $wrapped, [], $persistent);

    $audit = $GLOBALS['__lotto_timer_audit'] ?? null;
    if ($audit instanceof TimerAudit) {
        $audit->recordAdd($label !== '' ? $label : 'anonymous', $timerId, $interval, $context);
    }

    return $timerId;
}

/**
 * EPIC-11.2: cancel a Workerman timer with optional audit logging.
 *
 * @param array<string, scalar|null> $context
 */
function lottoTimerDel(int $timerId, string $label = '', array $context = []): bool
{
    $audit = $GLOBALS['__lotto_timer_audit'] ?? null;
    if ($audit instanceof TimerAudit) {
        $audit->recordDel($label !== '' ? $label : 'anonymous', $timerId, $context);
    }

    return \Workerman\Timer::del($timerId);
}

/**
 * EPIC-11.3: record a financial event when economy audit is enabled.
 *
 * @param array<string, scalar|null> $context
 */
function lottoEconomyRecord(string $operation, int $userId, int $amount, array $context = []): void
{
    $audit = $GLOBALS['__lotto_economy_audit'] ?? null;
    if ($audit instanceof EconomyAudit) {
        $audit->record($operation, $userId, $amount, $context);
    }
}

/**
 * EPIC-11.4: record a room state transition when state audit is enabled.
 *
 * @param array<string, scalar|null> $context
 */
function lottoStateTransition(int $roomId, string $from, string $to, string $trigger, array $context = []): void
{
    $audit = $GLOBALS['__lotto_state_audit'] ?? null;
    if ($audit instanceof StateMachineAudit) {
        $audit->recordTransition($roomId, $from, $to, $trigger, $context);
    }
}

/**
 * EPIC-11.4: record a rejected action in the current room state.
 *
 * @param array<string, scalar|null> $context
 */
function lottoStateReject(int $roomId, string $state, string $action, string $code, array $context = []): void
{
    $audit = $GLOBALS['__lotto_state_audit'] ?? null;
    if ($audit instanceof StateMachineAudit) {
        $audit->recordRejection($roomId, $state, $action, $code, $context);
    }
}

/**
 * EPIC-11.4: record a player state transition when state audit is enabled.
 *
 * @param array<string, scalar|null> $context
 */
function lottoPlayerStateTransition(
    int $roomId,
    int $connId,
    string $from,
    string $to,
    string $trigger,
    array $context = []
): void {
    $audit = $GLOBALS['__lotto_state_audit'] ?? null;
    if ($audit instanceof StateMachineAudit) {
        $audit->recordPlayerTransition($roomId, $connId, $from, $to, $trigger, $context);
    }
}

function lottoApplyTestConfig(): void
{
    $configFile = lottoRuntimeEnv('LOTTO_TEST_CONFIG');
    if ($configFile === null || !is_readable($configFile)) {
        return;
    }

    $raw = file_get_contents($configFile);
    $data = json_decode($raw !== false ? $raw : '', true);
    if (!is_array($data)) {
        return;
    }

    if (!isset($GLOBALS['__lotto_runtime_config']) || !is_array($GLOBALS['__lotto_runtime_config'])) {
        $GLOBALS['__lotto_runtime_config'] = [];
    }

    foreach ($data as $key => $value) {
        if (!is_string($key) || (!is_string($value) && !is_int($value) && !is_float($value))) {
            continue;
        }
        $str = (string) $value;
        putenv("{$key}={$str}");
        $_ENV[$key] = $str;
        $_SERVER[$key] = $str;
        $GLOBALS['__lotto_runtime_config'][$key] = $str;
    }
}

