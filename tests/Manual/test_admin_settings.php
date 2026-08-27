<?php

declare(strict_types=1);

/**
 * Admin runtime settings + Windows restart guard.
 *
 * Run: php tests/Manual/test_admin_settings.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Helpers.php';

use Lotto\Admin\AdminService;
use Lotto\Admin\AdminSettingsService;
use Lotto\Core\LottoWorker;

$passed = 0;
$failed = 0;

function ok(string $label): void
{
    global $passed;
    $passed++;
    echo "[PASS] {$label}\n";
}

function fail(string $label, string $reason = ''): void
{
    global $failed;
    $failed++;
    echo "[FAIL] {$label}" . ($reason !== '' ? " — {$reason}" : '') . "\n";
}

function assert_true(bool $cond, string $label, string $reason = ''): void
{
    $cond ? ok($label) : fail($label, $reason);
}

final class SettingsMockConnection
{
    public int $id = 1;
    public ?int $userId = 1;
    public bool $isAdmin = true;
    public array $sent = [];

    public function send(string $data): void
    {
        $this->sent[] = json_decode($data, true);
    }
}

$worker = new stdClass();
$worker->rooms = [];
$worker->userConnections = [];
$worker->connections = [];
$svc = new AdminSettingsService(new AdminService(), null);
$conn = new SettingsMockConnection();

$svc->handleGetSettings([], $conn, $worker);
$pkt = $conn->sent[0] ?? [];
assert_true(($pkt['type'] ?? '') === 'admin_settings_data', 'get_settings returns admin_settings_data');
assert_true(array_key_exists('restart_supported', $pkt), 'admin_settings_data includes restart_supported');

$windows = DIRECTORY_SEPARATOR === '\\';
assert_true(
    AdminSettingsService::isHostRestartSupported() === !$windows,
    'isHostRestartSupported matches OS (' . ($windows ? 'Windows' : 'non-Windows') . ')'
);
assert_true(
    ($pkt['restart_supported'] ?? null) === AdminSettingsService::isHostRestartSupported(),
    'packet restart_supported matches helper'
);

if ($windows) {
    $conn->sent = [];
    $svc->handleRestartServer([], $conn, $worker);
    $restart = $conn->sent[0] ?? [];
    assert_true(($restart['type'] ?? '') === 'admin_restart_result', 'Windows restart returns admin_restart_result');
    assert_true(($restart['success'] ?? true) === false, 'Windows restart success=false');
    assert_true(
        is_string($restart['message'] ?? null) && str_contains((string) $restart['message'], 'Windows'),
        'Windows restart message is explicit (not silent @exec)'
    );
}

assert_true(property_exists(LottoWorker::class, 'rooms'), 'LottoWorker declares rooms');
assert_true(property_exists(LottoWorker::class, 'serverSettings'), 'LottoWorker declares serverSettings');
assert_true(property_exists(LottoWorker::class, 'botWinStreaks'), 'LottoWorker declares botWinStreaks');
assert_true(property_exists(LottoWorker::class, 'userConnections'), 'LottoWorker declares userConnections');

echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
