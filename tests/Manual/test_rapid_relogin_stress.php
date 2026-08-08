<?php

declare(strict_types=1);

/**
 * EPIC-028.2 — rapid manual login/close cycles (no reconnect action).
 *
 * Models cross-browser timing: a prior socket may still sit in
 * $worker->connections when the next fresh login arrives, or onClose may
 * clear userId before the socket is removed (userId=null close in logs).
 *
 * Run: php tests/Manual/test_rapid_relogin_stress.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Helpers.php';

use Lotto\Auth\AuthHandler;
use Lotto\Auth\AuthService;
use Lotto\Auth\SessionGuardService;
use Lotto\Auth\SessionService;
use Lotto\Core\Logger;
use Lotto\Core\RoomManager;
use Lotto\Infrastructure\PreparedStatements;
use Lotto\Lobby\LobbyHostService;
use Lotto\Lobby\LobbyService;
use Lotto\Game\ReconnectService;

$passed = 0;
$failed = 0;

function rrsCheck(bool $cond, string $label): void
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

function makeMockConnection(int $id): object
{
    return new class($id) {
        public int $id;
        public $userId = null;
        public $username = null;
        public $isAdmin = false;
        public $sessionToken = null;
        public bool $closed = false;
        public bool $lottoEvicted = false;
        public bool $lottoCloseProcessed = false;
        public array $sent = [];

        public function __construct(int $id)
        {
            $this->id = $id;
        }

        public function send(string $msg): void
        {
            $this->sent[] = $msg;
        }

        public function close(): void
        {
            $this->closed = true;
        }
    };
}

function makePdo(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            coins INTEGER NOT NULL DEFAULT 500,
            is_admin INTEGER NOT NULL DEFAULT 0,
            banned_until INTEGER NOT NULL DEFAULT 0,
            last_daily_bonus INTEGER NOT NULL DEFAULT 0
        )
    ");

    return $pdo;
}

function makeStack(PDO $pdo): array
{
    $logger = new Logger('/dev/null');
    $statements = new PreparedStatements($pdo);
    $sessionService = new SessionService();
    $db = new class($pdo) extends \Lotto\Infrastructure\Database {
        public function __construct(private PDO $pdo) {}
        public function getPdo(): PDO { return $this->pdo; }
    };
    $authService = new AuthService($db, $statements, $logger, $sessionService);
    $sessionGuard = new SessionGuardService($logger);
    $authHandler = new AuthHandler($authService, $sessionService, $logger, $sessionGuard);
    $roomManager = new RoomManager($logger);
    $lobbyHostService = new LobbyHostService($roomManager, $logger);
    $lobbyService = new LobbyService($roomManager, $logger, $lobbyHostService);
    $gameStub = new class {
        public function calculateWinChances($a, $b) { return []; }
        public function handleDrawBarrel() {}
        public function handleNoSurvivors() {}
        public function finishGame() {}
        public function nextDrawer() {}
        public function startTurn() {}
    };
    $reconnectService = new ReconnectService($lobbyService, $gameStub, $logger);

    return [$authHandler, $sessionGuard, $lobbyService, $reconnectService, $roomManager];
}

function makeWorker(
    array $connections,
    SessionGuardService $sessionGuard,
    LobbyService $lobbyService,
    ReconnectService $reconnectService,
    RoomManager $roomManager
): object {
    return (object) [
        'connections' => $connections,
        'userConnections' => [],
        'sessionTokens' => [],
        'rooms' => [],
        'roomManager' => $roomManager,
        'lobbyService' => $lobbyService,
        'reconnectService' => $reconnectService,
        'sessionGuard' => $sessionGuard,
    ];
}

function doLogin(AuthHandler $authHandler, object $connection, object $worker, string $user, string $pass): void
{
    $authHandler->handleLogin(['username' => $user, 'password' => $pass], $connection, $worker);
}

function simulateClose(SessionGuardService $sessionGuard, object $connection, object $worker, bool $removeFromPool = true): void
{
    $sessionGuard->handleConnectionClose($connection, $worker);
    if ($removeFromPool) {
        $worker->connections = array_values(array_filter(
            $worker->connections,
            static fn($c) => $c !== $connection
        ));
    }
}

function countLiveAuthForUser(object $worker, int $userId): int
{
    $count = 0;
    foreach ($worker->connections as $connection) {
        if (empty($connection->closed) && (
            (int) ($connection->userId ?? 0) === $userId
            || (
                is_string($connection->sessionToken ?? null)
                && isset($worker->sessionTokens[$connection->sessionToken])
                && (int) $worker->sessionTokens[$connection->sessionToken] === $userId
            )
        )) {
            $count++;
        }
    }

    return $count;
}

function countSeatsForUser(object $worker, int $roomId, int $userId): int
{
    if (!isset($worker->rooms[$roomId]['players'])) {
        return 0;
    }
    $count = 0;
    foreach ($worker->rooms[$roomId]['players'] as $player) {
        if ((int) ($player['user_id'] ?? 0) === $userId) {
            $count++;
        }
    }

    return $count;
}

function enforceActionGuard(SessionGuardService $sessionGuard, object $connection, object $worker): void
{
    if (($connection->userId ?? null) !== null) {
        $sessionGuard->evictOtherLiveSessions($worker, (int) $connection->userId, $connection);
    }
}

$pdo = makePdo();
$pdo->exec("INSERT INTO users (username, password_hash) VALUES ('rrs_user', '" . password_hash('pass', PASSWORD_DEFAULT) . "')");
$userId = (int) $pdo->query("SELECT id FROM users WHERE username='rrs_user'")->fetchColumn();

[$authHandler, $sessionGuard, $lobbyService, $reconnectService, $roomManager] = makeStack($pdo);

echo "GROUP 1: rapid login/close cycles (7x, matching production log count)\n";

$history = [];
$worker = makeWorker([], $sessionGuard, $lobbyService, $reconnectService, $roomManager);
$nextId = 1;

for ($cycle = 0; $cycle < 7; $cycle++) {
    $conn = makeMockConnection($nextId++);
    $worker->connections[] = $conn;
    doLogin($authHandler, $conn, $worker, 'rrs_user', 'pass');
    rrsCheck((int) ($conn->userId ?? 0) === $userId, "cycle {$cycle}: login binds user_id");
    rrsCheck(countLiveAuthForUser($worker, $userId) === 1, "cycle {$cycle}: exactly one live auth socket");
    $history[] = $conn;
    simulateClose($sessionGuard, $conn, $worker, true);
}

$final = makeMockConnection($nextId++);
$worker->connections[] = $final;
doLogin($authHandler, $final, $worker, 'rrs_user', 'pass');
rrsCheck(countLiveAuthForUser($worker, $userId) === 1, 'after 7 cycles: final login is sole live auth');

echo "\nGROUP 2: late onClose — login conn B while conn A still in pool, then close A\n";

$worker2 = makeWorker([], $sessionGuard, $lobbyService, $reconnectService, $roomManager);
$connA = makeMockConnection(20);
$connB = makeMockConnection(21);
$worker2->connections = [$connA, $connB];

doLogin($authHandler, $connA, $worker2, 'rrs_user', 'pass');
$staleToken = (string) $connA->sessionToken;
doLogin($authHandler, $connB, $worker2, 'rrs_user', 'pass');

rrsCheck($connA->closed === true, 'conn A evicted when conn B logs in while A still in pool');
rrsCheck((int) ($connB->userId ?? 0) === $userId, 'conn B owns session after overlapping login');
rrsCheck(countLiveAuthForUser($worker2, $userId) === 1, 'only conn B live after overlapping login');

simulateClose($sessionGuard, $connA, $worker2, false);
rrsCheck((int) ($connB->userId ?? 0) === $userId, 'late onClose on evicted A does not clear B userId');
rrsCheck(countLiveAuthForUser($worker2, $userId) === 1, 'late onClose on A leaves B authenticated');

echo "\nGROUP 3: zombie sessionToken (userId cleared, token still mapped) — next login must evict\n";

$worker3 = makeWorker([], $sessionGuard, $lobbyService, $reconnectService, $roomManager);
$zombie = makeMockConnection(30);
$winner = makeMockConnection(31);
$worker3->connections = [$zombie, $winner];

doLogin($authHandler, $zombie, $worker3, 'rrs_user', 'pass');
$zombieToken = (string) $zombie->sessionToken;
$zombie->userId = null;
$zombie->username = null;
$zombie->sessionToken = $zombieToken;

doLogin($authHandler, $winner, $worker3, 'rrs_user', 'pass');
rrsCheck($zombie->closed === true, 'zombie with stale sessionToken evicted on next login');
rrsCheck(countLiveAuthForUser($worker3, $userId) === 1, 'zombie sessionToken path leaves one live auth');

echo "\nGROUP 4: production bug shape — final conn creates, stale conn cannot join as 2nd seat\n";

$worker4 = makeWorker([], $sessionGuard, $lobbyService, $reconnectService, $roomManager);
$staleLobby = makeMockConnection(40);
$finalLobby = makeMockConnection(41);
$worker4->connections = [$staleLobby, $finalLobby];

doLogin($authHandler, $staleLobby, $worker4, 'rrs_user', 'pass');
doLogin($authHandler, $finalLobby, $worker4, 'rrs_user', 'pass');
rrsCheck($staleLobby->closed === true, 'stale lobby socket evicted');

enforceActionGuard($sessionGuard, $finalLobby, $worker4);
$lobbyService->handleCreateRoom(
    ['max_players' => 4, 'password' => '', 'cards_count' => 1],
    $finalLobby,
    $worker4
);
$roomId = (int) array_key_first($worker4->rooms ?? []);
rrsCheck($roomId > 0, 'final connection creates room');

$lobbyService->handleJoinRoom(
    ['room_id' => $roomId, 'password' => '', 'cards_count' => 2],
    $staleLobby,
    $worker4
);
$gotAuthError = false;
foreach ($staleLobby->sent as $msg) {
    if (str_contains($msg, 'error.auth_required')) {
        $gotAuthError = true;
    }
}
$seatCount = countSeatsForUser($worker4, $roomId, $userId);

rrsCheck($gotAuthError, 'evicted socket gets auth_required on join_room');
rrsCheck($seatCount <= 1, 'at most one room seat for user_id (seats=' . $seatCount . ')');

echo "\nGROUP 5: duplicate onClose is idempotent (userId=null anomaly)\n";

$worker5 = makeWorker([], $sessionGuard, $lobbyService, $reconnectService, $roomManager);
$bare = makeMockConnection(50);
$worker5->connections[] = $bare;
simulateClose($sessionGuard, $bare, $worker5, false);
simulateClose($sessionGuard, $bare, $worker5, false);
rrsCheck($bare->lottoCloseProcessed === true, 'onClose marked processed');
rrsCheck(($bare->userId ?? null) === null, 'duplicate onClose keeps userId null');

echo "\n" . str_repeat('=', 60) . "\n";
echo "RESULT: {$passed} passed, {$failed} failed\n";
echo str_repeat('=', 60) . "\n";

exit($failed > 0 ? 1 : 0);
