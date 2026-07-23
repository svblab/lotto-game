<?php

declare(strict_types=1);

/**
 * tests/Manual/test_admin_close_room.php
 *
 * EPIC-9.4 — Close room. Юнит-тест AdminService::handleCloseRoom().
 *
 * Границы теста (ANCHOR_RULES Part 22 § Test Philosophy):
 *   - Реальные зависимости: PDO (SQLite in-memory) + PreparedStatements + AdminService.
 *   - SpyRoomManager вместо реального RoomManager — избегаем жёсткой зависимости
 *     от Lotto\Core\Logger (публичный интерфейс, ANCHOR_CORE Part 3 § Module Boundaries).
 *     Реальный RoomManager::destroyRoom() тестируется отдельно в EPIC-2.7.
 *   - Этот тест проверяет ТОЛЬКО контракт AdminService: транзакцию 100% рефанда
 *     ИЗ all_players_history (включая ранее удалённых игроков), обнуление bank,
 *     уведомление активных игроков, вызов destroyRoom(), откат при сбое БД.
 *
 * Запуск: php tests/Manual/test_admin_close_room.php
 */

require __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Helpers.php';

use Lotto\Admin\AdminService;
use Lotto\Infrastructure\PreparedStatements;

// =============================================================================
// Test harness
// =============================================================================

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

function assertEquals(mixed $expected, mixed $actual, string $label): void
{
    assertTrue(
        $expected === $actual,
        "{$label} (expected: " . var_export($expected, true) . ", got: " . var_export($actual, true) . ")"
    );
}

// =============================================================================
// Test doubles
// =============================================================================

final class FakeLogger
{
    public array $lines = [];
    public function info(string $msg): void { $this->lines[] = "INFO: {$msg}"; }
    public function warning(string $msg): void { $this->lines[] = "WARNING: {$msg}"; }
    public function error(string $msg): void { $this->lines[] = "ERROR: {$msg}"; }
}

final class FakeDb
{
    public function __construct(private PDO $pdo) {}
    public function getPdo(): PDO { return $this->pdo; }
}

final class SpyConnection
{
    public mixed $userId = null;
    public mixed $username = null;
    public mixed $isAdmin = false;
    public int $id = 0;
    public array $sent = [];

    public function send(string $json): void
    {
        $this->sent[] = json_decode($json, true);
    }

    public function lastSent(): ?array
    {
        return $this->sent[array_key_last($this->sent)] ?? null;
    }
}

final class FailingStatementsProxy
{
    public function __construct(private PreparedStatements $inner, private string $failingKey) {}

    public function get(string $key): PDOStatement
    {
        if ($key === $this->failingKey) {
            throw new \RuntimeException('Simulated statement failure: ' . $key);
        }
        return $this->inner->get($key);
    }
}

final class SpyRoomManager
{
    public array $destroyCalls = [];

    public function destroyRoom(object $worker, int $roomId): void
    {
        $this->destroyCalls[] = $roomId;
        unset($worker->rooms[$roomId]);
    }
}

// =============================================================================
// Fixtures
// =============================================================================

function insertUser(PDO $pdo, string $username, int $coins, bool $isAdmin): int
{
    $stmt = $pdo->prepare(
        "INSERT INTO users (username, password_hash, coins, is_admin, banned_until, last_daily_bonus)
         VALUES (?, 'x', ?, ?, 0, 0)"
    );
    $stmt->execute([$username, $coins, $isAdmin ? 1 : 0]);
    return (int)$pdo->lastInsertId();
}

function getCoins(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare("SELECT coins FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return (int)$stmt->fetch()['coins'];
}

function makePlayer(int $userId, string $username, int $totalPaid, string $status = 'active'): array
{
    return [
        'user_id'         => $userId,
        'username'        => $username,
        'cards'           => [],
        'cards_count'     => 1,
        'total_paid'      => $totalPaid,
        'last_action'     => time(),
        'afk_start'       => null,
        'strikes'         => 0,
        'auto_draws'      => 0,
        'status'          => $status,
        'session_token'   => '',
        'reconnect_timer' => null,
        'connection'      => new SpyConnection(),
        'immune'          => false,
    ];
}

function makeRoom(int $roomId, int $hostConnId, string $status, int $bank): array
{
    return [
        'room_id'               => $roomId,
        'host_conn_id'          => $hostConnId,
        'bet_per_card'          => 10,
        'max_players'           => 10,
        'password_hash'         => null,
        'status'                => $status,
        'bank'                  => $bank,
        'apartment_fired'       => false,
        'pause_for_apartment'   => false,
        'apartment_responses'   => [],
        'game_afk_timer_id'     => null,
        'apartment_timer_id'    => null,
        'lobby_afk_timer_id'    => null,
        'active_drawer_conn_id' => null,
        'drawer_order'          => [],
        'bag'                   => [],
        'drawn_numbers'         => [],
        'players'               => [],
        'all_players_history'   => [],
    ];
}

function makeWorker(): object
{
    $worker = new stdClass();
    $worker->rooms = [];
    $worker->userConnections = [];
    return $worker;
}

// =============================================================================
// DB bootstrap (SQLite in-memory)
// =============================================================================

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    coins INTEGER NOT NULL DEFAULT 500,
    is_admin INTEGER NOT NULL DEFAULT 0,
    banned_until INTEGER NOT NULL DEFAULT 0,
    last_daily_bonus INTEGER NOT NULL DEFAULT 0
)");

$stmts = new PreparedStatements($pdo);
$db    = new FakeDb($pdo);

$adminId = insertUser($pdo, 'admin1', 500, true);

function makeAdminConnection(int $adminId, int $connId): SpyConnection
{
    $c = new SpyConnection();
    $c->userId  = $adminId;
    $c->isAdmin = true;
    $c->id      = $connId;
    return $c;
}

// =============================================================================
// TEST 1 — Close room из waiting, все total_paid=0: без рефанда, но destroy вызван
// =============================================================================

echo "TEST 1: Close room from waiting, total_paid=0 for all\n";

$p1 = insertUser($pdo, 'w_player1', 500, false);
$p2 = insertUser($pdo, 'w_player2', 500, false);

$worker = makeWorker();
$worker->rooms[1] = makeRoom(1, 100, 'waiting', 0);
$worker->rooms[1]['players'][100] = makePlayer($p1, 'w_player1', 0);
$worker->rooms[1]['players'][101] = makePlayer($p2, 'w_player2', 0);

$roomManager1 = new SpyRoomManager();
$admin1 = new AdminService($stmts, new FakeLogger(), null, null, null, $db, $roomManager1);

$conn1 = makeAdminConnection($adminId, 900);
$admin1->handleCloseRoom(['room_id' => 1], $conn1, $worker);

assertEquals(500, getCoins($pdo, $p1), 'no refund needed when total_paid=0 (p1)');
assertEquals(500, getCoins($pdo, $p2), 'no refund needed when total_paid=0 (p2)');
assertEquals(1, count($roomManager1->destroyCalls), 'destroyRoom called once');
assertEquals(1, $roomManager1->destroyCalls[0] ?? null, 'destroyRoom called with correct roomId');
assertTrue(!isset($worker->rooms[1]), 'room removed from worker->rooms');

// =============================================================================
// TEST 2 — Close room из playing, все с total_paid>0: 100% рефанд, bank=0
// =============================================================================

echo "\nTEST 2: Close room from playing with refunds\n";

$p3 = insertUser($pdo, 'pl_player1', 480, false);
$p4 = insertUser($pdo, 'pl_player2', 480, false);

$worker = makeWorker();
$worker->rooms[2] = makeRoom(2, 200, 'playing', 40); // bank = 20+20
$worker->rooms[2]['players'][200] = makePlayer($p3, 'pl_player1', 20);
$worker->rooms[2]['players'][201] = makePlayer($p4, 'pl_player2', 20);

$roomManager2 = new SpyRoomManager();
$admin2 = new AdminService($stmts, new FakeLogger(), null, null, null, $db, $roomManager2);

$conn2 = makeAdminConnection($adminId, 901);
$coinsBefore3 = getCoins($pdo, $p3);
$coinsBefore4 = getCoins($pdo, $p4);

$admin2->handleCloseRoom(['room_id' => 2], $conn2, $worker);

assertEquals($coinsBefore3 + 20, getCoins($pdo, $p3), 'p3 refunded 100% of total_paid');
assertEquals($coinsBefore4 + 20, getCoins($pdo, $p4), 'p4 refunded 100% of total_paid');
assertEquals(1, count($roomManager2->destroyCalls), 'destroyRoom called once for playing room');

// =============================================================================
// TEST 3 — Рефанд включает игроков, УЖЕ удалённых ранее (all_players_history)
// =============================================================================

echo "\nTEST 3: Refund includes previously removed players from history\n";

$p5 = insertUser($pdo, 'left_earlier', 490, false);   // ушёл раньше, total_paid=10 остался в банке
$p6 = insertUser($pdo, 'still_active', 470, false);   // всё ещё в комнате, total_paid=30

$worker = makeWorker();
$worker->rooms[3] = makeRoom(3, 300, 'playing', 40); // bank = 10 (уже ушедшего) + 30 (активного)
$worker->rooms[3]['players'][301] = makePlayer($p6, 'still_active', 30);
// p5 уже удалён ранее (например, reason='leave'), его total_paid остался в истории без рефанда
$worker->rooms[3]['all_players_history'][300] = [
    'user_id'    => $p5,
    'username'   => 'left_earlier',
    'total_paid' => 10,
];

$roomManager3 = new SpyRoomManager();
$admin3 = new AdminService($stmts, new FakeLogger(), null, null, null, $db, $roomManager3);

$conn3 = makeAdminConnection($adminId, 902);
$coinsBefore5 = getCoins($pdo, $p5);
$coinsBefore6 = getCoins($pdo, $p6);

$admin3->handleCloseRoom(['room_id' => 3], $conn3, $worker);

assertEquals($coinsBefore5 + 10, getCoins($pdo, $p5), 'previously-removed player (left_earlier) IS refunded');
assertEquals($coinsBefore6 + 30, getCoins($pdo, $p6), 'still-active player IS refunded');

// =============================================================================
// TEST 4 — Уведомление активных игроков (player_left, reason=admin_close)
// =============================================================================

echo "\nTEST 4: Active players notified, disconnected players are not sent to\n";

$p7 = insertUser($pdo, 'active_notify', 480, false);
$p8 = insertUser($pdo, 'disconnected_skip', 480, false);

$worker = makeWorker();
$worker->rooms[4] = makeRoom(4, 400, 'playing', 40);
$worker->rooms[4]['players'][400] = makePlayer($p7, 'active_notify', 20, 'active');
$worker->rooms[4]['players'][401] = makePlayer($p8, 'disconnected_skip', 20, 'disconnected');

$activeConn = $worker->rooms[4]['players'][400]['connection'];
$disconnectedConn = $worker->rooms[4]['players'][401]['connection'];

$roomManager4 = new SpyRoomManager();
$admin4 = new AdminService($stmts, new FakeLogger(), null, null, null, $db, $roomManager4);

$conn4 = makeAdminConnection($adminId, 903);
$admin4->handleCloseRoom(['room_id' => 4], $conn4, $worker);

assertEquals(1, count($activeConn->sent), 'active player received exactly 1 packet');
assertEquals('player_left', $activeConn->lastSent()['type'] ?? null, 'packet type is player_left');
assertEquals('active_notify', $activeConn->lastSent()['username'] ?? null, 'username matches active player');
assertEquals('admin_close', $activeConn->lastSent()['reason'] ?? null, 'reason=admin_close');
assertEquals(0, count($disconnectedConn->sent), 'disconnected player NOT notified');
// но рефанд получают оба (проверено косвенно через total refund в предыдущих тестах;
// здесь дополнительно проверяем баланс disconnected игрока)
assertEquals(500, getCoins($pdo, $p8), 'disconnected player still refunded despite no notification');

// =============================================================================
// TEST 5 — room_id не найден
// =============================================================================

echo "\nTEST 5: Room not found\n";

$worker = makeWorker();
$roomManager5 = new SpyRoomManager();
$admin5 = new AdminService($stmts, new FakeLogger(), null, null, null, $db, $roomManager5);

$conn5 = makeAdminConnection($adminId, 904);
$admin5->handleCloseRoom(['room_id' => 999], $conn5, $worker);

assertEquals('error.room_not_found', $conn5->lastSent()['code'] ?? null, 'room_not_found returned');
assertEquals(0, count($roomManager5->destroyCalls), 'destroyRoom NOT called');

// =============================================================================
// TEST 6 — Не-админ не может закрыть комнату
// =============================================================================

echo "\nTEST 6: Non-admin caller rejected\n";

$callerId = insertUser($pdo, 'regular_closer', 500, false);

$worker = makeWorker();
$worker->rooms[6] = makeRoom(6, 600, 'waiting', 0);
$worker->rooms[6]['players'][600] = makePlayer($callerId, 'regular_closer', 0);

$roomManager6 = new SpyRoomManager();
$admin6 = new AdminService($stmts, new FakeLogger(), null, null, null, $db, $roomManager6);

$conn6 = new SpyConnection();
$conn6->userId  = $callerId;
$conn6->isAdmin = false;
$conn6->id      = 905;

$admin6->handleCloseRoom(['room_id' => 6], $conn6, $worker);

assertEquals('error.not_your_turn', $conn6->lastSent()['code'] ?? null, 'non-admin rejected');
assertEquals(0, count($roomManager6->destroyCalls), 'destroyRoom NOT called by non-admin');
assertTrue(isset($worker->rooms[6]), 'room NOT destroyed');

// =============================================================================
// TEST 7 — Сбой транзакции рефанда: rollback, room НЕ уничтожается
// =============================================================================

echo "\nTEST 7: Refund transaction failure -> rollback, room NOT destroyed\n";

$p9 = insertUser($pdo, 'fail_refund_target', 480, false);

$worker = makeWorker();
$worker->rooms[7] = makeRoom(7, 700, 'playing', 20);
$worker->rooms[7]['players'][700] = makePlayer($p9, 'fail_refund_target', 20);

$roomManager7 = new SpyRoomManager();
$failingStmts = new FailingStatementsProxy($stmts, 'add_user_coins');
$admin7 = new AdminService($failingStmts, new FakeLogger(), null, null, null, $db, $roomManager7);

$conn7 = makeAdminConnection($adminId, 906);
$coinsBefore9 = getCoins($pdo, $p9);

$admin7->handleCloseRoom(['room_id' => 7], $conn7, $worker);

assertEquals('error', $conn7->lastSent()['type'] ?? null, 'error packet sent on transaction failure');
assertEquals('error.invalid_json', $conn7->lastSent()['code'] ?? null, 'close_room refund failure reported');
assertEquals($coinsBefore9, getCoins($pdo, $p9), 'coins NOT changed after rollback');
assertEquals(20, $worker->rooms[7]['bank'], 'bank NOT touched after rollback');
assertEquals(0, count($roomManager7->destroyCalls), 'destroyRoom NOT called after rollback');
assertTrue(isset($worker->rooms[7]), 'room still exists after rollback');
assertTrue($pdo->inTransaction() === false, 'PDO transaction cleanly rolled back');

// =============================================================================
// Summary
// =============================================================================

echo "\n----------------------------------------\n";
echo "{$passed} / " . ($passed + $failed) . " PASSED (admin close room)\n";
echo "----------------------------------------\n";

exit($failed > 0 ? 1 : 0);