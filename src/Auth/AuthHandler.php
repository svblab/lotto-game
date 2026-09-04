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
   private SessionGuardService $sessionGuard;
   private IpAccountLimitService $ipAccountLimit;

   public function __construct(
       AuthService $authService,
       SessionService $sessionService,
       Logger $logger,
       SessionGuardService $sessionGuard,
       IpAccountLimitService $ipAccountLimit
   ) {
       $this->authService = $authService;
       $this->sessionService = $sessionService;
       $this->logger = $logger;
       $this->sessionGuard = $sessionGuard;
       $this->ipAccountLimit = $ipAccountLimit;
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

       // Same-request session after register — skip password_verify (password was
       // just hashed in register(); a second bcrypt would double auth latency).
       try {
           $result = $this->authService->loginAfterRegister($username);
       } catch (Exception $e) {
           $this->logger->write('WARNING', "Auto-login failed after register for {$username}: " . $e->getMessage());
           sendError($connection, 'error.auth_invalid_credentials', 'Auto-login failed after registration');
           return;
       }

       if ($this->ipAccountLimit->rejectNewAuthIfOverLimit($connection, $worker, (int) $result['user']['id'])) {
           return;
       }

       $this->completeLoginSession($connection, $worker, $result);
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

           $clientMsg = $msg === 'Auth rate limited' ? 'Invalid username or password' : $msg;
           sendError($connection, $this->mapLoginError($msg), $clientMsg);
           return;
       }

       if ($this->ipAccountLimit->rejectNewAuthIfOverLimit($connection, $worker, (int) $result['user']['id'])) {
           return;
       }

       $this->completeLoginSession($connection, $worker, $result);
   }

   /** reconnect — FIX-10/11: user row + banned_until; ReconnectService sends reconnect_state. */
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
       $user = $this->authService->getUserById($userId);
       if ($user === null) {
           sendError($connection, 'error.auth_invalid_token', 'Session not found or expired');
           return;
       }

       if ($user['banned_until'] > time()) {
           sendJson($connection, ['type' => 'banned', 'until' => $user['banned_until']]);
           return;
       }

       $this->sessionGuard->claimUserSession($worker, $userId, $connection, $token, $user, false);
       $connId = $connection->id ?? 'null';
       $this->logger->write('INFO', "Reconnect validated: user_id={$userId} conn_id={$connId}");
   }

   /** auth_result when reconnect succeeded but player has no room to restore. */
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

   // Private helpers

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

   private function completeLoginSession(object $connection, object $worker, array $result): void
   {
       $userId = (int) $result['user']['id'];
       $this->sessionGuard->claimUserSession($worker, $userId, $connection, $result['session_token'], $result['user'], true);
       $this->logger->write('INFO', "User login: {$result['user']['username']} user_id={$userId} conn_id=" . ($connection->id ?? 'null'));
       $this->sendAuthResult($connection, $result);
   }

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

   private function mapLoginError(string $message): string
   {
       return match ($message) {
           'Auth rate limited' => 'error.auth_rate_limited',
           default => 'error.auth_invalid_credentials',
       };
   }
}
