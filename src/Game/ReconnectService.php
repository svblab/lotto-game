<?php

declare(strict_types=1);

namespace Lotto\Game;

use Lotto\Core\Constants;
use Workerman\Timer;

use function Lotto\Core\sendJson;

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
        $room['players'][$connId]['connection'] = $connection;

        if (!empty($room['players'][$connId]['reconnect_timer'])) {
            Timer::del($room['players'][$connId]['reconnect_timer']);
        }

        $timerId = Timer::add(
            Constants::RECONNECT_TIMEOUT,
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
            false
        );

        $room['players'][$connId]['reconnect_timer'] = $timerId;
    }

    /**
     * EPIC-8.2: восстановление disconnected игрока по session token.
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

            foreach ($room['players'] as $connId => &$player) {
                if (($player['session_token'] ?? '') !== $token) {
                    continue;
                }
                if (($player['status'] ?? null) !== 'disconnected') {
                    continue;
                }

                if (!empty($player['reconnect_timer'])) {
                    Timer::del($player['reconnect_timer']);
                }

                $player['reconnect_timer'] = null;
                $player['status']          = 'active';
                $player['connection']      = $connection;
                $player['last_action']     = time();
                $player['afk_start']       = null;
                $player['strikes']         = 0;

                $connection->userId       = $player['user_id'];
                $connection->username     = $player['username'];
                $connection->sessionToken = $token;

                if (!isset($worker->userConnections)) {
                    $worker->userConnections = [];
                }
                $worker->userConnections[(int)$player['user_id']] = $connection;

                sendJson($connection, $this->buildReconnectState($room, (int)$connId));
                return true;
            }
        }

        return false;
    }

    /**
     * Контракт ANCHOR_PROTOCOL.md § reconnect_state.
     */
    public function buildReconnectState(array $room, int $connId): array
    {
        $status = $room['status'] ?? 'waiting';
        $player = $room['players'][$connId] ?? null;

        if ($status === 'waiting') {
            return [
                'type'      => 'reconnect_state',
                'status'    => 'waiting',
                'room_id'   => $room['room_id'],
                'bank'      => $room['bank'] ?? 0,
                'drawn_all' => [],
                'my_cards'  => null,
            ];
        }

        return [
            'type'      => 'reconnect_state',
            'status'    => 'playing',
            'room_id'   => $room['room_id'],
            'bank'      => $room['bank'] ?? 0,
            'drawn_all' => $room['drawn_numbers'] ?? [],
            'my_cards'  => $player['cards'] ?? [],
            'my_masks'  => $player['masks'] ?? [],
        ];
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

        $room['game_afk_timer_id'] = Timer::add(1, function () use ($worker, $roomId): void {
            $this->tickGameAfk($worker, $roomId);
        });
    }

    public function stopGameAfkTimer(object $worker, int $roomId): void
    {
        if (!isset($worker->rooms[$roomId])) {
            return;
        }
        $room = &$worker->rooms[$roomId];
        if (!empty($room['game_afk_timer_id'])) {
            Timer::del($room['game_afk_timer_id']);
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
        if ($elapsed >= 30) {
            $this->performAutoDraw($worker, $roomId, (int)$drawerConnId);
            return;
        }

        if ($elapsed >= 25 && (int)$drawer['strikes'] < 2) {
            $drawer['strikes'] = 2;
            $drawer['connection']->send(json_encode([
                'type'   => 'afk_warning',
                'strike' => 2,
            ]));
            return;
        }

        if ($elapsed >= 15 && (int)$drawer['strikes'] < 1) {
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

        $this->gameService->handleDrawBarrel($connection, $worker);

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
            Timer::del($player['reconnect_timer']);
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
            $this->gameService->sendYourTurn($room);
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
            Timer::del($room['lobby_afk_timer_id']);
        }
        if (!empty($room['game_afk_timer_id'])) {
            Timer::del($room['game_afk_timer_id']);
        }
        if (!empty($room['apartment_timer_id'])) {
            Timer::del($room['apartment_timer_id']);
        }

        foreach (($room['players'] ?? []) as $p) {
            if (!empty($p['reconnect_timer'])) {
                Timer::del($p['reconnect_timer']);
            }
        }
        unset($worker->rooms[$roomId]);
    }
}
