<?php

declare(strict_types=1);

/**
 * Manual WS probe for admin_get_users.
 * Run: php tests/Manual/ws_admin_users_probe.php
 */

require __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Helpers.php';

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Worker;

$username = 'admin';
$password = 'testpass123';
$token = null;
$step = 'connect';

Worker::$logFile = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';

$worker = new Worker();
$worker->onWorkerStart = function () use (&$token, &$step, $username, $password): void {
    $conn = new AsyncTcpConnection('ws://127.0.0.1:8080');
    $conn->onConnect = function () use ($conn): void {
        echo "connected\n";
    };
    $conn->onMessage = function ($_, $data) use (&$token, &$step, $conn, $username, $password): void {
        $pkt = json_decode($data, true);
        $type = $pkt['type'] ?? '';
        echo "RECV {$type}: " . substr($data, 0, 300) . "\n";

        if ($step === 'connect' && $type === 'hello') {
            $step = 'login';
            $conn->send(json_encode(['action' => 'login', 'username' => $username, 'password' => $password]));
            return;
        }
        if ($step === 'login' && $type === 'auth_result') {
            $token = $pkt['session_token'] ?? null;
            $step = 'users';
            $conn->send(json_encode([
                'action' => 'admin_get_users',
                'search' => '',
                'online_only' => false,
                'banned_only' => false,
            ]));
            return;
        }
        if ($step === 'users' && $type === 'admin_users_data') {
            $count = is_array($pkt['users'] ?? null) ? count($pkt['users']) : -1;
            echo "USERS_COUNT={$count}\n";
            Worker::stopAll();
            return;
        }
        if ($type === 'error') {
            echo "ERROR: " . ($pkt['code'] ?? '') . ' ' . ($pkt['message'] ?? '') . "\n";
            Worker::stopAll();
        }
    };
    $conn->connect();
};

Worker::runAll();
