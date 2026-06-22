<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Lotto\Infrastructure\Database;
use Lotto\Infrastructure\PreparedStatements;
use Lotto\Core\Logger;
use Lotto\Auth\SessionService;
use Lotto\Auth\AuthService;

echo "=== STARTING MANUAL TESTING FOR EPIC-1.3 (Single Session Protection) ===\n\n";

try {
    $db = new Database();
    $pdo = $db->getPdo();

    // Очистка и подготовка тестового пользователя
    $pdo->exec("DELETE FROM users WHERE username = 'single_user'");
    $passwordHash = password_hash('password123', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (username, password_hash, coins, is_admin, banned_until, last_daily_bonus) VALUES (?, ?, 500, 0, 0, 0)")
        ->execute(['single_user', $passwordHash]);

    // Получаем ID созданного пользователя для сверки с userConnections
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = 'single_user'");
    $stmt->execute();
    $userId = (int)$stmt->fetchColumn();

    $statements = new PreparedStatements($pdo);
    $logger = new Logger();
    $sessionService = new SessionService();
    $authService = new AuthService($db, $statements, $logger, $sessionService);

    // Создаем легковесный анонимный класс для имитации объекта воркера
    $worker = new class {
        public array $userConnections = [];
    };
    $mockConnection = 'mock_connection_resource_or_object';

    // --- Scenario 1: Создать первую авторизацию (user login) ---
    echo "[Scenario 1] Performing first login...\n";
    $res1 = $authService->login('single_user', 'password123', $worker, $mockConnection);

    if ($res1['success'] === true && isset($worker->userConnections[$userId]) && $worker->userConnections[$userId] === $mockConnection) {
        echo "✅ Success: First login succeeded and registered connection.\n";
    } else {
        echo "❌ Failure in Scenario 1: Connection was not registered in worker.\n";
        exit(1);
    }
    echo "----------------------------------------\n";

    // --- Scenario 2: Выполнить второй login, не удаляя из userConnections ---
    echo "[Scenario 2] Performing second login while connection is active...\n";
    try {
        $authService->login('single_user', 'password123', $worker, $mockConnection);
        echo "❌ Failure: Second login allowed for already connected user!\n";
        exit(1);
    } catch (\Exception $e) {
        if ($e->getMessage() === 'User already logged in') {
            echo "✅ Success: Caught expected 'User already logged in' exception.\n";
        } else {
            echo "❌ Failure: Unexpected exception message: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
    echo "----------------------------------------\n";

    // --- Scenario 3: Удалить запись из userConnections и выполнить login повторно ---
    echo "[Scenario 3] Removing connection and trying login again...\n";
    unset($worker->userConnections[$userId]);

    $res3 = $authService->login('single_user', 'password123', $worker, $mockConnection);

    if ($res3['success'] === true && isset($worker->userConnections[$userId]) && $worker->userConnections[$userId] === $mockConnection) {
        echo "✅ Success: Login succeeded after explicit connection termination.\n";
    } else {
        echo "❌ Failure in Scenario 3: Login failed or connection not restored.\n";
        exit(1);
    }
    echo "----------------------------------------\n";

    echo "🚀 ALL SINGLE SESSION PROTECTION TESTS PASSED SUCCESSFULLY!\n";

} catch (\Throwable $t) {
    echo "💥 Critical error during Single Session testing: " . $t->getMessage() . "\n";
    exit(1);
}