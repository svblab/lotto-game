<?php

declare(strict_types=1);

/**
 * tests/Manual/test_memory_audit.php
 *
 * EPIC-11.1 — Memory audit regression tests.
 *
 * Verifies:
 *   - MemoryAudit snapshot formatting and opt-in gating
 *   - $worker->rooms / $worker->userConnections cleanup after lifecycle events
 *   - Repeated room create/destroy cycles do not grow runtime maps
 *   - Memory usage stays bounded over simulated load (mock-based, no live WS)
 *
 * Run: php tests/Manual/test_memory_audit.php
 */

require_once __DIR__ . '/mock_timer.php';

require __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Helpers.php';

use Lotto\Core\Constants;
use Lotto\Core\Logger;
use Lotto\Core\MemoryAudit;
use Lotto\Core\RoomManager;

// Ensure audit env from a previous run does not leak into lifecycle tests.
putenv('LOTTO_MEMORY_AUDIT=0');
putenv('LOTTO_MEMORY_AUDIT_VERBOSE=0');
putenv('LOTTO_MEMORY_AUDIT_LOG');

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

final class FakeLogger extends Logger
{
    public function __construct() {}
    public function info(string $m): void {}
    public function warning(string $m): void {}
    public function error(string $m): void {}
}

function makeWorker(?string $auditLogPath = null): object
{
    $worker = new stdClass();
    $worker->rooms = [];
    $worker->userConnections = [];
    $worker->sessionTokens = [];
    $worker->connections = [];
    $worker->logger = new FakeLogger();
    $worker->memoryAudit = new MemoryAudit($worker->logger, $auditLogPath);
    return $worker;
}

// =============================================================================
// GROUP 1 — MemoryAudit utility
// =============================================================================

echo "GROUP 1: MemoryAudit utility\n";

assertTrue(
    MemoryAudit::formatMb(1048576) === '1.00',
    'formatMb converts 1 MiB correctly'
);

putenv('LOTTO_MEMORY_AUDIT=0');
assertTrue(!MemoryAudit::isEnabled(), 'audit disabled when env unset/0');

putenv('LOTTO_MEMORY_AUDIT=1');
assertTrue(MemoryAudit::isEnabled(), 'audit enabled when LOTTO_MEMORY_AUDIT=1');
assertTrue(MemoryAudit::shouldLogAction('create_room'), 'tracked action logged when enabled');
assertTrue(!MemoryAudit::shouldLogAction('ping'), 'ping not logged in default mode');

putenv('LOTTO_MEMORY_AUDIT_VERBOSE=1');
assertTrue(MemoryAudit::shouldLogAction('ping'), 'ping logged in verbose mode');
putenv('LOTTO_MEMORY_AUDIT_VERBOSE=0');
putenv('LOTTO_MEMORY_AUDIT=0'); // do not leak — GROUP 2–4 would sync-write logs/memory_audit.log

$worker = makeWorker();
$worker->rooms[1] = ['players' => [100 => []]];
$worker->userConnections[10] = new stdClass();
$stats = MemoryAudit::collect($worker);
assertTrue($stats['rooms'] === 1, 'collect() reports room count');
assertTrue($stats['user_connections'] === 1, 'collect() reports user_connections count');

// =============================================================================
// GROUP 2 — Room lifecycle map cleanup
// =============================================================================

echo "\nGROUP 2: Room lifecycle map cleanup\n";

\MockTimer::reset();
$roomManager = new RoomManager(new FakeLogger());
$worker2 = makeWorker();

$created = [];
for ($i = 0; $i < Constants::MAX_ROOMS; $i++) {
    $hostConnId = 1000 + $i;
    $roomId = $roomManager->createRoom($worker2, $hostConnId, 4, null);
    $created[] = $roomId;
}

assertTrue(count($worker2->rooms) === Constants::MAX_ROOMS, 'MAX_ROOMS rooms created');

foreach ($created as $roomId) {
    $roomManager->destroyRoom($worker2, $roomId);
}

assertTrue(count($worker2->rooms) === 0, 'all rooms destroyed — rooms map empty');
assertTrue(count(\MockTimer::$active) === 0, 'no orphaned timers after full room teardown');

// =============================================================================
// GROUP 3 — userConnections cleanup (FIX-10 contract)
// =============================================================================

echo "\nGROUP 3: userConnections cleanup\n";

$worker3 = makeWorker();
$worker3->userConnections[42] = new stdClass();
$worker3->userConnections[99] = new stdClass();

unset($worker3->userConnections[42]);
assertTrue(!isset($worker3->userConnections[42]), 'userConnections slot removed on unset');
assertTrue(isset($worker3->userConnections[99]), 'other userConnections entries unaffected');

// =============================================================================
// GROUP 4 — Repeated create/destroy cycles stay bounded
// =============================================================================

echo "\nGROUP 4: Repeated create/destroy cycles stay bounded\n";

\MockTimer::reset();
$worker4 = makeWorker();
$roomManager4 = new RoomManager(new FakeLogger());

gc_collect_cycles();
$baselineMem = memory_get_usage(true);

for ($cycle = 0; $cycle < 20; $cycle++) {
    $hostConnId = 2000 + $cycle;
    $roomId = $roomManager4->createRoom($worker4, $hostConnId, 6, null);

    $worker4->rooms[$roomId]['players'][$hostConnId] = [
        'user_id' => 500 + $cycle,
        'username' => "player{$cycle}",
        'cards' => array_fill(0, 3, array_fill(0, 15, 0)),
        'cards_count' => 1,
        'total_paid' => 10,
        'last_action' => time(),
        'afk_start' => null,
        'strikes' => 0,
        'auto_draws' => 0,
        'status' => 'active',
        'session_token' => 'tok',
        'reconnect_timer' => null,
        'connection' => new stdClass(),
        'immune' => false,
    ];

    $roomManager4->destroyRoom($worker4, $roomId);
}

gc_collect_cycles();
$afterMem = memory_get_usage(true);
$growthRatio = $baselineMem > 0 ? ($afterMem / $baselineMem) : 1.0;

assertTrue(count($worker4->rooms) === 0, 'rooms map empty after 20 cycles');
assertTrue(
    $growthRatio <= 1.5,
    'memory growth <= 50% after 20 create/destroy cycles (ratio=' . number_format($growthRatio, 2) . ')'
);

// =============================================================================
// GROUP 5 — Snapshot writes to log when audit enabled
// =============================================================================

echo "\nGROUP 5: Snapshot log file\n";

$productionAuditLog = dirname(__DIR__, 2) . '/logs/memory_audit.log';
$prodStatBefore = is_file($productionAuditLog)
    ? [filesize($productionAuditLog), filemtime($productionAuditLog)]
    : null;

$auditLog = sys_get_temp_dir() . '/lotto_test_memory_audit_' . getmypid() . '.log';
if (is_file($auditLog)) {
    @unlink($auditLog);
}

putenv('LOTTO_MEMORY_AUDIT=1');
putenv('LOTTO_MEMORY_AUDIT_LOG=');
$worker5 = makeWorker($auditLog);
$worker5->memoryAudit->snapshot('test_event', $worker5, ['marker' => 'epic_11_1']);

$logContents = is_file($auditLog) ? file_get_contents($auditLog) : '';
assertTrue(
    is_string($logContents) && str_contains($logContents, 'event=test_event'),
    'snapshot written to isolated audit log when enabled'
);
assertTrue(
    is_string($logContents) && str_contains($logContents, 'marker=epic_11_1'),
    'snapshot includes custom context fields'
);

$prodStatAfter = is_file($productionAuditLog)
    ? [filesize($productionAuditLog), filemtime($productionAuditLog)]
    : null;
assertTrue(
    $prodStatBefore === $prodStatAfter,
    'production logs/memory_audit.log unchanged by test (FIX-12 parity)'
);

putenv('LOTTO_MEMORY_AUDIT=0');
@unlink($auditLog);

// =============================================================================
// Summary
// =============================================================================

echo "\n" . str_repeat('=', 60) . "\n";
echo "EPIC-11.1 memory audit: {$passed} passed, {$failed} failed\n";
echo str_repeat('=', 60) . "\n";

exit($failed > 0 ? 1 : 0);
