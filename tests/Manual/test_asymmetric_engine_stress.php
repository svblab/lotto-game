<?php

declare(strict_types=1);

/**
 * EPIC-028.3 — asymmetric cross-engine teardown stress test.
 *
 * Models Chrome + Firefox timing: one socket's onClose is delayed until AFTER
 * the other engine's fresh login has already called claimUserSession(), then
 * BOTH sockets attempt create_room / join_room against the same room.
 *
 * Goal: prove SessionGuardService eviction sweeps close the dual-auth window,
 * or reproduce a remaining gap end to end.
 *
 * Run: php tests/Manual/test_asymmetric_engine_stress.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Helpers.php';

if (DIRECTORY_SEPARATOR === '\\') {
    $extDir = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'ext';
    if (is_dir($extDir)) {
        ini_set('extension_dir', $extDir);
        if (!extension_loaded('sqlite3')) {
            @dl('php_sqlite3.dll');
        }
        if (!extension_loaded('pdo_sqlite')) {
            @dl('php_pdo_sqlite.dll');
        }
    }
}

use Lotto\Auth\AuthHandler;
use Lotto\Auth\AuthService;
use Lotto\Auth\SessionGuardService;
use Lotto\Auth\IpAccountLimitService;
use Lotto\Auth\SessionService;
use Lotto\Core\Logger;
use Lotto\Core\RoomManager;
use Lotto\Infrastructure\PreparedStatements;
use Lotto\Lobby\LobbyHostService;
use Lotto\Lobby\LobbyService;
use Lotto\Game\ReconnectService;

$passed = 0;
$failed = 0;

function aesCheck(bool $cond, string $label): void
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
    $ipAccountLimit = new IpAccountLimitService($logger);
    $authHandler = new AuthHandler($authService, $sessionService, $logger, $sessionGuard, $ipAccountLimit);
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

function delayedClose(SessionGuardService $sessionGuard, object $connection, object $worker): void
{
    $sessionGuard->handleConnectionClose($connection, $worker);
    $worker->connections = array_values(array_filter(
        $worker->connections,
        static fn($c) => $c !== $connection
    ));
}

function enforceActionGuard(SessionGuardService $sessionGuard, object $connection, object $worker): void
{
    if (($connection->userId ?? null) !== null) {
        $sessionGuard->evictOtherLiveSessions($worker, (int) $connection->userId, $connection);
    }
}

function countLiveAuthForUser(object $worker, int $userId): int
{
    $count = 0;
    foreach ($worker->connections as $connection) {
        if (!empty($connection->closed)) {
            continue;
        }
        $uid = (int) ($connection->userId ?? 0);
        $token = $connection->sessionToken ?? null;
        $tokenMapped = is_string($token)
            && $token !== ''
            && isset($worker->sessionTokens[$token])
            && (int) $worker->sessionTokens[$token] === $userId;

        if ($uid === $userId || $tokenMapped) {
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

function sentHasCode(object $connection, string $code): bool
{
    foreach ($connection->sent as $msg) {
        if (str_contains($msg, $code)) {
            return true;
        }
    }

    return false;
}

function lobbyCreate(LobbyService $lobbyService, SessionGuardService $guard, object $conn, object $worker): int
{
    enforceActionGuard($guard, $conn, $worker);
    $lobbyService->handleCreateRoom(
        ['max_players' => 4, 'password' => '', 'cards_count' => 1],
        $conn,
        $worker
    );

    return (int) array_key_first($worker->rooms ?? []);
}

function lobbyJoin(LobbyService $lobbyService, SessionGuardService $guard, object $conn, object $worker, int $roomId): void
{
    enforceActionGuard($guard, $conn, $worker);
    $lobbyService->handleJoinRoom(
        ['room_id' => $roomId, 'password' => '', 'cards_count' => 2],
        $conn,
        $worker
    );
}

$pdo = makePdo();
$pdo->exec("INSERT INTO users (username, password_hash) VALUES ('aes_user', '" . password_hash('pass', PASSWORD_DEFAULT) . "')");
$userId = (int) $pdo->query("SELECT id FROM users WHERE username='aes_user'")->fetchColumn();

[$authHandler, $sessionGuard, $lobbyService, $reconnectService, $roomManager] = makeStack($pdo);

echo "GROUP 1: delayed onClose AFTER fresh login — winner creates, stale joins (pre-close)\n";

$worker1 = makeWorker([], $sessionGuard, $lobbyService, $reconnectService, $roomManager);
$engineA = makeMockConnection(101);
$engineB = makeMockConnection(102);
$worker1->connections = [$engineA, $engineB];

doLogin($authHandler, $engineA, $worker1, 'aes_user', 'pass');
doLogin($authHandler, $engineB, $worker1, 'aes_user', 'pass');

aesCheck($engineA->closed === true, 'engine A evicted when engine B logs in');
aesCheck(countLiveAuthForUser($worker1, $userId) === 1, 'only engine B live-auth after overlapping login');

$roomId = lobbyCreate($lobbyService, $sessionGuard, $engineB, $worker1);
aesCheck($roomId > 0, 'engine B creates room');

lobbyJoin($lobbyService, $sessionGuard, $engineA, $worker1, $roomId);
$seatsBeforeClose = countSeatsForUser($worker1, $roomId, $userId);
aesCheck($seatsBeforeClose <= 1, 'stale engine A cannot add 2nd seat before delayed onClose (seats=' . $seatsBeforeClose . ')');
aesCheck(
    sentHasCode($engineA, 'error.auth_required') || sentHasCode($engineA, 'error.auth_invalid_token'),
    'stale engine A rejected on join_room'
);

delayedClose($sessionGuard, $engineA, $worker1);
aesCheck((int) ($engineB->userId ?? 0) === $userId, 'delayed onClose on A does not unbind B');
aesCheck(countSeatsForUser($worker1, $roomId, $userId) <= 1, 'seat count still <=1 after delayed onClose');

echo "\nGROUP 2: delayed onClose AFTER both lobby actions — reverse order (stale create, winner join)\n";

$worker2 = makeWorker([], $sessionGuard, $lobbyService, $reconnectService, $roomManager);
$staleCreate = makeMockConnection(201);
$winnerJoin = makeMockConnection(202);
$worker2->connections = [$staleCreate, $winnerJoin];

doLogin($authHandler, $staleCreate, $worker2, 'aes_user', 'pass');
doLogin($authHandler, $winnerJoin, $worker2, 'aes_user', 'pass');
aesCheck($staleCreate->closed === true, 'stale create socket evicted on fresh login');

lobbyCreate($lobbyService, $sessionGuard, $staleCreate, $worker2);
$staleCreateRoom = (int) array_key_first($worker2->rooms ?? []);

lobbyCreate($lobbyService, $sessionGuard, $winnerJoin, $worker2);
$winnerRoom = (int) array_key_first($worker2->rooms ?? []);

aesCheck(
    $staleCreateRoom <= 0 || sentHasCode($staleCreate, 'error.auth_required') || sentHasCode($staleCreate, 'error.auth_invalid_token'),
    'evicted stale socket cannot create room'
);
aesCheck($winnerRoom > 0, 'winner socket creates room');

lobbyJoin($lobbyService, $sessionGuard, $staleCreate, $worker2, $winnerRoom);
$seats2 = countSeatsForUser($worker2, $winnerRoom, $userId);
aesCheck($seats2 <= 1, 'stale cannot join winner room as 2nd seat (seats=' . $seats2 . ')');

delayedClose($sessionGuard, $staleCreate, $worker2);
aesCheck(countLiveAuthForUser($worker2, $userId) === 1, 'single live auth after delayed onClose');

echo "\nGROUP 3: zombie sessionToken (userId cleared, token mapped) — both attempt create+join window\n";

$worker3 = makeWorker([], $sessionGuard, $lobbyService, $reconnectService, $roomManager);
$zombie = makeMockConnection(301);
$fresh = makeMockConnection(302);
$worker3->connections = [$zombie, $fresh];

doLogin($authHandler, $zombie, $worker3, 'aes_user', 'pass');
$zombieToken = (string) $zombie->sessionToken;
$zombie->userId = null;
$zombie->username = null;
$zombie->sessionToken = $zombieToken;
$zombie->closed = false;

doLogin($authHandler, $fresh, $worker3, 'aes_user', 'pass');
aesCheck($zombie->closed === true, 'zombie token socket evicted on fresh login');

$room3 = lobbyCreate($lobbyService, $sessionGuard, $fresh, $worker3);
lobbyJoin($lobbyService, $sessionGuard, $zombie, $worker3, $room3);
aesCheck(countSeatsForUser($worker3, $room3, $userId) <= 1, 'zombie path: at most one seat');
aesCheck(countLiveAuthForUser($worker3, $userId) === 1, 'zombie path: at most one live auth');

delayedClose($sessionGuard, $zombie, $worker3);

echo "\nGROUP 4: production shape — fresh login, BOTH attempt create then join same room before any onClose\n";

$worker4 = makeWorker([], $sessionGuard, $lobbyService, $reconnectService, $roomManager);
$chrome = makeMockConnection(401);
$firefox = makeMockConnection(402);
$worker4->connections = [$chrome, $firefox];

doLogin($authHandler, $chrome, $worker4, 'aes_user', 'pass');
doLogin($authHandler, $firefox, $worker4, 'aes_user', 'pass');

aesCheck($chrome->closed === true, 'chrome evicted when firefox logs in');
aesCheck(countLiveAuthForUser($worker4, $userId) === 1, 'single live auth before lobby race');

$prodRoom = lobbyCreate($lobbyService, $sessionGuard, $firefox, $worker4);
aesCheck($prodRoom > 0, 'firefox creates room');

lobbyJoin($lobbyService, $sessionGuard, $chrome, $worker4, $prodRoom);
$prodSeats = countSeatsForUser($worker4, $prodRoom, $userId);
aesCheck($prodSeats === 1, 'exactly one seat for user_id after create+join race (seats=' . $prodSeats . ')');
aesCheck(
    sentHasCode($chrome, 'error.auth_required') || sentHasCode($chrome, 'error.auth_invalid_token') || $prodSeats <= 1,
    'chrome join blocked or harmless'
);

delayedClose($sessionGuard, $chrome, $worker4);

echo "\nGROUP 5: late onClose while chrome still in pool — firefox create+join, then chrome onClose\n";

$worker5 = makeWorker([], $sessionGuard, $lobbyService, $reconnectService, $roomManager);
$lateA = makeMockConnection(501);
$lateB = makeMockConnection(502);
$worker5->connections = [$lateA, $lateB];

doLogin($authHandler, $lateA, $worker5, 'aes_user', 'pass');
doLogin($authHandler, $lateB, $worker5, 'aes_user', 'pass');

$lateRoom = lobbyCreate($lobbyService, $sessionGuard, $lateB, $worker5);
lobbyJoin($lobbyService, $sessionGuard, $lateA, $worker5, $lateRoom);

aesCheck(countSeatsForUser($worker5, $lateRoom, $userId) <= 1, 'pre-close join cannot duplicate seat');
delayedClose($sessionGuard, $lateA, $worker5);
aesCheck((int) ($lateB->userId ?? 0) === $userId, 'late onClose does not corrupt winner auth');
aesCheck(countSeatsForUser($worker5, $lateRoom, $userId) <= 1, 'post-close seat count stable');

echo "\nGROUP 6: dual live-auth probe — must never observe two authenticated sockets\n";

$worker6 = makeWorker([], $sessionGuard, $lobbyService, $reconnectService, $roomManager);
$probeA = makeMockConnection(601);
$probeB = makeMockConnection(602);
$worker6->connections = [$probeA, $probeB];

doLogin($authHandler, $probeA, $worker6, 'aes_user', 'pass');
doLogin($authHandler, $probeB, $worker6, 'aes_user', 'pass');

$maxLive = 0;
for ($step = 0; $step < 4; $step++) {
    $live = countLiveAuthForUser($worker6, $userId);
    $maxLive = max($maxLive, $live);
    if ($step === 0) {
        lobbyCreate($lobbyService, $sessionGuard, $probeB, $worker6);
    } elseif ($step === 1) {
        $rid = (int) array_key_first($worker6->rooms ?? []);
        lobbyJoin($lobbyService, $sessionGuard, $probeA, $worker6, $rid);
    } elseif ($step === 2) {
        delayedClose($sessionGuard, $probeA, $worker6);
    }
}
aesCheck($maxLive <= 1, 'never more than one live-auth socket during asymmetric window (max=' . $maxLive . ')');

echo "\n" . str_repeat('=', 60) . "\n";
echo "RESULT: {$passed} passed, {$failed} failed\n";
echo str_repeat('=', 60) . "\n";

exit($failed > 0 ? 1 : 0);
