<?php

namespace Lotto\Lobby;

use Lotto\Core\Constants;
use Lotto\Core\Logger;
use Lotto\Core\RoomManager;

use function Lotto\Core\sendJson;
use function Lotto\Core\sendError;
use function Lotto\Core\broadcastToRoom;
use function Lotto\Core\lottoTimerDel;
use function Lotto\Core\lottoStateReject;

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
 *   host_changed  → Server → Room  (всем активным при смене хоста)
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
    private LobbyHostService $hostService;

    public function __construct(RoomManager $roomManager, Logger $logger, LobbyHostService $hostService)
    {
        $this->roomManager = $roomManager;
        $this->logger = $logger;
        $this->hostService = $hostService;
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
        sendJson($connection, $this->buildRoomListPacket($worker));
    }

    /**
     * Рассылает актуальный room_list всем аутентифицированным клиентам.
     * Вызывается после create/join/leave/destroy, чтобы лобби синхронизировалось
     * без ручного запроса room_list.
     */
    public function broadcastRoomList(object $worker): void
    {
        $packet = $this->buildRoomListPacket($worker);

        foreach ($worker->connections ?? [] as $connection) {
            if (!empty($connection->userId)) {
                sendJson($connection, $packet);
            }
        }
    }

    /**
     * @return array{type: string, rooms: list<array<string, mixed>>}
     */
    private function buildRoomListPacket(object $worker): array
    {
        $rooms = [];
        foreach ($worker->rooms ?? [] as $room) {
            $rooms[] = $this->roomManager->buildRoomListEntry($room);
        }

        return [
            'type'  => 'room_list',
            'rooms' => $rooms,
        ];
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

        // ADR-001 / FIX-30: clear any stale seat before creating a new room.
        $this->removeExistingSeatForUser($worker, (int) $connection->userId, 'disconnect');

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
        sendJson($connection, $this->buildRoomJoinedPacket($worker->rooms[$roomId]));

        $this->broadcastRoomList($worker);
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
     * Предусловия (порядок проверок важен — FIX-7 / ADR-004):
     *   1. Пользователь аутентифицирован
     *   2. Комната существует и в статусе 'waiting'
     *   3. Общий лимит игроков не достигнут (error.server_full)
     *   4. Комната не заполнена (players < max_players) (error.room_full —
     *      отдельный код, НЕ error.server_full)
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

        $userId = (int) $connection->userId;
        $connId = (int) $connection->id;
        $existingConnId = $this->findConnIdForUserInRoom($room, $userId);

        if ($existingConnId !== null && $existingConnId !== $connId) {
            if (isset($worker->reconnectService)) {
                $worker->reconnectService->rebindSeat(
                    $worker,
                    $roomId,
                    $existingConnId,
                    $connection,
                    (string) ($connection->sessionToken ?? '')
                );
            }
            sendJson($connection, $this->buildRoomJoinedPacket($worker->rooms[$roomId]));
            return;
        }

        $otherRoomId = $this->roomManager->findRoomIdByUserId($worker, $userId);
        if ($otherRoomId !== null && $otherRoomId !== $roomId) {
            $this->removeExistingSeatForUser($worker, $userId, 'disconnect');
            $room = &$worker->rooms[$roomId];
        }

        // --- 3. Статус комнаты — только 'waiting' ---
        if ($room['status'] !== 'waiting') {
            lottoStateReject($roomId, $room['status'], 'join_room', 'error.room_not_found');
            sendError($connection, 'error.room_not_found', 'Room is not open for joining');
            return;
        }

        // --- 4. Общий лимит игроков (сервер) — проверяется ПЕРВЫМ (FIX-7 / ADR-004) ---
        $totalPlayers = $this->roomManager->getTotalPlayerCount($worker);
        if ($totalPlayers >= Constants::MAX_TOTAL_PLAYERS) {
            sendError($connection, 'error.server_full', 'Server is full');
            return;
        }

        // --- 5. Комната не заполнена (отдельный код от error.server_full, FIX-7 / ADR-004) ---
        if (count($room['players']) >= $room['max_players']) {
            sendError($connection, 'error.room_full', 'Room is full');
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

        // --- 9. Lobby AFK timer + host promotion (1→2 transition only) ---
        // Must run before room_joined so the joiner receives current host + timeout.
        $playerCount = count($room['players']);
        if ($playerCount === 2) {
            $this->promoteLobbyHost($worker, $roomId);
            $this->startLobbyAfkTimer($worker, $roomId);
            $room = &$worker->rooms[$roomId];
        }

        // --- 10. Новому игроку: room_joined ---
        sendJson($connection, $this->buildRoomJoinedPacket($room));

        // --- 11. Остальным игрокам: player_joined (with lobby AFK sync fields) ---
        $playerJoinedPacket = array_merge([
            'type'        => 'player_joined',
            'username'    => $connection->username,
            'cards_count' => $cardsCount,
        ], $this->lobbyHostTimeoutFields($room));
        $hostUsername = $this->resolveLobbyHostUsername($room);
        if ($hostUsername !== '') {
            $playerJoinedPacket['host'] = $hostUsername;
        }

        foreach ($room['players'] as $pid => $player) {
            if ($pid !== $connId && $player['status'] === 'active') {
                sendJson($player['connection'], $playerJoinedPacket);
            }
        }

        // --- 12. Re-sync lobby AFK countdown for everyone when 3rd+ player joins ---
        if ($playerCount > 2) {
            $this->broadcastLobbyAfkSync($room);
        }

        $this->broadcastRoomList($worker);
    }

    /**
     * Обрабатывает пакет {"action": "leave_room"}.
     *
     * Контракт входного пакета (ANCHOR_PROTOCOL.md § Lobby → leave_room):
     *   {"action": "leave_room"}  — без параметров
     *
     * Разрешён в статусе 'waiting' (ANCHOR_CORE.md Part 4 § State Machine).
     * В статусе 'playing' выход делегируется ReconnectService::removePlayerFromGame().
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

        // --- 3. waiting → LobbyService; playing/apartment → Game removal ---
        if (in_array($room['status'], ['playing', 'apartment'], true)) {
            if (isset($worker->reconnectService)) {
                $worker->reconnectService->removePlayerFromGame($worker, $roomId, $connId, 'leave');
            }
            return;
        }

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

        // --- 4a/5. Host + AFK when membership drops or host leaves ---
        $remaining = count($worker->rooms[$roomId]['players']);
        if ($remaining < 2) {
            $this->suspendLobbyHost($worker, $roomId);
        } elseif ($wasHost) {
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

        // FIX-6: отменить reconnect_timer ДО удаления игрока (ANCHOR_CORE.md
        // Part 5 § Timer Integrity Rules — "No reconnect timer survives
        // player removal"). Симметрично уже корректной
        // ReconnectService::removePlayerFromGame().
        if (!empty($playerEntry['reconnect_timer'])) {
            lottoTimerDel((int) $playerEntry['reconnect_timer'], 'reconnect', ['room_id' => $roomId, 'conn_id' => $connId]);
        }

        // Сохраняем в историю до удаления (ANCHOR_CORE.md Part 4 § Removal Rules,
        // Part 2 § Admin Close Room / No Survivors — all_players_history используется
        // для возврата монет. В waiting total_paid=0, но контракт обязателен.)
        $room['all_players_history'][$connId] = [
            'user_id'    => $playerEntry['user_id'],
            'username'   => $playerEntry['username'],
            'total_paid' => $playerEntry['total_paid'],
            'cards_count' => (int) ($playerEntry['cards_count'] ?? 1),
            'reason'     => $reason,
        ];

        if (($playerEntry['status'] ?? null) === 'active' && isset($playerEntry['connection'])) {
            sendJson($playerEntry['connection'], $this->buildPlayerLeftPacket(
                $username,
                (int) ($playerEntry['user_id'] ?? 0),
                $reason
            ));
        }

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
        } else {
            // Рассылаем player_left оставшимся активным игрокам
            // Контракт: ANCHOR_PROTOCOL.md § Lobby → player_left
            $packet = $this->buildPlayerLeftPacket($username, (int) ($playerEntry['user_id'] ?? 0), $reason);

            foreach ($room['players'] as $player) {
                if ($player['status'] === 'active') {
                    sendJson($player['connection'], $packet);
                }
            }
        }

        $this->broadcastRoomList($worker);
    }

    /**
     * Передаёт хост следующему активному игроку, идущему СТРОГО ПОСЛЕ
     * текущего хоста в drawer_order (FIFO по порядку входа в лобби).
     *
     * ADR-011: право хоста движется только вперёд по очереди и никогда не
     * возвращается к уже испробованным кандидатам.
     *
     * Именование: ANCHOR_CORE.md Part 6 § Function Names.
     * Правило: ANCHOR_CORE.md Part 4 § Host Rules — новый хост = следующий
     * активный FIFO (уточнено ADR-011: "следующий" = дальше по очереди,
     * не "первый активный, отличный от текущего").
     *
     * Если после текущего хоста в очереди не осталось ни одного
     * неиспробованного активного кандидата — очередь исчерпана (каждый
     * уже был хостом и не начал игру): комната принудительно закрывается,
     * все оставшиеся игроки удаляются с reason='afk' (существующий реестр
     * ANCHOR_CORE.md Part 1 § Removal Reasons — новая причина не вводится).
     */
    public function transferHost(object $worker, int $roomId): void
    {
        $this->hostService->transferHost($worker, $roomId);
    }

    private function startLobbyAfkTimer(object $worker, int $roomId): void
    {
        $this->hostService->startLobbyAfkTimer($worker, $roomId);
    }

    private function promoteLobbyHost(object $worker, int $roomId): void
    {
        $this->hostService->promoteLobbyHost($worker, $roomId);
    }

    public function touchLobbyHostActivity(object $worker, int $connId): void
    {
        $this->hostService->touchLobbyHostActivity($worker, $connId);
    }

    private function suspendLobbyHost(object $worker, int $roomId): void
    {
        $this->hostService->suspendLobbyHost($worker, $roomId);
    }

    public function broadcastLobbyAfkSync(array $room): void
    {
        $this->hostService->broadcastLobbyAfkSync($room);
    }

    private function lobbyHostTimeoutFields(array $room): array
    {
        return $this->hostService->lobbyHostTimeoutFields($room);
    }

    private function resolveLobbyHostUsername(array $room): string
    {
        return $this->hostService->resolveLobbyHostUsername($room);
    }

    /**
     * True when the same user_id is already seated via a different connection.
     */
    private function hasDuplicateSeatForUser(object $worker, int $userId, int $connId): bool
    {
        $roomId = $this->roomManager->findRoomIdByUserId($worker, $userId);
        if ($roomId === null) {
            return false;
        }

        foreach ($worker->rooms[$roomId]['players'] as $playerConnId => $player) {
            if ((int) ($player['user_id'] ?? 0) === $userId && (int) $playerConnId !== $connId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Removes the user's room seat if present (one seat per user_id).
     */
    public function removeExistingSeatForUser(object $worker, int $userId, string $reason): void
    {
        $roomId = $this->roomManager->findRoomIdByUserId($worker, $userId);
        if ($roomId === null || !isset($worker->rooms[$roomId]['players'])) {
            return;
        }

        foreach ($worker->rooms[$roomId]['players'] as $connId => $player) {
            if ((int) ($player['user_id'] ?? 0) !== $userId) {
                continue;
            }

            $status = $worker->rooms[$roomId]['status'] ?? 'waiting';
            if ($status === 'waiting') {
                $this->removePlayerFromLobby($worker, $roomId, (int) $connId, $reason);
            } elseif (isset($worker->reconnectService)) {
                $worker->reconnectService->removePlayerFromGame($worker, $roomId, (int) $connId, $reason);
            }
            return;
        }
    }

    /**
     * @return int|null conn_id of an existing seat for $userId in $room
     */
    private function findConnIdForUserInRoom(array $room, int $userId): ?int
    {
        foreach ($room['players'] as $connId => $player) {
            if ((int) ($player['user_id'] ?? 0) === $userId) {
                return (int) $connId;
            }
        }

        return null;
    }

    /**
     * @return array{type: string, username: string, user_id: int, reason: string}
     */
    private function buildPlayerLeftPacket(string $username, int $userId, string $reason): array
    {
        $packet = [
            'type'     => 'player_left',
            'username' => $username,
            'reason'   => $reason,
        ];
        if ($userId > 0) {
            $packet['user_id'] = $userId;
        }

        return $packet;
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
            'last_action'       => time(),
            'host_activity_at'  => time(),
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
     */
    private function buildRoomJoinedPacket(array $room): array
    {
        $players = [];
        foreach ($room['players'] as $player) {
            $players[] = [
                'username'    => $player['username'],
                'cards_count' => $player['cards_count'],
                'status'      => $player['status'],
            ];
        }

        return array_merge([
            'type'    => 'room_joined',
            'room_id' => $room['room_id'],
            'host'    => $this->resolveLobbyHostUsername($room),
            'status'  => $room['status'],
            'bank'    => $room['bank'],
            'players' => $players,
        ], $this->lobbyHostTimeoutFields($room));
    }
}