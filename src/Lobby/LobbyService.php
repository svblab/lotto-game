<?php

namespace Lotto\Lobby;

use Lotto\Core\Constants;
use Lotto\Core\Logger;
use Lotto\Core\RoomManager;

use function Lotto\Core\sendJson;
use function Lotto\Core\sendError;
use function Lotto\Core\broadcastToRoom;

/**
 * LobbyService — EPIC-2.1 / EPIC-2.2
 *
 * Бизнес-логика создания и входа в комнату (create_room, join_room).
 * Инфраструктура комнат (хранение, уничтожение, поиск) — RoomManager (EPIC-2.0).
 *
 * Контракт пакетов ANCHOR_PROTOCOL.md § Lobby:
 *   create_room   → Client → Server
 *   join_room     → Client → Server
 *   room_joined   → Server → Client (входящему игроку)
 *   player_joined → Server → Room  (остальным игрокам)
 *
 * Проверяемые лимиты (ANCHOR_CORE.md Part 1):
 *   MAX_ROOMS         = 30  — общее количество комнат
 *   MAX_TOTAL_PLAYERS = 150 — сумма игроков по всем комнатам
 *
 * Экономика (ANCHOR_CORE.md Part 2 § Reservation Rule):
 *   Создание/вход в комнату НЕ списывает монеты. Только start_game() (EPIC-4.3).
 *
 * Worker-память:
 *   $worker->rooms           — array<roomId, room>  (управляется RoomManager)
 *   $worker->userConnections — array<userId, conn>  (управляется AuthHandler)
 */
final class LobbyService
{
    private RoomManager $roomManager;
    private Logger $logger;

    public function __construct(RoomManager $roomManager, Logger $logger)
    {
        $this->roomManager = $roomManager;
        $this->logger = $logger;
    }

    // -------------------------------------------------------------------------
    // Public action handlers
    // -------------------------------------------------------------------------

    /**
     * Обрабатывает пакет {"action": "create_room"}.
     *
     * Контракт входного пакета (ANCHOR_PROTOCOL.md § Lobby → create_room):
     *   {"action": "create_room", "max_players": 10, "password": "", "cards_count": 1}
     *   cards_count: 1 или 2
     *
     * Успех → создаёт комнату, добавляет хоста как игрока, отправляет room_joined.
     * Ошибка → error (error.server_full | error.room_limit) или валидационная ошибка.
     *
     * Предусловия (проверяются здесь):
     *   1. Пользователь аутентифицирован ($connection->userId установлен)
     *   2. Количество комнат < MAX_ROOMS
     *   3. Общее количество игроков < MAX_TOTAL_PLAYERS
     *   4. cards_count ∈ {1, 2}
     *   5. max_players ∈ [2, 10]
     *
     * Пользователь не должен уже находиться в другой комнате
     * (проверка делегирована router'у в server.php, EPIC-10.4).
     */
    public function handleCreateRoom(array $data, object $connection, object $worker): void
    {
        // --- 1. Проверка аутентификации ---
        if (empty($connection->userId)) {
            sendError($connection, 'error.auth_required', 'Authentication required');
            return;
        }

        // --- 2. Лимит комнат ---
        $roomCount = isset($worker->rooms) ? count($worker->rooms) : 0;
        if ($roomCount >= Constants::MAX_ROOMS) {
            sendError($connection, 'error.room_limit', 'Maximum number of rooms reached');
            return;
        }

        // --- 3. Лимит игроков ---
        $totalPlayers = $this->roomManager->getTotalPlayerCount($worker);
        if ($totalPlayers >= Constants::MAX_TOTAL_PLAYERS) {
            sendError($connection, 'error.server_full', 'Server is full');
            return;
        }

        // --- 4. Валидация cards_count ---
        $cardsCount = isset($data['cards_count']) ? (int)$data['cards_count'] : 1;
        if ($cardsCount !== 1 && $cardsCount !== 2) {
            sendError($connection, 'error.invalid_json', 'cards_count must be 1 or 2');
            return;
        }

        // --- 5. Валидация max_players ---
        $maxPlayers = isset($data['max_players']) ? (int)$data['max_players'] : 10;
        if ($maxPlayers < 2 || $maxPlayers > 10) {
            sendError($connection, 'error.invalid_json', 'max_players must be between 2 and 10');
            return;
        }

        // --- 6. Хеш пароля (опционально) ---
        $passwordRaw = $data['password'] ?? '';
        $passwordHash = (is_string($passwordRaw) && $passwordRaw !== '')
            ? password_hash($passwordRaw, PASSWORD_BCRYPT)
            : null;

        // --- 7. Создание комнаты ---
        $connId = $connection->id;
        $roomId = $this->roomManager->createRoom($worker, $connId, $maxPlayers, $passwordHash);

        // --- 8. Добавление хоста как первого игрока ---
        $worker->rooms[$roomId]['players'][$connId] = $this->buildPlayerEntry(
            $connection,
            $cardsCount
        );

        // Хост первым в drawer_order (ANCHOR_CORE.md § Drawer Order Rules)
        $worker->rooms[$roomId]['drawer_order'][] = $connId;

        $this->logger->info(
            "Room {$roomId} created by user_id={$connection->userId}" .
            " username={$connection->username} cards_count={$cardsCount} max_players={$maxPlayers}"
        );

        // --- 9. Ответный пакет room_joined ---
        // Контракт: ANCHOR_PROTOCOL.md § Lobby → room_joined
        sendJson($connection, $this->buildRoomJoinedPacket($worker->rooms[$roomId], $connection));
    }

    /**
     * Обрабатывает пакет {"action": "join_room"}.
     *
     * Контракт входного пакета (ANCHOR_PROTOCOL.md § Lobby → join_room):
     *   {"action": "join_room", "room_id": 7, "password": "", "cards_count": 2}
     *
     * Успех:
     *   → входящему игроку: room_joined (полный список игроков)
     *   → остальным игрокам в комнате: player_joined
     *
     * Предусловия:
     *   1. Пользователь аутентифицирован
     *   2. Комната существует и в статусе 'waiting'
     *   3. Комната не заполнена (players < max_players)
     *   4. Общий лимит игроков не достигнут
     *   5. Пароль верный (если установлен)
     *   6. cards_count ∈ {1, 2}
     */
    public function handleJoinRoom(array $data, object $connection, object $worker): void
    {
        // --- 1. Аутентификация ---
        if (empty($connection->userId)) {
            sendError($connection, 'error.auth_required', 'Authentication required');
            return;
        }

        // --- 2. Комната существует ---
        $roomId = isset($data['room_id']) ? (int)$data['room_id'] : 0;
        if (!isset($worker->rooms[$roomId])) {
            sendError($connection, 'error.room_not_found', 'Room not found');
            return;
        }

        $room = &$worker->rooms[$roomId];

        // --- 3. Статус комнаты — только 'waiting' ---
        if ($room['status'] !== 'waiting') {
            sendError($connection, 'error.room_not_found', 'Room is not open for joining');
            return;
        }

        // --- 4. Комната не заполнена ---
        if (count($room['players']) >= $room['max_players']) {
            sendError($connection, 'error.server_full', 'Room is full');
            return;
        }

        // --- 5. Общий лимит игроков ---
        $totalPlayers = $this->roomManager->getTotalPlayerCount($worker);
        if ($totalPlayers >= Constants::MAX_TOTAL_PLAYERS) {
            sendError($connection, 'error.server_full', 'Server is full');
            return;
        }

        // --- 6. Пароль ---
        if ($room['password_hash'] !== null) {
            $passwordRaw = $data['password'] ?? '';
            if (!is_string($passwordRaw) || !password_verify($passwordRaw, $room['password_hash'])) {
                sendError($connection, 'error.auth_invalid_credentials', 'Wrong room password');
                return;
            }
        }

        // --- 7. Валидация cards_count ---
        $cardsCount = isset($data['cards_count']) ? (int)$data['cards_count'] : 1;
        if ($cardsCount !== 1 && $cardsCount !== 2) {
            sendError($connection, 'error.invalid_json', 'cards_count must be 1 or 2');
            return;
        }

        // --- 8. Добавление игрока ---
        $connId = $connection->id;
        $room['players'][$connId] = $this->buildPlayerEntry($connection, $cardsCount);

        // Добавляем в конец drawer_order (ANCHOR_CORE.md § Drawer Order Rules: FIFO)
        $room['drawer_order'][] = $connId;

        $this->logger->info(
            "User user_id={$connection->userId} username={$connection->username}" .
            " joined room_id={$roomId} cards_count={$cardsCount}"
        );

        // --- 9. Новому игроку: room_joined ---
        $hostConnId = $room['host_conn_id'];
        $hostUsername = isset($room['players'][$hostConnId])
            ? $room['players'][$hostConnId]['username']
            : '';

        sendJson($connection, $this->buildRoomJoinedPacket($room, $hostUsername));

        // --- 10. Остальным игрокам: player_joined ---
        $playerJoinedPacket = [
            'type'        => 'player_joined',
            'username'    => $connection->username,
            'cards_count' => $cardsCount,
        ];

        foreach ($room['players'] as $pid => $player) {
            if ($pid !== $connId && $player['status'] === 'active') {
                sendJson($player['connection'], $playerJoinedPacket);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Строит запись игрока для $room['players'][$connId].
     * Структура: ANCHOR_CORE.md Part 1 § Player Structure.
     *
     * Поля cards/masks пусты — карты назначаются в start_game() (EPIC-4.1).
     * total_paid = 0 — резервирование монет происходит в start_game() (EPIC-4.3).
     * immune = false — устанавливается в логике apartment (EPIC-7.x).
     */
    private function buildPlayerEntry(object $connection, int $cardsCount): array
    {
        return [
            'user_id'         => $connection->userId,
            'username'        => $connection->username,
            'cards'           => [],
            'cards_count'     => $cardsCount,
            'total_paid'      => 0,
            'last_action'     => time(),
            'afk_start'       => null,
            'strikes'         => 0,
            'auto_draws'      => 0,
            'status'          => 'active',
            'session_token'   => $connection->sessionToken ?? '',
            'reconnect_timer' => null,
            'connection'      => $connection,
            'immune'          => false,
        ];
    }

    /**
     * Строит пакет room_joined для входящего игрока.
     * Контракт: ANCHOR_PROTOCOL.md § Lobby → room_joined.
     *
     * @param array         $room  Структура комнаты
     * @param string|object $host  username хоста (string) или connection (object, для create_room)
     */
    private function buildRoomJoinedPacket(array $room, string|object $host): array
    {
        $hostUsername = is_object($host) ? $host->username : $host;

        $players = [];
        foreach ($room['players'] as $player) {
            $players[] = [
                'username'    => $player['username'],
                'cards_count' => $player['cards_count'],
                'status'      => $player['status'],
            ];
        }

        return [
            'type'    => 'room_joined',
            'room_id' => $room['room_id'],
            'host'    => $hostUsername,
            'status'  => $room['status'],
            'bank'    => $room['bank'],
            'players' => $players,
        ];
    }
}