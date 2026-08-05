<?php

declare(strict_types=1);

/**
 * tests/Manual/test_admin_stats.php
 *
 * EPIC-23.0 — Admin stats snapshot. Unit test AdminService::handleGetStats().
 *
 * Границы теста (ANCHOR_RULES Part 22 § Test Philosophy):
 *   - Проверяет КОНТРАКТ AdminService::handleGetStats(): guard
 *     (auth_required/not_your_turn), пакет admin_stats_data, online count,
 *     memory_mb, rooms[] через buildRoomListEntry().
 *   - room_list / renderAdminRooms() flow НЕ затрагивается этим Epic.
 *
 * Запуск: php tests/Manual/test_admin_stats.php
 */

require __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Helpers.php';

use Lotto\Admin\AdminService;
use Lotto\Core\MemoryAudit;
use Lotto\Core\RoomManager;

// =============================================================================
// Test harness
// =============================================================================

$passed = 0;
$failed = 0;

function assertTrue(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  [PASS] {$label}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$label}\n";
    }
}

function assertEquals(mixed $expected, mixed $actual, string $label): void
{
    assertTrue(
        $expected === $actual,
        "{$label} (expected: " . var_export($expected, true) . ", got: " . var_export($actual, true) . ")"
    );
}

// =============================================================================
// Test doubles
// =============================================================================

final class SpyConnection
{
    public mixed $userId = null;
    public mixed $isAdmin = false;
    public array $sent = [];

    public function send(string $json): void
    {
        $this->sent[] = json_decode($json, true);
    }

    public function lastSent(): ?array
    {
        return $this->sent[array_key_last($this->sent)] ?? null;
    }
}

function makeAdminConnection(int $userId): SpyConnection
{
    $c = new SpyConnection();
    $c->userId = $userId;
    $c->isAdmin = true;
    return $c;
}

function makeRegularConnection(int $userId): SpyConnection
{
    $c = new SpyConnection();
    $c->userId = $userId;
    $c->isAdmin = false;
    return $c;
}

function makeRoomFixture(int $roomId, string $status, int $playerCount, bool $hasPassword): array
{
    $players = [];
    for ($i = 0; $i < $playerCount; $i++) {
        $players[100 + $i] = ['user_id' => $i + 1, 'status' => 'active'];
    }

    return [
        'room_id'       => $roomId,
        'host_conn_id'  => 100,
        'bet_per_card'  => 10,
        'max_players'   => 10,
        'password_hash' => $hasPassword ? '$2y$10$hash' : null,
        'status'        => $status,
        'bank'          => 0,
        'players'       => $players,
    ];
}

function makeWorkerWithConnections(int $connectionCount, array $rooms): object
{
    $worker = new stdClass();
    $worker->rooms = $rooms;
    $worker->userConnections = [];
    for ($i = 1; $i <= $connectionCount; $i++) {
        $worker->userConnections[$i] = new stdClass();
    }
    return $worker;
}

// =============================================================================
// TEST 1 — Unauthenticated → error.auth_required
// =============================================================================

echo "TEST 1: Unauthenticated access\n";

$logger = new \Lotto\Core\Logger(sys_get_temp_dir() . '/lotto_test_admin_stats_' . getmypid() . '.log');
register_shutdown_function(function () {
    @unlink(sys_get_temp_dir() . '/lotto_test_admin_stats_' . getmypid() . '.log');
});
$roomManager = new RoomManager($logger);
$admin1 = new AdminService(null, null, null, null, null, null, $roomManager);
$conn1 = new SpyConnection();
$worker1 = makeWorkerWithConnections(0, []);

$admin1->handleGetStats(['action' => 'admin_get_stats'], $conn1, $worker1);

assertEquals('error', $conn1->lastSent()['type'] ?? null, 'response type is error');
assertEquals('error.auth_required', $conn1->lastSent()['code'] ?? null, 'code is error.auth_required');

// =============================================================================
// TEST 2 — Non-admin → error.not_your_turn
// =============================================================================

echo "\nTEST 2: Non-admin access\n";

$admin2 = new AdminService(null, null, null, null, null, null, $roomManager);
$conn2 = makeRegularConnection(42);
$worker2 = makeWorkerWithConnections(1, []);

$admin2->handleGetStats(['action' => 'admin_get_stats'], $conn2, $worker2);

assertEquals('error', $conn2->lastSent()['type'] ?? null, 'response type is error');
assertEquals('error.not_your_turn', $conn2->lastSent()['code'] ?? null, 'code is error.not_your_turn');

// =============================================================================
// TEST 3 — Admin success: online, memory_mb, rooms via buildRoomListEntry()
// =============================================================================

echo "\nTEST 3: Admin success path\n";

$rooms = [
    1 => makeRoomFixture(1, 'waiting', 2, false),
    2 => makeRoomFixture(2, 'playing', 3, true),
];
$worker3 = makeWorkerWithConnections(4, $rooms);

$expectedRooms = [
    $roomManager->buildRoomListEntry($rooms[1]),
    $roomManager->buildRoomListEntry($rooms[2]),
];
$expectedOnline = MemoryAudit::collect($worker3)['user_connections'];
$expectedMemoryMb = (int) round(MemoryAudit::collect($worker3)['mem_bytes'] / (1024 * 1024));

$admin3 = new AdminService(null, null, null, null, null, null, $roomManager);
$conn3 = makeAdminConnection(1);

$admin3->handleGetStats(['action' => 'admin_get_stats'], $conn3, $worker3);

$resp3 = $conn3->lastSent();
assertEquals('admin_stats_data', $resp3['type'] ?? null, 'response type is admin_stats_data');
assertEquals($expectedOnline, $resp3['online'] ?? null, 'online matches user_connections count');
assertTrue(is_int($resp3['memory_mb'] ?? null) && ($resp3['memory_mb'] ?? -1) >= 0, 'memory_mb is non-negative integer');
assertEquals($expectedMemoryMb, $resp3['memory_mb'] ?? null, 'memory_mb matches MemoryAudit snapshot');
assertEquals($expectedRooms, $resp3['rooms'] ?? null, 'rooms match buildRoomListEntry() for each open room');

// =============================================================================
// TEST 4 — RoomManager not configured → error
// =============================================================================

echo "\nTEST 4: RoomManager not configured\n";

$admin4 = new AdminService(null, null, null, null, null, null, null);
$conn4 = makeAdminConnection(1);
$worker4 = makeWorkerWithConnections(0, []);

$admin4->handleGetStats(['action' => 'admin_get_stats'], $conn4, $worker4);

assertEquals('error', $conn4->lastSent()['type'] ?? null, 'response type is error when RoomManager missing');

// =============================================================================
// Summary
// =============================================================================

echo "\n" . str_repeat('=', 60) . "\n";
echo "RESULT: {$passed} passed, {$failed} failed\n";
echo str_repeat('=', 60) . "\n";

exit($failed > 0 ? 1 : 0);
