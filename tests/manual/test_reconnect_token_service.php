<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Lotto\Auth\ReconnectTokenService;

echo "=== STARTING MANUAL TESTING FOR EPIC-1.3 (ReconnectTokenService) ===\n\n";

$service = new ReconnectTokenService();

// --- Scenario 1: Генерация токена ---
echo "[Scenario 1] Testing token generation length...\n";
$token1 = $service->generateToken();
if (strlen($token1) === 64) {
    echo "? Success: Token is exactly 64 characters long.\n";
} else {
    echo "? Failure: Expected length 64, got " . strlen($token1) . "\n";
    exit(1);
}
echo "----------------------------------------\n";

// --- Scenario 2: Проверка валидного токена ---
echo "[Scenario 2] Testing validateToken() with a valid token...\n";
if ($service->validateToken($token1) === true) {
    echo "? Success: Valid hex token recognized correctly.\n";
} else {
    echo "? Failure: Valid token reported as invalid.\n";
    exit(1);
}
echo "----------------------------------------\n";

// --- Scenario 3: Проверка мусорной строки ---
echo "[Scenario 3] Testing validateToken() with garbage string...\n";
$garbage = "not-a-hex-string-and-definitely-not-sixty-four-characters-long-12345";
if ($service->validateToken($garbage) === false) {
    echo "? Success: Non-hex/invalid length string successfully rejected.\n";
} else {
    echo "? Failure: Accepted an invalid garbage string.\n";
    exit(1);
}
echo "----------------------------------------\n";

// --- Scenario 4: Сравнение одинаковых токенов ---
echo "[Scenario 4] Testing tokensEqual() with identical tokens...\n";
if ($service->tokensEqual($token1, $token1) === true) {
    echo "? Success: Identical tokens matched perfectly via hash_equals.\n";
} else {
    echo "? Failure: Identical tokens mismatch.\n";
    exit(1);
}
echo "----------------------------------------\n";

// --- Scenario 5: Сравнение разных токенов ---
echo "[Scenario 5] Testing tokensEqual() with different tokens...\n";
$token2 = $service->generateToken();
if ($service->tokensEqual($token1, $token2) === false) {
    echo "? Success: Distinct tokens correctly identified as non-equal.\n";
} else {
    echo "? Failure: Different tokens falsely matched as equal.\n";
    exit(1);
}
echo "----------------------------------------\n";

echo "?? ALL RECONNECT TOKEN SERVICE TESTS PASSED SUCCESSFULLY!\n";
exit(0);