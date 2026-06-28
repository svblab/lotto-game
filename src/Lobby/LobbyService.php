<?php

namespace Lotto\Lobby;

use Lotto\Core\Constants;
use Lotto\Core\Logger;
use Lotto\Core\RoomManager;
use Workerman\Timer;

use function Lotto\Core\sendJson;
use function Lotto\Core\sendError;
use function Lotto\Core\broadcastToRoom;

/**
 * LobbyService — EPIC-2.1 / EPIC-2.2 / EPIC-2.3 / EPIC-2.4 / EPIC-2.6
 *
 * Бизнес-логика создания, входа и выхода из комнаты (create_room, join_room, leave_room).
 * Инфраструктура комнат (хранение, уничтожение, поиск) — RoomManager (EPIC-2.0).
 *
 * Контракт пакетов ANCHOR_PROTOCOL.md § Lobby:
 *   create_room   → Client → Server
 *   join_room     → Client → Server
 *   leave_room    → Client → Server
 *   room_list     → Client → Server / Server → Client
 *   room_joined   → Server → Client (входящему игроку)
 *   player_joined → Server → Room  (остальным игрокам)
 *   player_left   → Server → Room  (остальным при выходе)
 *
 * Проверяемые лимиты (ANCHOR_CORE.md Part 1):
 *   MAX_ROOMS         = 30  — общее количество комнат
 *   MAX_TOTAL_PLAYERS = 150 — сумма игроков по всем комнатам
 *
 * Экономика (ANCHOR_CORE.md Part 2 § Reservation Rule):
 *   Создание/вход в комнату НЕ списывает монеты. Только start_game() (EPIC-4.3).
 *   Выход из waiting: монеты не трогаем (ещё не списывались).
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
     * Обрабатывает пакет {"action": "room_list"}.
     *
     * Контракт (ANCHOR_PROTOCOL.md § Lobby → room_list):
     *   Client → Server: {"action": "room_list"}
     *   Server → Client: {"type": "room_list", "rooms": [...]}
     *
     * Room entry:
     *   {"room_id": 7, "players": 3, "max_players": 10, "has_password": false, "status": "waiting"}
     *
     * Требования:
     *   - Аутентификация обязательна (ANCHOR_CORE.md Part 3 § Auth Guard).
     *   - Возвращаются все комнаты в любом статусе (waiting / playing / apartment).
     *   - Формирование entry делегировано RoomManager::buildRoomListEntry() (EPIC-2.0).
     */
    public function handleRoomList(object $connection, object $worker): void
    {
        // --- 1. Аутентификация ---
        if (empty($connection->userId)) {
            sendError($connection, 'error.auth_required', 'Authentication required');
            return;
        }

        // --- 2. Формируем список комнат ---
        $rooms = [];
        foreach ($worker->rooms ?? [] as $room) {
            $rooms[] = $this->roomManager->buildRoomListEntry($room);
        }

        // --- 3. Отправляем ответ ---
        sendJson($connection, [
            'type'  => 'room_list',
            'rooms' => $rooms,
        ]);
    }

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

        // --- 11. Lobby AFK таймер ---
        // Контракт: ANCHOR_CORE.md § Lobby AFK Timer.
        // Запускается/рестартуется когда в комнате стало >= 2 игроков.
        // Предыдущий таймер отменяется перед созданием нового (max 1/room).
        if (count($room['players']) >= 2) {
            $this->startLobbyAfkTimer($worker, $roomId);
        }
    }

    /**
     * Обрабатывает пакет {"action": "leave_room"}.
     *
     * Контракт входного пакета (ANCHOR_PROTOCOL.md § Lobby → leave_room):
     *   {"action": "leave_room"}  — без параметров
     *
     * Разрешён только в статусе 'waiting' (ANCHOR_CORE.md Part 4 § State Machine).
     * В статусе 'playing' выход обрабатывается GameService (EPIC-5.x).
     *
     * Последовательность:
     *   1. Найти комнату игрока
     *   2. removePlayerFromLobby() — удалить, broadcast player_left
     *   3. Если комната пуста → destroyRoom()
     *   4. Если ушёл хост и игроки остались → transferHost()
     *
     * Экономика: монеты не затронуты (в waiting ещё не списывались).
     * Именование: removePlayerFromLobby() — реестр ANCHOR_CORE.md Part 6.
     */
    public function handleLeaveRoom(object $connection, object $worker): void
    {
        // --- 1. Аутентификация ---
        if (empty($connection->userId)) {
            sendError($connection, 'error.auth_required', 'Authentication required');
            return;
        }

        $connId = $connection->id;

        // --- 2. Найти комнату ---
        $roomId = $this->roomManager->findRoomIdByConnId($worker, $connId);
        if ($roomId === null) {
            // Игрок не в комнате — silently ignore (idempotent)
            return;
        }

        $room = &$worker->rooms[$roomId];

        // --- 3. Только статус 'waiting' ---
        // В 'playing' выход обрабатывает GameService
        if ($room['status'] !== 'waiting') {
            return;
        }

        $wasHost = ($room['host_conn_id'] === $connId);

        // --- 4. Удалить игрока из комнаты ---
        $this->removePlayerFromLobby($worker, $roomId, $connId, 'leave');

        // После удаления — проверяем состояние комнаты
        if (!isset($worker->rooms[$roomId])) {
            return;
        }

        // --- 4a. Остановить AFK таймер если игроков < 2 ---
        // Контракт: ANCHOR_CORE.md § Lobby AFK Timer — Destroyed when player count <2.
        if (count($worker->rooms[$roomId]['players']) < 2) {
            $this->stopLobbyAfkTimer($worker, $roomId);
        }

        // --- 5. Передача хоста если ушёл хост ---
        if ($wasHost) {
            $this->transferHost($worker, $roomId);
        }
    }

    /**
     * Удаляет игрока из комнаты в статусе 'waiting', рассылает player_left.
     * Если после удаления игроков не осталось — уничтожает комнату.
     *
     * Именование: ANCHOR_CORE.md Part 6 § Function Names.
     * Причина: ANCHOR_CORE.md Part 1 § Removal Reasons — 'leave'.
     *
     * @param object $worker
     * @param int    $roomId
     * @param int    $connId  connection->id удаляемого игрока
     * @param string $reason  Причина из реестра: leave | disconnect | kicked | banned | admin_close
     */
    public function removePlayerFromLobby(object $worker, int $roomId, int $connId, string $reason): void
    {
        if (!isset($worker->rooms[$roomId]['players'][$connId])) {
            return;
        }

        $room = &$worker->rooms[$roomId];
        $playerEntry = $room['players'][$connId];
        $username = $playerEntry['username'];

        // Сохраняем в историю до удаления (ANCHOR_CORE.md Part 4 § Removal Rules,
        // Part 2 § Admin Close Room / No Survivors — all_players_history используется
        // для возврата монет. В waiting total_paid=0, но контракт обязателен.)
        $room['all_players_history'][$connId] = [
            'user_id'    => $playerEntry['user_id'],
            'username'   => $playerEntry['username'],
            'total_paid' => $playerEntry['total_paid'],
        ];

        // Удаляем из players
        unset($room['players'][$connId]);

        // Удаляем из drawer_order
        $room['drawer_order'] = array_values(
            array_filter($room['drawer_order'], fn($id) => $id !== $connId)
        );

        $this->logger->info(
            "Player username={$username} removed from lobby room_id={$roomId} reason={$reason}"
        );

        // Если комната опустела — уничтожаем
        if (empty($room['players'])) {
            $this->roomManager->destroyRoom($worker, $roomId);
            return;
        }

        // Рассылаем player_left оставшимся активным игрокам
        // Контракт: ANCHOR_PROTOCOL.md § Lobby → player_left
        $packet = [
            'type'     => 'player_left',
            'username' => $username,
            'reason'   => $reason,
        ];

        foreach ($room['players'] as $player) {
            if ($player['status'] === 'active') {
                sendJson($player['connection'], $packet);
            }
        }
    }

    /**
     * Передаёт хост следующему активному игроку по FIFO из drawer_order.
     * Вызывается когда текущий хост покидает комнату (статус 'waiting').
     *
     * Именование: ANCHOR_CORE.md Part 6 § Function Names.
     * Правило: ANCHOR_CORE.md Part 4 § Host Rules — новый хост = следующий активный FIFO.
     *
     * Если активных игроков нет (все disconnected) — destroyRoom().
     */
    public function transferHost(object $worker, int $roomId): void
    {
        if (!isset($worker->rooms[$roomId])) {
            return;
        }

        $room = &$worker->rooms[$roomId];

        // Ищем первого активного игрока из drawer_order (FIFO)
        foreach ($room['drawer_order'] as $connId) {
            if (
                isset($room['players'][$connId]) &&
                $room['players'][$connId]['status'] === 'active'
            ) {
                $room['host_conn_id'] = $connId;
                $newHostUsername = $room['players'][$connId]['username'];

                $this->logger->info(
                    "Host transferred in room_id={$roomId} new_host={$newHostUsername}"
                );
                return;
            }
        }

        // Нет активных игроков — уничтожаем комнату
        $this->roomManager->destroyRoom($worker, $roomId);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Запускает (или рестартует) lobby AFK таймер для комнаты.
     *
     * Контракт: ANCHOR_CORE.md § Lobby AFK Timer.
     *   Owner: room. Interval: 1s repeat. Threshold: LOBBY_HOST_TIMEOUT (120s).
     *   Action: transferHost() если host.last_action устарел.
     *   Max 1 на комнату — предыдущий отменяется перед созданием.
     *
     * Вызывается из handleJoinRoom() когда count(players) >= 2.
     *
     * @param object $worker
     * @param int    $roomId
     */
    private function startLobbyAfkTimer(object $worker, int $roomId): void
    {
        if (!isset($worker->rooms[$roomId])) {
            return;
        }

        $room = &$worker->rooms[$roomId];

        // Отменяем предыдущий таймер (max 1/room)
        if (!empty($room['lobby_afk_timer_id'])) {
            Timer::del($room['lobby_afk_timer_id']);
            $room['lobby_afk_timer_id'] = null;
        }

        $timerId = Timer::add(1, function () use ($worker, $roomId): void {
            if (!isset($worker->rooms[$roomId])) {
                return;
            }

            $room = &$worker->rooms[$roomId];

            // Таймер актуален только в статусе waiting
            if ($room['status'] !== 'waiting') {
                $this->stopLobbyAfkTimer($worker, $roomId);
                return;
            }

            $hostConnId = $room['host_conn_id'];
            if (!isset($room['players'][$hostConnId])) {
                return;
            }

            $hostLastAction = $room['players'][$hostConnId]['last_action'];

            if ((time() - $hostLastAction) >= Constants::LOBBY_HOST_TIMEOUT) {
                $this->logger->info(
                    "Lobby AFK: host timed out in room_id={$roomId}, transferring host"
                );
                $this->transferHost($worker, $roomId);
            }
        });

        $room['lobby_afk_timer_id'] = $timerId;
    }

    /**
     * Останавливает lobby AFK таймер комнаты.
     *
     * Контракт: ANCHOR_CORE.md § Lobby AFK Timer — Destroyed when player count <2.
     * Вызывается из handleLeaveRoom() когда count(players) < 2.
     * destroyRoom() уже отменяет таймер сам — здесь только для count<2 случая.
     *
     * @param object $worker
     * @param int    $roomId
     */
    private function stopLobbyAfkTimer(object $worker, int $roomId): void
    {
        if (!isset($worker->rooms[$roomId])) {
            return;
        }

        $room = &$worker->rooms[$roomId];

        if (!empty($room['lobby_afk_timer_id'])) {
            Timer::del($room['lobby_afk_timer_id']);
            $room['lobby_afk_timer_id'] = null;
        }
    }

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
     * @param string|object $host  username хоста (string) или connection хоста (object, для create_room)
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