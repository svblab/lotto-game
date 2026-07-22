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

