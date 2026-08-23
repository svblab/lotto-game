<?php

namespace Lotto\Chat;

use Lotto\Core\Constants;
use Lotto\Core\Logger;
use Lotto\Core\RoomManager;
use function Lotto\Core\broadcastToRoom;
use function Lotto\Core\sendError;
use function Lotto\Core\serverLog;

/**
 * ChatService — ADR-030
 *
 * Password-room plain-text broadcast. No SQLite/disk. No history.
 */
final class ChatService
{
    public function __construct(
        private RoomManager $roomManager,
        private Logger $logger
    ) {
    }

    /**
     * @return array{room_id: int, room: array}|null
     */
    public function resolvePasswordRoom(object $worker, object $connection): ?array
    {
        $connId = (int) $connection->id;
        $roomId = $this->roomManager->findRoomIdByConnId($worker, $connId);
        if ($roomId === null || !isset($worker->rooms[$roomId])) {
            return null;
        }

        $room = $worker->rooms[$roomId];
        $player = $room['players'][$connId] ?? null;
        if (!is_array($player) || ($player['status'] ?? '') !== 'active') {
            return null;
        }

        if (($room['password_hash'] ?? null) === null) {
            return null;
        }

        $status = $room['status'] ?? '';
        if (!in_array($status, ['waiting', 'playing', 'apartment'], true)) {
            return null;
        }

        return ['room_id' => $roomId, 'room' => $room];
    }

    public function handleRoomMessage(array $data, object $connection, object $worker): void
    {
        $resolved = $this->resolvePasswordRoom($worker, $connection);
        if ($resolved === null) {
            sendError($connection, 'error.chat_unavailable', 'Chat is only available in password-protected rooms');
            return;
        }

        $text = $data['text'] ?? null;
        if (!is_string($text)) {
            sendError($connection, 'error.chat_message_invalid', 'Invalid chat message');
            return;
        }

        $text = trim($text);
        if ($text === '' || mb_strlen($text) > Constants::CHAT_MESSAGE_MAX_CHARS) {
            sendError($connection, 'error.chat_message_invalid', 'Invalid chat message');
            return;
        }

        $room = &$worker->rooms[$resolved['room_id']];
        $from = (string) ($room['players'][(int) $connection->id]['username'] ?? $connection->username ?? '');

        broadcastToRoom($room, [
            'type' => 'room_message',
            'from' => $from,
            'text' => $text,
            'ts'   => time(),
        ]);

        serverLog($this->logger, 'INFO', "Chat message room_id={$resolved['room_id']} from={$from}");
    }
}
