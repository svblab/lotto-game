<?php

/**
 * ADR-033 / EPIC-A — Admin password rotation
 *
 * Run: php tests/Manual/test_admin_change_password.php
 */

declare(strict_types=1);

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    die("FAIL: vendor/autoload.php not found\n");
}
require_once $autoload;
require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';

use Lotto\Admin\AdminService;
use Lotto\Auth\PasswordPolicy;
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

echo "=== PasswordPolicy unit ===\n";
ok('reject short', PasswordPolicy::validateAdminPassword('Ab1') !== null);
ok('reject no digit', PasswordPolicy::validateAdminPassword('Abcdefghij') !== null);
ok('reject no letter', PasswordPolicy::validateAdminPassword('1234567890') !== null);
ok('accept strong', PasswordPolicy::validateAdminPassword('Secret12ab') === null);
ok('reject control char', PasswordPolicy::validateAdminPassword("Secret12\nab") !== null);

echo "\n=== AdminService::handleChangePassword ===\n";

$dbPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lotto_admin_pw_' . getmypid() . '.sqlite';
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

$oldHash = password_hash('OldSecret12', PASSWORD_DEFAULT);
$pdo->prepare(
    'INSERT INTO users (username, password_hash, coins, is_admin) VALUES (?,?,?,?)'
)->execute(['boss', $oldHash, 500, 1]);
$adminId = (int) $pdo->lastInsertId();

$pdo->prepare(
    'INSERT INTO users (username, password_hash, coins, is_admin) VALUES (?,?,?,?)'
)->execute(['other_admin', password_hash('OtherAdmin99', PASSWORD_DEFAULT), 500, 1]);
$otherId = (int) $pdo->lastInsertId();

$stmts = new PreparedStatements($pdo);
$logPath = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'NUL' : '/dev/null';
$svc = new AdminService($stmts, new Logger($logPath), null, null, null, $db, null);

$conn = new SpyConn();
$conn->userId = $adminId;
$conn->username = 'boss';
$conn->isAdmin = true;

$conn->sent = [];
$svc->handleChangePassword([
    'current_password' => 'WrongPass99',
    'new_password' => 'NewSecret99',
], $conn);
$err = lastOf($conn, 'error');
ok('wrong current rejected', ($err['code'] ?? '') === 'error.admin_wrong_current_password');

$conn->sent = [];
$svc->handleChangePassword([
    'current_password' => 'OldSecret12',
    'new_password' => 'short1',
], $conn);
$err = lastOf($conn, 'error');
ok('weak new rejected', ($err['code'] ?? '') === 'error.admin_password_invalid');
ok('weak reason present', is_string($err['message'] ?? null) && $err['message'] !== '');

$conn->sent = [];
$svc->handleChangePassword([
    'current_password' => 'OldSecret12',
    'new_password' => 'NewSecret99',
], $conn);
$okPkt = lastOf($conn, 'admin_change_password_result');
ok('success packet', ($okPkt['success'] ?? false) === true);

$row = $pdo->query('SELECT password_hash FROM users WHERE id=' . $adminId)->fetch();
ok('old password dead', !password_verify('OldSecret12', $row['password_hash']));
ok('new password works', password_verify('NewSecret99', $row['password_hash']));

$other = $pdo->query('SELECT password_hash FROM users WHERE id=' . $otherId)->fetch();
ok('other admin unaffected', password_verify('OtherAdmin99', $other['password_hash']));

@unlink($dbPath);
summary();
