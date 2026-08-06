<?php

declare(strict_types=1);

/**
 * EPIC-8.6 — Reconnect & AFK tests
 * Run: php tests/manual/test_reconnect.php
 */

require_once __DIR__ . '/mock_timer.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Helpers.php';

use Lotto\Game\ReconnectService;
use Lotto\Game\GameFinishService;
use Lotto\Game\GameService;
use Lotto\Game\GameTurnService;
use Lotto\Game\LottoEngine;
use Lotto\Game\VictoryService;
use Lotto\Game\ApartmentService;

$passed = 0;
$failed = 0;

function ok(string $label): void { global $passed; $passed++; echo "[PASS] {$label}\n"; }
function fail(string $label, string $reason = ''): void { global $failed; $failed++; echo "[FAIL] {$label}" . ($reason ? " — {$reason}" : '') . "\n"; }
function assert_true(bool $cond, string $label, string $reason = ''): void { $cond ? ok($label) : fail($label, $reason); }

class MockConnection
{
    public int $id;
    public ?int $userId;
    public string $username;
    public ?string $sessionToken;
    public array $sent = [];

    public function __construct(int $id, int $userId, string $username, string $token = '')
    {
        $this->id = $id;
        $this->userId = $userId;
        $this->username = $username;
        $this->sessionToken = $token;
    }

    public function send(string $data): void
    {
        $this->sent[] = json_decode($data, true);
    }

    public function sentOfType(string $type): array
    {
        return array_values(array_filter($this->sent, fn($p) => ($p['type'] ?? '') === $type));
    }
}

class MockWorker
{
    public array $rooms = [];
    public array $userConnections = [];
}

class MockLogger
{
    public function info(string $msg): void {}
    public function warning(string $msg): void {}
    public function error(string $msg): void {}
}

class MockLobbyService
{
    public array $removed = [];

    public function removePlayerFromLobby(object $worker, int $roomId, int $connId, string $reason): void
    {
        $this->removed[] = [$roomId, $connId, $reason];
        unset($worker->rooms[$roomId]['players'][$connId]);
    }
}

class MockGameService
{
    public int $drawCalls = 0;
    public int $finishCalls = 0;
    public int $nextDrawerCalls = 0;
    public int $yourTurnCalls = 0;

    public function handleDrawBarrel(object $connection, object $worker, bool $fromAutoDraw = false): void
    {
        $this->drawCalls++;
    }

    public function startTurn(array &$room, object $worker, int $roomId): void
    {
        $this->yourTurnCalls++;
    }

    public function finishGame(
        array &$room,
        int $roomId,
        array $winners,
        array $prizes,
        object $worker,
        string $reason = 'victory'
    ): void {
        $this->finishCalls++;
        $this->lastHistory = $room['all_players_history'] ?? null;
        unset($worker->rooms[$roomId]);
    }

    public function nextDrawer(array &$room): void
    {
        $this->nextDrawerCalls++;
    }

    public function sendYourTurn(array &$room): void
    {
        $this->yourTurnCalls++;
    }

    public int $noSurvivorsCalls = 0;
    public ?array $lastHistory = null;

    public function handleNoSurvivors(array &$room, int $roomId, object $worker, ?object $notifyConnection = null): void
    {
        $this->noSurvivorsCalls++;
        unset($worker->rooms[$roomId]);
    }

    public function calculateWinChances(array $players): array
    {
        return (new VictoryService())->calculateWinChances($players);
    }
}

function makePlayer(MockConnection $conn, string $status = 'active'): array
{
    return [
        'user_id' => $conn->userId,
        'username' => $conn->username,
        'cards' => [[[null]]],
        'masks' => [[[false]]],
        'cards_count' => 1,
        'total_paid' => 10,
        'last_action' => time(),
        'afk_start' => null,
        'strikes' => 0,
        'auto_draws' => 0,
        'status' => $status,
        'session_token' => (string)$conn->sessionToken,
        'reconnect_timer' => null,
        'connection' => $conn,
        'immune' => false,
    ];
}

class MockPDO {
    public bool $committed = false;
    public bool $rolledBack = false;
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
                public function execute(array $p): void { $this->p->updates[] = ['coins' => $p[0], 'user_id' => $p[1]]; }
            };
        }
        if ($key === 'add_user_coins') {
            return new class($parent) {
                private object $p;
                public function __construct(object $p) { $this->p = $p; }
                public function execute(array $p): void { $this->p->updates[] = ['add' => $p[0], 'user_id' => $p[1]]; }
            };
        }
        throw new \InvalidArgumentException("Unknown: $key");
    }
}

function makeRefundGameService(MockPDO $pdo, MockStmts $st): object
{
    $db  = new MockDatabase($pdo);
    $log = new MockLogger();
    $fin = new GameFinishService($db, $st, $log);
    return new class($fin) extends MockGameService {
        private GameFinishService $fin;
        public ?array $lastHistory = null;
        public function __construct(GameFinishService $fin) { $this->fin = $fin; }
    public function handleNoSurvivors(array &$room, int $roomId, object $worker, ?object $notifyConnection = null): void
    {
        $this->noSurvivorsCalls++;
        $self = $this;
        $this->fin->handleNoSurvivors($room, $roomId, function () use ($worker, $roomId, $self) {
            if (isset($worker->rooms[$roomId])) {
                $self->lastHistory = $worker->rooms[$roomId]['all_players_history'] ?? null;
            }
            unset($worker->rooms[$roomId]);
        }, $notifyConnection);
    }
    };
}

function makeRoom(int $roomId, int $hostConnId): array
{
    return [
        'room_id' => $roomId,
        'host_conn_id' => $hostConnId,
        'bet_per_card' => 10,
        'max_players' => 10,
        'password_hash' => null,
        'status' => 'playing',
        'bank' => 20,
        'apartment_fired' => false,
        'pause_for_apartment' => false,
        'apartment_responses' => [],
        'game_afk_timer_id' => null,
        'apartment_timer_id' => null,
        'lobby_afk_timer_id' => null,
        'active_drawer_conn_id' => $hostConnId,
        'drawer_order' => [$hostConnId],
        'bag' => range(1, 90),
        'drawn_numbers' => [],
        'players' => [],
        'all_players_history' => [],
    ];
}

// ---------------------------------------------------------------------------
// GROUP 1: handleDisconnect -> disconnected + reconnect timer
// ---------------------------------------------------------------------------
{
    \MockTimer::reset();
    $worker = new MockWorker();
    $lobby = new MockLobbyService();
    $game = new MockGameService();
    $svc = new ReconnectService($lobby, $game, new MockLogger());

    $conn = new MockConnection(1, 10, 'host', 'tok-1');
    $room = makeRoom(1, 1);
    $room['status'] = 'playing';
    $room['players'][1] = makePlayer($conn, 'active');
    $worker->rooms[1] = $room;

    $svc->handleDisconnect($conn, $worker);

    assert_true($worker->rooms[1]['players'][1]['status'] === 'disconnected', 'disconnect: status -> disconnected');
    assert_true(!empty($worker->rooms[1]['players'][1]['reconnect_timer']), 'disconnect: reconnect timer created');
}

// ---------------------------------------------------------------------------
// GROUP 1c: playing disconnect broadcasts player_status_changed to room
// ---------------------------------------------------------------------------
{
    \MockTimer::reset();
    $worker = new MockWorker();
    $lobby = new MockLobbyService();
    $game = new MockGameService();
    $svc = new ReconnectService($lobby, $game, new MockLogger());

    $conn = new MockConnection(16, 160, 'dc16', 'tok-16');
    $mate = new MockConnection(17, 170, 'mate17', 'tok-17');
    $room = makeRoom(16, 16);
    $room['status'] = 'playing';
    $room['players'][16] = makePlayer($conn, 'active');
    $room['players'][17] = makePlayer($mate, 'active');
    $worker->rooms[16] = $room;

    $svc->handleDisconnect($conn, $worker);

    $statusPkts = $mate->sentOfType('player_status_changed');
    assert_true(count($statusPkts) === 1, 'disconnect broadcast: player_status_changed sent to mate');
    assert_true(($statusPkts[0]['username'] ?? '') === 'dc16', 'disconnect broadcast: username correct');
    assert_true(($statusPkts[0]['status'] ?? '') === 'disconnected', 'disconnect broadcast: status=disconnected');
    $selfPkts = $conn->sentOfType('player_status_changed');
    assert_true(count($selfPkts) === 0, 'disconnect broadcast: disconnected player not notified (inactive)');
}

// ---------------------------------------------------------------------------
// GROUP 1b: waiting disconnect -> immediate removePlayerFromLobby (no reconnect)
// ---------------------------------------------------------------------------
{
    \MockTimer::reset();
    $worker = new MockWorker();
    $lobby = new MockLobbyService();
    $game = new MockGameService();
    $svc = new ReconnectService($lobby, $game, new MockLogger());

    $conn = new MockConnection(12, 120, 'lobby', 'tok-12');
    $room = makeRoom(12, 12);
    $room['status'] = 'waiting';
    $room['players'][12] = makePlayer($conn, 'active');
    $worker->rooms[12] = $room;

    $svc->handleDisconnect($conn, $worker);

    assert_true(count($lobby->removed) === 1, 'waiting disconnect: immediate removePlayerFromLobby');
    assert_true($lobby->removed[0][2] === 'disconnect', 'waiting disconnect: reason=disconnect');
    assert_true(!isset($worker->rooms[12]['players'][12]), 'waiting disconnect: player removed from room');
}

// ---------------------------------------------------------------------------
// GROUP 2: playing reconnect timer expiry -> removePlayerFromGame
// ---------------------------------------------------------------------------
{
    \MockTimer::reset();
    $worker = new MockWorker();
    $lobby = new MockLobbyService();
    $game = new MockGameService();
    $svc = new ReconnectService($lobby, $game, new MockLogger());

    $conn = new MockConnection(2, 20, 'w', 'tok-2');
    $mate = new MockConnection(3, 30, 'mate', 'tok-3');
    $third = new MockConnection(4, 40, 'third', 'tok-4');
    $room = makeRoom(2, 2);
    $room['drawer_order'] = [2, 3, 4];
    $room['status'] = 'playing';
    $room['players'][2] = makePlayer($conn, 'active');
    $room['players'][3] = makePlayer($mate, 'active');
    $room['players'][4] = makePlayer($third, 'active');
    $worker->rooms[2] = $room;

    $svc->handleDisconnect($conn, $worker);
    $timerId = $worker->rooms[2]['players'][2]['reconnect_timer'];
    $cb = \MockTimer::$active[$timerId]['cb'];
    $cb();

    assert_true(!isset($worker->rooms[2]['players'][2]), 'reconnect timeout playing: disconnected player removed');
    assert_true(isset($worker->rooms[2]['players'][3]), 'reconnect timeout playing: other players remain');
    assert_true(isset($worker->rooms[2]['players'][4]), 'reconnect timeout playing: third player remains');
}

// ---------------------------------------------------------------------------
// GROUP 3: handleReconnect -> restore active + reconnect_state
// ---------------------------------------------------------------------------
{
    \MockTimer::reset();
    $worker = new MockWorker();
    $lobby = new MockLobbyService();
    $game = new MockGameService();
    $svc = new ReconnectService($lobby, $game, new MockLogger());

    $oldConn = new MockConnection(3, 30, 'p3', 'tok-3');
    $newConn = new MockConnection(103, 0, 'new');
    $room = makeRoom(3, 3);
    $room['status'] = 'playing';
    $room['drawn_numbers'] = [5, 10];
    $room['players'][3] = makePlayer($oldConn, 'disconnected');
    $worker->rooms[3] = $room;

    $result = $svc->handleReconnect('tok-3', $newConn, $worker);

    assert_true($result === true, 'reconnect: success=true');
    assert_true(!isset($worker->rooms[3]['players'][3]), 'reconnect (FIX-9): старый conn_id-ключ удалён из players');
    assert_true($worker->rooms[3]['players'][103]['status'] === 'active', 'reconnect (FIX-9): запись перенесена на новый conn_id, статус restored');
    assert_true($worker->rooms[3]['players'][103]['connection'] === $newConn, 'reconnect (FIX-9): connection указывает на новое соединение');
    assert_true($worker->rooms[3]['host_conn_id'] === 103, 'reconnect (FIX-9): host_conn_id переиндексирован (был disconnected хостом)');
    assert_true($worker->rooms[3]['active_drawer_conn_id'] === 103, 'reconnect (FIX-9): active_drawer_conn_id переиндексирован');
    assert_true($worker->rooms[3]['drawer_order'] === [103], 'reconnect (FIX-9): drawer_order переиндексирован');
    assert_true($worker->userConnections[30] === $newConn, 'reconnect: userConnections updated');
    $statePackets = $newConn->sentOfType('reconnect_state');
    assert_true(count($statePackets) === 1, 'reconnect: reconnect_state sent');
    assert_true($statePackets[0]['status'] === 'playing', 'reconnect_state: status=playing');
    assert_true($statePackets[0]['drawn_all'] === [5, 10], 'reconnect_state: drawn_all restored');
    assert_true(isset($statePackets[0]['win_chances']), 'reconnect_state: win_chances present');
    assert_true(is_array($statePackets[0]['win_chances']), 'reconnect_state: win_chances is map');
    assert_true($worker->rooms[3]['players'][103]['afk_start'] !== null, 'reconnect: afk_start armed for active drawer');
    assert_true((time() - (int)$worker->rooms[3]['players'][103]['afk_start']) <= 2, 'reconnect: afk_start freshly set');
}

// ---------------------------------------------------------------------------
// GROUP 3b: active player refresh (new WS before disconnect processed)
// ---------------------------------------------------------------------------
{
    \MockTimer::reset();
    $worker = new MockWorker();
    $lobby = new MockLobbyService();
    $game = new MockGameService();
    $svc = new ReconnectService($lobby, $game, new MockLogger());

    $oldConn = new MockConnection(6, 60, 'p6', 'tok-6');
    $newConn = new MockConnection(106, 0, 'new6');
    $room = makeRoom(6, 6);
    $room['status'] = 'waiting';
    $room['host_conn_id'] = 6;
    $room['players'][6] = makePlayer($oldConn, 'active');
    $room['players'][7] = makePlayer(new MockConnection(7, 61, 'p7'), 'active');
    $worker->rooms[6] = $room;

    $result = $svc->handleReconnect('tok-6', $newConn, $worker);

    assert_true($result === true, 'reconnect refresh: success=true for active player');
    assert_true(!isset($worker->rooms[6]['players'][6]), 'reconnect refresh: old conn_id removed');
    assert_true($worker->rooms[6]['players'][106]['status'] === 'active', 'reconnect refresh: player active on new conn');
    assert_true($worker->rooms[6]['host_conn_id'] === 106, 'reconnect refresh: host_conn_id re-keyed');
    $statePackets = $newConn->sentOfType('reconnect_state');
    assert_true(count($statePackets) === 1, 'reconnect refresh: reconnect_state sent');
    assert_true(($statePackets[0]['host'] ?? '') === 'p6', 'reconnect refresh: host restored in packet');
    assert_true(count($statePackets[0]['players'] ?? []) === 2, 'reconnect refresh: players list restored');
}

// ---------------------------------------------------------------------------
// GROUP 3c: adoptSessionTokenForUser — login after browser close
// ---------------------------------------------------------------------------
{
    \MockTimer::reset();
    $worker = new MockWorker();
    $lobby = new MockLobbyService();
    $game = new MockGameService();
    $svc = new ReconnectService($lobby, $game, new MockLogger());

    $oldConn = new MockConnection(8, 80, 'p8', 'old-token-8');
    $newConn = new MockConnection(108, 0, 'new8');
    $room = makeRoom(8, 8);
    $room['status'] = 'playing';
    $room['players'][8] = makePlayer($oldConn, 'disconnected');
    $worker->rooms[8] = $room;

    $svc->adoptSessionTokenForUser($worker, 80, 'new-token-8');
    assert_true(
        $worker->rooms[8]['players'][8]['session_token'] === 'new-token-8',
        'adopt token: room player session_token updated'
    );

    $result = $svc->handleReconnect('new-token-8', $newConn, $worker);
    assert_true($result === true, 'adopt token: reconnect succeeds with new token');
    assert_true($worker->rooms[8]['players'][108]['status'] === 'active', 'adopt token: player restored on new conn');
}

// ---------------------------------------------------------------------------
// GROUP 3d: reconnect as active drawer restores turn fields in reconnect_state
// ---------------------------------------------------------------------------
{
    \MockTimer::reset();
    $worker = new MockWorker();
    $lobby = new MockLobbyService();
    $game = new MockGameService();
    $svc = new ReconnectService($lobby, $game, new MockLogger());

    $oldDrawer = new MockConnection(9, 90, 'drawer9', 'tok-9');
    $mate = new MockConnection(10, 91, 'mate10');
    $newConn = new MockConnection(109, 0, 'new9');
    $room = makeRoom(9, 9);
    $room['status'] = 'playing';
    $room['active_drawer_conn_id'] = 9;
    $room['drawer_order'] = [9, 10];
    $room['players'][9] = makePlayer($oldDrawer, 'disconnected');
    $room['players'][9]['afk_start'] = time() - 5;
    $room['players'][9]['auto_draws'] = 0;
    $room['players'][10] = makePlayer($mate, 'active');
    $worker->rooms[9] = $room;

    $result = $svc->handleReconnect('tok-9', $newConn, $worker);
    assert_true($result === true, 'reconnect drawer: success=true');
    $statePackets = $newConn->sentOfType('reconnect_state');
    assert_true(count($statePackets) === 1, 'reconnect drawer: reconnect_state sent');
    $pkt = $statePackets[0];
    assert_true(($pkt['is_my_turn'] ?? false) === true, 'reconnect drawer: is_my_turn=true');
    assert_true(isset($pkt['afk_start']), 'reconnect drawer: afk_start present');
    assert_true(
        (int) ($pkt['turn_seconds'] ?? 0) === \Lotto\Core\Constants::gameAfkStrikeWindowSeconds(0),
        'reconnect drawer: turn_seconds matches strike1 window'
    );
    assert_true((int) ($pkt['auto_draws'] ?? -1) === 0, 'reconnect drawer: auto_draws=0');

    \MockTimer::reset();
    $worker2 = new MockWorker();
    $svc2 = new ReconnectService($lobby, $game, new MockLogger());

    $oldMate = new MockConnection(11, 110, 'mate11', 'tok-11');
    $drawer = new MockConnection(12, 120, 'drawer12');
    $newMate = new MockConnection(111, 0, 'new11');
    $room2 = makeRoom(11, 11);
    $room2['status'] = 'playing';
    $room2['active_drawer_conn_id'] = 12;
    $room2['drawer_order'] = [12, 11];
    $room2['players'][11] = makePlayer($oldMate, 'disconnected');
    $room2['players'][12] = makePlayer($drawer, 'active');
    $room2['players'][12]['afk_start'] = time();
    $worker2->rooms[11] = $room2;

    $result2 = $svc2->handleReconnect('tok-11', $newMate, $worker2);
    assert_true($result2 === true, 'reconnect non-drawer: success=true');
    $pkt2 = $newMate->sentOfType('reconnect_state')[0] ?? [];
    assert_true(($pkt2['is_my_turn'] ?? true) === false, 'reconnect non-drawer: is_my_turn=false');
    assert_true(!isset($pkt2['afk_start']), 'reconnect non-drawer: no afk_start');
    assert_true(!isset($pkt2['turn_seconds']), 'reconnect non-drawer: no turn_seconds');
    assert_true(!isset($pkt2['auto_draws']), 'reconnect non-drawer: no auto_draws');
}

// ---------------------------------------------------------------------------
// GROUP 3f: reconnect from disconnected broadcasts player_status_changed
// ---------------------------------------------------------------------------
{
    \MockTimer::reset();
    $worker = new MockWorker();
    $lobby = new MockLobbyService();
    $game = new MockGameService();
    $svc = new ReconnectService($lobby, $game, new MockLogger());

    $oldConn = new MockConnection(18, 180, 'recon18', 'tok-18');
    $mate = new MockConnection(19, 190, 'mate19', 'tok-19');
    $newConn = new MockConnection(118, 0, 'new18');
    $room = makeRoom(18, 18);
    $room['status'] = 'playing';
    $room['drawer_order'] = [18, 19];
    $room['players'][18] = makePlayer($oldConn, 'disconnected');
    $room['players'][19] = makePlayer($mate, 'active');
    $worker->rooms[18] = $room;

    $result = $svc->handleReconnect('tok-18', $newConn, $worker);
    assert_true($result === true, 'reconnect status broadcast: success=true');
    $statusPkts = $mate->sentOfType('player_status_changed');
    assert_true(count($statusPkts) === 1, 'reconnect status broadcast: player_status_changed sent to mate');
    assert_true(($statusPkts[0]['username'] ?? '') === 'recon18', 'reconnect status broadcast: username correct');
    assert_true(($statusPkts[0]['status'] ?? '') === 'active', 'reconnect status broadcast: status=active');
}

// ---------------------------------------------------------------------------
// GROUP 3e: reconnect into playing room with removed player — players roster
// ---------------------------------------------------------------------------
{
    \MockTimer::reset();
    $worker = new MockWorker();
    $lobby = new MockLobbyService();
    $game = new MockGameService();
    $svc = new ReconnectService($lobby, $game, new MockLogger());

    $removed = new MockConnection(13, 130, 'removed13', 'tok-13');
    $survivor = new MockConnection(14, 140, 'survivor14', 'tok-14');
    $reconnect = new MockConnection(15, 150, 'reconnect15', 'tok-15');
    $newConn = new MockConnection(115, 0, 'new15');
    $room = makeRoom(13, 14);
    $room['status'] = 'playing';
    $room['drawer_order'] = [14, 13, 15];
    $room['active_drawer_conn_id'] = 14;
    $room['players'][13] = makePlayer($removed, 'active');
    $room['players'][14] = makePlayer($survivor, 'active');
    $room['players'][14]['cards_count'] = 2;
    $room['players'][15] = makePlayer($reconnect, 'active');
    $room['players'][15]['cards_count'] = 1;
    $worker->rooms[13] = $room;

    $svc->removePlayerFromGame($worker, 13, 13, 'afk');

    assert_true(!isset($worker->rooms[13]['players'][13]), 'roster ghost: removed player absent from players');
    assert_true(isset($worker->rooms[13]), 'roster ghost: game continues with 2 active players');
    assert_true(
        ($worker->rooms[13]['all_players_history'][13]['reason'] ?? '') === 'afk',
        'roster ghost: history reason=afk'
    );
    assert_true(
        ($worker->rooms[13]['all_players_history'][13]['cards_count'] ?? -1) === 1,
        'roster ghost: history cards_count=1'
    );

    $svc->handleDisconnect($reconnect, $worker);
    assert_true($worker->rooms[13]['players'][15]['status'] === 'disconnected', 'roster ghost: reconnect target disconnected');

    $result = $svc->handleReconnect('tok-15', $newConn, $worker);
    assert_true($result === true, 'roster ghost: reconnect success');
    $pkt = $newConn->sentOfType('reconnect_state')[0] ?? [];
    assert_true(isset($pkt['players']), 'roster ghost: players key present');
    $players = $pkt['players'];
    assert_true(count($players) === 3, 'roster ghost: 3 roster entries (2 present + 1 ghost)');

    $byName = [];
    foreach ($players as $p) {
        $byName[$p['username']] = $p;
    }
    assert_true(isset($byName['survivor14']), 'roster ghost: survivor present');
    assert_true(($byName['survivor14']['status'] ?? '') === 'active', 'roster ghost: survivor status=active');
    assert_true(($byName['survivor14']['cards_count'] ?? -1) === 2, 'roster ghost: survivor cards_count=2');
    assert_true(isset($byName['reconnect15']), 'roster ghost: reconnecting player present');
    assert_true(($byName['reconnect15']['status'] ?? '') === 'active', 'roster ghost: reconnecting player status=active after restore');
    assert_true(isset($byName['removed13']), 'roster ghost: removed player as ghost');
    assert_true(($byName['removed13']['status'] ?? '') === 'removed', 'roster ghost: ghost status=removed');
    assert_true(($byName['removed13']['reason'] ?? '') === 'afk', 'roster ghost: ghost reason echoes history');
    assert_true(($byName['removed13']['cards_count'] ?? -1) === 1, 'roster ghost: ghost cards_count from history');
}

// ---------------------------------------------------------------------------
// GROUP 4: game AFK timer — strike 1 auto-draw (player stays)
// ---------------------------------------------------------------------------
{
    \MockTimer::reset();
    $worker = new MockWorker();
    $lobby = new MockLobbyService();
    $game = new MockGameService();
    $svc = new ReconnectService($lobby, $game, new MockLogger());

    $conn = new MockConnection(4, 40, 'afk');
    $room = makeRoom(4, 4);
    $room['status'] = 'playing';
    $room['players'][4] = makePlayer($conn, 'active');
    $room['players'][4]['afk_start'] = time() - 29;
    $room['players'][4]['auto_draws'] = 0;
    $worker->rooms[4] = $room;

    $svc->ensureGameAfkTimer($worker, 4);
    assert_true(!empty($worker->rooms[4]['game_afk_timer_id']), 'game afk: timer created once');

    $svc->tickGameAfk($worker, 4);
    assert_true($game->drawCalls === 0, 'game afk strike1: no draw before 30s');
    $worker->rooms[4]['players'][4]['afk_start'] = time() - 30;
    $svc->tickGameAfk($worker, 4);
    assert_true($game->drawCalls === 1, 'game afk: auto-draw on strike 1');
    assert_true($worker->rooms[4]['players'][4]['auto_draws'] === 1, 'game afk: auto_draws=1 after strike 1');
    assert_true(isset($worker->rooms[4]['players'][4]), 'game afk: player stays after strike 1');
    $warnings = $conn->sentOfType('afk_warning');
    assert_true(count($warnings) === 1, 'game afk: warning packet sent');
    assert_true(($warnings[0]['strike'] ?? null) === 1, 'game afk: warning strike=1');
    assert_true(($warnings[0]['turn_seconds'] ?? null) === 30, 'game afk: turn_seconds=30');
}

// ---------------------------------------------------------------------------
// GROUP 4b: second AFK strike — auto-draw (player stays)
// ---------------------------------------------------------------------------
{
    \MockTimer::reset();
    $worker = new MockWorker();
    $lobby = new MockLobbyService();
    $game = new MockGameService();
    $svc = new ReconnectService($lobby, $game, new MockLogger());

    $conn = new MockConnection(44, 440, 'afk2');
    $room = makeRoom(44, 44);
    $room['status'] = 'playing';
    $room['players'][44] = makePlayer($conn, 'active');
    $room['players'][44]['auto_draws'] = 1;
    $room['players'][44]['afk_start'] = time() - 14;
    $worker->rooms[44] = $room;

    $svc->tickGameAfk($worker, 44);
    assert_true($game->drawCalls === 0, 'game afk strike2: no draw before 15s');
    $worker->rooms[44]['players'][44]['afk_start'] = time() - 15;
    $svc->tickGameAfk($worker, 44);
    assert_true($game->drawCalls === 1, 'game afk: auto-draw on strike 2');
    assert_true($worker->rooms[44]['players'][44]['auto_draws'] === 2, 'game afk: auto_draws=2 after strike 2');
    assert_true(isset($worker->rooms[44]['players'][44]), 'game afk: player stays after strike 2');
    $warnings = $conn->sentOfType('afk_warning');
    assert_true(count($warnings) === 1, 'game afk: second warning sent');
    assert_true(($warnings[0]['strike'] ?? null) === 2, 'game afk: warning strike=2');
    assert_true(($warnings[0]['turn_seconds'] ?? null) === 15, 'game afk: strike2 turn_seconds=15');
}

// ---------------------------------------------------------------------------
// GROUP 4c: your_turn turn_seconds reflects auto_draws stage
// ---------------------------------------------------------------------------
{
    \MockTimer::reset();
    $conn = new MockConnection(440, 4400, 'turn_pkt');
    $room = makeRoom(440, 440);
    $room['status'] = 'playing';
    $room['active_drawer_conn_id'] = 440;
    $room['players'][440] = makePlayer($conn, 'active');
    $room['players'][440]['auto_draws'] = 1;

    $pdo = new MockPDO();
    $db = new MockDatabase($pdo);
    $st = new MockStmts();
    $log = new MockLogger();
    $vic = new VictoryService();
    $apt = new ApartmentService($db, $st, $log);
    $fin = new GameFinishService($db, $st, $log);
    $turn = new GameTurnService($log, $vic, $apt, $fin);
    $gs = new GameService($db, $st, new LottoEngine(), $log, $vic, $apt, $fin, $turn);
    $gs->sendYourTurn($room, false);

    $turnPkts = $conn->sentOfType('your_turn');
    assert_true(count($turnPkts) === 1, 'your_turn: packet sent');
    assert_true(($turnPkts[0]['turn_seconds'] ?? null) === 15, 'your_turn: turn_seconds=15 when auto_draws=1');
}

// ---------------------------------------------------------------------------
// GROUP 5: strike 3 removal — engaged last survivor (survivor auto_draws=0)
// ---------------------------------------------------------------------------
{
    \MockTimer::reset();
    $worker = new MockWorker();
    $lobby = new MockLobbyService();
    $game = new MockGameService();
    $svc = new ReconnectService($lobby, $game, new MockLogger());

    $conn = new MockConnection(5, 50, 'afk_out');
    $conn2 = new MockConnection(6, 51, 'survivor');
    $room = makeRoom(5, 5);
    $room['status'] = 'playing';
    $room['players'][5] = makePlayer($conn, 'active');
    $room['players'][6] = makePlayer($conn2, 'active');
    $room['drawer_order'] = [5, 6];
    $room['active_drawer_conn_id'] = 5;
    $room['players'][5]['afk_start'] = time() - 4;
    $room['players'][5]['auto_draws'] = 2;
    assert_true($room['players'][6]['auto_draws'] === 0, 'afk strike3: survivor never auto-drawn');
    $worker->rooms[5] = $room;

    $svc->tickGameAfk($worker, 5);
    assert_true(isset($worker->rooms[5]['players'][5]), 'afk strike3: no removal before 5s');
    $worker->rooms[5]['players'][5]['afk_start'] = time() - 5;
    $svc->tickGameAfk($worker, 5);
    assert_true($game->drawCalls === 0, 'afk strike3: no auto draw');
    $leftPkts = $conn->sentOfType('player_left');
    assert_true(count($leftPkts) === 1, 'afk strike3: removed player notified');
    assert_true(($leftPkts[0]['reason'] ?? '') === 'afk', 'afk strike3: removed player reason=afk');
    assert_true(!isset($worker->rooms[5]), 'afk strike3: engaged survivor ends room');
    assert_true($game->finishCalls === 1, 'afk strike3: last_survivor finishGame');
    assert_true($game->noSurvivorsCalls === 0, 'afk strike3: no refund when survivor engaged');
}

// ---------------------------------------------------------------------------
// GROUP 5b: AFK cascade — both idle (survivor auto_draws>0) → no_survivors
// ---------------------------------------------------------------------------
{
    \MockTimer::reset();
    $worker = new MockWorker();
    $lobby = new MockLobbyService();
    $game = new MockGameService();
    $svc = new ReconnectService($lobby, $game, new MockLogger());

    $afk = new MockConnection(51, 510, 'afk_out');
    $idle = new MockConnection(52, 520, 'idle_survivor');
    $room = makeRoom(51, 51);
    $room['status'] = 'playing';
    $room['bank'] = 20;
    $room['players'][51] = makePlayer($afk, 'active');
    $room['players'][51]['auto_draws'] = 2;
    $room['players'][52] = makePlayer($idle, 'active');
    $room['players'][52]['auto_draws'] = 1;
    $room['drawer_order'] = [51, 52];
    $worker->rooms[51] = $room;

    $svc->removePlayerFromGame($worker, 51, 51, 'afk');

    assert_true($game->noSurvivorsCalls === 1, 'afk both-idle: handleNoSurvivors called');
    assert_true($game->finishCalls === 0, 'afk both-idle: no last_survivor payout');
    assert_true(!isset($worker->rooms[51]), 'afk both-idle: room destroyed');
}

// ---------------------------------------------------------------------------
// GROUP 5c: AFK both-idle — survivor refunded via snapshot (integration)
// ---------------------------------------------------------------------------
{
    \MockTimer::reset();
    $worker = new MockWorker();
    $lobby = new MockLobbyService();
    $pdo = new MockPDO();
    $st = new MockStmts([
        510 => ['coins' => 100],
        520 => ['coins' => 200],
    ]);
    $game = makeRefundGameService($pdo, $st);
    $svc = new ReconnectService($lobby, $game, new MockLogger());

    $afk = new MockConnection(53, 510, 'afk_out');
    $idle = new MockConnection(54, 520, 'idle_survivor');
    $room = makeRoom(53, 53);
    $room['status'] = 'playing';
    $room['bank'] = 20;
    $room['players'][53] = makePlayer($afk, 'active');
    $room['players'][53]['total_paid'] = 10;
    $room['players'][53]['auto_draws'] = 2;
    $room['players'][54] = makePlayer($idle, 'active');
    $room['players'][54]['total_paid'] = 10;
    $room['players'][54]['auto_draws'] = 1;
    $worker->rooms[53] = $room;

    $svc->removePlayerFromGame($worker, 53, 53, 'afk');

    assert_true($game->noSurvivorsCalls === 1, 'afk both-idle refund: handleNoSurvivors');
    assert_true($game->finishCalls === 0, 'afk both-idle refund: no last_survivor');
    assert_true(!isset($worker->rooms[53]), 'afk both-idle refund: room destroyed');
    assert_true($pdo->committed === true, 'afk both-idle refund: transaction committed');
    assert_true(count($st->updates) === 2, 'afk both-idle refund: both players refunded');
    assert_true($st->updates[0]['add'] === 10, 'afk both-idle refund: removed player stake');
    assert_true($st->updates[1]['add'] === 10, 'afk both-idle refund: survivor stake');
    $go = $idle->sentOfType('game_over');
    assert_true(count($go) === 1, 'afk both-idle refund: game_over to survivor');
    assert_true(($go[0]['reason'] ?? '') === 'no_survivors', 'afk both-idle refund: reason=no_survivors');
    assert_true(($go[0]['prize'] ?? -1) === 0, 'afk both-idle refund: no prize');
    assert_true(($go[0]['winner'] ?? 'x') === '', 'afk both-idle refund: no winner');
}

// ---------------------------------------------------------------------------
// GROUP 5d: non-afk last survivor — auto_draws on survivor ignored (ADR-013)
// ---------------------------------------------------------------------------
{
    \MockTimer::reset();
    $worker = new MockWorker();
    $lobby = new MockLobbyService();
    $game = new MockGameService();
    $svc = new ReconnectService($lobby, $game, new MockLogger());

    foreach (['leave', 'disconnect', 'kicked', 'banned'] as $reason) {
        $roomId = 60 + crc32($reason) % 10;
        $leaverConn = $roomId;
        $winnerConn = $roomId + 1;
        $leaver = new MockConnection($leaverConn, $leaverConn * 10, "out_{$reason}");
        $winner = new MockConnection($winnerConn, $winnerConn * 10, "win_{$reason}");
        $room = makeRoom($roomId, $leaverConn);
        $room['status'] = 'playing';
        $room['bank'] = 50;
        $room['players'][$leaverConn] = makePlayer($leaver, 'active');
        $room['players'][$winnerConn] = makePlayer($winner, 'active');
        $room['players'][$winnerConn]['auto_draws'] = 2;
        $worker->rooms[$roomId] = $room;

        $svc->removePlayerFromGame($worker, $roomId, $leaverConn, $reason);

        assert_true(
            ($game->lastHistory[$leaverConn]['cards_count'] ?? -1) === 1,
            "{$reason}: history cards_count=1"
        );
        assert_true(
            ($game->lastHistory[$leaverConn]['reason'] ?? '') === $reason,
            "{$reason}: history reason matches removal"
        );
        $game->lastHistory = null;

        assert_true($game->finishCalls >= 1, "{$reason}: last_survivor still pays bank");
        assert_true($game->noSurvivorsCalls === 0, "{$reason}: no refund path");
        assert_true(!isset($worker->rooms[$roomId]), "{$reason}: room destroyed");
        $game->finishCalls = 0;
    }
}

// ---------------------------------------------------------------------------
// GROUP 6: strike 3 removal with 3 players — game continues
// ---------------------------------------------------------------------------
{
    \MockTimer::reset();
    $worker = new MockWorker();
    $lobby = new MockLobbyService();
    $game = new MockGameService();
    $svc = new ReconnectService($lobby, $game, new MockLogger());

    $c5 = new MockConnection(55, 550, 'afk_drawer');
    $c6 = new MockConnection(56, 560, 'p6');
    $c7 = new MockConnection(57, 570, 'p7');
    $room = makeRoom(55, 55);
    $room['status'] = 'playing';
    $room['players'][55] = makePlayer($c5, 'active');
    $room['players'][56] = makePlayer($c6, 'active');
    $room['players'][57] = makePlayer($c7, 'active');
    $room['drawer_order'] = [55, 56, 57];
    $room['active_drawer_conn_id'] = 55;
    $room['players'][55]['afk_start'] = time() - 5;
    $room['players'][55]['auto_draws'] = 2;
    $worker->rooms[55] = $room;

    $svc->tickGameAfk($worker, 55);
    assert_true($game->drawCalls === 0, 'afk remove: no auto draw on strike3');
    $leftPkts = $c5->sentOfType('player_left');
    assert_true(count($leftPkts) === 1, 'afk remove: removed player notified');
    assert_true(($leftPkts[0]['reason'] ?? '') === 'afk', 'afk remove: removed player reason=afk');
    assert_true(!isset($worker->rooms[55]['players'][55]), 'afk remove: drawer removed at strike3');
    assert_true(isset($worker->rooms[55]['players'][56]), 'afk remove: remaining players stay in room');
    assert_true($game->finishCalls === 0, 'afk remove: game continues with 2 active players');
    assert_true($game->yourTurnCalls >= 1, 'afk remove: turn passed to next drawer');
}

// ---------------------------------------------------------------------------
// GROUP 7: leave during playing — last survivor wins immediately
// ---------------------------------------------------------------------------
{
    \MockTimer::reset();
    $worker = new MockWorker();
    $lobby = new MockLobbyService();
    $game = new MockGameService();
    $svc = new ReconnectService($lobby, $game, new MockLogger());

    $leaver = new MockConnection(7, 70, 'leaver');
    $winner = new MockConnection(8, 80, 'winner');
    $room = makeRoom(7, 7);
    $room['status'] = 'playing';
    $room['bank'] = 100;
    $room['players'][7] = makePlayer($leaver, 'active');
    $room['players'][8] = makePlayer($winner, 'active');
    $room['drawer_order'] = [7, 8];
    $room['active_drawer_conn_id'] = 7;
    $worker->rooms[7] = $room;

    $svc->removePlayerFromGame($worker, 7, 7, 'leave');
    assert_true($game->finishCalls === 1, 'leave: last survivor finishes game');
    assert_true(!isset($worker->rooms[7]), 'leave: room destroyed after last survivor');
    $leaverLeft = $leaver->sentOfType('player_left');
    assert_true(count($leaverLeft) === 1, 'leave: departing player notified');
    assert_true(($leaverLeft[0]['reason'] ?? '') === 'leave', 'leave: departing player reason=leave');
}

// ---------------------------------------------------------------------------
// GROUP 8: zero active with disconnected stragglers — refund + timer cleanup
// ---------------------------------------------------------------------------
{
    \MockTimer::reset();
    $worker = new MockWorker();
    $lobby = new MockLobbyService();
    $pdo = new MockPDO();
    $st = new MockStmts([
        101 => ['coins' => 100],
        201 => ['coins' => 200],
        301 => ['coins' => 300],
    ]);
    $game = makeRefundGameService($pdo, $st);
    $svc = new ReconnectService($lobby, $game, new MockLogger());

    $c1 = new MockConnection(100, 101, 'active_last');
    $c2 = new MockConnection(200, 201, 'dc2');
    $c3 = new MockConnection(300, 301, 'dc3');
    $room = makeRoom(800, 100);
    $room['status'] = 'playing';
    $room['bank'] = 30;
    $room['drawer_order'] = [100, 200, 300];
    $room['players'][100] = makePlayer($c1, 'active');
    $room['players'][100]['total_paid'] = 10;
    $room['players'][200] = makePlayer($c2, 'disconnected');
    $room['players'][200]['total_paid'] = 10;
    $room['players'][200]['reconnect_timer'] = \MockTimer::add(15.0, fn() => null, false);
    $room['players'][300] = makePlayer($c3, 'disconnected');
    $room['players'][300]['total_paid'] = 10;
    $room['players'][300]['reconnect_timer'] = \MockTimer::add(15.0, fn() => null, false);
    $worker->rooms[800] = $room;

    $svc->removePlayerFromGame($worker, 800, 100, 'leave');

    assert_true($game->noSurvivorsCalls === 1, 'no survivors: handleNoSurvivors called');
    assert_true(!isset($worker->rooms[800]), 'no survivors: room destroyed');
    assert_true(count(\MockTimer::$active) === 0, 'no survivors: reconnect timers cancelled');
    assert_true($pdo->committed === true, 'no survivors: refund transaction committed');
    assert_true(count($st->updates) === 3, 'no survivors: all 3 players refunded');
    assert_true($st->updates[0]['add'] === 10, 'no survivors: p1 refunded');
    assert_true($st->updates[1]['add'] === 10, 'no survivors: p2 refunded');
    assert_true($st->updates[2]['add'] === 10, 'no survivors: p3 refunded');
    $go = $c2->sentOfType('game_over');
    assert_true(count($go) === 1, 'no survivors: game_over sent');
    assert_true(($go[0]['reason'] ?? '') === 'no_survivors', 'no survivors: reason=no_survivors');
    assert_true(($go[0]['prize'] ?? -1) === 0, 'no survivors: no prize');
    assert_true(($go[0]['winner'] ?? 'x') === '', 'no survivors: no winner');
    assert_true(isset($go[0]['win_chance_history']), 'no survivors: win_chance_history present');
    assert_true(is_array($go[0]['win_chance_history']), 'no survivors: win_chance_history is array');
    assert_true(is_array($game->lastHistory), 'no survivors: history captured');
    assert_true(
        ($game->lastHistory[100]['reason'] ?? '') === 'leave',
        'no survivors: leaver history reason=leave'
    );
    assert_true(
        ($game->lastHistory[100]['cards_count'] ?? -1) === 1,
        'no survivors: leaver history cards_count=1'
    );
    assert_true(
        ($game->lastHistory[200]['reason'] ?? null) === null,
        'no survivors: disconnected snapshot reason=null'
    );
    assert_true(
        ($game->lastHistory[200]['cards_count'] ?? -1) === 1,
        'no survivors: disconnected snapshot cards_count=1'
    );
}

// ---------------------------------------------------------------------------
// GROUP 8b: empty players fast-path — refund instead of silent destroy
// ---------------------------------------------------------------------------
{
    \MockTimer::reset();
    $worker = new MockWorker();
    $lobby = new MockLobbyService();
    $pdo = new MockPDO();
    $st = new MockStmts([50 => ['coins' => 500]]);
    $game = makeRefundGameService($pdo, $st);
    $svc = new ReconnectService($lobby, $game, new MockLogger());

    $solo = new MockConnection(50, 50, 'solo');
    $room = makeRoom(900, 50);
    $room['status'] = 'playing';
    $room['bank'] = 10;
    $room['players'][50] = makePlayer($solo, 'active');
    $room['players'][50]['total_paid'] = 10;
    $worker->rooms[900] = $room;

    $svc->removePlayerFromGame($worker, 900, 50, 'leave');

    assert_true($game->noSurvivorsCalls === 1, 'empty fast-path: handleNoSurvivors called');
    assert_true(!isset($worker->rooms[900]), 'empty fast-path: room destroyed');
    assert_true($pdo->committed === true, 'empty fast-path: refund committed');
    assert_true(count($st->updates) === 1, 'empty fast-path: solo player refunded');
    assert_true($st->updates[0]['add'] === 10, 'empty fast-path: stake returned');
    $go = $solo->sentOfType('game_over');
    assert_true(count($go) === 1, 'empty fast-path: game_over sent');
    assert_true(($go[0]['reason'] ?? '') === 'no_survivors', 'empty fast-path: no winner reason');
}

// ---------------------------------------------------------------------------
// RESULTS
// ---------------------------------------------------------------------------
$total = $passed + $failed;
echo "\n--- EPIC-8.6 Reconnect Test Suite ---\n";
echo "{$passed} / {$total} PASSED\n";
if ($failed > 0) {
    echo "{$failed} FAILED\n";
    exit(1);
}
exit(0);
