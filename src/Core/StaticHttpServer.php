<?php

declare(strict_types=1);

namespace Lotto\Core;

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

/**
 * Serves the browser SPA and static assets from a directory inside the container.
 * Enabled only when LOTTO_HTTP_PUBLIC is set (Docker V1 container-native HTTP).
 */
final class StaticHttpServer
{
    public static function serve(TcpConnection $connection, Request $request, string $publicRoot): void
    {
        $method = strtoupper($request->method());
        if ($method !== 'GET' && $method !== 'HEAD') {
            $connection->send(new Response(405, [], 'Method Not Allowed'));
            return;
        }

        $publicRoot = rtrim($publicRoot, '/\\');
        if ($publicRoot === '' || !is_dir($publicRoot)) {
            $connection->send(new Response(503, [], 'Service Unavailable'));
            return;
        }

        $path = $request->path();
        if ($path === '' || $path === '/') {
            $path = '/index.html';
        }

        $file = self::resolveSafeFile($publicRoot, $path);
        if ($file === null || !is_file($file)) {
            $fallback = self::resolveSafeFile($publicRoot, '/index.html');
            if ($fallback === null || !is_file($fallback)) {
                $connection->send(new Response(404, [], 'Not Found'));
                return;
            }
            $file = $fallback;
        }

        if ($method === 'HEAD') {
            $response = new Response(200);
            $response->withFile($file);
            $connection->send($response);
            return;
        }

        $connection->send((new Response())->withFile($file));
    }

    private static function resolveSafeFile(string $publicRoot, string $uriPath): ?string
    {
        $relative = rawurldecode($uriPath);
        $relative = str_replace('\\', '/', $relative);
        if (!str_starts_with($relative, '/')) {
            $relative = '/' . $relative;
        }

        $candidate = $publicRoot . $relative;
        $realPublic = realpath($publicRoot);
        $realFile = realpath($candidate);
        if ($realPublic === false || $realFile === false) {
            return null;
        }
        if (!str_starts_with($realFile, $realPublic . DIRECTORY_SEPARATOR)
            && $realFile !== $realPublic) {
            return null;
        }

        return $realFile;
    }
}
