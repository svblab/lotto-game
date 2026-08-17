<?php

declare(strict_types=1);

/**
 * ADR-032 — Turn nudge (nudge_turn / nudge_received)
 * Run: php tests/Manual/test_turn_nudge.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Helpers.php';

use Lotto\Core\Constants;
use Lotto\Game\GameHandler;
use Lotto\Game\GameService;
use Lotto\Game\GameTurnService;
use Lotto\Game\LottoEngine;
use Lotto\Game\VictoryService;
use Lotto\Game\ApartmentService;
use Lotto\Game\GameFinishService;

$passed = 0;
$failed = 0;

function ok(string $label): void  { global $passed; $passed++; echo "[PASS] $label\n"; }
function fail(string $label, string $r = ''): void { global $failed; $failed++; echo "[FAIL] $label" . ($r ? " — $r" : '') . "\n"; }
function assert_true(bool $c, string $l, string $r = ''): void { $c ? ok($l) : fail($l, $r); }

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
    public function beginTransaction(): void {}
    public function commit(): void {}
    public function rollBack(): void {}
}

class MockDatabase {
    public MockPDO $pdo;
    public function __construct(MockPDO $p) { $this->pdo = $p; }
    public function getPdo(): MockPDO { return $this->pdo; }
}

class MockStmts {
    public function get(string $key): object {
        throw new \InvalidArgumentException("Unknown: $key");
    }
}

class MockLogger {
    public function info(string $m): void {}
    public function warning(string $m): void {}
    public function error(string $m): void {}
}

function makeConn(int $id, int $uid, string $name): MockConnection {
    return new MockConnection($id, $uid, $name);
}

function makePlayer(MockConnection $conn, int $cardsCount = 1): array {
    $engine = new LottoEngine();
    $cards = [];
    $masks = [];
    for ($i = 0; $i < $cardsCount; $i++) {
        $cards[] = $engine->generateCard();
        $masks[] = array_map(fn($row) => array_fill(0, 9, false), $cards[$i]);
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
        'status' => 'playing', 'bank' => 30, 'apartment_fired' => false,
        'pause_for_apartment' => false, 'apartment_responses' => [],
        'game_afk_timer_id' => 42, 'apartment_timer_id' => null, 'lobby_afk_timer_id' => null,
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
    $vic = new VictoryService();
    $apt = new ApartmentService($db, $st, $log);
    $fin = (new ReflectionClass(GameFinishService::class))->newInstanceWithoutConstructor();
    $turn = new GameTurnService($log, $vic, $apt, $fin);
    $svc = new GameService($db, $st, $eng, $log, $vic, $apt, $fin, $turn);
    return [$svc, $turn];
}

function afkSnapshot(array $room): string {
    $out = [];
    foreach ($room['players'] as $cid => $p) {
        $auto = (int) ($p['auto_draws'] ?? 0);
        $out[(string) $cid] = [
            'afk_start'    => $p['afk_start'],
            'auto_draws'   => $auto,
            'strikes'      => $p['strikes'] ?? 0,
            'turn_seconds' => Constants::gameAfkStrikeWindowSeconds($auto),
        ];
    }
    $out['_timer']  = $room['game_afk_timer_id'] ?? null;
    $out['_drawer'] = $room['active_drawer_conn_id'] ?? null;
    return json_encode($out);
}

function seating(MockWorker $worker, array $room, MockConnection ...$conns): void {
    foreach ($conns as $c) {
        $room['players'][$c->id] = makePlayer($c);
    }
    $worker->rooms[$room['room_id']] = $room;
}

// ---------------------------------------------------------------------------
// Success: private packet to drawer only; AFK fields byte-identical
// ---------------------------------------------------------------------------

{
    [$svc] = makeSvc();
    $handler = new GameHandler($svc);
    $h = makeConn(1, 10, 'host'); $p2 = makeConn(2, 20, 'p2'); $p3 = makeConn(3, 30, 'p3');
    $worker = new MockWorker();
    $room = makeRoom(1, [1, 2, 3]);
    seating($worker, $room, $h, $p2, $p3);
    $r = &$worker->rooms[1];
    $r['players'][1]['afk_start'] = 1_700_000_000;
    $r['players'][1]['auto_draws'] = 1;
    $r['players'][1]['strikes'] = 0;
    $before = afkSnapshot($r);

    $handler->handleNudgeTurn($p2, $worker);

    $after = afkSnapshot($worker->rooms[1]);
    assert_true($before === $after, 'nudge: AFK snapshot byte-identical');
    assert_true($worker->rooms[1]['players'][2]['nudged_this_turn'] === true, 'nudge: sender flag set');
    $hN = $h->sentOfType('nudge_received');
    assert_true(count($hN) === 1, 'nudge: drawer received nudge_received');
    assert_true(($hN[0]['from'] ?? '') === 'p2', 'nudge: from=p2');
    assert_true(count($p2->sentOfType('nudge_received')) === 0, 'nudge: sender did not receive packet');
    assert_true(count($p3->sentOfType('nudge_received')) === 0, 'nudge: other player did not receive packet');
    assert_true($p2->lastError === null, 'nudge: sender no error');
}

// ---------------------------------------------------------------------------
// Second nudge same turn → error.already_nudged; still no broadcast
// ---------------------------------------------------------------------------

{
    [$svc] = makeSvc();
    $h = makeConn(1, 10, 'host'); $p2 = makeConn(2, 20, 'p2'); $p3 = makeConn(3, 30, 'p3');
    $worker = new MockWorker();
    seating($worker, makeRoom(1, [1, 2, 3]), $h, $p2, $p3);
    $svc->handleNudgeTurn($p2, $worker);
    $hCount = count($h->sentOfType('nudge_received'));
    $svc->handleNudgeTurn($p2, $worker);
    assert_true($p2->lastError === 'error.already_nudged', 'already_nudged: second nudge rejected');
    assert_true(count($h->sentOfType('nudge_received')) === $hCount, 'already_nudged: no extra drawer packet');
    assert_true(count($p3->sentOfType('nudge_received')) === 0, 'already_nudged: still not broadcast');
}

// ---------------------------------------------------------------------------
// Drawer cannot nudge self
// ---------------------------------------------------------------------------

{
    [$svc] = makeSvc();
    $h = makeConn(1, 10, 'host'); $p2 = makeConn(2, 20, 'p2');
    $worker = new MockWorker();
    seating($worker, makeRoom(1, [1, 2]), $h, $p2);
    $svc->handleNudgeTurn($h, $worker);
    assert_true($h->lastError === 'error.not_your_turn', 'self-nudge: error.not_your_turn');
    assert_true(count($h->sentOfType('nudge_received')) === 0, 'self-nudge: no nudge_received');
    assert_true(empty($worker->rooms[1]['players'][1]['nudged_this_turn']), 'self-nudge: flag not set');
}

// ---------------------------------------------------------------------------
// Not in that room / not seated
// ---------------------------------------------------------------------------

{
    [$svc] = makeSvc();
    $h = makeConn(1, 10, 'host'); $p2 = makeConn(2, 20, 'p2');
    $outsider = makeConn(9, 90, 'ghost');
    $worker = new MockWorker();
    seating($worker, makeRoom(1, [1, 2]), $h, $p2);
    $svc->handleNudgeTurn($outsider, $worker);
    assert_true($outsider->lastError === 'error.room_not_found', 'outsider: error.room_not_found');
    assert_true(count($h->sentOfType('nudge_received')) === 0, 'outsider: drawer not notified');
}

{
    [$svc] = makeSvc();
    $h = makeConn(1, 10, 'host'); $p2 = makeConn(2, 20, 'p2');
    $other = makeConn(8, 80, 'lobbyist');
    $worker = new MockWorker();
    seating($worker, makeRoom(1, [1, 2]), $h, $p2);
    $waiting = makeRoom(8, [8]);
    $waiting['room_id'] = 2;
    $waiting['status'] = 'waiting';
    seating($worker, $waiting, $other);
    $svc->handleNudgeTurn($other, $worker);
    assert_true($other->lastError === 'error.not_your_turn', 'other-room waiting: error.not_your_turn');
    assert_true(count($h->sentOfType('nudge_received')) === 0, 'other-room: playing drawer not notified');
}

{
    [$svc] = makeSvc();
    $h = makeConn(1, 10, 'host'); $p2 = makeConn(2, 20, 'p2');
    $worker = new MockWorker();
    seating($worker, makeRoom(1, [1, 2]), $h, $p2);
    $worker->rooms[1]['players'][2]['status'] = 'disconnected';
    $svc->handleNudgeTurn($p2, $worker);
    assert_true($p2->lastError === 'error.not_your_turn', 'disconnected: error.not_your_turn');
}

{
    [$svc] = makeSvc();
    $h = makeConn(1, 10, 'host'); $p2 = makeConn(2, 20, 'p2');
    $worker = new MockWorker();
    seating($worker, makeRoom(1, [1, 2]), $h, $p2);
    $worker->rooms[1]['status'] = 'apartment';
    $svc->handleNudgeTurn($p2, $worker);
    assert_true($p2->lastError === 'error.not_your_turn', 'apartment: error.not_your_turn');
}

// ---------------------------------------------------------------------------
// Flag resets on startTurn (new turn); not on same-turn turn_ready
// ---------------------------------------------------------------------------

{
    [$svc] = makeSvc();
    $h = makeConn(1, 10, 'host'); $p2 = makeConn(2, 20, 'p2');
    $worker = new MockWorker();
    seating($worker, makeRoom(1, [1, 2]), $h, $p2);
    $svc->handleNudgeTurn($p2, $worker);
    assert_true($worker->rooms[1]['players'][2]['nudged_this_turn'] === true, 'reset: flag set before new turn');

    $worker->rooms[1]['players'][1]['afk_start'] = null;
    $r = &$worker->rooms[1];
    $svc->handleTurnReady($h, $worker);
    assert_true($worker->rooms[1]['players'][2]['nudged_this_turn'] === true, 'reset: turn_ready does not clear flag');
    $p2->lastError = null;
    $svc->handleNudgeTurn($p2, $worker);
    assert_true($p2->lastError === 'error.already_nudged', 'reset: still already_nudged after turn_ready');

    $r = &$worker->rooms[1];
    $svc->startTurn($r, $worker, 1, true);
    assert_true($worker->rooms[1]['players'][2]['nudged_this_turn'] === false, 'reset: startTurn clears all flags');
    $p2->lastError = null;
    $r = &$worker->rooms[1];
    $before = afkSnapshot($r);
    $svc->handleNudgeTurn($p2, $worker);
    assert_true($p2->lastError === null, 'reset: second turn nudge succeeds');
    assert_true(count($h->sentOfType('nudge_received')) === 2, 'reset: drawer got second nudge_received');
    assert_true(afkSnapshot($worker->rooms[1]) === $before, 'reset: AFK still unchanged on second-turn nudge');
}

{
    [$svc] = makeSvc();
    $h = makeConn(1, 10, 'host'); $p2 = makeConn(2, 20, 'p2');
    $worker = new MockWorker();
    seating($worker, makeRoom(1, [1, 2]), $h, $p2);
    $svc->handleNudgeTurn($p2, $worker);
    assert_true($worker->rooms[1]['players'][2]['nudged_this_turn'] === true, 'draw-reset: nudged before draw');
    $svc->handleDrawBarrel($h, $worker);
    $r = $worker->rooms[1];
    assert_true(($r['active_drawer_conn_id'] ?? null) === 2, 'draw-reset: drawer rotated to p2');
    assert_true($r['players'][1]['nudged_this_turn'] === false, 'draw-reset: host flag cleared');
    assert_true($r['players'][2]['nudged_this_turn'] === false, 'draw-reset: p2 flag cleared');
    $h->lastError = null;
    $svc->handleNudgeTurn($h, $worker);
    assert_true($h->lastError === null, 'draw-reset: previous drawer may nudge new drawer');
    assert_true(count($p2->sentOfType('nudge_received')) === 1, 'draw-reset: new drawer got nudge_received');
}

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
