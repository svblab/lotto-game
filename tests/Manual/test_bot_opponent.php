<?php

declare(strict_types=1);

/**
 * EPIC-034.1–034.4 — Bot opponent (start, turns, apartment, bot_win, streak/mint).
 * Run: php tests/Manual/test_bot_opponent.php
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
    public array $botWinStreaks = [];
    public object $lobbyService;

    public function __construct()
    {
        $this->lobbyService = new class {
            public function broadcastRoomList(object $worker): void {}
        };
    }
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
    /** @var array<int, array> */
    public array $users;
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
        if ($key === 'add_user_coins') {
            $parent = $this;
            return new class($parent) {
                private object $parent;
                public function __construct(object $p) { $this->parent = $p; }
                public function execute(array $p): void {
                    $this->parent->updates[] = ['add' => $p[0], 'user_id' => $p[1]];
                    $uid = $p[1];
                    if (isset($this->parent->users[$uid])) {
                        $this->parent->users[$uid]['coins'] =
                            (int) $this->parent->users[$uid]['coins'] + (int) $p[0];
                    }
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

function makePlayer(MockConnection $conn, int $cardsCount = 1, array $cards = [], array $masks = [], bool $immune = false): array {
    $eng = new LottoEngine();
    if ($cards === []) {
        for ($i = 0; $i < $cardsCount; $i++) {
            $cards[] = $eng->generateCard();
        }
    }
    if ($masks === []) {
        foreach ($cards as $card) {
            $masks[] = array_map(fn($row) => array_fill(0, 9, false), $card);
        }
    }
    return [
        'user_id'         => $conn->userId,
        'username'        => $conn->username,
        'cards'           => $cards,
        'masks'           => $masks,
        'cards_count'     => $cardsCount,
        'total_paid'      => $cardsCount * 10,
        'last_action'     => time(),
        'afk_start'       => null,
        'strikes'         => 0,
        'auto_draws'      => 0,
        'status'          => 'active',
        'session_token'   => 'tok_' . $conn->id,
        'reconnect_timer' => null,
        'connection'      => $conn,
        'immune'          => $immune,
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
    $fin  = new GameFinishService($db, $stmts, $log);
    $turn = new GameTurnService($log, $vic, $apt, $fin);
    $svc  = new GameService($db, $stmts, $eng, $log, $vic, $apt, $fin, $turn);
    $apt->bindGameService($svc);
    return [$svc, $log, $stmts, $pdo, $turn, $apt];
}

/** Card with one fully-closed row (row 0) — same shape as test_apartment.php */
function makeCardWithClosedRow(): array {
    $card = [];
    for ($row = 0; $row < 3; $row++) {
        $card[$row] = array_fill(0, 9, null);
    }
    $card[0][0]=1; $card[0][2]=20; $card[0][4]=40; $card[0][6]=60; $card[0][8]=80;
    $card[1][1]=10; $card[1][3]=30; $card[1][5]=50; $card[1][7]=70; $card[1][8]=85;
    $card[2][0]=5;  $card[2][2]=25; $card[2][4]=45; $card[2][6]=65; $card[2][8]=90;
    return $card;
}

function makeMaskWithClosedRow(array $card): array {
    $mask = [];
    for ($row = 0; $row < 3; $row++) {
        $mask[$row] = [];
        for ($col = 0; $col < 9; $col++) {
            $mask[$row][$col] = false;
        }
    }
    for ($col = 0; $col < 9; $col++) {
        if ($card[0][$col] !== null) {
            $mask[0][$col] = true;
        }
    }
    return $mask;
}

/** Fully complete card/mask for victory (all 15 numbers marked). */
function makeCompleteCardAndMask(): array {
    $card = makeCardWithClosedRow();
    $mask = [];
    for ($row = 0; $row < 3; $row++) {
        $mask[$row] = [];
        for ($col = 0; $col < 9; $col++) {
            $mask[$row][$col] = ($card[$row][$col] !== null);
        }
    }
    return [$card, $mask];
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

// -------------------------------------------------------------------------
// 7. EPIC-034.2 — Apartment with bot
// -------------------------------------------------------------------------

echo "\n=== EPIC-034.2 Apartment fold-in ===\n";

{
    // Bot's row closed first → bot immune; human required; 10s timer arms; game resumes.
    MockTimer::reset();
    $host = new MockConnection(1, 10, 'host');
    $worker = new MockWorker();
    $users = [10 => ['id' => 10, 'coins' => 500]];
    [$svc, , $stmts, , , $apt] = makeService($users, new MockPDO());

    $botCard = makeCardWithClosedRow();
    $botMask = makeMaskWithClosedRow($botCard);
    $humanCard = makeCardWithClosedRow();
    $humanMask = [];
    for ($row = 0; $row < 3; $row++) {
        $humanMask[$row] = array_fill(0, 9, false);
    }

    $room = makeWaitingRoom(1);
    $room['status'] = 'playing';
    $room['bank'] = 10;
    $room['apartment_fired'] = false;
    $room['active_drawer_conn_id'] = 1;
    $room['players'][1] = makePlayer($host, 1, [$humanCard], [$humanMask]);
    $room['players'][1]['total_paid'] = 10;
    $room['bot'] = makeBot([$botCard, $botCard], [$botMask, $botMask]);
    $worker->rooms[1] = $room;

    assert_true($apt->hasLine($worker->rooms[1]['bot']) === true, 'Apt immune: bot hasLine');
    assert_true($apt->shouldTrigger($worker->rooms[1]) === true, 'Apt immune: shouldTrigger when bot has line');
    $apt->triggerApartment($worker->rooms[1], 1, $worker, $svc);
    $r = &$worker->rooms[1];

    assert_true(($r['status'] ?? '') === 'apartment', 'Apt immune: status=apartment');
    assert_true(is_array($r['bot'] ?? null), 'Apt immune: bot still present');
    assert_true(($r['bot']['immune'] ?? false) === true, 'Apt immune: bot immune=true');
    assert_true(($r['players'][1]['immune'] ?? true) === false, 'Apt immune: human not immune');
    assert_true(!empty($r['apartment_timer_id']), 'Apt immune: 10s apartment_timer_id armed');
    $alerts = $host->sentOfType('apartment_alert');
    assert_true(count($alerts) === 1, 'Apt immune: human got apartment_alert');
    assert_true(($alerts[0]['required'] ?? null) === true, 'Apt immune: human required=true');
    assert_true(!isset($r['apartment_responses']['Bot']), 'Apt immune: bot not in apartment_responses');

    $svc->handleApartmentChoice($host, $worker, 'agree');
    assert_true(($r['apartment_responses'][1] ?? '') === 'agree', 'Apt immune: human agree recorded');

    $apt->onApartmentTimeout($worker->rooms[1], 1, $worker, $svc);
    assert_true(isset($worker->rooms[1]), 'Apt immune: room still exists after resume');
    assert_true(($worker->rooms[1]['status'] ?? '') === 'playing', 'Apt immune: resumed playing');
    assert_true(is_array($worker->rooms[1]['bot'] ?? null), 'Apt immune: bot still present after resume');
    assert_true(($worker->rooms[1]['bank'] ?? 0) === 15, 'Apt immune: bank += apartment payment');
    MockTimer::reset();
}

{
    // Bot closed row during its draw → apartment → resume must hand turn to human (your_turn).
    MockTimer::reset();
    $host = new MockConnection(1, 10, 'host');
    $worker = new MockWorker();
    $users = [10 => ['id' => 10, 'coins' => 500]];
    [$svc, , , , , $apt] = makeService($users, new MockPDO());

    $botCard = makeCardWithClosedRow();
    $botMask = makeMaskWithClosedRow($botCard);
    $humanCard = makeCardWithClosedRow();
    $humanMask = [];
    for ($row = 0; $row < 3; $row++) {
        $humanMask[$row] = array_fill(0, 9, false);
    }

    $room = makeWaitingRoom(1);
    $room['status'] = 'apartment';
    $room['bank'] = 10;
    $room['apartment_fired'] = true;
    $room['bag'] = range(1, 90);
    $room['active_drawer_conn_id'] = null;
    $room['players'][1] = makePlayer($host, 1, [$humanCard], [$humanMask]);
    $room['players'][1]['total_paid'] = 10;
    $room['players'][1]['immune'] = false;
    $room['bot'] = makeBot([$botCard, $botCard], [$botMask, $botMask]);
    $room['bot']['immune'] = true;
    $room['bot']['drawing'] = true;
    $room['apartment_responses'] = [1 => 'agree'];
    $worker->rooms[1] = $room;
    $host->sent = [];

    $apt->finishApartment($worker->rooms[1], 1, $worker, $svc, 'apartment_timeout');
    $r = $worker->rooms[1];

    assert_true(($r['status'] ?? '') === 'playing', 'Apt resume: status=playing');
    assert_true(empty($r['bot']['drawing']), 'Apt resume: bot no longer drawing');
    assert_true(($r['active_drawer_conn_id'] ?? null) === 1, 'Apt resume: human is drawer');
    $turns = $host->sentOfType('your_turn');
    assert_true(count($turns) >= 1, 'Apt resume: your_turn sent to human after bot-immune apartment');
    MockTimer::reset();
}

{
    // Human row closed first → bot force-removed (refuse); last_survivor payout (existing economy).
    MockTimer::reset();
    $host = new MockConnection(1, 10, 'host');
    $worker = new MockWorker();
    $users = [10 => ['id' => 10, 'coins' => 500]];
    $pdo = new MockPDO();
    [$svc, , $stmts, , , $apt] = makeService($users, $pdo);

    $humanCard = makeCardWithClosedRow();
    $humanMask = makeMaskWithClosedRow($humanCard);
    $botCard = makeCardWithClosedRow();
    $botMask = [];
    for ($row = 0; $row < 3; $row++) {
        $botMask[$row] = array_fill(0, 9, false);
    }

    $room = makeWaitingRoom(1);
    $room['status'] = 'playing';
    $room['bank'] = 20;
    $room['apartment_fired'] = false;
    $room['game_roster'] = [1 => ['user_id' => 10, 'username' => 'host']];
    $room['win_chance_history'] = [];
    $room['active_drawer_conn_id'] = 1;
    $room['players'][1] = makePlayer($host, 1, [$humanCard], [$humanMask]);
    $room['players'][1]['total_paid'] = 20;
    $room['bot'] = makeBot([$botCard, $botCard], [$botMask, $botMask]);
    $worker = new MockWorker();
    $worker->rooms[1] = $room;

    assert_true($apt->hasLine($worker->rooms[1]['players'][1]) === true, 'Apt refuse: human hasLine');
    assert_true(($worker->rooms[1]['apartment_fired'] ?? null) === false, 'Apt refuse: apartment_fired false before');
    assert_true($apt->shouldTrigger($worker->rooms[1]) === true, 'Apt refuse: shouldTrigger on human line');
    $apt->triggerApartment($worker->rooms[1], 1, $worker, $svc);

    assert_true(!isset($worker->rooms[1]), 'Apt refuse: room destroyed after last_survivor');
    $left = $host->sentOfType('player_left');
    assert_true(count($left) >= 1, 'Apt refuse: player_left broadcast');
    $botLeft = null;
    foreach ($left as $pkt) {
        if (($pkt['username'] ?? '') === 'Bot') {
            $botLeft = $pkt;
        }
    }
    assert_true($botLeft !== null, 'Apt refuse: player_left username=Bot');
    assert_true(($botLeft['reason'] ?? '') === 'refuse', 'Apt refuse: reason=refuse');
    assert_true(!array_key_exists('user_id', $botLeft), 'Apt refuse: user_id omitted');
    assert_true($host->sentOfType('apartment_alert') === [], 'Apt refuse: no apartment_alert (instant end)');
    $overs = $host->sentOfType('game_over');
    assert_true(count($overs) === 1, 'Apt refuse: game_over sent');
    assert_true(($overs[0]['reason'] ?? '') === 'last_survivor', 'Apt refuse: reason=last_survivor');
    assert_true(($overs[0]['winner'] ?? '') === 'host', 'Apt refuse: winner=host');
    $paid = false;
    foreach ($stmts->updates as $u) {
        if (($u['add'] ?? null) === 20 && ($u['user_id'] ?? null) === 10) {
            $paid = true;
        }
    }
    assert_true($paid, 'Apt refuse: existing last_survivor payout credited human');
    MockTimer::reset();
}

{
    // Victory on same barrel overrides apartment (bot present) — priority unchanged.
    MockTimer::reset();
    $host = new MockConnection(1, 10, 'host');
    $worker = new MockWorker();
    $users = [10 => ['id' => 10, 'coins' => 500]];
    [$svc, , , , , $apt] = makeService($users, new MockPDO());
    $vic = new VictoryService();

    [$completeCard, $completeMask] = makeCompleteCardAndMask();
    $lastNum = null;
    $lastRow = $lastCol = null;
    for ($row = 2; $row >= 0 && $lastNum === null; $row--) {
        for ($col = 8; $col >= 0; $col--) {
            if ($completeCard[$row][$col] !== null) {
                $lastNum = $completeCard[$row][$col];
                $lastRow = $row;
                $lastCol = $col;
                $completeMask[$row][$col] = false;
                break;
            }
        }
    }
    assert_true($lastNum !== null, 'Apt victory: found last number to draw');

    $botCard = makeCardWithClosedRow();
    $botMask = [];
    for ($row = 0; $row < 3; $row++) {
        $botMask[$row] = array_fill(0, 9, false);
    }

    $playerBefore = makePlayer($host, 1, [$completeCard], [$completeMask]);
    assert_true($vic->checkCardVictory($playerBefore) === 0, 'Apt victory: not won before draw');
    // Row 0 is still fully marked, so a line already exists; completing the card
    // on this barrel would also keep shouldTrigger true — victory must win.
    assert_true($apt->hasLine($playerBefore) === true, 'Apt victory: line already present (row 0)');

    $room = makeWaitingRoom(1);
    $room['status'] = 'playing';
    $room['bank'] = 10;
    $room['apartment_fired'] = false;
    $room['game_roster'] = [1 => ['user_id' => 10, 'username' => 'host']];
    $room['win_chance_history'] = [];
    $room['active_drawer_conn_id'] = 1;
    $room['bag'] = [$lastNum];
    $room['players'][1] = $playerBefore;
    $room['players'][1]['total_paid'] = 10;
    $room['bot'] = makeBot([$botCard, $botCard], [$botMask, $botMask]);
    $worker->rooms[1] = $room;

    $svc->handleDrawBarrel($host, $worker);
    assert_true(!isset($worker->rooms[1]), 'Apt victory: room destroyed (victory path)');
    assert_true($host->sentOfType('apartment_alert') === [], 'Apt victory: apartment skipped');
    $overs = $host->sentOfType('game_over');
    assert_true(count($overs) === 1, 'Apt victory: game_over sent');
    assert_true(($overs[0]['reason'] ?? '') === 'victory', 'Apt victory: reason=victory (not apartment)');
    MockTimer::reset();
}

{
    // prepareApartment alone: bot required → cleared; bot with line → immune.
    MockTimer::reset();
    $host = new MockConnection(1, 10, 'host');
    [, , , , , $apt] = makeService([10 => ['id' => 10, 'coins' => 500]], new MockPDO());

    $humanCard = makeCardWithClosedRow();
    $humanMask = makeMaskWithClosedRow($humanCard);
    $botCard = makeCardWithClosedRow();
    $botMaskEmpty = [];
    for ($row = 0; $row < 3; $row++) {
        $botMaskEmpty[$row] = array_fill(0, 9, false);
    }

    $room = makeWaitingRoom(1);
    $room['apartment_fired'] = false;
    $room['players'][1] = makePlayer($host, 1, [$humanCard], [$humanMask]);
    assert_true($apt->hasLine($room['players'][1]) === true, 'prepareApartment: human hasLine precondition');
    $room['bot'] = makeBot([$botCard], [$botMaskEmpty]);
    $parts = $apt->prepareApartment($room);
    assert_true(($parts[1] ?? null) === false, 'prepareApartment: human with line immune');
    assert_true(($room['bot'] ?? null) === null, 'prepareApartment: bot without line cleared');

    $room2 = makeWaitingRoom(1);
    $room2['apartment_fired'] = false;
    $emptyHumanMask = [];
    for ($row = 0; $row < 3; $row++) {
        $emptyHumanMask[$row] = array_fill(0, 9, false);
    }
    $room2['players'][1] = makePlayer($host, 1, [$humanCard], [$emptyHumanMask]);
    $room2['bot'] = makeBot([$botCard], [makeMaskWithClosedRow($botCard)]);
    $apt->prepareApartment($room2);
    assert_true(is_array($room2['bot'] ?? null), 'prepareApartment: bot with line kept');
    assert_true(($room2['bot']['immune'] ?? false) === true, 'prepareApartment: bot immune set');
    MockTimer::reset();
}

// -------------------------------------------------------------------------
// 8. EPIC-034.3 — bot_win bank burn + human victory vs bot + streak reset
// -------------------------------------------------------------------------

echo "\n=== EPIC-034.3 bot_win / human victory vs bot ===\n";

{
    // Bot completes a card → bank burn, reason bot_win, streak unset.
    MockTimer::reset();
    $host = new MockConnection(1, 10, 'host');
    $worker = new MockWorker();
    $worker->botWinStreaks[10] = 2; // seed non-zero to prove reset fires
    $users = [10 => ['id' => 10, 'coins' => 480]];
    $pdo = new MockPDO();
    [$svc, , $stmts, , , $apt] = makeService($users, $pdo);
    $vic = new VictoryService();

    [$botCard, $botMask] = makeCompleteCardAndMask();
    $lastNum = null;
    for ($row = 2; $row >= 0 && $lastNum === null; $row--) {
        for ($col = 8; $col >= 0; $col--) {
            if ($botCard[$row][$col] !== null) {
                $lastNum = $botCard[$row][$col];
                $botMask[$row][$col] = false;
                break;
            }
        }
    }
    assert_true($lastNum !== null, 'bot_win: found last bot number');

    $humanCard = makeCardWithClosedRow();
    $emptyMask = [];
    for ($row = 0; $row < 3; $row++) {
        $emptyMask[$row] = array_fill(0, 9, false);
    }
    // Second bot card empty — only one card completes.
    $botCard2 = makeCardWithClosedRow();
    $botMask2 = $emptyMask;

    $room = makeWaitingRoom(1);
    $room['status'] = 'playing';
    $room['bank'] = 20;
    $room['apartment_fired'] = false;
    $room['game_roster'] = [1 => ['user_id' => 10, 'username' => 'host']];
    $room['win_chance_history'] = [
        ['turn_number' => 1, 'chances' => ['host' => 40.0, 'Bot' => 60.0]],
    ];
    $room['active_drawer_conn_id'] = 1;
    $room['bag'] = [$lastNum];
    $room['players'][1] = makePlayer($host, 1, [$humanCard], [$emptyMask]);
    $room['players'][1]['total_paid'] = 20;
    $room['bot'] = makeBot([$botCard, $botCard2], [$botMask, $botMask2]);
    $worker->rooms[1] = $room;

    assert_true($vic->checkBotVictory($worker->rooms[1]) === false, 'bot_win: not won before draw');
    assert_true($vic->checkAllVictories($worker->rooms[1]) === [], 'bot_win: no human winners before');

    $svc->handleDrawBarrel($host, $worker);

    assert_true(!isset($worker->rooms[1]), 'bot_win: room destroyed');
    assert_true(!array_key_exists(10, $worker->botWinStreaks), 'bot_win: streak unset (missing key ⇒ 0)');
    $overs = $host->sentOfType('game_over');
    assert_true(count($overs) === 1, 'bot_win: game_over sent');
    assert_true(($overs[0]['reason'] ?? '') === 'bot_win', 'bot_win: reason=bot_win');
    assert_true(($overs[0]['winner'] ?? '') === 'Bot', 'bot_win: winner=Bot');
    assert_true(($overs[0]['prize'] ?? null) === 0, 'bot_win: prize=0');
    assert_true(($overs[0]['final_bank'] ?? null) === 0, 'bot_win: final_bank=0');
    assert_true(is_array($overs[0]['win_chance_history'] ?? null)
        && count($overs[0]['win_chance_history']) >= 1, 'bot_win: win_chance_history present');
    assert_true(
        isset($overs[0]['win_chance_history'][0]['chances']['Bot']),
        'bot_win: win_chance_history includes Bot'
    );
    $stats = $overs[0]['statistics'] ?? [];
    assert_true(count($stats) === 1, 'bot_win: one human in statistics');
    assert_true(($stats[0]['username'] ?? '') === 'host', 'bot_win: stats username');
    assert_true(($stats[0]['paid'] ?? null) === 20, 'bot_win: stats paid=stake');
    assert_true(($stats[0]['received'] ?? null) === 0, 'bot_win: stats received=0');
    assert_true(($stats[0]['coins'] ?? null) === 480, 'bot_win: coins unchanged (ADR-016)');
    $credited = false;
    foreach ($stmts->updates as $u) {
        if (($u['add'] ?? 0) > 0 && ($u['user_id'] ?? null) === 10) {
            $credited = true;
        }
    }
    assert_true(!$credited, 'bot_win: no users.coins credit');
    assert_true($host->sentOfType('apartment_alert') === [], 'bot_win: apartment skipped');
    MockTimer::reset();
}

{
    // Human victory against bot — existing unmodified victory path (full bank).
    MockTimer::reset();
    $host = new MockConnection(1, 10, 'host');
    $worker = new MockWorker();
    $worker->botWinStreaks[10] = 1; // must NOT be cleared by human victory in 034.3
    $users = [10 => ['id' => 10, 'coins' => 480]];
    $pdo = new MockPDO();
    [$svc, , $stmts, , , ] = makeService($users, $pdo);
    $vic = new VictoryService();

    [$completeCard, $completeMask] = makeCompleteCardAndMask();
    $lastNum = null;
    for ($row = 2; $row >= 0 && $lastNum === null; $row--) {
        for ($col = 8; $col >= 0; $col--) {
            if ($completeCard[$row][$col] !== null) {
                $lastNum = $completeCard[$row][$col];
                $completeMask[$row][$col] = false;
                break;
            }
        }
    }
    assert_true($lastNum !== null, 'human vs bot victory: found last number');

    $botCard = makeCardWithClosedRow();
    $emptyMask = [];
    for ($row = 0; $row < 3; $row++) {
        $emptyMask[$row] = array_fill(0, 9, false);
    }

    $room = makeWaitingRoom(1);
    $room['status'] = 'playing';
    $room['bank'] = 20;
    $room['apartment_fired'] = false;
    $room['game_roster'] = [1 => ['user_id' => 10, 'username' => 'host']];
    $room['win_chance_history'] = [];
    $room['active_drawer_conn_id'] = 1;
    $room['bag'] = [$lastNum];
    $room['players'][1] = makePlayer($host, 1, [$completeCard], [$completeMask]);
    $room['players'][1]['total_paid'] = 20;
    $room['bot'] = makeBot([$botCard, $botCard], [$emptyMask, $emptyMask]);
    $worker->rooms[1] = $room;

    assert_true($vic->checkCardVictory($room['players'][1]) === 0, 'human vs bot: not won before');
    assert_true($vic->checkBotVictory($room) === false, 'human vs bot: bot not winning');

    $svc->handleDrawBarrel($host, $worker);

    assert_true(!isset($worker->rooms[1]), 'human vs bot: room destroyed');
    $overs = $host->sentOfType('game_over');
    assert_true(count($overs) === 1, 'human vs bot: game_over sent');
    assert_true(($overs[0]['reason'] ?? '') === 'victory', 'human vs bot: reason=victory (not bot_win)');
    assert_true(($overs[0]['winner'] ?? '') === 'host', 'human vs bot: winner=host');
    assert_true(($overs[0]['prize'] ?? null) === 20, 'human vs bot: full bank paid');
    assert_true(($overs[0]['statistics'][0]['received'] ?? null) === 20, 'human vs bot: received=bank only');
    assert_true(!isset($overs[0]['statistics'][0]['streak_mint']), 'human vs bot: no streak_mint');
    assert_true(($overs[0]['vs_bot'] ?? false) === true, 'human vs bot: vs_bot flag');
    $paid = false;
    foreach ($stmts->updates as $u) {
        if (($u['add'] ?? null) === 20 && ($u['user_id'] ?? null) === 10) {
            $paid = true;
        }
    }
    assert_true($paid, 'human vs bot: existing victory payout credited');
    // EPIC-034.4: human victory vs bot increments streak (1 → 2).
    assert_true(($worker->botWinStreaks[10] ?? null) === 2, 'human vs bot: streak incremented 1→2');
    MockTimer::reset();
}

{
    // Same barrel: human + bot both complete → human victory wins (priority).
    MockTimer::reset();
    $host = new MockConnection(1, 10, 'host');
    $worker = new MockWorker();
    [$svc, , , , , ] = makeService([10 => ['id' => 10, 'coins' => 500]], new MockPDO());
    $vic = new VictoryService();

    [$humanCard, $humanMask] = makeCompleteCardAndMask();
    [$botCard, $botMask] = makeCompleteCardAndMask();
    // Share the same last number on both cards so one barrel completes both.
    $shared = 90;
    $humanCard[2][8] = $shared;
    $botCard[2][8] = $shared;
    $humanMask[2][8] = false;
    $botMask[2][8] = false;

    $emptyMask = [];
    for ($row = 0; $row < 3; $row++) {
        $emptyMask[$row] = array_fill(0, 9, false);
    }

    $room = makeWaitingRoom(1);
    $room['status'] = 'playing';
    $room['bank'] = 10;
    $room['apartment_fired'] = true; // avoid apartment distraction
    $room['game_roster'] = [1 => ['user_id' => 10, 'username' => 'host']];
    $room['active_drawer_conn_id'] = 1;
    $room['bag'] = [$shared];
    $room['players'][1] = makePlayer($host, 1, [$humanCard], [$humanMask]);
    $room['players'][1]['total_paid'] = 10;
    $room['bot'] = makeBot([$botCard, makeCardWithClosedRow()], [$botMask, $emptyMask]);
    $worker->rooms[1] = $room;

    assert_true($vic->checkAllVictories($room) === [], 'same-barrel: neither won before');
    assert_true($vic->checkBotVictory($room) === false, 'same-barrel: bot not won before');

    $svc->handleDrawBarrel($host, $worker);

    $overs = $host->sentOfType('game_over');
    assert_true(count($overs) === 1, 'same-barrel: game_over sent');
    assert_true(($overs[0]['reason'] ?? '') === 'victory', 'same-barrel: human victory overrides bot_win');
    assert_true(($overs[0]['winner'] ?? '') === 'host', 'same-barrel: winner=host');
    MockTimer::reset();
}

{
    // Unit: checkBotVictory / checkAllVictories stay separate.
    $vic = new VictoryService();
    [$card, $mask] = makeCompleteCardAndMask();
    $room = makeWaitingRoom(1);
    $room['bot'] = makeBot([$card], [$mask]);
    $room['players'] = [];
    assert_true($vic->checkBotVictory($room) === true, 'unit: checkBotVictory true when bot complete');
    assert_true($vic->checkAllVictories($room) === [], 'unit: checkAllVictories ignores bot');
    $room['bot'] = null;
    assert_true($vic->checkBotVictory($room) === false, 'unit: checkBotVictory false when bot null');
}

// -------------------------------------------------------------------------
// 9. EPIC-034.4 — win streak + double-bank mint
// -------------------------------------------------------------------------

echo "\n=== EPIC-034.4 streak + mint ===\n";

{
    // 3rd consecutive win vs bot → bank payout + equal mint, streak unset.
    MockTimer::reset();
    $host = new MockConnection(1, 10, 'host');
    $worker = new MockWorker();
    $worker->botWinStreaks[10] = 2; // next win is the 3rd
    $users = [10 => ['id' => 10, 'coins' => 480]];
    $pdo = new MockPDO();
    [$svc, , $stmts, , , ] = makeService($users, $pdo);

    [$completeCard, $completeMask] = makeCompleteCardAndMask();
    $lastNum = null;
    for ($row = 2; $row >= 0 && $lastNum === null; $row--) {
        for ($col = 8; $col >= 0; $col--) {
            if ($completeCard[$row][$col] !== null) {
                $lastNum = $completeCard[$row][$col];
                $completeMask[$row][$col] = false;
                break;
            }
        }
    }
    $emptyMask = [];
    for ($row = 0; $row < 3; $row++) {
        $emptyMask[$row] = array_fill(0, 9, false);
    }
    $botCard = makeCardWithClosedRow();

    $room = makeWaitingRoom(1);
    $room['status'] = 'playing';
    $room['bank'] = 20;
    $room['apartment_fired'] = true;
    $room['game_roster'] = [1 => ['user_id' => 10, 'username' => 'host']];
    $room['active_drawer_conn_id'] = 1;
    $room['bag'] = [$lastNum];
    $room['players'][1] = makePlayer($host, 1, [$completeCard], [$completeMask]);
    $room['players'][1]['total_paid'] = 20;
    $room['bot'] = makeBot([$botCard, $botCard], [$emptyMask, $emptyMask]);
    $worker->rooms[1] = $room;

    $svc->handleDrawBarrel($host, $worker);

    assert_true(!isset($worker->rooms[1]), 'streak mint: room destroyed');
    assert_true(!array_key_exists(10, $worker->botWinStreaks), 'streak mint: streak unset after bonus');
    $overs = $host->sentOfType('game_over');
    assert_true(count($overs) === 1, 'streak mint: game_over sent');
    assert_true(($overs[0]['reason'] ?? '') === 'victory', 'streak mint: reason=victory');
    assert_true(($overs[0]['prize'] ?? null) === 20, 'streak mint: prize=bank (mint not in prize field)');
    assert_true(($overs[0]['statistics'][0]['received'] ?? null) === 40, 'streak mint: received=bank+mint');
    assert_true(($overs[0]['statistics'][0]['streak_mint'] ?? null) === 20, 'streak mint: streak_mint=bank');
    assert_true(($overs[0]['vs_bot'] ?? false) === true, 'streak mint: vs_bot flag');
    assert_true(($overs[0]['statistics'][0]['coins'] ?? null) === 520, 'streak mint: coins=480+20+20');
    $adds = [];
    foreach ($stmts->updates as $u) {
        if (($u['user_id'] ?? null) === 10 && isset($u['add'])) {
            $adds[] = (int) $u['add'];
        }
    }
    sort($adds);
    assert_true($adds === [20, 20], 'streak mint: two credits of bank size');
    MockTimer::reset();
}

{
    // last_survivor after bot refuse also increments streak.
    MockTimer::reset();
    $host = new MockConnection(1, 10, 'host');
    $worker = new MockWorker();
    $worker->botWinStreaks[10] = 0;
    unset($worker->botWinStreaks[10]); // missing key ⇒ 0
    $users = [10 => ['id' => 10, 'coins' => 500]];
    [$svc, , , , , $apt] = makeService($users, new MockPDO());

    $humanCard = makeCardWithClosedRow();
    $humanMask = makeMaskWithClosedRow($humanCard);
    $botCard = makeCardWithClosedRow();
    $botMask = [];
    for ($row = 0; $row < 3; $row++) {
        $botMask[$row] = array_fill(0, 9, false);
    }

    $room = makeWaitingRoom(1);
    $room['status'] = 'playing';
    $room['bank'] = 20;
    $room['apartment_fired'] = false;
    $room['game_roster'] = [1 => ['user_id' => 10, 'username' => 'host']];
    $room['active_drawer_conn_id'] = 1;
    $room['players'][1] = makePlayer($host, 1, [$humanCard], [$humanMask]);
    $room['players'][1]['total_paid'] = 20;
    $room['bot'] = makeBot([$botCard, $botCard], [$botMask, $botMask]);
    $worker->rooms[1] = $room;

    $apt->triggerApartment($worker->rooms[1], 1, $worker, $svc);
    assert_true(!isset($worker->rooms[1]), 'streak last_survivor: room destroyed');
    assert_true(($worker->botWinStreaks[10] ?? null) === 1, 'streak last_survivor: streak 0→1');
    MockTimer::reset();
}

{
    // Human-vs-human finish resets streak for participants.
    MockTimer::reset();
    $host = new MockConnection(1, 10, 'host');
    $guest = new MockConnection(2, 20, 'guest');
    $worker = new MockWorker();
    $worker->botWinStreaks[10] = 2;
    $worker->botWinStreaks[20] = 1;
    $worker->botWinStreaks[99] = 3; // unrelated user — must survive
    $users = [
        10 => ['id' => 10, 'coins' => 500],
        20 => ['id' => 20, 'coins' => 500],
    ];
    [$svc, , , , , ] = makeService($users, new MockPDO());

    $room = makeWaitingRoom(1);
    $room['status'] = 'playing';
    $room['bank'] = 30;
    $room['bot'] = null;
    $room['game_roster'] = [
        1 => ['user_id' => 10, 'username' => 'host'],
        2 => ['user_id' => 20, 'username' => 'guest'],
    ];
    $room['players'][1] = makePlayer($host, 1);
    $room['players'][1]['total_paid'] = 10;
    $room['players'][2] = makePlayer($guest, 2);
    $room['players'][2]['total_paid'] = 20;
    $worker->rooms[1] = $room;

    $svc->finishGame($worker->rooms[1], 1, [1 => 1], [1 => 30], $worker, 'victory');
    assert_true(!array_key_exists(10, $worker->botWinStreaks), 'HvH finish: host streak cleared');
    assert_true(!array_key_exists(20, $worker->botWinStreaks), 'HvH finish: guest streak cleared');
    assert_true(($worker->botWinStreaks[99] ?? null) === 3, 'HvH finish: unrelated streak preserved');
    MockTimer::reset();
}

{
    // Fresh login clears streak; reconnect (freshLogin=false) does not.
    $worker = new MockWorker();
    $worker->botWinStreaks[10] = 2;
    $worker->sessionTokens = [];
    $worker->userConnections = [];
    $worker->connections = [];
    $logPath = sys_get_temp_dir() . '/lotto_streak_auth_' . getmypid() . '.log';
    $guard = new \Lotto\Auth\SessionGuardService(new Logger($logPath));
    $conn = new MockConnection(1, 10, 'host');
    $user = ['id' => 10, 'username' => 'host', 'is_admin' => false];

    $guard->claimUserSession($worker, 10, $conn, 'tok_reconnect', $user, false);
    assert_true(($worker->botWinStreaks[10] ?? null) === 2, 'reconnect: streak preserved');

    $guard->claimUserSession($worker, 10, $conn, 'tok_login', $user, true);
    assert_true(!array_key_exists(10, $worker->botWinStreaks), 'fresh login: streak cleared');
    @unlink($logPath);
}

$total = $passed + $failed;
echo "\n--- EPIC-034.1–034.4 Bot opponent ---\n";
echo "$passed / $total PASSED\n";
if ($failed > 0) {
    exit(1);
}
