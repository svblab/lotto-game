<?php

declare(strict_types=1);

/**
 * EPIC-9.2 — Admin unban user tests
 * Run: php tests/manual/test_admin_unban.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Helpers.php';

use Lotto\Admin\AdminService;

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

class MockLogger
{
    public array $logs = [];
    public function info(string $m): void { $this->logs[] = $m; }
}

class MockStmts
{
    public array $unbanUpdates = [];

    public function get(string $key): object
    {
        if ($key === 'unban_user') {
            return new class($this) {
                private MockStmts $parent;
                public function __construct(MockStmts $parent) { $this->parent = $parent; }
                public function execute(array $p): void { $this->parent->unbanUpdates[] = (int)$p[0]; }
                public function fetch(): false { return false; }
            };
        }
        throw new InvalidArgumentException("Unknown statement key: {$key}");
    }
}

// ---------------------------------------------------------------------------
// GROUP 1: unauthenticated / non-admin are rejected by guard
// ---------------------------------------------------------------------------
{
    $svc = new AdminService(new MockStmts(), new MockLogger());

    $connNoAuth = new MockConnection(1, null, false);
    $svc->handleUnbanUser(['user_id' => 15], $connNoAuth);
    assert_true(($connNoAuth->lastPacket()['code'] ?? '') === 'error.auth_required', 'unban: unauthenticated rejected');

    $connNonAdmin = new MockConnection(2, 20, false);
    $svc->handleUnbanUser(['user_id' => 15], $connNonAdmin);
    assert_true(($connNonAdmin->lastPacket()['code'] ?? '') === 'error.not_your_turn', 'unban: non-admin rejected');
}

// ---------------------------------------------------------------------------
// GROUP 2: invalid user_id rejected
// ---------------------------------------------------------------------------
{
    $stmts = new MockStmts();
    $svc = new AdminService($stmts, new MockLogger());
    $admin = new MockConnection(3, 1, true);

    $svc->handleUnbanUser(['user_id' => 0], $admin);
    assert_true(($admin->lastPacket()['code'] ?? '') === 'error.invalid_json', 'unban: invalid user_id rejected');
    assert_true(count($stmts->unbanUpdates) === 0, 'unban: no DB update on invalid payload');
}

// ---------------------------------------------------------------------------
// GROUP 3: success path updates DB and logs
// ---------------------------------------------------------------------------
{
    $stmts = new MockStmts();
    $logger = new MockLogger();
    $svc = new AdminService($stmts, $logger);
    $admin = new MockConnection(4, 1, true);

    $svc->handleUnbanUser(['user_id' => 42], $admin);
    assert_true(count($stmts->unbanUpdates) === 1, 'unban: DB update executed');
    assert_true($stmts->unbanUpdates[0] === 42, 'unban: DB update target user_id matches');
    assert_true(count($admin->sent) === 0, 'unban: no error packet on success');
    assert_true(count($logger->logs) === 1, 'unban: action logged');
}

// ---------------------------------------------------------------------------
// RESULTS
// ---------------------------------------------------------------------------
$total = $passed + $failed;
echo "\n--- EPIC-9.2 Admin Unban Test Suite ---\n";
echo "{$passed} / {$total} PASSED\n";
if ($failed > 0) {
    echo "{$failed} FAILED\n";
    exit(1);
}
exit(0);
