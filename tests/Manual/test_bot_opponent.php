<?php

declare(strict_types=1);

/**
 * EPIC-034.1 — Bot entity + play_vs_bot start path + turn engine fold-in.
 * Run: php tests/Manual/test_bot_opponent.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Helpers.php';

use Lotto\Game\GameService;
use Lotto\Game\GameTurnService;
use Lotto\Game\LottoEngine;
use Lotto\Game\VictoryService;
use Lotto\Game\ApartmentService;
use Lotto\Game\GameFinishService;
use Lotto\Game\ReconnectService;
use Lotto\Core\RoomManager;
use Lotto\Core\Logger;

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

class MockConnection {
    public int $id;
    public ?int $userId;
    public string $username;
    public array $sent = [];
    public ?string $lastError = null;

    public function __construct(int $id, int $userId, string $username) {
        $this->id = $id;
        $this->userId = $userId;
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

    public function sentOfType(string $type): array {
        return array_values(array_filter($this->sent, fn($p) => ($p['type'] ?? '') === $type));
    }
}

class MockWorker {
    public array $rooms = [];
}

class MockPDO {
    public bool $committed = false;
    public bool $rolledBack = false;
    public function beginTransaction(): void {}
    public function commit(): void { $this->committed = true; }
    public function rollBack(): void { $this->rolledBack = true; }
}

class MockDatabase {
    public MockPDO $pdo;
    public function __construct(MockPDO $pdo) { $this->pdo = $pdo; }
    public function getPdo(): MockPDO { return $this->pdo; }
}

class MockPreparedStatements {
    private array $users;
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

class MockLogger {
    public array $logs = [];
    public function info(string $m): void { $this->logs[] = $m; }
    public function error(string $m): void { $this->logs[] = $m; }
    public function warning(string $m): void { $this->logs[] = $m; }
    public function write(string $level, string $m): void { $this->logs[] = $m; }
}

function makeWaitingRoom(int $hostConnId): array {
    return [
        'room_id'               => 1,
        'host_conn_id'          => $hostConnId,
        'bet_per_card'          => 10,
        'max_players'           => 10,
        'password_hash'         => null,
        'status'                => 'waiting',
        'bank'                  => 0,
        'apartment_fired'       => false,
        'pause_for_apartment'   => false,
        'apartment_responses'   => [],
        'win_chance_history'    => [],
        'game_afk_timer_id'     => null,
        'apartment_timer_id'    => null,
        'lobby_afk_timer_id'    => null,
        'active_drawer_conn_id' => null,
        'drawer_order'          => [$hostConnId],
        'bag'                   => [],
        'drawn_numbers'         => [],
        'players'               => [],
        'all_players_history'   => [],
        'game_roster'           => [],
        'bot'                   => null,
        'speed_mode'            => 'slow',
    ];
}

function makePlayer(MockConnection $conn, int $cardsCount = 1): array {
    return [
        'user_id'         => $conn->userId,
        'username'        => $conn->username,
        'cards'           => [],
        'cards_count'     => $cardsCount,
        'total_paid'      => 0,
        'last_action'     => time(),
        'afk_start'       => null,
        'strikes'         => 0,
        'auto_draws'      => 0,
        'status'          => 'active',
        'session_token'   => 'tok_' . $conn->id,
        'reconnect_timer' => null,
        'connection'      => $conn,
        'immune'          => false,
    ];
}

function makeBot(array $cards = [], array $masks = []): array {
    if ($cards === []) {
        $eng = new LottoEngine();
        $cards = [$eng->generateCard(), $eng->generateCard()];
    }
    if ($masks === []) {
        foreach ($cards as $card) {
            $masks[] = array_map(fn($row) => array_fill(0, 9, false), $card);
        }
    }
    return [
        'username'    => 'Bot',
        'cards'       => $cards,
        'cards_count' => 2,
        'total_paid'  => 0,
        'immune'      => false,
        'drawing'     => false,
        'status'      => 'active',
        'masks'       => $masks,
    ];
}

function makeService(array $users, MockPDO $pdo): array {
    $db   = new MockDatabase($pdo);
    $stmts = new MockPreparedStatements($users);
    $log  = new MockLogger();
    $eng  = new LottoEngine();
    $vic  = new VictoryService();
    $apt  = new ApartmentService($db, $stmts, $log);
    $fin  = (new \ReflectionClass(GameFinishService::class))->newInstanceWithoutConstructor();
    $turn = new GameTurnService($log, $vic, $apt, $fin);
    $svc  = new GameService($db, $stmts, $eng, $log, $vic, $apt, $fin, $turn);
    return [$svc, $log, $stmts, $pdo, $turn];
}

echo "=== EPIC-034.1 Bot opponent ===\n\n";

// -------------------------------------------------------------------------
// 1. RoomManager initializes bot = null
// -------------------------------------------------------------------------
{
    $logPath = sys_get_temp_dir() . '/lotto_bot_rm_' . getmypid() . '.log';
    $rm = new RoomManager(new Logger($logPath));
    $worker = new MockWorker();
    $roomId = $rm->createRoom($worker, 1, 4, null);
    assert_true(
        array_key_exists('bot', $worker->rooms[$roomId]) && $worker->rooms[$roomId]['bot'] === null,
        'RoomManager: bot key exists and is null'
    );
    @unlink($logPath);
}

// -------------------------------------------------------------------------
// 2. play_vs_bot guards
// -------------------------------------------------------------------------
{
    $host = new MockConnection(1, 10, 'host');
    $host->userId = null;
    [$svc] = makeService([], new MockPDO());
    $svc->handlePlayVsBot($host, new MockWorker());
    assert_true($host->lastError === 'error.auth_required', 'Guard: unauthenticated → auth_required');
}

{
    $host = new MockConnection(1, 10, 'host');
    [$svc] = makeService([], new MockPDO());
    $svc->handlePlayVsBot($host, new MockWorker());
    assert_true($host->lastError === 'error.room_not_found', 'Guard: not in room → room_not_found');
}

{
    $host = new MockConnection(1, 10, 'host');
    $guest = new MockConnection(2, 20, 'guest');
    $worker = new MockWorker();
    $room = makeWaitingRoom(1);
    $room['players'][1] = makePlayer($host);
    $room['players'][2] = makePlayer($guest);
    $room['drawer_order'] = [1, 2];
    $worker->rooms[1] = $room;
    [$svc] = makeService([10 => ['id' => 10, 'coins' => 500]], new MockPDO());
    $svc->handlePlayVsBot($guest, $worker);
    assert_true($guest->lastError === 'error.not_your_turn', 'Guard: non-host → not_your_turn');
}

{
    $host = new MockConnection(1, 10, 'host');
    $guest = new MockConnection(2, 20, 'guest');
    $worker = new MockWorker();
    $room = makeWaitingRoom(1);
    $room['players'][1] = makePlayer($host);
    $room['players'][2] = makePlayer($guest);
    $room['drawer_order'] = [1, 2];
    $worker->rooms[1] = $room;
    [$svc] = makeService([10 => ['id' => 10, 'coins' => 500]], new MockPDO());
    $svc->handlePlayVsBot($host, $worker);
    assert_true($host->lastError === 'error.not_your_turn', 'Guard: two humans → not_your_turn');
}

{
    $host = new MockConnection(1, 10, 'host');
    $worker = new MockWorker();
    $room = makeWaitingRoom(1);
    $room['status'] = 'playing';
    $room['players'][1] = makePlayer($host);
    $worker->rooms[1] = $room;
    [$svc] = makeService([10 => ['id' => 10, 'coins' => 500]], new MockPDO());
    $svc->handlePlayVsBot($host, $worker);
    assert_true($host->lastError === 'error.not_your_turn', 'Guard: not waiting → not_your_turn');
}

{
    $host = new MockConnection(1, 10, 'host');
    $p2 = new MockConnection(2, 20, 'p2');
    $worker = new MockWorker();
    $room = makeWaitingRoom(1);
    $room['players'][1] = makePlayer($host);
    $room['players'][2] = makePlayer($p2);
    $room['drawer_order'] = [1, 2];
    $worker->rooms[1] = $room;
    [$svc] = makeService([
        10 => ['id' => 10, 'coins' => 500],
        20 => ['id' => 20, 'coins' => 500],
    ], new MockPDO());
    $svc->handleStartGame($host, $worker);
    assert_true($worker->rooms[1]['status'] === 'playing', 'start_game: still starts with ≥2 humans');
    assert_true(($worker->rooms[1]['bot'] ?? null) === null, 'start_game: does not create a bot');
}

{
    $host = new MockConnection(1, 10, 'host');
    $worker = new MockWorker();
    $room = makeWaitingRoom(1);
    $room['players'][1] = makePlayer($host);
    $worker->rooms[1] = $room;
    [$svc] = makeService([], new MockPDO());
    $svc->handleStartGame($host, $worker);
    assert_true($host->lastError === 'error.not_your_turn', 'start_game: still rejects solo human');
}

// -------------------------------------------------------------------------
// 3. Successful play_vs_bot start path
// -------------------------------------------------------------------------
{
    $host = new MockConnection(1, 10, 'host');
    $worker = new MockWorker();
    $room = makeWaitingRoom(1);
    $room['players'][1] = makePlayer($host, 2);
    $worker->rooms[1] = $room;
    $pdo = new MockPDO();
    [$svc, , $stmts] = makeService([10 => ['id' => 10, 'coins' => 500]], $pdo);
    $svc->handlePlayVsBot($host, $worker);

    $r = $worker->rooms[1];
    assert_true($r['status'] === 'playing', 'Start: waiting → playing');
    assert_true(is_array($r['bot']), 'Start: bot object created');
    assert_true($r['bot']['username'] === 'Bot', 'Start: bot username Bot');
    assert_true($r['bot']['cards_count'] === 2, 'Start: bot cards_count=2');
    assert_true($r['bot']['total_paid'] === 0, 'Start: bot total_paid=0');
    assert_true($r['bot']['drawing'] === false, 'Start: bot not drawing (host first)');
    assert_true($r['bot']['status'] === 'active', 'Start: bot status active');
    assert_true(count($r['bot']['cards']) === 2, 'Start: bot has 2 real cards');
    assert_true(count($r['bot']['masks']) === 2, 'Start: bot has 2 masks');
    assert_true(!isset($r['players'][PHP_INT_MIN]) && count($r['players']) === 1, 'Start: bot not in players');
    assert_true($r['drawer_order'] === [1], 'Start: drawer_order is human only');
    assert_true($r['bank'] === 20, 'Start: bank = human total_paid (2 cards × 10)');
    assert_true($r['players'][1]['total_paid'] === 20, 'Start: human total_paid=20');
    assert_true(count($r['players'][1]['cards']) === 2, 'Start: human cards generated');
    assert_true($r['active_drawer_conn_id'] === 1, 'Start: host draws first');
    assert_true($pdo->committed === true, 'Start: PDO committed');
    assert_true(count($stmts->updates) === 1, 'Start: only one coins update');
    assert_true($stmts->updates[0]['user_id'] === 10, 'Start: coins update is the human');
    assert_true($stmts->updates[0]['coins'] === 480, 'Start: human coins 500-20');
    assert_true(!isset($r['all_players_history']['Bot']) && $r['all_players_history'] === [], 'Start: bot not in history');

    $started = $host->sentOfType('game_started');
    assert_true(count($started) === 1, 'Start: game_started sent');
    $pkt = $started[0];
    assert_true($pkt['bank'] === 20, 'Start: game_started.bank=20');
    assert_true($pkt['drawer_order'] === ['host'], 'Start: drawer_order wire has no Bot');
    $usernames = array_column($pkt['players'], 'username');
    assert_true(in_array('Bot', $usernames, true), 'Start: roster includes Bot');
    $botEntry = null;
    $selfEntry = null;
    foreach ($pkt['players'] as $entry) {
        if (($entry['username'] ?? '') === 'Bot') {
            $botEntry = $entry;
        }
        if (!empty($entry['is_self'])) {
            $selfEntry = $entry;
        }
    }
    assert_true($botEntry !== null && $botEntry['is_self'] === false, 'Start: Bot is_self false');
    assert_true($botEntry['cards'] === null, 'Start: Bot cards null (foreign)');
    assert_true($botEntry['cards_count'] === 2, 'Start: Bot cards_count 2');
    assert_true(count($botEntry['masks']) === 2, 'Start: Bot masks length 2');
    assert_true(!array_key_exists('is_bot', $botEntry), 'Start: no is_bot field');
    assert_true($selfEntry !== null && $selfEntry['cards'] !== null, 'Start: human sees own cards');
    $turns = $host->sentOfType('your_turn');
    assert_true(count($turns) === 1, 'Start: your_turn sent to human, not bot');
}

// -------------------------------------------------------------------------
// 4. Turn rotation + immediate bot draw
// -------------------------------------------------------------------------
{
    [$svc] = makeService([], new MockPDO());
    $host = new MockConnection(1, 10, 'host');
    $worker = new MockWorker();
    $room = makeWaitingRoom(1);
    $room['status'] = 'playing';
    $room['bank'] = 10;
    $room['apartment_fired'] = true;
    $room['active_drawer_conn_id'] = 1;
    $room['bag'] = range(1, 90);
    $room['players'][1] = makePlayer($host, 1);
    $eng = new LottoEngine();
    $room['players'][1]['cards'] = [$eng->generateCard()];
    $room['players'][1]['masks'] = [array_map(fn($row) => array_fill(0, 9, false), $room['players'][1]['cards'][0])];
    $room['bot'] = makeBot();
    $worker->rooms[1] = $room;

    $svc->handleDrawBarrel($host, $worker);
    $r = $worker->rooms[1];

    $drawnPkts = $host->sentOfType('barrels_drawn');
    assert_true(count($drawnPkts) === 2, 'Turn: human draw then immediate bot draw (2 barrels_drawn)');
    assert_true(($drawnPkts[0]['next_drawer'] ?? '') === 'Bot', 'Turn: after human, next_drawer=Bot');
    assert_true(($drawnPkts[1]['next_drawer'] ?? '') === 'host', 'Turn: after bot, next_drawer=host');
    assert_true(isset($drawnPkts[0]['win_chances']['Bot']), 'Turn: win_chances includes Bot (human draw)');
    assert_true(isset($drawnPkts[1]['win_chances']['Bot']), 'Turn: win_chances includes Bot (bot draw)');
    assert_true(isset($drawnPkts[0]['win_chances']['host']), 'Turn: win_chances includes host');
    assert_true($r['active_drawer_conn_id'] === 1, 'Turn: after bot, drawer is human again');
    assert_true($r['bot']['drawing'] === false, 'Turn: bot drawing cleared after bot turn');
    $yourTurns = $host->sentOfType('your_turn');
    assert_true(count($yourTurns) >= 1, 'Turn: your_turn only for human after cycle');
    assert_true(count($r['drawn_numbers']) === 6, 'Turn: 3 human + 3 bot barrels');
}

// -------------------------------------------------------------------------
// 5. nextDrawer isolation + mark bot cards
// -------------------------------------------------------------------------
{
    [$svc] = makeService([], new MockPDO());
    $host = new MockConnection(1, 10, 'host');
    $room = makeWaitingRoom(1);
    $room['status'] = 'playing';
    $room['active_drawer_conn_id'] = 1;
    $room['players'][1] = makePlayer($host);
    $room['bot'] = makeBot();

    $svc->nextDrawer($room);
    assert_true($room['active_drawer_conn_id'] === null, 'nextDrawer: human → bot sets conn_id null');
    assert_true($room['bot']['drawing'] === true, 'nextDrawer: bot drawing true');

    $svc->nextDrawer($room);
    assert_true($room['active_drawer_conn_id'] === 1, 'nextDrawer: bot → human');
    assert_true($room['bot']['drawing'] === false, 'nextDrawer: bot drawing false');
}

{
    [$svc] = makeService([], new MockPDO());
    $host = new MockConnection(1, 10, 'host');
    $worker = new MockWorker();

    $botCard = array_fill(0, 3, array_fill(0, 9, null));
    $botCard[0][0] = 5;
    $botCard[0][1] = 12;
    $botCard[0][2] = 23;
    $botCard[1][0] = 6;
    $botCard[1][3] = 34;
    $botMask = array_fill(0, 3, array_fill(0, 9, false));

    $humanCard = array_fill(0, 3, array_fill(0, 9, null));
    $humanCard[0][1] = 15;
    $humanCard[0][2] = 24;
    $humanCard[1][0] = 7;
    $humanCard[1][3] = 35;
    $humanMask = array_fill(0, 3, array_fill(0, 9, false));

    $room = makeWaitingRoom(1);
    $room['status'] = 'playing';
    $room['bank'] = 10;
    $room['apartment_fired'] = true;
    $room['active_drawer_conn_id'] = 1;
    $room['bag'] = [5, 15, 90, 80, 81, 82];
    $room['players'][1] = makePlayer($host, 1);
    $room['players'][1]['cards'] = [$humanCard];
    $room['players'][1]['masks'] = [$humanMask];
    $room['bot'] = makeBot([$botCard, $botCard], [$botMask, $botMask]);
    $worker->rooms[1] = $room;

    $svc->handleDrawBarrel($host, $worker);
    $r = $worker->rooms[1];
    assert_true($r['bot']['masks'][0][0][0] === true, 'mark: bot card marked for drawn 5');
    assert_true($r['players'][1]['masks'][0][0][1] === true, 'mark: human card marked for drawn 15');
}

// -------------------------------------------------------------------------
// 6. Game AFK must not tick while bot is drawing (even if drawer id is stale)
// -------------------------------------------------------------------------
{
    $host = new MockConnection(1, 10, 'host');
    $probe = new class {
        public int $draws = 0;
        public function handleDrawBarrel(object $c, object $w, bool $auto = false): void { $this->draws++; }
        public function calculateWinChances(array $p, ?string $s = null): array { return []; }
        public function nextDrawer(array &$r): void {}
        public function startTurn(array &$r, object $w, int $id, bool $d = false): void {}
        public function handleNoSurvivors(array &$r, int $id, object $w, $n = null): void {}
        public function finishGame(...$a): void {}
    };
    $rc = new ReconnectService(new stdClass(), $probe, new MockLogger());
    $worker = new MockWorker();
    $room = makeWaitingRoom(1);
    $room['status'] = 'playing';
    $room['active_drawer_conn_id'] = 1;
    $room['bot'] = makeBot();
    $room['bot']['drawing'] = true;
    $room['players'][1] = makePlayer($host);
    $room['players'][1]['afk_start'] = time() - 120;
    $worker->rooms[1] = $room;

    $rc->tickGameAfk($worker, 1);
    assert_true($probe->draws === 0, 'AFK: no tick/auto-draw while bot drawing (stale human drawer id)');
    assert_true($worker->rooms[1]['players'][1]['auto_draws'] === 0, 'AFK: human auto_draws unchanged');
}

{
    $host = new MockConnection(1, 10, 'host');
    [$svc, , , , $turn] = makeService([], new MockPDO());
    $room = makeWaitingRoom(1);
    $room['status'] = 'playing';
    $room['active_drawer_conn_id'] = null;
    $room['bot'] = makeBot();
    $room['bot']['drawing'] = true;
    $room['players'][1] = makePlayer($host);
    $turn->sendYourTurn($room);
    $turn->startTurn($room, new MockWorker(), 1);
    assert_true($host->sentOfType('your_turn') === [], 'AFK: startTurn/sendYourTurn no-op while bot drawing');
}

$total = $passed + $failed;
echo "\n--- EPIC-034.1 ---\n";
echo "$passed / $total PASSED\n";
if ($failed > 0) {
    exit(1);
}
