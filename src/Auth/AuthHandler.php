<?php

namespace Lotto\Auth;

use Exception;
use Lotto\Core\Logger;
use function Lotto\Core\sendJson;
use function Lotto\Core\sendError;

/**
* AuthHandler — EPIC-1.3
*
* Обрабатывает WebSocket-пакеты аутентификации: register, login, reconnect.
* Транслирует входящие пакеты ANCHOR_PROTOCOL.md в вызовы AuthService и
* формирует ответные пакеты строго по тому же протоколу.
*
* Контракты worker-памяти (инициализируются в server.php, EPIC-10.3):
*   $worker->sessionTokens   — array<string, int>  token → user_id
*   $worker->userConnections — array<int, object>  user_id → connection
*
* Зависимости:
*   AuthService    — бизнес-логика регистрации и входа
*   SessionService — валидация формата токена при reconnect
*   Logger         — аудит-лог всех auth-событий
*
* Глобальные функции (src/Core/Helpers.php):
*   sendJson(object $connection, array $data): void
*   sendError(object $connection, string $code, string $message = ''): void
*/
final class AuthHandler
{
   private AuthService $authService;
   private SessionService $sessionService;
   private Logger $logger;

   public function __construct(
       AuthService $authService,
       SessionService $sessionService,
       Logger $logger
   ) {
       $this->authService = $authService;
       $this->sessionService = $sessionService;
       $this->logger = $logger;
   }

   // -------------------------------------------------------------------------
   // Public action handlers
   // -------------------------------------------------------------------------

   /**
    * Обрабатывает пакет {"action": "register"}.
    *
    * Успех  → авто-логин → auth_result
    * Ошибка → error (error.auth_invalid_username | error.auth_username_taken)
    *
    * Авто-логин обоснован отсутствием отдельного register_result в протоколе:
    * клиент получает auth_result в любом случае при старте сессии.
    */
   public function handleRegister(array $data, object $connection, object $worker): void
   {
       $username = $data['username'] ?? null;
       $password = $data['password'] ?? null;

       if (!is_string($username) || !is_string($password)) {
           sendError($connection, 'error.auth_invalid_username', 'Missing username or password');
           return;
       }

       try {
           $this->authService->register($username, $password);
       } catch (Exception $e) {
           sendError($connection, $this->mapRegisterError($e->getMessage()), $e->getMessage());
           return;
       }

       // Авто-логин: регистрация завершена, сразу создаём сессию
       try {
           $result = $this->authService->login($username, $password);
       } catch (Exception $e) {
           // Теоретически невозможно сразу после успешного register(),
           // но защищаемся на случай race condition или внутренней ошибки БД.
           $this->logger->write('WARNING', "Auto-login failed after register for {$username}: " . $e->getMessage());
           sendError($connection, 'error.auth_invalid_credentials', 'Auto-login failed after registration');
           return;
       }

       $this->claimUserSession($worker, (int) $result['user']['id'], $connection, $result['session_token'], $result['user'], true);
       $this->sendAuthResult($connection, $result);
   }

   /**
    * Обрабатывает пакет {"action": "login"}.
    *
    * Успех → auth_result
    * Бан   → banned (с Unix-timestamp истечения блокировки)
    * Ошибка → error (error.auth_invalid_credentials)
    */
   public function handleLogin(array $data, object $connection, object $worker): void
   {
       $username = $data['username'] ?? null;
       $password = $data['password'] ?? null;

       if (!is_string($username) || !is_string($password)) {
           sendError($connection, 'error.auth_invalid_credentials', 'Missing username or password');
           return;
       }

       try {
           $result = $this->authService->login($username, $password);
       } catch (Exception $e) {
           $msg = $e->getMessage();

           if ($msg === 'User is banned') {
               // $e->getCode() содержит banned_until (Unix timestamp).
               // AuthService передаёт его через второй аргумент конструктора Exception.
               // Пакет: ANCHOR_PROTOCOL.md § Authentication → banned
               sendJson($connection, ['type' => 'banned', 'until' => $e->getCode()]);
               return;
           }

           sendError($connection, $this->mapLoginError($msg), $msg);
           return;
       }

       $this->claimUserSession($worker, (int) $result['user']['id'], $connection, $result['session_token'], $result['user'], true);
       $this->sendAuthResult($connection, $result);
   }

   /**
    * Обрабатывает пакет {"action": "reconnect"}.
    *
    * Успех  → восстанавливает $worker->userConnections[$userId] И
    *          связывает Connection Runtime Fields через claimUserSession()
    *          (FIX-10 — до этого фикса $connection->userId никогда не
    *          устанавливался здесь, что оставляло реконнекчённое
    *          соединение фактически неаутентифицированным для guard'а
    *          error.auth_required в server.php, см. IMPLEMENTATION_STATUS.md
    *          FIX-10).
    *          Пакет reconnect_state отправляет ReconnectService (EPIC-8.0/
    *          EPIC-10.5), если у пользователя есть активная комната.
    * Ошибка → error (error.auth_invalid_token)
    *
    * Формат токена (32-символьный hex) валидируется через SessionService::isValidToken().
    * Наличие активной сессии проверяется по $worker->sessionTokens.
    */
   public function handleReconnect(array $data, object $connection, object $worker): void
   {
       $token = $data['token'] ?? null;

       if (!is_string($token) || !$this->sessionService->isValidToken($token)) {
           sendError($connection, 'error.auth_invalid_token', 'Invalid or missing session token');
           return;
       }

       if (!isset($worker->sessionTokens[$token])) {
           sendError($connection, 'error.auth_invalid_token', 'Session not found or expired');
           return;
       }

       $userId = (int)$worker->sessionTokens[$token];

       // FIX-10: без этой проверки удалённый (или иным образом более не
       // существующий) пользователь мог бы пройти reconnect с валидным по
       // формату токеном, но без реальной учётной записи — bindConnection()
       // ниже требует username/is_admin, которых тогда просто нет.
       $user = $this->authService->getUserById($userId);
       if ($user === null) {
           sendError($connection, 'error.auth_invalid_token', 'Session not found or expired');
           return;
       }

       // FIX-11: login() уже отказывает забаненным пользователям
       // (AuthService::login(), banned_until > time()) — reconnect должен
       // делать то же самое. До этого фикса reconnect вообще не проверял
       // banned_until: игрок, забаненный админом, мог просто отправить
       // {"action":"reconnect","token":<старый session_token>} вместо
       // login() и полностью восстановить аутентифицированную сессию в
       // обход бана — см. IMPLEMENTATION_STATUS.md FIX-11 (воспроизведено
       // сквозным сценарием: бан во время reconnect-окна -> reconnect
       // всё равно проходил). Пакет идентичен уже принятому контракту
       // login()'а (ANCHOR_PROTOCOL.md § Authentication → banned) — не
       // новый код ошибки, просто тот же banned-путь, доступный из
       // второго места, где он был пропущен.
       if ($user['banned_until'] > time()) {
           sendJson($connection, ['type' => 'banned', 'until' => $user['banned_until']]);
           return;
       }

       $this->claimUserSession($worker, $userId, $connection, $token, $user, false);

       $this->logger->write('INFO', "Reconnect validated: user_id={$userId}");

       // Пакет reconnect_state формирует ReconnectService (EPIC-8.0), если
       // у пользователя есть активная комната (server.php вызывает его
       // сразу после этого метода, EPIC-10.5).
   }

   /**
    * Отправляет auth_result, когда reconnect подтвердил сессию, но игрок
    * не находится в восстанавливаемой комнате (закрывает KNOWN GAP в
    * server.php — клиент иначе зависает без ответа).
    */
   public function notifyLobbyRestored(object $connection, string $token): void
   {
       if (empty($connection->userId)) {
           return;
       }

       $user = $this->authService->getUserById((int)$connection->userId);
       if ($user === null) {
           return;
       }

       $this->sendAuthResult($connection, [
           'session_token' => $token,
           'user'          => $user,
       ]);
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

       $connId = $newConnection->id ?? 'null';
       $this->logger->write('INFO', "Session claimed: user_id={$userId} conn_id={$connId}");
   }

   // -------------------------------------------------------------------------
   // Private helpers
   // -------------------------------------------------------------------------

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

   /**
    * Отправляет пакет auth_result.
    * Контракт: ANCHOR_PROTOCOL.md § Authentication → auth_result
    *
    * {"type": "auth_result", "success": true, "user_id": 15,
    *  "username": "player", "coins": 500, "is_admin": false, "session_token": "..."}
    */
   private function sendAuthResult(object $connection, array $loginResult): void
   {
       sendJson($connection, [
           'type'          => 'auth_result',
           'success'       => true,
           'user_id'       => $loginResult['user']['id'],
           'username'      => $loginResult['user']['username'],
           'coins'         => $loginResult['user']['coins'],
           'is_admin'      => $loginResult['user']['is_admin'],
           'session_token' => $loginResult['session_token'],
       ]);
   }

   /**
    * Сопоставляет сообщение исключения register() с кодом ошибки ANCHOR_PROTOCOL.md.
    *
    * Реестр кодов (ANCHOR_PROTOCOL.md § Error Packet):
    *   error.auth_invalid_username — невалидный формат имени или пароля
    *   error.auth_username_taken   — имя уже занято
    */
   private function mapRegisterError(string $message): string
   {
       if ($message === 'Username already exists') {
           return 'error.auth_username_taken';
       }
       if (str_contains($message, 'UNIQUE constraint failed') && str_contains($message, 'username')) {
           return 'error.auth_username_taken';
       }

       return 'error.auth_invalid_username';
   }

   /**
    * Сопоставляет сообщение исключения login() с кодом ошибки ANCHOR_PROTOCOL.md.
    *
    * Реестр кодов (ANCHOR_PROTOCOL.md § Error Packet):
    *   error.auth_invalid_credentials — неверный логин/пароль или двойной вход
    *
    * Бан обрабатывается отдельно в handleLogin() до вызова этого метода.
    */
   private function mapLoginError(string $message): string
   {
       // Все ошибки входа (неверный пароль, двойной вход) сводятся к одному
       // коду — намеренно: не раскрываем клиенту причину отказа.
       return match ($message) {
           default => 'error.auth_invalid_credentials',
       };
   }
}
