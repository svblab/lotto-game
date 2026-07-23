<?php

declare(strict_types=1);

/**
 * EPIC-6.5 — Victory system tests
 * Run: php tests/Manual/test_victory.php
 */

// mock_timer.php MUST be loaded before autoload to stub Workerman\Timer
// (unit tests run without Workerman event loop).
require_once __DIR__ . '/mock_timer.php';

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Helpers.php';

use Lotto\Game\GameService;
use Lotto\Game\LottoEngine;
use Lotto\Game\VictoryService;
use Lotto\Game\ApartmentService;
use Lotto\Infrastructure\Database;
use Lotto\Infrastructure\PreparedStatements;

$passed = 0;
$failed = 0;
function ok(string $l): void  { global $passed; $passed++; echo "[PASS] $l\n"; }
function fail(string $l, string $r = ''): void { global $failed; $failed++; echo "[FAIL] $l" . ($r ? " — $r" : '') . "\n"; }
function assert_true(bool $c, string $l, string $r = ''): void { $c ? ok($l) : fail($l, $r); }

// ---------------------------------------------------------------------------
// Mocks
// ---------------------------------------------------------------------------

class MockConnection {
    public int $id; public ?int $userId; public string $username;
    public array $sent = []; public ?string $lastError = null;
    public function __construct(int $id, int $uid, string $name) {
        $this->id = $id; $this->userId = $uid; $this->username = $name;
    }
    public function send(string $d): void {
        $p = json_decode($d, true); $this->sent[] = $p;
        if (($p['type'] ?? '') === 'error') $this->lastError = $p['code'] ?? 'unknown';
    }
    public function sentOfType(string $t): array {
        return array_values(array_filter($this->sent, fn($p) => ($p['type'] ?? '') === $t));
    }
}

class MockWorker { public array $rooms = []; }

class MockPDO {
    public bool $committed = false; public bool $rolledBack = false;
    public bool $shouldFail = false;
    public function beginTransaction(): void {}
    public function commit(): void {
        if ($this->shouldFail) throw new \RuntimeException('DB error');
        $this->committed = true;
    }
    public function rollBack(): void { $this->rolledBack = true; }
}

class MockDatabase {
    public MockPDO $pdo;
    public function __construct(MockPDO $p) { $this->pdo = $p; }
    public function getPdo(): MockPDO { return $this->pdo; }
}

class MockStmts {
    private array $users; public array $updates = [];
    public function __construct(array $u = []) { $this->users = $u; }
    public function get(string $key): object {
        $users = $this->users; $parent = $this;
        if ($key === 'user_by_id') {
            return new class($users) {
                private array $u; private ?int $id = null;
                public function __construct(array $u) { $this->u = $u; }
                public function execute(array $p): void { $this->id = $p[0]; }
                public function fetch(): array|false { return $this->u[$this->id] ?? false; }
            };
        }
        if ($key === 'update_user_coins') {
            return new class($parent) {
                private object $p;
                public function __construct(object $p) { $this->p = $p; }
                public function execute(array $p): void { $this->p->updates[] = ['coins' => $p[0], 'user_id' => $p[1]]; }
                public function fetch(): false { return false; }
            };
        }
        throw new \InvalidArgumentException("Unknown: $key");
    }
}

class MockLogger {
    public array $logs = [];
    public function info(string $m): void    { $this->logs[] = ['INFO',    $m]; }
    public function warning(string $m): void { $this->logs[] = ['WARNING', $m]; }
    public function error(string $m): void   { $this->logs[] = ['ERROR',   $m]; }
}

// ---------------------------------------------------------------------------
// Factories
// ---------------------------------------------------------------------------

function makeConn(int $id, int $uid, string $name): MockConnection {
    return new MockConnection($id, $uid, $name);
}

/** Build a fully-closed card (all 15 cells set) with matching all-true mask */
function makeCompleteCard(): array {
    // Standard card structure, all 15 numbers filled
    $card = array_fill(0, 3, array_fill(0, 9, null));
    // Row 0: cols 0,1,2,3,4
    $card[0][0]=1; $card[0][1]=10; $card[0][2]=20; $card[0][3]=30; $card[0][4]=40;
    // Row 1: cols 5,6,7,8 + col 0
    $card[1][0]=2; $card[1][5]=50; $card[1][6]=60; $card[1][7]=70; $card[1][8]=80;
    // Row 2: cols 1,2,3,4,5
    $card[2][1]=11; $card[2][2]=21; $card[2][3]=31; $card[2][4]=41; $card[2][5]=51;
    return $card;
}

function makeAllTrueMask(array $card): array {
    $mask = array_fill(0, 3, array_fill(0, 9, false));
    for ($r = 0; $r < 3; $r++) {
        for ($c = 0; $c < 9; $c++) {
            if ($card[$r][$c] !== null) $mask[$r][$c] = true;
        }
    }
    return $mask;
}

function makePlayer(MockConnection $conn, int $cardsCount = 1, array $cards = [], array $masks = []): array {
    $eng = new LottoEngine();
    if (empty($cards)) {
        for ($i = 0; $i < $cardsCount; $i++) $cards[] = $eng->generateCard();
    }
    if (empty($masks)) {
        foreach ($cards as $card) $masks[] = array_map(fn($row) => array_fill(0, 9, false), $card);
    }
    return [
        'user_id' => $conn->userId, 'username' => $conn->username,
        'cards' => $cards, 'masks' => $masks, 'cards_count' => $cardsCount,
        'total_paid' => $cardsCount * 10, 'last_action' => time(),
        'afk_start' => null, 'strikes' => 0, 'auto_draws' => 0,
        'status' => 'active', 'session_token' => 'tok', 'reconnect_timer' => null,
        'connection' => $conn, 'immune' => false,
    ];
}

function makeRoom(int $hostId, array $connIds, int $bank = 20): array {
    return [
        'room_id' => 1, 'host_conn_id' => $hostId,
        'bet_per_card' => 10, 'max_players' => 10, 'password_hash' => null,
        'status' => 'playing', 'bank' => $bank,
        'apartment_fired' => false, 'pause_for_apartment' => false, 'apartment_responses' => [],
        'game_afk_timer_id' => null, 'apartment_timer_id' => null, 'lobby_afk_timer_id' => null,
        'active_drawer_conn_id' => $hostId, 'drawer_order' => $connIds,
        'bag' => range(1, 90), 'drawn_numbers' => [],
        'players' => [], 'all_players_history' => [],
    ];
}

function makeSvc(array $users = [], ?MockPDO $pdo = null, ?\PDO $realPdo = null): array {
    $pdo  = $pdo ?? new MockPDO();
    $db   = new MockDatabase($pdo);
    $st   = new MockStmts($users);
    $log  = new MockLogger();
    $eng  = new LottoEngine();
    $vic  = new VictoryService();
    $apt  = new ApartmentService($db, $st, $log);

    // FIX-4: GameFinishService — final class со строгой типизацией
    // зависимостей (ADR-002). Анонимный класс не проходит проверку типа
    // конструктора GameService, поэтому используется РЕАЛЬНЫЙ
    // GameFinishService с реальными Database/PreparedStatements поверх
    // in-memory SQLite ($realPdo, готовится вызывающим кодом — GROUP 4/5),
    // и реальным Core\Logger (побочный эффект — запись в logs/server.log,
    // как и в остальных интеграционных тестах проекта).
    $realDb    = new Database($realPdo);
    $realStmts = new PreparedStatements($realPdo);
    $realLog   = new \Lotto\Core\Logger();
    $fin       = new \Lotto\Game\GameFinishService($realDb, $realStmts, $realLog);

    $svc  = new GameService($db, $st, $eng, $log, $vic, $apt, $fin);
    return [$svc, $log, $st, $pdo, $vic];
}

// ---------------------------------------------------------------------------
// GROUP 1: VictoryService::checkCardVictory
// ---------------------------------------------------------------------------

$vic = new VictoryService();

// No victory — empty masks
{
    $h = makeConn(1, 10, 'host');
    $eng = new LottoEngine();
    $card = $eng->generateCard();
    $mask = array_map(fn($row) => array_fill(0, 9, false), $card);
    $player = makePlayer($h, 1, [$card], [$mask]);
    assert_true($vic->checkCardVictory($player) === 0, 'VictoryService: no win with empty mask');
}

// Normal victory — 1 card complete
{
    $h = makeConn(1, 10, 'host');
    $card = makeCompleteCard();
    $mask = makeAllTrueMask($card);
    $player = makePlayer($h, 1, [$card], [$mask]);
    assert_true($vic->checkCardVictory($player) === 1, 'VictoryService: 1 card win = 1');
}

// Double victory — 2 cards complete
{
    $h = makeConn(1, 10, 'host');
    $card  = makeCompleteCard();
    $mask  = makeAllTrueMask($card);
    $card2 = makeCompleteCard(); $card2[0][0] = 3; // slightly different
    $mask2 = makeAllTrueMask($card2);
    $player = makePlayer($h, 2, [$card, $card2], [$mask, $mask2]);
    assert_true($vic->checkCardVictory($player) === 2, 'VictoryService: 2 cards win = 2 (double)');
}

// Partial — 1 of 2 cards complete
{
    $h = makeConn(1, 10, 'host');
    $card1 = makeCompleteCard();
    $mask1 = makeAllTrueMask($card1);
    $eng = new LottoEngine();
    $card2 = $eng->generateCard();
    $mask2 = array_map(fn($row) => array_fill(0, 9, false), $card2); // empty
    $player = makePlayer($h, 2, [$card1, $card2], [$mask1, $mask2]);
    assert_true($vic->checkCardVictory($player) === 1, 'VictoryService: 1 of 2 cards = 1');
}

// ---------------------------------------------------------------------------
// GROUP 2: VictoryService::checkAllVictories
// ---------------------------------------------------------------------------

{
    $h  = makeConn(1, 10, 'host');
    $p2 = makeConn(2, 20, 'p2');
    $card = makeCompleteCard(); $mask = makeAllTrueMask($card);
    $eng = new LottoEngine();
    $card2 = $eng->generateCard();
    $mask2 = array_map(fn($row) => array_fill(0, 9, false), $card2);

    $room = makeRoom(1, [1, 2]);
    $room['players'][1] = makePlayer($h,  1, [$card],  [$mask]);
    $room['players'][2] = makePlayer($p2, 1, [$card2], [$mask2]);

    $winners = $vic->checkAllVictories($room);
    assert_true(count($winners) === 1,      'checkAllVictories: 1 winner');
    assert_true(isset($winners[1]),          'checkAllVictories: winner is conn 1');
    assert_true($winners[1] === 1,           'checkAllVictories: winner has 1 win');
    assert_true(!isset($winners[2]),         'checkAllVictories: p2 not a winner');
}

// Disconnected player not counted
{
    $h  = makeConn(1, 10, 'host');
    $card = makeCompleteCard(); $mask = makeAllTrueMask($card);
    $room = makeRoom(1, [1]);
    $room['players'][1] = makePlayer($h, 1, [$card], [$mask]);
    $room['players'][1]['status'] = 'disconnected';

    $winners = $vic->checkAllVictories($room);
    assert_true(empty($winners), 'checkAllVictories: disconnected not counted');
}

// ---------------------------------------------------------------------------
// GROUP 3: VictoryService::calculatePrize
// ---------------------------------------------------------------------------

// Normal: 1 winner, bank=20
{
    $result = $vic->calculatePrize(20, [1 => 1]);
    assert_true($result['prizes'][1] === 20, 'calculatePrize: 1 winner gets full bank');
    assert_true($result['burned'] === 0,     'calculatePrize: burned=0');
}

// Double victory: 1 player, 2 shares, bank=20
{
    $result = $vic->calculatePrize(20, [1 => 2]);
    assert_true($result['prizes'][1] === 20, 'calculatePrize: double win gets full bank');
    assert_true($result['burned'] === 0,     'calculatePrize: double burned=0');
}

// Double + normal: bank=100, playerA=2 shares, playerB=1 share → share=33
{
    $result = $vic->calculatePrize(100, [1 => 2, 2 => 1]);
    assert_true($result['prizes'][1] === 66, 'calculatePrize: double+normal playerA=66');
    assert_true($result['prizes'][2] === 33, 'calculatePrize: double+normal playerB=33');
    assert_true($result['burned'] === 1,     'calculatePrize: remainder 1 burned');
}

// Zero winners (edge case)
{
    $result = $vic->calculatePrize(100, []);
    assert_true(empty($result['prizes']), 'calculatePrize: no winners → empty prizes');
    assert_true($result['burned'] === 0,  'calculatePrize: no winners → burned=0');
}

// Indivisible bank
{
    $result = $vic->calculatePrize(10, [1 => 1, 2 => 1]);
    assert_true($result['prizes'][1] === 5, 'calculatePrize: split bank player1=5');
    assert_true($result['prizes'][2] === 5, 'calculatePrize: split bank player2=5');
    assert_true($result['burned'] === 0,    'calculatePrize: no remainder');
}

{
    $result = $vic->calculatePrize(11, [1 => 1, 2 => 1]);
    assert_true($result['prizes'][1] === 5, 'calculatePrize: floor(11/2)=5 each');
    assert_true($result['prizes'][2] === 5, 'calculatePrize: floor(11/2)=5 each (2)');
    assert_true($result['burned'] === 1,    'calculatePrize: remainder 1 burned');
}

// ---------------------------------------------------------------------------
// GROUP 4: GameService::finishGame — normal victory
// ---------------------------------------------------------------------------

{
    $h  = makeConn(1, 10, 'host');
    $p2 = makeConn(2, 20, 'p2');
    $worker = new MockWorker();

    $realPdo = new \PDO('sqlite::memory:');
    $realPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $realPdo->exec("CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        username TEXT NOT NULL,
        password_hash TEXT NOT NULL DEFAULT 'x',
        coins INTEGER NOT NULL DEFAULT 500,
        is_admin INTEGER NOT NULL DEFAULT 0,
        banned_until INTEGER NOT NULL DEFAULT 0,
        last_daily_bonus INTEGER NOT NULL DEFAULT 0
    )");
    $realPdo->exec("INSERT INTO users (id, username, coins) VALUES (10, 'host', 100)");
    $realPdo->exec("INSERT INTO users (id, username, coins) VALUES (20, 'p2', 100)");

    [$svc, $log, $st] = makeSvc([
        10 => ['id' => 10, 'coins' => 100],
        20 => ['id' => 20, 'coins' => 100],
    ], null, $realPdo);

    $room = makeRoom(1, [1, 2], 20);
    $room['players'][1] = makePlayer($h);
    $room['players'][2] = makePlayer($p2);
    $worker->rooms[1] = $room;

    $winners = [1 => 1];
    $prizes  = [1 => 20];
    $svc->finishGame($worker->rooms[1], 1, $winners, $prizes, $worker);

    // Room destroyed
    assert_true(!isset($worker->rooms[1]), 'finishGame: room destroyed');

    // Real DB: prize actually credited (100 -> 120)
    $hostCoins = (int)$realPdo->query("SELECT coins FROM users WHERE id = 10")->fetch()['coins'];
    assert_true($hostCoins === 120, 'finishGame: transaction committed (coins credited 100 -> 120)');

    // Winner got game_over
    $pkts = $h->sentOfType('game_over');
    assert_true(count($pkts) === 1,         'finishGame: host got game_over');
    assert_true($pkts[0]['winner'] === 'host', 'finishGame: winner=host');
    assert_true($pkts[0]['reason'] === 'victory', 'finishGame: reason=victory');
    assert_true($pkts[0]['prize']  === 20,   'finishGame: prize=20');

    // p2 also got game_over
    $p2Pkts = $p2->sentOfType('game_over');
    assert_true(count($p2Pkts) === 1, 'finishGame: p2 got game_over');

    // Statistics present
    assert_true(is_array($pkts[0]['statistics']), 'finishGame: statistics is array');
}

// ---------------------------------------------------------------------------
// GROUP 5: finishGame — DB failure → rollback
// ---------------------------------------------------------------------------
// FIX-4: реальный сбой транзакции через SQL CHECK-ограничение (coins<=200)
// вместо искусственного MockPDO->shouldFail флага — честно проверяет
// настоящий rollback внутри GameFinishService::finishGame() (ANCHOR_CORE.md
// Part 2 § Mandatory Transactions: all-or-nothing).
{
    $h  = makeConn(1, 10, 'host');
    $p2 = makeConn(2, 20, 'p2');
    $worker = new MockWorker();

    $realPdo = new \PDO('sqlite::memory:');
    $realPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $realPdo->exec("CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        username TEXT NOT NULL,
        password_hash TEXT NOT NULL DEFAULT 'x',
        coins INTEGER NOT NULL DEFAULT 500 CHECK(coins <= 200),
        is_admin INTEGER NOT NULL DEFAULT 0,
        banned_until INTEGER NOT NULL DEFAULT 0,
        last_daily_bonus INTEGER NOT NULL DEFAULT 0
    )");
    $realPdo->exec("INSERT INTO users (id, username, coins) VALUES (10, 'host', 100)");
    $realPdo->exec("INSERT INTO users (id, username, coins) VALUES (20, 'p2', 100)");

    [$svc, $log] = makeSvc([
        10 => ['id' => 10, 'coins' => 100],
        20 => ['id' => 20, 'coins' => 100],
    ], null, $realPdo);

    $room = makeRoom(1, [1, 2], 20);
    $room['players'][1] = makePlayer($h);
    $room['players'][2] = makePlayer($p2);
    $worker->rooms[1] = $room;

    // prize=150 → 100+150=250 нарушает CHECK(coins<=200) → PDOException → rollback
    $svc->finishGame($worker->rooms[1], 1, [1 => 1], [1 => 150], $worker);

    $hostCoins = (int)$realPdo->query("SELECT coins FROM users WHERE id = 10")->fetch()['coins'];
    assert_true($hostCoins === 100, 'finishGame: DB fail → rollback (coins unchanged at 100)');
    assert_true($realPdo->inTransaction() === false, 'finishGame: DB fail → no dangling transaction');
    // No game_over sent
    assert_true(count($h->sentOfType('game_over')) === 0, 'finishGame: DB fail → no game_over');
    // Room NOT destroyed — finishGame() returns early on transaction failure
    assert_true(isset($worker->rooms[1]), 'finishGame: DB fail → room not destroyed');
}

// ---------------------------------------------------------------------------
// GROUP 6: Full integration — draw until victory
// ---------------------------------------------------------------------------

{
    $h  = makeConn(1, 10, 'host');
    $p2 = makeConn(2, 20, 'p2');
    $worker = new MockWorker();
    $pdo    = new MockPDO();

    $realPdo = new \PDO('sqlite::memory:');
    $realPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $realPdo->exec("CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        username TEXT NOT NULL,
        password_hash TEXT NOT NULL DEFAULT 'x',
        coins INTEGER NOT NULL DEFAULT 500,
        is_admin INTEGER NOT NULL DEFAULT 0,
        banned_until INTEGER NOT NULL DEFAULT 0,
        last_daily_bonus INTEGER NOT NULL DEFAULT 0
    )");
    $realPdo->exec("INSERT INTO users (id, username, coins) VALUES (10, 'host', 490)");
    $realPdo->exec("INSERT INTO users (id, username, coins) VALUES (20, 'p2', 490)");

    // Give host a complete card so we can trigger victory immediately
    $card = makeCompleteCard();
    // All numbers on this card: 1,2,10,11,20,21,30,31,40,41,50,51,60,70,80
    $numbers = [1,2,10,11,20,21,30,31,40,41,50,51,60,70,80];

    $eng = new LottoEngine(); $p2card = $eng->generateCard();

    [$svc, $log] = makeSvc([
        10 => ['id' => 10, 'coins' => 490],
        20 => ['id' => 20, 'coins' => 490],
    ], $pdo, $realPdo);

    $room = makeRoom(1, [1, 2], 20);
    $room['players'][1] = makePlayer($h,  1, [$card],   [array_map(fn($r) => array_fill(0, 9, false), $card)]);
    $room['players'][2] = makePlayer($p2, 1, [$p2card], [array_map(fn($r) => array_fill(0, 9, false), $p2card)]);

    // Victory integration should not be interrupted by Apartment phase.
    // Force apartment_fired=true so ApartmentService::shouldTrigger() always returns false.
    $room['apartment_fired'] = true;

    // Put the 15 winning numbers at the front of the bag
    $remainingBag = array_diff(range(1, 90), $numbers);
    $room['bag'] = array_values(array_merge($numbers, $remainingBag));

    $worker->rooms[1] = $room;

    // Draw all 15 numbers — host wins on the 15th
    // We alternate turns but only host draws here for simplicity
    // Override active_drawer to host every time until game ends
    $drawnCount = 0;
    for ($i = 0; $i < 15; $i++) {
        if (!isset($worker->rooms[1])) break; // game ended
        $worker->rooms[1]['active_drawer_conn_id'] = 1; // force host's turn
        $svc->handleDrawBarrel($h, $worker);
        $drawnCount++;
    }

    // After 15 draws, game should be over (host won)
    assert_true(!isset($worker->rooms[1]),         'Integration: room destroyed after victory');
    $goPackets = $h->sentOfType('game_over');
    assert_true(count($goPackets) === 1,            'Integration: game_over sent');
    assert_true($goPackets[0]['winner'] === 'host', 'Integration: host is winner');

    // Real DB: bank (20) credited to host (490 -> 510)
    $hostCoinsAfter = (int)$realPdo->query("SELECT coins FROM users WHERE id = 10")->fetch()['coins'];
    assert_true($hostCoinsAfter === 510, 'Integration: payout committed (coins 490 -> 510)');
}

// ---------------------------------------------------------------------------
// RESULTS
// ---------------------------------------------------------------------------

$total = $passed + $failed;
echo "\n--- EPIC-6.5 Victory System Test Suite ---\n";
echo "$passed / $total PASSED\n";
if ($failed > 0) { echo "$failed FAILED\n"; exit(1); }
exit(0);
