<?php

/**
 * ADR-033 / EPIC-C — Admin delete + bulk delete
 *
 * Run: php tests/Manual/test_admin_delete_user.php
 */

declare(strict_types=1);

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    die("FAIL: vendor/autoload.php not found\n");
}
require_once $autoload;
require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';

use Lotto\Admin\AdminService;
use Lotto\Core\Logger;
use Lotto\Infrastructure\Database;
use Lotto\Infrastructure\PreparedStatements;

$passed = 0;
$failed = 0;

function ok(string $name, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        echo "[PASS] {$name}\n";
        $passed++;
    } else {
        echo "[FAIL] {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
        $failed++;
    }
}

function summary(): void
{
    global $passed, $failed;
    $total = $passed + $failed;
    echo "\nResults: {$passed}/{$total} passed" . ($failed ? ", {$failed} FAILED" : '') . "\n";
    exit($failed > 0 ? 1 : 0);
}

class SpyConn
{
    public array $sent = [];
    public int $id = 1;
    public ?int $userId = null;
    public ?string $username = null;
    public bool $isAdmin = false;

    public function send(string $data): void
    {
        $this->sent[] = json_decode($data, true);
    }
}

function lastOf(SpyConn $c, string $type): ?array
{
    for ($i = count($c->sent) - 1; $i >= 0; $i--) {
        if (($c->sent[$i]['type'] ?? null) === $type) {
            return $c->sent[$i];
        }
    }
    return null;
}

function userExists(PDO $pdo, int $id): bool
{
    $row = $pdo->query('SELECT id FROM users WHERE id=' . $id)->fetch();
    return $row !== false;
}

echo "=== AdminService delete / bulk delete ===\n";

$dbPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lotto_admin_del_' . getmypid() . '.sqlite';
@unlink($dbPath);
putenv('LOTTO_DB_PATH=' . $dbPath);
$_ENV['LOTTO_DB_PATH'] = $dbPath;

$db = new Database();
$pdo = $db->getPdo();
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        coins INTEGER NOT NULL DEFAULT 500,
        is_admin INTEGER NOT NULL DEFAULT 0,
        banned_until INTEGER NOT NULL DEFAULT 0,
        last_daily_bonus INTEGER NOT NULL DEFAULT 0
    )'
);

$hash = password_hash('Secret12ab', PASSWORD_DEFAULT);
$ins = $pdo->prepare(
    'INSERT INTO users (username, password_hash, coins, is_admin) VALUES (?,?,?,?)'
);
$ins->execute(['boss', $hash, 500, 1]);
$adminId = (int) $pdo->lastInsertId();
$ins->execute(['junk1', $hash, 100, 0]);
$junk1 = (int) $pdo->lastInsertId();
$ins->execute(['junk2', $hash, 100, 0]);
$junk2 = (int) $pdo->lastInsertId();
$ins->execute(['other_admin', $hash, 500, 1]);
$otherAdmin = (int) $pdo->lastInsertId();
$ins->execute(['online_user', $hash, 100, 0]);
$onlineId = (int) $pdo->lastInsertId();
$ins->execute(['history_user', $hash, 100, 0]);
$histId = (int) $pdo->lastInsertId();
$ins->execute(['roster_user', $hash, 100, 0]);
$rosterId = (int) $pdo->lastInsertId();
$ins->execute(['batch_a', $hash, 50, 0]);
$batchA = (int) $pdo->lastInsertId();
$ins->execute(['batch_b', $hash, 50, 0]);
$batchB = (int) $pdo->lastInsertId();
$ins->execute(['batch_busy', $hash, 50, 0]);
$batchBusy = (int) $pdo->lastInsertId();

$stmts = new PreparedStatements($pdo);
$logPath = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'NUL' : '/dev/null';
$svc = new AdminService($stmts, new Logger($logPath), null, null, null, $db, null);

$admin = new SpyConn();
$admin->userId = $adminId;
$admin->username = 'boss';
$admin->isAdmin = true;

$worker = new stdClass();
$worker->userConnections = [];
$worker->rooms = [];

$admin->sent = [];
$svc->handleDeleteUser(['user_id' => 99999], $admin, $worker);
$err = lastOf($admin, 'error');
ok('unknown id', ($err['code'] ?? '') === 'error.admin_user_not_found');

$admin->sent = [];
$svc->handleDeleteUser(['user_id' => $otherAdmin], $admin, $worker);
$err = lastOf($admin, 'error');
ok('admin target blocked', ($err['code'] ?? '') === 'error.cannot_moderate_admin');
ok('admin row kept', userExists($pdo, $otherAdmin));

$onlineConn = new SpyConn();
$onlineConn->userId = $onlineId;
$worker->userConnections[$onlineId] = $onlineConn;
$admin->sent = [];
$svc->handleDeleteUser(['user_id' => $onlineId], $admin, $worker);
$err = lastOf($admin, 'error');
ok('online blocked', ($err['code'] ?? '') === 'error.admin_user_busy');
ok('online row kept', userExists($pdo, $onlineId));
unset($worker->userConnections[$onlineId]);

$worker->rooms[7] = [
    'players' => [],
    'all_players_history' => [
        11 => ['user_id' => $histId, 'username' => 'history_user', 'total_paid' => 0],
    ],
    'game_roster' => [],
];
$admin->sent = [];
$svc->handleDeleteUser(['user_id' => $histId], $admin, $worker);
$err = lastOf($admin, 'error');
ok('history blocked', ($err['code'] ?? '') === 'error.admin_user_busy');
ok('history row kept', userExists($pdo, $histId));
$worker->rooms = [];

$worker->rooms[8] = [
    'players' => [],
    'all_players_history' => [],
    'game_roster' => [
        22 => ['user_id' => $rosterId, 'username' => 'roster_user'],
    ],
];
$admin->sent = [];
$svc->handleDeleteUser(['user_id' => $rosterId], $admin, $worker);
$err = lastOf($admin, 'error');
ok('roster blocked', ($err['code'] ?? '') === 'error.admin_user_busy');
ok('roster row kept', userExists($pdo, $rosterId));
$worker->rooms = [];

$admin->sent = [];
$svc->handleDeleteUser(['user_id' => $junk1], $admin, $worker);
ok('single delete no error', lastOf($admin, 'error') === null);
ok('single delete removed', !userExists($pdo, $junk1));
ok('sibling kept', userExists($pdo, $junk2));

$worker->userConnections[$batchBusy] = new SpyConn();
$admin->sent = [];
$svc->handleBulkDeleteUsers(['user_ids' => [$batchA, $batchB, $batchBusy]], $admin, $worker);
$err = lastOf($admin, 'error');
ok('bulk aborts on busy', ($err['code'] ?? '') === 'error.admin_user_busy');
ok('bulk a kept', userExists($pdo, $batchA));
ok('bulk b kept', userExists($pdo, $batchB));
ok('bulk busy kept', userExists($pdo, $batchBusy));
unset($worker->userConnections[$batchBusy]);

$admin->sent = [];
$svc->handleBulkDeleteUsers(['user_ids' => [$batchA, $batchB, $junk2]], $admin, $worker);
ok('bulk success no error', lastOf($admin, 'error') === null);
ok('bulk a gone', !userExists($pdo, $batchA));
ok('bulk b gone', !userExists($pdo, $batchB));
ok('bulk junk2 gone', !userExists($pdo, $junk2));
ok('admin still present', userExists($pdo, $adminId));

@unlink($dbPath);
summary();
