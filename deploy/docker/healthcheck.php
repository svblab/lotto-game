<?php

declare(strict_types=1);

/**
 * Minimal WebSocket healthcheck for Docker HEALTHCHECK.
 * Performs a real RFC6455 handshake and verifies the hello packet.
 */

$portEnv = getenv('LOTTO_WS_PORT');
$port = (is_string($portEnv) && $portEnv !== '') ? (int) $portEnv : 8080;
$host = '127.0.0.1';

try {
    $sock = @fsockopen($host, $port, $errno, $errstr, 3.0);
    if (!$sock) {
        fwrite(STDERR, "healthcheck: connect failed: {$errstr} (errno={$errno})\n");
        exit(1);
    }

    $key = base64_encode(random_bytes(16));
    $request = "GET / HTTP/1.1\r\n"
        . "Host: {$host}:{$port}\r\n"
        . "Upgrade: websocket\r\n"
        . "Connection: Upgrade\r\n"
        . "Sec-WebSocket-Key: {$key}\r\n"
        . "Sec-WebSocket-Version: 13\r\n\r\n";
    fwrite($sock, $request);

    stream_set_timeout($sock, 3);
    $response = '';
    while (!feof($sock)) {
        $line = fgets($sock);
        if ($line === false) {
            break;
        }
        $response .= $line;
        if ($line === "\r\n") {
            break;
        }
    }
    if (strpos($response, '101') === false) {
        fwrite(STDERR, "healthcheck: websocket upgrade failed\n");
        fclose($sock);
        exit(1);
    }

    $hdr = fread($sock, 2);
    if ($hdr === false || strlen($hdr) < 2) {
        fwrite(STDERR, "healthcheck: missing websocket frame\n");
        fclose($sock);
        exit(1);
    }

    $opcode = ord($hdr[0]) & 0x0F;
    if ($opcode !== 0x1) {
        fwrite(STDERR, "healthcheck: expected text frame, got opcode {$opcode}\n");
        fclose($sock);
        exit(1);
    }

    $b1 = ord($hdr[1]);
    $len = $b1 & 0x7F;
    if ($len === 126) {
        $ext = fread($sock, 2);
        if ($ext === false || strlen($ext) < 2) {
            fwrite(STDERR, "healthcheck: invalid extended length\n");
            fclose($sock);
            exit(1);
        }
        $len = unpack('n', $ext)[1];
    } elseif ($len === 127) {
        $ext = fread($sock, 8);
        if ($ext === false || strlen($ext) < 8) {
            fwrite(STDERR, "healthcheck: invalid extended length\n");
            fclose($sock);
            exit(1);
        }
        $len = unpack('J', $ext)[1];
    }

    $payload = '';
    while (strlen($payload) < $len) {
        $chunk = fread($sock, $len - strlen($payload));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $payload .= $chunk;
    }
    fclose($sock);

    $data = json_decode($payload, true);
    if (!is_array($data)) {
        fwrite(STDERR, "healthcheck: hello payload is not JSON\n");
        exit(1);
    }
    if (($data['type'] ?? null) !== 'hello') {
        fwrite(STDERR, "healthcheck: expected type=hello\n");
        exit(1);
    }
    if ((int) ($data['protocol_version'] ?? 0) !== 1) {
        fwrite(STDERR, "healthcheck: unexpected protocol_version\n");
        exit(1);
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'healthcheck: ' . $e->getMessage() . "\n");
    exit(1);
}
