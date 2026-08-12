<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Lotto\Auth\AuthService;
use Lotto\Auth\LoginThrottleService;
use Lotto\Auth\SessionService;
use Lotto\Core\Constants;
use Lotto\Core\Logger;
use Lotto\Infrastructure\Database;
use Lotto\Infrastructure\PreparedStatements;

echo "=== test_login_throttle.php (EPIC-5a / ADR-028) ===\n\n";

$passed = 0;
$failed = 0;

function assertTrue(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) {
        echo "PASS: {$label}\n";
        $passed++;
    } else {
        echo "FAIL: {$label}\n";
        $failed++;
    }
}

function expectLoginException(AuthService $auth, string $user, string $pass, string $expectedMsg): void
{
    try {
        $auth->login($user, $pass);
        assertTrue(false, "expected exception: {$expectedMsg}");
    } catch (Exception $e) {
        assertTrue($e->getMessage() === $expectedMsg, "exception message === {$expectedMsg} (got: {$e->getMessage()})");
    }
}

try {
    $db = new Database();
    $pdo = $db->getPdo();
    $pdo->exec("DELETE FROM users WHERE username LIKE 'throttle_%'");

    $passwordHash = password_hash('secret123', PASSWORD_DEFAULT);
    $now = time();
    $insert = $pdo->prepare(
        'INSERT INTO users (username, password_hash, coins, is_admin, banned_until, last_daily_bonus) VALUES (?, ?, 500, 0, 0, ?)'
    );
    $insert->execute(['throttle_user_a', $passwordHash, $now]);
    $insert->execute(['throttle_user_b', $passwordHash, $now]);

    $statements = new PreparedStatements($pdo);
    $logger = new Logger(sys_get_temp_dir() . '/lotto_test_login_throttle_' . getmypid() . '.log');
    register_shutdown_function(function (): void {
        @unlink(sys_get_temp_dir() . '/lotto_test_login_throttle_' . getmypid() . '.log');
    });
    $sessionService = new SessionService();

    $clock = ['t' => time()];
    $throttle = new LoginThrottleService(function () use (&$clock): int {
        return $clock['t'];
    });
    $auth = new AuthService($db, $statements, $logger, $sessionService, $throttle);

    // 1) Two mistyped passwords do NOT lock out
    echo "--- Scenario 1: two typos not locked ---\n";
    expectLoginException($auth, 'throttle_user_a', 'wrong1', 'Invalid username or password');
    expectLoginException($auth, 'throttle_user_a', 'wrong2', 'Invalid username or password');
    expectLoginException($auth, 'throttle_user_a', 'wrong3', 'Invalid username or password');
    assertTrue(!$throttle->isLocked('throttle_user_a'), 'not locked after 3 failures (< MAX)');

    // 2) Lockout after MAX_ATTEMPTS failures
    echo "\n--- Scenario 2: lockout after N failures ---\n";
    $throttle->recordSuccess('throttle_user_a');
    for ($i = 0; $i < Constants::LOGIN_THROTTLE_MAX_ATTEMPTS; $i++) {
        expectLoginException($auth, 'throttle_user_a', 'bad', 'Invalid username or password');
    }
    expectLoginException($auth, 'throttle_user_a', 'bad', 'Auth rate limited');
    assertTrue($throttle->isLocked('throttle_user_a'), 'locked after threshold');

    // 3) Cooldown expires and allows retry
    echo "\n--- Scenario 3: cooldown expiry ---\n";
    $clock['t'] += Constants::LOGIN_THROTTLE_LOCKOUT_SECONDS + 1;
    expectLoginException($auth, 'throttle_user_a', 'bad', 'Invalid username or password');
    assertTrue(!$throttle->isLocked('throttle_user_a'), 'unlocked after cooldown');

    // 4) Successful login clears failure counter
    echo "\n--- Scenario 4: success clears counter ---\n";
    $throttle->recordSuccess('throttle_user_a');
    for ($i = 0; $i < Constants::LOGIN_THROTTLE_MAX_ATTEMPTS - 1; $i++) {
        expectLoginException($auth, 'throttle_user_a', 'bad', 'Invalid username or password');
    }
    $ok = $auth->login('throttle_user_a', 'secret123');
    assertTrue($ok['success'] === true, 'successful login after failures');
    for ($i = 0; $i < Constants::LOGIN_THROTTLE_MAX_ATTEMPTS - 1; $i++) {
        expectLoginException($auth, 'throttle_user_a', 'bad', 'Invalid username or password');
    }
    assertTrue(!$throttle->isLocked('throttle_user_a'), 'not locked after success reset + 4 failures');

    // 5) Per-username isolation
    echo "\n--- Scenario 5: per-username isolation ---\n";
    $throttle->recordSuccess('throttle_user_a');
    $throttle->recordSuccess('throttle_user_b');
    for ($i = 0; $i < Constants::LOGIN_THROTTLE_MAX_ATTEMPTS; $i++) {
        expectLoginException($auth, 'throttle_user_a', 'bad', 'Invalid username or password');
    }
    expectLoginException($auth, 'throttle_user_a', 'bad', 'Auth rate limited');
    $okB = $auth->login('throttle_user_b', 'secret123');
    assertTrue($okB['success'] === true, 'other username unaffected by lockout');

    echo "\n=== SUMMARY: {$passed} passed, {$failed} failed ===\n";
    exit($failed > 0 ? 1 : 0);
} catch (Throwable $e) {
    echo "FATAL: " . $e->getMessage() . "\n";
    exit(1);
}
