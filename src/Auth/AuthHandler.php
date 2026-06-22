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
           $result = $this->authService->login($username, $password, $worker, $connection);
       } catch (Exception $e) {
           // Теоретически невозможно сразу после успешного register(),
           // но защищаемся на случай race condition или внутренней ошибки БД.
           $this->logger->write('WARNING', "Auto-login failed after register for {$username}: " . $e->getMessage());
           sendError($connection, 'error.auth_invalid_credentials', 'Auto-login failed after registration');
           return;
       }

       $this->storeSession($worker, $result['session_token'], $result['user']['id']);
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
           $result = $this->authService->login($username, $password, $worker, $connection);
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

       $this->storeSession($worker, $result['session_token'], $result['user']['id']);
       $this->sendAuthResult($connection, $result);
   }

   /**
    * Обрабатывает пакет {"action": "reconnect"}.
    *
    * Успех  → восстанавливает $worker->userConnections[$userId]
    *          Пакет reconnect_state отправляет ReconnectService (EPIC-8.0)
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

       // Восстанавливаем маппинг соединения в worker-памяти
       $worker->userConnections[$userId] = $connection;

       $this->logger->write('INFO', "Reconnect validated: user_id={$userId}");

       // Пакет reconnect_state формирует ReconnectService (EPIC-8.0).
       // Маршрутизация из server.php подключается в EPIC-10.3.
   }

   // -------------------------------------------------------------------------
   // Private helpers
   // -------------------------------------------------------------------------

   /**
    * Сохраняет session_token → user_id в worker-памяти.
    * Инициализирует $worker->sessionTokens если массив ещё не создан.
    */
   private function storeSession(object $worker, string $token, int $userId): void
   {
       if (!isset($worker->sessionTokens)) {
           $worker->sessionTokens = [];
       }
       $worker->sessionTokens[$token] = $userId;
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
       return match ($message) {
           'Username already exists' => 'error.auth_username_taken',
           default                   => 'error.auth_invalid_username',
       };
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