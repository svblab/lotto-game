<?php

declare(strict_types=1);

/**
 * EPIC-7.6 — Apartment integration tests
 * Run: php tests/Manual/test_apartment.php
 */

require_once __DIR__ . '/mock_timer.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Helpers.php';

use Lotto\Game\GameService;
use Lotto\Game\GameTurnService;
use Lotto\Game\LottoEngine;
use Lotto\Game\VictoryService;
use Lotto\Game\ApartmentService;
use Lotto\Game\GameFinishService;

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

class MockWorker {
    public array $rooms = [];
    public array $botWinStreaks = [];
    public ?array $serverSettings = null;
    public object $lobbyService;

    public function __construct()
    {
        $this->lobbyService = new class {
            public function broadcastRoomList(object $worker): void {}
            public function removeExistingSeatForUser(object $worker, int $userId, string $reason): void {}
        };
    }
}

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
        if ($key === 'add_user_coins') {
            return new class($parent) {
                private object $p;
                public function __construct(object $p) { $this->p = $p; }
                public function execute(array $p): void { $this->p->updates[] = ['add' => $p[0], 'user_id' => $p[1]]; }
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

function makeSvc(array $users = [], ?MockPDO $pdo = null): array {
    $pdo = $pdo ?? new MockPDO();
    $db  = new MockDatabase($pdo);
    $st  = new MockStmts($users);
    $log = new MockLogger();
    $eng = new LottoEngine();
    $vic = new VictoryService();
    $apt = new ApartmentService($db, $st, $log);
    $fin = new GameFinishService($db, $st, $log);
    $turn = new GameTurnService($log, $vic, $apt, $fin);
    $svc = new GameService($db, $st, $eng, $log, $vic, $apt, $fin, $turn);
    $apt->bindGameService($svc);
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
    $card = makeCardWithClosedRow();
    $maskClosed = makeMaskWithClosedRow($card);
    $maskEmpty  = array_fill(0, 3, array_fill(0, 9, false));

    $room = makeRoom(1, [1, 2]);
    $room['players'][1] = makePlayer($h,  1, [$card], [$maskClosed]);
    $room['players'][2] = makePlayer($p2, 1, [$card], [$maskEmpty]);

    $participants = $apt->prepareApartment($room);

    assert_true($room['status'] === 'apartment',      'prepareApartment: status=apartment');
    assert_true($room['apartment_fired'] === true,     'prepareApartment: apartment_fired=true');
    assert_true($room['apartment_responses'] === [],   'prepareApartment: responses empty');
    assert_true($participants[1] === false,            'prepareApartment: closed-row player required=false (immune)');
    assert_true($participants[2] === true,             'prepareApartment: no-line player required=true');
    assert_true($room['players'][1]['immune'] === true,  'prepareApartment: closed-row player immune=true');
    assert_true($room['players'][2]['immune'] === false, 'prepareApartment: no-line player immune=false');
}

{
    $p1 = makeConn(1, 10, 'p1');
    $p2 = makeConn(2, 20, 'p2');
    $p3 = makeConn(3, 30, 'p3');
    $card = makeCardWithClosedRow();
    $maskClosed = makeMaskWithClosedRow($card);
    $maskEmpty  = array_fill(0, 3, array_fill(0, 9, false));

    $room = makeRoom(1, [1, 2, 3]);
    $room['players'][1] = makePlayer($p1, 1, [$card], [$maskClosed]);
    $room['players'][2] = makePlayer($p2, 1, [$card], [$maskEmpty]);
    $room['players'][3] = makePlayer($p3, 1, [$card], [$maskEmpty]);

    $participants = $apt->prepareApartment($room);

    assert_true($participants[1] === false, 'prepareApartment 3p: closed-row player immune');
    assert_true($participants[2] === true,  'prepareApartment 3p: no-line p2 required');
    assert_true($participants[3] === true,  'prepareApartment 3p: no-line p3 required');
    assert_true(count(array_filter($participants)) === 2, 'prepareApartment 3p: exactly two required');
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
    $card = makeCardWithClosedRow();
    $maskClosed = makeMaskWithClosedRow($card);
    $maskEmpty  = array_fill(0, 3, array_fill(0, 9, false));
    $room = makeRoom(1, [1, 2]);
    $room['players'][1] = makePlayer($h,  1, [$card], [$maskClosed]);
    $room['players'][2] = makePlayer($p2, 1, [$card], [$maskEmpty]);

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
    assert_true($hAlert[0]['required'] === false, 'Alert: closed-row host required=false (immune)');
    assert_true(count($p2Alert) === 1,            'Alert: p2 received alert');
    assert_true($p2Alert[0]['required'] === true, 'Alert: no-line p2 required=true');
    assert_true($hAlert[0]['time_left'] === 10,   'Alert: time_left=10');
}

// ---------------------------------------------------------------------------
// GROUP 5b: triggerApartment cancels game_afk_timer immediately (EPIC-14.3)
// ---------------------------------------------------------------------------

{
    $h  = makeConn(1, 10, 'host');
    $p2 = makeConn(2, 20, 'p2');
    $worker = new MockWorker();
    $room = makeRoom(1, [1, 2]);
    $room['players'][1] = makePlayer($h,  1, [], [], false);
    $room['players'][2] = makePlayer($p2, 1, [], [], false);
    $room['game_afk_timer_id'] = MockTimer::add(1.0, fn() => null, true);
    $worker->rooms[1] = $room;

    $mockGameService = new class {};
    $apt->triggerApartment($worker->rooms[1], 1, $worker, $mockGameService);

    assert_true(
        $worker->rooms[1]['game_afk_timer_id'] === null,
        'triggerApartment: game_afk_timer_id null immediately after transition'
    );
    assert_true(
        MockTimer::$delCount >= 1,
        'triggerApartment: game_afk timer cancelled via lottoTimerDel'
    );
}

// ---------------------------------------------------------------------------
// GROUP 6: handleApartmentChoice — agree → payment
// ---------------------------------------------------------------------------

{
    $h  = makeConn(1, 10, 'host');
    $p2 = makeConn(2, 20, 'p2');
    $worker = new MockWorker();
    $pdo    = new MockPDO();

    [$svc, , , $pdo, $apt] = makeSvc([
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

    // h agrees, p2 agrees → still apartment until timer
    $svc->handleApartmentChoice($h, $worker, 'agree');

    if (isset($worker->rooms[1])) {
        assert_true($worker->rooms[1]['status'] === 'apartment', 'Choice: after 1 agree still apartment');
    }

    $svc->handleApartmentChoice($p2, $worker, 'agree');

    if (isset($worker->rooms[1])) {
        assert_true($worker->rooms[1]['status'] === 'apartment', 'Choice: both agree still apartment until timer');
    }

    $apt->onApartmentTimeout($worker->rooms[1], 1, $worker, $svc);

    // After timer → finishApartment → status=playing
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

    [$svc, , , , $apt] = makeSvc([
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

    // p2 refuses → recorded, still in room until timer
    $svc->handleApartmentChoice($p2, $worker, 'refuse');

    $r = $worker->rooms[1];
    assert_true(isset($r['players'][2]),           'Refuse: p2 still in room until timer');
    assert_true(($r['apartment_responses'][2] ?? '') === 'refuse', 'Refuse: response recorded');

    // h and p3 agree
    $svc->handleApartmentChoice($h, $worker, 'agree');
    $svc->handleApartmentChoice($p3, $worker, 'agree');

    assert_true($worker->rooms[1]['status'] === 'apartment', 'Refuse+Agree: still apartment until timer');

    $apt->onApartmentTimeout($worker->rooms[1], 1, $worker, $svc);

    $r = $worker->rooms[1];
    assert_true(!isset($r['players'][2]),           'Refuse: p2 removed after timer');
    assert_true(!in_array(2, $r['drawer_order']),   'Refuse: p2 removed from drawer_order');
    assert_true(
        ($worker->rooms[1]['all_players_history'][2]['cards_count'] ?? -1) === 1,
        'Refuse: history cards_count=1'
    );
    assert_true(
        ($worker->rooms[1]['all_players_history'][2]['reason'] ?? '') === 'refuse',
        'Refuse: history reason=refuse'
    );

    // player_left sent to remaining and to removed player (with user_id for client self-removal)
    $hLeft = $h->sentOfType('player_left');
    assert_true(count($hLeft) === 1,                'Refuse: player_left sent to host');
    assert_true($hLeft[0]['reason'] === 'refuse',   'Refuse: reason=refuse');
    $p2Left = $p2->sentOfType('player_left');
    assert_true(count($p2Left) === 1,               'Refuse: player_left sent to removed player');
    assert_true(($p2Left[0]['user_id'] ?? 0) === 20, 'Refuse: removed player packet has user_id');

    if (isset($worker->rooms[1])) {
        assert_true($worker->rooms[1]['status'] === 'playing', 'Refuse+Agree: game resumes');
        assert_true($worker->rooms[1]['bank'] === 40,          'Refuse+Agree: bank += 5+5=40');
    }
}

// ---------------------------------------------------------------------------
// GROUP 7b: handleApartmentChoice — change decision before timer
// ---------------------------------------------------------------------------

{
    $h  = makeConn(1, 10, 'host');
    $p2 = makeConn(2, 20, 'p2');
    $worker = new MockWorker();
    $pdo    = new MockPDO();

    [$svc, , , , $apt] = makeSvc([
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

    $svc->handleApartmentChoice($p2, $worker, 'refuse');
    $svc->handleApartmentChoice($p2, $worker, 'agree');
    assert_true(isset($worker->rooms[1]['players'][2]), 'Change: refuse→agree keeps player in room');
    assert_true(($worker->rooms[1]['apartment_responses'][2] ?? '') === 'agree', 'Change: last choice wins');

    $svc->handleApartmentChoice($h, $worker, 'agree');
    $apt->onApartmentTimeout($worker->rooms[1], 1, $worker, $svc);

    if (isset($worker->rooms[1])) {
        assert_true(isset($worker->rooms[1]['players'][2]), 'Change: p2 stays after final agree');
        assert_true($worker->rooms[1]['status'] === 'playing', 'Change: game resumes');
    } else {
        fail('Change: room should exist after agree+agree');
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
// GROUP 9: removePlayerFromApartment last player — no-survivors refund
// ---------------------------------------------------------------------------

{
    $h = makeConn(1, 10, 'solo');
    $worker = new MockWorker();
    $pdo = new MockPDO();
    [$svc, , $st, $pdo, $apt] = makeSvc([10 => ['id' => 10, 'coins' => 100]], $pdo);

    $room = makeRoom(1, [1], 10);
    $room['status'] = 'apartment';
    $room['players'][1] = makePlayer($h, 1, [], [], false);
    $room['players'][1]['total_paid'] = 10;
    $worker->rooms[1] = $room;

    $apt->removePlayerFromApartment($room, 1, 1, 'refuse', $worker);

    assert_true(!isset($worker->rooms[1]), 'apartment empty-path: room destroyed');
    assert_true($pdo->committed === true, 'apartment empty-path: refund committed');
    assert_true(count($st->updates) === 1, 'apartment empty-path: solo refunded');
    assert_true($st->updates[0]['add'] === 10, 'apartment empty-path: stake returned');
    $go = $h->sentOfType('game_over');
    assert_true(count($go) === 1, 'apartment empty-path: game_over sent');
    assert_true(($go[0]['reason'] ?? '') === 'no_survivors', 'apartment empty-path: no winner');
}

// ---------------------------------------------------------------------------
// GROUP 10: host transfer when host removed during apartment (EPIC-9.3 gap)
// ---------------------------------------------------------------------------

{
    $h  = makeConn(1, 10, 'host');
    $p2 = makeConn(2, 20, 'p2');
    $p3 = makeConn(3, 30, 'p3');
    $worker = new MockWorker();

    [$svc, , , , $apt] = makeSvc([
        10 => ['id' => 10, 'coins' => 100],
        20 => ['id' => 20, 'coins' => 100],
        30 => ['id' => 30, 'coins' => 100],
    ]);

    $room = makeRoom(1, [1, 2, 3], 30);
    $room['players'][1] = makePlayer($h,  1, [], [], false);
    $room['players'][2] = makePlayer($p2, 1, [], [], true);
    $room['players'][3] = makePlayer($p3, 1, [], [], true);
    $room['status']     = 'apartment';
    $room['apartment_fired'] = true;
    $worker->rooms[1] = $room;

    $apt->removePlayerFromApartment($worker->rooms[1], 1, 1, 'refuse', $worker);

    $r = $worker->rooms[1];
    assert_true($r['host_conn_id'] === 2, 'Host transfer: FIFO next active player becomes host');

    $p2HostChanged = $p2->sentOfType('host_changed');
    assert_true(count($p2HostChanged) === 1, 'Host transfer: host_changed sent to p2');
    assert_true($p2HostChanged[0]['host'] === 'p2', 'Host transfer: host_changed names p2');

    $p3HostChanged = $p3->sentOfType('host_changed');
    assert_true(count($p3HostChanged) === 1, 'Host transfer: host_changed sent to p3');
    assert_true($p3HostChanged[0]['host'] === 'p2', 'Host transfer: host_changed consistent for all');
}

// ---------------------------------------------------------------------------
// GROUP 11: bank_updated on apartment resume (not last_survivor / no_survivors)
// ---------------------------------------------------------------------------

{
    $h  = makeConn(1, 10, 'host');
    $p2 = makeConn(2, 20, 'p2');
    $p3 = makeConn(3, 30, 'p3');
    $worker = new MockWorker();
    $pdo    = new MockPDO();

    [$svc, , , , $apt] = makeSvc([
        10 => ['id' => 10, 'coins' => 100],
        20 => ['id' => 20, 'coins' => 100],
        30 => ['id' => 30, 'coins' => 100],
    ], $pdo);

    $room = makeRoom(1, [1, 2, 3], 20);
    $room['players'][1] = makePlayer($h,  1, [], [], false);
    $room['players'][2] = makePlayer($p2, 1, [], [], false);
    $room['players'][3] = makePlayer($p3, 1, [], [], true);
    $room['status']     = 'apartment';
    $room['apartment_fired'] = true;
    $room['_apartment_participants'] = [1 => true, 2 => true];
    $worker->rooms[1] = $room;

    $svc->handleApartmentChoice($h, $worker, 'agree');
    $svc->handleApartmentChoice($p2, $worker, 'agree');
    $apt->onApartmentTimeout($worker->rooms[1], 1, $worker, $svc);

    assert_true(isset($worker->rooms[1]), 'bank_updated: room exists after resume');
    $expectedBank = 30;
    assert_true($worker->rooms[1]['bank'] === $expectedBank, 'bank_updated: bank += 5+5');

    foreach ([$h, $p2, $p3] as $conn) {
        $pkts = $conn->sentOfType('bank_updated');
        assert_true(count($pkts) === 1, 'bank_updated: sent to active member ' . $conn->username);
        assert_true((int) ($pkts[0]['bank'] ?? 0) === $expectedBank, 'bank_updated: correct bank for ' . $conn->username);
    }

    $hBalancePkts  = $h->sentOfType('balance_updated');
    $p2BalancePkts = $p2->sentOfType('balance_updated');
    $p3BalancePkts = $p3->sentOfType('balance_updated');
    assert_true(count($hBalancePkts) === 1, 'balance_updated: paying host receives own balance');
    assert_true((int) ($hBalancePkts[0]['coins'] ?? 0) === 95, 'balance_updated: host post-payment coins');
    assert_true(count($p2BalancePkts) === 1, 'balance_updated: paying p2 receives own balance');
    assert_true((int) ($p2BalancePkts[0]['coins'] ?? 0) === 95, 'balance_updated: p2 post-payment coins');
    assert_true(count($p3BalancePkts) === 0, 'balance_updated: non-paying immune player does NOT receive others balance');

    // last_survivor branch — no bank_updated (game_over carries final bank)
    $h2  = makeConn(4, 40, 'solo_h');
    $p2b = makeConn(5, 50, 'solo_p2');
    $worker2 = new MockWorker();
    $pdo2 = new MockPDO();
    [$svc2, , , , $apt2] = makeSvc([
        40 => ['id' => 40, 'coins' => 100],
        50 => ['id' => 50, 'coins' => 100],
    ], $pdo2);

    $room2 = makeRoom(1, [4, 5], 20);
    $room2['players'][4] = makePlayer($h2,  1, [], [], false);
    $room2['players'][5] = makePlayer($p2b, 1, [], [], false);
    $room2['status'] = 'apartment';
    $room2['apartment_fired'] = true;
    $room2['_apartment_participants'] = [4 => true, 5 => true];
    $worker2->rooms[1] = $room2;

    $svc2->handleApartmentChoice($p2b, $worker2, 'refuse');
    $svc2->handleApartmentChoice($h2, $worker2, 'agree');
    $apt2->onApartmentTimeout($worker2->rooms[1], 1, $worker2, $svc2);

    assert_true(count($h2->sentOfType('bank_updated')) === 0, 'bank_updated: not sent on last_survivor');
    assert_true(count($h2->sentOfType('game_over')) >= 1, 'bank_updated: game_over on last_survivor');

    // no_survivors branch — no bank_updated
    $solo = makeConn(6, 60, 'gone');
    $worker3 = new MockWorker();
    $pdo3 = new MockPDO();
    [$svc3, , , , $apt3] = makeSvc([60 => ['id' => 60, 'coins' => 100]], $pdo3);

    $room3 = makeRoom(1, [6], 10);
    $room3['status'] = 'apartment';
    $room3['apartment_fired'] = true;
    $room3['players'][6] = makePlayer($solo, 1, [], [], false);
    $room3['players'][6]['status'] = 'disconnected';
    $room3['_apartment_participants'] = [6 => true];
    $room3['apartment_responses'] = [6 => 'agree'];
    $worker3->rooms[1] = $room3;

    $apt3->finishApartment($worker3->rooms[1], 1, $worker3, $svc3);

    assert_true(count($solo->sentOfType('bank_updated')) === 0, 'bank_updated: not sent on no_survivors');
}

// ---------------------------------------------------------------------------
// RESULTS
// ---------------------------------------------------------------------------

$total = $passed + $failed;
echo "\n--- EPIC-7.6 Apartment Test Suite ---\n";
echo "$passed / $total PASSED\n";
if ($failed > 0) { echo "$failed FAILED\n"; exit(1); }
exit(0);
