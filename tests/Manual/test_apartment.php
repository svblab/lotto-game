<?php

declare(strict_types=1);

/**
 * EPIC-7.6 — Apartment integration tests
 * Run: php tests/Manual/test_apartment.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Helpers.php';

use Lotto\Game\GameService;
use Lotto\Game\LottoEngine;
use Lotto\Game\VictoryService;
use Lotto\Game\ApartmentService;

$passed = 0; $failed = 0;
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
    public function lastSent(): ?array { return end($this->sent) ?: null; }
}

class MockWorker { public array $rooms = []; }

class MockPDO {
    public bool $committed = false; public bool $rolledBack = false;
    public function beginTransaction(): void {}
    public function commit(): void { $this->committed = true; }
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

/** Card with one fully-closed row (row 0) and matching mask */
function makeCardWithClosedRow(): array {
    $card = array_fill(0, 3, array_fill(0, 9, null));
    $card[0][0]=1; $card[0][2]=20; $card[0][4]=40; $card[0][6]=60; $card[0][8]=80;
    $card[1][1]=10; $card[1][3]=30; $card[1][5]=50; $card[1][7]=70; $card[1][8]=85;
    $card[2][0]=5;  $card[2][2]=25; $card[2][4]=45; $card[2][6]=65; $card[2][8]=90;
    return $card;
}

function makeMaskWithClosedRow(array $card): array {
    $mask = array_fill(0, 3, array_fill(0, 9, false));
    // Close only row 0
    for ($col = 0; $col < 9; $col++) {
        if ($card[0][$col] !== null) $mask[0][$col] = true;
    }
    return $mask;
}

function makePlayer(MockConnection $conn, int $cardsCount = 1, array $cards = [], array $masks = [], bool $immune = false): array {
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
        'connection' => $conn, 'immune' => $immune,
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

function makeSvc(array $users = [], MockPDO $pdo = null): array {
    $pdo = $pdo ?? new MockPDO();
    $db  = new MockDatabase($pdo);
    $st  = new MockStmts($users);
    $log = new MockLogger();
    $eng = new LottoEngine();
    $vic = new VictoryService();
    $apt = new ApartmentService($db, $st, $log);
    $svc = new GameService($db, $st, $eng, $log, $vic, $apt);
    return [$svc, $log, $st, $pdo, $apt];
}

// ---------------------------------------------------------------------------
// GROUP 1: ApartmentService::hasLine
// ---------------------------------------------------------------------------

$_mockDb  = new MockDatabase(new MockPDO());
$_mockSt  = new MockStmts();
$_mockLog = new MockLogger();
$apt = new ApartmentService($_mockDb, $_mockSt, $_mockLog);

{
    // No line — empty mask
    $h    = makeConn(1, 10, 'host');
    $card = makeCardWithClosedRow();
    $mask = array_fill(0, 3, array_fill(0, 9, false));
    $player = makePlayer($h, 1, [$card], [$mask]);
    assert_true(!$apt->hasLine($player), 'hasLine: empty mask = no line');
}

{
    // Line exists — row 0 closed
    $h    = makeConn(1, 10, 'host');
    $card = makeCardWithClosedRow();
    $mask = makeMaskWithClosedRow($card);
    $player = makePlayer($h, 1, [$card], [$mask]);
    assert_true($apt->hasLine($player), 'hasLine: row 0 closed = line');
}

{
    // Partial row — not a line
    $h    = makeConn(1, 10, 'host');
    $card = makeCardWithClosedRow();
    $mask = array_fill(0, 3, array_fill(0, 9, false));
    $mask[0][0] = true; // only 1 of 5 in row 0
    $player = makePlayer($h, 1, [$card], [$mask]);
    assert_true(!$apt->hasLine($player), 'hasLine: partial row = no line');
}

// ---------------------------------------------------------------------------
// GROUP 2: ApartmentService::shouldTrigger
// ---------------------------------------------------------------------------

{
    // Should trigger
    $h  = makeConn(1, 10, 'host');
    $p2 = makeConn(2, 20, 'p2');
    $card = makeCardWithClosedRow();
    $mask = makeMaskWithClosedRow($card);
    $eng  = new LottoEngine(); $card2 = $eng->generateCard();
    $mask2 = array_map(fn($row) => array_fill(0, 9, false), $card2);

    $room = makeRoom(1, [1, 2]);
    $room['players'][1] = makePlayer($h,  1, [$card],  [$mask]);
    $room['players'][2] = makePlayer($p2, 1, [$card2], [$mask2]);

    assert_true($apt->shouldTrigger($room), 'shouldTrigger: line detected → true');
}

{
    // Already fired
    $h    = makeConn(1, 10, 'host');
    $card = makeCardWithClosedRow();
    $mask = makeMaskWithClosedRow($card);
    $room = makeRoom(1, [1]);
    $room['players'][1]    = makePlayer($h, 1, [$card], [$mask]);
    $room['apartment_fired'] = true;
    assert_true(!$apt->shouldTrigger($room), 'shouldTrigger: already fired → false');
}

{
    // Disconnected player with line — not counted
    $h    = makeConn(1, 10, 'host');
    $card = makeCardWithClosedRow();
    $mask = makeMaskWithClosedRow($card);
    $room = makeRoom(1, [1]);
    $room['players'][1] = makePlayer($h, 1, [$card], [$mask]);
    $room['players'][1]['status'] = 'disconnected';
    assert_true(!$apt->shouldTrigger($room), 'shouldTrigger: disconnected player skipped');
}

// ---------------------------------------------------------------------------
// GROUP 3: ApartmentService::prepareApartment
// ---------------------------------------------------------------------------

{
    $h  = makeConn(1, 10, 'host');
    $p2 = makeConn(2, 20, 'p2');
    $room = makeRoom(1, [1, 2]);
    $room['players'][1] = makePlayer($h,  1, [], [], false); // not immune
    $room['players'][2] = makePlayer($p2, 1, [], [], true);  // immune

    $participants = $apt->prepareApartment($room);

    assert_true($room['status'] === 'apartment',      'prepareApartment: status=apartment');
    assert_true($room['apartment_fired'] === true,     'prepareApartment: apartment_fired=true');
    assert_true($room['apartment_responses'] === [],   'prepareApartment: responses empty');
    assert_true($participants[1] === true,             'prepareApartment: non-immune required=true');
    assert_true($participants[2] === false,            'prepareApartment: immune required=false');
}

// ---------------------------------------------------------------------------
// GROUP 4: allRequiredAnswered / getPendingRequired
// ---------------------------------------------------------------------------

{
    $h  = makeConn(1, 10, 'host');
    $p2 = makeConn(2, 20, 'p2');
    $room = makeRoom(1, [1, 2]);
    $room['players'][1] = makePlayer($h);
    $room['players'][2] = makePlayer($p2);
    $room['status'] = 'apartment';
    $participants = [1 => true, 2 => true];

    assert_true(!$apt->allRequiredAnswered($room, $participants), 'allRequired: 0 answers → false');

    $apt->recordResponse($room, 1, 'agree');
    assert_true(!$apt->allRequiredAnswered($room, $participants), 'allRequired: 1/2 answers → false');

    $apt->recordResponse($room, 2, 'agree');
    assert_true($apt->allRequiredAnswered($room, $participants), 'allRequired: 2/2 answers → true');
}

// ---------------------------------------------------------------------------
// GROUP 5: triggerApartment — alert broadcast (no real timer)
// ---------------------------------------------------------------------------

{
    // We cannot test Workerman\Timer in unit tests — test only the alert broadcast
    // by stubbing triggerApartment to skip timer creation.
    // Instead test prepareApartment + alert logic manually.

    $h  = makeConn(1, 10, 'host');
    $p2 = makeConn(2, 20, 'p2');
    $room = makeRoom(1, [1, 2]);
    $room['players'][1] = makePlayer($h,  1, [], [], false);
    $room['players'][2] = makePlayer($p2, 1, [], [], true);

    $participants = $apt->prepareApartment($room);

    // Simulate alert broadcast
    foreach ($room['players'] as $connId => $player) {
        $required = $participants[$connId] ?? false;
        $player['connection']->send(json_encode([
            'type'      => 'apartment_alert',
            'required'  => $required,
            'time_left' => 10,
        ]));
    }

    $hAlert  = $h->sentOfType('apartment_alert');
    $p2Alert = $p2->sentOfType('apartment_alert');
    assert_true(count($hAlert) === 1,             'Alert: host received alert');
    assert_true($hAlert[0]['required'] === true,  'Alert: host required=true');
    assert_true(count($p2Alert) === 1,            'Alert: p2 received alert');
    assert_true($p2Alert[0]['required'] === false,'Alert: immune p2 required=false');
    assert_true($hAlert[0]['time_left'] === 10,   'Alert: time_left=10');
}

// ---------------------------------------------------------------------------
// GROUP 6: handleApartmentChoice — agree → payment
// ---------------------------------------------------------------------------

{
    $h  = makeConn(1, 10, 'host');
    $p2 = makeConn(2, 20, 'p2');
    $worker = new MockWorker();
    $pdo    = new MockPDO();

    [$svc, $log, $st] = makeSvc([
        10 => ['id' => 10, 'coins' => 100],
        20 => ['id' => 20, 'coins' => 100],
    ], $pdo);

    $room = makeRoom(1, [1, 2], 20);
    $room['players'][1] = makePlayer($h,  1, [], [], false);
    $room['players'][2] = makePlayer($p2, 1, [], [], false);
    $room['status']     = 'apartment';
    $room['apartment_fired'] = true;
    $room['_apartment_participants'] = [1 => true, 2 => true];
    $worker->rooms[1] = $room;

    // h agrees, p2 agrees → finishApartment triggered
    $svc->handleApartmentChoice($h, $worker, 'agree');

    // After h agrees (1/2), game still in apartment
    if (isset($worker->rooms[1])) {
        assert_true($worker->rooms[1]['status'] === 'apartment', 'Choice: after 1 agree still apartment');
    }

    $svc->handleApartmentChoice($p2, $worker, 'agree');

    // After both agree → finishApartment → status=playing
    if (isset($worker->rooms[1])) {
        assert_true($worker->rooms[1]['status'] === 'playing', 'Choice: both agree → playing');
        assert_true($worker->rooms[1]['bank'] === 30,          'Choice: bank += 5+5 = 30');
        assert_true($pdo->committed === true,                   'Choice: payment committed');
        assert_true($worker->rooms[1]['players'][1]['immune'] === true, 'Choice: h immune after agree');
        assert_true($worker->rooms[1]['players'][2]['immune'] === true, 'Choice: p2 immune after agree');
    } else {
        fail('Choice: room should exist after all agree');
    }
}

// ---------------------------------------------------------------------------
// GROUP 7: handleApartmentChoice — refuse → player removed
// ---------------------------------------------------------------------------

{
    $h  = makeConn(1, 10, 'host');
    $p2 = makeConn(2, 20, 'p2');
    $p3 = makeConn(3, 30, 'p3');
    $worker = new MockWorker();
    $pdo    = new MockPDO();

    [$svc] = makeSvc([
        10 => ['id' => 10, 'coins' => 100],
        20 => ['id' => 20, 'coins' => 100],
        30 => ['id' => 30, 'coins' => 100],
    ], $pdo);

    $room = makeRoom(1, [1, 2, 3], 30);
    $room['players'][1] = makePlayer($h,  1, [], [], false);
    $room['players'][2] = makePlayer($p2, 1, [], [], false);
    $room['players'][3] = makePlayer($p3, 1, [], [], false);
    $room['status']     = 'apartment';
    $room['apartment_fired'] = true;
    $room['_apartment_participants'] = [1 => true, 2 => true, 3 => true];
    $worker->rooms[1] = $room;

    // p2 refuses → removed
    $svc->handleApartmentChoice($p2, $worker, 'refuse');

    $r = $worker->rooms[1];
    assert_true(!isset($r['players'][2]),           'Refuse: p2 removed from players');
    assert_true(!in_array(2, $r['drawer_order']),   'Refuse: p2 removed from drawer_order');

    // player_left sent to remaining
    $hLeft = $h->sentOfType('player_left');
    assert_true(count($hLeft) === 1,                'Refuse: player_left sent to host');
    assert_true($hLeft[0]['reason'] === 'refuse',   'Refuse: reason=refuse');

    // h and p3 agree → finish
    $svc->handleApartmentChoice($h, $worker, 'agree');
    $svc->handleApartmentChoice($p3, $worker, 'agree');

    if (isset($worker->rooms[1])) {
        assert_true($worker->rooms[1]['status'] === 'playing', 'Refuse+Agree: game resumes');
        assert_true($worker->rooms[1]['bank'] === 40,          'Refuse+Agree: bank += 5+5=40');
    }
}

// ---------------------------------------------------------------------------
// GROUP 8: apartment_fired prevents re-trigger
// ---------------------------------------------------------------------------

{
    [$svc, , , , ] = makeSvc();
$_db2 = new MockDatabase(new MockPDO());
$_st2 = new MockStmts();
$_log2 = new MockLogger();
$apt2 = new ApartmentService($_db2, $_st2, $_log2);
    $h    = makeConn(1, 10, 'host');
    $card = makeCardWithClosedRow();
    $mask = makeMaskWithClosedRow($card);
    $room = makeRoom(1, [1]);
    $room['players'][1]     = makePlayer($h, 1, [$card], [$mask]);
    $room['apartment_fired'] = true;

    assert_true(!$apt2->shouldTrigger($room), 'Re-trigger: apartment_fired blocks re-trigger');
}

// ---------------------------------------------------------------------------
// RESULTS
// ---------------------------------------------------------------------------

$total = $passed + $failed;
echo "\n--- EPIC-7.6 Apartment Test Suite ---\n";
echo "$passed / $total PASSED\n";
if ($failed > 0) { echo "$failed FAILED\n"; exit(1); }
exit(0);
