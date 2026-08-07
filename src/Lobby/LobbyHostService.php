<?php

namespace Lotto\Lobby;

use Lotto\Core\Constants;
use Lotto\Core\Logger;
use Lotto\Core\RoomManager;

use function Lotto\Core\sendJson;
use function Lotto\Core\lottoTimerAdd;
use function Lotto\Core\lottoTimerDel;

/**
 * LobbyHostService — EPIC-26.0 (ADR-024)
 *
 * Lobby host lifecycle / lobby AFK timer cluster extracted from LobbyService.
 */
final class LobbyHostService
{
    private RoomManager $roomManager;
    private Logger $logger;

    public function __construct(RoomManager $roomManager, Logger $logger)
    {
        $this->roomManager = $roomManager;
        $this->logger       = $logger;
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
        if (!isset($worker->rooms[$roomId])) {
            return;
        }

        $room = &$worker->rooms[$roomId];

        // Позиция текущего хоста в drawer_order. Ищем кандидата СТРОГО
        // ПОСЛЕ неё — не с начала массива (это и было причиной бага:
        // поиск с начала всегда находил первого активного игрока, отличного
        // от текущего хоста, что приводило к пинг-понгу между двумя первыми
        // игроками вместо продвижения по очереди).
        $currentIndex = array_search($room['host_conn_id'], $room['drawer_order'], true);
        $startIndex   = ($currentIndex === false) ? 0 : $currentIndex + 1;
        $order        = $room['drawer_order'];

        for ($i = $startIndex; $i < count($order); $i++) {
            $connId = $order[$i];
            if (
                isset($room['players'][$connId]) &&
                $room['players'][$connId]['status'] === 'active'
            ) {
                $room['host_conn_id'] = $connId;
                $newHostUsername = $room['players'][$connId]['username'];

                // Lobby AFK timer checks host.host_activity_at — refresh on promotion so
                // a player with stale activity is not immediately re-transferred.
                $room['players'][$connId]['host_activity_at'] = time();

                $this->broadcastHostChanged($room);

                $this->logger->info(
                    "Host transferred in room_id={$roomId} new_host={$newHostUsername}"
                );

                // Brand-new 120s window for the promoted host (ADR-011).
                $this->startLobbyAfkTimer($worker, $roomId);
                return;
            }
        }

        // Очередь исчерпана вперёд по FIFO: либо активных игроков не
        // осталось вовсе, либо все оставшиеся уже были хостом и не начали
        // игру (ADR-011: host-candidate queue exhausted).
        $this->closeRoomAfkExhausted($worker, $roomId);
    }

    /**
     * Принудительно удаляет всех оставшихся игроков (reason='afk') и
     * уничтожает комнату, когда очередь кандидатов на хоста в
     * transferHost() исчерпана вперёд по FIFO без единого start_game().
     *
     * ADR-011. Переиспользует существующий пакет player_left и
     * существующую причину 'afk' из реестра ANCHOR_CORE.md Part 1 §
     * Removal Reasons — новых пакетов/причин не вводится (Rule 7).
     * Экономика не затронута: в 'waiting' total_paid всегда 0
     * (ANCHOR_CORE.md Part 2 § Reservation Rule), рефанд не требуется.
     */
    private function closeRoomAfkExhausted(object $worker, int $roomId): void
    {
        if (!isset($worker->rooms[$roomId])) {
            return;
        }

        $room = &$worker->rooms[$roomId];

        foreach ($room['players'] as $player) {
            if (($player['status'] ?? null) === 'active' && isset($player['connection'])) {
                sendJson($player['connection'], $this->buildPlayerLeftPacket(
                    (string) $player['username'],
                    (int) ($player['user_id'] ?? 0),
                    'afk'
                ));
            }
        }

        $this->logger->info(
            "Lobby AFK: host candidate queue exhausted in room_id={$roomId}, " .
            'closing room and removing ' . count($room['players']) . ' player(s)'
        );

        $this->roomManager->destroyRoom($worker, $roomId, 'afk');
        $this->broadcastRoomList($worker);
    }

    /**
     * Запускает (или рестартует) lobby AFK таймер для комнаты.
     *
     * Контракт: ANCHOR_CORE.md § Lobby AFK Timer.
     *   Owner: room. Interval: 1s repeat. Threshold: LOBBY_HOST_TIMEOUT (120s).
     *   Action: transferHost() если host.host_activity_at устарел.
     *   Max 1 на комнату — предыдущий отменяется перед созданием.
     *
     * Вызывается из handleJoinRoom() on the 1→2 transition and from
     * transferHost() after host promotion.
     *
     * @param object $worker
     * @param int    $roomId
     */
    public function startLobbyAfkTimer(object $worker, int $roomId): void
    {
        if (!isset($worker->rooms[$roomId])) {
            return;
        }

        $room = &$worker->rooms[$roomId];

        // Отменяем предыдущий таймер (max 1/room)
        if (!empty($room['lobby_afk_timer_id'])) {
            lottoTimerDel((int) $room['lobby_afk_timer_id'], 'lobby_afk', ['room_id' => $roomId]);
            $room['lobby_afk_timer_id'] = null;
        }

        // Arm the visible 120s window when the lobby AFK timer starts (2nd player seated).
        $hostConnId = $room['host_conn_id'] ?? null;
        if ($hostConnId !== null && isset($room['players'][$hostConnId])) {
            $room['players'][$hostConnId]['host_activity_at'] = time();
            $this->broadcastHostChanged($room);
        }

        $timerId = lottoTimerAdd(Constants::afkTickInterval(), function () use ($worker, $roomId): void {
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

            $hostLastActivity = $room['players'][$hostConnId]['host_activity_at'] ?? 0;

            if ((time() - $hostLastActivity) >= Constants::lobbyHostTimeout()) {
                $this->logger->info(
                    "Lobby AFK: host timed out in room_id={$roomId}, transferring host"
                );
                $this->transferHost($worker, $roomId);
            }
        }, [], true, 'lobby_afk', ['room_id' => $roomId]);

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
            lottoTimerDel((int) $room['lobby_afk_timer_id'], 'lobby_afk', ['room_id' => $roomId]);
            $room['lobby_afk_timer_id'] = null;
        }
    }

    /**
     * Promotes the first FIFO player (room creator) to lobby host on the 1→2 transition.
     */
    public function promoteLobbyHost(object $worker, int $roomId): void
    {
        if (!isset($worker->rooms[$roomId])) {
            return;
        }

        $room = &$worker->rooms[$roomId];
        $firstConnId = $room['drawer_order'][0] ?? null;
        if ($firstConnId === null || !isset($room['players'][$firstConnId])) {
            return;
        }

        $room['host_conn_id'] = $firstConnId;
        $room['players'][$firstConnId]['host_activity_at'] = time();
        $this->broadcastHostChanged($room);
    }

    /**
     * Records genuine lobby host interaction (ADR-010).
     * Called from server.php for non-ping actions when the sender is host in waiting.
     */
    public function touchLobbyHostActivity(object $worker, int $connId): void
    {
        $roomId = $this->roomManager->findRoomIdByConnId($worker, $connId);
        if ($roomId === null || !isset($worker->rooms[$roomId])) {
            return;
        }

        $room = &$worker->rooms[$roomId];

        if (($room['status'] ?? null) !== 'waiting' || count($room['players']) < 2) {
            return;
        }

        if (($room['host_conn_id'] ?? null) !== $connId || !isset($room['players'][$connId])) {
            return;
        }

        $room['players'][$connId]['host_activity_at'] = time();
        $this->broadcastHostChanged($room);
    }

    /**
     * Drops lobby host privileges when fewer than two players remain (no AFK timer).
     */
    public function suspendLobbyHost(object $worker, int $roomId): void
    {
        if (!isset($worker->rooms[$roomId])) {
            return;
        }

        $this->stopLobbyAfkTimer($worker, $roomId);
        $this->broadcastHostChanged($worker->rooms[$roomId], '');
    }

    /**
     * Re-broadcast host + lobby AFK deadline so every client uses host.host_activity_at.
     */
    public function broadcastLobbyAfkSync(array $room): void
    {
        if (($room['status'] ?? null) !== 'waiting' || count($room['players'] ?? []) < 2) {
            return;
        }

        $this->broadcastHostChanged($room);
    }

    /**
     * @param array $room
     */
    private function broadcastHostChanged(array $room, ?string $hostUsername = null): void
    {
        $hostUsername ??= $this->resolveLobbyHostUsername($room);
        $packet = [
            'type' => 'host_changed',
            'host' => $hostUsername,
        ];
        $packet = array_merge($packet, $this->lobbyHostTimeoutFields($room));

        foreach ($room['players'] as $player) {
            if ($player['status'] === 'active') {
                sendJson($player['connection'], $packet);
            }
        }
    }

    /**
     * Host AFK window fields for clients (only when host is assigned, 2+ players).
     *
     * @return array<string, int>
     */
    public function lobbyHostTimeoutFields(array $room): array
    {
        $hostUsername = $this->resolveLobbyHostUsername($room);
        if ($hostUsername === '') {
            return [];
        }

        $hostConnId = $room['host_conn_id'] ?? null;
        if ($hostConnId === null || !isset($room['players'][$hostConnId])) {
            return [];
        }

        return [
            'host_timeout_start'   => (int) ($room['players'][$hostConnId]['host_activity_at'] ?? 0),
            'host_timeout_seconds' => Constants::lobbyHostTimeout(),
        ];
    }

    /**
     * Lobby host username is exposed to clients only when >=2 players are seated.
     */
    public function resolveLobbyHostUsername(array $room): string
    {
        if (count($room['players']) < 2) {
            return '';
        }

        $hostConnId = $room['host_conn_id'] ?? null;
        if ($hostConnId === null || !isset($room['players'][$hostConnId])) {
            return '';
        }

        return (string) $room['players'][$hostConnId]['username'];
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

    private function broadcastRoomList(object $worker): void
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
}
