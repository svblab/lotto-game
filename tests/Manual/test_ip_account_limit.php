<?php

declare(strict_types=1);

/**
 * EPIC-031c / ADR-031 Part B — IP account limit + trusted-proxy resolution.
 *
 * Run: php tests/Manual/test_ip_account_limit.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/ws_test_harness.php';

use Lotto\Auth\IpAccountLimitService;
use Lotto\Core\Constants;
use Lotto\Core\Logger;
use Lotto\Infrastructure\Database;

$passed = 0;
$failed = 0;

function ipCheck(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "[PASS] $label\n";
    } else {
        $failed++;
        echo "[FAIL] $label\n";
    }
}

function makeMockConnection(string $peerIp, ?int $id = 1): object
{
    return new class($peerIp, $id) {
        public ?int $id;
        public ?string $clientRemoteIp = null;

        public function __construct(private string $peerIp, int $id)
        {
            $this->id = $id;
        }

        public function getRemoteIp(): string
        {
            return $this->peerIp;
        }
    };
}

function makeMockRequest(?string $xff = null, ?string $realIp = null): object
{
    return new class($xff, $realIp) {
        public function __construct(private ?string $xff, private ?string $realIp) {}

        public function header(string $name): ?string
        {
            $name = strtolower($name);
            if ($name === 'x-forwarded-for') {
                return $this->xff;
            }
            if ($name === 'x-real-ip') {
                return $this->realIp;
            }
            return null;
        }
    };
}

function makeWorker(array $userConnections = []): object
{
    return (object) ['userConnections' => $userConnections];
}

echo "=== Unit: IpAccountLimitService resolve + cap ===\n\n";

$logPath = sys_get_temp_dir() . '/lotto_ip_limit_' . getmypid() . '.log';
$logger = new Logger($logPath);
register_shutdown_function(static fn () => @unlink($logPath));
$service = new IpAccountLimitService($logger);

// (e) trusted proxy + X-Forwarded-For
putenv('LOTTO_TRUSTED_PROXY_IPS=127.0.0.1');
$connTrusted = makeMockConnection('127.0.0.1');
$reqXff = makeMockRequest('203.0.113.1, 10.0.0.5');
$resolved = $service->resolveClientRemoteIp($connTrusted, $reqXff);
ipCheck($resolved === '203.0.113.1', '(e) trusted proxy uses first X-Forwarded-For IP');

// (f) trusted proxy, missing XFF → sentinel
$connTrusted2 = makeMockConnection('127.0.0.1');
$sentinel = $service->resolveClientRemoteIp($connTrusted2, null);
ipCheck(
    $sentinel === IpAccountLimitService::TRUSTED_PROXY_UNRESOLVED_BUCKET,
    '(f) trusted proxy without headers uses sentinel bucket'
);

// (g) untrusted direct peer ignores forged XFF
putenv('LOTTO_TRUSTED_PROXY_IPS=10.0.0.1');
$connDirect = makeMockConnection('192.0.2.50');
$reqForged = makeMockRequest('203.0.113.99');
$directResolved = $service->resolveClientRemoteIp($connDirect, $reqForged);
ipCheck($directResolved === '192.0.2.50', '(g) untrusted peer ignores X-Forwarded-For');

putenv('LOTTO_TRUSTED_PROXY_IPS');

// Cap counting
$bucketIp = '198.51.100.10';
$c1 = makeMockConnection('192.0.2.1', 1);
$c1->clientRemoteIp = $bucketIp;
$c2 = makeMockConnection('192.0.2.2', 2);
$c2->clientRemoteIp = $bucketIp;
$c3 = makeMockConnection('192.0.2.3', 3);
$c3->clientRemoteIp = $bucketIp;
$worker = makeWorker([1 => $c1, 2 => $c2, 3 => $c3]);
$newConn = makeMockConnection('192.0.2.4', 4);
$newConn->clientRemoteIp = $bucketIp;
ipCheck(
    $service->wouldRejectNewAuth($worker, $newConn, 4),
    'fourth distinct user at same client IP bucket is rejected'
);
ipCheck(
    !$service->wouldRejectNewAuth($worker, $newConn, 2),
    're-login same user_id does not increase distinct count'
);
ipCheck(
    Constants::MAX_ACCOUNTS_PER_IP === 3,
    'MAX_ACCOUNTS_PER_IP constant is 3'
);

echo "\n=== Integration: WS login cap + reconnect (c)(d) ===\n\n";

$projectRoot = dirname(__DIR__, 2);
wsTestEnsureDatabase($projectRoot);
$db = new Database();
$pdo = $db->getPdo();
$pdo->exec("DELETE FROM users WHERE username LIKE 'ip031\\_%' ESCAPE '\\'");

$hash = password_hash('ip031pass', PASSWORD_DEFAULT);
$now = time();
$insert = $pdo->prepare(
    'INSERT INTO users (username, password_hash, coins, is_admin, banned_until, last_daily_bonus) VALUES (?, ?, 500, 0, 0, ?)'
);
for ($i = 1; $i <= 4; $i++) {
    $insert->execute(['ip031_u' . $i, $hash, $now]);
}

putenv('LOTTO_TRUSTED_PROXY_IPS=127.0.0.1,::1');

try {
    $serverCtx = wsTestStartServer($projectRoot);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$port = (int) wsTestPort();

/**
 * Minimal WS client with optional handshake headers.
 */
final class IpLimitWsClient
{
    private $sock;

    public function __construct(int $port, array $extraHeaders = [])
    {
        $this->sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 5.0);
        if (!$this->sock) {
            throw new RuntimeException("connect failed: $errstr");
        }
        $key = base64_encode(random_bytes(16));
        $lines = [
            "GET / HTTP/1.1",
            "Host: 127.0.0.1:$port",
            'Upgrade: websocket',
            'Connection: Upgrade',
            "Sec-WebSocket-Key: $key",
            'Sec-WebSocket-Version: 13',
        ];
        foreach ($extraHeaders as $hName => $hVal) {
            $lines[] = "$hName: $hVal";
        }
        $req = implode("\r\n", $lines) . "\r\n\r\n";
        fwrite($this->sock, $req);
        stream_set_timeout($this->sock, 5);
        $resp = '';
        while (!feof($this->sock)) {
            $line = fgets($this->sock);
            if ($line === false) {
                break;
            }
            $resp .= $line;
            if ($line === "\r\n") {
                break;
            }
        }
        if (!str_contains($resp, '101')) {
            throw new RuntimeException('handshake failed');
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

    public function recvJson(float $timeout = 3.0): ?array
    {
        $raw = $this->recvRaw($timeout);
        if ($raw === null) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function recvRaw(float $timeout): ?string
    {
        stream_set_timeout($this->sock, (int) $timeout, (int) (($timeout - (int) $timeout) * 1_000_000));
        $hdr = fread($this->sock, 2);
        if ($hdr === false || strlen($hdr) < 2) {
            return null;
        }
        $b1 = ord($hdr[1]);
        $len = $b1 & 0x7F;
        if ($len === 126) {
            $ext = fread($this->sock, 2);
            if ($ext === false || strlen($ext) < 2) {
                return null;
            }
            $len = unpack('n', $ext)[1];
        } elseif ($len === 127) {
            $ext = fread($this->sock, 8);
            if ($ext === false || strlen($ext) < 8) {
                return null;
            }
            $len = unpack('J', $ext)[1];
        }
        $payload = '';
        while (strlen($payload) < $len) {
            $chunk = fread($this->sock, $len - strlen($payload));
            if ($chunk === false || $chunk === '') {
                break;
            }
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

function wsLogin(int $port, string $username, array $extraHeaders = []): array
{
    $client = new IpLimitWsClient($port, $extraHeaders);
    $hello = $client->recvJson();
    if (($hello['type'] ?? '') !== 'hello') {
        throw new RuntimeException('expected hello');
    }
    $client->send(json_encode([
        'action' => 'login',
        'username' => $username,
        'password' => 'ip031pass',
    ], JSON_THROW_ON_ERROR));
    $pkt = $client->recvJson(5.0);
    return [$client, $pkt ?? []];
}

function wsReconnect(int $port, string $token): array
{
    $client = new IpLimitWsClient($port);
    $hello = $client->recvJson();
    if (($hello['type'] ?? '') !== 'hello') {
        throw new RuntimeException('expected hello');
    }
    $client->send(json_encode(['action' => 'reconnect', 'token' => $token], JSON_THROW_ON_ERROR));
    // May not get immediate packet; poll briefly
    $pkt = $client->recvJson(2.0);
    return [$client, $pkt];
}

$tokens = [];
$clients = [];

try {
  // Same simulated client network (one XFF bucket)
  $sharedXff = '198.51.100.50';
  for ($i = 1; $i <= 3; $i++) {
      [$cli, $pkt] = wsLogin($port, 'ip031_u' . $i, [
          'X-Forwarded-For' => $sharedXff,
      ]);
      ipCheck(($pkt['type'] ?? '') === 'auth_result' && ($pkt['success'] ?? false) === true, "(c) login $i/3 succeeds");
      $tokens[$i] = $pkt['session_token'] ?? '';
      $clients[$i] = $cli;
  }

  [, $pkt4] = wsLogin($port, 'ip031_u4', ['X-Forwarded-For' => $sharedXff]);
  ipCheck(
      ($pkt4['type'] ?? '') === 'error'
      && ($pkt4['code'] ?? '') === 'error.auth_too_many_accounts_same_network',
      '(c) fourth distinct login at same IP bucket rejected'
  );

  // (d) reconnect at cap — not rejected by IP limit
  [, $rePkt] = wsReconnect($port, $tokens[1]);
  $rejected = ($rePkt['type'] ?? '') === 'error'
      && ($rePkt['code'] ?? '') === 'error.auth_too_many_accounts_same_network';
  ipCheck(!$rejected, '(d) reconnect at cap is not rejected by IP limit');
} catch (Throwable $e) {
    ipCheck(false, 'WS integration: ' . $e->getMessage());
}

foreach ($clients as $cli) {
    $cli->close();
}

wsTestStopServer($projectRoot);
putenv('LOTTO_TRUSTED_PROXY_IPS');

echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
