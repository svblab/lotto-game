<?php

declare(strict_types=1);

/**
 * Admin user directory for moderation UI.
 * Run: php tests/Manual/test_admin_users.php
 */

require __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Helpers.php';

use Lotto\Admin\AdminService;

$passed = 0;
$failed = 0;

function assertTrue(bool $cond, string $label): void
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

function assertEquals(mixed $expected, mixed $actual, string $label): void
{
    assertTrue(
        $expected === $actual,
        "{$label} (expected: " . var_export($expected, true) . ", got: " . var_export($actual, true) . ")"
    );
}

final class SpyConnection
{
    public mixed $userId = null;
    public mixed $isAdmin = false;
    public array $sent = [];

    public function send(string $json): void
    {
        $this->sent[] = json_decode($json, true);
    }

    public function lastSent(): ?array
    {
        return $this->sent[array_key_last($this->sent)] ?? null;
    }
}

final class MockUsersStmts
{
    /** @var list<array<string, mixed>> */
    private array $users;

    public function __construct(array $users)
    {
        $this->users = $users;
    }

    public function get(string $key): object
    {
        if ($key !== 'users_admin_list') {
            throw new InvalidArgumentException("Unexpected key: {$key}");
        }

        return new class($this->users) {
            /** @var list<array<string, mixed>> */
            private array $users;

            public function __construct(array $users)
            {
                $this->users = $users;
            }

            public function execute(array $params): void
            {
                $this->lastParams = $params;
            }

            public array $lastParams = [];

            public function fetchAll(): array
            {
                $search = (string)($this->lastParams[0] ?? '');
                $bannedOnly = (int)($this->lastParams[2] ?? 0) === 1;
                $limit = (int)($this->lastParams[3] ?? 200);
                $now = time();

                $rows = array_values(array_filter($this->users, static function (array $user) use ($search, $bannedOnly, $now): bool {
                    if ($search !== '' && stripos((string)$user['username'], $search) === false) {
                        return false;
                    }
                    if ($bannedOnly && (int)($user['banned_until'] ?? 0) <= $now) {
                        return false;
                    }
                    return true;
                }));

                return array_slice($rows, 0, max(1, $limit));
            }
        };
    }
}

function makeWorker(array $onlineIds, array $rooms): object
{
    $worker = new stdClass();
    $worker->userConnections = [];
    foreach ($onlineIds as $id) {
        $worker->userConnections[$id] = new stdClass();
    }
    $worker->rooms = $rooms;
    return $worker;
}

$users = [
    ['id' => 1, 'username' => 'alice', 'coins' => 500, 'is_admin' => 0, 'banned_until' => 0],
    ['id' => 2, 'username' => 'bob', 'coins' => 300, 'is_admin' => 0, 'banned_until' => time() + 3600],
    ['id' => 3, 'username' => 'admin2', 'coins' => 1000, 'is_admin' => 1, 'banned_until' => 0],
];

$rooms = [
    7 => [
        'players' => [
            100 => ['user_id' => 1, 'username' => 'alice', 'status' => 'active'],
        ],
    ],
];

echo "TEST 1: Unauthenticated access\n";
$svc1 = new AdminService(new MockUsersStmts($users));
$conn1 = new SpyConnection();
$svc1->handleGetUsers([], $conn1, makeWorker([], []));
assertEquals('error', $conn1->lastSent()['type'] ?? null, 'response type is error');
assertEquals('error.auth_required', $conn1->lastSent()['code'] ?? null, 'code is error.auth_required');

echo "\nTEST 2: Non-admin access\n";
$svc2 = new AdminService(new MockUsersStmts($users));
$conn2 = new SpyConnection();
$conn2->userId = 42;
$conn2->isAdmin = false;
$svc2->handleGetUsers([], $conn2, makeWorker([], []));
assertEquals('error', $conn2->lastSent()['type'] ?? null, 'response type is error');
assertEquals('error.not_your_turn', $conn2->lastSent()['code'] ?? null, 'code is error.not_your_turn');

echo "\nTEST 3: Admin success with online and room mapping\n";
$svc3 = new AdminService(new MockUsersStmts($users));
$conn3 = new SpyConnection();
$conn3->userId = 1;
$conn3->isAdmin = true;
$svc3->handleGetUsers(['search' => ''], $conn3, makeWorker([1], $rooms));
$resp3 = $conn3->lastSent();
assertEquals('admin_users_data', $resp3['type'] ?? null, 'response type');
assertEquals(3, count($resp3['users'] ?? []), 'returns all users');

$alice = null;
foreach ($resp3['users'] as $user) {
    if (($user['id'] ?? 0) === 1) {
        $alice = $user;
        break;
    }
}
assertTrue($alice !== null, 'alice present');
assertTrue(($alice['online'] ?? false) === true, 'alice marked online');
assertEquals(7, $alice['room_id'] ?? null, 'alice room_id mapped');

echo "\nTEST 4: Search and banned_only filters\n";
$svc4 = new AdminService(new MockUsersStmts($users));
$conn4 = new SpyConnection();
$conn4->userId = 1;
$conn4->isAdmin = true;
$svc4->handleGetUsers(['search' => 'bo', 'banned_only' => true], $conn4, makeWorker([], []));
$resp4 = $conn4->lastSent();
assertEquals('admin_users_data', $resp4['type'] ?? null, 'response type');
assertEquals(1, count($resp4['users'] ?? []), 'only bob matches');
assertEquals('bob', $resp4['users'][0]['username'] ?? null, 'bob username');
assertTrue(($resp4['users'][0]['banned'] ?? false) === true, 'bob marked banned');

echo "\nTEST 5: online_only filter\n";
$svc5 = new AdminService(new MockUsersStmts($users));
$conn5 = new SpyConnection();
$conn5->userId = 1;
$conn5->isAdmin = true;
$svc5->handleGetUsers(['online_only' => true], $conn5, makeWorker([3], []));
$resp5 = $conn5->lastSent();
assertEquals(1, count($resp5['users'] ?? []), 'only online user returned');
assertEquals(3, $resp5['users'][0]['id'] ?? null, 'online user is admin2');

echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
