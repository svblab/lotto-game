<?php
// tests/manual/test_helpers_runner.php

// Нам понадобятся логгер из EPIC-0.5 и наши хелперы
require_once __DIR__ . '/../../src/Core/Logger.php';
require_once __DIR__ . '/../../src/Core/Helpers.php';

use Lotto\Core\Logger;
// Импортируем функции из пространства имен Lotto\Core
use function Lotto\Core\sendJson;
use function Lotto\Core\sendError;
use function Lotto\Core\broadcastToRoom;
use function Lotto\Core\serverLog;

/**
 * Имитация (Mock) соединения Workerman для перехвата сетевых отправка.
 */
class MockConnection 
{
    public array $sentMessages = [];

    public function send(string $data): void 
    {
        // Вместо отправки в сокет, сохраняем данные в массив для проверки
        $this->sentMessages[] = $data;
    }
}

// Переменная для отслеживания общего статуса тестов
$allPassed = true;

echo "=== STARTING MANUAL TESTING FOR EPIC-0.9 ===\n\n";

// --- СЦЕНАРИЙ 1: Проверка sendJson ---
echo "[Scenario 1] Testing sendJson()...\n";
$conn1 = new MockConnection();
$testPayload = ['status' => 'ok', 'message' => 'Привет, Лото!'];

try {
    sendJson($conn1, $testPayload);
    $resultJson = $conn1->sentMessages[0] ?? '';
    
    // Проверяем, что кириллица не превратилась в \uXXXX и JSON валиден
    if ($resultJson === '{"status":"ok","message":"Привет, Лото!"}') {
        echo "✅ Success: JSON encoded correctly with UNESCAPED_UNICODE.\n";
    } else {
        echo "❌ Fail: Unexpected JSON output: " . $resultJson . "\n";
        $allPassed = false;
    }
} catch (Exception $e) {
    echo "❌ Fail: Exception caught: " . $e->getMessage() . "\n";
    $allPassed = false;
}
echo "----------------------------------------\n";


// --- СЦЕНАРИЙ 2: Проверка sendError ---
// --- СЦЕНАРИЙ 2: Проверка sendError() ---
// FIX-5: обновлено под актуальный контракт после FIX-1 —
// sendError(object $connection, string $code, string $message = ''):
// пакет содержит обязательное поле code (ANCHOR_PROTOCOL.md § Error Packet).
echo "[Scenario 2] Testing sendError()...\n";
$conn2 = new MockConnection();

sendError($conn2, 'error.invalid_json', 'Invalid action syntax');
$resultErrorJson = $conn2->sentMessages[0] ?? '';
$expectedError = '{"type":"error","code":"error.invalid_json","message":"Invalid action syntax"}';

if ($resultErrorJson === $expectedError) {
    echo "✅ Success: Protocol error packet formatted perfectly.\n";
} else {
    echo "❌ Fail: Expected {$expectedError}, but got: " . $resultErrorJson . "\n";
    $allPassed = false;
}
echo "----------------------------------------\n";


// --- СЦЕНАРИЙ 3: Проверка broadcastToRoom ---
echo "[Scenario 3] Testing broadcastToRoom()...\n";

// Накатываем структуру комнаты строго по правилам ANCHOR_CORE
$activeConn1 = new MockConnection();
$activeConn2 = new MockConnection();
$disconnectedConn = new MockConnection();

$room = [
    'room_id' => 101,
    'players' => [
        1 => ['status' => 'active', 'connection' => $activeConn1, 'username' => 'Player1'],
        2 => ['status' => 'active', 'connection' => $activeConn2, 'username' => 'Player2'],
        3 => ['status' => 'disconnected', 'connection' => $disconnectedConn, 'username' => 'Player3']
    ]
];

$broadcastPayload = ['type' => 'game_started', 'bank' => 40];
broadcastToRoom($room, $broadcastPayload);

$p1Received = count($activeConn1->sentMessages) === 1;
$p2Received = count($activeConn2->sentMessages) === 1;
$p3Received = count($disconnectedConn->sentMessages) === 0;

if ($p1Received && $p2Received && $p3Received) {
    echo "✅ Success: Broadcast delivered ONLY to active players. Disconnected ignored.\n";
} else {
    echo "❌ Fail: Broadcast routing broken.\n";
    echo "   Active 1 received: " . count($activeConn1->sentMessages) . " (Expected 1)\n";
    echo "   Active 2 received: " . count($activeConn2->sentMessages) . " (Expected 1)\n";
    echo "   Disconnected received: " . count($disconnectedConn->sentMessages) . " (Expected 0)\n";
    $allPassed = false;
}
echo "----------------------------------------\n";


// --- СЦЕНАРИЙ 4: Проверка serverLog ---
echo "[Scenario 4] Testing serverLog()...\n";

// Директория логов должна существовать относительно корня
if (!is_dir(__DIR__ . '/../../logs')) {
    mkdir(__DIR__ . '/../../logs', 0777, true);
}

$logger = new Logger();
$uniqueMessage = "Testing serverLog wrapper functionality at " . time();

serverLog($logger, 'INFO', $uniqueMessage);

$logContent = file_get_contents(__DIR__ . '/../../logs/server.log');
if (strpos($logContent, $uniqueMessage) !== false) {
    echo "✅ Success: Message successfully written to logs/server.log via wrapper.\n";
} else {
    echo "❌ Fail: Message not found in log file.\n";
    $allPassed = false;
}
echo "----------------------------------------\n";

// --- ИТОГ ---
if ($allPassed) {
    echo "🚀 ALL TESTS PASSED SUCCESSFULLY! Core Helpers are integration-ready.\n";
    exit(0);
} else {
    echo "💥 SOME TESTS FAILED. Review the implementation.\n";
    exit(1);
}
