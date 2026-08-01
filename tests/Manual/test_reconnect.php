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

    public function handleDrawBarrel(object $connection, object $worker, bool $fromAutoDraw = false): void
    {
        $this->drawCalls++;
        // Simulate post-draw turn rotation (real GameService::nextDrawer).
        foreach ($worker->rooms as &$room) {
            if (!isset($room['players'][$connection->id])) {
                continue;
            }
            $order = $room['drawer_order'] ?? [];
            $pos = array_search($connection->id, $order, true);
            if ($pos === false) {
                break;
            }
            $count = count($order);
            for ($i = 1; $i <= $count; $i++) {
                $next = $order[($pos + $i) % $count];
                if (
                    isset($room['players'][$next])
                    && ($room['players'][$next]['status'] ?? null) === 'active'
                ) {
                    $room['active_drawer_conn_id'] = $next;
                    break;
                }
            }
            break;
        }
        unset($room);
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
// GROUP 4b: second AFK warning at 25s
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
    $room['players'][44]['afk_start'] = time() - 26;
    $room['players'][44]['strikes'] = 1;
    $worker->rooms[44] = $room;

    $svc->tickGameAfk($worker, 44);
    assert_true($worker->rooms[44]['players'][44]['strikes'] === 2, 'game afk: strike=2 at 25s');
    $warnings = $conn->sentOfType('afk_warning');
    assert_true(count($warnings) === 1, 'game afk: second warning sent');
    assert_true(($warnings[0]['strike'] ?? null) === 2, 'game afk: warning strike=2');
}

// ---------------------------------------------------------------------------
// GROUP 5: auto draw + afk removal after 3 auto draws (last survivor)
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
// GROUP 6: afk removal with 3 players — game continues
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
    $room['players'][55]['auto_draws'] = 2;
    $worker->rooms[55] = $room;

    $svc->performAutoDraw($worker, 55, 55);
    assert_true($game->drawCalls === 1, 'afk remove: auto draw executed');
    assert_true(!isset($worker->rooms[55]['players'][55]), 'afk remove: drawer removed after 3rd auto draw');
    assert_true(isset($worker->rooms[55]['players'][56]), 'afk remove: remaining players stay in room');
    assert_true($game->finishCalls === 0, 'afk remove: game continues with 2 active players');
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
