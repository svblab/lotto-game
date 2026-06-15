<?php require_once __DIR__ . '/../../vendor/autoload.php'; use 
Lotto\Infrastructure\Database; use 
Lotto\Infrastructure\PreparedStatements; use Lotto\Core\Logger; use 
Lotto\Auth\AuthService; echo "=== STARTING MANUAL TESTING FOR EPIC-1.0 
===\n\n"; try {
    // 1. Инициализация инфраструктуры
    $db = new Database();
    
    // Тест проверяет контракт напрямую, без угадывания и 
    // fallback-логики
    $pdo = $db->getPdo();
    
    // Предварительная очистка таблицы для чистоты экспериментов
    $pdo->exec("DELETE FROM users WHERE username IN ('test_user_1', 
    'ab')");
    
    $statements = new PreparedStatements($pdo); $logger = new Logger();
    
    $authService = new AuthService($db, $statements, $logger);
    // --- Сценарий №1: Регистрация нового валидного пользователя ---
    echo "[Scenario 1] Registering a new valid user...\n"; $res1 = 
    $authService->register('test_user_1', 'password123'); if 
    (isset($res1['success']) && $res1['success'] === true && 
    $res1['username'] === 'test_user_1') {
        echo "✅ Success: User registered successfully.\n";
    } else {
        echo "❌ Failure in Scenario 1\n"; exit(1);
    }
    echo "----------------------------------------\n";
    // --- Сценарий №2: Повторная регистрация того же пользователя ---
    echo "[Scenario 2] Registering the same user again...\n"; try { 
        $authService->register('test_user_1', 'password123'); echo "❌ 
        Failure: Duplicate user was allowed!\n"; exit(1);
    } catch (\Exception $e) {
        if ($e->getMessage() === 'Username already exists') { echo "✅ 
            Success: Correctly rejected duplicate username.\n";
        } else {
            echo "❌ Failure: Unexpected exception message: " . 
            $e->getMessage() . "\n"; exit(1);
        }
    }
    echo "----------------------------------------\n";
    // --- Сценарий №3: Проверка слишком короткого username ---
    echo "[Scenario 3] Testing short username ('ab')...\n"; try { 
        $authService->register('ab', 'password123'); echo "❌ Failure: 
        Short username was accepted!\n"; exit(1);
    } catch (\Exception $e) {
        echo "✅ Success: Formally caught validation exception: " . 
        $e->getMessage() . "\n";
    }
    echo "----------------------------------------\n";
    // --- Сценарий №4: Проверка короткого пароля ---
    echo "[Scenario 4] Testing short password ('123')...\n"; try { 
        $authService->register('test_user_2', '123'); echo "❌ Failure: 
        Short password was accepted!\n"; exit(1);
    } catch (\Exception $e) {
        echo "✅ Success: Formally caught validation exception: " . 
        $e->getMessage() . "\n";
    }
    echo "----------------------------------------\n";
    // --- Сценарий №5: Проверка полей БД на дефолтные значения ---
    echo "[Scenario 5] Checking database fields for default values 
    (coins = 500)...\n"; $checkStmt = $pdo->prepare("SELECT username, 
    coins FROM users WHERE username = 'test_user_1'"); 
    $checkStmt->execute(); $userRow = $checkStmt->fetch();
    
    if ($userRow && (int)$userRow['coins'] === 500) { echo "✅ Success: 
        Initial balance is strictly 500 coins.\n";
    } else {
        echo "❌ Failure: Incorrect or missing initial data. Balance: " 
        . ($userRow['coins'] ?? 'null') . "\n"; exit(1);
    }
    echo "----------------------------------------\n"; echo "🚀 ALL 
    TESTS PASSED SUCCESSFULLY! AuthService Registration module is 
    ready.\n";
} catch (\Throwable $t) {
    echo "💥 Critical error during testing: " . $t->getMessage() . "\n"; 
    echo "File: " . $t->getFile() . " on line " . $t->getLine() . "\n"; 
    exit(1);
}
