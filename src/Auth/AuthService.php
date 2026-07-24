<?php

namespace Lotto\Auth;

use Exception;
use Lotto\Infrastructure\Database;
use Lotto\Infrastructure\PreparedStatements;
use Lotto\Core\Logger;

class AuthService
{
    private Database $db;
    private PreparedStatements $statements;
    private Logger $logger;
    private SessionService $sessionService;

    /**
     * Конструктор сервиса с внедрением зависимостей (DI).
     * Избегаем глобальных состояний и синглтонов.
     */
    public function __construct(Database $db, PreparedStatements $statements, Logger $logger, SessionService $sessionService)
    {
        $this->db = $db;
        $this->statements = $statements;
        $this->logger = $logger;
        $this->sessionService = $sessionService;
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
            $this->logger->write('INFO', "User registered: {$username}");

            return [
                'success' => true,
                'username' => $username
            ];

        } catch (Exception $e) {
            // Логирование ошибки регистрации (Уровень WARNING)
            $this->logger->write('WARNING', "Registration failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Вход пользователя в систему (Аутентификация и проверка Daily Bonus).
     *
     * @param string $username
     * @param string $password
     * @param object|null $worker Объект воркера для проверки активных соединений (EPIC-1.3)
     * @param mixed $connection Экземпляр соединения пользователя (EPIC-1.3)
     * @return array Контракт EPIC-1.1 + session_token на верхнем уровне
     * @throws Exception
     */
    public function login(string $username, string $password, $worker = null, $connection = 'mock_connection'): array
    {
        try {
            // Шаг 1: Проверить входные данные на валидность формы
            // Шаг 1: Получить пользователя из реестра PreparedStatements
            $selectStmt = $this->statements->get('user_by_username');
            $selectStmt->execute([$username]);
            $user = $selectStmt->fetch();

            // Шаг 2: Если пользователь не найден
            if (!$user) {
                throw new Exception('Invalid username or password');
            }

            // Шаг 3: Проверить хеш пароля
            if (!password_verify($password, $user['password_hash'])) {
                throw new Exception('Invalid username or password');
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

            // EPIC-1.3: Single Session Protection
            $userId = (int)$user['id'];
            if ($worker !== null) {
                if (isset($worker->userConnections[$userId])) {
                    throw new Exception('User already logged in');
                }
                $worker->userConnections[$userId] = $connection;
            }

            // Шаг 8: Записать лог об успешном входе (Уровень INFO)
            $this->logger->write('INFO', "User login: {$username}");

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
            $this->logger->write('WARNING', "Login failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * FIX-10: возвращает минимальный набор полей (id, username, is_admin),
     * необходимый AuthHandler::bindConnection() при восстановлении
     * соединения по reconnect-токену — до этого фикса не существовало
     * способа получить username/is_admin по одному только user_id,
     * из-за чего reconnect не мог полноценно аутентифицировать соединение
     * (см. FIX-10 в IMPLEMENTATION_STATUS.md).
     *
     * Не бросает исключений на "не найдено" — вызывающая сторона
     * (AuthHandler::handleReconnect()) трактует null как невалидную сессию.
     *
     * @return array{id:int, username:string, is_admin:bool}|null
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
        ];
    }
}