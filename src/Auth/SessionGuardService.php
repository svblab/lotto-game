<?php

namespace Lotto\Auth;

use Lotto\Core\Logger;
use function Lotto\Core\sendError;

/**
 * SessionGuardService — EPIC-027.0
 *
 * Concurrent-session eviction cluster extracted from AuthHandler (FIX-30 /
 * ADR-001). Single public entry point: claimUserSession().
 */
final class SessionGuardService
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * ADR-001 / FIX-30: единая точка принятия сессии после login/register/reconnect.
     * Новейший успешный login/reconnect выигрывает — все другие live-соединения
     * того же user_id выселяются (room removal + error.auth_invalid_token + close).
     */
    public function claimUserSession(
        object $worker,
        int $userId,
        object $newConnection,
        string $token,
        array $user,
        bool $freshLogin = false
    ): void {
        foreach ($this->findAllLiveConnectionsForUser($worker, $userId, $newConnection) as $oldConnection) {
            $this->evictConnection($worker, $oldConnection, $userId);
        }

        if ($freshLogin && isset($worker->lobbyService)) {
            $worker->lobbyService->removeExistingSeatForUser($worker, $userId, 'disconnect');
        }

        if (isset($worker->userConnections[$userId])) {
            $registered = $worker->userConnections[$userId];
            if ($registered !== $newConnection && !$this->isConnectionLive($worker, $registered)) {
                unset($worker->userConnections[$userId]);
            }
        }

        if (!isset($worker->sessionTokens)) {
            $worker->sessionTokens = [];
        }
        $this->revokeTokensForUser($worker, $userId);
        $worker->sessionTokens[$token] = $userId;
        $worker->userConnections[$userId] = $newConnection;
        $this->bindConnection($newConnection, $user, $token);

        // EPIC-028: belt-and-suspenders — catch any live socket still bound to
        // $userId after the primary eviction pass (e.g. join_room rebindSeat or
        // ReconnectService::bindConnectionToPlayer racing a stale owner).
        foreach ($worker->connections ?? [] as $liveConnection) {
            if ($liveConnection === $newConnection) {
                continue;
            }
            if ((int) ($liveConnection->userId ?? 0) === $userId) {
                $this->evictConnection($worker, $liveConnection, $userId);
            }
        }

        $connId = $newConnection->id ?? 'null';
        $this->logger->write('INFO', "Session claimed: user_id={$userId} conn_id={$connId}");
    }

    /**
     * Evicts every other live socket currently bound to $userId.
     * Used by lobby rebind after the room seat is already re-keyed.
     */
    public function evictOtherLiveSessions(object $worker, int $userId, object $keepConnection): void
    {
        foreach ($this->findAllLiveConnectionsForUser($worker, $userId, $keepConnection) as $oldConnection) {
            $this->evictConnection($worker, $oldConnection, $userId);
        }
    }

    /**
     * Evicts a superseded live connection: remove from room, notify, clear auth, close.
     */
    private function evictConnection(object $worker, object $oldConnection, int $userId): void
    {
        $oldConnId = $oldConnection->id ?? 'null';
        $this->logger->write(
            'INFO',
            "Evicting superseded session: user_id={$userId} old_conn_id={$oldConnId}"
        );

        $this->removeConnectionFromRoom($worker, $oldConnection, $userId);

        sendError($oldConnection, 'error.auth_invalid_token', 'Session superseded');
        $oldConnection->userId       = null;
        $oldConnection->username     = null;
        $oldConnection->isAdmin      = false;
        $oldConnection->sessionToken = null;

        if (isset($worker->userConnections[$userId]) && $worker->userConnections[$userId] === $oldConnection) {
            unset($worker->userConnections[$userId]);
        }

        $oldConnection->close();
    }

    /**
     * Removes the evicted connection's room seat (by conn_id or user_id fallback).
     */
    private function removeConnectionFromRoom(object $worker, object $connection, int $userId): void
    {
        if (!isset($worker->roomManager) || !isset($worker->lobbyService) || !isset($worker->reconnectService)) {
            return;
        }

        $connId = (int) ($connection->id ?? 0);
        $roomId = $worker->roomManager->findRoomIdByConnId($worker, $connId);

        if ($roomId === null && $userId > 0) {
            $roomId = $worker->roomManager->findRoomIdByUserId($worker, $userId);
            if ($roomId !== null && isset($worker->rooms[$roomId]['players'])) {
                foreach ($worker->rooms[$roomId]['players'] as $playerConnId => $player) {
                    if ((int) ($player['user_id'] ?? 0) === $userId) {
                        $connId = (int) $playerConnId;
                        break;
                    }
                }
            }
        }

        if ($roomId === null || !isset($worker->rooms[$roomId]['players'][$connId])) {
            return;
        }

        $player = $worker->rooms[$roomId]['players'][$connId];
        if (isset($player['connection']) && $player['connection'] !== $connection) {
            return;
        }

        $status = $worker->rooms[$roomId]['status'] ?? 'waiting';
        if ($status === 'waiting') {
            $worker->lobbyService->removePlayerFromLobby($worker, $roomId, $connId, 'disconnect');
            return;
        }

        $worker->reconnectService->removePlayerFromGame($worker, $roomId, $connId, 'disconnect');
    }

    /**
     * @return list<object>
     */
    private function findAllLiveConnectionsForUser(object $worker, int $userId, object $exceptConnection): array
    {
        $found = [];

        foreach ($worker->connections ?? [] as $liveConnection) {
            if ($liveConnection === $exceptConnection) {
                continue;
            }
            if ((int) ($liveConnection->userId ?? 0) === $userId) {
                $found[] = $liveConnection;
            }
        }

        if (isset($worker->userConnections[$userId])) {
            $registered = $worker->userConnections[$userId];
            if (
                $registered !== $exceptConnection
                && $this->isConnectionLive($worker, $registered)
                && !in_array($registered, $found, true)
            ) {
                $found[] = $registered;
            }
        }

        return $found;
    }

    private function isConnectionLive(object $worker, object $connection): bool
    {
        foreach ($worker->connections ?? [] as $liveConnection) {
            if ($liveConnection === $connection) {
                return true;
            }
        }

        return false;
    }

    /**
     * Removes every session token mapped to $userId from worker memory.
     */
    private function revokeTokensForUser(object $worker, int $userId): void
    {
        if (empty($worker->sessionTokens)) {
            return;
        }

        foreach ($worker->sessionTokens as $existingToken => $mappedUserId) {
            if ((int) $mappedUserId === $userId) {
                unset($worker->sessionTokens[$existingToken]);
            }
        }
    }

    /**
     * FIX-8: связывает Connection Runtime Fields (ANCHOR_CORE.md § Connection
     * Runtime Fields) с только что аутентифицированным пользователем.
     *
     * AuthService::login() сохраняет только $worker->userConnections[$userId]
     * — САМ $connection->userId никогда не устанавливался нигде в register/
     * login до этого фикса (в отличие от ReconnectService::attemptReconnect(),
     * который для СВОЕГО сценария это делает). Без этого метода
     * error.auth_required guard (EPIC-10.2 continuation/ADR-006) блокировал
     * бы КАЖДОЕ действие только что залогинившегося пользователя — клиент
     * получал бы auth_result, но был бы фактически неавторизован на уровне
     * соединения.
     *
     * @param array{id:int, username:string, coins:int, is_admin:bool} $user
     */
    private function bindConnection(object $connection, array $user, string $token): void
    {
        $connection->userId       = (int)$user['id'];
        $connection->username     = (string)$user['username'];
        $connection->isAdmin      = (bool)$user['is_admin'];
        $connection->sessionToken = $token;
    }
}
