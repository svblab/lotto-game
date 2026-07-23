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

    public function handleDrawBarrel(object $connection, object $worker): void
    {
        $this->drawCalls++;
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
// GROUP 2: reconnect timer expiry -> removePlayerFromLobby for waiting
// ---------------------------------------------------------------------------
{
    \MockTimer::reset();
    $worker = new MockWorker();
    $lobby = new MockLobbyService();
    $game = new MockGameService();
    $svc = new ReconnectService($lobby, $game, new MockLogger());

    $conn = new MockConnection(2, 20, 'w', 'tok-2');
    $room = makeRoom(2, 2);
    $room['status'] = 'waiting';
    $room['players'][2] = makePlayer($conn, 'active');
    $worker->rooms[2] = $room;

    $svc->handleDisconnect($conn, $worker);
    $timerId = $worker->rooms[2]['players'][2]['reconnect_timer'];
    $cb = \MockTimer::$active[$timerId]['cb'];
    $cb();

    assert_true(count($lobby->removed) === 1, 'reconnect timeout waiting: removePlayerFromLobby called');
    assert_true($lobby->removed[0][2] === 'disconnect', 'reconnect timeout waiting: reason=disconnect');
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
}

// ---------------------------------------------------------------------------
// GROUP 4: game AFK timer warning path
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
    $room['players'][4]['afk_start'] = time() - 16;
    $worker->rooms[4] = $room;

    $svc->ensureGameAfkTimer($worker, 4);
    assert_true(!empty($worker->rooms[4]['game_afk_timer_id']), 'game afk: timer created once');

    $svc->tickGameAfk($worker, 4);
    assert_true($worker->rooms[4]['players'][4]['strikes'] === 1, 'game afk: strike=1 at 15s');
    assert_true(count($conn->sentOfType('afk_warning')) === 1, 'game afk: warning packet sent');
}

// ---------------------------------------------------------------------------
// GROUP 5: auto draw + afk removal after 3 auto draws
// ---------------------------------------------------------------------------
{
    \MockTimer::reset();
    $worker = new MockWorker();
    $lobby = new MockLobbyService();
    $game = new MockGameService();
    $svc = new ReconnectService($lobby, $game, new MockLogger());

    $conn = new MockConnection(5, 50, 'auto');
    $room = makeRoom(5, 5);
    $room['status'] = 'playing';
    $room['players'][5] = makePlayer($conn, 'active');
    $room['players'][5]['auto_draws'] = 2;
    $worker->rooms[5] = $room;

    $svc->performAutoDraw($worker, 5, 5);
    assert_true($game->drawCalls === 1, 'auto draw: delegated to draw_barrel flow');
    assert_true(!isset($worker->rooms[5]), 'afk remove: last active survivor flow finished room');
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
