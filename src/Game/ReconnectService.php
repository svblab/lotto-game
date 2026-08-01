<?php

declare(strict_types=1);

namespace Lotto\Game;

use Lotto\Core\Constants;

use function Lotto\Core\sendJson;
use function Lotto\Core\lottoTimerAdd;
use function Lotto\Core\lottoTimerDel;
use function Lotto\Core\lottoPlayerStateTransition;

/**
 * ReconnectService — EPIC-8.0 / 8.1 / 8.2 / 8.3 / 8.4 / 8.5
 *
 * Зона ответственности:
 * - обработка временного disconnect в waiting/playing,
 * - восстановление игрока по reconnect token,
 * - game AFK protection: warning -> auto draw -> remove('afk').
 */
final class ReconnectService
{
    private object $lobbyService;
    private object $gameService;
    private object $logger;

    public function __construct(
        object $lobbyService,
        object $gameService,
        object $logger
    ) {
        $this->lobbyService = $lobbyService;
        $this->gameService  = $gameService;
        $this->logger       = $logger;
    }

    /**
     * EPIC-8.1: обработка потери соединения игрока.
     * waiting/playing -> disconnected + reconnect timer.
     * apartment -> немедленное удаление (reason=disconnect).
     */
    public function handleDisconnect(object $connection, object $worker): void
    {
        $connId = (int)$connection->id;
        $roomId = $this->findRoomIdByConnId($worker, $connId);
        if ($roomId === null) {
            return;
        }

        $room = &$worker->rooms[$roomId];
        if (!isset($room['players'][$connId])) {
            return;
        }

        $status = $room['status'] ?? 'waiting';
        if ($status === 'apartment') {
            $this->removePlayerFromGame($worker, $roomId, $connId, 'disconnect');
            return;
        }

        if ($status !== 'waiting' && $status !== 'playing') {
            return;
        }

        $room['players'][$connId]['status'] = 'disconnected';
        lottoPlayerStateTransition($roomId, $connId, 'active', 'disconnected', 'connection_lost');
        $room['players'][$connId]['connection'] = $connection;

        if (!empty($room['players'][$connId]['reconnect_timer'])) {
            lottoTimerDel((int) $room['players'][$connId]['reconnect_timer'], 'reconnect', [
                'room_id' => $roomId,
                'conn_id' => $connId,
            ]);
        }

        $timerId = lottoTimerAdd(
            (float) Constants::reconnectTimeout(),
            function () use ($worker, $roomId, $connId): void {
                if (!isset($worker->rooms[$roomId]['players'][$connId])) {
                    return;
                }

                $room = &$worker->rooms[$roomId];
                if (($room['players'][$connId]['status'] ?? null) !== 'disconnected') {
                    return;
                }

                if (($room['status'] ?? 'waiting') === 'waiting') {
                    $this->lobbyService->removePlayerFromLobby($worker, $roomId, $connId, 'disconnect');
                    return;
                }

                $this->removePlayerFromGame($worker, $roomId, $connId, 'disconnect');
            },
            [],
            false,
            'reconnect',
            ['room_id' => $roomId, 'conn_id' => $connId]
        );

        $room['players'][$connId]['reconnect_timer'] = $timerId;
    }

    /**
     * EPIC-8.2: восстановление disconnected игрока по session token.
     *
     * FIX-9 (обнаружено при подключении EPIC-10.5, т.е. при первом реальном
     * end-to-end прогоне через живой router, а не через MockConnection):
     * $room['players'] keyed по conn_id (ANCHOR_CORE.md § Room Structure).
     * Новое WS-соединение после reconnect получает от Workerman НОВЫЙ
     * connection->id, отличный от старого (disconnected) ключа. Без
     * переиндексации записи все последующие хендлеры (draw_barrel/
     * leave_room/apartment_choice/...), ищущие игрока по $connection->id,
     * не находят его — reconnect выглядел бы успешным (reconnect_state
     * отправлен, статус='active'), но был бы функционально мёртвым для
     * любого дальнейшего действия с новой физической связи. Юнит-тест
     * GROUP 3 (tests/Manual/test_reconnect.php) не ловил это, т.к. проверяет
     * только сам вызов handleReconnect() в изоляции, не последующий вызов
     * от нового соединения через реальный router.
     */
    public function handleReconnect(string $token, object $connection, object $worker): bool
    {
        if (empty($worker->rooms) || !is_array($worker->rooms)) {
            return false;
        }

        foreach ($worker->rooms as $roomId => &$room) {
            $roomStatus = $room['status'] ?? 'waiting';
            if ($roomStatus !== 'waiting' && $roomStatus !== 'playing') {
                continue;
            }

            foreach ($room['players'] as $connId => $candidate) {
                if (($candidate['session_token'] ?? '') !== $token) {
                    continue;
                }

                $status = $candidate['status'] ?? null;
                $newConnId = (int)$connection->id;

                if ($status === 'disconnected') {
                    return $this->restorePlayerConnection($worker, $roomId, $room, $connId, $connection, $token);
                }

                // Page refresh: new WebSocket may arrive before onClose marks the
                // player disconnected — re-key the active entry onto the new conn.
                if ($status === 'active' && $connId !== $newConnId) {
                    return $this->restorePlayerConnection($worker, $roomId, $room, $connId, $connection, $token);
                }

                if ($status === 'active' && $connId === $newConnId) {
                    $this->bindConnectionToPlayer($connection, $candidate, $token, $worker);
                    sendJson($connection, $this->buildReconnectState($room, $connId));
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Re-keys a room player onto a new connection and sends reconnect_state.
     */
    private function restorePlayerConnection(
        object $worker,
        int $roomId,
        array &$room,
        int $oldConnId,
        object $connection,
        string $token
    ): bool {
        if (!isset($room['players'][$oldConnId])) {
            return false;
        }

        $player = $room['players'][$oldConnId];
        $wasDisconnected = ($player['status'] ?? null) === 'disconnected';

        if (!empty($player['reconnect_timer'])) {
            lottoTimerDel((int) $player['reconnect_timer'], 'reconnect', [
                'room_id' => $roomId,
                'conn_id' => $oldConnId,
            ]);
        }

        $newConnId = (int)$connection->id;

        $player['reconnect_timer'] = null;
        $player['status']          = 'active';
        if ($wasDisconnected) {
            lottoPlayerStateTransition($roomId, $newConnId, 'disconnected', 'active', 'reconnect');
        }
        $player['connection']      = $connection;
        $player['last_action']     = time();
        $player['strikes']         = 0;

        unset($room['players'][$oldConnId]);
        $room['players'][$newConnId] = $player;

        if (($room['host_conn_id'] ?? null) === $oldConnId) {
            $room['host_conn_id'] = $newConnId;
        }
        if (($room['active_drawer_conn_id'] ?? null) === $oldConnId) {
            $room['active_drawer_conn_id'] = $newConnId;
        }
        if (!empty($room['drawer_order'])) {
            $room['drawer_order'] = array_map(
                fn($cid) => $cid === $oldConnId ? $newConnId : $cid,
                $room['drawer_order']
            );
        }

        // ANCHOR_CORE § Game AFK: afk_start отсчитывается с your_turn.
        // После reconnect активный drawer должен снова попасть под AFK-защиту.
        if (
            ($room['status'] ?? null) === 'playing'
            && (int)($room['active_drawer_conn_id'] ?? 0) === $newConnId
        ) {
            $room['players'][$newConnId]['afk_start'] = time();
        } else {
            $room['players'][$newConnId]['afk_start'] = null;
        }

        $this->bindConnectionToPlayer($connection, $player, $token, $worker);
        sendJson($connection, $this->buildReconnectState($room, $newConnId));

        return true;
    }

    private function bindConnectionToPlayer(
        object $connection,
        array $player,
        string $token,
        object $worker
    ): void {
        $connection->userId       = $player['user_id'];
        $connection->username     = $player['username'];
        $connection->sessionToken = $token;

        if (!isset($worker->userConnections)) {
            $worker->userConnections = [];
        }
        $worker->userConnections[(int)$player['user_id']] = $connection;
    }

    /**
     * Контракт ANCHOR_PROTOCOL.md § reconnect_state.
     */
    public function buildReconnectState(array $room, int $connId): array
    {
        $status = $room['status'] ?? 'waiting';
        $player = $room['players'][$connId] ?? null;

        $base = [
            'type'      => 'reconnect_state',
            'status'    => $status,
            'room_id'   => $room['room_id'],
            'bank'      => $room['bank'] ?? 0,
        ];

        if ($status === 'waiting') {
            return array_merge($base, [
                'host'      => $this->resolveHostUsername($room),
                'players'   => $this->buildLobbyPlayersList($room),
                'drawn_all' => [],
                'my_cards'  => null,
            ]);
        }

        $drawerOrder = array_values(array_filter(
            $room['drawer_order'] ?? [],
            fn($cid) => isset($room['players'][$cid]) && ($room['players'][$cid]['status'] ?? null) === 'active'
        ));
        $drawerUsernames = array_map(
            fn($cid) => $room['players'][$cid]['username'],
            $drawerOrder
        );
        $drawerConnId = $room['active_drawer_conn_id'] ?? null;
        $currentDrawer = ($drawerConnId !== null && isset($room['players'][$drawerConnId]))
            ? (string) $room['players'][$drawerConnId]['username']
            : '';

        return array_merge($base, [
            'drawn_all'      => $room['drawn_numbers'] ?? [],
            'my_cards'       => $player['cards'] ?? [],
            'my_masks'       => $player['masks'] ?? [],
            'drawer_order'   => $drawerUsernames,
            'current_drawer' => $currentDrawer,
        ]);
    }

    /**
     * @return list<array{username: string, cards_count: int, status: string}>
     */
    private function buildLobbyPlayersList(array $room): array
    {
        $players = [];
        foreach ($room['players'] as $entry) {
            $players[] = [
                'username'    => (string) $entry['username'],
                'cards_count' => (int) ($entry['cards_count'] ?? 1),
                'status'      => (string) ($entry['status'] ?? 'active'),
            ];
        }

        return $players;
    }

    private function resolveHostUsername(array $room): string
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
     * EPIC-8.3: старт/контроль room-level game AFK timer.
     * Таймер создаётся ровно один раз на комнату.
     */
    public function ensureGameAfkTimer(object $worker, int $roomId): void
    {
        if (!isset($worker->rooms[$roomId])) {
            return;
        }

        $room = &$worker->rooms[$roomId];
        if (($room['status'] ?? null) !== 'playing') {
            return;
        }
        if (!empty($room['game_afk_timer_id'])) {
            return;
        }

        $room['game_afk_timer_id'] = lottoTimerAdd(Constants::afkTickInterval(), function () use ($worker, $roomId): void {
            $this->tickGameAfk($worker, $roomId);
        }, [], true, 'game_afk', ['room_id' => $roomId]);
    }

    public function stopGameAfkTimer(object $worker, int $roomId): void
    {
        if (!isset($worker->rooms[$roomId])) {
            return;
        }
        $room = &$worker->rooms[$roomId];
        if (!empty($room['game_afk_timer_id'])) {
            lottoTimerDel((int) $room['game_afk_timer_id'], 'game_afk', ['room_id' => $roomId]);
            $room['game_afk_timer_id'] = null;
        }
    }

    /**
     * EPIC-8.4 / 8.5: warning -> auto draw -> remove('afk').
     */
    public function tickGameAfk(object $worker, int $roomId): void
    {
        if (!isset($worker->rooms[$roomId])) {
            return;
        }

        $room = &$worker->rooms[$roomId];
        if (($room['status'] ?? null) !== 'playing') {
            $this->stopGameAfkTimer($worker, $roomId);
            return;
        }

        $drawerConnId = $room['active_drawer_conn_id'] ?? null;
        if ($drawerConnId === null || !isset($room['players'][$drawerConnId])) {
            return;
        }

        $drawer = &$room['players'][$drawerConnId];
        if (($drawer['status'] ?? null) !== 'active') {
            return;
        }

        if (empty($drawer['afk_start'])) {
            return;
        }

        $elapsed = time() - (int)$drawer['afk_start'];
        if ($elapsed >= Constants::gameAfkAutoSeconds()) {
            $this->performAutoDraw($worker, $roomId, (int)$drawerConnId);
            return;
        }

        if ($elapsed >= Constants::gameAfkWarn2Seconds() && (int)$drawer['strikes'] < 2) {
            $drawer['strikes'] = 2;
            $drawer['connection']->send(json_encode([
                'type'   => 'afk_warning',
                'strike' => 2,
            ]));
            return;
        }

        if ($elapsed >= Constants::gameAfkWarn1Seconds() && (int)$drawer['strikes'] < 1) {
            $drawer['strikes'] = 1;
            $drawer['connection']->send(json_encode([
                'type'   => 'afk_warning',
                'strike' => 1,
            ]));
        }
    }

    /**
     * Auto draw делегируется в существующий игровой цикл draw_barrel.
     * После этого накапливаем auto_draws и при >=3 удаляем игрока (reason=afk).
     */
    public function performAutoDraw(object $worker, int $roomId, int $drawerConnId): void
    {
        if (!isset($worker->rooms[$roomId]['players'][$drawerConnId])) {
            return;
        }
        $room = &$worker->rooms[$roomId];
        $drawer = &$room['players'][$drawerConnId];
        if (($drawer['status'] ?? null) !== 'active') {
            return;
        }

        $autoDrawsBefore = (int)($drawer['auto_draws'] ?? 0);
        $connection = $drawer['connection'];

        $this->gameService->handleDrawBarrel($connection, $worker, true);

        if (!isset($worker->rooms[$roomId]['players'][$drawerConnId])) {
            return;
        }

        $room = &$worker->rooms[$roomId];
        $drawer = &$room['players'][$drawerConnId];
        $drawer['auto_draws'] = $autoDrawsBefore + 1;
        $drawer['strikes']    = 0;
        $drawer['afk_start']  = null;

        if ($drawer['auto_draws'] >= 3) {
            $this->removePlayerFromGame($worker, $roomId, $drawerConnId, 'afk');
        }
    }

    /**
     * Удаление игрока из playing/apartment c reason: disconnect/afk.
     */
    public function removePlayerFromGame(object $worker, int $roomId, int $connId, string $reason): void
    {
        if (!isset($worker->rooms[$roomId]['players'][$connId])) {
            return;
        }

        $room = &$worker->rooms[$roomId];
        $player = $room['players'][$connId];
        $wasHost = ($room['host_conn_id'] ?? null) === $connId;
        $wasDrawer = ($room['active_drawer_conn_id'] ?? null) === $connId;

        if (!empty($player['reconnect_timer'])) {
            lottoTimerDel((int) $player['reconnect_timer'], 'reconnect', [
                'room_id' => $roomId,
                'conn_id' => $connId,
            ]);
        }

        $room['all_players_history'][$connId] = [
            'user_id'    => $player['user_id'],
            'username'   => $player['username'],
            'total_paid' => $player['total_paid'],
        ];

        unset($room['players'][$connId]);
        $room['drawer_order'] = array_values(
            array_filter($room['drawer_order'], fn($id) => $id !== $connId)
        );

        foreach ($room['players'] as $p) {
            if (($p['status'] ?? null) === 'active') {
                $p['connection']->send(json_encode([
                    'type'     => 'player_left',
                    'username' => $player['username'],
                    'reason'   => $reason,
                ]));
            }
        }

        if (empty($room['players'])) {
            $this->destroyRoom($worker, $roomId);
            return;
        }

        $active = array_filter($room['players'], fn($p) => ($p['status'] ?? null) === 'active');
        if (count($active) === 1) {
            $winnerConnId = (int)array_key_first($active);
            $this->gameService->finishGame(
                $room,
                $roomId,
                [$winnerConnId => 1],
                [$winnerConnId => (int)($room['bank'] ?? 0)],
                $worker,
                'last_survivor'
            );
            return;
        }

        if ($wasHost) {
            foreach ($room['drawer_order'] as $candidateConnId) {
                if (
                    isset($room['players'][$candidateConnId]) &&
                    ($room['players'][$candidateConnId]['status'] ?? null) === 'active'
                ) {
                    $room['host_conn_id'] = $candidateConnId;
                    break;
                }
            }
        }

        if ($wasDrawer) {
            $this->gameService->nextDrawer($room);
            $this->gameService->startTurn($room, $worker, $roomId);
        }
    }

    private function findRoomIdByConnId(object $worker, int $connId): ?int
    {
        foreach (($worker->rooms ?? []) as $roomId => $room) {
            if (isset($room['players'][$connId])) {
                return (int)$roomId;
            }
        }
        return null;
    }

    private function destroyRoom(object $worker, int $roomId): void
    {
        if (!isset($worker->rooms[$roomId])) {
            return;
        }

        $room = $worker->rooms[$roomId];
        if (!empty($room['lobby_afk_timer_id'])) {
            lottoTimerDel((int) $room['lobby_afk_timer_id'], 'lobby_afk', ['room_id' => $roomId]);
        }
        if (!empty($room['game_afk_timer_id'])) {
            lottoTimerDel((int) $room['game_afk_timer_id'], 'game_afk', ['room_id' => $roomId]);
        }
        if (!empty($room['apartment_timer_id'])) {
            lottoTimerDel((int) $room['apartment_timer_id'], 'apartment', ['room_id' => $roomId]);
        }

        foreach (($room['players'] ?? []) as $connId => $p) {
            if (!empty($p['reconnect_timer'])) {
                lottoTimerDel((int) $p['reconnect_timer'], 'reconnect', [
                    'room_id' => $roomId,
                    'conn_id' => $connId,
                ]);
            }
        }
        unset($worker->rooms[$roomId]);
    }
}
