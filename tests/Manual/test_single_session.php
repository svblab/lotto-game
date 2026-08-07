<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Helpers.php';

use Lotto\Infrastructure\Database;
use Lotto\Infrastructure\PreparedStatements;
use Lotto\Core\Logger;
use Lotto\Core\RoomManager;
use Lotto\Auth\SessionService;
use Lotto\Auth\AuthService;
use Lotto\Auth\AuthHandler;
use Lotto\Auth\SessionGuardService;
use Lotto\Lobby\LobbyService;
use Lotto\Lobby\LobbyHostService;
use Lotto\Game\ReconnectService;
use Lotto\Game\GameService;

echo "=== STARTING MANUAL TESTING FOR EPIC-1.3 / FIX-30 (Single Session — claimUserSession) ===\n\n";

function makeMockConnection(int $id): object
{
    return new class($id) {
        public int $id;
        public $userId = null;
        public $username = null;
        public $isAdmin = false;
        public $sessionToken = null;
        public bool $closed = false;
        public array $sentMessages = [];

        public function __construct(int $id)
        {
            $this->id = $id;
        }

        public function send(string $msg): void
        {
            $this->sentMessages[] = $msg;
        }

        public function close(): void
        {
            $this->closed = true;
        }
    };
}

try {
    $db = new Database();
    $pdo = $db->getPdo();

    $pdo->exec("DELETE FROM users WHERE username = 'single_user'");
    $passwordHash = password_hash('password123', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (username, password_hash, coins, is_admin, banned_until, last_daily_bonus) VALUES (?, ?, 500, 0, 0, 0)")
        ->execute(['single_user', $passwordHash]);

    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = 'single_user'");
    $stmt->execute();
    $userId = (int)$stmt->fetchColumn();

    $statements = new PreparedStatements($pdo);
    $logger = new Logger(sys_get_temp_dir() . '/lotto_test_single_session_' . getmypid() . '.log');
    register_shutdown_function(function () {
        @unlink(sys_get_temp_dir() . '/lotto_test_single_session_' . getmypid() . '.log');
    });
    $sessionService = new SessionService();
    $authService = new AuthService($db, $statements, $logger, $sessionService);
    $sessionGuard = new SessionGuardService($logger);
    $authHandler = new AuthHandler($authService, $sessionService, $logger, $sessionGuard);

    $roomManager = new RoomManager($logger);
    $lobbyHostService = new LobbyHostService($roomManager, $logger);
    $lobbyService = new LobbyService($roomManager, $logger, $lobbyHostService);

  // Minimal GameService stub for ReconnectService constructor
    $gameServiceStub = new class {
        public function calculateWinChances($players, $status) { return []; }
        public function handleDrawBarrel() {}
        public function handleNoSurvivors() {}
        public function finishGame() {}
        public function nextDrawer() {}
        public function startTurn() {}
    };
    $reconnectService = new ReconnectService($lobbyService, $gameServiceStub, $logger);

    $conn1 = makeMockConnection(1);
    $conn2 = makeMockConnection(2);

    $worker = new class($conn1, $conn2, $roomManager, $lobbyService, $reconnectService) {
        public array $userConnections = [];
        public array $sessionTokens = [];
        public array $rooms = [];
        public array $connections;
        public object $roomManager;
        public object $lobbyService;
        public object $reconnectService;

        public function __construct($c1, $c2, $rm, $ls, $rs)
        {
            $this->connections = [$c1, $c2];
            $this->roomManager = $rm;
            $this->lobbyService = $ls;
            $this->reconnectService = $rs;
        }
    };

    // --- Scenario 1: first login claims session ---
    echo "[Scenario 1] First login claims session...\n";
    $res1 = $authService->login('single_user', 'password123');
    $sessionGuard->claimUserSession($worker, $userId, $conn1, $res1['session_token'], $res1['user'], true);

    if ($res1['success'] === true && $worker->userConnections[$userId] === $conn1 && $conn1->userId === $userId) {
        echo "✅ Success: First login registered connection.\n";
    } else {
        echo "❌ Failure in Scenario 1.\n";
        exit(1);
    }
    echo "----------------------------------------\n";

    // --- Scenario 2: second live login evicts first (newest wins) ---
    echo "[Scenario 2] Second login evicts first live connection...\n";
    $res2 = $authService->login('single_user', 'password123');
    $sessionGuard->claimUserSession($worker, $userId, $conn2, $res2['session_token'], $res2['user'], true);

    if ($conn1->closed && $worker->userConnections[$userId] === $conn2 && $conn2->userId === $userId) {
        echo "✅ Success: Second login evicted first; newest session owns account.\n";
    } else {
        echo "❌ Failure in Scenario 2: eviction did not occur as expected.\n";
        exit(1);
    }
    echo "----------------------------------------\n";

    // --- Scenario 3: after first conn closed, login succeeds ---
    echo "[Scenario 3] Login after prior connection closed...\n";
    unset($worker->connections[0]);
    $conn3 = makeMockConnection(3);
    $worker->connections[] = $conn3;

    $res3 = $authService->login('single_user', 'password123');
    $sessionGuard->claimUserSession($worker, $userId, $conn3, $res3['session_token'], $res3['user'], true);

    if ($res3['success'] === true && $worker->userConnections[$userId] === $conn3) {
        echo "✅ Success: Login succeeded after prior owner disconnected.\n";
    } else {
        echo "❌ Failure in Scenario 3.\n";
        exit(1);
    }
    echo "----------------------------------------\n";

    echo "🚀 ALL SINGLE SESSION (FIX-30) TESTS PASSED SUCCESSFULLY!\n";

} catch (\Throwable $t) {
    echo "💥 Critical error during Single Session testing: " . $t->getMessage() . "\n";
    exit(1);
}
