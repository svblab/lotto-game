<?php

declare(strict_types=1);

/**
 * EPIC-028.0 — SessionGuard unit repro for zombie connection auth fields after onClose.
 *
 * Run: php tests/Manual/test_concurrent_session_unit.php
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

function unitCheck(bool $cond, string $label): void
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

    return [$authService, $authHandler, $sessionGuard, $lobbyService, $reconnectService, $roomManager];
}

function simulateProductionOnClose(object $connection, object $worker, SessionGuardService $sessionGuard): void
{
    $sessionGuard->handleConnectionClose($connection, $worker);
    $worker->connections = array_values(array_filter(
        $worker->connections,
        static fn($c) => $c !== $connection
    ));
}

function countLiveAuthForUser(object $worker, int $userId): int
{
    $count = 0;
    foreach ($worker->connections as $connection) {
        if ((int) ($connection->userId ?? 0) === $userId && empty($connection->closed)) {
            $count++;
        }
    }

    return $count;
}

echo "GROUP 1: zombie userId on closed-but-not-cleared connection object\n";

$pdo = makePdo();
$pdo->exec("INSERT INTO users (username, password_hash) VALUES ('unit_x', '" . password_hash('pass', PASSWORD_DEFAULT) . "')");
$userId = (int) $pdo->query("SELECT id FROM users WHERE username='unit_x'")->fetchColumn();

[, $authHandler, $sessionGuard, $lobbyService, $reconnectService, $roomManager] = makeStack($pdo);

$connA = makeMockConnection(1);
$connB = makeMockConnection(2);
$connC = makeMockConnection(3);

$worker = (object) [
    'connections' => [$connA],
    'userConnections' => [],
    'sessionTokens' => [],
    'rooms' => [],
    'roomManager' => $roomManager,
    'lobbyService' => $lobbyService,
    'reconnectService' => $reconnectService,
    'sessionGuard' => $sessionGuard,
    'authHandler' => $authHandler,
];

$loginA = $authHandler->handleLogin(['username' => 'unit_x', 'password' => 'pass'], $connA, $worker);
$tokenA = $worker->sessionTokens ? array_key_first($worker->sessionTokens) : null;
unitCheck($tokenA !== null && $connA->userId === $userId, 'A login binds userId');

simulateProductionOnClose($connA, $worker, $sessionGuard);
unitCheck(!isset($worker->userConnections[$userId]), 'onClose clears userConnections slot');

$worker->connections[] = $connB;
$authHandler->handleLogin(['username' => 'unit_x', 'password' => 'pass'], $connB, $worker);
$tokenB = null;
foreach ($worker->sessionTokens as $tok => $uid) {
    if ((int) $uid === $userId) {
        $tokenB = $tok;
    }
}
unitCheck(is_string($tokenB) && $tokenB !== $tokenA, 'B login revokes token A');

$worker->connections[] = $connC;
$authHandler->handleReconnect(['token' => $tokenA], $connC, $worker);
unitCheck(($connC->userId ?? null) === null, 'stale token A reconnect rejected');

echo "\nGROUP 2: reconnect with current token must evict prior live login\n";

$connD = makeMockConnection(4);
$connE = makeMockConnection(5);
$worker2 = (object) [
    'connections' => [$connD, $connE],
    'userConnections' => [],
    'sessionTokens' => [],
    'rooms' => [],
    'roomManager' => $roomManager,
    'lobbyService' => $lobbyService,
    'reconnectService' => $reconnectService,
    'sessionGuard' => $sessionGuard,
    'authHandler' => $authHandler,
];

$authHandler->handleLogin(['username' => 'unit_x', 'password' => 'pass'], $connD, $worker2);
$liveToken = $connD->sessionToken;
unitCheck($connD->userId === $userId, 'D login ok');

$authHandler->handleReconnect(['token' => $liveToken], $connE, $worker2);
unitCheck($connE->userId === $userId, 'E reconnect with current token succeeds');
unitCheck($connD->closed === true, 'D evicted on E reconnect');
unitCheck(countLiveAuthForUser($worker2, $userId) === 1, 'exactly one live authenticated connection');

echo "\nGROUP 3: bindConnectionToPlayer cannot leave two live auth sockets\n";

$connF = makeMockConnection(6);
$connG = makeMockConnection(7);
$worker3 = (object) [
    'connections' => [$connF, $connG],
    'userConnections' => [],
    'sessionTokens' => [],
    'rooms' => [],
    'roomManager' => $roomManager,
    'lobbyService' => $lobbyService,
    'reconnectService' => $reconnectService,
    'sessionGuard' => $sessionGuard,
    'authHandler' => $authHandler,
];

$authHandler->handleLogin(['username' => 'unit_x', 'password' => 'pass'], $connF, $worker3);
$tokenF = $connF->sessionToken;
$roomId = $roomManager->createRoom($worker3, (int) $connF->id, 4, null);
$worker3->rooms[$roomId]['players'][(int) $connF->id] = [
    'user_id' => $userId,
    'username' => 'unit_x',
    'cards' => [],
    'cards_count' => 1,
    'total_paid' => 10,
    'last_action' => time(),
    'host_activity_at' => time(),
    'afk_start' => null,
    'strikes' => 0,
    'auto_draws' => 0,
    'status' => 'active',
    'session_token' => $tokenF,
    'reconnect_timer' => null,
    'connection' => $connF,
    'immune' => false,
];
$worker3->rooms[$roomId]['status'] = 'waiting';

$authHandler->handleReconnect(['token' => $tokenF], $connG, $worker3);
$reconnectService->handleReconnect($tokenF, $connG, $worker3);
unitCheck(countLiveAuthForUser($worker3, $userId) === 1, 'room reconnect leaves one live auth connection');
echo "\nGROUP 4: join_room rebind must evict prior live socket for same user_id\n";

$connH = makeMockConnection(8);
$connI = makeMockConnection(9);
$worker4 = (object) [
    'connections' => [$connH, $connI],
    'userConnections' => [],
    'sessionTokens' => [],
    'rooms' => [],
    'roomManager' => $roomManager,
    'lobbyService' => $lobbyService,
    'reconnectService' => $reconnectService,
    'sessionGuard' => $sessionGuard,
    'authHandler' => $authHandler,
];

$authHandler->handleLogin(['username' => 'unit_x', 'password' => 'pass'], $connH, $worker4);
$tokenH = (string) $connH->sessionToken;
$roomId4 = $roomManager->createRoom($worker4, (int) $connH->id, 4, null);
$worker4->rooms[$roomId4]['players'][(int) $connH->id] = [
    'user_id' => $userId,
    'username' => 'unit_x',
    'cards' => [],
    'cards_count' => 1,
    'total_paid' => 10,
    'last_action' => time(),
    'host_activity_at' => time(),
    'afk_start' => null,
    'strikes' => 0,
    'auto_draws' => 0,
    'status' => 'active',
    'session_token' => $tokenH,
    'reconnect_timer' => null,
    'connection' => $connH,
    'immune' => false,
];
$worker4->rooms[$roomId4]['status'] = 'waiting';
$worker4->rooms[$roomId4]['host_conn_id'] = (int) $connH->id;

$connI->userId = $userId;
$connI->username = 'unit_x';
$connI->isAdmin = false;
$connI->sessionToken = $tokenH;
$worker4->userConnections[$userId] = $connI;

$lobbyService->handleJoinRoom(
    ['room_id' => $roomId4, 'password' => '', 'cards_count' => 2],
    $connI,
    $worker4
);

unitCheck($connH->closed === true, 'join_room rebind evicts prior live socket');
unitCheck(countLiveAuthForUser($worker4, $userId) === 1, 'join_room rebind leaves one live auth connection');
unitCheck(isset($worker4->rooms[$roomId4]['players'][(int) $connI->id]), 'join_room rebind moves seat to new connection');

echo "\n" . str_repeat('=', 60) . "\n";
echo "RESULT: {$passed} passed, {$failed} failed\n";
echo str_repeat('=', 60) . "\n";

exit($failed > 0 ? 1 : 0);
