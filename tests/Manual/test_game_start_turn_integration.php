<?php

declare(strict_types=1);

/**
 * EPIC-13.4 — Turn-start integration test (handleStartGame entry point).
 * Run: php tests/Manual/test_game_start_turn_integration.php
 */

require_once __DIR__ . '/mock_timer.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Helpers.php';

use Lotto\Game\GameService;
use Lotto\Game\GameTurnService;
use Lotto\Game\LottoEngine;
use Lotto\Game\VictoryService;
use Lotto\Game\ApartmentService;
use Lotto\Game\GameFinishService;
use Lotto\Game\ReconnectService;

$passed = 0;
$failed = 0;

function ok(string $label): void
{
    global $passed;
    $passed++;
    echo "[PASS] $label\n";
}

function fail(string $label, string $reason = ''): void
{
    global $failed;
    $failed++;
    echo "[FAIL] $label" . ($reason ? " — $reason" : '') . "\n";
}

function assert_true(bool $cond, string $label, string $reason = ''): void
{
    $cond ? ok($label) : fail($label, $reason);
}

class TIntMockConnection
{
    public int $id;
    public ?int $userId;
    public string $username;
    public array $sent = [];

    public function __construct(int $id, int $userId, string $username)
    {
        $this->id = $id;
        $this->userId = $userId;
        $this->username = $username;
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

class TIntMockWorker
{
    public array $rooms = [];
    public array $botWinStreaks = [];
    public ?array $serverSettings = null;
}

class TIntMockPDO
{
    public bool $committed = false;
    public function beginTransaction(): void {}
    public function commit(): void { $this->committed = true; }
    public function rollBack(): void {}
}

class TIntMockDatabase
{
    public function __construct(private TIntMockPDO $pdo) {}
    public function getPdo(): TIntMockPDO { return $this->pdo; }
}

class TIntMockStmts
{
    public function __construct(private array $users) {}

    public function get(string $key): object
    {
        $users = $this->users;
        if ($key === 'user_by_id') {
            return new class($users) {
                private array $users;
                private ?int $userId = null;
                public function __construct(array $u) { $this->users = $u; }
                public function execute(array $p): void { $this->userId = $p[0]; }
                public function fetch(): array|false { return $this->users[$this->userId] ?? false; }
            };
        }
        if ($key === 'update_user_coins') {
            return new class {
                public function execute(array $p): void {}
                public function fetch(): false { return false; }
            };
        }
        throw new InvalidArgumentException("Unknown: $key");
    }
}

class TIntMockLogger
{
    public function info(string $m): void {}
    public function warning(string $m): void {}
    public function error(string $m): void {}
}

class TIntMockLobby
{
    public function removePlayerFromLobby(): void {}
}

function makeRoom(int $id, array $drawerOrder): array
{
    return [
        'room_id' => $id,
        'status' => 'waiting',
        'host_conn_id' => $drawerOrder[0],
        'drawer_order' => $drawerOrder,
        'active_drawer_conn_id' => null,
        'bank' => 0,
        'bag' => [],
        'drawn_numbers' => [],
        'lobby_afk_timer_id' => null,
        'game_afk_timer_id' => null,
        'apartment_timer_id' => null,
        'players' => [],
        'all_players_history' => [],
    ];
}

function makePlayer(TIntMockConnection $conn, int $cardsCount = 1): array
{
    return [
        'user_id' => $conn->userId,
        'username' => $conn->username,
        'cards' => [],
        'cards_count' => $cardsCount,
        'total_paid' => 0,
        'last_action' => time(),
        'afk_start' => null,
        'strikes' => 0,
        'auto_draws' => 0,
        'status' => 'active',
        'session_token' => 'tok_' . $conn->id,
        'reconnect_timer' => null,
        'connection' => $conn,
        'immune' => false,
    ];
}

// ---------------------------------------------------------------------------
// Integration: handleStartGame → game_started → your_turn → afk_start → timer
// ---------------------------------------------------------------------------

{
    MockTimer::reset();
    $host = new TIntMockConnection(1, 10, 'host');
    $p2 = new TIntMockConnection(2, 20, 'p2');
    $worker = new TIntMockWorker();
    $room = makeRoom(1, [1, 2]);
    $room['players'][1] = makePlayer($host, 1);
    $room['players'][2] = makePlayer($p2, 1);
    $worker->rooms[1] = $room;

    $db = new TIntMockDatabase(new TIntMockPDO());
    $users = [
        10 => ['id' => 10, 'coins' => 500],
        20 => ['id' => 20, 'coins' => 500],
    ];
    $stmts = new TIntMockStmts($users);
    $log = new TIntMockLogger();
    $eng = new LottoEngine();
    $vic = new VictoryService();
    $apt = new ApartmentService($db, $stmts, $log);
    $fin = (new ReflectionClass(GameFinishService::class))->newInstanceWithoutConstructor();
    $turn = new GameTurnService($log, $vic, $apt, $fin);
    $game = new GameService($db, $stmts, $eng, $log, $vic, $apt, $fin, $turn);
    $reconnect = new ReconnectService(new TIntMockLobby(), $game, $log);
    $game->setReconnectService($reconnect);
    $turn->setReconnectService($reconnect);

    $game->handleStartGame($host, $worker);

    $r = $worker->rooms[1];

    assert_true($r['status'] === 'playing', 'integration: status=playing');
    assert_true(count($host->sentOfType('game_started')) === 1, 'integration: host game_started');
    assert_true(count($p2->sentOfType('game_started')) === 1, 'integration: p2 game_started');
    assert_true(count($host->sentOfType('your_turn')) === 1, 'integration: first drawer your_turn');
    assert_true(count($p2->sentOfType('your_turn')) === 0, 'integration: non-drawer no your_turn');
    assert_true($r['players'][1]['afk_start'] !== null, 'integration: afk_start set on drawer');
    assert_true(!empty($r['game_afk_timer_id']), 'integration: game_afk_timer_id armed');
}

// ---------------------------------------------------------------------------
// Edge case: GameService without setReconnectService() — your_turn only, no AFK timer
// ---------------------------------------------------------------------------

{
    MockTimer::reset();
    $host = new TIntMockConnection(1, 10, 'host');
    $p2 = new TIntMockConnection(2, 20, 'p2');
    $worker = new TIntMockWorker();
    $room = makeRoom(2, [1, 2]);
    $room['players'][1] = makePlayer($host, 1);
    $room['players'][2] = makePlayer($p2, 1);
    $worker->rooms[2] = $room;

    $db = new TIntMockDatabase(new TIntMockPDO());
    $users = [
        10 => ['id' => 10, 'coins' => 500],
        20 => ['id' => 20, 'coins' => 500],
    ];
    $stmts = new TIntMockStmts($users);
    $log = new TIntMockLogger();
    $eng = new LottoEngine();
    $vic = new VictoryService();
    $apt = new ApartmentService($db, $stmts, $log);
    $fin = (new ReflectionClass(GameFinishService::class))->newInstanceWithoutConstructor();
    $turn = new GameTurnService($log, $vic, $apt, $fin);
    $game = new GameService($db, $stmts, $eng, $log, $vic, $apt, $fin, $turn);
    // Deliberately no setReconnectService() — mirrors test_game_start.php / test_turn_system.php

    $game->handleStartGame($host, $worker);

    $r = $worker->rooms[2];

    assert_true(count($host->sentOfType('your_turn')) === 1, 'no-reconnect-svc: your_turn still sent');
    assert_true($r['players'][1]['afk_start'] !== null, 'no-reconnect-svc: afk_start still set');
    assert_true(empty($r['game_afk_timer_id']), 'no-reconnect-svc: game_afk_timer_id not armed');
    assert_true(\MockTimer::$addCount === 0, 'no-reconnect-svc: no timer registered in MockTimer');
}

$total = $passed + $failed;
echo "\n--- EPIC-13.4 Turn-Start Integration Test ---\n";
echo "$passed / $total PASSED\n";
if ($failed > 0) {
    echo "$failed FAILED\n";
    exit(1);
}
exit(0);
