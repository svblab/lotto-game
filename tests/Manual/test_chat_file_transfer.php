<?php

/**
 * ADR-030 — Room chat + file transfer contract tests.
 *
 * Run: php tests/Manual/test_chat_file_transfer.php
 *
 * Covers: chat broadcast, passwordless reject, offer/accept/relay,
 * reject, offer timeout, transfer lock, sender/recipient disconnect,
 * oversized decoded payload, file-offer rate limit.
 */

declare(strict_types=1);

require_once __DIR__ . '/mock_timer.php';

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    die("FAIL: vendor/autoload.php not found. Run: composer install\n");
}
require_once $autoload;
require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';

use Lotto\Chat\ChatHandler;
use Lotto\Chat\ChatService;
use Lotto\Chat\FileTransferService;
use Lotto\Core\Constants;
use Lotto\Core\Logger;
use Lotto\Core\RoomManager;
use Lotto\Lobby\LobbyHostService;
use Lotto\Lobby\LobbyService;

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

function packetOfType(MockConnection $conn, string $type): ?array
{
    foreach ($conn->sent as $packet) {
        if (($packet['type'] ?? null) === $type) {
            return $packet;
        }
    }
    return null;
}

function lastPacketOfType(MockConnection $conn, string $type): ?array
{
    for ($i = count($conn->sent) - 1; $i >= 0; $i--) {
        if (($conn->sent[$i]['type'] ?? null) === $type) {
            return $conn->sent[$i];
        }
    }
    return null;
}

function errorCode(MockConnection $conn): ?string
{
    $pkt = lastPacketOfType($conn, 'error');
    return is_array($pkt) ? ($pkt['code'] ?? null) : null;
}

class MockConnection
{
    private static int $nextId = 1;

    public array $sent = [];
    public int $id;
    public ?int $userId = null;
    public ?string $username = null;
    public bool $isAdmin = false;
    public ?string $sessionToken = null;
    public int $fileActionCount = 0;
    public int $fileActionWindowStart = 0;

    public function __construct(int $userId = 0, string $username = '')
    {
        $this->id = self::$nextId++;
        $this->userId = $userId ?: $this->id * 10;
        $this->username = $username ?: "user_{$this->id}";
        $this->fileActionWindowStart = time();
    }

    public function send(string $data): void
    {
        $this->sent[] = json_decode($data, true);
    }

    public function clearSent(): void
    {
        $this->sent = [];
    }

    public static function reset(): void
    {
        self::$nextId = 1;
    }
}

class MockWorker
{
    public array $rooms = [];
    public array $userConnections = [];
    public array $sessionTokens = [];
    public array $connections = [];
    public array $botWinStreaks = [];
    public ?array $serverSettings = null;
    public ?object $reconnectService = null;
    public ?FileTransferService $fileTransferService = null;
    public ?ChatService $chatService = null;
    public ?ChatHandler $chatHandler = null;
    public ?RoomManager $roomManager = null;
}

function makeLogger(): Logger
{
    $path = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'NUL' : '/dev/null';
    return new Logger($path);
}

/**
 * @return array{worker: MockWorker, lobby: LobbyService, chat: ChatHandler, ft: FileTransferService, rm: RoomManager}
 */
function makeStack(): array
{
    MockTimer::reset();
    MockConnection::reset();
    $logger = makeLogger();
    $rm = new RoomManager($logger);
    $host = new LobbyHostService($rm, $logger);
    $lobby = new LobbyService($rm, $logger, $host);
    $chatService = new ChatService($rm, $logger);
    $ft = new FileTransferService($rm, $chatService, $logger);
    $chat = new ChatHandler($chatService, $ft);

    $worker = new MockWorker();
    $worker->roomManager = $rm;
    $worker->chatService = $chatService;
    $worker->fileTransferService = $ft;
    $worker->chatHandler = $chat;

    return compact('worker', 'lobby', 'chat', 'ft', 'rm') + ['chatService' => $chatService];
}

/**
 * @return array{0: MockConnection, 1: MockConnection, 2: int}
 */
function seatTwoInPasswordRoom(LobbyService $lobby, MockWorker $worker, string $password = 'secret'): array
{
    $host = new MockConnection(1, 'alice');
    $guest = new MockConnection(2, 'bob');

    $lobby->handleCreateRoom([
        'max_players' => 4,
        'password' => $password,
        'cards_count' => 1,
    ], $host, $worker);

    $roomId = (int) (packetOfType($host, 'room_joined')['room_id'] ?? 0);
    $lobby->handleJoinRoom([
        'room_id' => $roomId,
        'password' => $password,
        'cards_count' => 1,
    ], $guest, $worker);

    $host->clearSent();
    $guest->clearSent();

    return [$host, $guest, $roomId];
}

function transferIdle(MockWorker $worker, int $roomId): bool
{
    $ft = $worker->rooms[$roomId]['file_transfer'] ?? null;
    return $ft === null;
}

function seatOpenRoom(LobbyService $lobby, MockWorker $worker): array
{
    $host = new MockConnection(11, 'open_host');
    $guest = new MockConnection(12, 'open_guest');
    $lobby->handleCreateRoom([
        'max_players' => 4,
        'password' => '',
        'cards_count' => 1,
    ], $host, $worker);
    $roomId = (int) (packetOfType($host, 'room_joined')['room_id'] ?? 0);
    $lobby->handleJoinRoom([
        'room_id' => $roomId,
        'cards_count' => 1,
    ], $guest, $worker);
    $host->clearSent();
    $guest->clearSent();
    return [$host, $guest, $roomId];
}

echo "=== ADR-030 chat + file transfer ===\n\n";

// ── 1. Chat broadcast in password room ──────────────────────────────────────
{
    $s = makeStack();
    [$alice, $bob] = seatTwoInPasswordRoom($s['lobby'], $s['worker']);
    $joined = packetOfType(new MockConnection(), 'room_joined'); // noop guard
    unset($joined);

    // Re-fetch has_password from a fresh create for documentation assert:
    $probe = new MockConnection(99, 'probe');
    // already seated — just message
    $s['chat']->handleRoomMessage(['text' => 'hello room'], $alice, $s['worker']);
    ok('chat broadcast to sender', packetOfType($alice, 'room_message')['text'] === 'hello room');
    ok('chat broadcast to peer', packetOfType($bob, 'room_message')['text'] === 'hello room');
    ok('chat from username', (packetOfType($bob, 'room_message')['from'] ?? '') === 'alice');
}

// ── 2. Chat unavailable in passwordless room ────────────────────────────────
{
    $s = makeStack();
    [$host, $guest] = seatOpenRoom($s['lobby'], $s['worker']);
    $s['chat']->handleRoomMessage(['text' => 'nope'], $host, $s['worker']);
    ok('passwordless chat rejected', errorCode($host) === 'error.chat_unavailable');
    ok('passwordless peer got no chat', packetOfType($guest, 'room_message') === null);
}

// ── 3. room_joined has_password ─────────────────────────────────────────────
{
    $s = makeStack();
    $host = new MockConnection(21, 'hp_alice');
    $s['lobby']->handleCreateRoom([
        'max_players' => 2,
        'password' => 'x',
        'cards_count' => 1,
    ], $host, $s['worker']);
    $pkt = packetOfType($host, 'room_joined');
    ok('room_joined.has_password true', ($pkt['has_password'] ?? null) === true);

    $open = new MockConnection(22, 'hp_open');
    $s['lobby']->handleCreateRoom([
        'max_players' => 2,
        'password' => '',
        'cards_count' => 1,
    ], $open, $s['worker']);
    $pkt2 = packetOfType($open, 'room_joined');
    ok('room_joined.has_password false', ($pkt2['has_password'] ?? null) === false);
}

// ── 4. Happy path offer/accept/relay ────────────────────────────────────────
{
    $s = makeStack();
    [$alice, $bob, $roomId] = seatTwoInPasswordRoom($s['lobby'], $s['worker']);
    $bytes = 'hello-file-bytes';
    $b64 = base64_encode($bytes);

    $s['chat']->handleFileOffer([
        'to_username' => 'bob',
        'filename' => 'hello.txt',
        'size_bytes' => strlen($bytes),
    ], $alice, $s['worker']);

    $offer = packetOfType($bob, 'file_offer');
    ok('recipient got file_offer', $offer !== null && ($offer['filename'] ?? '') === 'hello.txt');
    ok('sender got no file_offer', packetOfType($alice, 'file_offer') === null);
    ok('lock held after offer', is_array($s['worker']->rooms[$roomId]['file_transfer'] ?? null));

    $offerId = (string) ($offer['offer_id'] ?? '');
    $s['chat']->handleFileAccept(['offer_id' => $offerId], $bob, $s['worker']);
    ok('sender got file_accepted', (packetOfType($alice, 'file_accepted')['offer_id'] ?? '') === $offerId);
    ok('state relay_pending', ($s['worker']->rooms[$roomId]['file_transfer']['state'] ?? '') === 'relay_pending');

    $alice->clearSent();
    $bob->clearSent();
    $s['chat']->handleFileData(['offer_id' => $offerId, 'data' => $b64], $alice, $s['worker']);
    $dataPkt = packetOfType($bob, 'file_data');
    ok('recipient got file_data', $dataPkt !== null && ($dataPkt['data'] ?? '') === $b64);
    ok('lock released after relay', transferIdle($s['worker'], $roomId));
}

// ── 5. Reject → declined packet + lock release ──────────────────────────────
{
    $s = makeStack();
    [$alice, $bob, $roomId] = seatTwoInPasswordRoom($s['lobby'], $s['worker']);
    $s['chat']->handleFileOffer([
        'to_username' => 'bob',
        'filename' => 'a.bin',
        'size_bytes' => 4,
    ], $alice, $s['worker']);
    $offerId = (string) (packetOfType($bob, 'file_offer')['offer_id'] ?? '');
    $alice->clearSent();
    $s['chat']->handleFileReject(['offer_id' => $offerId], $bob, $s['worker']);
    $rej = packetOfType($alice, 'file_rejected');
    ok('sender got file_rejected', $rej !== null);
    ok('reject reason declined', ($rej['reason'] ?? '') === 'declined');
    ok('lock released after reject', transferIdle($s['worker'], $roomId));
}

// ── 6. Offer timeout releases lock ──────────────────────────────────────────
{
    $s = makeStack();
    [$alice, $bob, $roomId] = seatTwoInPasswordRoom($s['lobby'], $s['worker']);
    $s['chat']->handleFileOffer([
        'to_username' => 'bob',
        'filename' => 't.bin',
        'size_bytes' => 2,
    ], $alice, $s['worker']);
    $offerId = (string) (packetOfType($bob, 'file_offer')['offer_id'] ?? '');
    $timerId = (int) ($s['worker']->rooms[$roomId]['file_transfer']['timer_id'] ?? 0);
    $alice->clearSent();
    $bob->clearSent();
    ok('offer timer armed', $timerId > 0 && isset(MockTimer::$active[$timerId]));
    MockTimer::fire($timerId);
    ok('timeout expired packet to sender', (packetOfType($alice, 'file_offer_expired')['offer_id'] ?? '') === $offerId);
    ok('timeout expired packet to recipient', (packetOfType($bob, 'file_offer_expired')['offer_id'] ?? '') === $offerId);
    ok('lock released after timeout', transferIdle($s['worker'], $roomId));
}

// ── 7. Second player blocked while transfer pending ─────────────────────────
{
    $s = makeStack();
    [$alice, $bob, $roomId] = seatTwoInPasswordRoom($s['lobby'], $s['worker']);
    $carol = new MockConnection(3, 'carol');
    $s['lobby']->handleJoinRoom([
        'room_id' => $roomId,
        'password' => 'secret',
        'cards_count' => 1,
    ], $carol, $s['worker']);
    $carol->clearSent();

    $s['chat']->handleFileOffer([
        'to_username' => 'bob',
        'filename' => 'a.bin',
        'size_bytes' => 1,
    ], $alice, $s['worker']);

    $s['chat']->handleFileOffer([
        'to_username' => 'alice',
        'filename' => 'b.bin',
        'size_bytes' => 1,
    ], $carol, $s['worker']);
    ok('busy lock for third player', errorCode($carol) === 'error.file_transfer_busy');
}

// ── 8. Sender disconnect mid-offer ──────────────────────────────────────────
{
    $s = makeStack();
    [$alice, $bob, $roomId] = seatTwoInPasswordRoom($s['lobby'], $s['worker']);
    $s['chat']->handleFileOffer([
        'to_username' => 'bob',
        'filename' => 'a.bin',
        'size_bytes' => 1,
    ], $alice, $s['worker']);
    $offerId = (string) (packetOfType($bob, 'file_offer')['offer_id'] ?? '');
    $bob->clearSent();
    $s['ft']->releaseForConn($s['worker'], (int) $alice->id);
    ok('sender disconnect releases lock', transferIdle($s['worker'], $roomId));
    ok('recipient notified on sender disconnect', (packetOfType($bob, 'file_offer_expired')['offer_id'] ?? '') === $offerId);
}

// ── 9. Recipient disconnect mid-offer ───────────────────────────────────────
{
    $s = makeStack();
    [$alice, $bob, $roomId] = seatTwoInPasswordRoom($s['lobby'], $s['worker']);
    $s['chat']->handleFileOffer([
        'to_username' => 'bob',
        'filename' => 'a.bin',
        'size_bytes' => 1,
    ], $alice, $s['worker']);
    $offerId = (string) (packetOfType($bob, 'file_offer')['offer_id'] ?? '');
    $alice->clearSent();
    $s['ft']->releaseForConn($s['worker'], (int) $bob->id);
    ok('recipient disconnect releases lock', transferIdle($s['worker'], $roomId));
    ok('sender notified on recipient disconnect', (packetOfType($alice, 'file_offer_expired')['offer_id'] ?? '') === $offerId);
}

// ── 10. Oversized via decoded-byte check (disguised encoding) ────────────────
{
    $s = makeStack();
    [$alice, $bob, $roomId] = seatTwoInPasswordRoom($s['lobby'], $s['worker']);

    // Offer claims small size, then send larger decoded payload.
    $s['chat']->handleFileOffer([
        'to_username' => 'bob',
        'filename' => 'big.bin',
        'size_bytes' => 4,
    ], $alice, $s['worker']);
    $offerId = (string) (packetOfType($bob, 'file_offer')['offer_id'] ?? '');
    $s['chat']->handleFileAccept(['offer_id' => $offerId], $bob, $s['worker']);
    $alice->clearSent();
    $big = str_repeat('A', 32);
    $s['chat']->handleFileData([
        'offer_id' => $offerId,
        'data' => base64_encode($big),
    ], $alice, $s['worker']);
    ok('decoded size mismatch rejected', errorCode($alice) === 'error.file_invalid_payload');
    ok('lock released after bad payload', transferIdle($s['worker'], $roomId));

    // Offer with declared size > FILE_MAX_BYTES
    $alice->clearSent();
    $s['chat']->handleFileOffer([
        'to_username' => 'bob',
        'filename' => 'huge.bin',
        'size_bytes' => Constants::FILE_MAX_BYTES + 1,
    ], $alice, $s['worker']);
    ok('declared oversize rejected', errorCode($alice) === 'error.file_too_large');
}

// ── 11. Rate limit on rapid offer spam ──────────────────────────────────────
{
    $s = makeStack();
    [$alice, $bob] = seatTwoInPasswordRoom($s['lobby'], $s['worker']);
    $alice->fileActionCount = 0;
    $alice->fileActionWindowStart = time();

    $limited = false;
    for ($i = 0; $i < Constants::FILE_RATE_LIMIT_MAX + 2; $i++) {
        // Accept/reject between offers to free the lock without consuming rate slots.
        if (!empty($s['worker']->rooms[array_key_first($s['worker']->rooms)]['file_transfer'])) {
            $roomId = (int) array_key_first($s['worker']->rooms);
            $oid = (string) $s['worker']->rooms[$roomId]['file_transfer']['offer_id'];
            $s['chat']->handleFileReject(['offer_id' => $oid], $bob, $s['worker']);
        }
        $alice->clearSent();
        $s['chat']->handleFileOffer([
            'to_username' => 'bob',
            'filename' => "f{$i}.bin",
            'size_bytes' => 1,
        ], $alice, $s['worker']);
        if (errorCode($alice) === 'error.file_rate_limited') {
            $limited = true;
            break;
        }
    }
    ok('file offer rate limit enforced', $limited);
}

// ── 12. Chat stays available while file lock held ───────────────────────────
{
    $s = makeStack();
    [$alice, $bob] = seatTwoInPasswordRoom($s['lobby'], $s['worker']);
    $s['chat']->handleFileOffer([
        'to_username' => 'bob',
        'filename' => 'a.bin',
        'size_bytes' => 1,
    ], $alice, $s['worker']);
    $alice->clearSent();
    $bob->clearSent();
    $s['chat']->handleRoomMessage(['text' => 'still works'], $bob, $s['worker']);
    ok('chat unlocked during file lock', (packetOfType($alice, 'room_message')['text'] ?? '') === 'still works');
}

summary();
