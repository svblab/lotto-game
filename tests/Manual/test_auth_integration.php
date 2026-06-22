<?php

/**
 * EPIC-1.4 — Authentication Integration Tests
 *
 * Верифицирует контракты AuthService, AuthHandler, SessionService.
 * Запускается на VPS: php tests/Manual/test_auth_integration.php
 *
 * Зависимости: PDO/SQLite3, namespace Lotto\Auth, Lotto\Infrastructure, Lotto\Core.
 * БД: in-memory SQLite (не затрагивает production game.db).
 * Workerman не требуется: используется MockConnection.
 *
 * Rule 22 ANCHOR_RULES: тесты верифицируют контракты, не компенсируют их отсутствие.
 */

declare(strict_types=1);

// ─── Bootstrap ───────────────────────────────────────────────────────────────

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    die("FAIL: vendor/autoload.php not found. Run: composer install\n");
}
require_once $autoload;
require_once dirname(__DIR__, 2) . '/src/Core/Helpers.php';

use Lotto\Auth\AuthService;
use Lotto\Auth\AuthHandler;
use Lotto\Auth\SessionService;
use Lotto\Infrastructure\PreparedStatements;
use Lotto\Core\Logger;

// ─── Test runner ─────────────────────────────────────────────────────────────

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

// ─── In-memory SQLite fixture ─────────────────────────────────────────────────

function makeTestPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("PRAGMA foreign_keys = ON;");
    $pdo->exec("PRAGMA journal_mode = WAL;");
    $pdo->exec("
        CREATE TABLE users (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            username         TEXT    NOT NULL UNIQUE,
            password_hash    TEXT    NOT NULL,
            coins            INTEGER NOT NULL DEFAULT 500,
            is_admin         INTEGER NOT NULL DEFAULT 0,
            banned_until     INTEGER NOT NULL DEFAULT 0,
            last_daily_bonus INTEGER NOT NULL DEFAULT 0
        )
    ");
    return $pdo;
}

// ─── Mock helpers ─────────────────────────────────────────────────────────────

/**
 * MockConnection имитирует объект Workerman\Connection.
 * Перехватывает send() для проверки отправленных пакетов.
 */
class MockConnection
{
    public array $sent = [];
    public ?int $userId      = null;
    public ?string $username = null;
    public bool $isAdmin     = false;
    public ?string $sessionToken = null;
    public int $lastPing     = 0;

    public function send(string $data): void
    {
        $this->sent[] = json_decode($data, true);
    }

    public function lastPacket(): ?array
    {
        return $this->sent[count($this->sent) - 1] ?? null;
    }
}

class MockWorker
{
    public array $sessionTokens   = [];
    public array $userConnections = [];
}

function makeLogger(): Logger
{
    return new Logger('/dev/null');
}

function makeServices(PDO $pdo): array
{
    $statements     = new PreparedStatements($pdo);
    $sessionService = new SessionService();
    $logger         = makeLogger();
    $authService    = new AuthService(
        new class($pdo) extends \Lotto\Infrastructure\Database {
            private PDO $testPdo;
            public function __construct(PDO $pdo) { $this->testPdo = $pdo; }
            public function getPdo(): PDO { return $this->testPdo; }
        },
        $statements,
        $logger,
        $sessionService
    );
    $authHandler = new AuthHandler($authService, $sessionService, $logger);
    return [$authService, $authHandler, $sessionService];
}

// ─── SUITE 1: SessionService ──────────────────────────────────────────────────

echo "\n=== SUITE 1: SessionService ===\n";

$session = new SessionService();

$token = $session->generateToken();
ok('SessionService: generateToken() returns string',      is_string($token));
ok('SessionService: token is 32 characters',              strlen($token) === 32);
ok('SessionService: token matches hex pattern',           (bool)preg_match('/^[a-f0-9]{32}$/', $token));
ok('SessionService: isValidToken() accepts valid token',  $session->isValidToken($token));
ok('SessionService: isValidToken() rejects short token',  !$session->isValidToken('abc'));
ok('SessionService: isValidToken() rejects uppercase hex', !$session->isValidToken(strtoupper($token)));
ok('SessionService: tokensEqual() same tokens',           $session->tokensEqual($token, $token));
ok('SessionService: tokensEqual() different tokens',      !$session->tokensEqual($token, $session->generateToken()));

// ─── SUITE 2: AuthService::register() ────────────────────────────────────────

echo "\n=== SUITE 2: AuthService::register() ===\n";

$pdo = makeTestPdo();
[$authService] = makeServices($pdo);

// 2a. Успешная регистрация
try {
    $result = $authService->register('player1', 'secret123');
    ok('register: success returns array with success=true', $result['success'] === true);
    ok('register: success returns correct username',        $result['username'] === 'player1');
} catch (Exception $e) {
    ok('register: success (exception thrown)', false, $e->getMessage());
    ok('register: username in result', false);
}

// 2b. Дубль username
try {
    $authService->register('player1', 'secret123');
    ok('register: duplicate username throws exception', false, 'No exception thrown');
} catch (Exception $e) {
    ok('register: duplicate username throws exception', $e->getMessage() === 'Username already exists');
}

// 2c. Невалидный username
try {
    $authService->register('x', 'secret123');
    ok('register: short username throws exception', false, 'No exception thrown');
} catch (Exception $e) {
    ok('register: short username throws exception',
        str_contains($e->getMessage(), 'Invalid username'));
}

// 2d. Невалидный пароль (слишком короткий)
try {
    $authService->register('newuser', '123');
    ok('register: short password throws exception', false, 'No exception thrown');
} catch (Exception $e) {
    ok('register: short password throws exception',
        str_contains($e->getMessage(), 'Password'));
}

// 2e. Проверяем начальный баланс 500 монет (ANCHOR_CORE Part 2)
$stmt = $pdo->prepare('SELECT coins FROM users WHERE username = ?');
$stmt->execute(['player1']);
$row = $stmt->fetch();
ok('register: initial coins = 500', (int)$row['coins'] === 500);

// ─── SUITE 3: AuthService::login() ───────────────────────────────────────────

echo "\n=== SUITE 3: AuthService::login() ===\n";

$pdo2 = makeTestPdo();
[$authService2] = makeServices($pdo2);
$authService2->register('loginuser', 'pass1234');

$worker = new MockWorker();
$conn   = new MockConnection();

// 3a. Успешный вход
try {
    $result = $authService2->login('loginuser', 'pass1234', $worker, $conn);
    ok('login: success returns success=true',                  $result['success'] === true);
    ok('login: result contains session_token',                 isset($result['session_token']) && strlen($result['session_token']) === 32);
    ok('login: result contains user.id',                       isset($result['user']['id']));
    ok('login: result contains user.username',                 $result['user']['username'] === 'loginuser');
    ok('login: result contains user.coins',                    isset($result['user']['coins']));
    ok('login: result contains user.is_admin',                 isset($result['user']['is_admin']));
    ok('login: worker->userConnections populated',             isset($worker->userConnections[$result['user']['id']]));
} catch (Exception $e) {
    foreach (range(1, 7) as $i) {
        ok("login: success check {$i}", false, $e->getMessage());
    }
}

// 3b. Неверный пароль
try {
    $authService2->login('loginuser', 'wrongpass');
    ok('login: wrong password throws exception', false, 'No exception thrown');
} catch (Exception $e) {
    ok('login: wrong password throws exception',
        $e->getMessage() === 'Invalid username or password');
}

// 3c. Несуществующий пользователь
try {
    $authService2->login('nobody', 'pass1234');
    ok('login: unknown user throws exception', false, 'No exception thrown');
} catch (Exception $e) {
    ok('login: unknown user throws exception',
        $e->getMessage() === 'Invalid username or password');
}

// 3d. Бан
$pdo2->exec("UPDATE users SET banned_until = " . (time() + 3600) . " WHERE username='loginuser'");
try {
    $authService2->login('loginuser', 'pass1234');
    ok('login: banned user throws exception', false, 'No exception thrown');
} catch (Exception $e) {
    ok('login: banned user throws exception',      $e->getMessage() === 'User is banned');
    ok('login: exception code = banned_until Unix timestamp', $e->getCode() > time());
}
$pdo2->exec("UPDATE users SET banned_until = 0 WHERE username='loginuser'");

// 3e. Daily bonus (принудительно сбрасываем last_daily_bonus в прошлое)
$pdo3 = makeTestPdo();
[$authService3] = makeServices($pdo3);
$authService3->register('bonususer', 'pass1234');
$pdo3->exec("UPDATE users SET last_daily_bonus = 0 WHERE username='bonususer'");
try {
    $result3 = $authService3->login('bonususer', 'pass1234');
    ok('login: daily_bonus_received = true when eligible', $result3['daily_bonus_received'] === true);
    ok('login: coins increased by 100 after daily bonus',  $result3['user']['coins'] === 600);
} catch (Exception $e) {
    ok('login: daily bonus eligible', false, $e->getMessage());
    ok('login: coins after bonus', false);
}

// 3f. Двойной вход (same user_id, $worker->userConnections уже занят)
$pdo4 = makeTestPdo();
[$authService4] = makeServices($pdo4);
$authService4->register('doubleuser', 'pass1234');
$worker4 = new MockWorker();
$conn4a  = new MockConnection();
$conn4b  = new MockConnection();
$authService4->login('doubleuser', 'pass1234', $worker4, $conn4a);
try {
    $authService4->login('doubleuser', 'pass1234', $worker4, $conn4b);
    ok('login: double login throws exception', false, 'No exception thrown');
} catch (Exception $e) {
    ok('login: double login throws exception', $e->getMessage() === 'User already logged in');
}

// ─── SUITE 4: AuthHandler ─────────────────────────────────────────────────────

echo "\n=== SUITE 4: AuthHandler ===\n";

$pdo5 = makeTestPdo();
[, $handler5, ] = makeServices($pdo5);
$worker5 = new MockWorker();

// 4a. handleRegister — успех: получаем auth_result
$connReg = new MockConnection();
$handler5->handleRegister(['username' => 'huser1', 'password' => 'hpass123'], $connReg, $worker5);
$pkt = $connReg->lastPacket();
print_r($pkt);
ok('handleRegister: sends auth_result on success',   ($pkt['type'] ?? '') === 'auth_result');
ok('handleRegister: auth_result success=true',        ($pkt['success'] ?? false) === true);
ok('handleRegister: auth_result contains session_token', isset($pkt['session_token']));
ok('handleRegister: auth_result username matches',    ($pkt['username'] ?? '') === 'huser1');
ok('handleRegister: auth_result coins = 500',         ($pkt['coins'] ?? -1) === 500);
ok('handleRegister: session stored in worker',        count($worker5->sessionTokens) === 1);

// 4b. handleRegister — невалидное имя: error-пакет с error.auth_invalid_username
$connRegErr = new MockConnection();
$handler5->handleRegister(['username' => 'x', 'password' => 'hpass123'], $connRegErr, $worker5);
$pktErr = $connRegErr->lastPacket();
ok('handleRegister: sends error on invalid username', ($pktErr['type'] ?? '') === 'error');
ok('handleRegister: error.code = error.auth_invalid_username',
    ($pktErr['code'] ?? '') === 'error.auth_invalid_username');

// 4c. handleRegister — дубль: error.auth_username_taken
$connRegDup = new MockConnection();
$handler5->handleRegister(['username' => 'huser1', 'password' => 'hpass123'], $connRegDup, $worker5);
$pktDup = $connRegDup->lastPacket();
ok('handleRegister: error.code = error.auth_username_taken on duplicate',
    ($pktDup['code'] ?? '') === 'error.auth_username_taken');

// 4d. handleRegister — отсутствуют поля: error.auth_invalid_username
$connRegNoFields = new MockConnection();
$handler5->handleRegister([], $connRegNoFields, $worker5);
$pktNoF = $connRegNoFields->lastPacket();
ok('handleRegister: error on missing fields', ($pktNoF['type'] ?? '') === 'error');

// 4e. handleLogin — успех
$pdo6 = makeTestPdo();
[, $handler6, ] = makeServices($pdo6);
$worker6  = new MockWorker();
$connLogin = new MockConnection();
// Регистрируем через handler чтобы пользователь существовал
$handler6->handleRegister(['username' => 'luser1', 'password' => 'lpass123'], $connLogin, $worker6);
// Сбрасываем userConnections для повторного логина
$worker6->userConnections = [];
$connLogin2 = new MockConnection();
$handler6->handleLogin(['username' => 'luser1', 'password' => 'lpass123'], $connLogin2, $worker6);
$pktLogin = $connLogin2->lastPacket();
ok('handleLogin: sends auth_result on success', ($pktLogin['type'] ?? '') === 'auth_result');

// 4f. handleLogin — неверный пароль
$connLoginBad = new MockConnection();
$handler6->handleLogin(['username' => 'luser1', 'password' => 'wrong'], $connLoginBad, $worker6);
$pktLoginBad = $connLoginBad->lastPacket();
ok('handleLogin: sends error on wrong password',   ($pktLoginBad['type'] ?? '') === 'error');
ok('handleLogin: error.code = error.auth_invalid_credentials',
    ($pktLoginBad['code'] ?? '') === 'error.auth_invalid_credentials');

// 4g. handleLogin — бан
$pdo6->exec("UPDATE users SET banned_until = " . (time() + 3600) . " WHERE username='luser1'");
$worker6b = new MockWorker();
$connBanned = new MockConnection();
$handler6->handleLogin(['username' => 'luser1', 'password' => 'lpass123'], $connBanned, $worker6b);
$pktBanned = $connBanned->lastPacket();
ok('handleLogin: sends banned packet for banned user',      ($pktBanned['type'] ?? '') === 'banned');
ok('handleLogin: banned packet contains until timestamp',   isset($pktBanned['until']) && $pktBanned['until'] > time());

// 4h. handleReconnect — несуществующий токен
$connReconn = new MockConnection();
$worker6->sessionTokens = [];
$handler6->handleReconnect(['token' => 'deadbeef00000000deadbeef00000000'], $connReconn, $worker6);
$pktReconn = $connReconn->lastPacket();
ok('handleReconnect: error on unknown token',              ($pktReconn['type'] ?? '') === 'error');
ok('handleReconnect: error.code = error.auth_invalid_token',
    ($pktReconn['code'] ?? '') === 'error.auth_invalid_token');

// 4i. handleReconnect — невалидный формат токена (не 32 hex символа)
$connRecBad = new MockConnection();
$handler6->handleReconnect(['token' => 'short'], $connRecBad, $worker6);
$pktRecBad = $connRecBad->lastPacket();
ok('handleReconnect: error on malformed token format',
    ($pktRecBad['code'] ?? '') === 'error.auth_invalid_token');

// 4j. handleReconnect — корректный токен: восстанавливает userConnections
$pdo7 = makeTestPdo();
[, $handler7, ] = makeServices($pdo7);
$worker7  = new MockWorker();
$connR1 = new MockConnection();
$handler7->handleRegister(['username' => 'reconn_user', 'password' => 'rpass123'], $connR1, $worker7);
$storedToken = array_key_first($worker7->sessionTokens);
$storedUserId = $worker7->sessionTokens[$storedToken];
// Очищаем userConnections, симулируем разрыв
$worker7->userConnections = [];
$connR2 = new MockConnection();
$handler7->handleReconnect(['token' => $storedToken], $connR2, $worker7);
ok('handleReconnect: userConnections restored on valid token',
    isset($worker7->userConnections[$storedUserId]));
ok('handleReconnect: no error packet sent on valid reconnect',
    ($connR2->lastPacket()['type'] ?? 'none') !== 'error');

// ─── Summary ──────────────────────────────────────────────────────────────────

summary();
