<?php

namespace Lotto\Auth;

use Exception;
use Lotto\Infrastructure\Database;
use Lotto\Infrastructure\PreparedStatements;
use Lotto\Core\Logger;

class AuthService
{
    /**
     * Precomputed bcrypt (cost 10) for constant-time login on unknown usernames.
     * password_verify() result is discarded — only its timing is used.
     */
    private const TIMING_DUMMY_PASSWORD_HASH =
        '$2y$10$/ST2sjWQ5iTbxJZyOreKg.rgIH2mEC/s2jyDXQCBgVy27kQFqtZMK';

    private Database $db;
    private PreparedStatements $statements;
    private Logger $logger;
    private SessionService $sessionService;
    private ?LoginThrottleService $loginThrottle;

    /**
     * Конструктор сервиса с внедрением зависимостей (DI).
     * Избегаем глобальных состояний и синглтонов.
     */
    public function __construct(
        Database $db,
        PreparedStatements $statements,
        Logger $logger,
        SessionService $sessionService,
        ?LoginThrottleService $loginThrottle = null
    ) {
        $this->db = $db;
        $this->statements = $statements;
        $this->logger = $logger;
        $this->sessionService = $sessionService;
        $this->loginThrottle = $loginThrottle;
    }

    /**
     * Регистрация нового пользователя в системе.
     *
     * @param string $username
     * @param string $password
     * @return array
     * @throws Exception
     */
    public function register(string $username, string $password): array
    {
        try {
            // 1. Валидация имени пользователя
            if (!preg_match('/^[A-Za-z0-9_]{3,20}$/', $username)) {
                throw new Exception('Invalid username format');
            }

            // ADR-034: reserved wire name for the bot opponent (case-insensitive).
            if (strcasecmp($username, 'Bot') === 0) {
                throw new Exception('Invalid username format');
            }

            // 2. Валидация длины пароля
            $passwordLength = strlen($password);
            if ($passwordLength < 6 || $passwordLength > 64) {
                throw new Exception('Password must be between 6 and 64 characters');
            }

            // 3. Проверка на уникальность username с использованием строгого реестра SQL
            $selectStmt = $this->statements->get('user_by_username');
            $selectStmt->execute([$username]);
            
            if ($selectStmt->fetch()) {
                throw new Exception('Username already exists');
            }

            // 4. Хеширование пароля безопасным системным методом
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            // 5. Запись в БД дефолтных значений через строгое имя в реестре
            $insertStmt = $this->statements->get('create_user');
            $insertStmt->execute([$username, $passwordHash]);

            // 6. Логирование успешного исхода (Уровень INFO)
            $this->safeLog('INFO', "User registered: {$username}");

            return [
                'success' => true,
                'username' => $username
            ];

        } catch (Exception $e) {
            // Логирование ошибки регистрации (Уровень WARNING)
            $this->safeLog('WARNING', "Registration failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Вход пользователя в систему (Аутентификация и проверка Daily Bonus).
     *
     * @param string $username
     * @param string $password
     * @return array Контракт EPIC-1.1 + session_token на верхнем уровне
     * @throws Exception
     */
    public function login(string $username, string $password): array
    {
        try {
            // Шаг 1: Проверить входные данные на валидность формы
            // Шаг 1: Получить пользователя из реестра PreparedStatements
            $selectStmt = $this->statements->get('user_by_username');
            $selectStmt->execute([$username]);
            $user = $selectStmt->fetch();

            // ADR-028: per-username lockout (timing-hardened like 007712c)
            if ($this->loginThrottle !== null && $this->loginThrottle->isLocked($username)) {
                if (is_array($user)) {
                    password_verify($password, $user['password_hash']);
                } else {
                    password_verify($password, self::TIMING_DUMMY_PASSWORD_HASH);
                }
                throw new Exception('Auth rate limited');
            }

            // Шаг 2–3: Проверить хеш пароля (constant-time path when user missing)
            if (!is_array($user)) {
                password_verify($password, self::TIMING_DUMMY_PASSWORD_HASH);
                if ($this->loginThrottle !== null) {
                    $this->loginThrottle->recordFailure($username);
                }
                throw new Exception('Invalid username or password');
            }

            if (!password_verify($password, $user['password_hash'])) {
                if ($this->loginThrottle !== null) {
                    $this->loginThrottle->recordFailure($username);
                }
                throw new Exception('Invalid username or password');
            }

            if ($this->loginThrottle !== null) {
                $this->loginThrottle->recordSuccess($username);
            }

            // Шаг 4: Проверить состояние блокировки (бан)
            if ((int)$user['banned_until'] > time()) {
                throw new Exception('User is banned', (int)$user['banned_until']);
            }

            // Шаг 5: Проверить условия начисления Daily Bonus (не админ и прошло >= 24 часов)
            $dailyBonusReceived = false;
            if (!(bool)$user['is_admin'] && (time() - (int)$user['last_daily_bonus'] >= 86400)) {
                $dailyBonusReceived = true;
                $newCoins = (int)$user['coins'] + 100;
                $currentTime = time();

                // Шаг 6: Начислить бонус через update_daily_bonus
                $updateStmt = $this->statements->get('update_daily_bonus');
                $updateStmt->execute([$newCoins, $currentTime, $user['id']]);

                // Шаг 7: Синхронизировать локальную структуру пользователя
                $user['coins'] = $newCoins;
                $user['last_daily_bonus'] = $currentTime;
            }

            // EPIC-1.2: Создание нового токена сессии
            $token = $this->sessionService->generateToken();

            // EPIC-1.2: Проверка валидности созданного токена
            if (!$this->sessionService->isValidToken($token)) {
                throw new Exception('Generated session token is invalid');
            }

            // EPIC-1.3 / FIX-30: single-session enforcement lives in
            // AuthHandler::claimUserSession() — not here.
            // Login audit log (with conn_id) is written in AuthHandler after claim.

            // EPIC-1.2: Возврат существующего контракта с добавлением session_token
            return [
                'success' => true,
                'user' => [
                    'id' => (int)$user['id'],
                    'username' => (string)$user['username'],
                    'coins' => (int)$user['coins'],
                    'is_admin' => (bool)$user['is_admin']
                ],
                'daily_bonus_received' => $dailyBonusReceived,
                'session_token' => $token
            ];

        } catch (Exception $e) {
            // Запись лога ошибки выполнения/аутентификации (Уровень WARNING)
            $this->safeLog('WARNING', "Login failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * FIX-14: logging must never break auth flows (e.g. root-owned logs/server.log
     * during www-data WS tests on VPS).
     */
    private function safeLog(string $level, string $message): void
    {
        try {
            $this->logger->write($level, $message);
        } catch (Exception) {
            // Intentionally ignored.
        }
    }

    /**
     * FIX-10/FIX-11: возвращает минимальный набор полей (id, username,
     * is_admin, banned_until), необходимый AuthHandler::bindConnection()
     * при восстановлении соединения по reconnect-токену, И достаточный
     * для проверки бана в том же потоке (FIX-11 — reconnect изначально не
     * проверял banned_until вообще, в отличие от login(), что позволяло
     * забаненному пользователю обходить бан, просто используя старый
     * session_token вместо повторного login()).
     *
     * Не бросает исключений на "не найдено" — вызывающая сторона
     * (AuthHandler::handleReconnect()) трактует null как невалидную сессию.
     *
     * @return array{id:int, username:string, is_admin:bool, banned_until:int, coins:int}|null
     */
    public function getUserById(int $userId): ?array
    {
        $stmt = $this->statements->get('user_auth_fields_by_id');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'username' => (string)$row['username'],
            'is_admin' => (bool)$row['is_admin'],
            'banned_until' => (int)$row['banned_until'],
            'coins' => (int)$row['coins'],
        ];
    }
}