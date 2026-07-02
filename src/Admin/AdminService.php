<?php

declare(strict_types=1);

namespace Lotto\Admin;

use function Lotto\Core\sendJson;
use function Lotto\Core\sendError;

/**
 * AdminService — EPIC-9.0
 *
 * На этом этапе реализуется базовая аутентификация администратора:
 * все административные действия обязаны проходить единый guard.
 */
final class AdminService
{
    private ?object $stmts;
    private ?object $logger;
    private ?object $lobbyService;
    private ?object $reconnectService;
    private ?object $apartmentService;
    private ?object $db;
    private ?object $roomManager;

    public function __construct(
        ?object $stmts = null,
        ?object $logger = null,
        ?object $lobbyService = null,
        ?object $reconnectService = null,
        ?object $apartmentService = null,
        ?object $db = null,
        ?object $roomManager = null
    ) {
        $this->stmts = $stmts;
        $this->logger = $logger;
        $this->lobbyService = $lobbyService;
        $this->reconnectService = $reconnectService;
        $this->apartmentService = $apartmentService;
        $this->db = $db;
        $this->roomManager = $roomManager;
    }

    /**
     * Проверяет право на выполнение admin-* действий.
     *
     * Условия доступа:
     * 1) пользователь аутентифицирован (userId установлен),
     * 2) пользователь имеет флаг администратора (isAdmin === true/1).
     *
     * @return bool true если доступ разрешён
     */
    public function assertAdmin(object $connection): bool
    {
        if (empty($connection->userId)) {
            sendError($connection, 'error.auth_required', 'Authentication required');
            return false;
        }

        $isAdmin = ($connection->isAdmin ?? false);
        if ($isAdmin !== true && (int)$isAdmin !== 1) {
            sendError($connection, 'error.not_your_turn', 'Admin access required');
            return false;
        }

        return true;
    }

    /**
     * EPIC-9.1: ban user.
     *
     * Input: {"action":"admin_ban_user","user_id":15,"duration":"1d|3d|permanent"}
     */
    public function handleBanUser(array $data, object $connection, object $worker): void
    {
        if (!$this->assertAdmin($connection)) {
            return;
        }
        if ($this->stmts === null) {
            sendError($connection, 'error.invalid_json', 'Admin statements storage is not configured');
            return;
        }

        $targetUserId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
        $duration = isset($data['duration']) ? (string)$data['duration'] : '';
        if ($targetUserId <= 0) {
            sendError($connection, 'error.invalid_json', 'user_id must be positive integer');
            return;
        }

        $bannedUntil = $this->parseBanDuration($duration);
        if ($bannedUntil === null) {
            sendError($connection, 'error.invalid_json', 'duration must be one of: 1d, 3d, permanent');
            return;
        }

        $stmt = $this->stmts->get('user_admin_by_id');
        $stmt->execute([$targetUserId]);
        $target = $stmt->fetch();
        if ($target === false) {
            sendError($connection, 'error.invalid_json', 'User not found');
            return;
        }

        if ((int)($target['is_admin'] ?? 0) === 1) {
            sendError($connection, 'error.cannot_moderate_admin', 'Cannot moderate admin account');
            return;
        }

        $banStmt = $this->stmts->get('ban_user');
        $banStmt->execute([$bannedUntil, $targetUserId]);

        // Если пользователь онлайн — отправляем banned и удаляем из комнаты без возврата ставок.
        if (isset($worker->userConnections[$targetUserId])) {
            $targetConnection = $worker->userConnections[$targetUserId];
            sendJson($targetConnection, [
                'type'  => 'banned',
                'until' => $bannedUntil,
            ]);

            $membership = $this->findPlayerMembership($worker, $targetUserId);
            if ($membership !== null) {
                ['room_id' => $roomId, 'conn_id' => $connId] = $membership;
                $roomStatus = $worker->rooms[$roomId]['status'] ?? 'waiting';

                if ($roomStatus === 'waiting' && $this->lobbyService !== null) {
                    $this->lobbyService->removePlayerFromLobby($worker, $roomId, $connId, 'banned');
                } elseif ($roomStatus === 'playing' && $this->reconnectService !== null) {
                    $this->reconnectService->removePlayerFromGame($worker, $roomId, $connId, 'banned');
                } elseif ($roomStatus === 'apartment' && $this->apartmentService !== null) {
                    $room = &$worker->rooms[$roomId];
                    $this->apartmentService->removePlayerFromApartment(
                        $room,
                        $roomId,
                        $connId,
                        'banned',
                        $worker
                    );
                }
            }
        }

        if ($this->logger !== null) {
            $this->logger->info(
                "Admin user_id={$connection->userId} banned user_id={$targetUserId} until={$bannedUntil}"
            );
        }
    }

    /**
     * EPIC-9.2: unban user.
     *
     * Input: {"action":"admin_unban_user","user_id":15}
     */
    public function handleUnbanUser(array $data, object $connection): void
    {
        if (!$this->assertAdmin($connection)) {
            return;
        }
        if ($this->stmts === null) {
            sendError($connection, 'error.invalid_json', 'Admin statements storage is not configured');
            return;
        }

        $targetUserId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
        if ($targetUserId <= 0) {
            sendError($connection, 'error.invalid_json', 'user_id must be positive integer');
            return;
        }

        $stmt = $this->stmts->get('unban_user');
        $stmt->execute([$targetUserId]);

        if ($this->logger !== null) {
            $this->logger->info(
                "Admin user_id={$connection->userId} unbanned user_id={$targetUserId}"
            );
        }
    }

    /**
     * EPIC-9.3: kick player.
     *
     * Input: {"action":"admin_kick_user","user_id":15}
     *
     * Экономика (ANCHOR_CORE.md Part 2 § Kick):
     * Player refunded total_paid: bank -= total_paid; coins += total_paid.
     * Транзакция обязательна (ANCHOR_CORE.md § Mandatory Transactions) —
     * bank и users.coins обновляются как единое целое: при сбое DB-транзакции
     * bank не трогается и игрок НЕ удаляется из комнаты.
     *
     * Removal reason: 'kicked' (ANCHOR_CORE.md § Removal Reasons).
     * Структурное удаление делегируется существующим removePlayerFrom*()
     * по текущему статусу комнаты — паттерн идентичен handleBanUser().
     *
     * Host Rules (ANCHOR_CORE.md Part 4): 'kicked' — валидная причина смены
     * хоста. Для 'waiting' host transfer выполняется явно ниже. Для 'playing'
     * host transfer уже встроен в ReconnectService::removePlayerFromGame().
     * Для 'apartment' — ApartmentService::removePlayerFromApartment() host
     * transfer не поддерживает (существующий пробел вне scope этого Epic —
     * см. KNOWN GAPS в IMPLEMENTATION_STATUS.md).
     */
    public function handleKickUser(array $data, object $connection, object $worker): void
    {
        if (!$this->assertAdmin($connection)) {
            return;
        }
        if ($this->stmts === null || $this->db === null) {
            sendError($connection, 'error.invalid_json', 'Admin statements storage is not configured');
            return;
        }

        $targetUserId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
        if ($targetUserId <= 0) {
            sendError($connection, 'error.invalid_json', 'user_id must be positive integer');
            return;
        }

        $stmt = $this->stmts->get('user_admin_by_id');
        $stmt->execute([$targetUserId]);
        $target = $stmt->fetch();
        if ($target === false) {
            sendError($connection, 'error.invalid_json', 'User not found');
            return;
        }

        if ((int)($target['is_admin'] ?? 0) === 1) {
            sendError($connection, 'error.cannot_moderate_admin', 'Cannot moderate admin account');
            return;
        }

        $membership = $this->findPlayerMembership($worker, $targetUserId);
        if ($membership === null) {
            sendError($connection, 'error.room_not_found', 'User is not in a room');
            return;
        }

        ['room_id' => $roomId, 'conn_id' => $connId] = $membership;
        if (!isset($worker->rooms[$roomId]['players'][$connId])) {
            sendError($connection, 'error.room_not_found', 'User is not in a room');
            return;
        }

        $room = &$worker->rooms[$roomId];
        $player = $room['players'][$connId];
        $totalPaid = (int)($player['total_paid'] ?? 0);

        // --- Refund transaction (ANCHOR_CORE § Kick) — mandatory, all-or-nothing ---
        if ($totalPaid > 0) {
            $pdo = $this->db->getPdo();
            try {
                $pdo->beginTransaction();
                $refundStmt = $this->stmts->get('add_user_coins');
                $refundStmt->execute([$totalPaid, $targetUserId]);
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                if ($this->logger !== null) {
                    $this->logger->error(
                        "Admin kick refund failed for user_id={$targetUserId}: " . $e->getMessage()
                    );
                }
                sendError($connection, 'error.invalid_json', 'Failed to process kick refund');
                return;
            }

            $room['bank'] = max(0, (int)($room['bank'] ?? 0) - $totalPaid);

            // FIX-3: обнулить total_paid ПОСЛЕ рефанда, до записи в
            // all_players_history (см. removePlayerFromLobby/Game/Apartment
            // ниже). Иначе handleCloseRoom() позже повторно вернёт те же
            // total_paid из истории — двойной рефанд, нарушение
            // Economic Integrity Rule (ANCHOR_CORE.md Part 2).
            $room['players'][$connId]['total_paid'] = 0;
        }

        // --- Structural removal, delegated by room status ---
        $roomStatus = $room['status'] ?? 'waiting';

        if ($roomStatus === 'waiting' && $this->lobbyService !== null) {
            $wasHost = ($room['host_conn_id'] ?? null) === $connId;
            $this->lobbyService->removePlayerFromLobby($worker, $roomId, $connId, 'kicked');
            if ($wasHost && isset($worker->rooms[$roomId])) {
                $this->lobbyService->transferHost($worker, $roomId);
            }
        } elseif ($roomStatus === 'playing' && $this->reconnectService !== null) {
            $this->reconnectService->removePlayerFromGame($worker, $roomId, $connId, 'kicked');
        } elseif ($roomStatus === 'apartment' && $this->apartmentService !== null) {
            $this->apartmentService->removePlayerFromApartment(
                $room,
                $roomId,
                $connId,
                'kicked',
                $worker
            );
        }

        if ($this->logger !== null) {
            $this->logger->info(
                "Admin user_id={$connection->userId} kicked user_id={$targetUserId} refunded={$totalPaid}"
            );
        }
    }

    /**
     * EPIC-9.4: close room.
     *
     * Input: {"action":"admin_close_room","room_id":7}
     *
     * Экономика (ANCHOR_CORE.md Part 2 § Admin Close Room):
     * Reason 'admin_close'. Все участники получают 100% рефанд total_paid,
     * ИСТОЧНИК — all_players_history (не только текущие active players,
     * но и все ранее удалённые reason=leave/disconnect/afk/refuse/kicked/banned,
     * чьи total_paid оставались в банке по правилам этих reason).
     * Транзакция обязательна (Mandatory Transactions) — все-или-ничего.
     *
     * State Machine (ANCHOR_CORE.md Part 4): валидный переход из ЛЮБОГО статуса
     * (waiting/playing/apartment) → destroyed. Ветвления по статусу не требуется —
     * комната уничтожается целиком, не через removePlayerFrom*().
     *
     * Таймеры: полная очистка делегирована RoomManager::destroyRoom() (EPIC-2.0) —
     * повторно реализует контракт ANCHOR_CORE § Room Destruction Cleanup корректно,
     * дублировать его здесь запрещено (Rule 6).
     */
    public function handleCloseRoom(array $data, object $connection, object $worker): void
    {
        if (!$this->assertAdmin($connection)) {
            return;
        }
        if ($this->stmts === null || $this->db === null) {
            sendError($connection, 'error.invalid_json', 'Admin statements storage is not configured');
            return;
        }

        $roomId = isset($data['room_id']) ? (int)$data['room_id'] : 0;
        if ($roomId <= 0) {
            sendError($connection, 'error.invalid_json', 'room_id must be positive integer');
            return;
        }
        if (!isset($worker->rooms[$roomId])) {
            sendError($connection, 'error.room_not_found', 'Room not found');
            return;
        }

        $room = &$worker->rooms[$roomId];

        // --- Мигрировать ещё присутствующих игроков в историю ---
        // (all_players_history — единый источник рефанда, ANCHOR_CORE § Admin Close Room)
        foreach ($room['players'] as $connId => $player) {
            if (!isset($room['all_players_history'][$connId])) {
                $room['all_players_history'][$connId] = [
                    'user_id'    => $player['user_id'],
                    'username'   => $player['username'],
                    'total_paid' => $player['total_paid'],
                ];
            }
        }

        // --- Транзакционный 100% рефанд всем участникам истории ---
        $pdo = $this->db->getPdo();
        try {
            $pdo->beginTransaction();
            foreach ($room['all_players_history'] as $hist) {
                $userId    = (int)($hist['user_id'] ?? 0);
                $totalPaid = (int)($hist['total_paid'] ?? 0);
                if ($userId <= 0 || $totalPaid <= 0) {
                    continue;
                }
                $refundStmt = $this->stmts->get('add_user_coins');
                $refundStmt->execute([$totalPaid, $userId]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            if ($this->logger !== null) {
                $this->logger->error(
                    "Admin close_room refund failed for room_id={$roomId}: " . $e->getMessage()
                );
            }
            sendError($connection, 'error.invalid_json', 'Failed to process room close refund');
            return;
        }

        $room['bank'] = 0;

        // --- Уведомление активных игроков (reason: admin_close) ---
        foreach ($room['players'] as $player) {
            if (($player['status'] ?? null) === 'active' && isset($player['connection'])) {
                sendJson($player['connection'], [
                    'type'     => 'player_left',
                    'username' => $player['username'],
                    'reason'   => 'admin_close',
                ]);
            }
        }

        if ($this->logger !== null) {
            $this->logger->info(
                "Admin user_id={$connection->userId} closed room_id={$roomId}, refunded " .
                count($room['all_players_history']) . " participants"
            );
        }

        // --- Уничтожение комнаты: полная очистка таймеров (RoomManager) ---
        if ($this->roomManager !== null) {
            $this->roomManager->destroyRoom($worker, $roomId);
        } else {
            unset($worker->rooms[$roomId]);
        }
    }

    /**
     * EPIC-9.5: logs access.
     *
     * Input:
     * {"action":"admin_get_logs"}
     *
     * Output:
     * {"type":"admin_logs_data","lines":[]}
     */
    public function handleGetLogs(array $data, object $connection): void
    {
        if (!$this->assertAdmin($connection)) {
            return;
        }

        if ($this->logger === null) {
            sendError(
                $connection,
                'error.invalid_json',
                'Logger is not configured'
            );
            return;
        }

        sendJson($connection, [
            'type'  => 'admin_logs_data',
            'lines' => $this->logger->getLastLines(100),
        ]);

        $this->logger->info(
            "Admin user_id={$connection->userId} requested system logs"
        );
    }

    private function parseBanDuration(string $duration): ?int
    {
        return match ($duration) {
            '1d' => time() + 86400,
            '3d' => time() + 259200,
            'permanent' => 4102444800,
            default => null,
        };
    }

    private function findPlayerMembership(object $worker, int $userId): ?array
    {
        foreach (($worker->rooms ?? []) as $roomId => $room) {
            foreach (($room['players'] ?? []) as $connId => $player) {
                if ((int)($player['user_id'] ?? 0) === $userId) {
                    return [
                        'room_id' => (int)$roomId,
                        'conn_id' => (int)$connId,
                    ];
                }
            }
        }
        return null;
    }
}