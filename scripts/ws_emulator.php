<?php

declare(strict_types=1);

/**
 * scripts/ws_emulator.php
 *
 * EPIC-11.5 — WebSocket client emulator for protocol audit and replay.
 *
 * Usage:
 *   php scripts/ws_emulator.php --host 127.0.0.1 --port 8080 --send '{"action":"ping"}'
 *   php scripts/ws_emulator.php --host 127.0.0.1 --port 8080 --replay session.jsonl
 *   php scripts/ws_emulator.php --host 127.0.0.1 --port 8080 --interactive
 *
 * session.jsonl format (one JSON object per line):
 *   {"send": {"action": "register", "username": "u1", "password": "pass"}}
 *   {"expect": {"type": "auth_result", "success": true}}
 *   {"send": {"action": "room_list"}}
 *   {"recv": true}
 *
 * Lines with "send" transmit a packet; "recv" waits for one response;
 * "expect" receives and checks that all listed keys match (subset match).
 */

final class WsEmulatorClient
{
    private $sock;

    public function __construct(private readonly string $host, private readonly int $port)
    {
        $this->sock = @fsockopen($host, $port, $errno, $errstr, 5.0);
        if (!$this->sock) {
            throw new RuntimeException("connect failed: {$errstr} (errno={$errno})");
        }

        $key = base64_encode(random_bytes(16));
        $req = "GET / HTTP/1.1\r\nHost: {$host}:{$port}\r\nUpgrade: websocket\r\n" .
               "Connection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n";
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
            throw new RuntimeException("WS handshake failed: {$resp}");
        }
    }

    public function send(array $payload): void
    {
        $msg = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo ">>> {$msg}\n";
        $this->sendRaw($msg);
    }

    public function recv(float $timeout = 3.0): ?array
    {
        $raw = $this->recvRaw($timeout);
        if ($raw === null) {
            echo "<<< (timeout)\n";
            return null;
        }
        echo "<<< {$raw}\n";
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    public function expect(array $expected, float $timeout = 3.0): bool
    {
        $data = $this->recv($timeout);
        if ($data === null) {
            fwrite(STDERR, "EXPECT FAIL: no response\n");
            return false;
        }
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $data) || $data[$key] !== $value) {
                fwrite(STDERR, "EXPECT FAIL: key '{$key}' expected " . json_encode($value) .
                    ", got " . json_encode($data[$key] ?? null) . "\n");
                return false;
            }
        }
        echo "    (expect OK)\n";
        return true;
    }

    public function close(): void
    {
        if (is_resource($this->sock)) {
            fclose($this->sock);
        }
    }

    private function sendRaw(string $msg): void
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

    private function recvRaw(float $timeout): ?string
    {
        stream_set_timeout($this->sock, (int)$timeout, (int)(($timeout - (int)$timeout) * 1000000));
        $hdr = fread($this->sock, 2);
        if ($hdr === false || strlen($hdr) < 2) {
            return null;
        }
        $opcode = ord($hdr[0]) & 0x0F;
        if ($opcode === 0x8) {
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
}

function printUsage(): void
{
    fwrite(STDERR, <<<USAGE
WebSocket client emulator (EPIC-11.5)

Options:
  --host HOST       Server host (default: 127.0.0.1)
  --port PORT       Server port (default: 8080)
  --send JSON       Send one packet and print response
  --replay FILE     Replay a .jsonl session file
  --interactive     Read JSON lines from stdin (send each, print response)
  --skip-hello      Do not auto-receive hello on connect
  --help            Show this help

USAGE);
}

$options = getopt('', ['host:', 'port:', 'send:', 'replay:', 'interactive', 'skip-hello', 'help']);

if (isset($options['help'])) {
    printUsage();
    exit(0);
}

$host = $options['host'] ?? '127.0.0.1';
$port = (int)($options['port'] ?? 8080);
$skipHello = isset($options['skip-hello']);

try {
    $client = new WsEmulatorClient($host, $port);

    if (!$skipHello) {
        $client->recv();
    }

    if (isset($options['send'])) {
        $payload = json_decode($options['send'], true);
        if (!is_array($payload)) {
            throw new InvalidArgumentException('--send must be valid JSON object');
        }
        $client->send($payload);
        $client->recv();
    } elseif (isset($options['replay'])) {
        $file = $options['replay'];
        if (!is_readable($file)) {
            throw new InvalidArgumentException("Cannot read replay file: {$file}");
        }
        $lineNo = 0;
        $failures = 0;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $lineNo++;
            $step = json_decode($line, true);
            if (!is_array($step)) {
                fwrite(STDERR, "Line {$lineNo}: invalid JSON\n");
                $failures++;
                continue;
            }
            if (isset($step['send']) && is_array($step['send'])) {
                $client->send($step['send']);
            } elseif (isset($step['recv'])) {
                $client->recv();
            } elseif (isset($step['expect']) && is_array($step['expect'])) {
                if (!$client->expect($step['expect'])) {
                    $failures++;
                }
            } else {
                fwrite(STDERR, "Line {$lineNo}: unknown step (need send, recv, or expect)\n");
                $failures++;
            }
        }
        $client->close();
        exit($failures > 0 ? 1 : 0);
    } elseif (isset($options['interactive'])) {
        echo "Interactive mode — enter JSON packets (Ctrl+D to quit):\n";
        while (($line = fgets(STDIN)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $payload = json_decode($line, true);
            if (!is_array($payload)) {
                fwrite(STDERR, "Invalid JSON, skipped\n");
                continue;
            }
            $client->send($payload);
            $client->recv();
        }
    } else {
        printUsage();
        exit(1);
    }

    $client->close();
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
