<?php

declare(strict_types=1);

/**
 * EPIC-5.5 — Turn system tests
 * Run: php tests/Manual/test_turn_system.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Helpers.php';

use Lotto\Game\GameService;
use Lotto\Game\LottoEngine;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

$passed = 0;
$failed = 0;

function ok(string $label): void  { global $passed; $passed++; echo "[PASS] $label\n"; }
function fail(string $label, string $r = ''): void { global $failed; $failed++; echo "[FAIL] $label" . ($r ? " — $r" : '') . "\n"; }
function assert_true(bool $c, string $l, string $r = ''): void { $c ? ok($l) : fail($l, $r); }

// ---------------------------------------------------------------------------
// Mocks (same pattern as test_game_start.php)
// ---------------------------------------------------------------------------

class MockConnection {
    public int $id;
    public ?int $userId;
    public string $username;
    public array $sent = [];
    public ?string $lastError = null;

    public function __construct(int $id, int $userId, string $username) {
        $this->id = $id; $this->userId = $userId; $this->username = $username;
    }
    public function send(string $data): void {
        $d = json_decode($data, true);
        $this->sent[] = $d;
        if (($d['type'] ?? '') === 'error') $this->lastError = $d['code'] ?? 'unknown';
    }
    public function lastSent(): ?array { return end($this->sent) ?: null; }
    public function sentOfType(string $type): array {
        return array_values(array_filter($this->sent, fn($p) => ($p['type'] ?? '') === $type));
    }
}

class MockWorker { public array $rooms = []; }

class MockPDO {
    public bool $committed = false;
    public function beginTransaction(): void {}
    public function commit(): void { $this->committed = true; }
    public function rollBack(): void {}
}

class MockDatabase {
    public MockPDO $pdo;
    public function __construct(MockPDO $p) { $this->pdo = $p; }
    public function getPdo(): MockPDO { return $this->pdo; }
}

class MockStmts {
    private array $users;
    public array $updates = [];
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
                public function execute(array $p): void { $this->p->updates[] = $p; }
                public function fetch(): false { return false; }
            };
        }
        throw new \InvalidArgumentException("Unknown: $key");
    }
}

class MockLogger {
    public array $logs = [];
    public function info(string $m): void    { $this->logs[] = $m; }
    public function warning(string $m): void { $this->logs[] = $m; }
    public function error(string $m): void   { $this->logs[] = $m; }
}

// ---------------------------------------------------------------------------
// Factory
// ---------------------------------------------------------------------------

function makeConn(int $id, int $uid, string $name): MockConnection {
    return new MockConnection($id, $uid, $name);
}

function makePlayer(MockConnection $conn, int $cardsCount = 1, array $cards = [], array $masks = []): array {
    $engine = new LottoEngine();
    if (empty($cards)) {
        for ($i = 0; $i < $cardsCount; $i++) $cards[] = $engine->generateCard();
    }
    if (empty($masks)) {
        foreach ($cards as $card) {
            $masks[] = array_map(fn($row) => array_fill(0, 9, false), $card);
        }
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

function makeRoom(int $hostConnId, array $allConnIds): array {
    return [
        'room_id' => 1, 'host_conn_id' => $hostConnId,
        'bet_per_card' => 10, 'max_players' => 10, 'password_hash' => null,
        'status' => 'playing', 'bank' => 20, 'apartment_fired' => false,
        'pause_for_apartment' => false, 'apartment_responses' => [],
        'game_afk_timer_id' => null, 'apartment_timer_id' => null, 'lobby_afk_timer_id' => null,
        'active_drawer_conn_id' => $hostConnId,
        'drawer_order' => $allConnIds,
        'bag' => range(1, 90), 'drawn_numbers' => [],
        'players' => [], 'all_players_history' => [],
    ];
}

function makeSvc(): array {
    $db  = new MockDatabase(new MockPDO());
    $st  = new MockStmts();
    $log = new MockLogger();
    $eng = new LottoEngine();
    $svc = new GameService($db, $st, $eng, $log);
    return [$svc, $log];
}

// ---------------------------------------------------------------------------
// GROUP 1: sendYourTurn
// ---------------------------------------------------------------------------

{
    [$svc] = makeSvc();
    $host  = makeConn(1, 10, 'host');
    $room  = makeRoom(1, [1]);
    $room['players'][1] = makePlayer($host);
    $svc->sendYourTurn($room);

    $pkt = $host->lastSent();
    assert_true($pkt !== null && $pkt['type'] === 'your_turn', 'sendYourTurn: your_turn sent');
    assert_true($room['players'][1]['afk_start'] !== null, 'sendYourTurn: afk_start set');
}

{
    // disconnected drawer — no packet sent
    [$svc] = makeSvc();
    $host = makeConn(1, 10, 'host');
    $room = makeRoom(1, [1]);
    $room['players'][1] = makePlayer($host);
    $room['players'][1]['status'] = 'disconnected';
    $svc->sendYourTurn($room);
    assert_true(count($host->sent) === 0, 'sendYourTurn: disconnected drawer skipped');
}

// ---------------------------------------------------------------------------
// GROUP 2: nextDrawer
// ---------------------------------------------------------------------------

{
    [$svc] = makeSvc();
    $h = makeConn(1, 10, 'host'); $p2 = makeConn(2, 20, 'p2'); $p3 = makeConn(3, 30, 'p3');
    $room = makeRoom(1, [1, 2, 3]);
    $room['players'][1] = makePlayer($h);
    $room['players'][2] = makePlayer($p2);
    $room['players'][3] = makePlayer($p3);
    $room['active_drawer_conn_id'] = 1;

    $svc->nextDrawer($room);
    assert_true($room['active_drawer_conn_id'] === 2, 'nextDrawer: 1→2');

    $svc->nextDrawer($room);
    assert_true($room['active_drawer_conn_id'] === 3, 'nextDrawer: 2→3');

    $svc->nextDrawer($room);
    assert_true($room['active_drawer_conn_id'] === 1, 'nextDrawer: 3→1 (cyclic)');
}

{
    // Skip disconnected
    [$svc] = makeSvc();
    $h = makeConn(1, 10, 'host'); $p2 = makeConn(2, 20, 'p2'); $p3 = makeConn(3, 30, 'p3');
    $room = makeRoom(1, [1, 2, 3]);
    $room['players'][1] = makePlayer($h);
    $room['players'][2] = makePlayer($p2);
    $room['players'][3] = makePlayer($p3);
    $room['players'][2]['status'] = 'disconnected';
    $room['active_drawer_conn_id'] = 1;

    $svc->nextDrawer($room);
    assert_true($room['active_drawer_conn_id'] === 3, 'nextDrawer: skip disconnected');
}

{
    // Skip removed (not in players)
    [$svc] = makeSvc();
    $h = makeConn(1, 10, 'host'); $p3 = makeConn(3, 30, 'p3');
    $room = makeRoom(1, [1, 2, 3]);
    $room['players'][1] = makePlayer($h);
    $room['players'][3] = makePlayer($p3);
    $room['active_drawer_conn_id'] = 1;

    $svc->nextDrawer($room);
    assert_true($room['active_drawer_conn_id'] === 3, 'nextDrawer: skip removed player');
}

{
    // No active players → null
    [$svc] = makeSvc();
    $h = makeConn(1, 10, 'host');
    $room = makeRoom(1, [1]);
    $room['players'][1] = makePlayer($h);
    $room['players'][1]['status'] = 'disconnected';
    $room['active_drawer_conn_id'] = 1;

    $svc->nextDrawer($room);
    assert_true($room['active_drawer_conn_id'] === null, 'nextDrawer: null when no active');
}

// ---------------------------------------------------------------------------
// GROUP 3: handleDrawBarrel — guards
// ---------------------------------------------------------------------------

{
    // Unauthenticated
    [$svc] = makeSvc();
    $conn = makeConn(1, 0, 'x'); $conn->userId = null;
    $worker = new MockWorker();
    $svc->handleDrawBarrel($conn, $worker);
    assert_true($conn->lastError === 'error.auth_required', 'DrawBarrel: auth required');
}

{
    // Not in room
    [$svc] = makeSvc();
    $conn = makeConn(1, 10, 'host');
    $worker = new MockWorker();
    $svc->handleDrawBarrel($conn, $worker);
    assert_true($conn->lastError === 'error.room_not_found', 'DrawBarrel: not in room');
}

{
    // Wrong status
    [$svc] = makeSvc();
    $h = makeConn(1, 10, 'host'); $p2 = makeConn(2, 20, 'p2');
    $worker = new MockWorker();
    $room = makeRoom(1, [1, 2]);
    $room['status'] = 'waiting';
    $room['players'][1] = makePlayer($h);
    $room['players'][2] = makePlayer($p2);
    $worker->rooms[1] = $room;
    $svc->handleDrawBarrel($h, $worker);
    assert_true($h->lastError === 'error.not_your_turn', 'DrawBarrel: wrong status');
}

{
    // Not your turn
    [$svc] = makeSvc();
    $h = makeConn(1, 10, 'host'); $p2 = makeConn(2, 20, 'p2');
    $worker = new MockWorker();
    $room = makeRoom(1, [1, 2]);
    $room['players'][1] = makePlayer($h);
    $room['players'][2] = makePlayer($p2);
    $worker->rooms[1] = $room;
    $svc->handleDrawBarrel($p2, $worker); // p2 tries to draw but host's turn
    assert_true($p2->lastError === 'error.not_your_turn', 'DrawBarrel: not your turn');
}

// ---------------------------------------------------------------------------
// GROUP 4: handleDrawBarrel — successful draw
// ---------------------------------------------------------------------------

{
    [$svc] = makeSvc();
    $h = makeConn(1, 10, 'host'); $p2 = makeConn(2, 20, 'p2');
    $worker = new MockWorker();
    $room = makeRoom(1, [1, 2]);
    $room['players'][1] = makePlayer($h);
    $room['players'][2] = makePlayer($p2);
    $worker->rooms[1] = $room;

    $bagBefore = $room['bag'];
    $firstNumber = $bagBefore[0];

    $svc->handleDrawBarrel($h, $worker);

    $r = $worker->rooms[1];

    // Bag shrunk by 1
    assert_true(count($r['bag']) === 89, 'DrawBarrel: bag shrunk to 89');

    // drawn_numbers updated
    assert_true(count($r['drawn_numbers']) === 1, 'DrawBarrel: drawn_numbers has 1');
    assert_true($r['drawn_numbers'][0] === $firstNumber, 'DrawBarrel: correct number recorded');

    // Drawer AFK reset
    assert_true($r['players'][1]['afk_start'] === null, 'DrawBarrel: afk_start reset');
    assert_true($r['players'][1]['strikes'] === 0,       'DrawBarrel: strikes=0');
    assert_true($r['players'][1]['auto_draws'] === 0,    'DrawBarrel: auto_draws=0');

    // barrels_drawn broadcast to both players
    $hPkts  = $h->sentOfType('barrels_drawn');
    $p2Pkts = $p2->sentOfType('barrels_drawn');
    assert_true(count($hPkts) === 1,  'DrawBarrel: host got barrels_drawn');
    assert_true(count($p2Pkts) === 1, 'DrawBarrel: p2 got barrels_drawn');

    $pkt = $hPkts[0];
    assert_true($pkt['numbers'] === [$firstNumber], 'DrawBarrel: correct numbers in packet');
    assert_true($pkt['remaining'] === 89,            'DrawBarrel: remaining=89');
    assert_true(is_bool($pkt['is_final']),           'DrawBarrel: is_final is bool');
    assert_true($pkt['is_final'] === false,          'DrawBarrel: is_final=false');
    assert_true($pkt['next_drawer'] === 'p2',        'DrawBarrel: next_drawer=p2');

    // your_turn sent to p2 (next drawer)
    $p2YT = $p2->sentOfType('your_turn');
    assert_true(count($p2YT) === 1, 'DrawBarrel: your_turn sent to p2');

    // active_drawer rotated to p2
    assert_true($r['active_drawer_conn_id'] === 2, 'DrawBarrel: active_drawer=p2');
}

// ---------------------------------------------------------------------------
// GROUP 5: markNumber
// ---------------------------------------------------------------------------

{
    [$svc] = makeSvc();
    $h = makeConn(1, 10, 'host');

    // Build a card with known number in col 0 (1-9): place number 5 at row 0, col 0
    $card = array_fill(0, 3, array_fill(0, 9, null));
    $card[0][0] = 5;  $card[0][3] = 30; $card[0][5] = 50;
    $card[0][6] = 60; $card[0][8] = 80;
    $card[1][1] = 10; $card[1][2] = 20; $card[1][4] = 40;
    $card[1][7] = 70; $card[1][8] = 85;
    $card[2][0] = 7;  $card[2][1] = 15; $card[2][2] = 25;
    $card[2][4] = 45; $card[2][6] = 65;

    $mask = array_map(fn($row) => array_fill(0, 9, false), $card);
    $room = makeRoom(1, [1]);
    $room['players'][1] = makePlayer($h, 1, [$card], [$mask]);

    // Mark number 5 — should set mask[0][0][0] = true
    $svc->markNumber($room, 1, 5);
    assert_true($room['players'][1]['masks'][0][0][0] === true,  'markNumber: 5 marked at [0][0][0]');
    assert_true($room['players'][1]['masks'][0][0][3] === false, 'markNumber: 30 not marked yet');

    // Mark number 7 — row 2, col 0
    $svc->markNumber($room, 1, 7);
    assert_true($room['players'][1]['masks'][0][2][0] === true,  'markNumber: 7 marked at [0][2][0]');

    // Mark number not on card — no change
    $svc->markNumber($room, 1, 99);
    $allFalseExcept = true;
    foreach ($room['players'][1]['masks'][0] as $ri => $row) {
        foreach ($row as $ci => $cell) {
            if ($cell === true && !in_array([$ri, $ci], [[0,0],[2,0]])) {
                $allFalseExcept = false;
            }
        }
    }
    assert_true($allFalseExcept, 'markNumber: only marked cells are true');
}

// ---------------------------------------------------------------------------
// GROUP 6: Full 2-turn cycle
// ---------------------------------------------------------------------------

{
    [$svc] = makeSvc();
    $h  = makeConn(1, 10, 'host');
    $p2 = makeConn(2, 20, 'p2');
    $worker = new MockWorker();
    $room = makeRoom(1, [1, 2]);
    $room['players'][1] = makePlayer($h);
    $room['players'][2] = makePlayer($p2);
    $worker->rooms[1] = $room;

    // Turn 1: host draws
    $svc->handleDrawBarrel($h, $worker);
    assert_true($worker->rooms[1]['active_drawer_conn_id'] === 2, 'Cycle: after turn 1 drawer=p2');

    // Turn 2: p2 draws
    $svc->handleDrawBarrel($p2, $worker);
    assert_true($worker->rooms[1]['active_drawer_conn_id'] === 1, 'Cycle: after turn 2 drawer=host');

    // Turn 3: host draws again
    $svc->handleDrawBarrel($h, $worker);
    assert_true($worker->rooms[1]['active_drawer_conn_id'] === 2, 'Cycle: after turn 3 drawer=p2');

    // 3 numbers drawn total
    assert_true(count($worker->rooms[1]['drawn_numbers']) === 3, 'Cycle: 3 numbers drawn');
    assert_true(count($worker->rooms[1]['bag']) === 87,          'Cycle: bag has 87 left');
}

// ---------------------------------------------------------------------------
// RESULTS
// ---------------------------------------------------------------------------

$total = $passed + $failed;
echo "\n--- EPIC-5.5 Turn System Test Suite ---\n";
echo "$passed / $total PASSED\n";
if ($failed > 0) { echo "$failed FAILED\n"; exit(1); }
exit(0);
