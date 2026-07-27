#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * scripts/economy_integrity_runner.php
 *
 * EPIC-11.3 — Multi-scenario economy simulation with balance conservation check.
 *
 * Runs stake → prize/burn → apartment → refund against in-memory SQLite and
 * verifies at each step:
 *   initial_total = sum(user coins) + active room banks + cumulative burned
 *
 * Usage:
 *   php scripts/economy_integrity_runner.php [--log=/path/to/economy_audit.log]
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/Core/Helpers.php';

if (DIRECTORY_SEPARATOR === '\\') {
    $extDir = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'ext';
    if (is_dir($extDir)) {
        ini_set('extension_dir', $extDir);
        if (!extension_loaded('sqlite3')) {
            @dl('php_sqlite3.dll');
        }
        if (!extension_loaded('pdo_sqlite')) {
            @dl('php_pdo_sqlite.dll');
        }
    }
}

use Lotto\Core\EconomyAudit;
use Lotto\Core\Logger;
use Lotto\Game\GameFinishService;
use Lotto\Game\VictoryService;
use Lotto\Infrastructure\Database;
use Lotto\Infrastructure\PreparedStatements;

use function Lotto\Core\lottoEconomyRecord;

final class RunnerLogger extends Logger
{
    public function info(string $m): void {}
    public function warning(string $m): void {}
    public function error(string $m): void {}
}

$projectRoot = dirname(__DIR__);
$logPath = $projectRoot . '/logs/economy_audit_runner.log';

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--log=')) {
        $logPath = substr($arg, strlen('--log='));
    }
}

@mkdir(dirname($logPath), 0755, true);
@unlink($logPath);

putenv('LOTTO_ECONOMY_AUDIT=1');
putenv('LOTTO_ECONOMY_AUDIT_LOG=' . $logPath);
$GLOBALS['__lotto_economy_audit'] = new EconomyAudit(new RunnerLogger(), $logPath);

function makeDb(): array
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        username TEXT NOT NULL,
        password_hash TEXT NOT NULL DEFAULT 'x',
        coins INTEGER NOT NULL DEFAULT 500,
        is_admin INTEGER NOT NULL DEFAULT 0,
        banned_until INTEGER NOT NULL DEFAULT 0,
        last_daily_bonus INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec("INSERT INTO users (id, username, coins) VALUES
        (1, 'alice', 500),
        (2, 'bob', 500),
        (3, 'carol', 500)");

    return [$pdo, new Database($pdo), new PreparedStatements($pdo)];
}

function sumCoins(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COALESCE(SUM(coins), 0) FROM users')->fetchColumn();
}

function getBalances(PDO $pdo): array
{
    $balances = [];
    $stmt = $pdo->query('SELECT id, coins FROM users');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $balances[(int) $row['id']] = (int) $row['coins'];
    }
    return $balances;
}

function checkConservation(
    int $initialTotal,
    int $userCoins,
    int $roomBank,
    int $cumulativeBurned,
    string $label,
    array &$failures
): void {
    $actual = $userCoins + $roomBank + $cumulativeBurned;
    if ($actual !== $initialTotal) {
        $failures[] = "{$label}: total={$actual} expected={$initialTotal} (coins={$userCoins} bank={$roomBank} burned={$cumulativeBurned})";
    }
}

[$pdo, $db, $stmts] = makeDb();
$initialTotal = sumCoins($pdo);
$initialBalances = getBalances($pdo);
$cumulativeBurned = 0;
$roomBank = 0;
$scenarios = 0;
$failures = [];

echo "EPIC-11.3 economy integrity runner\n";
echo "Initial total coins: {$initialTotal}\n";

// Scenario 1: stakes (two players buy into room 1)
$scenarios++;
$upd = $stmts->get('update_user_coins');
$upd->execute([480, 1]);
$upd->execute([461, 2]);
lottoEconomyRecord('stake', 1, -20, ['room_id' => 1]);
lottoEconomyRecord('stake', 2, -39, ['room_id' => 1]);
$roomBank = 59;

checkConservation($initialTotal, sumCoins($pdo), $roomBank, $cumulativeBurned, "Scenario {$scenarios} (stakes)", $failures);

// Scenario 2: prize + burn via GameFinishService (bank=59, split 29+29, burn 1)
$scenarios++;
$vic = new VictoryService();
$result = $vic->calculatePrize($roomBank, [10 => 1, 20 => 1]);
$cumulativeBurned += $result['burned'];

$finish = new GameFinishService($db, $stmts, new RunnerLogger());
$room = [
    'status' => 'playing',
    'bank'   => $roomBank,
    'players' => [
        10 => ['user_id' => 1, 'username' => 'alice', 'status' => 'active', 'connection' => new class {
            public function send(string $d): void {}
        }],
        20 => ['user_id' => 2, 'username' => 'bob', 'status' => 'active', 'connection' => new class {
            public function send(string $d): void {}
        }],
    ],
    'all_players_history' => [
        10 => ['user_id' => 1, 'username' => 'alice', 'total_paid' => 20, 'conn_id' => 10],
        20 => ['user_id' => 2, 'username' => 'bob', 'total_paid' => 39, 'conn_id' => 20],
    ],
];
$finish->finishGame($room, 1, [10 => 1, 20 => 1], $result['prizes'], 'victory', function (): void {});
$roomBank = 0;

checkConservation($initialTotal, sumCoins($pdo), $roomBank, $cumulativeBurned, "Scenario {$scenarios} (prize+burn)", $failures);

// Scenario 3: apartment payment (new room)
$scenarios++;
$current = (int) $pdo->query('SELECT coins FROM users WHERE id=1')->fetchColumn();
$upd->execute([$current - 5, 1]);
lottoEconomyRecord('apartment', 1, -5, ['room_id' => 2]);
$roomBank = 5;

checkConservation($initialTotal, sumCoins($pdo), $roomBank, $cumulativeBurned, "Scenario {$scenarios} (apartment)", $failures);

// Scenario 4: admin refund from room bank
$scenarios++;
$add = $stmts->get('add_user_coins');
$add->execute([5, 3]);
lottoEconomyRecord('refund', 3, 5, ['room_id' => 2, 'reason' => 'admin_close']);
$roomBank = 0;

checkConservation($initialTotal, sumCoins($pdo), $roomBank, $cumulativeBurned, "Scenario {$scenarios} (refund)", $failures);

$events = EconomyAudit::parseLog($logPath);
$replay = EconomyAudit::replay($events, $initialBalances);
if (!EconomyAudit::verifyConservation(
    $initialBalances,
    $replay['balances'],
    $replay['burned'],
    $replay['room_banks']
)) {
    $failures[] = 'Log replay conservation violated';
}

$stats = EconomyAudit::parseLogStats($logPath);
echo sprintf(
    "Scenarios run: %d | Log events: %d | DB coins: %d | Burned: %d | Room bank: %d\n",
    $scenarios,
    $stats['events'],
    sumCoins($pdo),
    $cumulativeBurned,
    $roomBank
);

putenv('LOTTO_ECONOMY_AUDIT=0');
unset($GLOBALS['__lotto_economy_audit']);

if (count($failures) === 0) {
    echo "PASS: all economy integrity scenarios conserved coins.\n";
    echo "Analyze log: php scripts/analyze_economy_log.php {$logPath} --initial=1:500,2:500,3:500\n";
    exit(0);
}

echo "FAIL:\n";
foreach ($failures as $f) {
    echo "  - {$f}\n";
}
exit(1);
