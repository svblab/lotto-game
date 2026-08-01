<?php

declare(strict_types=1);

/**
 * tests/Manual/test_timer_audit.php
 *
 * EPIC-11.2 — Timer audit mock regression tests (Windows + Linux).
 *
 * Covers:
 *   - TimerAudit utility + log parsing
 *   - Constants env overrides for accelerated timeouts
 *   - RoomManager / LobbyService timer cleanup
 *   - Reconnect timer schedule + cancel on reconnect
 *   - Single-shot apartment timer fires once (MockTimer::fire)
 *
 * Run: php tests/Manual/test_timer_audit.php
 */

require_once __DIR__ . '/mock_timer.php';

require __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Helpers.php';

use Lotto\Core\Constants;
use Lotto\Core\Logger;
use Lotto\Core\RoomManager;
use Lotto\Core\TimerAudit;
use Lotto\Game\ReconnectService;
use Lotto\Lobby\LobbyService;

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

final class SpyConnection
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
}

final class MockLobbyService
{
    public array $removed = [];

    public function removePlayerFromLobby(object $worker, int $roomId, int $connId, string $reason): void
    {
        $this->removed[] = [$roomId, $connId, $reason];
        unset($worker->rooms[$roomId]['players'][$connId]);
    }
}

final class MockGameService
{
    public int $drawCalls = 0;

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
        unset($worker->rooms[$roomId]);
    }

    public function nextDrawer(array &$room): void {}
    public function sendYourTurn(array &$room): void {}
}

function makeRoom(int $roomId, int $hostConnId, string $status = 'waiting'): array
{
    return [
        'room_id' => $roomId,
        'host_conn_id' => $hostConnId,
        'bet_per_card' => 10,
        'max_players' => 10,
        'password_hash' => null,
        'status' => $status,
        'bank' => 0,
        'apartment_fired' => false,
        'pause_for_apartment' => false,
        'apartment_responses' => [],
        'game_afk_timer_id' => null,
        'apartment_timer_id' => null,
        'lobby_afk_timer_id' => null,
        'active_drawer_conn_id' => null,
        'drawer_order' => [],
        'bag' => [],
        'drawn_numbers' => [],
        'players' => [],
        'all_players_history' => [],
    ];
}

function makePlayer(SpyConnection $conn, string $status = 'active', ?int $reconnectTimer = null): array
{
    return [
        'user_id' => $conn->userId,
        'username' => $conn->username,
        'cards' => [],
        'cards_count' => 1,
        'total_paid' => 0,
        'last_action' => time(),
        'afk_start' => null,
        'strikes' => 0,
        'auto_draws' => 0,
        'status' => $status,
        'session_token' => (string) $conn->sessionToken,
        'reconnect_timer' => $reconnectTimer,
        'connection' => $conn,
        'immune' => false,
    ];
}

// =============================================================================
// GROUP 1 — TimerAudit utility
// =============================================================================

echo "GROUP 1: TimerAudit utility\n";

$auditLogPath = sys_get_temp_dir() . '/lotto_timer_audit_test_' . getmypid() . '.log';
@unlink($auditLogPath);

putenv('LOTTO_TIMER_AUDIT=1');
putenv('LOTTO_TIMER_AUDIT_LOG=' . $auditLogPath);

$audit = new TimerAudit(new FakeLogger(), $auditLogPath);
$audit->recordAdd('reconnect', 1, 15.0, ['room_id' => 7]);
$audit->recordFire('reconnect', 1, ['room_id' => 7]);
$audit->recordDel('reconnect', 1, ['room_id' => 7]);

assertTrue(is_file($auditLogPath), 'timer audit log file created');
$logContent = (string) file_get_contents($auditLogPath);
assertTrue(str_contains($logContent, 'event=add'), 'log contains add event');
assertTrue(str_contains($logContent, 'event=fire'), 'log contains fire event');
assertTrue(str_contains($logContent, 'ts_us='), 'log contains microsecond timestamp');

$stats = TimerAudit::parseLogStats($auditLogPath);
assertTrue($stats['adds'] === 1 && $stats['fires'] === 1 && $stats['dels'] === 1, 'parseLogStats counts events');
assertTrue($stats['active'] === 0, 'no orphaned timers after del');

putenv('LOTTO_TIMER_AUDIT');
putenv('LOTTO_TIMER_AUDIT_LOG');
@unlink($auditLogPath);

// =============================================================================
// GROUP 2 — Constants env overrides
// =============================================================================

echo "\nGROUP 2: Constants env overrides\n";

putenv('LOTTO_RECONNECT_TIMEOUT=5');
putenv('LOTTO_GAME_AFK_AUTO=7');
assertTrue(Constants::reconnectTimeout() === 5, 'LOTTO_RECONNECT_TIMEOUT override');
assertTrue(Constants::gameAfkStrike3Seconds() === 7, 'LOTTO_GAME_AFK_STRIKE3/ AUTO override');

putenv('LOTTO_RECONNECT_TIMEOUT');
putenv('LOTTO_GAME_AFK_AUTO');
assertTrue(Constants::reconnectTimeout() === Constants::RECONNECT_TIMEOUT, 'default reconnect timeout restored');

// =============================================================================
// GROUP 3 — RoomManager destroys all timer slots
// =============================================================================

echo "\nGROUP 3: RoomManager timer cleanup\n";

\MockTimer::reset();
$roomManager = new RoomManager(new FakeLogger());
$worker = new stdClass();
$worker->rooms = [];

$roomId = $roomManager->createRoom($worker, 100, 4, null);
$lobbyTimer = \MockTimer::add(1.0, function () {}, true);
$gameTimer = \MockTimer::add(1.0, function () {}, true);
$aptTimer = \MockTimer::add(5.0, function () {}, false);
$reconnectTimer = \MockTimer::add(15.0, function () {}, false);

$worker->rooms[$roomId]['lobby_afk_timer_id'] = $lobbyTimer;
$worker->rooms[$roomId]['game_afk_timer_id'] = $gameTimer;
$worker->rooms[$roomId]['apartment_timer_id'] = $aptTimer;
$worker->rooms[$roomId]['players'][101] = makePlayer(
    new SpyConnection(101, 20, 'guest'),
    'disconnected',
    $reconnectTimer
);

$roomManager->destroyRoom($worker, $roomId);

assertTrue(!isset($worker->rooms[$roomId]), 'room removed from worker');
assertTrue(count(\MockTimer::$active) === 0, 'all room timers cancelled on destroyRoom');

// =============================================================================
// GROUP 4 — Reconnect timer schedule + cancel on reconnect
// =============================================================================

echo "\nGROUP 4: Reconnect timer schedule and cancel\n";

\MockTimer::reset();
putenv('LOTTO_RECONNECT_TIMEOUT=5');
putenv('LOTTO_TIMER_AUDIT=1');
putenv('LOTTO_TIMER_AUDIT_LOG=' . $auditLogPath);

$audit = new TimerAudit(new FakeLogger(), $auditLogPath);
$GLOBALS['__lotto_timer_audit'] = $audit;

$worker4 = new stdClass();
$worker4->rooms = [1 => makeRoom(1, 10, 'waiting')];
$host = new SpyConnection(10, 1, 'host', 'tok_host');
$guest = new SpyConnection(11, 2, 'guest', 'tok_guest');
$worker4->rooms[1]['players'][10] = makePlayer($host);
$worker4->rooms[1]['players'][11] = makePlayer($guest);

$lobby = new MockLobbyService();
$game = new MockGameService();
$reconnect = new ReconnectService($lobby, $game, new FakeLogger());

$reconnect->handleDisconnect($guest, $worker4);
$timerId = $worker4->rooms[1]['players'][11]['reconnect_timer'] ?? null;
assertTrue($timerId !== null && isset(\MockTimer::$active[$timerId]), 'disconnect schedules reconnect timer');

$newConn = new SpyConnection(99, 2, 'guest', 'tok_guest');
$reconnect->handleReconnect('tok_guest', $newConn, $worker4);
assertTrue(!isset(\MockTimer::$active[$timerId]), 'reconnect cancels pending reconnect timer');
assertTrue(isset($worker4->rooms[1]['players'][99]), 'player re-keyed to new connection id');

unset($GLOBALS['__lotto_timer_audit']);
putenv('LOTTO_TIMER_AUDIT');
putenv('LOTTO_TIMER_AUDIT_LOG');
putenv('LOTTO_RECONNECT_TIMEOUT');
@unlink($auditLogPath);

// =============================================================================
// GROUP 5 — LobbyService stops lobby AFK timer when player count drops
// =============================================================================

echo "\nGROUP 5: LobbyService lobby AFK timer stop\n";

\MockTimer::reset();
$worker5 = new stdClass();
$worker5->rooms = [2 => makeRoom(2, 200, 'waiting')];
$worker5->rooms[2]['players'][200] = makePlayer(new SpyConnection(200, 30, 'host'));
$worker5->rooms[2]['players'][201] = makePlayer(new SpyConnection(201, 40, 'guest'));

$roomManager5 = new RoomManager(new FakeLogger());
$lobbyService = new LobbyService($roomManager5, new FakeLogger());

$joinRef = new ReflectionClass(LobbyService::class);
$startMethod = $joinRef->getMethod('startLobbyAfkTimer');
$startMethod->setAccessible(true);
$startMethod->invoke($lobbyService, $worker5, 2);

$timerId = $worker5->rooms[2]['lobby_afk_timer_id'] ?? null;
assertTrue($timerId !== null && isset(\MockTimer::$active[$timerId]), 'lobby AFK timer started with 2 players');

$stopMethod = $joinRef->getMethod('stopLobbyAfkTimer');
$stopMethod->setAccessible(true);
unset($worker5->rooms[2]['players'][201]);
$stopMethod->invoke($lobbyService, $worker5, 2);

assertTrue(!isset(\MockTimer::$active[$timerId]), 'lobby AFK timer stopped when player removed');

// =============================================================================
// GROUP 6 — Single-shot timer fires once
// =============================================================================

echo "\nGROUP 6: Single-shot timer fires once\n";

\MockTimer::reset();
$fired = 0;
$timerId = \MockTimer::add(5.0, function () use (&$fired): void {
    $fired++;
}, false);

assertTrue(\MockTimer::fire($timerId), 'single-shot timer fires');
assertTrue($fired === 1, 'callback executed once');
assertTrue(!isset(\MockTimer::$active[$timerId]), 'single-shot timer removed after fire');
assertTrue(!\MockTimer::fire($timerId), 'second fire is no-op');

// =============================================================================
// Summary
// =============================================================================

echo "\n" . str_repeat('=', 60) . "\n";
echo "RESULT: {$passed} passed, {$failed} failed\n";
echo str_repeat('=', 60) . "\n";

exit($failed > 0 ? 1 : 0);
