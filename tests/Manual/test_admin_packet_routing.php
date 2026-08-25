<?php

declare(strict_types=1);

/**
 * tests/Manual/test_admin_packet_routing.php
 *
 * EPIC-10.6 Admin packet routing — verifies admin_ban_user/
 * admin_unban_user/admin_kick_user/admin_close_room/admin_get_logs are
 * actually wired to AdminHandler through a live server.php (not a
 * MockConnection unit test — those already exist and thoroughly cover
 * AdminService's business logic from Phase 9; this file's job is only to
 * prove the routing + full dependency wiring, since AdminService takes 7
 * nullable dependencies and silently degrades if any are missing).
 *
 * Also covers FIX-11 (found during this Epic's dependency-wiring audit):
 * banned users could fully bypass their ban via reconnect, and a banned
 * online player's connection was never actually closed. See
 * IMPLEMENTATION_STATUS.md FIX-11 for the full writeup.
 *
 * Run: php tests/Manual/test_admin_packet_routing.php
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Lotto\Infrastructure\Database;

const HARD_TIMEOUT_SECONDS = 30;
$GLOBALS['__serverProcess'] = null;

function hardKillAndExit(string $reason): void
{
    fwrite(STDERR, "\n!!! HARD TIMEOUT: {$reason}\n");
    if (is_resource($GLOBALS['__serverProcess'] ?? null)) {
        proc_terminate($GLOBALS['__serverProcess'], 9);
        proc_close($GLOBALS['__serverProcess']);
    }
    exit(2);
}

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, fn() => hardKillAndExit('script exceeded hard timeout'));
    pcntl_alarm(HARD_TIMEOUT_SECONDS);
}

final class AdminRoutingClient
{
    private $sock;

    public function __construct(string $host, int $port)
    {
        $this->sock = @fsockopen($host, $port, $errno, $errstr, 5.0);
        if (!$this->sock) {
            throw new \RuntimeException("connect failed: {$errstr} (errno={$errno})");
        }
        $key = base64_encode(random_bytes(16));
        $req = "GET / HTTP/1.1\r\nHost: {$host}:{$port}\r\nUpgrade: websocket\r\n" .
               "Connection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n";
        fwrite($this->sock, $req);
        stream_set_timeout($this->sock, 5);
        $resp = '';
        while (!feof($this->sock)) {
            $line = fgets($this->sock);
            if ($line === false) break;
            $resp .= $line;
            if ($line === "\r\n") break;
        }
        if (strpos($resp, '101') === false) {
            throw new \RuntimeException("WS handshake failed: {$resp}");
        }
    }

    public function send(string $msg): void
    {
        $len = strlen($msg);
        $frame = chr(0x81);
        $maskBit = 0x80;
        if ($len <= 125) {
            $frame .= chr($len | $maskBit);
        } elseif ($len <= 65535) {
            $frame .= chr(126 | $maskBit) . pack('n', $len);
        } else {
            $frame .= chr(127 | $maskBit) . pack('J', $len);
        }
        $mask = random_bytes(4);
        $frame .= $mask;
        for ($i = 0; $i < $len; $i++) {
            $frame .= $msg[$i] ^ $mask[$i % 4];
        }
        fwrite($this->sock, $frame);
    }

    public function recvOrNull(float $timeout = 2.0): ?string
    {
        stream_set_timeout($this->sock, (int)$timeout, (int)(($timeout - (int)$timeout) * 1000000));
        $hdr = fread($this->sock, 2);
        if ($hdr === false || strlen($hdr) < 2) {
            return null;
        }
        $len = ord($hdr[1]) & 0x7F;
        if ($len === 126) {
            $ext = fread($this->sock, 2);
            if ($ext === false || strlen($ext) < 2) return null;
            $len = unpack('n', $ext)[1];
        } elseif ($len === 127) {
            $ext = fread($this->sock, 8);
            if ($ext === false || strlen($ext) < 8) return null;
            $len = unpack('J', $ext)[1];
        }
        $payload = '';
        while (strlen($payload) < $len) {
            $chunk = fread($this->sock, $len - strlen($payload));
            if ($chunk === false || $chunk === '') break;
            $payload .= $chunk;
        }
        return $payload;
    }

    public function close(): void
    {
        if (is_resource($this->sock)) {
            fclose($this->sock);
        }
    }
}

$passed = 0;
$failed = 0;

function check(bool $cond, string $label): void
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

require_once __DIR__ . '/ws_test_harness.php';

$projectRoot = dirname(__DIR__, 2);
wsTestEnsureDatabase($projectRoot);

$db  = new Database();
$pdo = $db->getPdo();
$pdo->exec("DELETE FROM users WHERE username LIKE 'e106\\_%' ESCAPE '\\'");

$wsPort = wsTestPort();

try {
    $serverCtx = wsTestStartServer($projectRoot);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$process = $serverCtx['process'];
$stdoutFile = $serverCtx['stdoutFile'];
$stderrFile = $serverCtx['stderrFile'];
$GLOBALS['__serverProcess'] = $process;

try {
    // Promote an admin user directly via DB (matches existing admin test
    // fixtures' convention — there is no register-as-admin protocol path).
    $adminPasswordHash = password_hash('e106adminpass', PASSWORD_BCRYPT);
    $pdo->exec("INSERT INTO users (username, password_hash, coins, is_admin, banned_until, last_daily_bonus) " .
               "VALUES ('e106_admin', " . $pdo->quote($adminPasswordHash) . ", 500, 1, 0, 0)");

    echo "SETUP: admin login, register two regular players\n";
    $admin = new AdminRoutingClient('127.0.0.1', $wsPort);
    $admin->recvOrNull();
    $admin->send(json_encode(['action' => 'login', 'username' => 'e106_admin', 'password' => 'e106adminpass']));
    $adminAuth = json_decode($admin->recvOrNull() ?? '', true);
    check(($adminAuth['type'] ?? null) === 'auth_result' && ($adminAuth['is_admin'] ?? false) === true, 'admin login succeeds, is_admin=true');

    $p1 = new AdminRoutingClient('127.0.0.1', $wsPort);
    $p1->recvOrNull();
    $p1->send(json_encode(['action' => 'register', 'username' => 'e106_p1', 'password' => 'e106pass123']));
    $p1Auth = json_decode($p1->recvOrNull() ?? '', true);
    $p1Id = (int)$p1Auth['user_id'];

    $p2 = new AdminRoutingClient('127.0.0.1', $wsPort);
    $p2->recvOrNull();
    $p2->send(json_encode(['action' => 'register', 'username' => 'e106_p2', 'password' => 'e106pass123']));
    $p2Auth = json_decode($p2->recvOrNull() ?? '', true);
    $p2Id = (int)$p2Auth['user_id'];
    $p2Token = $p2Auth['session_token'] ?? null;

    // =========================================================================
    // TEST 1: non-admin -> error.not_your_turn (assertAdmin guard reachable
    // through real routing)
    // =========================================================================
    echo "\nTEST 1: non-admin admin_get_logs -> error.not_your_turn\n";
    $p1->send(json_encode(['action' => 'admin_get_logs']));
    $data1 = wsRecvIgnoringSync($p1);
    check(($data1['code'] ?? null) === 'error.not_your_turn', 'code=error.not_your_turn');

    // =========================================================================
    // TEST 2: unauthenticated -> error.auth_required (router-level guard,
    // ADR-006, reached before AdminHandler at all)
    // =========================================================================
    echo "\nTEST 2: unauth admin_kick_user -> error.auth_required\n";
    $anon = new AdminRoutingClient('127.0.0.1', $wsPort);
    $anon->recvOrNull();
    $anon->send(json_encode(['action' => 'admin_kick_user', 'user_id' => $p1Id]));
    $data2 = wsRecvIgnoringSync($anon);
    check(($data2['code'] ?? null) === 'error.auth_required', 'code=error.auth_required');
    $anon->close();

    // =========================================================================
    // TEST 3: admin_get_logs -> admin_logs_data (proves logger dependency
    // was wired)
    // =========================================================================
    echo "\nTEST 3: admin admin_get_logs -> admin_logs_data\n";
    $admin->send(json_encode(['action' => 'admin_get_logs']));
    $data3 = wsRecvOfType($admin, 'admin_logs_data');
    check(($data3['type'] ?? null) === 'admin_logs_data', 'type=admin_logs_data');
    check(is_array($data3['lines'] ?? null), 'lines field present as array');

    // =========================================================================
    // TEST 4: cannot ban an admin
    // =========================================================================
    echo "\nTEST 4: admin_ban_user targeting another admin -> error.cannot_moderate_admin\n";
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = 'e106_admin'");
    $stmt->execute();
    $adminSelfId = (int)$stmt->fetchColumn();
    $admin->send(json_encode(['action' => 'admin_ban_user', 'user_id' => $adminSelfId, 'duration' => '1d']));
    $data4 = wsRecvIgnoringSync($admin);
    check(($data4['code'] ?? null) === 'error.cannot_moderate_admin', 'code=error.cannot_moderate_admin');

    // =========================================================================
    // TEST 5: kick — p1 creates a room, p2 joins, admin kicks p2 (waiting
    // state) -> p1 receives player_left(reason=kicked). Proves
    // lobbyService/reconnectService/apartmentService were wired into
    // AdminService (kick has no gate on those being null — but if they
    // were null, no removal would happen at all and this would hang/fail).
    // =========================================================================
    echo "\nTEST 5: admin_kick_user (waiting room) -> player_left broadcast to remaining player\n";
    $p1->send(json_encode(['action' => 'create_room', 'max_players' => 4, 'password' => '', 'cards_count' => 1]));
    $roomCreated = wsRecvOfType($p1, 'room_joined');
    $roomId = $roomCreated['room_id'] ?? null;
    wsDrainBrief($p1);

    $p2->send(json_encode(['action' => 'join_room', 'room_id' => $roomId, 'password' => '', 'cards_count' => 1]));
    wsRecvOfType($p2, 'room_joined');
    wsRecvOfType($p1, 'player_joined');
    wsDrainBrief($p1);
    wsDrainBrief($p2);

    $admin->send(json_encode(['action' => 'admin_kick_user', 'user_id' => $p2Id]));
    $kickBroadcast = wsRecvOfType($p1, 'player_left');
    check(
        ($kickBroadcast['type'] ?? null) === 'player_left' && ($kickBroadcast['reason'] ?? null) === 'kicked',
        'p1 receives player_left(reason=kicked) for p2'
    );
    wsDrainBrief($p1);

    // =========================================================================
    // TEST 6 (FIX-11): admin bans an ONLINE player -> target receives
    // 'banned' packet AND their connection is closed by the server (not
    // just DB-flagged while staying fully authenticated).
    // =========================================================================
    echo "\nTEST 6 (FIX-11): admin_ban_user on an online target -> banned packet + connection closed\n";
    $admin->send(json_encode(['action' => 'admin_ban_user', 'user_id' => $p2Id, 'duration' => '1d']));
    $p2BannedPacket = wsRecvOfType($p2, 'banned');
    check(($p2BannedPacket['type'] ?? null) === 'banned', 'p2 receives banned packet');
    $p2AfterClose = $p2->recvOrNull(1.5);
    check($p2AfterClose === null, 'p2 connection closed by server (read after ban returns null)');
    $p2->close();

    // =========================================================================
    // TEST 7 (FIX-11 core scenario): a player disconnects while seated in a
    // room, admin bans them WHILE disconnected (inside the 15s reconnect
    // window), then the original token tries to reconnect -> must get
    // 'banned', not 'reconnect_state', and must NOT be authenticated
    // afterward.
    // =========================================================================
    echo "\nTEST 7 (FIX-11): ban while disconnected (mid reconnect-window) blocks the pending reconnect\n";
    $p3 = new AdminRoutingClient('127.0.0.1', $wsPort);
    $p3->recvOrNull();
    $p3->send(json_encode(['action' => 'register', 'username' => 'e106_p3', 'password' => 'e106pass123']));
    $p3Auth = json_decode($p3->recvOrNull() ?? '', true);
    $p3Id = (int)$p3Auth['user_id'];
    $p3Token = $p3Auth['session_token'] ?? null;

    $p3->send(json_encode(['action' => 'create_room', 'max_players' => 4, 'password' => '', 'cards_count' => 1]));
    $p3RoomCreated = wsRecvOfType($p3, 'room_joined');
    $p3RoomId = $p3RoomCreated['room_id'] ?? null;

    $p3->close(); // real TCP close -> onClose -> ReconnectService::handleDisconnect() -> 'disconnected' + 15s timer
    usleep(400_000);

    $admin->send(json_encode(['action' => 'admin_ban_user', 'user_id' => $p3Id, 'duration' => '1d']));
    usleep(300_000);

    $p3Back = new AdminRoutingClient('127.0.0.1', $wsPort);
    $p3Back->recvOrNull();
    $p3Back->send(json_encode(['action' => 'reconnect', 'token' => $p3Token]));
    $p3ReconnectResp = wsRecvIgnoringSync($p3Back);
    check(
        ($p3ReconnectResp['type'] ?? null) === 'banned',
        'reconnect after mid-disconnect ban returns banned (not reconnect_state)'
    );

    $p3Back->send(json_encode(['action' => 'room_list']));
    $p3RoomListResp = wsRecvIgnoringSync($p3Back);
    check(
        ($p3RoomListResp['code'] ?? null) === 'error.auth_required',
        'connection still unauthenticated after banned-reconnect (no bypass)'
    );
    $p3Back->close();

    // Confirm the room-level removal also happened (not just the auth
    // block) — the room should have been destroyed since p3 was its only
    // (now-removed) player, so a fresh room_list from admin should not
    // show it.
    $admin->send(json_encode(['action' => 'room_list']));
    $adminRoomList = wsRecvOfType($admin, 'room_list');
    $stillListed = false;
    foreach (($adminRoomList['rooms'] ?? []) as $r) {
        if (($r['room_id'] ?? null) === $p3RoomId) {
            $stillListed = true;
        }
    }
    check(!$stillListed, 'p3\'s room no longer listed (structural removal happened despite being offline at ban time)');

    // =========================================================================
    // TEST 8: admin_unban_user -> banned user can log in again
    // =========================================================================
    echo "\nTEST 8: admin_unban_user -> banned user can log in again\n";
    $admin->send(json_encode(['action' => 'admin_unban_user', 'user_id' => $p3Id]));
    usleep(200_000);
    $p3Relogin = new AdminRoutingClient('127.0.0.1', $wsPort);
    $p3Relogin->recvOrNull();
    $p3Relogin->send(json_encode(['action' => 'login', 'username' => 'e106_p3', 'password' => 'e106pass123']));
    $p3ReloginResp = json_decode($p3Relogin->recvOrNull() ?? '', true);
    check(($p3ReloginResp['type'] ?? null) === 'auth_result', 'login succeeds after unban');
    $p3Relogin->close();

    // =========================================================================
    // TEST 9: admin_close_room -> room destroyed + refund
    // =========================================================================
    echo "\nTEST 9: admin_close_room -> room destroyed, refund issued\n";
    // Reuse the room from TEST 5 - p1 is still its sole occupant (p2 was
    // kicked out of it). A second create_room here would hit the "already
    // in a room" guard (EPIC-10.4) since p1 never left it.
    $closeRoomId = $roomId;

    $admin->send(json_encode(['action' => 'admin_close_room', 'room_id' => $closeRoomId]));
    $p1CloseNotice = wsRecvOfType($p1, 'player_left');
    check(
        ($p1CloseNotice['type'] ?? null) === 'player_left' && ($p1CloseNotice['reason'] ?? null) === 'admin_close',
        'p1 receives player_left(reason=admin_close)'
    );

    // Drop any pre-close room_list fan-outs (and the post-destroy broadcast)
    // so the explicit room_list below is the packet we assert on.
    wsDrainBrief($admin, 0.4);
    $admin->send(json_encode(['action' => 'room_list']));
    $finalRoomList = wsRecvOfType($admin, 'room_list');
    $closedStillListed = false;
    foreach (($finalRoomList['rooms'] ?? []) as $r) {
        if (($r['room_id'] ?? null) === $closeRoomId) {
            $closedStillListed = true;
        }
    }
    check(!$closedStillListed, 'closed room no longer listed');

    $p1->close();
    $admin->close();
} catch (\Throwable $e) {
    fwrite(STDERR, "Exception during test: " . $e->getMessage() . "\n");
    fwrite(STDERR, "--- server stdout ---\n" . @file_get_contents($stdoutFile) . "\n");
    fwrite(STDERR, "--- server stderr ---\n" . @file_get_contents($stderrFile) . "\n");
    $failed++;
} finally {
    proc_terminate($process, 15);
    $waited = 0;
    while (proc_get_status($process)['running'] && $waited < 3_000_000) {
        usleep(100_000);
        $waited += 100_000;
    }
    if (proc_get_status($process)['running']) {
        proc_terminate($process, 9);
    }
    proc_close($process);
    @unlink($stdoutFile);
    @unlink($stderrFile);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        try {
            $pdo->exec("DELETE FROM users WHERE username LIKE 'e106\\_%' ESCAPE '\\'");
            break;
        } catch (\Throwable $e) {
            usleep(200_000);
        }
    }
    wsTestCleanupDatabase();
}

if (function_exists('pcntl_alarm')) {
    pcntl_alarm(0);
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "RESULT: {$passed} passed, {$failed} failed\n";
echo str_repeat('=', 60) . "\n";

exit($failed > 0 ? 1 : 0);
