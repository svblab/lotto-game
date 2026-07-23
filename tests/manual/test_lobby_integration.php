<?php

/**
 * EPIC-2.7 — Lobby Integration Tests
 *
 * Верифицирует контракты LobbyService, RoomManager (EPIC-2.0 — 2.6).
 * Запускается на VPS: php tests/Manual/test_lobby_integration.php
 *
 * Зависимости: namespace Lotto\Lobby, Lotto\Core. БД не требуется.
 * Workerman не требуется: MockConnection, MockWorker, MockTimer (mock_timer.php).
 *
 * Rule 22 ANCHOR_RULES: тесты верифицируют контракты, не компенсируют их отсутствие.
 */

declare(strict_types=1);

// ─── Bootstrap ───────────────────────────────────────────────────────────────

// mock_timer.php ОБЯЗАН быть первым — объявляет namespace Workerman\Timer
// до того как autoload загрузит реальный Workerman
require_once __DIR__ . '/mock_timer.php';

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    die("FAIL: vendor/autoload.php not found. Run: composer install\n");
}
require_once $autoload;
require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';

use Lotto\Lobby\LobbyService;
use Lotto\Core\RoomManager;
use Lotto\Core\Logger;
use Lotto\Core\Constants;

// ─── Test runner ─────────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;

function ok(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] {$name}\n";
        $passed++;
    } else {
        echo "[FAIL] {$name}" . ($detail ? " — {$detail}" : '') . "\n";
        $failed++;
    }
}

function summary(): void
{
    global $passed, $failed;
    $total = $passed + $failed;
    echo "\n─────────────────────────────────────────\n";
    echo "Results: {$passed}/{$total} passed";
    echo ($failed > 0 ? ", {$failed} FAILED" : '') . "\n";
    if ($failed > 0) {
        exit(1);
    }
}

// ─── Mock helpers ─────────────────────────────────────────────────────────────

class MockConnection
{
    private static int $nextId = 1;

    public array   $sent         = [];
    public int     $id;
    public ?int    $userId       = null;
    public ?string $username     = null;
    public bool    $isAdmin      = false;
    public ?string $sessionToken = null;

    public function __construct(int $userId = 0, string $username = '')
    {
        $this->id       = self::$nextId++;
        $this->userId   = $userId ?: $this->id * 10;
        $this->username = $username ?: "user_{$this->id}";
    }

    public function send(string $data): void
    {
        $this->sent[] = json_decode($data, true);
    }

    public function lastPacket(): ?array
    {
        return $this->sent[count($this->sent) - 1] ?? null;
    }

    public static function reset(): void
    {
        self::$nextId = 1;
    }
}

class MockWorker
{
    public array $rooms           = [];
    public array $userConnections = [];
    public array $sessionTokens   = [];
}

function makeLogger(): Logger
{
    return new Logger('/dev/null');
}

function makeServices(): array
{
    $logger       = makeLogger();
    $roomManager  = new RoomManager($logger);
    $lobbyService = new LobbyService($roomManager, $logger);
    return [$lobbyService, $roomManager];
}

// ─── SUITE 1: RoomManager ────────────────────────────────────────────────────

echo "\n=== SUITE 1: RoomManager ===\n";

MockConnection::reset();
[, $rm] = makeServices();
$worker = new MockWorker();

$conn1  = new MockConnection(1, 'host1');
$roomId = $rm->createRoom($worker, $conn1->id, 4, null);
ok('RoomManager: createRoom returns int room_id',     is_int($roomId) && $roomId > 0);
ok('RoomManager: room exists in worker->rooms',       isset($worker->rooms[$roomId]));
ok('RoomManager: room status = waiting',              $worker->rooms[$roomId]['status'] === 'waiting');
ok('RoomManager: room bank = 0',                      $worker->rooms[$roomId]['bank'] === 0);
ok('RoomManager: room host_conn_id correct',          $worker->rooms[$roomId]['host_conn_id'] === $conn1->id);
ok('RoomManager: room max_players correct',           $worker->rooms[$roomId]['max_players'] === 4);
ok('RoomManager: room password_hash null',            $worker->rooms[$roomId]['password_hash'] === null);
ok('RoomManager: all_players_history initialized',    $worker->rooms[$roomId]['all_players_history'] === []);
ok('RoomManager: drawer_order initialized empty',     $worker->rooms[$roomId]['drawer_order'] === []);

$rm->destroyRoom($worker, $roomId);
ok('RoomManager: destroyRoom removes room',           !isset($worker->rooms[$roomId]));
$rm->destroyRoom($worker, $roomId);
ok('RoomManager: destroyRoom non-existent is no-op',  true);

$worker2 = new MockWorker();
$rm->createRoom($worker2, 99, 4, null);
$worker2->rooms[1]['players'][99] = ['user_id' => 1, 'username' => 'x', 'status' => 'active'];
ok('RoomManager: findRoomIdByConnId finds room',      $rm->findRoomIdByConnId($worker2, 99) === 1);
ok('RoomManager: findRoomIdByConnId returns null',    $rm->findRoomIdByConnId($worker2, 999) === null);
ok('RoomManager: findRoomIdByUserId finds room',      $rm->findRoomIdByUserId($worker2, 1) === 1);
ok('RoomManager: findRoomIdByUserId returns null',    $rm->findRoomIdByUserId($worker2, 999) === null);

$worker3 = new MockWorker();
$rm->createRoom($worker3, 1, 4, null);
$rm->createRoom($worker3, 2, 4, null);
$worker3->rooms[1]['players'][1] = ['user_id' => 1, 'username' => 'a', 'status' => 'active'];
$worker3->rooms[1]['players'][2] = ['user_id' => 2, 'username' => 'b', 'status' => 'active'];
$worker3->rooms[2]['players'][3] = ['user_id' => 3, 'username' => 'c', 'status' => 'active'];
ok('RoomManager: getTotalPlayerCount = 3',            $rm->getTotalPlayerCount($worker3) === 3);
ok('RoomManager: getTotalPlayerCount empty = 0',      $rm->getTotalPlayerCount(new MockWorker()) === 0);

$fakeRoom = [
    'room_id'       => 7,
    'players'       => ['a' => [], 'b' => [], 'c' => []],
    'max_players'   => 10,
    'password_hash' => 'hash',
    'status'        => 'waiting',
];
$entry = $rm->buildRoomListEntry($fakeRoom);
ok('RoomManager: buildRoomListEntry room_id',         $entry['room_id'] === 7);
ok('RoomManager: buildRoomListEntry players count',   $entry['players'] === 3);
ok('RoomManager: buildRoomListEntry max_players',     $entry['max_players'] === 10);
ok('RoomManager: buildRoomListEntry has_password',    $entry['has_password'] === true);
ok('RoomManager: buildRoomListEntry status',          $entry['status'] === 'waiting');
$fakeRoom['password_hash'] = null;
$entry2 = $rm->buildRoomListEntry($fakeRoom);
ok('RoomManager: buildRoomListEntry no password',     $entry2['has_password'] === false);

// ─── SUITE 2: handleCreateRoom ───────────────────────────────────────────────

echo "\n=== SUITE 2: handleCreateRoom ===\n";

MockConnection::reset();

[$ls] = makeServices();
$worker = new MockWorker();
$conn   = new MockConnection(1, 'host');
$ls->handleCreateRoom(['max_players' => 4, 'password' => '', 'cards_count' => 1], $conn, $worker);
$pkt    = $conn->lastPacket();
ok('createRoom: sends room_joined',                   ($pkt['type'] ?? '') === 'room_joined');
ok('createRoom: room_joined has room_id',             isset($pkt['room_id']));
ok('createRoom: room_joined status = waiting',        ($pkt['status'] ?? '') === 'waiting');
ok('createRoom: room_joined bank = 0',                ($pkt['bank'] ?? -1) === 0);
ok('createRoom: room_joined host = username',         ($pkt['host'] ?? '') === 'host');
ok('createRoom: room_joined players count = 1',       count($pkt['players'] ?? []) === 1);
ok('createRoom: player entry username',               ($pkt['players'][0]['username'] ?? '') === 'host');
ok('createRoom: player entry cards_count',            ($pkt['players'][0]['cards_count'] ?? 0) === 1);
ok('createRoom: player status = active',              ($pkt['players'][0]['status'] ?? '') === 'active');
$roomId = $pkt['room_id'];
ok('createRoom: drawer_order has host',               in_array($conn->id, $worker->rooms[$roomId]['drawer_order']));
ok('createRoom: host_conn_id set',                    $worker->rooms[$roomId]['host_conn_id'] === $conn->id);

$connNoAuth         = new MockConnection();
$connNoAuth->userId = null;
[$ls2] = makeServices();
$ls2->handleCreateRoom(['max_players' => 4, 'password' => '', 'cards_count' => 1], $connNoAuth, new MockWorker());
ok('createRoom: auth_required without userId',
    ($connNoAuth->lastPacket()['code'] ?? '') === 'error.auth_required');

[$ls3] = makeServices();
$w3 = new MockWorker();
for ($i = 0; $i < Constants::MAX_ROOMS; $i++) {
    $w3->rooms[$i + 1] = ['players' => []];
}
$connLimit = new MockConnection(5, 'u5');
$ls3->handleCreateRoom(['max_players' => 4, 'password' => '', 'cards_count' => 1], $connLimit, $w3);
ok('createRoom: error.room_limit when MAX_ROOMS reached',
    ($connLimit->lastPacket()['code'] ?? '') === 'error.room_limit');

[$ls4] = makeServices();
$connBadCards = new MockConnection(6, 'u6');
$ls4->handleCreateRoom(['max_players' => 4, 'password' => '', 'cards_count' => 3], $connBadCards, new MockWorker());
ok('createRoom: error on invalid cards_count',
    ($connBadCards->lastPacket()['code'] ?? '') === 'error.invalid_json');

[$ls5] = makeServices();
$connBadMax = new MockConnection(7, 'u7');
$ls5->handleCreateRoom(['max_players' => 1, 'password' => '', 'cards_count' => 1], $connBadMax, new MockWorker());
ok('createRoom: error on max_players < 2',
    ($connBadMax->lastPacket()['code'] ?? '') === 'error.invalid_json');

$connBadMax2 = new MockConnection(8, 'u8');
$ls5->handleCreateRoom(['max_players' => 11, 'password' => '', 'cards_count' => 1], $connBadMax2, new MockWorker());
ok('createRoom: error on max_players > 10',
    ($connBadMax2->lastPacket()['code'] ?? '') === 'error.invalid_json');

[$ls6] = makeServices();
$w6      = new MockWorker();
$connPwd = new MockConnection(9, 'u9');
$ls6->handleCreateRoom(['max_players' => 4, 'password' => 'secret', 'cards_count' => 1], $connPwd, $w6);
$pwdRoomId = $connPwd->lastPacket()['room_id'];
ok('createRoom: password_hash is not null',           $w6->rooms[$pwdRoomId]['password_hash'] !== null);
ok('createRoom: password_hash is bcrypt',             password_verify('secret', $w6->rooms[$pwdRoomId]['password_hash']));

// ─── SUITE 3: handleJoinRoom ─────────────────────────────────────────────────

echo "\n=== SUITE 3: handleJoinRoom ===\n";

MockConnection::reset();

[$ls] = makeServices();
$worker = new MockWorker();
$host   = new MockConnection(1, 'host');
$ls->handleCreateRoom(['max_players' => 4, 'password' => '', 'cards_count' => 1], $host, $worker);
$roomId = $host->lastPacket()['room_id'];

$joiner = new MockConnection(2, 'joiner');
$ls->handleJoinRoom(['room_id' => $roomId, 'password' => '', 'cards_count' => 2], $joiner, $worker);
$pktJoin = $joiner->lastPacket();
ok('joinRoom: sends room_joined to joiner',           ($pktJoin['type'] ?? '') === 'room_joined');
ok('joinRoom: room_joined players count = 2',         count($pktJoin['players'] ?? []) === 2);
ok('joinRoom: host receives player_joined',           ($host->lastPacket()['type'] ?? '') === 'player_joined');
ok('joinRoom: player_joined username correct',        ($host->lastPacket()['username'] ?? '') === 'joiner');
ok('joinRoom: player_joined cards_count correct',     ($host->lastPacket()['cards_count'] ?? 0) === 2);
ok('joinRoom: joiner added to drawer_order',          in_array($joiner->id, $worker->rooms[$roomId]['drawer_order']));
ok('joinRoom: drawer_order FIFO host first',          $worker->rooms[$roomId]['drawer_order'][0] === $host->id);

$connNotFound = new MockConnection(3, 'u3');
$ls->handleJoinRoom(['room_id' => 9999, 'password' => '', 'cards_count' => 1], $connNotFound, $worker);
ok('joinRoom: error.room_not_found on wrong room_id',
    ($connNotFound->lastPacket()['code'] ?? '') === 'error.room_not_found');

[$ls2] = makeServices();
$w2       = new MockWorker();
$hostFull = new MockConnection(10, 'hfull');
$ls2->handleCreateRoom(['max_players' => 2, 'password' => '', 'cards_count' => 1], $hostFull, $w2);
$fullRoomId = $hostFull->lastPacket()['room_id'];
$joiner2    = new MockConnection(11, 'j2');
$ls2->handleJoinRoom(['room_id' => $fullRoomId, 'password' => '', 'cards_count' => 1], $joiner2, $w2);
$joiner3    = new MockConnection(12, 'j3');
$ls2->handleJoinRoom(['room_id' => $fullRoomId, 'password' => '', 'cards_count' => 1], $joiner3, $w2);
ok('joinRoom: error.room_full when room is full (FIX-7/ADR-004)',
    ($joiner3->lastPacket()['code'] ?? '') === 'error.room_full');

// FIX-7/ADR-004 regression: when BOTH the room and the server are full,
// error.server_full must win (server-wide check runs first).
[$ls2b] = makeServices();
$w2b       = new MockWorker();
$hostFull2 = new MockConnection(13, 'hfull2');
$ls2b->handleCreateRoom(['max_players' => 2, 'password' => '', 'cards_count' => 1], $hostFull2, $w2b);
$fullRoomId2 = $hostFull2->lastPacket()['room_id'];
// Делаем целевую комнату уже заполненной (max_players=2, +1 синтетический игрок).
$w2b->rooms[$fullRoomId2]['players'][999] = ['user_id' => 999, 'total_paid' => 0];
// Отдельно поднимаем ОБЩЕЕ число игроков на сервере выше MAX_TOTAL_PLAYERS через
// вторую синтетическую комнату — используем реальный RoomManager::getTotalPlayerCount().
$w2b->rooms[9001] = ['room_id' => 9001, 'players' => []];
for ($extraConnId = 100; $extraConnId < 100 + Constants::MAX_TOTAL_PLAYERS; $extraConnId++) {
    $w2b->rooms[9001]['players'][$extraConnId] = ['user_id' => $extraConnId, 'total_paid' => 0];
}
$joinerBoth = new MockConnection(14, 'jboth');
$ls2b->handleJoinRoom(['room_id' => $fullRoomId2, 'password' => '', 'cards_count' => 1], $joinerBoth, $w2b);
ok('joinRoom: error.server_full wins over room_full when both apply (FIX-7/ADR-004)',
    ($joinerBoth->lastPacket()['code'] ?? '') === 'error.server_full');

[$ls3] = makeServices();
$w3      = new MockWorker();
$hostPwd = new MockConnection(20, 'hpwd');
$ls3->handleCreateRoom(['max_players' => 4, 'password' => 'mypass', 'cards_count' => 1], $hostPwd, $w3);
$pwdRoomId    = $hostPwd->lastPacket()['room_id'];
$connWrongPwd = new MockConnection(21, 'wrongpwd');
$ls3->handleJoinRoom(['room_id' => $pwdRoomId, 'password' => 'bad', 'cards_count' => 1], $connWrongPwd, $w3);
ok('joinRoom: error on wrong password',
    ($connWrongPwd->lastPacket()['code'] ?? '') === 'error.auth_invalid_credentials');

$connGoodPwd = new MockConnection(22, 'goodpwd');
$ls3->handleJoinRoom(['room_id' => $pwdRoomId, 'password' => 'mypass', 'cards_count' => 1], $connGoodPwd, $w3);
ok('joinRoom: success with correct password',
    ($connGoodPwd->lastPacket()['type'] ?? '') === 'room_joined');

$connNoAuth         = new MockConnection();
$connNoAuth->userId = null;
[$ls4] = makeServices();
$ls4->handleJoinRoom(['room_id' => 1, 'password' => '', 'cards_count' => 1], $connNoAuth, new MockWorker());
ok('joinRoom: error.auth_required without userId',
    ($connNoAuth->lastPacket()['code'] ?? '') === 'error.auth_required');

// ─── SUITE 4: handleLeaveRoom & removePlayerFromLobby ────────────────────────

echo "\n=== SUITE 4: handleLeaveRoom & removePlayerFromLobby ===\n";

MockConnection::reset();

[$ls] = makeServices();
$worker = new MockWorker();
$host   = new MockConnection(1, 'host');
$ls->handleCreateRoom(['max_players' => 4, 'password' => '', 'cards_count' => 1], $host, $worker);
$roomId = $host->lastPacket()['room_id'];
$joiner = new MockConnection(2, 'joiner');
$ls->handleJoinRoom(['room_id' => $roomId, 'password' => '', 'cards_count' => 1], $joiner, $worker);

$ls->handleLeaveRoom($joiner, $worker);
ok('leaveRoom: room still exists after non-host leaves', isset($worker->rooms[$roomId]));
ok('leaveRoom: joiner removed from players',             !isset($worker->rooms[$roomId]['players'][$joiner->id]));
ok('leaveRoom: joiner removed from drawer_order',
    !in_array($joiner->id, $worker->rooms[$roomId]['drawer_order']));
ok('leaveRoom: host receives player_left',               ($host->lastPacket()['type'] ?? '') === 'player_left');
ok('leaveRoom: player_left username correct',            ($host->lastPacket()['username'] ?? '') === 'joiner');
ok('leaveRoom: player_left reason = leave',              ($host->lastPacket()['reason'] ?? '') === 'leave');

ok('leaveRoom: joiner in all_players_history',
    isset($worker->rooms[$roomId]['all_players_history'][$joiner->id]));
ok('leaveRoom: all_players_history username correct',
    ($worker->rooms[$roomId]['all_players_history'][$joiner->id]['username'] ?? '') === 'joiner');
ok('leaveRoom: all_players_history total_paid = 0',
    ($worker->rooms[$roomId]['all_players_history'][$joiner->id]['total_paid'] ?? -1) === 0);

[$ls2] = makeServices();
$w2   = new MockWorker();
$solo = new MockConnection(10, 'solo');
$ls2->handleCreateRoom(['max_players' => 4, 'password' => '', 'cards_count' => 1], $solo, $w2);
$soloRoomId = $solo->lastPacket()['room_id'];
$ls2->handleLeaveRoom($solo, $w2);
ok('leaveRoom: room destroyed when last player leaves',  !isset($w2->rooms[$soloRoomId]));

[$ls3] = makeServices();
$w3       = new MockWorker();
$hPlaying = new MockConnection(20, 'hplaying');
$ls3->handleCreateRoom(['max_players' => 4, 'password' => '', 'cards_count' => 1], $hPlaying, $w3);
$playingRoomId = $hPlaying->lastPacket()['room_id'];
$w3->rooms[$playingRoomId]['status'] = 'playing';
$ls3->handleLeaveRoom($hPlaying, $w3);
ok('leaveRoom: silent return when status = playing',     isset($w3->rooms[$playingRoomId]));

$connNoAuth         = new MockConnection();
$connNoAuth->userId = null;
[$ls4] = makeServices();
$ls4->handleLeaveRoom($connNoAuth, new MockWorker());
ok('leaveRoom: error.auth_required without userId',
    ($connNoAuth->lastPacket()['code'] ?? '') === 'error.auth_required');

// ─── SUITE 5: transferHost ───────────────────────────────────────────────────

echo "\n=== SUITE 5: transferHost ===\n";

MockConnection::reset();

[$ls] = makeServices();
$worker = new MockWorker();
$host   = new MockConnection(1, 'host');
$ls->handleCreateRoom(['max_players' => 4, 'password' => '', 'cards_count' => 1], $host, $worker);
$roomId = $host->lastPacket()['room_id'];
$j1     = new MockConnection(2, 'j1');
$j2     = new MockConnection(3, 'j2');
$ls->handleJoinRoom(['room_id' => $roomId, 'password' => '', 'cards_count' => 1], $j1, $worker);
$ls->handleJoinRoom(['room_id' => $roomId, 'password' => '', 'cards_count' => 1], $j2, $worker);

$ls->handleLeaveRoom($host, $worker);
ok('transferHost: room still exists',                    isset($worker->rooms[$roomId]));
ok('transferHost: host transferred to j1 (FIFO)',        $worker->rooms[$roomId]['host_conn_id'] === $j1->id);
ok('transferHost: old host removed from players',        !isset($worker->rooms[$roomId]['players'][$host->id]));

$ls->handleLeaveRoom($j1, $worker);
$ls->handleLeaveRoom($j2, $worker);
ok('transferHost: room destroyed when all leave',        !isset($worker->rooms[$roomId]));

// ─── SUITE 6: handleRoomList ─────────────────────────────────────────────────

echo "\n=== SUITE 6: handleRoomList ===\n";

MockConnection::reset();

[$ls] = makeServices();
$worker = new MockWorker();
$conn   = new MockConnection(1, 'u1');
$ls->handleRoomList($conn, $worker);
$pkt = $conn->lastPacket();
ok('roomList: type = room_list',                         ($pkt['type'] ?? '') === 'room_list');
ok('roomList: rooms is array',                           is_array($pkt['rooms'] ?? null));
ok('roomList: empty rooms when no rooms',                count($pkt['rooms']) === 0);

$host1 = new MockConnection(2, 'h1');
$host2 = new MockConnection(3, 'h2');
$ls->handleCreateRoom(['max_players' => 4, 'password' => '',       'cards_count' => 1], $host1, $worker);
$ls->handleCreateRoom(['max_players' => 6, 'password' => 'secret', 'cards_count' => 2], $host2, $worker);
$conn2 = new MockConnection(4, 'viewer');
$ls->handleRoomList($conn2, $worker);
$pkt2 = $conn2->lastPacket();
ok('roomList: returns 2 rooms',                          count($pkt2['rooms'] ?? []) === 2);
$r = $pkt2['rooms'][0];
ok('roomList: entry has room_id',                        isset($r['room_id']));
ok('roomList: entry has players count',                  isset($r['players']));
ok('roomList: entry has max_players',                    isset($r['max_players']));
ok('roomList: entry has has_password',                   isset($r['has_password']));
ok('roomList: entry has status',                         isset($r['status']));
ok('roomList: room without password has_password=false', $pkt2['rooms'][0]['has_password'] === false);
ok('roomList: room with password has_password=true',     $pkt2['rooms'][1]['has_password'] === true);

$connNoAuth         = new MockConnection();
$connNoAuth->userId = null;
[$ls2] = makeServices();
$ls2->handleRoomList($connNoAuth, new MockWorker());
ok('roomList: error.auth_required without userId',
    ($connNoAuth->lastPacket()['code'] ?? '') === 'error.auth_required');

// ─── SUITE 7: Lobby AFK Timer ────────────────────────────────────────────────

echo "\n=== SUITE 7: Lobby AFK Timer ===\n";

MockConnection::reset();
MockTimer::reset();

[$ls] = makeServices();
$worker = new MockWorker();
$host   = new MockConnection(1, 'host');
$ls->handleCreateRoom(['max_players' => 4, 'password' => '', 'cards_count' => 1], $host, $worker);
ok('afkTimer: no timer on createRoom (1 player)',        MockTimer::$addCount === 0);

$roomId = $host->lastPacket()['room_id'];
$j1     = new MockConnection(2, 'j1');
$ls->handleJoinRoom(['room_id' => $roomId, 'password' => '', 'cards_count' => 1], $j1, $worker);
ok('afkTimer: timer created on join (count=2)',          MockTimer::$addCount === 1);
ok('afkTimer: lobby_afk_timer_id stored in room',        !empty($worker->rooms[$roomId]['lobby_afk_timer_id']));

$addBefore = MockTimer::$addCount;
$delBefore = MockTimer::$delCount;
$j2        = new MockConnection(3, 'j2');
$ls->handleJoinRoom(['room_id' => $roomId, 'password' => '', 'cards_count' => 1], $j2, $worker);
ok('afkTimer: old timer deleted on re-join (max 1/room)', MockTimer::$delCount === $delBefore + 1);
ok('afkTimer: new timer created on re-join',              MockTimer::$addCount === $addBefore + 1);

$ls->handleLeaveRoom($j2, $worker);
$ls->handleLeaveRoom($j1, $worker);

echo "\n=== DEBUG AFK ===\n";
echo "Room exists: ";
var_dump(isset($worker->rooms[$roomId]));
if (isset($worker->rooms[$roomId])) {
    echo "Player count: ";
    var_dump(count($worker->rooms[$roomId]['players']));
    echo "lobby_afk_timer_id: ";
    var_dump($worker->rooms[$roomId]['lobby_afk_timer_id']);
}
echo "MockTimer::active:\n";
var_dump(MockTimer::$active);
echo "MockTimer::delCount: ";
var_dump(MockTimer::$delCount);

ok(
    'afkTimer: timer stopped when count < 2',
    array_key_exists('lobby_afk_timer_id', $worker->rooms[$roomId]) &&
    $worker->rooms[$roomId]['lobby_afk_timer_id'] === null
);

MockTimer::reset();
[$ls2] = makeServices();
$w2    = new MockWorker();
$h2    = new MockConnection(10, 'h2');
$ls2->handleCreateRoom(['max_players' => 4, 'password' => '', 'cards_count' => 1], $h2, $w2);
$rId2  = $h2->lastPacket()['room_id'];
$jj    = new MockConnection(11, 'jj');
$ls2->handleJoinRoom(['room_id' => $rId2, 'password' => '', 'cards_count' => 1], $jj, $w2);
ok('afkTimer: timer active before destroyRoom',           !empty($w2->rooms[$rId2]['lobby_afk_timer_id']));
[, $rm2] = makeServices();
$rm2->destroyRoom($w2, $rId2);
ok('afkTimer: destroyRoom removes room (timer cancelled)', !isset($w2->rooms[$rId2]));
ok('afkTimer: MockTimer::del called by destroyRoom',       MockTimer::$delCount >= 1);

// ─── Summary ──────────────────────────────────────────────────────────────────

summary();