<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Lotto\Auth\SessionService;

$service = new SessionService();

// Test 1: Generation length check
$token = $service->generateToken();
$test1 = (strlen($token) === 32);
echo "Test 1 (Length == 32): " . ($test1 ? "PASSED" : "FAILED") . "\n";

// Test 2: Valid token check
$test2 = $service->isValidToken($token);
echo "Test 2 (isValidToken true): " . ($test2 ? "PASSED" : "FAILED") . "\n";

// Test 3: Invalid token check (short string)
$test3 = ($service->isValidToken("123") === false);
echo "Test 3 (isValidToken false): " . ($test3 ? "PASSED" : "FAILED") . "\n";

// Test 4: Equal tokens comparison
$test4 = $service->tokensEqual($token, $token);
echo "Test 4 (tokensEqual true): " . ($test4 ? "PASSED" : "FAILED") . "\n";

// Test 5: Unequal tokens comparison
$newToken = $service->generateToken();
$test5 = ($service->tokensEqual($token, $newToken) === false);
echo "Test 5 (tokensEqual false): " . ($test5 ? "PASSED" : "FAILED") . "\n";

if ($test1 && $test2 && $test3 && $test4 && $test5) {
    echo "ALL TESTS PASSED\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
