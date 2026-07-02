<?php

declare(strict_types=1);

/**
 * EPIC-9.0 — Admin authentication tests
 * Run: php tests/manual/test_admin_auth.php
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
