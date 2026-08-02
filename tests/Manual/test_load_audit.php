<?php

declare(strict_types=1);

/**
 * tests/Manual/test_load_audit.php
 *
 * EPIC-11.6 — Load audit regression tests.
 *
 * Verifies:
 *   - LoadAudit opt-in gating, log formatting, latency stats parsing
 *   - Percentile math for p95 acceptance
 *   - analyze_load_log.php client/resource log parsing (mock files)
 *
 * Run: php tests/Manual/test_load_audit.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Lotto\Core\LoadAudit;
use Lotto\Core\Logger;

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

$tmpDir = sys_get_temp_dir() . '/lotto_load_audit_' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0755, true);
$auditLog = $tmpDir . '/load_audit.log';
$clientLog = $tmpDir . '/load_client.log';
$resourceLog = $tmpDir . '/load_resource.log';

// =============================================================================
// GROUP 1 — LoadAudit utility
// =============================================================================

echo "GROUP 1: LoadAudit utility\n";

putenv('LOTTO_LOAD_AUDIT=0');
assertTrue(!LoadAudit::isEnabled(), 'disabled when env unset');

putenv('LOTTO_LOAD_AUDIT=1');
assertTrue(LoadAudit::isEnabled(), 'enabled when LOTTO_LOAD_AUDIT=1');

$logger = new FakeLogger();
$audit = new LoadAudit($logger, $auditLog);

$worker = new stdClass();
$worker->rooms = ['1' => [], '2' => []];
$worker->connections = array_fill(0, 5, new stdClass());
$worker->userConnections = ['1' => new stdClass()];
$worker->sessionTokens = ['t1' => 'abc'];

$audit->recordLatency('register', 12.5, $worker);
$audit->snapshot('test_snapshot', $worker);

putenv('LOTTO_LOAD_AUDIT=0');
$audit->recordLatency('register', 99.0, $worker);
$content = file_get_contents($auditLog);
assertTrue($content !== false && str_contains($content, '[LOAD]'), 'log contains [LOAD] prefix');
assertTrue(str_contains($content, 'event=latency'), 'latency event logged');
assertTrue(str_contains($content, 'action=register'), 'action field present');
assertTrue(str_contains($content, 'latency_ms=12.50'), 'latency formatted');
assertTrue(str_contains($content, 'connections=5'), 'worker connections in log');
assertTrue(str_contains($content, 'event=snapshot'), 'snapshot event logged');

$stats = LoadAudit::parseLatencyStats($auditLog);
assertTrue(isset($stats['register']), 'parseLatencyStats finds register');
assertTrue($stats['register']['count'] === 1, 'one register sample');
assertTrue($stats['register']['p95'] === 12.5, 'p95 equals single sample');

$snapStats = LoadAudit::parseSnapshotStats($auditLog);
assertTrue($snapStats['snapshots'] === 1, 'one snapshot parsed');
assertTrue($snapStats['peak_connections'] === 5, 'peak connections from snapshot');

// =============================================================================
// GROUP 2 — Percentile math
// =============================================================================

echo "GROUP 2: Percentile math\n";

$values = [10.0, 20.0, 30.0, 40.0, 50.0, 60.0, 70.0, 80.0, 90.0, 100.0];
$summary = LoadAudit::summarizeLatencies($values);
assertTrue($summary['count'] === 10, 'summarize count');
assertTrue($summary['p50'] === 50.0, 'p50 at median');
assertTrue($summary['p95'] === 100.0, 'p95 at top for 10 samples');
assertTrue($summary['max'] === 100.0, 'max value');

assertTrue(LoadAudit::percentile([1.0, 2.0, 3.0], 50) === 2.0, 'percentile middle');

// =============================================================================
// GROUP 3 — Client / resource log parsing (inline mirror of analyze_load_log.php)
// =============================================================================

echo "GROUP 3: Client and resource log parsing\n";

file_put_contents($clientLog, implode("\n", [
    '[2026-07-27 12:00:00] action=register rtt_ms=15.00',
    '[2026-07-27 12:00:01] action=register rtt_ms=25.00',
    '[2026-07-27 12:00:02] action=draw_barrel rtt_ms=8.00',
    '[2026-07-27 12:00:03] action=draw_barrel rtt_ms=12.00',
]) . "\n");

file_put_contents($resourceLog, implode("\n", [
    '[2026-07-27 12:00:00] cpu_pct=45.0 mem_mb=120.50 clients=50 rooms=5',
    '[2026-07-27 12:00:30] cpu_pct=72.5 mem_mb=200.00 clients=100 rooms=10',
]) . "\n");

$clientLines = file($clientLog, FILE_IGNORE_NEW_LINES);
$byAction = [];
foreach ($clientLines as $line) {
    if (preg_match('/action=(\w+)\s+rtt_ms=([\d.]+)/', $line, $m)) {
        $byAction[$m[1]] ??= [];
        $byAction[$m[1]][] = (float) $m[2];
    }
}
$clientStats = [];
foreach ($byAction as $action => $vals) {
    $clientStats[$action] = LoadAudit::summarizeLatencies($vals);
}

assertTrue(isset($clientStats['register']), 'client register parsed');
assertTrue($clientStats['register']['count'] === 2, 'two register RTT samples');
assertTrue($clientStats['draw_barrel']['p95'] <= 12.0, 'draw_barrel p95 within samples');

$peakCpu = 0.0;
$peakMem = 0.0;
foreach (file($resourceLog, FILE_IGNORE_NEW_LINES) as $line) {
    if (preg_match('/cpu_pct=([\d.]+)\s+mem_mb=([\d.]+)/', $line, $m)) {
        $peakCpu = max($peakCpu, (float) $m[1]);
        $peakMem = max($peakMem, (float) $m[2]);
    }
}
assertTrue($peakCpu === 72.5, 'resource peak CPU parsed');
assertTrue($peakMem === 200.0, 'resource peak memory parsed');

// =============================================================================
// GROUP 4 — TRACKED_ACTIONS coverage
// =============================================================================

echo "GROUP 4: TRACKED_ACTIONS\n";

$expected = ['register', 'login', 'room_list', 'create_room', 'join_room', 'start_game', 'draw_barrel'];
foreach ($expected as $action) {
    assertTrue(in_array($action, LoadAudit::TRACKED_ACTIONS, true), "TRACKED_ACTIONS includes {$action}");
}

// =============================================================================
// Cleanup
// =============================================================================

@unlink($auditLog);
@unlink($clientLog);
@unlink($resourceLog);
@rmdir($tmpDir);

echo "\n";
echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
