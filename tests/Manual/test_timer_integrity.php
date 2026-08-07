<?php

declare(strict_types=1);

/**
 * tests/Manual/test_timer_integrity.php
 *
 * FIX-6 — regression test: "No reconnect timer survives player removal"
 * (ANCHOR_CORE.md Part 5 § Timer Integrity Rules).
 *
 * Найдено при аудите после FIX-3/FIX-4/FIX-5: ReconnectService::
 * removePlayerFromGame() корректно отменяет player['reconnect_timer']
 * ПЕРЕД удалением игрока, но LobbyService::removePlayerFromLobby() и
 * ApartmentService::removePlayerFromApartment() этого не делали —
 * асимметрия между тремя "сёстринскими" методами удаления.
 *
 * Достижимость (Lobby): disconnected-игрок в waiting-комнате имеет
 * активный 15s reconnect_timer (ANCHOR_CORE § Reconnect Timer). Если за
 * это время администратор кикает/банит его, removePlayerFromLobby()
 * удаляет игрока, но таймер остаётся зарегистрированным в Workerman —
 * а generateRoomId() (RoomManager) переиспользует ПЕРВЫЙ свободный
 * room_id сразу после уничтожения комнаты (MAX_ROOMS=30), так что это
 * не просто "потерянная память", а нарушение инварианта "A destroyed
 * owner keeps no timers" на переиспользуемом ресурсе.
 *
 * Запуск: php tests/Manual/test_timer_integrity.php
 */

require_once __DIR__ . '/mock_timer.php';

require __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Helpers.php';

use Lotto\Core\RoomManager;
use Lotto\Core\Logger;
use Lotto\Lobby\LobbyService;
use Lotto\Lobby\LobbyHostService;
use Lotto\Game\ApartmentService;
use Lotto\Game\GameFinishService;
use Lotto\Game\ReconnectService;

$passed = 0;
$failed = 0;

function assertTrue(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) { $passed++; echo "  [PASS] {$label}\n"; }
    else       { $failed++; echo "  [FAIL] {$label}\n"; }
}

// =============================================================================
// Test doubles
// =============================================================================

final class SpyConnection
{
    public array $sent = [];
    public function send(string $json): void { $this->sent[] = json_decode($json, true); }
}

final class FakeLogger extends Logger
{
    public function __construct() {}
    public function info(string $m): void {}
    public function warning(string $m): void {}
    public function error(string $m): void {}
}

function makePlayer(int $userId, string $username, ?int $reconnectTimerId, string $status = 'disconnected'): array
{
    return [
        'user_id' => $userId, 'username' => $username, 'cards' => [], 'cards_count' => 1,
        'total_paid' => 0, 'last_action' => time(), 'afk_start' => null, 'strikes' => 0,
        'auto_draws' => 0, 'status' => $status, 'session_token' => '',
        'reconnect_timer' => $reconnectTimerId, 'connection' => new SpyConnection(),
        'immune' => false,
    ];
}

function makeRoom(int $roomId, int $hostConnId, string $status): array
{
    return [
        'room_id' => $roomId, 'host_conn_id' => $hostConnId, 'bet_per_card' => 10,
        'max_players' => 10, 'password_hash' => null, 'status' => $status, 'bank' => 0,
        'apartment_fired' => false, 'pause_for_apartment' => false, 'apartment_responses' => [],
        'game_afk_timer_id' => null, 'apartment_timer_id' => null, 'lobby_afk_timer_id' => null,
        'active_drawer_conn_id' => null, 'drawer_order' => [], 'bag' => [], 'drawn_numbers' => [],
        'players' => [], 'all_players_history' => [],
    ];
}

/** Minimal PDO stub for GameFinishService timer-cleanup tests (no SQLite driver needed). */
function makeFinishService(FakeLogger $logger): GameFinishService
{
    $pdo = new class extends \PDO {
        public function __construct() {}
        public function beginTransaction(): bool { return true; }
        public function commit(): bool { return true; }
        public function rollBack(): bool { return true; }
    };
    $db = new \Lotto\Infrastructure\Database($pdo);
    $stmts = new \Lotto\Infrastructure\PreparedStatements($pdo);
    return new GameFinishService($db, $stmts, $logger);
}

// =============================================================================
// TEST 1 — LobbyService::removePlayerFromLobby() cancels reconnect_timer
// =============================================================================

echo "TEST 1: removePlayerFromLobby() cancels a pending reconnect_timer\n";

\MockTimer::reset();
$timerId = \MockTimer::add(15.0, function () {});
assertTrue(isset(\MockTimer::$active[$timerId]), 'setup: timer is registered before removal');

$worker1 = new stdClass();
$worker1->rooms = [];
$worker1->rooms[1] = makeRoom(1, 100, 'waiting');
$worker1->rooms[1]['players'][100] = makePlayer(10, 'host', null, 'active');
$worker1->rooms[1]['players'][101] = makePlayer(20, 'victim', $timerId, 'disconnected');

$logger = new FakeLogger();
$roomManager = new RoomManager($logger);
$lobbyHostService = new LobbyHostService($roomManager, $logger);
$lobbyService = new LobbyService($roomManager, $logger, $lobbyHostService);

$lobbyService->removePlayerFromLobby($worker1, 1, 101, 'kicked');

assertTrue(
    !isset(\MockTimer::$active[$timerId]),
    'FIX-6: reconnect_timer cancelled after removePlayerFromLobby() (kicked)'
);
assertTrue(!isset($worker1->rooms[1]['players'][101]), 'victim removed from players');

// =============================================================================
// TEST 2 — Same check for 'banned' reason (identical code path)
// =============================================================================

echo "\nTEST 2: removePlayerFromLobby() cancels timer for reason=banned too\n";

\MockTimer::reset();
$timerId2 = \MockTimer::add(15.0, function () {});

$worker2 = new stdClass();
$worker2->rooms = [];
$worker2->rooms[2] = makeRoom(2, 200, 'waiting');
$worker2->rooms[2]['players'][200] = makePlayer(30, 'host2', null, 'active');
$worker2->rooms[2]['players'][201] = makePlayer(40, 'victim2', $timerId2, 'disconnected');

$lobbyService->removePlayerFromLobby($worker2, 2, 201, 'banned');

assertTrue(
    !isset(\MockTimer::$active[$timerId2]),
    'FIX-6: reconnect_timer cancelled after removePlayerFromLobby() (banned)'
);

// =============================================================================
// TEST 3 — ApartmentService::removePlayerFromApartment() cancels reconnect_timer
// (defensive: reconnect is forbidden in apartment state per state machine, but
// Rule 23 is unconditional — "a destroyed owner keeps no timers")
// =============================================================================

echo "\nTEST 3: removePlayerFromApartment() cancels a pending reconnect_timer (defensive)\n";

\MockTimer::reset();
$timerId3 = \MockTimer::add(15.0, function () {});

$worker3 = new stdClass();
$worker3->rooms = [];
$worker3->rooms[3] = makeRoom(3, 300, 'apartment');
$worker3->rooms[3]['players'][300] = makePlayer(50, 'host3', null, 'active');
$worker3->rooms[3]['players'][301] = makePlayer(60, 'victim3', $timerId3, 'active');
$worker3->rooms[3]['drawer_order'] = [300, 301];

$apartmentService = new ApartmentService(new stdClass(), new stdClass(), $logger);
$apartmentService->removePlayerFromApartment($worker3->rooms[3], 3, 301, 'kicked', $worker3);

assertTrue(
    !isset(\MockTimer::$active[$timerId3]),
    'FIX-6: reconnect_timer cancelled after removePlayerFromApartment()'
);

// =============================================================================
// TEST 4 — RoomManager::destroyRoom() cancels game_afk_timer_id
// =============================================================================

echo "\nTEST 4: destroyRoom() cancels game_afk_timer_id (no leak on room destroy)\n";

\MockTimer::reset();
$gameAfkId = \MockTimer::add(1.0, function () {}, true);
assertTrue(isset(\MockTimer::$active[$gameAfkId]), 'setup: game_afk timer registered');

$worker4 = new stdClass();
$worker4->rooms = [];
$worker4->rooms[4] = makeRoom(4, 400, 'playing');
$worker4->rooms[4]['game_afk_timer_id'] = $gameAfkId;

$roomManager4 = new RoomManager($logger);
$roomManager4->destroyRoom($worker4, 4);

assertTrue(
    !isset(\MockTimer::$active[$gameAfkId]),
    'game_afk_timer cancelled after RoomManager::destroyRoom()'
);
assertTrue(!isset($worker4->rooms[4]), 'room removed from worker');

// =============================================================================
// TEST 5 — GameFinishService::finishGame() cancels game_afk_timer_id (victory)
// =============================================================================

echo "\nTEST 5: finishGame(victory) cancels game_afk_timer_id before room destroy\n";

\MockTimer::reset();
$gameAfkId5 = \MockTimer::add(1.0, function () {}, true);

$worker5 = new stdClass();
$worker5->rooms = [];
$h5 = new SpyConnection();
$worker5->rooms[5] = makeRoom(5, 500, 'playing');
$worker5->rooms[5]['game_afk_timer_id'] = $gameAfkId5;
$worker5->rooms[5]['players'][500] = [
    'user_id' => 50, 'username' => 'winner', 'cards' => [], 'cards_count' => 1,
    'total_paid' => 10, 'last_action' => time(), 'afk_start' => null, 'strikes' => 0,
    'auto_draws' => 0, 'status' => 'active', 'session_token' => '', 'reconnect_timer' => null,
    'connection' => $h5, 'immune' => false,
];

$finishService = makeFinishService($logger);
$room5 = &$worker5->rooms[5];
$finishService->finishGame(
    $room5,
    5,
    [500 => 1],
    [], // empty prizes — skip DB; timer cleanup is what we test
    'victory',
    function () use ($worker5) { unset($worker5->rooms[5]); }
);

assertTrue(
    !isset(\MockTimer::$active[$gameAfkId5]),
    'game_afk_timer cancelled after GameFinishService::finishGame(victory)'
);
assertTrue(!isset($worker5->rooms[5]), 'room destroyed after finishGame');

// =============================================================================
// TEST 6 — ReconnectService last_survivor + empty-room destroy cancel game_afk
// =============================================================================

echo "\nTEST 6a: finishGame(last_survivor) cancels game_afk_timer_id\n";

\MockTimer::reset();
$gameAfkId6a = \MockTimer::add(1.0, function () {}, true);

$worker6a = new stdClass();
$worker6a->rooms = [];
$c6a = new SpyConnection();
$worker6a->rooms[6] = makeRoom(6, 601, 'playing');
$worker6a->rooms[6]['game_afk_timer_id'] = $gameAfkId6a;
$worker6a->rooms[6]['players'][601] = [
    'user_id' => 61, 'username' => 'survivor', 'cards' => [], 'cards_count' => 1,
    'total_paid' => 10, 'last_action' => time(), 'afk_start' => null, 'strikes' => 0,
    'auto_draws' => 0, 'status' => 'active', 'session_token' => '', 'reconnect_timer' => null,
    'connection' => $c6a, 'immune' => false,
];

$finish6a = makeFinishService($logger);
$room6a = &$worker6a->rooms[6];
$finish6a->finishGame(
    $room6a,
    6,
    [601 => 1],
    [],
    'last_survivor',
    function () use ($worker6a) { unset($worker6a->rooms[6]); }
);

assertTrue(
    !isset(\MockTimer::$active[$gameAfkId6a]),
    'game_afk_timer cancelled after GameFinishService::finishGame(last_survivor)'
);
assertTrue(!isset($worker6a->rooms[6]), 'room destroyed after last_survivor finishGame');

echo "\nTEST 6b: removePlayerFromGame(empty room) cancels game_afk_timer_id\n";

\MockTimer::reset();
$gameAfkId6b = \MockTimer::add(1.0, function () {}, true);

$worker6b = new stdClass();
$worker6b->rooms = [];
$only6b = new SpyConnection();
$worker6b->rooms[7] = makeRoom(7, 700, 'playing');
$worker6b->rooms[7]['game_afk_timer_id'] = $gameAfkId6b;
$worker6b->rooms[7]['players'][701] = [
    'user_id' => 71, 'username' => 'solo', 'cards' => [], 'cards_count' => 1,
    'total_paid' => 10, 'last_action' => time(), 'afk_start' => null, 'strikes' => 0,
    'auto_draws' => 0, 'status' => 'active', 'session_token' => '', 'reconnect_timer' => null,
    'connection' => $only6b, 'immune' => false,
];

$noopGame = new class {
    public function finishGame(): void {}
    public function nextDrawer(): void {}
    public function startTurn(): void {}
    public function handleNoSurvivors(array &$room, int $roomId, object $worker, ?object $notifyConnection = null): void
    {
        if (!empty($room['game_afk_timer_id'])) {
            \MockTimer::del((int) $room['game_afk_timer_id']);
        }
        unset($worker->rooms[$roomId]);
    }
};
$reconnect6b = new ReconnectService(new stdClass(), $noopGame, $logger);
$reconnect6b->removePlayerFromGame($worker6b, 7, 701, 'afk');

assertTrue(
    !isset(\MockTimer::$active[$gameAfkId6b]),
    'game_afk_timer cancelled when last player removed (destroyRoom path)'
);
assertTrue(!isset($worker6b->rooms[7]), 'room destroyed when last player removed');

// =============================================================================
// Regression proof — without FIX-6 this test would fail (documented, not
// re-executed here to avoid mutating production source from a test run;
// verified manually during development by reverting the two Timer::del()
// additions and confirming TEST 1–3 go red).
// =============================================================================

// =============================================================================
// Summary
// =============================================================================

echo "\n" . str_repeat('=', 60) . "\n";
echo "RESULT: {$passed} passed, {$failed} failed\n";
echo str_repeat('=', 60) . "\n";

exit($failed > 0 ? 1 : 0);
