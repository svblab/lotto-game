<?php

declare(strict_types=1);

namespace Lotto\Core;

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Websocket;
use Workerman\Worker;

use function Lotto\Core\lottoRuntimeEnv;

/**
 * Docker V1 front door: upgrades /ws to WebSocket on an HTTP worker (single port).
 */
final class ContainerFrontDoor
{
    public static function webSocketPath(): string
    {
        $configured = lottoRuntimeEnv('LOTTO_WS_PATH');
        if ($configured === null || trim($configured) === '') {
            return '/ws';
        }
        $path = '/' . trim($configured, '/');
        return $path === '/' ? '/ws' : $path;
    }

    public static function isWebSocketUpgrade(Request $request): bool
    {
        $upgrade = strtolower((string) ($request->header('upgrade') ?? ''));
        $connection = strtolower((string) ($request->header('connection') ?? ''));
        return $upgrade === 'websocket' && str_contains($connection, 'upgrade');
    }

    /**
     * Complete RFC6455 handshake on an HTTP worker connection and switch to Websocket protocol.
     */
    public static function tryWebSocketUpgrade(TcpConnection $connection, Request $request, Worker $worker): bool
    {
        if (!self::isWebSocketUpgrade($request)) {
            return false;
        }

        if ($request->path() !== self::webSocketPath()) {
            $connection->send(new \Workerman\Protocols\Http\Response(404, [], 'Not Found'));
            return true;
        }

        $version = (string) ($request->header('sec-websocket-version') ?? '');
        if ($version !== '13') {
            $connection->send(
                "HTTP/1.1 426 Upgrade Required\r\n"
                . "Connection: Upgrade\r\n"
                . "Upgrade: WebSocket\r\n"
                . "Sec-WebSocket-Version: 13\r\n\r\n",
                true
            );
            $connection->close();
            return true;
        }

        $secKey = (string) ($request->header('sec-websocket-key') ?? '');
        if ($secKey === '') {
            $connection->send(new \Workerman\Protocols\Http\Response(400, [], 'Bad Request'));
            $connection->close();
            return true;
        }

        $accept = base64_encode(sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $handshake = "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: {$accept}\r\n\r\n";

        Websocket::initContext($connection);
        $connection->protocol = Websocket::class;
        $connection->websocketType = Websocket::BINARY_TYPE_BLOB;
        $connection->context->websocketHandshake = true;
        $connection->worker = $worker;

        $connection->send($handshake, true);

        $onConnect = $connection->onWebSocketConnect ?? $worker->onWebSocketConnect ?? null;
        if (is_callable($onConnect)) {
            $onConnect($connection, $request);
        }

        $onConnected = $connection->onWebSocketConnected ?? $worker->onWebSocketConnected ?? null;
        if (is_callable($onConnected)) {
            $onConnected($connection, $request);
        }

        return true;
    }
}
