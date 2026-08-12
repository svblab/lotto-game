<?php

declare(strict_types=1);

/**
 * EPIC-9.0 — Admin authentication tests
 * Run: php tests/manual/test_admin_auth.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Helpers.php';

use Lotto\Admin\AdminService;
use Lotto\Infrastructure\Database;
use Lotto\Infrastructure\PreparedStatements;
use function Lotto\Core\lottoBootstrapPhpExtensions;

lottoBootstrapPhpExtensions();

$passed = 0;
$failed = 0;

function ok(string $label): void { global $passed; $passed++; echo "[PASS] {$label}\n"; }
function fail(string $label, string $reason = ''): void { global $failed; $failed++; echo "[FAIL] {$label}" . ($reason ? " — {$reason}" : '') . "\n"; }
function assert_true(bool $cond, string $label, string $reason = ''): void { $cond ? ok($label) : fail($label, $reason); }

class MockConnection
{
    public int $id;
    public ?int $userId;
    public bool $isAdmin;
    public array $sent = [];

    public function __construct(int $id, ?int $userId, bool $isAdmin)
    {
        $this->id = $id;
        $this->userId = $userId;
        $this->isAdmin = $isAdmin;
    }

    public function send(string $data): void
    {
        $this->sent[] = json_decode($data, true);
    }

    public function lastPacket(): ?array
    {
        return end($this->sent) ?: null;
    }
}

$svc = new AdminService();

// ---------------------------------------------------------------------------
// GROUP 1: unauthenticated connection is rejected
// ---------------------------------------------------------------------------
{
    $conn = new MockConnection(1, null, false);
    $okAdmin = $svc->assertAdmin($conn);
    assert_true($okAdmin === false, 'assertAdmin: unauthenticated rejected');
    $pkt = $conn->lastPacket();
    assert_true(($pkt['type'] ?? '') === 'error', 'assertAdmin: error packet sent for unauthenticated');
    assert_true(($pkt['code'] ?? '') === 'error.auth_required', 'assertAdmin: code error.auth_required');
}

// ---------------------------------------------------------------------------
// GROUP 2: authenticated non-admin is rejected
// ---------------------------------------------------------------------------
{
    $conn = new MockConnection(2, 20, false);
    $okAdmin = $svc->assertAdmin($conn);
    assert_true($okAdmin === false, 'assertAdmin: non-admin rejected');
    $pkt = $conn->lastPacket();
    assert_true(($pkt['type'] ?? '') === 'error', 'assertAdmin: error packet sent for non-admin');
    assert_true(($pkt['code'] ?? '') === 'error.not_your_turn', 'assertAdmin: code error.not_your_turn');
}

// ---------------------------------------------------------------------------
// GROUP 3: authenticated admin is allowed
// ---------------------------------------------------------------------------
{
    $conn = new MockConnection(3, 30, true);
    $okAdmin = $svc->assertAdmin($conn);
    assert_true($okAdmin === true, 'assertAdmin: admin allowed');
    assert_true(count($conn->sent) === 0, 'assertAdmin: no error packet for admin');
}

// ---------------------------------------------------------------------------
// GROUP 4: demoted admin (SQLite is_admin=0) rejected; connection flag corrected
// ---------------------------------------------------------------------------
{
    $db = new Database();
    $pdo = $db->getPdo();
    $pdo->exec("DELETE FROM users WHERE username LIKE 'admin_auth_%'");

    $passwordHash = password_hash('secret', PASSWORD_DEFAULT);
    $now = time();
    $pdo->prepare(
        'INSERT INTO users (username, password_hash, coins, is_admin, banned_until, last_daily_bonus) VALUES (?, ?, 500, 1, 0, ?)'
    )->execute(['admin_auth_demoted', $passwordHash, $now]);

    $adminId = (int)$pdo->lastInsertId();
    $stmts = new PreparedStatements($pdo);
    $svc = new AdminService($stmts);

    $conn = new MockConnection(4, $adminId, true);
    assert_true($svc->assertAdmin($conn) === true, 'assertAdmin: admin allowed before demotion');

    $pdo->prepare('UPDATE users SET is_admin = 0 WHERE id = ?')->execute([$adminId]);
    assert_true($svc->assertAdmin($conn) === false, 'assertAdmin: demoted admin rejected');
    $pkt = $conn->lastPacket();
    assert_true(($pkt['code'] ?? '') === 'error.not_your_turn', 'assertAdmin: demoted admin code error.not_your_turn');
    assert_true($conn->isAdmin === false, 'assertAdmin: demoted admin connection flag cleared');

    $sentBefore = count($conn->sent);
    assert_true($svc->assertAdmin($conn) === false, 'assertAdmin: demoted admin fast-fail on second call');
    assert_true(count($conn->sent) === $sentBefore + 1, 'assertAdmin: demoted admin second call still errors');
}

// ---------------------------------------------------------------------------
// GROUP 5: still-admin user unaffected by demotion of another account
// ---------------------------------------------------------------------------
{
    $db = new Database();
    $pdo = $db->getPdo();
    $passwordHash = password_hash('secret', PASSWORD_DEFAULT);
    $now = time();
    $pdo->prepare(
        'INSERT INTO users (username, password_hash, coins, is_admin, banned_until, last_daily_bonus) VALUES (?, ?, 500, 1, 0, ?)'
    )->execute(['admin_auth_still', $passwordHash, $now]);
    $stillAdminId = (int)$pdo->lastInsertId();

    $stmts = new PreparedStatements($pdo);
    $svc = new AdminService($stmts);
    $conn = new MockConnection(5, $stillAdminId, true);

    assert_true($svc->assertAdmin($conn) === true, 'assertAdmin: still-admin allowed (first)');
    assert_true($svc->assertAdmin($conn) === true, 'assertAdmin: still-admin allowed (second)');
    assert_true(count($conn->sent) === 0, 'assertAdmin: still-admin no error packets');
}

// ---------------------------------------------------------------------------
// GROUP 6: banned admin rejected with banned packet; flag cleared
// ---------------------------------------------------------------------------
{
    $db = new Database();
    $pdo = $db->getPdo();
    $passwordHash = password_hash('secret', PASSWORD_DEFAULT);
    $now = time();
    $bannedUntil = $now + 3600;
    $pdo->prepare(
        'INSERT INTO users (username, password_hash, coins, is_admin, banned_until, last_daily_bonus) VALUES (?, ?, 500, 1, ?, ?)'
    )->execute(['admin_auth_banned', $passwordHash, $bannedUntil, $now]);

    $bannedAdminId = (int)$pdo->lastInsertId();
    $stmts = new PreparedStatements($pdo);
    $svc = new AdminService($stmts);
    $conn = new MockConnection(6, $bannedAdminId, true);

    assert_true($svc->assertAdmin($conn) === false, 'assertAdmin: banned admin rejected');
    $pkt = $conn->lastPacket();
    assert_true(($pkt['type'] ?? '') === 'banned', 'assertAdmin: banned admin gets banned packet');
    assert_true((int)($pkt['until'] ?? 0) === $bannedUntil, 'assertAdmin: banned until timestamp preserved');
    assert_true($conn->isAdmin === false, 'assertAdmin: banned admin connection flag cleared');
}

// ---------------------------------------------------------------------------
// RESULTS
// ---------------------------------------------------------------------------
$total = $passed + $failed;
echo "\n--- EPIC-9.0 Admin Auth Test Suite ---\n";
echo "{$passed} / {$total} PASSED\n";
if ($failed > 0) {
    echo "{$failed} FAILED\n";
    exit(1);
}
exit(0);
