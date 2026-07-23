<?php

declare(strict_types=1);

/**
 * tests/Manual/test_admin_integration.php
 *
 * EPIC-9.6 — Admin integration tests.
 *
 * Границы теста (ANCHOR_RULES Part 22 § Test Philosophy):
 *   - Изолированные контракты каждого admin-действия уже покрыты:
 *     test_admin_auth.php (9.0), test_admin_ban.php (9.1),
 *     test_admin_unban.php (9.2), test_admin_kick.php (9.3),
 *     test_admin_close_room.php (9.4), test_admin_logs.php (9.5/9.6).
 *   - Этот файл проверяет ТОЛЬКО кросс-сценарии — последовательности из
 *     НЕСКОЛЬКИХ admin-действий в одной комнате, где инвариант экономики
 *     (ANCHOR_CORE.md Part 2 § Economic Integrity Rule) может быть нарушен
 *     на стыке двух контрактов, даже если каждый контракт по отдельности
 *     покрыт unit-тестом.
 *   - ContractAwareReconnectService/LobbyService ниже — не заглушки с
 *     произвольным поведением, а спеки, воспроизводящие ЕДИНСТВЕННУЮ часть
 *     документированного контракта, которая непосредственно участвует в
 *     проверяемых инвариантах: запись all_players_history[$connId]
 *     ['total_paid'] из ТЕКУЩЕГО (на момент вызова) $player['total_paid'].
 *     Эта запись идентична во всех реальных реализациях
 *     (LobbyService::removePlayerFromLobby, ReconnectService::
 *     removePlayerFromGame, ApartmentService::removePlayerFromApartment) —
 *     см. ANCHOR_CORE.md Room Structure § all_players_history. Сама
 *     остальная логика этих сервисов (host transfer, drawer rotation,
 *     таймеры) вне границ Admin-модуля и тестируется в EPIC-2.7/7.6/8.6.
 *
 * Запуск: php tests/Manual/test_admin_integration.php
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

final class SpyRoomManager
{
    public function destroyRoom(object $worker, int $roomId): void
    {
        unset($worker->rooms[$roomId]);
    }
}

/**
 * Воспроизводит ТОЛЬКО документированный контракт записи в
 * all_players_history (см. комментарий в шапке файла). Используется как
 * для LobbyService (waiting), так и для ReconnectService (playing) —
 * контракт записи истории идентичен в обеих реальных реализациях.
 */
final class ContractAwareRemovalService
{
    /** @var array<int, array{roomId:int, connId:int, reason:string}> */
    public array $removeCalls = [];

    public function removePlayerFromLobby(object $worker, int $roomId, int $connId, string $reason): void
    {
        $this->remove($worker, $roomId, $connId, $reason);
    }

    public function removePlayerFromGame(object $worker, int $roomId, int $connId, string $reason): void
    {
        $this->remove($worker, $roomId, $connId, $reason);
    }

    /**
     * Спек host transfer (ANCHOR_CORE § Host Rules): следующий active
     * игрок FIFO становится хостом; если активных не осталось — комната
     * не трогается (уничтожение — забота RoomManager/LobbyService,
     * вне границ Admin-модуля и этого теста).
     */
    public function transferHost(object $worker, int $roomId): void
    {
        if (!isset($worker->rooms[$roomId])) {
            return;
        }
        foreach ($worker->rooms[$roomId]['players'] as $connId => $p) {
            if (($p['status'] ?? null) === 'active') {
                $worker->rooms[$roomId]['host_conn_id'] = $connId;
                return;
            }
        }
    }

    private function remove(object $worker, int $roomId, int $connId, string $reason): void
    {
        $this->removeCalls[] = ['roomId' => $roomId, 'connId' => $connId, 'reason' => $reason];

        $room = &$worker->rooms[$roomId];
        $player = $room['players'][$connId];

        // Контракт всех трёх реальных сервисов (ANCHOR_CORE § Room Structure):
        $room['all_players_history'][$connId] = [
            'user_id'    => $player['user_id'],
            'username'   => $player['username'],
            'total_paid' => $player['total_paid'],
        ];

        unset($room['players'][$connId]);
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
        'cards_count'     => 2,
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

function makeAdminConnection(int $adminId): SpyConnection
{
    $c = new SpyConnection();
    $c->userId = $adminId;
    $c->isAdmin = true;
    return $c;
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

// =============================================================================
// TEST 1 — REGRESSION (FIX-3): kick during 'playing', then admin_close_room.
// Kicked player must NOT be refunded twice.
// =============================================================================

echo "TEST 1: Kick (playing) followed by admin_close_room — no double refund\n";

$k1 = insertUser($pdo, 'kicked_player', 500, false);
$k2 = insertUser($pdo, 'remaining_player', 500, false);

$worker1 = makeWorker();
$worker1->rooms[1] = makeRoom(1, 200, 'playing', 40); // bank = 20(k1) + 20(k2)
$worker1->rooms[1]['players'][200] = makePlayer($k1, 'kicked_player', 20);
$worker1->rooms[1]['players'][201] = makePlayer($k2, 'remaining_player', 20);

$removal1 = new ContractAwareRemovalService();
$roomManager1 = new SpyRoomManager();
$admin1 = new AdminService($stmts, new FakeLogger(), $removal1, $removal1, null, $db, $roomManager1);
$adminConn1 = makeAdminConnection($adminId);

// Шаг 1: кик k1 из playing-комнаты.
$admin1->handleKickUser(['action' => 'admin_kick_user', 'user_id' => $k1], $adminConn1, $worker1);

assertEquals(520, getCoins($pdo, $k1), 'kicked player refunded 20 coins once (500 -> 520)');
assertEquals(20, $worker1->rooms[1]['bank'], 'bank reduced by kicked total_paid (40 -> 20)');
assertEquals(
    0,
    $worker1->rooms[1]['all_players_history'][200]['total_paid'] ?? null,
    'FIX-3: all_players_history records total_paid=0 for the kicked player (already refunded)'
);

// Шаг 2: админ закрывает комнату.
$admin1->handleCloseRoom(['action' => 'admin_close_room', 'room_id' => 1], $adminConn1, $worker1);

assertEquals(
    520,
    getCoins($pdo, $k1),
    'REGRESSION CHECK: kicked player still has 520 coins, NOT 540 — no double refund on close_room'
);
assertEquals(520, getCoins($pdo, $k2), 'remaining player refunded their own 20 coins once (500 -> 520)');
assertEquals(0, $worker1->rooms[1]['bank'] ?? 0, 'bank is 0 after close');

$totalStart = 40;                                   // исходный банк комнаты
$totalRefunded = (getCoins($pdo, $k1) - 500) + (getCoins($pdo, $k2) - 500);
assertEquals(
    $totalStart,
    $totalRefunded,
    'Economic Integrity Rule: total refunded across both users equals original bank exactly (40)'
);

// =============================================================================
// TEST 2 — Ban (no refund at ban-time) then admin_close_room refunds fully.
// Confirms FIX-3 does not affect the ban path (positive control).
// =============================================================================

echo "\nTEST 2: Ban (playing, no refund) followed by admin_close_room — full refund on close\n";

$b1 = insertUser($pdo, 'banned_player', 500, false);
$b2 = insertUser($pdo, 'active_player', 500, false);

$worker2 = makeWorker();
$worker2->rooms[2] = makeRoom(2, 300, 'playing', 40);
$worker2->rooms[2]['players'][300] = makePlayer($b1, 'banned_player', 20);
$worker2->rooms[2]['players'][301] = makePlayer($b2, 'active_player', 20);
$bannedConn = new SpyConnection();
$bannedConn->userId = $b1;
$worker2->userConnections[$b1] = $bannedConn;

$removal2 = new ContractAwareRemovalService();
$roomManager2 = new SpyRoomManager();
$admin2 = new AdminService($stmts, new FakeLogger(), $removal2, $removal2, null, $db, $roomManager2);
$adminConn2 = makeAdminConnection($adminId);

$admin2->handleBanUser(
    ['action' => 'admin_ban_user', 'user_id' => $b1, 'duration' => '1d'],
    $adminConn2,
    $worker2
);

assertEquals(500, getCoins($pdo, $b1), 'ban does NOT refund at ban-time (coins unchanged)');
assertEquals(
    20,
    $worker2->rooms[2]['all_players_history'][300]['total_paid'] ?? null,
    'all_players_history keeps full total_paid=20 for banned player (nothing refunded yet)'
);

$admin2->handleCloseRoom(['action' => 'admin_close_room', 'room_id' => 2], $adminConn2, $worker2);

assertEquals(520, getCoins($pdo, $b1), 'banned player refunded exactly once on close (500 -> 520)');
assertEquals(520, getCoins($pdo, $b2), 'active player refunded once on close (500 -> 520)');
assertEquals(0, $worker2->rooms[2]['bank'] ?? 0, 'bank is 0 after close');

// =============================================================================
// TEST 3 — Mixed history: kick + already-removed(leave, pre-existing history
// entry) + still-active player, single admin_close_room call. Verifies the
// full-room refund sums to the original bank with no gaps or duplicates.
// =============================================================================

echo "\nTEST 3: Mixed history (kick + prior leave + active) — single close_room refund\n";

$m1 = insertUser($pdo, 'kicked_m', 500, false);   // будет kicked (refund at kick time)
$m2 = insertUser($pdo, 'left_m', 500, false);     // уже ушёл раньше (history без рефанда)
$m3 = insertUser($pdo, 'active_m', 500, false);   // всё ещё в комнате

$worker3 = makeWorker();
$worker3->rooms[3] = makeRoom(3, 400, 'playing', 60); // 20(m1) + 20(m2 already left) + 20(m3)
$worker3->rooms[3]['players'][400] = makePlayer($m1, 'kicked_m', 20);
$worker3->rooms[3]['players'][402] = makePlayer($m3, 'active_m', 20);
// m2 уже покинул комнату ранее (leave, ANCHOR_CORE § Leave During Game — coins remain in bank):
$worker3->rooms[3]['all_players_history'][401] = [
    'user_id'    => $m2,
    'username'   => 'left_m',
    'total_paid' => 20,
];

$removal3 = new ContractAwareRemovalService();
$roomManager3 = new SpyRoomManager();
$admin3 = new AdminService($stmts, new FakeLogger(), $removal3, $removal3, null, $db, $roomManager3);
$adminConn3 = makeAdminConnection($adminId);

$admin3->handleKickUser(['action' => 'admin_kick_user', 'user_id' => $m1], $adminConn3, $worker3);
assertEquals(520, getCoins($pdo, $m1), 'm1 refunded once at kick time');
assertEquals(40, $worker3->rooms[3]['bank'], 'bank reduced by m1 refund (60 -> 40)');

$admin3->handleCloseRoom(['action' => 'admin_close_room', 'room_id' => 3], $adminConn3, $worker3);

assertEquals(520, getCoins($pdo, $m1), 'm1 still 520 after close — no double refund (FIX-3 holds across 3 entries)');
assertEquals(520, getCoins($pdo, $m2), 'm2 (pre-existing history, never refunded) gets full refund on close (500 -> 520)');
assertEquals(520, getCoins($pdo, $m3), 'm3 (still active) gets full refund on close (500 -> 520)');

$refunded3 = (getCoins($pdo, $m1) - 500) + (getCoins($pdo, $m2) - 500) + (getCoins($pdo, $m3) - 500);
assertEquals(60, $refunded3, 'Economic Integrity Rule: 3-participant total refund equals original bank (60), no burn/duplication');

// =============================================================================
// TEST 4 — Real Admin + real Logger integration: actions get logged and are
// retrievable via handleGetLogs() (exercises AdminService + Logger together,
// not mocks — closes the EPIC-9.5 verification gap end-to-end).
// =============================================================================

echo "\nTEST 4: Real Logger + AdminService::handleGetLogs() after real actions\n";

$realLogger = new \Lotto\Core\Logger();
$q1 = insertUser($pdo, 'log_target', 500, false);

$worker4 = makeWorker();
$worker4->rooms[4] = makeRoom(4, 500, 'waiting', 0);
$worker4->rooms[4]['players'][500] = makePlayer($q1, 'log_target', 0);

$removal4 = new ContractAwareRemovalService();
$admin4 = new AdminService($stmts, $realLogger, $removal4, $removal4, null, $db, new SpyRoomManager());
$adminConn4 = makeAdminConnection($adminId);

$marker = 'integration-marker-' . uniqid();
$admin4->handleKickUser(['action' => 'admin_kick_user', 'user_id' => $q1], $adminConn4, $worker4);
$realLogger->info($marker);

$admin4->handleGetLogs(['action' => 'admin_get_logs'], $adminConn4);
$logsResp = $adminConn4->lastSent();

assertEquals('admin_logs_data', $logsResp['type'] ?? null, 'handleGetLogs returns admin_logs_data after real activity');
$found = false;
foreach (($logsResp['lines'] ?? []) as $line) {
    if (str_contains($line, $marker)) {
        $found = true;
        break;
    }
}
assertTrue($found, 'kick + marker log lines are present in the retrieved tail (real Logger + AdminService integration)');

// =============================================================================
// Summary
// =============================================================================

echo "\n" . str_repeat('=', 60) . "\n";
echo "RESULT: {$passed} passed, {$failed} failed\n";
echo str_repeat('=', 60) . "\n";

exit($failed > 0 ? 1 : 0);
