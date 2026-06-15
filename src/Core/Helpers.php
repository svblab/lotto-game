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
 *
 * @param object $connection Экземпляр соединения Workerman
 * @param string $message Сообщение об ошибке
 * @return void
 */
function sendError(object $connection, string $message): void
{
    sendJson($connection, [
        'type' => 'error',
        'message' => $message
    ]);
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

