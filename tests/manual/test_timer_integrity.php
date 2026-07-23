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
use Lotto\Game\ApartmentService;

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
$lobbyService = new LobbyService($roomManager, $logger);

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
