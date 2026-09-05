<?php

/**
 * Reset the bootstrap admin password (ADR-038 recovery).
 * Writes a one-shot bootstrap artifact; caller promotes to AHPC pending file.
 *
 * Env: LOTTO_DB_PATH (required), LOTTO_ADMIN_BOOTSTRAP_FILE (required)
 */

$dbPathEnv = getenv('LOTTO_DB_PATH');
$bootstrapFile = getenv('LOTTO_ADMIN_BOOTSTRAP_FILE');

if (!is_string($dbPathEnv) || $dbPathEnv === '') {
    fwrite(STDERR, "LOTTO_DB_PATH is required\n");
    exit(1);
}
if (!is_string($bootstrapFile) || $bootstrapFile === '') {
    fwrite(STDERR, "LOTTO_ADMIN_BOOTSTRAP_FILE is required\n");
    exit(1);
}
if (!is_file($dbPathEnv)) {
    fwrite(STDERR, "Database not found: {$dbPathEnv}\n");
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $dbPathEnv);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
    $stmt->execute([':username' => 'admin']);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$admin) {
        fwrite(STDERR, "Admin user does not exist\n");
        exit(1);
    }

    $password = bin2hex(random_bytes(12));
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);

    $update = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE username = :username');
    $update->execute([
        ':password_hash' => $passwordHash,
        ':username'      => 'admin',
    ]);

    $bootstrapDir = dirname($bootstrapFile);
    if ($bootstrapDir !== '' && $bootstrapDir !== '.' && !is_dir($bootstrapDir)) {
        if (!mkdir($bootstrapDir, 0755, true) && !is_dir($bootstrapDir)) {
            throw new RuntimeException("Cannot create bootstrap directory: {$bootstrapDir}");
        }
    }

    $written = file_put_contents(
        $bootstrapFile,
        "ADMIN PASSWORD:\n" . $password . "\n",
        LOCK_EX
    );
    if ($written === false) {
        throw new RuntimeException("Cannot write admin bootstrap file: {$bootstrapFile}");
    }
    @chmod($bootstrapFile, 0600);
} catch (Throwable $e) {
    fwrite(STDERR, 'Reset failed: ' . $e->getMessage() . "\n");
    exit(1);
}
