<?php

declare(strict_types=1);

/**
 * EPIC-11.0 — Core user journey integration tests.
 *
 * Chains auth → lobby → game handlers without a live WebSocket server
 * (MockConnection/MockWorker). Complements per-module tests and live-server
 * routing tests (test_*_packet_routing.php).
 *
 * Run: php tests/Manual/test_phase11_core_flows.php
 */

require_once __DIR__ . '/mock_timer.php';

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    die("FAIL: vendor/autoload.php not found. Run: composer install\n");
}
require_once $autoload;
require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';

use Lotto\Auth\AuthService;
use Lotto\Auth\AuthHandler;
use Lotto\Auth\SessionService;
use Lotto\Infrastructure\PreparedStatements;
use Lotto\Core\Logger;
use Lotto\Core\RoomManager;
use Lotto\Core\Constants;
use Lotto\Lobby\LobbyService;
use Lotto\Lobby\LobbyHandler;
use Lotto\Game\LottoEngine;
use Lotto\Game\VictoryService;
use Lotto\Game\ApartmentService;
use Lotto\Game\GameFinishService;
use Lotto\Game\GameService;
use Lotto\Game\GameHandler;

$passed = 0;
$failed = 0;

function ok(string $name, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        echo "[PASS] {$name}\n";
        $passed++;
    } else {
        echo "[FAIL] {$name}" . ($detail ? " — {$detail}" : '') . "\n";
        $failed++;
    }
}

function summary(): never
{
    global $passed, $failed;
    $total = $passed + $failed;
    echo "\n" . str_repeat('-', 40) . "\n";
    echo "EPIC-11.0 core flows: {$passed}/{$total} passed\n";
    exit($failed > 0 ? 1 : 0);
}

function makeTestPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec("
        CREATE TABLE users (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            username         TEXT    NOT NULL UNIQUE,
            password_hash    TEXT    NOT NULL,
            coins            INTEGER NOT NULL DEFAULT 500,
            is_admin         INTEGER NOT NULL DEFAULT 0,
            banned_until     INTEGER NOT NULL DEFAULT 0,
            last_daily_bonus INTEGER NOT NULL DEFAULT 0
        )
    ");
    return $pdo;
}

class FlowConnection
{
    private static int $nextId = 1;

    public int $id;
    public ?int $userId = null;
    public ?string $username = null;
    public bool $isAdmin = false;
    public ?string $sessionToken = null;
    public array $sent = [];

    public function __construct()
    {
        $this->id = self::$nextId++;
    }

    public function send(string $data): void
    {
        $this->sent[] = json_decode($data, true);
    }

    public function lastPacket(): ?array
    {
        return $this->sent[array_key_last($this->sent)] ?? null;
    }

    public function packetsOfType(string $type): array
    {
        return array_values(array_filter($this->sent, fn($p) => ($p['type'] ?? '') === $type));
    }
}

class FlowWorker
{
    public array $rooms = [];
    public array $userConnections = [];
    public array $sessionTokens = [];
}

class TestDatabase extends \Lotto\Infrastructure\Database
{
    public function __construct(private PDO $testPdo)
    {
    }

    public function getPdo(): PDO
    {
        return $this->testPdo;
    }
}

function buildStack(PDO $pdo): array
{
    $logger = new Logger('/dev/null');
    $statements = new PreparedStatements($pdo);
    $sessionService = new SessionService();
    $db = new TestDatabase($pdo);
    $authService = new AuthService($db, $statements, $logger, $sessionService);
    $authHandler = new AuthHandler($authService, $sessionService, $logger);
    $roomManager = new RoomManager($logger);
    $lobbyService = new LobbyService($roomManager, $logger);
    $lobbyHandler = new LobbyHandler($lobbyService);
    $lottoEngine = new LottoEngine();
    $victoryService = new VictoryService();
    $apartmentService = new ApartmentService($db, $statements, $logger);
    $gameFinishService = new GameFinishService($db, $statements, $logger);
    $gameService = new GameService(
        $db,
        $statements,
        $lottoEngine,
        $logger,
        $victoryService,
        $apartmentService,
        $gameFinishService
    );
    $gameHandler = new GameHandler($gameService);

    return compact(
        'authHandler',
        'lobbyHandler',
        'gameHandler',
        'sessionService'
    );
}

echo "\n=== SUITE 1: register → login → create_room → join_room → start_game ===\n";

$pdo = makeTestPdo();
$stack = buildStack($pdo);
$worker = new FlowWorker();

$hostConn = new FlowConnection();
$guestConn = new FlowConnection();

$stack['authHandler']->handleRegister(
    ['action' => 'register', 'username' => 'host11', 'password' => 'secret123'],
    $hostConn,
    $worker
);
ok('register: host receives auth_result', ($hostConn->lastPacket()['type'] ?? '') === 'auth_result');

$stack['authHandler']->handleLogin(
    ['action' => 'login', 'username' => 'host11', 'password' => 'secret123'],
    $hostConn,
    $worker
);
ok('login: host userId bound', $hostConn->userId !== null);
ok('login: host in userConnections', isset($worker->userConnections[$hostConn->userId]));

$stack['authHandler']->handleRegister(
    ['action' => 'register', 'username' => 'guest11', 'password' => 'secret123'],
    $guestConn,
    $worker
);
$stack['authHandler']->handleLogin(
    ['action' => 'login', 'username' => 'guest11', 'password' => 'secret123'],
    $guestConn,
    $worker
);
ok('login: guest userId bound', $guestConn->userId !== null);

$stack['lobbyHandler']->handleCreateRoom(
    ['action' => 'create_room', 'max_players' => 4, 'cards_count' => 1],
    $hostConn,
    $worker
);
$roomJoined = $hostConn->packetsOfType('room_joined');
ok('create_room: room_joined packet sent', count($roomJoined) === 1);
$roomId = (int)($roomJoined[0]['room_id'] ?? 0);
ok('create_room: valid room_id', $roomId > 0);

$stack['lobbyHandler']->handleJoinRoom(
    ['action' => 'join_room', 'room_id' => $roomId],
    $guestConn,
    $worker
);
ok('join_room: guest receives room_joined', ($guestConn->lastPacket()['type'] ?? '') === 'room_joined');
ok('join_room: room has 2 players', count($worker->rooms[$roomId]['players'] ?? []) === 2);

$stack['gameHandler']->handleStartGame($hostConn, $worker);
$gameStarted = $hostConn->packetsOfType('game_started');
ok('start_game: game_started broadcast', count($gameStarted) >= 1);
ok('start_game: room status is playing', ($worker->rooms[$roomId]['status'] ?? '') === 'playing');
ok('start_game: each player has cards', isset($worker->rooms[$roomId]['players'])
    && count($worker->rooms[$roomId]['players']) === 2
    && isset($worker->rooms[$roomId]['players'][$hostConn->id]['cards'])
    && isset($worker->rooms[$roomId]['players'][$guestConn->id]['cards']));

echo "\n=== SUITE 2: invalid state transitions ===\n";

$stack['gameHandler']->handleStartGame($hostConn, $worker);
$err = $hostConn->lastPacket();
ok('start_game while playing: error returned', ($err['type'] ?? '') === 'error');

$waitingPdo = makeTestPdo();
$waitingStack = buildStack($waitingPdo);
$waitingWorker = new FlowWorker();
$wHost = new FlowConnection();
$waitingStack['authHandler']->handleRegister(
    ['action' => 'register', 'username' => 'waithost', 'password' => 'secret123'],
    $wHost,
    $waitingWorker
);
$waitingStack['authHandler']->handleLogin(
    ['action' => 'login', 'username' => 'waithost', 'password' => 'secret123'],
    $wHost,
    $waitingWorker
);
$waitingStack['lobbyHandler']->handleCreateRoom(
    ['action' => 'create_room', 'max_players' => 4, 'cards_count' => 1],
    $wHost,
    $waitingWorker
);
$waitRoomId = (int)($wHost->packetsOfType('room_joined')[0]['room_id'] ?? 0);

$waitingStack['gameHandler']->handleDrawBarrel($wHost, $waitingWorker);
ok('draw_barrel in waiting: rejected', ($wHost->lastPacket()['type'] ?? '') === 'error');

echo "\n=== SUITE 3: rate limit constants ===\n";

ok('RATE_LIMIT_PACKETS_PER_WINDOW is 15', Constants::RATE_LIMIT_PACKETS_PER_WINDOW === 15);
ok('RATE_LIMIT_WINDOW_SECONDS is 1', Constants::RATE_LIMIT_WINDOW_SECONDS === 1);
ok('MAX_TOTAL_PLAYERS is 150', Constants::MAX_TOTAL_PLAYERS === 150);

echo "\n=== SUITE 4: non-host cannot start game ===\n";

$nonHost = new FlowConnection();
$waitingStack['authHandler']->handleRegister(
    ['action' => 'register', 'username' => 'nonhost', 'password' => 'secret123'],
    $nonHost,
    $waitingWorker
);
$waitingStack['authHandler']->handleLogin(
    ['action' => 'login', 'username' => 'nonhost', 'password' => 'secret123'],
    $nonHost,
    $waitingWorker
);
$waitingStack['lobbyHandler']->handleJoinRoom(
    ['action' => 'join_room', 'room_id' => $waitRoomId],
    $nonHost,
    $waitingWorker
);
$waitingStack['gameHandler']->handleStartGame($nonHost, $waitingWorker);
ok('start_game by non-host: error.not_your_turn', ($nonHost->lastPacket()['code'] ?? '') === 'error.not_your_turn');

summary();
