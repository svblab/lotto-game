<?php

namespace Lotto\Core;

use Workerman\Timer;

/**
 * RoomManager — EPIC-2.0
 *
 * Инфраструктура жизненного цикла комнат: создание, уничтожение, поиск.
 * Бизнес-логика лобби (join/leave/start_game) находится в LobbyService (EPIC-2.1+).
 *
 * Хранение: $worker->rooms[roomId] = [...] — структура из ANCHOR_CORE.md Part 1.
 * Таймеры: при уничтожении комнаты отменяются все timer-поля согласно ANCHOR_CORE.md Part 5.
 *
 * Публичный контракт (другие модули вызывают только эти методы):
 *   createRoom(object $worker, int $hostConnId, int $maxPlayers, ?string $passwordHash, int $cardsCount): int
 *   destroyRoom(object $worker, int $roomId): void
 *   findRoomIdByConnId(object $worker, int $connId): ?int
 *   findRoomIdByUserId(object $worker, int $userId): ?int
 *   getTotalPlayerCount(object $worker): int
 *   buildRoomListEntry(array $room): array
 *
 * Naming: все ключи строго из реестра ANCHOR_CORE.md Part 6.
 */
final class RoomManager
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    // -------------------------------------------------------------------------
    // Room creation
    // -------------------------------------------------------------------------

    /**
     * Создаёт новую комнату в worker-памяти и возвращает её room_id.
     *
     * Контракт структуры: ANCHOR_CORE.md Part 1 § Room Structure.
     * Начальный статус: 'waiting'. Bank: 0.
     * Хост и owner: hostConnId. Drawer: null (устанавливается при start_game).
     *
     * @param object   $worker       Workerman worker
     * @param int      $hostConnId   connection->id хоста комнаты
     * @param int      $maxPlayers   1–10 (валидируется в LobbyService)
     * @param string|null $passwordHash  bcrypt-хеш пароля или null
     * @return int room_id
     */
    public function createRoom(
        object $worker,
        int $hostConnId,
        int $maxPlayers,
        ?string $passwordHash
    ): int {
        if (!isset($worker->rooms)) {
            $worker->rooms = [];
        }

        $roomId = $this->generateRoomId($worker);

        $worker->rooms[$roomId] = [
            'room_id'               => $roomId,
            'host_conn_id'          => $hostConnId,
            'bet_per_card'          => Constants::BET_PER_CARD,
            'max_players'           => $maxPlayers,
            'password_hash'         => $passwordHash,
            'status'                => 'waiting',
            'bank'                  => 0,
            'apartment_fired'       => false,
            'pause_for_apartment'   => false,
            'apartment_responses'   => [],
            'game_afk_timer_id'     => null,
            'apartment_timer_id'    => null,
            'lobby_afk_timer_id'    => null,
            'active_drawer_conn_id' => null,
            'drawer_order'          => [],
            'bag'                   => [],
            'drawn_numbers'         => [],
            'players'               => [],
            'all_players_history'   => [],
        ];

        $this->logger->info("Room created: room_id={$roomId} host_conn_id={$hostConnId} max_players={$maxPlayers}");

        return $roomId;
    }

    // -------------------------------------------------------------------------
    // Room destruction
    // -------------------------------------------------------------------------

    /**
     * Уничтожает комнату: отменяет все таймеры, удаляет из worker-памяти.
     *
     * Порядок очистки строго по ANCHOR_CORE.md Part 5 § Room Destruction Cleanup:
     *   1. lobby_afk_timer_id
     *   2. game_afk_timer_id
     *   3. apartment_timer_id
     *   4. reconnect_timer у каждого игрока
     *   5. unset($worker->rooms[$roomId])
     *
     * Вызов с несуществующим roomId — no-op (безопасно).
     */
    public function destroyRoom(object $worker, int $roomId): void
    {
        if (!isset($worker->rooms[$roomId])) {
            return;
        }

        $room = $worker->rooms[$roomId];

        // Отменяем room-level таймеры
        if (!empty($room['lobby_afk_timer_id'])) {
            Timer::del($room['lobby_afk_timer_id']);
        }
        if (!empty($room['game_afk_timer_id'])) {
            Timer::del($room['game_afk_timer_id']);
        }
        if (!empty($room['apartment_timer_id'])) {
            Timer::del($room['apartment_timer_id']);
        }

        // Отменяем player-level reconnect таймеры
        foreach ($room['players'] as $player) {
            if (!empty($player['reconnect_timer'])) {
                Timer::del($player['reconnect_timer']);
            }
        }

        unset($worker->rooms[$roomId]);

        $this->logger->info("Room destroyed: room_id={$roomId}");
    }

    // -------------------------------------------------------------------------
    // Lookup methods
    // -------------------------------------------------------------------------

    /**
     * Ищет room_id по connection ID (conn_id).
     * Используется в обработчиках пакетов, которым известен только $connection->id.
     *
     * @return int|null room_id или null если соединение не в комнате
     */
    public function findRoomIdByConnId(object $worker, int $connId): ?int
    {
        if (empty($worker->rooms)) {
            return null;
        }

        foreach ($worker->rooms as $roomId => $room) {
            if (isset($room['players'][$connId])) {
                return $roomId;
            }
        }

        return null;
    }

    /**
     * Ищет room_id по user_id игрока.
     * Используется при reconnect для восстановления состояния.
     *
     * @return int|null room_id или null если пользователь не в комнате
     */
    public function findRoomIdByUserId(object $worker, int $userId): ?int
    {
        if (empty($worker->rooms)) {
            return null;
        }

        foreach ($worker->rooms as $roomId => $room) {
            foreach ($room['players'] as $player) {
                if ($player['user_id'] === $userId) {
                    return $roomId;
                }
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Capacity / listing helpers
    // -------------------------------------------------------------------------

    /**
     * Суммирует количество игроков во всех комнатах.
     * Используется для проверки MAX_TOTAL_PLAYERS перед созданием/входом.
     */
    public function getTotalPlayerCount(object $worker): int
    {
        if (empty($worker->rooms)) {
            return 0;
        }

        $total = 0;
        foreach ($worker->rooms as $room) {
            $total += count($room['players']);
        }
        return $total;
    }

    /**
     * Формирует entry для пакета room_list.
     * Контракт: ANCHOR_PROTOCOL.md § Lobby → room_list → Room entry.
     *
     * {"room_id": 7, "players": 3, "max_players": 10, "has_password": false, "status": "waiting"}
     */
    public function buildRoomListEntry(array $room): array
    {
        return [
            'room_id'     => $room['room_id'],
            'players'     => count($room['players']),
            'max_players' => $room['max_players'],
            'has_password' => $room['password_hash'] !== null,
            'status'      => $room['status'],
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Генерирует уникальный room_id в диапазоне 1–MAX_ROOMS.
     * Перебор начинается с 1, возвращает первый свободный слот.
     *
     * Бросает \RuntimeException если все слоты заняты (должно быть
     * проверено на уровне LobbyService до вызова createRoom).
     */
    private function generateRoomId(object $worker): int
    {
        for ($id = 1; $id <= Constants::MAX_ROOMS; $id++) {
            if (!isset($worker->rooms[$id])) {
                return $id;
            }
        }

        throw new \RuntimeException('No available room slots (MAX_ROOMS reached)');
    }
}
