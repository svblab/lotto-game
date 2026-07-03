<?php

declare(strict_types=1);

/**
 * EPIC-4.5 — Game Start integration tests
 * Run: php tests/Manual/test_game_start.php
 *
 * Тестирует GameService::handleStartGame() через mock-объекты.
 * Реальная БД не используется — PDO подменяется stub-классом.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Helpers.php';

use Lotto\Game\GameService;
use Lotto\Game\LottoEngine;
use Lotto\Game\VictoryService;
use Lotto\Game\ApartmentService;
use Lotto\Game\GameFinishService;
use Lotto\Core\Constants;

// ---------------------------------------------------------------------------
// Test helpers
// ---------------------------------------------------------------------------

$passed = 0;
$failed = 0;

function ok(string $label): void {
    global $passed;
    $passed++;
    echo "[PASS] $label\n";
}

function fail(string $label, string $reason = ''): void {
    global $failed;
    $failed++;
    echo "[FAIL] $label" . ($reason ? " — $reason" : '') . "\n";
}

function assert_true(bool $cond, string $label, string $reason = ''): void {
    $cond ? ok($label) : fail($label, $reason);
}

// ---------------------------------------------------------------------------
// Mock infrastructure
// ---------------------------------------------------------------------------

/**
 * Mock connection — имитирует Workerman\Connection.
 */
class MockConnection {
    public int $id;
    public ?int $userId;
    public string $username;
    public bool $isAdmin = false;
    public array $sent = [];
    public ?string $lastError = null;

    public function __construct(int $id, int $userId, string $username) {
        $this->id       = $id;
        $this->userId   = $userId;
        $this->username = $username;
    }

    public function send(string $data): void {
        $decoded = json_decode($data, true);
        $this->sent[] = $decoded;
        if (isset($decoded['type']) && $decoded['type'] === 'error') {
            $this->lastError = $decoded['code'] ?? 'unknown';
        }
    }

    public function lastSent(): ?array {
        return end($this->sent) ?: null;
    }
}

/**
 * Mock Worker.
 */
class MockWorker {
    public array $rooms = [];
    public array $userConnections = [];
}

/**
 * Mock PDOStatement.
 */
class MockPDOStatement {
    private array $rows;
    public function __construct(array $rows) { $this->rows = $rows; }
    public function execute(array $params = []): void {}
    public function fetch(): array|false { return array_shift($this->rows) ?? false; }
}

/**
 * Mock PDO — поддерживает транзакции и записывает вызовы.
 */
class MockPDO {
    public bool $inTransaction = false;
    public bool $committed = false;
    public bool $rolledBack = false;
    public array $executed = [];

    public function beginTransaction(): void { $this->inTransaction = true; }
    public function commit(): void { $this->committed = true; $this->inTransaction = false; }
    public function rollBack(): void { $this->rolledBack = true; $this->inTransaction = false; }
}

/**
 * Mock Database.
 */
class MockDatabase {
    public MockPDO $pdo;
    public function __construct(MockPDO $pdo) { $this->pdo = $pdo; }
    public function getPdo(): MockPDO { return $this->pdo; }
}

/**
 * Mock PreparedStatements.
 * user_by_id возвращает заданные строки; update_user_coins ничего не делает.
 */
class MockPreparedStatements {
    /** @var array<int, array> userId → row */
    private array $users;
    /** @var array записи об обновлениях */
    public array $updates = [];

    public function __construct(array $users) {
        $this->users = $users;
    }

    public function get(string $key): object {
        if ($key === 'user_by_id') {
            $users = $this->users;
            return new class($users) {
                private array $users;
                private ?int $userId = null;
                public function __construct(array $u) { $this->users = $u; }
                public function execute(array $p): void { $this->userId = $p[0]; }
                public function fetch(): array|false {
                    return $this->users[$this->userId] ?? false;
                }
            };
        }

        if ($key === 'update_user_coins') {
            // Записываем в $this->updates через замыкание на родительский объект
            $parent = $this;
            return new class($parent) {
                private object $parent;
                public function __construct(object $p) { $this->parent = $p; }
                public function execute(array $p): void {
                    $this->parent->updates[] = ['coins' => $p[0], 'user_id' => $p[1]];
                }
                public function fetch(): false { return false; }
            };
        }

        throw new \InvalidArgumentException("Unknown key: $key");
    }
}

/**
 * Mock Logger.
 */
class MockLogger {
    public array $logs = [];
    public function info(string $m): void  { $this->logs[] = ['INFO', $m]; }
    public function error(string $m): void { $this->logs[] = ['ERROR', $m]; }
    public function warning(string $m): void { $this->logs[] = ['WARNING', $m]; }
}

// ---------------------------------------------------------------------------
// Factory helpers
// ---------------------------------------------------------------------------

function makeRoom(int $hostConnId, array $playerConnIds, int $maxPlayers = 10): array {
    $drawerOrder = array_merge([$hostConnId], array_filter($playerConnIds, fn($id) => $id !== $hostConnId));
    return [
        'room_id'               => 1,
        'host_conn_id'          => $hostConnId,
        'bet_per_card'          => 10,
        'max_players'           => $maxPlayers,
        'password_hash'         => null,
        'status'                => 'waiting',
        'bank'                  => 0,
        'apartment_fired'       => false,
        'pause_for_apartment'   => false,
        'apartment_responses'   => [],
        'game_afk_timer_id'     => null,
        'apartment_timer_id'    => null,
        'lobby_afk_timer_id'    => null,
        'active_drawer_conn_id' => null,
        'drawer_order'          => $drawerOrder,
        'bag'                   => [],
        'drawn_numbers'         => [],
        'players'               => [],
        'all_players_history'   => [],
    ];
}

function makePlayer(MockConnection $conn, int $cardsCount = 1): array {
    return [
        'user_id'        => $conn->userId,
        'username'       => $conn->username,
        'cards'          => [],
        'cards_count'    => $cardsCount,
        'total_paid'     => 0,
        'last_action'    => time(),
        'afk_start'      => null,
        'strikes'        => 0,
        'auto_draws'     => 0,
        'status'         => 'active',
        'session_token'  => 'tok_' . $conn->id,
        'reconnect_timer'=> null,
        'connection'     => $conn,
        'immune'         => false,
    ];
}

function makeService(array $users, MockPDO $pdo): array {
    $db    = new MockDatabase($pdo);
    $stmts = new MockPreparedStatements($users);
    $log   = new MockLogger();
    $eng   = new LottoEngine();
    $vic   = new VictoryService();
    $apt   = new ApartmentService($db, $stmts, $log);
    // finishGame() не вызывается ни в одном сценарии EPIC-4.5 (только
    // handleStartGame()). GameFinishService — final class со строгой
    // типизацией зависимостей (ADR-002), анонимный класс не проходит
    // проверку типа конструктора GameService. newInstanceWithoutConstructor()
    // — уже принятый в проекте паттерн для этого случая, см.
    // tests/Manual/test_apartment.php и tests/Manual/test_turn_system.php.
    $fin   = (new \ReflectionClass(\Lotto\Game\GameFinishService::class))->newInstanceWithoutConstructor();
    $svc   = new GameService($db, $stmts, $eng, $log, $vic, $apt, $fin);
    return [$svc, $log, $stmts, $pdo];
}

// ---------------------------------------------------------------------------
// TESTS
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Group 1: Auth guard
// ---------------------------------------------------------------------------

{
    $conn = new MockConnection(1, 0, 'ghost');
    $conn->userId = null; // unauthenticated
    $worker = new MockWorker();
    [$svc] = makeService([], new MockPDO());
    $svc->handleStartGame($conn, $worker);
    assert_true($conn->lastError === 'error.auth_required', 'Auth: unauthenticated rejected');
}

// ---------------------------------------------------------------------------
// Group 2: Room guard
// ---------------------------------------------------------------------------

{
    $conn = new MockConnection(1, 10, 'host');
    $worker = new MockWorker(); // нет комнат
    [$svc] = makeService([], new MockPDO());
    $svc->handleStartGame($conn, $worker);
    assert_true($conn->lastError === 'error.room_not_found', 'Room: not in room rejected');
}

// ---------------------------------------------------------------------------
// Group 3: Host guard
// ---------------------------------------------------------------------------

{
    $host   = new MockConnection(1, 10, 'host');
    $guest  = new MockConnection(2, 20, 'guest');
    $worker = new MockWorker();
    $room   = makeRoom(1, [1, 2]);
    $room['players'][1] = makePlayer($host);
    $room['players'][2] = makePlayer($guest);
    $worker->rooms[1] = $room;
    [$svc] = makeService([], new MockPDO());
    $svc->handleStartGame($guest, $worker); // гость пытается запустить
    assert_true($guest->lastError === 'error.not_your_turn', 'Host: non-host rejected');
}

// ---------------------------------------------------------------------------
// Group 4: Status guard
// ---------------------------------------------------------------------------

{
    $host  = new MockConnection(1, 10, 'host');
    $p2    = new MockConnection(2, 20, 'p2');
    $worker = new MockWorker();
    $room   = makeRoom(1, [1, 2]);
    $room['status'] = 'playing'; // уже играем
    $room['players'][1] = makePlayer($host);
    $room['players'][2] = makePlayer($p2);
    $worker->rooms[1] = $room;
    [$svc] = makeService([], new MockPDO());
    $svc->handleStartGame($host, $worker);
    assert_true($host->lastError === 'error.not_your_turn', 'Status: playing room rejected');
}

// ---------------------------------------------------------------------------
// Group 5: Min players guard
// ---------------------------------------------------------------------------

{
    $host  = new MockConnection(1, 10, 'host');
    $worker = new MockWorker();
    $room   = makeRoom(1, [1]);
    $room['players'][1] = makePlayer($host);
    $worker->rooms[1] = $room;
    [$svc] = makeService([], new MockPDO());
    $svc->handleStartGame($host, $worker);
    assert_true($host->lastError === 'error.not_your_turn', 'Players: 1 player rejected');
}

// ---------------------------------------------------------------------------
// Group 6: Insufficient coins
// ---------------------------------------------------------------------------

{
    $host  = new MockConnection(1, 10, 'host');
    $p2    = new MockConnection(2, 20, 'p2');
    $worker = new MockWorker();
    $room   = makeRoom(1, [1, 2]);
    $room['players'][1] = makePlayer($host, 1);
    $room['players'][2] = makePlayer($p2, 1);
    $worker->rooms[1] = $room;

    $users = [
        10 => ['id' => 10, 'coins' => 5],  // только 5, нужно 10
        20 => ['id' => 20, 'coins' => 100],
    ];
    [$svc] = makeService($users, new MockPDO());
    $svc->handleStartGame($host, $worker);
    assert_true($host->lastError === 'error.not_your_turn', 'Economy: insufficient coins rejected');
}

// ---------------------------------------------------------------------------
// Group 7: Successful game start — 2 players, 1 card each
// ---------------------------------------------------------------------------

{
    $host  = new MockConnection(1, 10, 'host');
    $p2    = new MockConnection(2, 20, 'p2');
    $worker = new MockWorker();
    $room   = makeRoom(1, [1, 2]);
    $room['players'][1] = makePlayer($host, 1);
    $room['players'][2] = makePlayer($p2, 1);
    $worker->rooms[1] = $room;

    $pdo = new MockPDO();
    $users = [
        10 => ['id' => 10, 'coins' => 500],
        20 => ['id' => 20, 'coins' => 500],
    ];
    [$svc, $log, $stmts] = makeService($users, $pdo);
    $svc->handleStartGame($host, $worker);

    $r = $worker->rooms[1];

    // Status
    assert_true($r['status'] === 'playing',       'Start: status=playing');

    // Bank = 10+10 = 20
    assert_true($r['bank'] === 20,                'Start: bank=20 (2×1 card)');

    // Bag: 90 numbers
    assert_true(count($r['bag']) === 90,           'Start: bag has 90 numbers');
    $sortedBag = $r['bag']; sort($sortedBag);
    assert_true($sortedBag === range(1, 90),       'Start: bag contains 1-90');

    // drawn_numbers empty
    assert_true($r['drawn_numbers'] === [],        'Start: drawn_numbers empty');

    // active_drawer_conn_id = host (first in drawer_order)
    assert_true($r['active_drawer_conn_id'] === 1, 'Start: active_drawer=host');

    // Transaction committed
    assert_true($pdo->committed === true,          'Start: transaction committed');
    assert_true($pdo->rolledBack === false,        'Start: not rolled back');

    // Cards assigned
    assert_true(count($r['players'][1]['cards']) === 1, 'Start: host has 1 card');
    assert_true(count($r['players'][2]['cards']) === 1, 'Start: p2 has 1 card');

    // Cards valid
    $eng = new LottoEngine();
    assert_true($eng->validateCard($r['players'][1]['cards'][0]), 'Start: host card valid');
    assert_true($eng->validateCard($r['players'][2]['cards'][0]), 'Start: p2 card valid');

    // total_paid
    assert_true($r['players'][1]['total_paid'] === 10, 'Start: host total_paid=10');
    assert_true($r['players'][2]['total_paid'] === 10, 'Start: p2 total_paid=10');

    // game_started packets sent
    assert_true(count($host->sent) === 1,          'Start: host received game_started');
    assert_true(count($p2->sent) === 1,            'Start: p2 received game_started');

    $hostPkt = $host->sent[0];
    assert_true($hostPkt['type'] === 'game_started',     'Packet: type=game_started');
    assert_true($hostPkt['bank'] === 20,                  'Packet: bank=20');
    assert_true(count($hostPkt['drawer_order']) === 2,    'Packet: drawer_order has 2');
    assert_true($hostPkt['drawer_order'][0] === 'host',   'Packet: host first in drawer_order');
    assert_true(count($hostPkt['players']) === 2,         'Packet: 2 players in packet');

    // is_self semantics
    $selfEntry  = null;
    $otherEntry = null;
    foreach ($hostPkt['players'] as $pe) {
        if ($pe['username'] === 'host') $selfEntry  = $pe;
        else                            $otherEntry = $pe;
    }
    assert_true($selfEntry  !== null && $selfEntry['is_self'] === true,   'Packet: host is_self=true');
    assert_true($selfEntry['cards'] !== null,                              'Packet: host cards visible');
    assert_true($otherEntry !== null && $otherEntry['is_self'] === false,  'Packet: p2 is_self=false');
    assert_true($otherEntry['cards'] === null,                             'Packet: p2 cards hidden');

    // masks: all false
    $hostMasks = $selfEntry['masks'];
    $masksOk = true;
    foreach ($hostMasks as $maskCard) {
        foreach ($maskCard as $row) {
            foreach ($row as $cell) {
                if ($cell !== false) { $masksOk = false; break 3; }
            }
        }
    }
    assert_true($masksOk, 'Packet: all masks=false initially');
}

// ---------------------------------------------------------------------------
// Group 8: 2 cards each → bank=40
// ---------------------------------------------------------------------------

{
    $host  = new MockConnection(1, 10, 'host');
    $p2    = new MockConnection(2, 20, 'p2');
    $worker = new MockWorker();
    $room   = makeRoom(1, [1, 2]);
    $room['players'][1] = makePlayer($host, 2);
    $room['players'][2] = makePlayer($p2, 2);
    $worker->rooms[1] = $room;

    $pdo = new MockPDO();
    $users = [
        10 => ['id' => 10, 'coins' => 500],
        20 => ['id' => 20, 'coins' => 500],
    ];
    [$svc] = makeService($users, $pdo);
    $svc->handleStartGame($host, $worker);

    $r = $worker->rooms[1];
    assert_true($r['bank'] === 40, 'Economy: 2+2 cards → bank=40');
    assert_true(count($r['players'][1]['cards']) === 2, 'Cards: host has 2 cards');
    assert_true(count($r['players'][2]['cards']) === 2, 'Cards: p2 has 2 cards');

    $eng = new LottoEngine();
    foreach ($r['players'] as $player) {
        foreach ($player['cards'] as $i => $card) {
            assert_true($eng->validateCard($card), "Cards: player {$player['username']} card {$i} valid");
        }
    }
}

// ---------------------------------------------------------------------------
// Group 9: Mixed cards (1+2) → bank=30
// ---------------------------------------------------------------------------

{
    $host  = new MockConnection(1, 10, 'host');
    $p2    = new MockConnection(2, 20, 'p2');
    $p3    = new MockConnection(3, 30, 'p3');
    $worker = new MockWorker();
    $room   = makeRoom(1, [1, 2, 3]);
    $room['players'][1] = makePlayer($host, 1); // 10
    $room['players'][2] = makePlayer($p2, 2);   // 20
    $room['players'][3] = makePlayer($p3, 1);   // 10
    $worker->rooms[1] = $room;

    $pdo = new MockPDO();
    $users = [
        10 => ['id' => 10, 'coins' => 500],
        20 => ['id' => 20, 'coins' => 500],
        30 => ['id' => 30, 'coins' => 500],
    ];
    [$svc] = makeService($users, $pdo);
    $svc->handleStartGame($host, $worker);

    $r = $worker->rooms[1];
    assert_true($r['bank'] === 40, 'Economy: 1+2+1 cards → bank=40');
    assert_true($r['status'] === 'playing', 'Status: 3-player game started');
}

// ---------------------------------------------------------------------------
// Group 10: AFK fields reset
// ---------------------------------------------------------------------------

{
    $host  = new MockConnection(1, 10, 'host');
    $p2    = new MockConnection(2, 20, 'p2');
    $worker = new MockWorker();
    $room   = makeRoom(1, [1, 2]);
    $room['players'][1] = makePlayer($host, 1);
    $room['players'][2] = makePlayer($p2, 1);
    // Установить грязные AFK-поля
    $room['players'][1]['strikes']    = 2;
    $room['players'][1]['auto_draws'] = 1;
    $room['players'][1]['afk_start']  = time() - 100;
    $worker->rooms[1] = $room;

    $pdo = new MockPDO();
    $users = [
        10 => ['id' => 10, 'coins' => 500],
        20 => ['id' => 20, 'coins' => 500],
    ];
    [$svc] = makeService($users, $pdo);
    $svc->handleStartGame($host, $worker);

    $r = $worker->rooms[1];
    assert_true($r['players'][1]['strikes']    === 0,    'AFK: strikes reset to 0');
    assert_true($r['players'][1]['auto_draws'] === 0,    'AFK: auto_draws reset to 0');
    assert_true($r['players'][1]['afk_start']  === null, 'AFK: afk_start reset to null');
}

// ---------------------------------------------------------------------------
// RESULTS
// ---------------------------------------------------------------------------

$total = $passed + $failed;
echo "\n--- EPIC-4.5 Game Start Test Suite ---\n";
echo "$passed / $total PASSED\n";
if ($failed > 0) {
    echo "$failed FAILED\n";
    exit(1);
}
exit(0);
