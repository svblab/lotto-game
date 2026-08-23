<?php

declare(strict_types=1);

/**
 * Verify users_admin_list SQL against SQLite (banned_only flag uses string '0'/'1').
 * Run: php tests/Manual/test_users_admin_list_sql.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Helpers.php';

use function Lotto\Core\lottoBootstrapPhpExtensions;
use Lotto\Infrastructure\PreparedStatements;

lottoBootstrapPhpExtensions();

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void
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

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, coins INTEGER, is_admin INTEGER, banned_until INTEGER)');
$pdo->exec("INSERT INTO users VALUES (1,'alice',500,0,0),(2,'bob',100,0,9999999999),(3,'carol',200,0,0)");

$stmts = new PreparedStatements($pdo);
$stmt = $stmts->get('users_admin_list');

$stmt->execute(['', '', '0', 200]);
ok(count($stmt->fetchAll()) === 3, 'returns all users when banned_only=0');

$stmt->execute(['bo', 'bo', '0', 200]);
$rows = $stmt->fetchAll();
ok(count($rows) === 1 && ($rows[0]['username'] ?? '') === 'bob', 'search filters by username');

$stmt->execute(['', '', '1', 200]);
$rows = $stmt->fetchAll();
ok(count($rows) === 1 && ($rows[0]['username'] ?? '') === 'bob', 'banned_only returns active bans only');

echo PHP_EOL . "=== Results: {$passed} passed, {$failed} failed ===" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
