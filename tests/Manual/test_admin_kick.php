<?php

declare(strict_types=1);

/**
 * tests/Manual/test_admin_kick.php
 *
 * EPIC-9.3 — Kick player. Юнит-тест AdminService::handleKickUser().
 *
 * Границы теста (ANCHOR_RULES Part 22 § Test Philosophy):
 *   - Реальные зависимости: PDO (SQLite in-memory) + PreparedStatements + AdminService.
 *   - Spy-заглушки для LobbyService/ReconnectService/ApartmentService — это
 *     публичные интерфейсы других модулей (Module Boundaries, ANCHOR_CORE Part 3),
 *     их собственная логика удаления проверяется в EPIC-2.7 / 7.6 / 8.6.
 *   - Этот тест проверяет ТОЛЬКО контракт AdminService: транзакцию рефанда,
 *     арифметику bank, корректность вызова delegation с reason='kicked',
 *     host transfer, защиту админов, откат при сбое БД.
 *
 * Запуск: php tests/Manual/test_admin_kick.php
 */

require __DIR__ . '/../../vendor/autoload.php';

// Функции (sendJson/sendError/broadcastToRoom/serverLog) не подхватываются
// PSR-4 автозагрузкой — грузим Helpers.php явно (require_once безопасен
// даже если composer.json уже объявляет "files" autoload на этот путь).
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

/**
 * Прокси над реальным PreparedStatements — фейлит get() для указанного ключа,
 * делегируя всё остальное реальному объекту. Используется для инъекции сбоя
 * ровно в момент refund-транзакции (Test 8), без порчи реальной БД/таблиц
 * (DROP TABLE на таблице с закэшированными PDOStatement блокируется SQLite —
 * "database table is locked").
 */
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

final class SpyLobbyService
{
    /** @var array<int, array{roomId:int, connId:int, reason:string}> */
    public array $removeCalls = [];
    public array $transferHostCalls = [];

    public function removePlayerFromLobby(object $worker, int $roomId, int $connId, string $reason): void
    {
        $this->removeCalls[] = ['roomId' => $roomId, 'connId' => $connId, 'reason' => $reason];
        unset($worker->rooms[$roomId]['players'][$connId]);
    }

    public function transferHost(object $worker, int $roomId): void
    {
        $this->transferHostCalls[] = $roomId;
        foreach ($worker->rooms[$roomId]['players'] as $cid => $p) {
            if (($p['status'] ?? null) === 'active') {
                $worker->rooms[$roomId]['host_conn_id'] = $cid;
                break;
            }
        }
    }
}

final class SpyReconnectService
{
    public array $removeCalls = [];

    public function removePlayerFromGame(object $worker, int $roomId, int $connId, string $reason): void
    {
        $this->removeCalls[] = ['roomId' => $roomId, 'connId' => $connId, 'reason' => $reason];
        unset($worker->rooms[$roomId]['players'][$connId]);
    }
}

final class SpyApartmentService
{
    public array $removeCalls = [];

    public function removePlayerFromApartment(array &$room, int $roomId, int $connId, string $reason, object $worker): void
    {
        $this->removeCalls[] = ['roomId' => $roomId, 'connId' => $connId, 'reason' => $reason];
        unset($room['players'][$connId]);
        unset($worker->rooms[$roomId]['players'][$connId]);
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

// =============================================================================
// TEST 1 — Kick из waiting, total_paid=0, не хост: рефанда нет, delegation есть
// =============================================================================

echo "TEST 1: Kick from waiting, total_paid=0, non-host\n";

$adminId  = insertUser($pdo, 'admin1', 500, true);
$targetId = insertUser($pdo, 'player_w1', 500, false);

$worker = makeWorker();
$worker->rooms[1] = makeRoom(1, /* host conn */ 100, 'waiting', 0);
$worker->rooms[1]['players'][100] = makePlayer($adminId, 'host_player', 0);   // хост — другой игрок
$worker->rooms[1]['players'][200] = makePlayer($targetId, 'player_w1', 0);

$lobby     = new SpyLobbyService();
$reconnect = new SpyReconnectService();
$apartment = new SpyApartmentService();
$logger    = new FakeLogger();
$admin     = new AdminService($stmts, $logger, $lobby, $reconnect, $apartment, $db);

$connection = new SpyConnection();
$connection->userId  = $adminId;
$connection->isAdmin = true;
$connection->id      = 999;

$admin->handleKickUser(['user_id' => $targetId], $connection, $worker);

assertEquals(500, getCoins($pdo, $targetId), 'no refund when total_paid=0');
assertEquals(0, $worker->rooms[1]['bank'], 'bank unchanged when total_paid=0');
assertEquals(1, count($lobby->removeCalls), 'removePlayerFromLobby called once');
assertEquals('kicked', $lobby->removeCalls[0]['reason'] ?? null, 'reason=kicked passed to LobbyService');
assertEquals(200, $lobby->removeCalls[0]['connId'] ?? null, 'correct connId passed to LobbyService');
assertEquals(0, count($lobby->transferHostCalls), 'transferHost NOT called (kicked player was not host)');
assertTrue(!isset($worker->rooms[1]['players'][200]), 'player removed from room');

// =============================================================================
// TEST 2 — Kick хоста из waiting: transferHost вызывается
// =============================================================================

echo "\nTEST 2: Kick host from waiting -> transferHost triggered\n";

$hostId   = insertUser($pdo, 'host_to_kick', 500, false);
$otherId  = insertUser($pdo, 'stays_active', 500, false);

$worker = makeWorker();
$worker->rooms[2] = makeRoom(2, /* host conn */ 300, 'waiting', 0);
$worker->rooms[2]['players'][300] = makePlayer($hostId, 'host_to_kick', 0);
$worker->rooms[2]['players'][301] = makePlayer($otherId, 'stays_active', 0);

$lobby2     = new SpyLobbyService();
$reconnect2 = new SpyReconnectService();
$apartment2 = new SpyApartmentService();
$admin2     = new AdminService($stmts, new FakeLogger(), $lobby2, $reconnect2, $apartment2, $db);

$connection2 = new SpyConnection();
$connection2->userId  = $adminId;
$connection2->isAdmin = true;
$connection2->id      = 998;

$admin2->handleKickUser(['user_id' => $hostId], $connection2, $worker);

assertEquals(1, count($lobby2->removeCalls), 'removePlayerFromLobby called for host');
assertEquals(1, count($lobby2->transferHostCalls), 'transferHost called exactly once when host is kicked');
assertEquals(2, $lobby2->transferHostCalls[0] ?? null, 'transferHost called for correct roomId');

// =============================================================================
// TEST 3 — Kick из playing с total_paid>0: рефанд-транзакция + delegation
// =============================================================================

echo "\nTEST 3: Kick from playing with refund\n";

$hostPId   = insertUser($pdo, 'host_p', 480, false);   // уже заплатил 20 при старте
$targetPId = insertUser($pdo, 'target_p', 480, false); // уже заплатил 20 при старте

$worker = makeWorker();
$worker->rooms[3] = makeRoom(3, 400, 'playing', 40); // bank = 20+20
$worker->rooms[3]['players'][400] = makePlayer($hostPId, 'host_p', 20);
$worker->rooms[3]['players'][401] = makePlayer($targetPId, 'target_p', 20);

$lobby3     = new SpyLobbyService();
$reconnect3 = new SpyReconnectService();
$apartment3 = new SpyApartmentService();
$admin3     = new AdminService($stmts, new FakeLogger(), $lobby3, $reconnect3, $apartment3, $db);

$connection3 = new SpyConnection();
$connection3->userId  = $adminId;
$connection3->isAdmin = true;
$connection3->id      = 997;

$coinsBefore = getCoins($pdo, $targetPId);
$admin3->handleKickUser(['user_id' => $targetPId], $connection3, $worker);
$coinsAfter = getCoins($pdo, $targetPId);

assertEquals($coinsBefore + 20, $coinsAfter, 'refund credited to users.coins (+total_paid)');
assertEquals(20, $worker->rooms[3]['bank'], 'bank decremented by total_paid');
assertEquals(1, count($reconnect3->removeCalls), 'removePlayerFromGame called once');
assertEquals('kicked', $reconnect3->removeCalls[0]['reason'] ?? null, 'reason=kicked passed to ReconnectService');
assertEquals(401, $reconnect3->removeCalls[0]['connId'] ?? null, 'correct connId passed to ReconnectService');

// =============================================================================
// TEST 4 — Kick из apartment с рефандом: делегирование в ApartmentService
// =============================================================================

echo "\nTEST 4: Kick from apartment with refund\n";

$targetAId = insertUser($pdo, 'target_a', 480, false);

$worker = makeWorker();
$worker->rooms[4] = makeRoom(4, 500, 'apartment', 15);
$worker->rooms[4]['players'][500] = makePlayer($adminId, 'immune_host', 0, 'active');
$worker->rooms[4]['players'][501] = makePlayer($targetAId, 'target_a', 15);

$lobby4     = new SpyLobbyService();
$reconnect4 = new SpyReconnectService();
$apartment4 = new SpyApartmentService();
$admin4     = new AdminService($stmts, new FakeLogger(), $lobby4, $reconnect4, $apartment4, $db);

$connection4 = new SpyConnection();
$connection4->userId  = $adminId;
$connection4->isAdmin = true;
$connection4->id      = 996;

$coinsBefore = getCoins($pdo, $targetAId);
$admin4->handleKickUser(['user_id' => $targetAId], $connection4, $worker);
$coinsAfter = getCoins($pdo, $targetAId);

assertEquals($coinsBefore + 15, $coinsAfter, 'apartment kick refund credited');
assertEquals(0, $worker->rooms[4]['bank'], 'bank decremented to 0 (15-15)');
assertEquals(1, count($apartment4->removeCalls), 'removePlayerFromApartment called once');
assertEquals('kicked', $apartment4->removeCalls[0]['reason'] ?? null, 'reason=kicked passed to ApartmentService');
assertEquals(0, count($lobby4->removeCalls), 'LobbyService NOT called for apartment state');
assertEquals(0, count($reconnect4->removeCalls), 'ReconnectService NOT called for apartment state');

// =============================================================================
// TEST 5 — Нельзя кикнуть админа (cannot_moderate_admin)
// =============================================================================

echo "\nTEST 5: Cannot kick an admin account\n";

$otherAdminId = insertUser($pdo, 'admin2', 500, true);

$worker = makeWorker();
$worker->rooms[5] = makeRoom(5, 600, 'waiting', 0);
$worker->rooms[5]['players'][600] = makePlayer($adminId, 'admin1', 0);
$worker->rooms[5]['players'][601] = makePlayer($otherAdminId, 'admin2', 0);

$lobby5     = new SpyLobbyService();
$reconnect5 = new SpyReconnectService();
$apartment5 = new SpyApartmentService();
$admin5     = new AdminService($stmts, new FakeLogger(), $lobby5, $reconnect5, $apartment5, $db);

$connection5 = new SpyConnection();
$connection5->userId  = $adminId;
$connection5->isAdmin = true;
$connection5->id      = 995;

$coinsBefore = getCoins($pdo, $otherAdminId);
$admin5->handleKickUser(['user_id' => $otherAdminId], $connection5, $worker);

assertEquals('error', $connection5->lastSent()['type'] ?? null, 'error packet sent');
assertEquals('error.cannot_moderate_admin', $connection5->lastSent()['code'] ?? null, 'cannot_moderate_admin code returned');
assertEquals($coinsBefore, getCoins($pdo, $otherAdminId), 'no coins changed');
assertEquals(0, count($lobby5->removeCalls), 'no removal delegated');
assertTrue(isset($worker->rooms[5]['players'][601]), 'target admin still in room');

// =============================================================================
// TEST 6 — Цель не найдена ни в одной комнате
// =============================================================================

echo "\nTEST 6: Target user not in any room\n";

$offlineId = insertUser($pdo, 'offline_user', 500, false);

$worker = makeWorker(); // пустой worker, ни одной комнаты

$lobby6     = new SpyLobbyService();
$reconnect6 = new SpyReconnectService();
$apartment6 = new SpyApartmentService();
$admin6     = new AdminService($stmts, new FakeLogger(), $lobby6, $reconnect6, $apartment6, $db);

$connection6 = new SpyConnection();
$connection6->userId  = $adminId;
$connection6->isAdmin = true;
$connection6->id      = 994;

$admin6->handleKickUser(['user_id' => $offlineId], $connection6, $worker);

assertEquals('error.room_not_found', $connection6->lastSent()['code'] ?? null, 'room_not_found returned for offline/absent user');
assertEquals(0, count($lobby6->removeCalls) + count($reconnect6->removeCalls) + count($apartment6->removeCalls), 'no delegation attempted');

// =============================================================================
// TEST 7 — Не-админ не может выполнить kick (assertAdmin guard)
// =============================================================================

echo "\nTEST 7: Non-admin caller rejected by assertAdmin\n";

$callerId = insertUser($pdo, 'regular_user', 500, false);
$victimId = insertUser($pdo, 'victim', 500, false);

$worker = makeWorker();
$worker->rooms[7] = makeRoom(7, 700, 'waiting', 0);
$worker->rooms[7]['players'][700] = makePlayer($victimId, 'victim', 0);

$lobby7     = new SpyLobbyService();
$reconnect7 = new SpyReconnectService();
$apartment7 = new SpyApartmentService();
$admin7     = new AdminService($stmts, new FakeLogger(), $lobby7, $reconnect7, $apartment7, $db);

$connection7 = new SpyConnection();
$connection7->userId  = $callerId;
$connection7->isAdmin = false; // не админ
$connection7->id      = 993;

$admin7->handleKickUser(['user_id' => $victimId], $connection7, $worker);

assertEquals('error.not_your_turn', $connection7->lastSent()['code'] ?? null, 'non-admin rejected with not_your_turn');
assertEquals(0, count($lobby7->removeCalls), 'no removal attempted by non-admin');
assertTrue(isset($worker->rooms[7]['players'][700]), 'victim untouched');

// =============================================================================
// TEST 8 — Сбой транзакции рефанда: bank/игрок НЕ трогаются, rollback
// =============================================================================

echo "\nTEST 8: Refund transaction failure -> rollback, no removal\n";

$targetFailId = insertUser($pdo, 'target_fail', 480, false);

$worker = makeWorker();
$worker->rooms[8] = makeRoom(8, 800, 'playing', 20);
$worker->rooms[8]['players'][800] = makePlayer($targetFailId, 'target_fail', 20);

$lobby8     = new SpyLobbyService();
$reconnect8 = new SpyReconnectService();
$apartment8 = new SpyApartmentService();
$admin8     = new AdminService($stmts, new FakeLogger(), $lobby8, $reconnect8, $apartment8, $db);

$connection8 = new SpyConnection();
$connection8->userId  = $adminId;
$connection8->isAdmin = true;
$connection8->id      = 992;

// Инъекция сбоя: get('add_user_coins') бросает исключение внутри try{} блока
// handleKickUser() ровно после beginTransaction() — проверяем rollBack()
// без порчи реальной таблицы users.
$failingStmts = new FailingStatementsProxy($stmts, 'add_user_coins');
$admin8 = new AdminService($failingStmts, new FakeLogger(), $lobby8, $reconnect8, $apartment8, $db);

$admin8->handleKickUser(['user_id' => $targetFailId], $connection8, $worker);

assertEquals('error', $connection8->lastSent()['type'] ?? null, 'error packet sent on transaction failure');
assertEquals('error.invalid_json', $connection8->lastSent()['code'] ?? null, 'kick refund failure reported');
assertEquals(20, $worker->rooms[8]['bank'], 'bank NOT touched after failed transaction');
assertEquals(0, count($reconnect8->removeCalls), 'player NOT removed after failed transaction');
assertTrue(isset($worker->rooms[8]['players'][800]), 'target player remains in room after rollback');
assertTrue($pdo->inTransaction() === false, 'PDO transaction cleanly rolled back (no dangling transaction)');

// =============================================================================
// Summary
// =============================================================================

echo "\n----------------------------------------\n";
echo "{$passed} / " . ($passed + $failed) . " PASSED (admin kick)\n";
echo "----------------------------------------\n";

exit($failed > 0 ? 1 : 0);