<?php

namespace Lotto\Lobby;

use Lotto\Core\Constants;
use Lotto\Core\Logger;
use Lotto\Core\RoomManager;

use function Lotto\Core\sendJson;
use function Lotto\Core\sendError;

/**
 * LobbyService — EPIC-2.1
 *
 * Бизнес-логика создания комнаты (action: create_room).
 * Инфраструктура комнат (хранение, уничтожение, поиск) — RoomManager (EPIC-2.0).
 *
 * Контракт пакета ANCHOR_PROTOCOL.md § Lobby:
 *   create_room  → Client → Server
 *   room_joined  → Server → Client
 *   player_joined → Server → Room (только при join; здесь не нужен — первый игрок)
 *
 * Проверяемые лимиты (ANCHOR_CORE.md Part 1):
 *   MAX_ROOMS         = 30  — общее количество комнат
 *   MAX_TOTAL_PLAYERS = 150 — сумма игроков по всем комнатам
 *
 * Экономика (ANCHOR_CORE.md Part 2 § Reservation Rule):
 *   Создание комнаты НЕ списывает монеты. Только start_game() (EPIC-4.3).
 *
 * Worker-память:
 *   $worker->rooms          — array<roomId, room>  (управляется RoomManager)
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
     * Строит пакет room_joined.
     * Контракт: ANCHOR_PROTOCOL.md § Lobby → room_joined.
     *
     * {"type": "room_joined", "room_id": 7, "host": "player1",
     *  "status": "waiting", "bank": 0, "players": [...]}
     *
     * Player entry:
     * {"username": "player", "cards_count": 2, "status": "active"}
     */
    private function buildRoomJoinedPacket(array $room, object $hostConnection): array
    {
        $hostUsername = $hostConnection->username;

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