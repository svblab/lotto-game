<?php

declare(strict_types=1);

/**
 * tests/Manual/test_economy_audit.php
 *
 * EPIC-11.3 — Economy audit mock regression tests (Windows + Linux).
 *
 * Covers:
 *   - EconomyAudit utility + log parsing / replay
 *   - Conservation invariant across stake/prize/burn/apartment/refund scenarios
 *   - VictoryService prize math preserves total coins (minus burn)
 *   - GameFinishService logs prize + burn after successful transaction
 *
 * Run: php tests/Manual/test_economy_audit.php
 */

require_once __DIR__ . '/mock_timer.php';

if (DIRECTORY_SEPARATOR === '\\') {
    $extDir = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'ext';
    if (is_dir($extDir)) {
        ini_set('extension_dir', $extDir);
        if (!extension_loaded('sqlite3')) {
            dl('php_sqlite3.dll');
        }
        if (!extension_loaded('pdo_sqlite')) {
            dl('php_pdo_sqlite.dll');
        }
    }
}

require __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Helpers.php';

use Lotto\Core\EconomyAudit;
use Lotto\Core\Logger;
use Lotto\Game\GameFinishService;
use Lotto\Game\VictoryService;
use Lotto\Infrastructure\Database;
use Lotto\Infrastructure\PreparedStatements;

use function Lotto\Core\lottoEconomyRecord;
use function Lotto\Core\lottoEconomyCheckInvariants;

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

$auditLogPath = sys_get_temp_dir() . '/lotto_test_economy_audit_' . getmypid() . '.log';
@unlink($auditLogPath);

// =============================================================================
// GROUP 1 — EconomyAudit utility
// =============================================================================

echo "GROUP 1: EconomyAudit utility\n";

putenv('LOTTO_ECONOMY_AUDIT=0');
assertTrue(!EconomyAudit::isEnabled(), 'audit disabled when env unset/0');

putenv('LOTTO_ECONOMY_AUDIT=1');
assertTrue(EconomyAudit::isEnabled(), 'audit enabled when LOTTO_ECONOMY_AUDIT=1');

putenv('LOTTO_ECONOMY_AUDIT=0');
$auditDisabled = new EconomyAudit(new FakeLogger(), $auditLogPath);
$auditDisabled->record('stake', 10, -20, ['room_id' => 1]);
assertTrue(!is_file($auditLogPath) || filesize($auditLogPath) === 0, 'record no-op when audit disabled');

putenv('LOTTO_ECONOMY_AUDIT=1');
$GLOBALS['__lotto_economy_audit'] = new EconomyAudit(new FakeLogger(), $auditLogPath);

$audit = $GLOBALS['__lotto_economy_audit'];
$audit->record('stake', 10, -20, ['room_id' => 1]);
$audit->record('stake', 20, -20, ['room_id' => 1]);
$audit->record('prize', 10, 40, ['room_id' => 1, 'reason' => 'victory']);

$events = EconomyAudit::parseLog($auditLogPath);
assertTrue(count($events) === 3, 'parseLog finds 3 events');
assertTrue($events[0]['op'] === 'stake' && $events[0]['amount'] === -20, 'stake event parsed');
assertTrue($events[2]['op'] === 'prize' && $events[2]['user_id'] === 10, 'prize event parsed');

$stats = EconomyAudit::parseLogStats($auditLogPath);
assertTrue($stats['events'] === 3, 'parseLogStats counts events');
assertTrue($stats['ops']['stake'] === 2 && $stats['ops']['prize'] === 1, 'parseLogStats counts ops');

putenv('LOTTO_ECONOMY_AUDIT=0');

// =============================================================================
// GROUP 2 — Log replay + conservation
// =============================================================================

echo "GROUP 2: Log replay + conservation\n";

$initial = [10 => 500, 20 => 500];
$replay = EconomyAudit::replay($events, $initial);

assertTrue($replay['balances'][10] === 520, 'replay: user 10 after stake+prize');
assertTrue($replay['balances'][20] === 480, 'replay: user 20 after stake only');
assertTrue($replay['burned'] === 0, 'replay: no burn in scenario');
assertTrue(($replay['room_banks'][1] ?? 0) === 0, 'replay: room bank zeroed after payout');

assertTrue(
    EconomyAudit::verifyConservation($initial, $replay['balances'], $replay['burned'], $replay['room_banks']),
    'conservation holds after replay'
);

// Full scenario: two players stake, split prize with burn
$scenarioEvents = [
    ['tx_id' => 'a', 'op' => 'stake', 'user_id' => 1, 'amount' => -20, 'room_id' => 5, 'game_id' => null, 'ts_us' => null, 'line' => ''],
    ['tx_id' => 'b', 'op' => 'stake', 'user_id' => 2, 'amount' => -20, 'room_id' => 5, 'game_id' => null, 'ts_us' => null, 'line' => ''],
    ['tx_id' => 'c', 'op' => 'prize', 'user_id' => 1, 'amount' => 20, 'room_id' => 5, 'game_id' => null, 'ts_us' => null, 'line' => ''],
    ['tx_id' => 'd', 'op' => 'prize', 'user_id' => 2, 'amount' => 19, 'room_id' => 5, 'game_id' => null, 'ts_us' => null, 'line' => ''],
    ['tx_id' => 'e', 'op' => 'burn', 'user_id' => 0, 'amount' => 1, 'room_id' => 5, 'game_id' => null, 'ts_us' => null, 'line' => ''],
];
$start = [1 => 100, 2 => 100];
$end = EconomyAudit::replay($scenarioEvents, $start);
assertTrue(
    EconomyAudit::verifyConservation($start, $end['balances'], $end['burned'], $end['room_banks']),
    'conservation: split prize with burn'
);
assertTrue($end['burned'] === 1, 'conservation scenario burned=1');
assertTrue($end['balances'][1] === 100 && $end['balances'][2] === 99, 'conservation: correct final balances');

// Apartment + refund scenario
$aptEvents = [
    ['tx_id' => 'f', 'op' => 'stake', 'user_id' => 1, 'amount' => -20, 'room_id' => 7, 'game_id' => null, 'ts_us' => null, 'line' => ''],
    ['tx_id' => 'g', 'op' => 'stake', 'user_id' => 2, 'amount' => -20, 'room_id' => 7, 'game_id' => null, 'ts_us' => null, 'line' => ''],
    ['tx_id' => 'h', 'op' => 'apartment', 'user_id' => 1, 'amount' => -5, 'room_id' => 7, 'game_id' => null, 'ts_us' => null, 'line' => ''],
    ['tx_id' => 'i', 'op' => 'refund', 'user_id' => 2, 'amount' => 20, 'room_id' => 7, 'game_id' => null, 'ts_us' => null, 'line' => ''],
];
$aptStart = [1 => 200, 2 => 200];
$aptEnd = EconomyAudit::replay($aptEvents, $aptStart);
assertTrue(
    EconomyAudit::verifyConservation($aptStart, $aptEnd['balances'], $aptEnd['burned'], $aptEnd['room_banks']),
    'conservation: apartment + refund'
);

// =============================================================================
// GROUP 3 — VictoryService integrity (pure math)
// =============================================================================

echo "GROUP 3: VictoryService prize integrity\n";

$vic = new VictoryService();

function simulatePayout(VictoryService $vic, int $bank, array $winners, array $initialBalances): array
{
    $result = $vic->calculatePrize($bank, $winners);
    $balances = $initialBalances;
    $roomBank = $bank;

    foreach ($result['prizes'] as $connId => $prize) {
        $balances[$connId] = ($balances[$connId] ?? 0) + $prize;
        $roomBank -= $prize;
    }
    $burned = $result['burned'];
    $roomBank = 0;

    return [
        'balances'  => $balances,
        'burned'    => $burned,
        'room_bank' => $roomBank,
        'conserved' => array_sum($initialBalances) + $bank
            === array_sum($balances) + $burned + $roomBank,
    ];
}

// Single winner
{
    $initial = [1 => 480, 2 => 480];
    $bank = 40;
    $out = simulatePayout($vic, $bank, [1 => 1], $initial);
    assertTrue($out['conserved'], 'VictoryService: single winner conserves coins');
    assertTrue($out['balances'][1] === 520, 'VictoryService: single winner gets full bank');
}

// Multiple winners with burn
{
    $initial = [1 => 480, 2 => 480, 3 => 480];
    $bank = 100;
    $out = simulatePayout($vic, $bank, [1 => 2, 2 => 1], $initial);
    assertTrue($out['conserved'], 'VictoryService: double+normal conserves coins');
    assertTrue($out['burned'] === 1, 'VictoryService: remainder burned');
}

// Tie split
{
    $initial = [1 => 490, 2 => 490];
    $bank = 20;
    $out = simulatePayout($vic, $bank, [1 => 1, 2 => 1], $initial);
    assertTrue($out['balances'][1] === 500 && $out['balances'][2] === 500, 'VictoryService: tie split');
    assertTrue($out['burned'] === 0, 'VictoryService: even split no burn');
}

// =============================================================================
// GROUP 4 — GameFinishService logs prize + burn (integration)
// =============================================================================

echo "GROUP 4: GameFinishService audit logging\n";

@unlink($auditLogPath);
putenv('LOTTO_ECONOMY_AUDIT=1');
$GLOBALS['__lotto_economy_audit'] = new EconomyAudit(new FakeLogger(), $auditLogPath);

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
$pdo->exec("INSERT INTO users (id, username, coins) VALUES (10, 'winner', 480), (20, 'loser', 480)");

$stmts = new PreparedStatements($pdo);
$finish = new GameFinishService(
    new Database($pdo),
    $stmts,
    new FakeLogger()
);

$room = [
    'status' => 'playing',
    'bank'   => 41,
    'players' => [
        1 => ['user_id' => 10, 'username' => 'winner', 'status' => 'active', 'connection' => new class {
            public function send(string $d): void {}
        }],
        2 => ['user_id' => 20, 'username' => 'loser', 'status' => 'active', 'connection' => new class {
            public function send(string $d): void {}
        }],
    ],
    'all_players_history' => [
        1 => ['user_id' => 10, 'username' => 'winner', 'total_paid' => 20, 'conn_id' => 1],
        2 => ['user_id' => 20, 'username' => 'loser', 'total_paid' => 21, 'conn_id' => 2],
    ],
];

$finish->finishGame($room, 99, [1 => 1, 2 => 1], [1 => 20, 2 => 20], 'victory', function (): void {});

$stmt = $pdo->query('SELECT id, coins FROM users ORDER BY id');
$dbBalances = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $dbBalances[(int) $row['id']] = (int) $row['coins'];
}

assertTrue($dbBalances[10] === 500, 'GameFinishService: winner coins credited');
assertTrue($dbBalances[20] === 500, 'GameFinishService: loser coins credited');

$finishEvents = EconomyAudit::parseLog($auditLogPath);
$prizeOps = array_filter($finishEvents, fn($e) => $e['op'] === 'prize');
$burnOps = array_filter($finishEvents, fn($e) => $e['op'] === 'burn');
assertTrue(count($prizeOps) === 2, 'GameFinishService: 2 prize log entries');
assertTrue(count($burnOps) === 1, 'GameFinishService: burn logged for remainder');
$burnAmount = array_values($burnOps)[0]['amount'];
assertTrue($burnAmount === 1, 'GameFinishService: burn amount = 1');

$initialBalances = [10 => 480, 20 => 480];
$replayed = EconomyAudit::replay($finishEvents, $initialBalances);
assertTrue($replayed['balances'][10] === 500 && $replayed['balances'][20] === 500, 'replay matches DB balances');
assertTrue($replayed['burned'] === 1, 'replay tracks burn from finishGame');

putenv('LOTTO_ECONOMY_AUDIT=0');
unset($GLOBALS['__lotto_economy_audit']);
@unlink($auditLogPath);

// =============================================================================
// GROUP 5 — lottoEconomyRecord helper
// =============================================================================

echo "GROUP 5: lottoEconomyRecord helper\n";

$helperLog = sys_get_temp_dir() . '/lotto_test_economy_helper_' . getmypid() . '.log';
@unlink($helperLog);

putenv('LOTTO_ECONOMY_AUDIT=1');
$GLOBALS['__lotto_economy_audit'] = new EconomyAudit(new FakeLogger(), $helperLog);

lottoEconomyRecord('refund', 42, 15, ['room_id' => 3, 'reason' => 'admin_kick']);

$helperEvents = EconomyAudit::parseLog($helperLog);
assertTrue(count($helperEvents) === 1, 'lottoEconomyRecord writes one event');
assertTrue($helperEvents[0]['op'] === 'refund' && $helperEvents[0]['amount'] === 15, 'lottoEconomyRecord correct op/amount');

putenv('LOTTO_ECONOMY_AUDIT=0');
unset($GLOBALS['__lotto_economy_audit']);
@unlink($helperLog);

// =============================================================================
// GROUP 6 — EPIC-028.3 structural invariant checks
// =============================================================================

echo "GROUP 6: checkWorkerInvariants (duplicate seat / dual auth)\n";

$dupConnA = new class {
    public int $id = 1;
    public int $userId = 10;
    public bool $closed = false;
};
$dupConnB = new class {
    public int $id = 2;
    public int $userId = 10;
    public bool $closed = false;
};

$dupWorker = (object) [
    'connections' => [$dupConnA, $dupConnB],
    'rooms' => [
        7 => [
            'status' => 'waiting',
            'bank' => 0,
            'players' => [
                1 => ['user_id' => 5, 'total_paid' => 0],
                2 => ['user_id' => 5, 'total_paid' => 0],
            ],
            'all_players_history' => [],
        ],
    ],
];

class InvariantCaptureLogger extends Logger
{
    public array $errors = [];
    public function __construct() {}
    public function info(string $m): void {}
    public function warning(string $m): void {}
    public function error(string $m): void { $this->errors[] = $m; }
    public function write(string $level, string $message): void
    {
        if ($level === 'ERROR') {
            $this->errors[] = $message;
        }
    }
}

$invLogger = new InvariantCaptureLogger();
$invAudit = new EconomyAudit($invLogger, sys_get_temp_dir() . '/lotto_inv_' . getmypid() . '.log');
$dupWorker->economyAudit = $invAudit;
$GLOBALS['__lotto_economy_audit'] = $invAudit;

lottoEconomyCheckInvariants($dupWorker, 'test_duplicate');

assertTrue(
    count(array_filter($invLogger->errors, static fn($e) => str_contains($e, 'duplicate user_id=5'))) === 1,
    'duplicate seat in same room logs ERROR'
);
assertTrue(
    count(array_filter($invLogger->errors, static fn($e) => str_contains($e, 'dual live auth user_id=10'))) === 1,
    'dual live auth logs ERROR'
);

unset($GLOBALS['__lotto_economy_audit']);

// =============================================================================
// Summary
// =============================================================================

echo "\nEPIC-11.3 economy audit: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
