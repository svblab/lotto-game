<?php require_once __DIR__ . '/../../vendor/autoload.php'; use 
Lotto\Infrastructure\Database; use 
Lotto\Infrastructure\PreparedStatements; use Lotto\Core\Logger; use 
Lotto\Auth\AuthService; echo "=== STARTING MANUAL TESTING FOR EPIC-1.1 
===\n\n"; try {
    // Инициализация инфраструктуры по контракту
    $db = new Database(); $pdo = $db->getPdo();
    
    // Изоляция: Очистка старых тестовых данных
    $pdo->exec("DELETE FROM users WHERE username IN ('login_valid', 
    'login_banned', 'login_bonus')");
    
    $passwordHash = password_hash('password123', PASSWORD_DEFAULT); $now 
    = time();
    
    // Подготовка пользователей под сценарии 1. Валидный пользователь 
    // (бонус уже получен сегодня, чтобы не влиять на Сценарий 1)
    $pdo->prepare("INSERT INTO users (username, password_hash, coins, 
    is_admin, banned_until, last_daily_bonus) VALUES (?, ?, 500, 0, 0, 
    ?)")
        ->execute(['login_valid', $passwordHash, $now]);
        
    // 2. Забаненный пользователь (Сценарий 4)
    $pdo->prepare("INSERT INTO users (username, password_hash, coins, 
    is_admin, banned_until, last_daily_bonus) VALUES (?, ?, 500, 0, ?, 
    ?)")
        ->execute(['login_banned', $passwordHash, $now + 3600, $now]);
        
    // 3. Пользователь для получения ежедневного бонуса (Сценарий 5 и 6, 
    // бонус получен 25 часов назад)
    $pdo->prepare("INSERT INTO users (username, password_hash, coins, 
    is_admin, banned_until, last_daily_bonus) VALUES (?, ?, 500, 0, 0, 
    ?)")
        ->execute(['login_bonus', $passwordHash, $now - 90000]); 
    $statements = new PreparedStatements($pdo); $logger = new Logger(); 
    $authService = new AuthService($db, $statements, $logger);
    // --- Сценарий 1: Валидный логин ---
    echo "[Scenario 1] Testing valid login...\n"; $res1 = 
    $authService->login('login_valid', 'password123'); if 
    ($res1['success'] === true && $res1['user']['username'] === 
    'login_valid' && $res1['daily_bonus_received'] === false) {
        echo "✅ Success: Valid user logged in correctly.\n";
    } else {
        echo "❌ Failure in Scenario 1\n"; exit(1);
    }
    echo "----------------------------------------\n";
    // --- Сценарий 2: Неверный пароль ---
    echo "[Scenario 2] Testing wrong password...\n"; try { 
        $authService->login('login_valid', 'wrong_password'); echo "❌ 
        Failure: Login allowed with invalid password!\n"; exit(1);
    } catch (\Exception $e) {
        if ($e->getMessage() === 'Invalid username or password') { echo 
            "✅ Success: Caught expected exception for wrong 
            password.\n";
        } else {
            echo "❌ Failure: Unexpected message: " . $e->getMessage() . 
            "\n"; exit(1);
        }
    }
    echo "----------------------------------------\n";
    // --- Сценарий 3: Несуществующий пользователь ---
    echo "[Scenario 3] Testing non-existent user...\n"; try { 
        $authService->login('login_ghost', 'password123'); echo "❌ 
        Failure: Login allowed for non-existent user!\n"; exit(1);
    } catch (\Exception $e) {
        if ($e->getMessage() === 'Invalid username or password') { echo 
            "✅ Success: Caught expected exception for non-existent 
            user.\n";
        } else {
            echo "❌ Failure: Unexpected message: " . $e->getMessage() . 
            "\n"; exit(1);
        }
    }
    echo "----------------------------------------\n";
    // --- Сценарий 4: Активный бан ---
    echo "[Scenario 4] Testing active ban enforcement...\n"; try { 
        $authService->login('login_banned', 'password123'); echo "❌ 
        Failure: Banned user successfully bypassed security!\n"; 
        exit(1);
    } catch (\Exception $e) {
        if ($e->getMessage() === 'User is banned') { echo "✅ Success: 
            Banned user block caught correctly.\n";
        } else {
            echo "❌ Failure: Unexpected message: " . $e->getMessage() . 
            "\n"; exit(1);
        }
    }
    echo "----------------------------------------\n";
    // --- Сценарий 5: Начисление Daily Bonus ---
    echo "[Scenario 5] Testing daily bonus assignment (over 24h 
    passed)...\n"; $res5 = $authService->login('login_bonus', 
    'password123'); if ($res5['success'] === true && 
    $res5['daily_bonus_received'] === true && $res5['user']['coins'] === 
    600) {
        echo "✅ Success: Bonus assigned (+100 coins, total balance 
        600).\n";
    } else {
        echo "❌ Failure in Scenario 5: Coins = " . 
        ($res5['user']['coins'] ?? 'null') . "\n"; exit(1);
    }
    echo "----------------------------------------\n";
    // --- Сценарий 6: Повторный логин до истечения суток ---
    echo "[Scenario 6] Testing repeated login within 24 hours...\n"; 
    $res6 = $authService->login('login_bonus', 'password123'); if 
    ($res6['success'] === true && $res6['daily_bonus_received'] === 
    false && $res6['user']['coins'] === 600) {
        echo "✅ Success: Daily bonus correctly skipped on rapid 
        sequential login.\n";
    } else {
        echo "❌ Failure in Scenario 6: Bonus received state is 
        unexpected.\n"; exit(1);
    }
    echo "----------------------------------------\n"; echo "🚀 ALL 
    LOGIN TESTS PASSED SUCCESSFULLY! EPIC-1.1 module is fully 
    production-ready.\n";
} catch (\Throwable $t) {
    echo "💥 Critical error during login testing: " . $t->getMessage() . 
    "\n"; echo "File: " . $t->getFile() . " on line " . $t->getLine() . 
    "\n"; exit(1);
}
