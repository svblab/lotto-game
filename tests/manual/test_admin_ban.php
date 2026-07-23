<?php

declare(strict_types=1);

/**
 * EPIC-9.1 — Admin ban user tests
 * Run: php tests/manual/test_admin_ban.php
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

    public function sentOfType(string $type): array
    {
        return array_values(array_filter($this->sent, fn($p) => ($p['type'] ?? '') === $type));
    }
}

class MockWorker
{
    public array $rooms = [];
    public array $userConnections = [];
}

class MockLogger
{
    public array $logs = [];
    public function info(string $m): void { $this->logs[] = $m; }
}

class MockLobbyService
{
    public array $removed = [];
    public function removePlayerFromLobby(object $worker, int $roomId, int $connId, string $reason): void
    {
        $this->removed[] = [$roomId, $connId, $reason];
    }
}

class MockReconnectService
{
    public array $removed = [];
    public function removePlayerFromGame(object $worker, int $roomId, int $connId, string $reason): void
    {
        $this->removed[] = [$roomId, $connId, $reason];
    }
}

class MockApartmentService
{
    public array $removed = [];
    public function removePlayerFromApartment(array &$room, int $roomId, int $connId, string $reason, object $worker): void
    {
        $this->removed[] = [$roomId, $connId, $reason];
    }
}

class MockStmts
{
    public array $users = [];
    public array $banUpdates = [];

    public function __construct(array $users)
    {
        $this->users = $users;
    }

    public function get(string $key): object
    {
        if ($key === 'user_admin_by_id') {
            return new class($this->users) {
                private array $users;
                private int $id = 0;
                public function __construct(array $users) { $this->users = $users; }
                public function execute(array $p): void { $this->id = (int)$p[0]; }
                public function fetch(): array|false { return $this->users[$this->id] ?? false; }
            };
        }

        if ($key === 'ban_user') {
            return new class($this) {
                private MockStmts $parent;
                public function __construct(MockStmts $parent) { $this->parent = $parent; }
                public function execute(array $p): void
                {
                    $this->parent->banUpdates[] = ['until' => (int)$p[0], 'user_id' => (int)$p[1]];
                }
                public function fetch(): false { return false; }
            };
        }

        throw new InvalidArgumentException("Unknown statement key: {$key}");
    }
}

function makeRoom(string $status, int $connId, int $userId): array
{
    return [
        'room_id' => 1,
        'status' => $status,
        'host_conn_id' => $connId,
        'bank' => 20,
        'players' => [
            $connId => [
                'user_id' => $userId,
                'username' => 'target',
                'status' => 'active',
                'total_paid' => 10,
            ],
        ],
    ];
}

// ---------------------------------------------------------------------------
// GROUP 1: cannot ban admin account
// ---------------------------------------------------------------------------
{
    $stmts = new MockStmts([
        99 => ['id' => 99, 'is_admin' => 1],
    ]);
    $svc = new AdminService($stmts, new MockLogger(), null, null, null);
    $worker = new MockWorker();
    $admin = new MockConnection(1, 1, true);

    $svc->handleBanUser(['user_id' => 99, 'duration' => '1d'], $admin, $worker);
    $pkt = $admin->lastPacket();
    assert_true(($pkt['code'] ?? '') === 'error.cannot_moderate_admin', 'ban: cannot moderate admin');
    assert_true(count($stmts->banUpdates) === 0, 'ban: no DB update for admin target');
}

// ---------------------------------------------------------------------------
// GROUP 2: duration mapping and banned packet for online user
// ---------------------------------------------------------------------------
{
    $now = time();
    $stmts = new MockStmts([
        15 => ['id' => 15, 'is_admin' => 0],
    ]);
    $logger = new MockLogger();
    $lobby = new MockLobbyService();
    $svc = new AdminService($stmts, $logger, $lobby, null, null);

    $worker = new MockWorker();
    $admin = new MockConnection(1, 1, true);
    $targetConn = new MockConnection(10, 15, false);
    $worker->userConnections[15] = $targetConn;
    $worker->rooms[1] = makeRoom('waiting', 10, 15);

    $svc->handleBanUser(['user_id' => 15, 'duration' => '1d'], $admin, $worker);

    assert_true(count($stmts->banUpdates) === 1, 'ban: DB update executed');
    $until = $stmts->banUpdates[0]['until'] ?? 0;
    assert_true(abs($until - ($now + 86400)) <= 5, 'ban: 1d duration mapped');

    $bannedPackets = $targetConn->sentOfType('banned');
    assert_true(count($bannedPackets) === 1, 'ban: online target receives banned packet');
    assert_true(($lobby->removed[0][2] ?? '') === 'banned', 'ban: waiting player removed with banned reason');
}

// ---------------------------------------------------------------------------
// GROUP 3: playing/apartment routing and permanent ban value
// ---------------------------------------------------------------------------
{
    $stmts = new MockStmts([
        16 => ['id' => 16, 'is_admin' => 0],
        17 => ['id' => 17, 'is_admin' => 0],
    ]);
    $rec = new MockReconnectService();
    $apt = new MockApartmentService();
    $svc = new AdminService($stmts, new MockLogger(), null, $rec, $apt);
    $admin = new MockConnection(1, 1, true);
    $worker = new MockWorker();

    $targetPlaying = new MockConnection(20, 16, false);
    $worker->userConnections[16] = $targetPlaying;
    $worker->rooms[2] = makeRoom('playing', 20, 16);
    $svc->handleBanUser(['user_id' => 16, 'duration' => '3d'], $admin, $worker);
    assert_true(($rec->removed[0][2] ?? '') === 'banned', 'ban: playing user removed via game path');

    $targetApartment = new MockConnection(30, 17, false);
    $worker->userConnections[17] = $targetApartment;
    $worker->rooms[3] = makeRoom('apartment', 30, 17);
    $svc->handleBanUser(['user_id' => 17, 'duration' => 'permanent'], $admin, $worker);
    assert_true(($apt->removed[0][2] ?? '') === 'banned', 'ban: apartment user removed via apartment path');

    $lastUpdate = end($stmts->banUpdates);
    assert_true(($lastUpdate['until'] ?? 0) === 4102444800, 'ban: permanent value mapped');
}

// ---------------------------------------------------------------------------
// RESULTS
// ---------------------------------------------------------------------------
$total = $passed + $failed;
echo "\n--- EPIC-9.1 Admin Ban Test Suite ---\n";
echo "{$passed} / {$total} PASSED\n";
if ($failed > 0) {
    echo "{$failed} FAILED\n";
    exit(1);
}
exit(0);
