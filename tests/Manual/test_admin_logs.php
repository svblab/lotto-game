<?php

declare(strict_types=1);

/**
 * tests/Manual/test_admin_logs.php
 *
 * EPIC-9.6 (закрывает пробел верификации EPIC-9.5) — Logs access.
 * Юнит-тест AdminService::handleGetLogs() + Logger::getLastLines().
 *
 * Границы теста (ANCHOR_RULES Part 22 § Test Philosophy):
 *   - tests/Manual/test_logger.php (EPIC-9.5) проверяет только Logger как
 *     изолированный класс (Core), без протокольного контракта.
 *   - Этот файл проверяет КОНТРАКТ AdminService::handleGetLogs(): guard
 *     (auth_required/not_your_turn), пакет admin_logs_data, поведение при
 *     отсутствующем логгере, срез последних N строк.
 *
 * Запуск: php tests/Manual/test_admin_logs.php
 */

require __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Helpers.php';

use Lotto\Admin\AdminService;

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

final class FakeLoggerWithLines
{
    public array $infoCalls = [];
    private array $lines;

    public function __construct(array $lines)
    {
        $this->lines = $lines;
    }

    public function getLastLines(int $limit = 100): array
    {
        return array_slice($this->lines, -$limit);
    }

    public function info(string $msg): void
    {
        $this->infoCalls[] = $msg;
    }

    public function warning(string $msg): void {}
    public function error(string $msg): void {}
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

// =============================================================================
// TEST 1 — Unauthenticated → error.auth_required, no logger call
// =============================================================================

echo "TEST 1: Unauthenticated access\n";

$logger1 = new FakeLoggerWithLines(['[l1]', '[l2]']);
$admin1 = new AdminService(null, $logger1, null, null, null, null, null);
$conn1 = new SpyConnection(); // userId остаётся null

$admin1->handleGetLogs(['action' => 'admin_get_logs'], $conn1);

assertEquals('error', $conn1->lastSent()['type'] ?? null, 'response type is error');
assertEquals('error.auth_required', $conn1->lastSent()['code'] ?? null, 'code is error.auth_required');
assertEquals(0, count($logger1->infoCalls), 'logger not invoked for unauthenticated request');

// =============================================================================
// TEST 2 — Non-admin → error.not_your_turn
// =============================================================================

echo "\nTEST 2: Non-admin access\n";

$logger2 = new FakeLoggerWithLines(['[l1]']);
$admin2 = new AdminService(null, $logger2, null, null, null, null, null);
$conn2 = makeRegularConnection(42);

$admin2->handleGetLogs(['action' => 'admin_get_logs'], $conn2);

assertEquals('error', $conn2->lastSent()['type'] ?? null, 'response type is error');
assertEquals('error.not_your_turn', $conn2->lastSent()['code'] ?? null, 'code is error.not_your_turn');

// =============================================================================
// TEST 3 — Admin, logger configured → admin_logs_data with lines
// =============================================================================

echo "\nTEST 3: Admin success path\n";

$lines3 = ['[2026-07-03 10:00:00] [INFO] a', '[2026-07-03 10:00:01] [WARNING] b', '[2026-07-03 10:00:02] [ERROR] c'];
$logger3 = new FakeLoggerWithLines($lines3);
$admin3 = new AdminService(null, $logger3, null, null, null, null, null);
$conn3 = makeAdminConnection(1);

$admin3->handleGetLogs(['action' => 'admin_get_logs'], $conn3);

$resp3 = $conn3->lastSent();
assertEquals('admin_logs_data', $resp3['type'] ?? null, 'response type is admin_logs_data');
assertTrue(isset($resp3['lines']) && is_array($resp3['lines']), 'lines field is array');
assertEquals($lines3, $resp3['lines'] ?? null, 'lines match logger output');
assertEquals(1, count($logger3->infoCalls), 'admin request logged exactly once');

// =============================================================================
// TEST 4 — Admin, logger NOT configured → error, no crash
// =============================================================================

echo "\nTEST 4: Logger not configured\n";

$admin4 = new AdminService(null, null, null, null, null, null, null);
$conn4 = makeAdminConnection(1);

$admin4->handleGetLogs(['action' => 'admin_get_logs'], $conn4);

assertEquals('error', $conn4->lastSent()['type'] ?? null, 'response type is error when logger missing');

// =============================================================================
// TEST 5 — limit=100 slicing: only last N lines returned (ANCHOR_CORE contract)
// =============================================================================

echo "\nTEST 5: Limit=100 slicing via Logger::getLastLines()\n";

$manyLines = array_map(fn(int $i) => "line-{$i}", range(1, 150));
$logger5 = new FakeLoggerWithLines($manyLines);
$admin5 = new AdminService(null, $logger5, null, null, null, null, null);
$conn5 = makeAdminConnection(1);

$admin5->handleGetLogs(['action' => 'admin_get_logs'], $conn5);

$resp5 = $conn5->lastSent();
assertEquals(100, count($resp5['lines'] ?? []), 'exactly 100 lines returned out of 150');
assertEquals('line-51', $resp5['lines'][0] ?? null, 'oldest returned line is the 51st (last 100)');
assertEquals('line-150', $resp5['lines'][99] ?? null, 'newest returned line is the last one');

// =============================================================================
// TEST 6 — Logger::getLastLines() real implementation: missing file → []
// =============================================================================

echo "\nTEST 6: Real Logger::getLastLines() against a missing/empty file\n";

$realLogger = new \Lotto\Core\Logger();
// Свежий Logger создаёт logs/server.log при первой записи; до записи
// getLastLines() не должен падать, только вернуть то, что реально есть.
$before = $realLogger->getLastLines(5);
assertTrue(is_array($before), 'getLastLines() always returns an array, never throws');

$realLogger->info('test_admin_logs marker line');
$after = $realLogger->getLastLines(1);
assertEquals(1, count($after), 'getLastLines(1) returns exactly one line after a write');
assertTrue(str_contains($after[0], 'test_admin_logs marker line'), 'returned line contains the written message');

// =============================================================================
// Summary
// =============================================================================

echo "\n" . str_repeat('=', 60) . "\n";
echo "RESULT: {$passed} passed, {$failed} failed\n";
echo str_repeat('=', 60) . "\n";

exit($failed > 0 ? 1 : 0);
