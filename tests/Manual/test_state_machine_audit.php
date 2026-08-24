<?php

declare(strict_types=1);

/**
 * tests/Manual/test_state_machine_audit.php
 *
 * EPIC-11.4 — State machine audit mock regression tests (Windows + Linux).
 *
 * Covers:
 *   - StateMachineAudit utility + log parsing / validation
 *   - Valid room transitions: waiting→playing→apartment→playing→finished
 *   - Invalid action rejections per ANCHOR_CORE Part 4
 *   - Player active↔disconnected transitions (reconnect recovery)
 *   - Apartment timeout automatic transition
 *   - Host disconnect + reconnect preserves host ownership
 *
 * Run: php tests/Manual/test_state_machine_audit.php
 */

require_once __DIR__ . '/mock_timer.php';

if (DIRECTORY_SEPARATOR === '\\') {
    $extDir = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'ext';
    if (is_dir($extDir)) {
        ini_set('extension_dir', $extDir);
        if (!extension_loaded('sqlite3')) {
            dl('php_sqlite3.dll');
        }
        if (!extension_loaded('pdo_sqlite')) {
            dl('php_pdo_sqlite.dll');
        }
    }
}

require __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Helpers.php';

use Lotto\Auth\AuthHandler;
use Lotto\Auth\SessionGuardService;
use Lotto\Auth\IpAccountLimitService;
use Lotto\Auth\AuthService;
use Lotto\Auth\SessionService;
use Lotto\Core\Logger;
use Lotto\Core\RoomManager;
use Lotto\Core\StateMachineAudit;
use Lotto\Game\ApartmentService;
use Lotto\Game\GameFinishService;
use Lotto\Game\GameHandler;
use Lotto\Game\GameService;
use Lotto\Game\GameTurnService;
use Lotto\Game\LottoEngine;
use Lotto\Game\ReconnectService;
use Lotto\Game\VictoryService;
use Lotto\Infrastructure\PreparedStatements;
use Lotto\Lobby\LobbyHandler;
use Lotto\Lobby\LobbyService;
use Lotto\Lobby\LobbyHostService;

use function Lotto\Core\lottoPlayerStateTransition;
use function Lotto\Core\lottoStateReject;
use function Lotto\Core\lottoStateTransition;

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

final class FakeLogger extends Logger
{
    public function __construct() {}
    public function info(string $m): void {}
    public function warning(string $m): void {}
    public function error(string $m): void {}
}

final class FlowConnection
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

final class FlowWorker
{
    public array $rooms = [];
    public array $userConnections = [];
    public array $sessionTokens = [];
    public object $lobbyService;

    public function __construct()
    {
        $this->lobbyService = new class {
            public function broadcastRoomList(object $worker): void {}
        };
    }
}

final class TestDatabase extends \Lotto\Infrastructure\Database
{
    public function __construct(private PDO $testPdo) {}

    public function getPdo(): PDO
    {
        return $this->testPdo;
    }
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

function buildStack(PDO $pdo): array
{
    $logger = new Logger('/dev/null');
    $statements = new PreparedStatements($pdo);
    $sessionService = new SessionService();
    $db = new TestDatabase($pdo);
    $authService = new AuthService($db, $statements, $logger, $sessionService);
    $sessionGuard = new SessionGuardService($logger);
    $ipAccountLimit = new IpAccountLimitService($logger);
    $authHandler = new AuthHandler($authService, $sessionService, $logger, $sessionGuard, $ipAccountLimit);
    $roomManager = new RoomManager($logger);
    $lobbyHostService = new LobbyHostService($roomManager, $logger);
    $lobbyService = new LobbyService($roomManager, $logger, $lobbyHostService);
    $lobbyHandler = new LobbyHandler($lobbyService);
    $lottoEngine = new LottoEngine();
    $victoryService = new VictoryService();
    $apartmentService = new ApartmentService($db, $statements, $logger);
    $gameFinishService = new GameFinishService($db, $statements, $logger);
    $gameTurnService = new GameTurnService(
        $logger,
        $victoryService,
        $apartmentService,
        $gameFinishService
    );
    $gameService = new GameService(
        $db,
        $statements,
        $lottoEngine,
        $logger,
        $victoryService,
        $apartmentService,
        $gameFinishService,
        $gameTurnService
    );
    $gameHandler = new GameHandler($gameService);

    return compact(
        'authHandler',
        'lobbyHandler',
        'gameHandler',
        'gameService',
        'apartmentService',
        'roomManager',
        'sessionService'
    );
}

function makeCardWithClosedRow(): array
{
    $card = array_fill(0, 3, array_fill(0, 9, null));
    $card[0][0] = 1;
    $card[0][2] = 20;
    $card[0][4] = 40;
    $card[0][6] = 60;
    $card[0][8] = 80;
    $card[1][1] = 10;
    $card[1][3] = 30;
    $card[1][5] = 50;
    $card[1][7] = 70;
    $card[1][8] = 85;
    $card[2][0] = 5;
    $card[2][2] = 25;
    $card[2][4] = 45;
    $card[2][6] = 65;
    $card[2][8] = 90;
    return $card;
}

function makeMaskWithClosedRow(array $card): array
{
    $mask = array_fill(0, 3, array_fill(0, 9, false));
    for ($col = 0; $col < 9; $col++) {
        if ($card[0][$col] !== null) {
            $mask[0][$col] = true;
        }
    }
    return $mask;
}

$auditLogPath = sys_get_temp_dir() . '/lotto_test_state_audit_' . getmypid() . '.log';
@unlink($auditLogPath);

// =============================================================================
// GROUP 1 — StateMachineAudit utility
// =============================================================================

echo "GROUP 1: StateMachineAudit utility\n";

putenv('LOTTO_STATE_AUDIT=0');
assertTrue(!StateMachineAudit::isEnabled(), 'audit disabled when env unset/0');

putenv('LOTTO_STATE_AUDIT=1');
assertTrue(StateMachineAudit::isEnabled(), 'audit enabled when LOTTO_STATE_AUDIT=1');

assertTrue(
    StateMachineAudit::isRoomTransitionAllowed('waiting', 'playing', 'start_game'),
    'spec: waiting→playing via start_game'
);
assertTrue(
    !StateMachineAudit::isRoomTransitionAllowed('waiting', 'apartment', 'start_game'),
    'spec: waiting cannot jump to apartment'
);
assertTrue(
    StateMachineAudit::isActionAllowed('waiting', 'start_game'),
    'spec: start_game allowed in waiting'
);
assertTrue(
    !StateMachineAudit::isActionAllowed('waiting', 'draw_barrel'),
    'spec: draw_barrel forbidden in waiting'
);
assertTrue(
    !StateMachineAudit::isActionAllowed('apartment', 'reconnect'),
    'spec: reconnect forbidden in apartment'
);
assertTrue(
    StateMachineAudit::isPlayerTransitionAllowed('active', 'disconnected', 'connection_lost'),
    'spec: active→disconnected on connection_lost'
);

putenv('LOTTO_STATE_AUDIT=1');
$GLOBALS['__lotto_state_audit'] = new StateMachineAudit(new FakeLogger(), $auditLogPath);

lottoStateTransition(1, 'created', 'waiting', 'room_created');
lottoStateTransition(1, 'waiting', 'playing', 'start_game');
lottoStateTransition(1, 'playing', 'apartment', 'apartment_detected');
lottoStateTransition(1, 'apartment', 'playing', 'apartment_complete');
lottoStateTransition(1, 'playing', 'finished', 'victory');
lottoStateTransition(1, 'finished', 'destroyed', 'game_over_cleanup');
lottoStateReject(2, 'playing', 'join_room', 'error.room_not_found');
lottoPlayerStateTransition(1, 5, 'active', 'disconnected', 'connection_lost');
lottoPlayerStateTransition(1, 6, 'disconnected', 'active', 'reconnect');

$events = StateMachineAudit::parseLog($auditLogPath);
assertTrue(count($events) === 9, 'parseLog finds 9 events');

$failures = StateMachineAudit::validateLog($events);
assertTrue(count($failures) === 0, 'validateLog passes for canonical lifecycle');

$badEvents = [
    ['event' => 'transition', 'room_id' => '3', 'from' => 'waiting', 'to' => 'finished', 'trigger' => 'start_game'],
];
assertTrue(count(StateMachineAudit::validateLog($badEvents)) > 0, 'validateLog catches invalid transition');

// =============================================================================
// GROUP 2 — waiting → playing + invalid transitions
// =============================================================================

echo "\nGROUP 2: waiting → playing + rejections\n";

@unlink($auditLogPath);
$GLOBALS['__lotto_state_audit'] = new StateMachineAudit(new FakeLogger(), $auditLogPath);

$pdo = makeTestPdo();
$stack = buildStack($pdo);
$worker = new FlowWorker();
$hostConn = new FlowConnection();
$guestConn = new FlowConnection();

$stack['authHandler']->handleRegister(
    ['action' => 'register', 'username' => 'smhost', 'password' => 'secret123'],
    $hostConn,
    $worker
);
$stack['authHandler']->handleLogin(
    ['action' => 'login', 'username' => 'smhost', 'password' => 'secret123'],
    $hostConn,
    $worker
);
$stack['authHandler']->handleRegister(
    ['action' => 'register', 'username' => 'smguest', 'password' => 'secret123'],
    $guestConn,
    $worker
);
$stack['authHandler']->handleLogin(
    ['action' => 'login', 'username' => 'smguest', 'password' => 'secret123'],
    $guestConn,
    $worker
);

$stack['lobbyHandler']->handleCreateRoom(
    ['action' => 'create_room', 'max_players' => 4, 'cards_count' => 1],
    $hostConn,
    $worker
);
$roomId = (int)($hostConn->packetsOfType('room_joined')[0]['room_id'] ?? 0);

$stack['lobbyHandler']->handleJoinRoom(
    ['action' => 'join_room', 'room_id' => $roomId],
    $guestConn,
    $worker
);

$stack['gameHandler']->handleDrawBarrel($hostConn, $worker);
assertTrue(($hostConn->lastPacket()['type'] ?? '') === 'error', 'draw_barrel in waiting rejected');

$stack['gameHandler']->handleStartGame($hostConn, $worker);
assertTrue(($worker->rooms[$roomId]['status'] ?? '') === 'playing', 'start_game: room status playing');

$stack['gameHandler']->handleStartGame($hostConn, $worker);
assertTrue(($hostConn->lastPacket()['type'] ?? '') === 'error', 'duplicate start_game rejected');

$stack['gameHandler']->handleDrawBarrel($guestConn, $worker);
assertTrue(($guestConn->lastPacket()['type'] ?? '') === 'error', 'draw_barrel by non-drawer rejected');

$logFailures = StateMachineAudit::validateLog(StateMachineAudit::parseLog($auditLogPath));
assertTrue(count($logFailures) === 0, 'GROUP 2 audit log validates' . (count($logFailures) ? ': ' . implode('; ', $logFailures) : ''));

// =============================================================================
// GROUP 3 — playing → apartment → playing (all agree)
// =============================================================================

echo "\nGROUP 3: apartment cycle\n";

@unlink($auditLogPath);
$GLOBALS['__lotto_state_audit'] = new StateMachineAudit(new FakeLogger(), $auditLogPath);

$aptWorker = new FlowWorker();
$h = new FlowConnection();
$h->userId = 100;
$h->username = 'ahost';
$g = new FlowConnection();
$g->userId = 200;
$g->username = 'aguest';

$card = makeCardWithClosedRow();
$mask = makeMaskWithClosedRow($card);
$eng = new LottoEngine();
$card2 = $eng->generateCard();
$mask2 = array_map(fn($row) => array_fill(0, 9, false), $card2);

$aptRoom = [
    'room_id' => 99,
    'host_conn_id' => $h->id,
    'bet_per_card' => 10,
    'max_players' => 10,
    'password_hash' => null,
    'status' => 'playing',
    'bank' => 40,
    'apartment_fired' => false,
    'pause_for_apartment' => false,
    'apartment_responses' => [],
    'game_afk_timer_id' => null,
    'apartment_timer_id' => null,
    'lobby_afk_timer_id' => null,
    'active_drawer_conn_id' => $h->id,
    'drawer_order' => [$h->id, $g->id],
    'bag' => range(1, 90),
    'drawn_numbers' => [],
    'players' => [
        $h->id => [
            'user_id' => 100, 'username' => 'ahost', 'cards' => [$card], 'masks' => [$mask],
            'cards_count' => 1, 'total_paid' => 10, 'status' => 'active', 'immune' => false,
            'connection' => $h, 'session_token' => 't1', 'reconnect_timer' => null,
            'last_action' => time(), 'afk_start' => null, 'strikes' => 0, 'auto_draws' => 0,
        ],
        $g->id => [
            'user_id' => 200, 'username' => 'aguest', 'cards' => [$card2], 'masks' => [$mask2],
            'cards_count' => 1, 'total_paid' => 10, 'status' => 'active', 'immune' => false,
            'connection' => $g, 'session_token' => 't2', 'reconnect_timer' => null,
            'last_action' => time(), 'afk_start' => null, 'strikes' => 0, 'auto_draws' => 0,
        ],
    ],
    'all_players_history' => [],
];
$aptWorker->rooms[99] = $aptRoom;

$aptPdo = makeTestPdo();
$aptPdo->exec("INSERT INTO users (id, username, password_hash, coins) VALUES (100, 'ahost', 'x', 500), (200, 'aguest', 'x', 500)");
$aptStack = buildStack($aptPdo);

$aptStack['apartmentService']->triggerApartment($aptWorker->rooms[99], 99, $aptWorker, $aptStack['gameService']);
assertTrue($aptWorker->rooms[99]['status'] === 'apartment', 'apartment triggered');

$aptStack['apartmentService']->handleApartmentChoice($h, $aptWorker, 'agree', $aptStack['gameService']);
$aptStack['apartmentService']->handleApartmentChoice($g, $aptWorker, 'agree', $aptStack['gameService']);
assertTrue($aptWorker->rooms[99]['status'] === 'playing', 'apartment complete resumes playing');

$logFailures = StateMachineAudit::validateLog(StateMachineAudit::parseLog($auditLogPath));
assertTrue(count($logFailures) === 0, 'GROUP 3 audit log validates');

// =============================================================================
// GROUP 4 — apartment timeout → playing
// =============================================================================

echo "\nGROUP 4: apartment timeout\n";

@unlink($auditLogPath);
$GLOBALS['__lotto_state_audit'] = new StateMachineAudit(new FakeLogger(), $auditLogPath);
\MockTimer::reset();

$toWorker = new FlowWorker();
$th = new FlowConnection();
$th->userId = 300;
$th->username = 'thost';
$tg = new FlowConnection();
$tg->userId = 400;
$tg->username = 'tguest';

$toRoom = $aptRoom;
$toRoom['room_id'] = 88;
$toRoom['host_conn_id'] = $th->id;
$toRoom['active_drawer_conn_id'] = $th->id;
$toRoom['drawer_order'] = [$th->id, $tg->id];
$toRoom['players'] = [
    $th->id => array_merge($aptRoom['players'][$h->id], [
        'user_id' => 300, 'connection' => $th, 'immune' => false,
    ]),
    $tg->id => array_merge($aptRoom['players'][$g->id], [
        'user_id' => 400, 'connection' => $tg, 'immune' => false,
    ]),
];
$tr = new FlowConnection();
$tr->userId = 500;
$tr->username = 'tthird';
$toRoom['drawer_order'] = [$th->id, $tg->id, $tr->id];
$toRoom['players'][$tr->id] = [
    'user_id' => 500, 'username' => 'tthird', 'cards' => [[]], 'masks' => [[[false]]],
    'cards_count' => 1, 'total_paid' => 10, 'status' => 'active', 'immune' => false,
    'connection' => $tr, 'session_token' => 't3', 'reconnect_timer' => null,
    'last_action' => time(), 'afk_start' => null, 'strikes' => 0, 'auto_draws' => 0,
];
$toWorker->rooms[88] = $toRoom;

$toPdo = makeTestPdo();
$toPdo->exec("INSERT INTO users (id, username, password_hash, coins) VALUES (300, 'thost', 'x', 500), (400, 'tguest', 'x', 500), (500, 'tthird', 'x', 500)");
$toStack = buildStack($toPdo);

$toStack['apartmentService']->triggerApartment($toWorker->rooms[88], 88, $toWorker, $toStack['gameService']);
// Two players agree; host stays pending until timeout → refuse → 2 survivors remain.
$toStack['apartmentService']->handleApartmentChoice($tg, $toWorker, 'agree', $toStack['gameService']);
$toStack['apartmentService']->handleApartmentChoice($tr, $toWorker, 'agree', $toStack['gameService']);
$timerId = $toWorker->rooms[88]['apartment_timer_id'] ?? null;
assertTrue($timerId !== null, 'apartment timer scheduled');

\MockTimer::fire((int) $timerId);
assertTrue($toWorker->rooms[88]['status'] === 'playing', 'apartment timeout resumes playing');

$parsed = StateMachineAudit::parseLog($auditLogPath);
$timeoutFound = false;
foreach ($parsed as $ev) {
    if (($ev['event'] ?? '') === 'transition'
        && ($ev['trigger'] ?? '') === 'apartment_timeout'
        && ($ev['from'] ?? '') === 'apartment'
        && ($ev['to'] ?? '') === 'playing') {
        $timeoutFound = true;
    }
}
assertTrue($timeoutFound, 'audit log records apartment_timeout transition');
assertTrue(count(StateMachineAudit::validateLog($parsed)) === 0, 'GROUP 4 audit log validates');

// =============================================================================
// GROUP 5 — player disconnect / reconnect + host transfer
// =============================================================================

echo "\nGROUP 5: disconnect / reconnect recovery\n";

@unlink($auditLogPath);
$GLOBALS['__lotto_state_audit'] = new StateMachineAudit(new FakeLogger(), $auditLogPath);
\MockTimer::reset();

$rcWorker = new FlowWorker();
$rcHost = new FlowConnection();
$rcHost->userId = 501;
$rcHost->username = 'rchost';
$rcHost->sessionToken = 'host-tok';
$rcGuest = new FlowConnection();
$rcGuest->userId = 502;
$rcGuest->username = 'rcguest';

$rcRoom = makeCardWithClosedRow();
$rcMask = makeMaskWithClosedRow($rcRoom);
$rcWorker->rooms[7] = [
    'room_id' => 7,
    'host_conn_id' => $rcHost->id,
    'status' => 'playing',
    'bank' => 20,
    'bet_per_card' => 10,
    'max_players' => 10,
    'password_hash' => null,
    'apartment_fired' => false,
    'pause_for_apartment' => false,
    'apartment_responses' => [],
    'game_afk_timer_id' => null,
    'apartment_timer_id' => null,
    'lobby_afk_timer_id' => null,
    'active_drawer_conn_id' => $rcHost->id,
    'drawer_order' => [$rcHost->id, $rcGuest->id],
    'bag' => range(1, 90),
    'drawn_numbers' => [],
    'all_players_history' => [],
    'players' => [
        $rcHost->id => [
            'user_id' => 501, 'username' => 'rchost', 'status' => 'active',
            'session_token' => 'host-tok', 'connection' => $rcHost,
            'cards' => [$rcRoom], 'masks' => [$rcMask], 'cards_count' => 1,
            'total_paid' => 10, 'immune' => false, 'reconnect_timer' => null,
            'last_action' => time(), 'afk_start' => null, 'strikes' => 0, 'auto_draws' => 0,
        ],
        $rcGuest->id => [
            'user_id' => 502, 'username' => 'rcguest', 'status' => 'active',
            'session_token' => 'guest-tok', 'connection' => $rcGuest,
            'cards' => [[]], 'masks' => [[[false]]], 'cards_count' => 1,
            'total_paid' => 10, 'immune' => false, 'reconnect_timer' => null,
            'last_action' => time(), 'afk_start' => null, 'strikes' => 0, 'auto_draws' => 0,
        ],
    ],
];

class RcMockLobby
{
    public function removePlayerFromLobby(object $w, int $r, int $c, string $reason): void {}
}

class RcMockGame
{
    public function handleDrawBarrel(object $c, object $w): void {}
    public function finishGame(array &$room, int $roomId, array $winners, array $prizes, object $worker, string $reason = 'victory'): void {}
    public function nextDrawer(array &$room): void {}
    public function sendYourTurn(array &$room): void {}
}

$rcSvc = new ReconnectService(new RcMockLobby(), new RcMockGame(), new FakeLogger());
$rcSvc->handleDisconnect($rcHost, $rcWorker);
assertTrue($rcWorker->rooms[7]['players'][$rcHost->id]['status'] === 'disconnected', 'host disconnect → disconnected');

$newHostConn = new FlowConnection();
$newHostConn->id = 9001;
$newHostConn->userId = 501;
$newHostConn->username = 'rchost';

$reconnected = $rcSvc->handleReconnect('host-tok', $newHostConn, $rcWorker);
assertTrue($reconnected, 'host reconnect succeeds');
assertTrue($rcWorker->rooms[7]['host_conn_id'] === $newHostConn->id, 'host_conn_id updated after reconnect');
assertTrue($rcWorker->rooms[7]['players'][$newHostConn->id]['status'] === 'active', 'reconnected host is active');

$parsed = StateMachineAudit::parseLog($auditLogPath);
assertTrue(count(StateMachineAudit::validateLog($parsed)) === 0, 'GROUP 5 audit log validates');

// =============================================================================
// GROUP 6 — join_room rejected in playing
// =============================================================================

echo "\nGROUP 6: join_room rejected in playing\n";

$intruder = new FlowConnection();
$stack['authHandler']->handleRegister(
    ['action' => 'register', 'username' => 'intruder', 'password' => 'secret123'],
    $intruder,
    $worker
);
$stack['authHandler']->handleLogin(
    ['action' => 'login', 'username' => 'intruder', 'password' => 'secret123'],
    $intruder,
    $worker
);
$stack['lobbyHandler']->handleJoinRoom(
    ['action' => 'join_room', 'room_id' => $roomId],
    $intruder,
    $worker
);
assertTrue(($intruder->lastPacket()['type'] ?? '') === 'error', 'join_room in playing rejected');

// =============================================================================
// Summary
// =============================================================================

putenv('LOTTO_STATE_AUDIT=0');
unset($GLOBALS['__lotto_state_audit']);
@unlink($auditLogPath);

$total = $passed + $failed;
echo "\n" . str_repeat('-', 40) . "\n";
echo "EPIC-11.4 state machine audit: {$passed}/{$total} passed\n";
exit($failed > 0 ? 1 : 0);
