<?php

try {
    $dbFile = __DIR__ . '/game.db';
    
    // Open PDO connection (Creates game.db file if it does not exist)
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Enable WAL mode and Foreign Keys enforcement
    $pdo->exec('PRAGMA journal_mode=WAL;');
    $pdo->exec('PRAGMA foreign_keys=ON;');

    // Create users table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            coins INTEGER NOT NULL DEFAULT 500,
            is_admin INTEGER NOT NULL DEFAULT 0,
            banned_until INTEGER NOT NULL DEFAULT 0,
            last_daily_bonus INTEGER NOT NULL DEFAULT 0
        );
    ");

    // Create unique-assinement optimization index
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_users_username ON users (username);");

    // Check if administrator already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :username LIMIT 1");
    $stmt->execute([':username' => 'admin']);
    $admin = $stmt->fetch();

    if (!$admin) {
        // Bootstrap fresh administrator credentials
        $password = bin2hex(random_bytes(12));
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);

        $insertStmt = $pdo->prepare("
            INSERT INTO users (username, password_hash, is_admin)
            VALUES (:username, :password_hash, :is_admin)
        ");
        
        $insertStmt->execute([
            ':username'      => 'admin',
            ':password_hash' => $passwordHash,
            ':is_admin'      => 1
        ]);

        // Output generated password to console exactly once
        echo "ADMIN PASSWORD:\n" . $password . "\n";
    }

} catch (PDOException $e) {
    fwrite(STDERR, "Fatal initialization failure: " . $e->getMessage() . "\n");
    exit(1);
}
