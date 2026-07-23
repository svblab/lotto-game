<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Lotto\Infrastructure\Database;
use Lotto\Infrastructure\PreparedStatements;
use Lotto\Core\Logger;
use Lotto\Auth\SessionService;
use Lotto\Auth\AuthService;

echo "=== STARTING MANUAL TESTING FOR EPIC-1.2 (SessionService Integration) ===\n\n";

try {
    $db = new Database();
    $pdo = $db->getPdo();
    
    $sessionService = new SessionService();
    $statements = new PreparedStatements($pdo);
    $logger = new Logger();
    $authService = new AuthService($db, $statements, $logger, $sessionService);

    // --- Scenario 1: generateToken() format ---
    echo "[Scenario 1] Testing generateToken() format...\n";
    $token1 = $sessionService->generateToken();
    if (strlen($token1) === 32 && ctype_xdigit($token1)) {
        echo "✅ Success: Token is exactly 32 chars and hex format.\n";
    } else {
        echo "❌ Failure: Invalid format or length of token.\n";
        exit(1);
    }
    echo "----------------------------------------\n";

    // --- Scenario 2: isValidToken(validToken) ---
    echo "[Scenario 2] Testing isValidToken() with valid token...\n";
    if ($sessionService->isValidToken($token1) === true) {
        echo "✅ Success: Valid token recognized correctly.\n";
    } else {
        echo "❌ Failure: Valid token reported as invalid.\n";
        exit(1);
    }
    echo "----------------------------------------\n";

    // --- Scenario 3: isValidToken('invalid') ---
    echo "[Scenario 3] Testing isValidToken() with invalid string...\n";
    if ($sessionService->isValidToken('invalid_token_string_here_12345') === false) {
        echo "✅ Success: Invalid token successfully rejected.\n";
    } else {
        echo "❌ Failure: Accepted an invalid token string.\n";
        exit(1);
    }
    echo "----------------------------------------\n";

    // --- Scenario 4: tokensEqual() same ---
    echo "[Scenario 4] Testing tokensEqual() with identical tokens...\n";
    if ($sessionService->tokensEqual($token1, $token1) === true) {
        echo "✅ Success: Identical tokens match perfectly.\n";
    } else {
        echo "❌ Failure: Identical tokens mismatch.\n";
        exit(1);
    }
    echo "----------------------------------------\n";

    // --- Scenario 5: tokensEqual() different ---
    echo "[Scenario 5] Testing tokensEqual() with different tokens...\n";
    $token2 = $sessionService->generateToken();
    if ($sessionService->tokensEqual($token1, $token2) === false) {
        echo "✅ Success: Different tokens distinctiveness verified.\n";
    } else {
        echo "❌ Failure: Different tokens matched as equal.\n";
        exit(1);
    }
    echo "----------------------------------------\n";

    // --- Scenario 6: Consecutive logins produce different session tokens ---
    echo "[Scenario 6] Testing consecutive logins for unique session tokens...\n";
    $pdo->exec("DELETE FROM users WHERE username = 'session_user'");
    $passwordHash = password_hash('password123', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (username, password_hash, coins, is_admin, banned_until, last_daily_bonus) VALUES (?, ?, 500, 0, 0, 0)")
        ->execute(['session_user', $passwordHash]);

    $loginA = $authService->login('session_user', 'password123');
    $loginB = $authService->login('session_user', 'password123');

    if ($loginA['session_token'] !== $loginB['session_token']) {
        echo "✅ Success: Sequential logins generated unique tokens.\n";
    } else {
        echo "❌ Failure: Sequential logins yielded duplicate token: " . $loginA['session_token'] . "\n";
        exit(1);
    }
    echo "----------------------------------------\n";

    echo "🚀 ALL SESSION INTEGRATION TESTS PASSED SUCCESSFULLY!\n";

} catch (\Throwable $t) {
    echo "💥 Critical error during SessionService testing: " . $t->getMessage() . "\n";
    exit(1);
}