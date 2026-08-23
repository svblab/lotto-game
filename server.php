<?php

/**
 * server.php — EPIC-10.0 Protocol router
 *
 * Bootstrap-файл Workerman WebSocket-сервера. Согласно ANCHOR_RULES.md
 * Rule 15/16 (Server Architecture Discipline) и ANCHOR_CORE.md § Bootstrap
 * Rule, этот файл ограничен: Workerman bootstrap, dependency wiring,
 * action routing, timer registration, module loading. Бизнес-логика
 * auth/room/economy/victory/apartment/reconnect/admin — запрещена.
 *
 * СОЗНАТЕЛЬНО НЕ РЕАЛИЗОВАНО: ничего — все группы действий, определённые
 * ANCHOR_PROTOCOL.md на текущий момент (auth/lobby/game/admin),
 * подключены (EPIC-10.3/10.4/10.5/10.6). Phase 10 wiring завершён по
 * всем модулям.
 *
 * EPIC-10.6 (Admin packet routing): admin_ban_user/admin_unban_user/
 * admin_kick_user/admin_close_room/admin_get_logs/admin_get_stats подключены к
 * AdminHandler (AdminService уже существовал, Phase 9 — здесь только
 * dependency wiring и routing, никакой новой admin-логики). AdminService
 * принимает 7 nullable-зависимостей — все семь подключены здесь (см.
 * комментарий у конструкции $adminService ниже в onWorkerStart);
 * пропуск любой из них тихо деградировал бы часть функциональности
 * (см. тот же комментарий) без явной ошибки, поэтому проверено отдельно
 * end-to-end, не только по сигнатуре конструктора.
 *
 * EPIC-10.5 (Game packet routing): start_game/draw_barrel/apartment_choice
 * подключены к GameHandler (GameService уже существовал, Phase 4-7 —
 * здесь только dependency wiring и routing, никакой новой игровой логики).
 * ReconnectService (EPIC-8.0) теперь тоже подключён — его конструктор
 * требует LobbyService И GameService одновременно (см. его собственный
 * __construct), поэтому подключение стало возможно только сейчас, когда
 * оба зависимых сервиса уже собраны (EPIC-10.4 + EPIC-10.5). onClose
 * делегирует ReconnectService::handleDisconnect(); action 'reconnect' —
 * после валидации токена AuthHandler'ом (EPIC-10.3) — дополнительно
 * пытается восстановить игровое состояние через
 * ReconnectService::handleReconnect() и разослать reconnect_state.
 *
 * EPIC-10.4 (Lobby packet routing): room_list/create_room/join_room/
 * leave_room подключены к LobbyHandler (LobbyService уже существовал —
 * здесь только dependency wiring, routing и guard «уже в комнате» для
 * create_room/join_room, делегированный из LobbyService::handleCreateRoom()).
 *
 * EPIC-10.3 (Auth packet routing): register/login/reconnect подключены к
 * AuthHandler (EPIC-1.3, уже существовал — здесь только dependency wiring
 * и routing, никакой новой auth-логики). AuthHandler::handleReconnect()
 * валидирует токен и восстанавливает $worker->userConnections, но НЕ
 * устанавливает $connection->userId и не отправляет reconnect_state — это
 * теперь делает ReconnectService::handleReconnect() (EPIC-10.5, см. выше),
 * когда находит игрока с совпадающим session_token в состоянии
 * 'disconnected' внутри какой-либо комнаты.
 *
 * ⚠️ KNOWN GAP (обнаружено при подключении EPIC-10.5, не устранено в этом
 * Epic'е — узкий edge-case вне основного сценария, см.
 * IMPLEMENTATION_STATUS.md): если токен валиден (AuthHandler подтвердил
 * сессию), но ReconnectService::handleReconnect() не находит подходящего
 * disconnected-игрока ни в одной комнате (т.е. пользователь не был в
 * игровой комнате на момент разрыва — сценарий вне ANCHOR_CORE.md §
 * Reconnect Rules, где reconnect определён только для 'waiting'/'playing'
 * комнаты), $connection->userId остаётся null. Требует отдельного фикса
 * в AuthHandler (симметрично FIX-8), но не в scope EPIC-10.5 (Rule 11).
 *
 * EPIC-10.1 (Packet validation): rate limiting и invalid-JSON policy
 * теперь реализованы и формализованы в ADR-003
 * (docs/ADR/003-rate-limiting-and-invalid-json-policy.md):
 *   - Невалидный JSON / неизвестный action → error.invalid_json,
 *     соединение НЕ закрывается.
 *   - Более RATE_LIMIT_PACKETS_PER_WINDOW пакетов за
 *     RATE_LIMIT_WINDOW_SECONDS секунд от одного соединения →
 *     немедленное закрытие БЕЗ error-пакета (это защита от злоупотребления,
 *     а не сообщение об ошибке клиенту).
 *
 * $worker->onClose теперь делегирует ReconnectService::handleDisconnect()
 * (EPIC-10.5) — заглушка снята, т.к. LobbyService (EPIC-10.4) и
 * GameService (EPIC-10.5), обе зависимости конструктора ReconnectService,
 * теперь собраны.
 *
 * EPIC-10.2 (Protocol error handling): реализован ТОЛЬКО глобальный
 * лимит соединений (ADR-005 — docs/ADR/005.md): при превышении
 * Constants::MAX_TOTAL_PLAYERS соединение получает error.server_full и
 * закрывается с WS close code 4001, до hello. Generic auth_required guard
 * (prompt.md Фаза 1, для действий вне {register, login, reconnect, ping})
 * СОЗНАТЕЛЬНО отложен — решение пользователя, будет реализован отдельно.
 */

declare(strict_types=1);

// FIX-14: CLI config path survives fork even when putenv is unreliable.
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $lottoCliArg) {
    if (!is_string($lottoCliArg) || !str_starts_with($lottoCliArg, '--lotto-config=')) {
        continue;
    }
    $lottoConfigPath = substr($lottoCliArg, strlen('--lotto-config='));
    if ($lottoConfigPath !== '') {
        putenv('LOTTO_TEST_CONFIG=' . $lottoConfigPath);
        $_ENV['LOTTO_TEST_CONFIG'] = $lottoConfigPath;
        $_SERVER['LOTTO_TEST_CONFIG'] = $lottoConfigPath;
    }
    break;
}

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Core/Helpers.php';

use function Lotto\Core\lottoApplyTestConfig;
use function Lotto\Core\lottoBootstrapPhpExtensions;
use function Lotto\Core\lottoRuntimeEnv;

lottoBootstrapPhpExtensions();
lottoApplyTestConfig();

use Workerman\Worker;
use Lotto\Core\Constants;
use Lotto\Core\Logger;
use Lotto\Core\MemoryAudit;
use Lotto\Core\TimerAudit;
use Lotto\Core\EconomyAudit;
use Lotto\Core\ServerRuntimeSettings;
use Lotto\Core\StateMachineAudit;
use Lotto\Core\LoadAudit;
use Lotto\Infrastructure\Database;
use Lotto\Infrastructure\PreparedStatements;
use Lotto\Auth\SessionService;
use Lotto\Auth\AuthService;
use Lotto\Auth\LoginThrottleService;
use Lotto\Auth\AuthHandler;
use Lotto\Auth\SessionGuardService;
use Lotto\Auth\IpAccountLimitService;
use Lotto\Core\RoomManager;
use Lotto\Lobby\LobbyService;
use Lotto\Lobby\LobbyHostService;
use Lotto\Lobby\LobbyHandler;
use Lotto\Game\LottoEngine;
use Lotto\Game\VictoryService;
use Lotto\Game\ApartmentService;
use Lotto\Game\GameFinishService;
use Lotto\Game\GameTurnService;
use Lotto\Game\GameService;
use Lotto\Game\GameHandler;
use Lotto\Game\ReconnectService;
use Lotto\Admin\AdminService;
use Lotto\Admin\AdminSettingsService;
use Lotto\Admin\AdminHandler;
use Lotto\Chat\ChatService;
use Lotto\Chat\FileTransferService;
use Lotto\Chat\ChatHandler;
use Workerman\Connection\TcpConnection;

use function Lotto\Core\sendJson;
use function Lotto\Core\sendError;
use function Lotto\Core\closeWithCode;
use function Lotto\Core\lottoTimerAdd;

// -----------------------------------------------------------------------
// Worker bootstrap (ANCHOR_CORE.md Part 1 — single Workerman worker,
// LOCAL_ENVIRONMENT.md — ws://localhost:8080)
// -----------------------------------------------------------------------

$wsPortEnv = lottoRuntimeEnv('LOTTO_WS_PORT');
$wsPort = ($wsPortEnv !== null) ? (int) $wsPortEnv : 8080;

$wmLogFile = lottoRuntimeEnv('LOTTO_WORKERMAN_LOG_FILE');
if ($wmLogFile !== null) {
    Worker::$logFile = $wmLogFile;
}

$wmPidFile = lottoRuntimeEnv('LOTTO_WORKERMAN_PID_FILE');
if ($wmPidFile !== null) {
    Worker::$pidFile = $wmPidFile;
}

$worker = new Worker('websocket://0.0.0.0:' . $wsPort);
$worker->count = 1;
$worker->name = 'LottoGameServer';

// ADR-030: explicit Workerman package cap (default is 10 MiB). 2 MiB fits
// ~1.37 MiB base64 for a 1 MiB file plus JSON envelope; see ADR-030 memory analysis.
TcpConnection::$defaultMaxPackageSize = Constants::WS_MAX_PACKAGE_SIZE;

$worker->onWorkerStart = function (Worker $worker): void {
    // Re-apply in forked worker (putenv may not survive pcntl_fork on all hosts).
    lottoApplyTestConfig();

    // Инфраструктура (Rule 15: dependency wiring разрешён в server.php)
    $worker->db = new Database();
    $serverLogPath = lottoRuntimeEnv('LOTTO_SERVER_LOG');
    $worker->logger = new Logger($serverLogPath);

    // EPIC-10.3 (Auth packet routing): AuthHandler уже реализован
    // (EPIC-1.3) — здесь только сборка зависимостей и подключение к
    // router'у, никакой новой бизнес-логики.
    $statements     = new PreparedStatements($worker->db->getPdo());
    $sessionService = new SessionService();
    $worker->loginThrottle = new LoginThrottleService();
    $authService    = new AuthService($worker->db, $statements, $worker->logger, $sessionService, $worker->loginThrottle);
    $sessionGuardService = new SessionGuardService($worker->logger);
    $worker->sessionGuard = $sessionGuardService;
    $ipAccountLimitService = new IpAccountLimitService($worker->logger);
    $worker->ipAccountLimit = $ipAccountLimitService;
    $worker->authHandler = new AuthHandler(
        $authService,
        $sessionService,
        $worker->logger,
        $sessionGuardService,
        $ipAccountLimitService
    );

    // EPIC-10.4 (Lobby packet routing): LobbyService уже реализован
    // (EPIC-2.x) — здесь только сборка зависимостей и подключение к
    // router'у, никакой новой lobby-логики.
    $worker->roomManager  = new RoomManager($worker->logger);
    $lobbyHostService = new LobbyHostService($worker->roomManager, $worker->logger);
    $worker->lobbyService = new LobbyService($worker->roomManager, $worker->logger, $lobbyHostService);
    $worker->lobbyHandler = new LobbyHandler($worker->lobbyService);

    // EPIC-10.5 (Game packet routing): GameService/VictoryService/
    // ApartmentService/GameFinishService уже реализованы (Phase 4-7) —
    // здесь только сборка зависимостей и подключение к router'у, никакой
    // новой игровой логики. Порядок аргументов конструктора GameService
    // идентичен уже принятому в tests/Manual/test_game_start.php.
    $lottoEngine    = new LottoEngine();
    $victoryService = new VictoryService();
    $apartmentService  = new ApartmentService($worker->db, $statements, $worker->logger);
    $gameFinishService = new GameFinishService($worker->db, $statements, $worker->logger);
    $gameTurnService = new GameTurnService(
        $worker->logger,
        $victoryService,
        $apartmentService,
        $gameFinishService
    );
    $worker->gameService = new GameService(
        $worker->db,
        $statements,
        $lottoEngine,
        $worker->logger,
        $victoryService,
        $apartmentService,
        $gameFinishService,
        $gameTurnService
    );
    $apartmentService->bindGameService($worker->gameService);
    $worker->gameHandler = new GameHandler($worker->gameService);

    // ReconnectService (EPIC-8.0) — конструктор требует LobbyService И
    // GameService одновременно; оба теперь собраны (EPIC-10.4 + EPIC-10.5
    // выше), поэтому подключение стало возможно только сейчас, не раньше
    // (см. комментарий у $worker->onClose и Rule 11 Epic Isolation).
    $worker->reconnectService = new ReconnectService(
        $worker->lobbyService,
        $worker->gameService,
        $worker->logger,
        $statements
    );
    // EPIC-13.1 (ADR-008): post-construction wiring for startTurn() AFK arm.
    $worker->gameService->setReconnectService($worker->reconnectService);
    $gameTurnService->setReconnectService($worker->reconnectService);

    // EPIC-10.6 (Admin packet routing): AdminService уже реализован
    // (Phase 9) — здесь только сборка зависимостей и подключение к
    // router'у, никакой новой admin-логики. Конструктор AdminService
    // принимает 7 nullable-зависимостей (stmts, logger, lobbyService,
    // reconnectService, apartmentService, db, roomManager) в этом
    // порядке — см. tests/Manual/test_admin_kick.php/test_admin_ban.php
    // для уже принятого паттерна вызова. КРИТИЧНО передать ВСЕ семь:
    // отсутствие lobbyService/reconnectService/apartmentService means an
    // online banned/kicked player is never actually removed from the
    // room (money still moves correctly, but a "ghost" player entry
    // lingers); отсутствие roomManager means admin_close_room falls back
    // to a raw unset($worker->rooms[$roomId]) that skips ALL timer
    // cleanup — the exact class of bug FIX-6 fixed elsewhere (Timer
    // Integrity Rule violation). $apartmentService is intentionally the
    // local variable from the EPIC-10.5 block above, not a $worker
    // property — EPIC-10.5 never stored it on $worker (only GameService
    // needed it, via constructor injection), so it's captured here by
    // closure scope instead of retroactively touching already-completed
    // EPIC-10.5 code (Rule 11 Epic Isolation).
    $adminService = new AdminService(
        $statements,
        $worker->logger,
        $worker->lobbyService,
        $worker->reconnectService,
        $apartmentService,
        $worker->db,
        $worker->roomManager
    );
    $adminSettingsService = new AdminSettingsService($adminService, $worker->logger);
    $worker->adminHandler = new AdminHandler($adminService, $adminSettingsService);

    // ADR-030: Chat + file transfer (password rooms only; RAM-only).
    $worker->chatService = new ChatService($worker->roomManager, $worker->logger);
    $worker->fileTransferService = new FileTransferService(
        $worker->roomManager,
        $worker->chatService,
        $worker->logger
    );
    $worker->chatHandler = new ChatHandler($worker->chatService, $worker->fileTransferService);

    // Runtime-память (ANCHOR_CORE.md § Runtime Memory Layout / Worker Storage)
    $worker->rooms           = [];
    $worker->userConnections = [];
    $worker->sessionTokens   = [];
    ServerRuntimeSettings::initOnWorker($worker);

    $worker->memoryAudit = new MemoryAudit($worker->logger);
    $worker->timerAudit = new TimerAudit($worker->logger);
    $worker->economyAudit = new EconomyAudit($worker->logger);
    $worker->stateAudit = new StateMachineAudit($worker->logger);
    $worker->loadAudit = new LoadAudit($worker->logger);
    $GLOBALS['__lotto_timer_audit'] = $worker->timerAudit;
    $GLOBALS['__lotto_economy_audit'] = $worker->economyAudit;
    $GLOBALS['__lotto_state_audit'] = $worker->stateAudit;

    $worker->logger->info('LottoGameServer started (protocol_version=' . Constants::PROTOCOL_VERSION . ')');
    $worker->memoryAudit->snapshot('worker_start', $worker);

    if (LoadAudit::isEnabled()) {
        $worker->loadAudit->snapshot('worker_start', $worker);
        lottoTimerAdd(60, function () use ($worker): void {
            $worker->loadAudit->snapshot('periodic', $worker);
        }, [], true, 'load_audit_periodic');
    }

    // EPIC-11.1: periodic memory snapshots every 30 minutes when audit is on.
    if (MemoryAudit::isEnabled()) {
        lottoTimerAdd(1800, function () use ($worker): void {
            $worker->memoryAudit->snapshot('periodic_snapshot', $worker);
        }, [], true, 'memory_audit_periodic');
    }

    // Global Watchdog Timer (ANCHOR_CORE.md Part 5 § Global Watchdog Timer)
    // Owner: server. Count: 1 для всего процесса. Interval: WATCHDOG_INTERVAL.
    // Закрывает мёртвые соединения по порогам AUTHORIZED/UNAUTHORIZED_TIMEOUT.
    // Создан в onWorkerStart, уничтожается вместе с процессом воркера —
    // отдельного Timer::del() не требуется (Worker shutdown = timer stop).
    lottoTimerAdd((float) Constants::watchdogInterval(), function () use ($worker): void {
        $now = time();

        foreach ($worker->connections as $connection) {
            $lastPing = $connection->lastPing ?? $now;
            $isAuthorized = !empty($connection->userId);

            $threshold = $isAuthorized
                ? Constants::authorizedTimeout()
                : Constants::unauthorizedTimeout();

            if (($now - $lastPing) > $threshold) {
                $worker->logger->info(
                    "Watchdog: closing dead connection (userId=" .
                    ($connection->userId ?? 'null') . ", idle=" . ($now - $lastPing) . "s)"
                );
                $connection->close();
            }
        }
    }, [], true, 'global_watchdog');

    // ADR-029: optional Origin allow-list (null = allow all).
    $originsRaw = lottoRuntimeEnv('LOTTO_ALLOWED_ORIGINS');
    if ($originsRaw === null || trim($originsRaw) === '') {
        $worker->allowedOrigins = null;
    } else {
        $worker->allowedOrigins = array_values(array_filter(
            array_map('trim', explode(',', $originsRaw)),
            static fn (string $o): bool => $o !== ''
        ));
    }
};

// -----------------------------------------------------------------------
// onWebSocketConnected — "Emitted after websocket handshake" (единственный
// корректный момент отправки hello: до этого коллбэка handshake ещё не
// завершён и произвольная запись в $connection->send() не будет
// корректно оформлена как WS-фрейм).
//
// EPIC-10.2 (ADR-005): глобальный лимит соединений — первая проверка,
// раньше инициализации полей и hello. $worker->connections уже включает
// ТЕКУЩЕЕ соединение (Workerman регистрирует его в acceptTcpConnection()
// на этапе TCP accept, до завершения WS handshake и вызова этого
// коллбэка) — поэтому сравнение строгое `>`, а не `>=`: ровно
// MAX_TOTAL_PLAYERS одновременных соединений допустимо, (N+1)-е получает
// error.server_full + WS close code 4001 и не доходит до hello.
//
// Это ОТДЕЛЬНАЯ метрика от проверок в LobbyService (FIX-7 / ADR-004):
// там считаются игроки, реально сидящие в комнатах
// (RoomManager::getTotalPlayerCount()) — уже аутентифицированное
// подмножество. Здесь — все живые сокеты сервера, включая ещё не
// аутентифицированные, до registration/login.
//
// ADR-029: optional Origin allow-list — second gate when
// $worker->allowedOrigins is non-empty (LOTTO_ALLOWED_ORIGINS env).
// -----------------------------------------------------------------------

$worker->onWebSocketConnected = function ($connection, $request = null) use ($worker): void {
    if (count($worker->connections) > Constants::MAX_TOTAL_PLAYERS) {
        sendError($connection, 'error.server_full', 'Server is full');
        closeWithCode($connection, 4001, 'server_full');
        return;
    }

    $allowedOrigins = $worker->allowedOrigins ?? null;
    if (is_array($allowedOrigins) && $allowedOrigins !== []) {
        $origin = null;
        if ($request !== null && is_object($request) && method_exists($request, 'header')) {
            $origin = $request->header('origin');
        }
        if (!is_string($origin) || $origin === '') {
            $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        }

        if (!is_string($origin) || $origin === '' || !in_array($origin, $allowedOrigins, true)) {
            $worker->logger->write(
                'WARNING',
                'Origin rejected: origin=' . (is_string($origin) && $origin !== '' ? $origin : 'missing')
                . ' conn_id=' . ($connection->id ?? 'null')
            );
            sendError($connection, 'error.origin_forbidden', 'Connection refused');
            closeWithCode($connection, 4002, 'origin_forbidden');
            return;
        }
    }

    // Инициализация свойств соединения (ANCHOR_CORE.md § Connection
    // Runtime Fields). Никаких дополнительных бизнес-полей — Rule запрещает.
    $connection->userId       = null;
    $connection->username     = null;
    $connection->isAdmin      = false;
    $connection->sessionToken = null;
    $connection->lastPing     = time();

    // ADR-003: Rate limiting — счётчик пакетов в текущем окне (1s).
    $connection->packetCount       = 0;
    $connection->packetWindowStart = time();

    // ADR-030: dedicated file_offer/file_data soft rate limit.
    $connection->fileActionCount       = 0;
    $connection->fileActionWindowStart = time();

    // ADR-031: resolved client IP for per-network account cap (trusted-proxy aware).
    if (isset($worker->ipAccountLimit)) {
        $worker->ipAccountLimit->attachClientRemoteIp($connection, $request);
    }

    sendJson($connection, [
        'type'             => 'hello',
        'protocol_version' => Constants::PROTOCOL_VERSION,
        'server_time'      => time(),
    ]);

    if (isset($worker->memoryAudit)) {
        $worker->memoryAudit->snapshot('connection_open', $worker, [
            'conn_id' => $connection->id ?? null,
        ]);
    }
};

// -----------------------------------------------------------------------
// onMessage — rate limiting (ADR-003) + безопасный парсинг JSON + пустой
// диспетчер действий.
//
// Rate limiting считает КАЖДОЕ входящее сообщение (валидное или нет) —
// это защита от объёма трафика, а не от конкретно невалидных пакетов,
// поэтому счётчик инкрементируется ДО json_decode.
//
// error.invalid_json (невалидный JSON, отсутствующий/неизвестный action)
// НЕ закрывает соединение — финализировано ADR-003 (docs/ADR/
// 003-rate-limiting-and-invalid-json-policy.md): код ошибки в
// ANCHOR_PROTOCOL.md предполагает, что клиент его получит и разберёт,
// что требует открытого соединения. Защиту от злоупотребления
// малформед-JSON обеспечивает rate limiting выше, а не разрыв на первом
// же невалидном пакете.
// -----------------------------------------------------------------------

$worker->onMessage = function ($connection, string $rawData) use ($worker): void {
    $connection->lastPing = time();

    // ADR-003: Rate limiting — окно RATE_LIMIT_WINDOW_SECONDS, лимит
    // RATE_LIMIT_PACKETS_PER_WINDOW пакетов. При превышении — немедленное
    // закрытие БЕЗ error-пакета (защита от злоупотребления, не сообщение
    // об ошибке).
    $now = time();
    if (($now - $connection->packetWindowStart) >= Constants::RATE_LIMIT_WINDOW_SECONDS) {
        $connection->packetWindowStart = $now;
        $connection->packetCount = 0;
    }
    $connection->packetCount++;

    if ($connection->packetCount > Constants::RATE_LIMIT_PACKETS_PER_WINDOW) {
        try {
            $worker->logger->info(
                'Rate limit exceeded, closing connection (userId=' .
                ($connection->userId ?? 'null') . ", count={$connection->packetCount})"
            );
        } catch (\Throwable) {
            // Logging must not prevent rate-limit close (FIX-14).
        }
        $connection->close();
        return;
    }

    $data = json_decode($rawData, true);

    if (!is_array($data)) {
        sendError($connection, 'error.invalid_json', 'Malformed JSON payload');
        return;
    }

    $action = $data['action'] ?? null;

    if ($action === 'ping') {
        // prompt.md § ping: в комнате — last_action; в лобби — lastPing (уже выше).
        // На afk_start не влияет (ANCHOR_CORE § Game AFK Timer).
        // На host_activity_at не влияет (ADR-010 § Lobby AFK Timer).
        $connId = (int) $connection->id;
        $roomId = $worker->roomManager->findRoomIdByConnId($worker, $connId);
        if ($roomId !== null && isset($worker->rooms[$roomId]['players'][$connId])) {
            $worker->rooms[$roomId]['players'][$connId]['last_action'] = time();
        }
        return;
    }

    if (!is_string($action) || $action === '') {
        sendError($connection, 'error.invalid_json', 'Missing or invalid action field');
        return;
    }

    // EPIC-10.2 continuation (ADR-006): generic auth_required guard.
    // prompt.md Фаза 1: "проверка userId для всех кейсов кроме register,
    // login, reconnect" — ping уже обработан выше (return до этой точки),
    // поэтому в списке исключений не дублируется. Проверяется здесь ОДИН
    // РАЗ для всех действий, а не в каждом будущем хендлере отдельно —
    // не бизнес-логика конкретного модуля, а протокольное правило
    // маршрутизации (Rule 15 разрешает такие проверки в server.php).
    $authExemptActions = ['register', 'login', 'reconnect'];
    if ($connection->userId === null && !in_array($action, $authExemptActions, true)) {
        sendError($connection, 'error.auth_required', 'Authentication required');
        return;
    }

    // EPIC-028.1: on every authenticated action, evict any other live socket for
    // this user_id (closes the dual-live window where reconnect + fresh login
    // both stay bound — e.g. client auto-reconnect after superseded close).
    if (
        ($connection->userId ?? null) !== null
        && isset($worker->sessionGuard)
        && !in_array($action, $authExemptActions, true)
    ) {
        $worker->sessionGuard->evictOtherLiveSessions(
            $worker,
            (int) $connection->userId,
            $connection
        );
    }

    // EPIC-10.4: guard «уже в комнате» для create_room/join_room.
    // LobbyService::handleCreateRoom() документирует эту проверку как
    // ответственность router'а — один раз здесь, не в каждом хендлере.
    $lobbyMembershipActions = ['create_room', 'join_room'];
    if (in_array($action, $lobbyMembershipActions, true)) {
        $existingRoomId = $worker->roomManager->findRoomIdByConnId($worker, $connection->id);
        if ($existingRoomId !== null) {
            sendError($connection, 'error.invalid_json', 'Already in a room');
            return;
        }
    }

    // EPIC-10.5: 'reconnect' обрабатывается отдельно от match()-диспетчера
    // ниже (не в одном arm) — требует ДВУХ последовательных шагов, а не
    // одного вызова: (1) AuthHandler::handleReconnect() валидирует токен
    // формально и восстанавливает $worker->userConnections (EPIC-10.3);
    // (2) если токен синтаксически валиден, ReconnectService::
    // handleReconnect() (теперь собран — оба его зависимых сервиса,
    // LobbyService и GameService, готовы) довершает восстановление:
    // находит игрока с совпадающим session_token в состоянии
    // 'disconnected' внутри комнаты, устанавливает $connection->userId и
    // рассылает reconnect_state. Если совпадения нет — см. KNOWN GAP в
    // шапке файла, ничего дополнительно не отправляется.
    if ($action === 'reconnect') {
        $worker->authHandler->handleReconnect($data, $connection, $worker);

        $token = $data['token'] ?? null;
        $roomRestored = false;
        if (is_string($token) && $token !== '' && ($connection->userId ?? null) !== null) {
            $roomRestored = $worker->reconnectService->handleReconnect($token, $connection, $worker);
        }

        if (
            ($connection->userId ?? null) !== null
            && !$roomRestored
            && is_string($token)
            && $token !== ''
        ) {
            $worker->authHandler->notifyLobbyRestored($connection, $token);
        }
        return;
    }

    // Диспетчер: auth (EPIC-10.3), lobby (EPIC-10.4), game (EPIC-10.5) и
    // admin (EPIC-10.6) подключены. reconnect обработан отдельно выше.
    $handlerStart = hrtime(true);

    // ADR-010: genuine lobby host activity — only waiting-room lobby actions.
    // Excludes start_game (game transition) and all in-game/admin actions.
    $lobbyHostActivityActions = ['room_list', 'create_room', 'join_room', 'leave_room'];
    if (in_array($action, $lobbyHostActivityActions, true)) {
        $worker->lobbyService->touchLobbyHostActivity($worker, (int) $connection->id);
    }

    match ($action) {
        'register'         => $worker->authHandler->handleRegister($data, $connection, $worker),
        'login'            => (function () use ($worker, $data, $connection): void {
            $worker->authHandler->handleLogin($data, $connection, $worker);
            $token = $connection->sessionToken ?? null;
            if (($connection->userId ?? null) === null || !is_string($token) || $token === '') {
                return;
            }
            $worker->reconnectService->adoptSessionTokenForUser(
                $worker,
                (int) $connection->userId,
                $token
            );
            $roomRestored = $worker->reconnectService->handleReconnect($token, $connection, $worker);
            if (!$roomRestored) {
                $worker->authHandler->notifyLobbyRestored($connection, $token);
            }
        })(),
        'room_list'        => $worker->lobbyHandler->handleRoomList($connection, $worker),
        'create_room'      => $worker->lobbyHandler->handleCreateRoom($data, $connection, $worker),
        'join_room'        => $worker->lobbyHandler->handleJoinRoom($data, $connection, $worker),
        'leave_room'       => $worker->lobbyHandler->handleLeaveRoom($connection, $worker),
        'start_game'       => $worker->gameHandler->handleStartGame($connection, $worker),
        'draw_barrel'      => $worker->gameHandler->handleDrawBarrel($connection, $worker),
        'turn_ready'       => $worker->gameHandler->handleTurnReady($connection, $worker),
        'nudge_turn'       => $worker->gameHandler->handleNudgeTurn($connection, $worker),
        'apartment_choice' => $worker->gameHandler->handleApartmentChoice($data, $connection, $worker),
        'admin_ban_user'   => $worker->adminHandler->handleBanUser($data, $connection, $worker),
        'admin_unban_user' => $worker->adminHandler->handleUnbanUser($data, $connection),
        'admin_kick_user'  => $worker->adminHandler->handleKickUser($data, $connection, $worker),
        'admin_close_room' => $worker->adminHandler->handleCloseRoom($data, $connection, $worker),
        'admin_get_logs'   => $worker->adminHandler->handleGetLogs($data, $connection),
        'admin_get_stats'  => $worker->adminHandler->handleGetStats($data, $connection, $worker),
        'admin_get_users'    => $worker->adminHandler->handleGetUsers($data, $connection, $worker),
        'admin_get_settings' => $worker->adminHandler->handleGetSettings($data, $connection, $worker),
        'admin_set_settings' => $worker->adminHandler->handleSetSettings($data, $connection, $worker),
        'admin_restart_server' => $worker->adminHandler->handleRestartServer($data, $connection, $worker),
        'room_message'     => $worker->chatHandler->handleRoomMessage($data, $connection, $worker),
        'file_offer'       => $worker->chatHandler->handleFileOffer($data, $connection, $worker),
        'file_accept'      => $worker->chatHandler->handleFileAccept($data, $connection, $worker),
        'file_reject'      => $worker->chatHandler->handleFileReject($data, $connection, $worker),
        'file_data'        => $worker->chatHandler->handleFileData($data, $connection, $worker),
        default            => sendError($connection, 'error.invalid_json', "Unknown or not-yet-wired action: {$action}"),
    };

    if (isset($worker->loadAudit)) {
        $latencyMs = (hrtime(true) - $handlerStart) / 1_000_000;
        $worker->loadAudit->recordLatency($action, $latencyMs, $worker);
    }

    if (isset($worker->memoryAudit) && MemoryAudit::shouldLogAction($action)) {
        $worker->memoryAudit->snapshot('packet_processed', $worker, ['action' => $action]);
    }
};

// -----------------------------------------------------------------------
// onClose — EPIC-10.5: делегирует ReconnectService::handleDisconnect().
// Оба зависимых сервиса ReconnectService (LobbyService, GameService)
// теперь собраны в onWorkerStart, поэтому подключение реальной бизнес-
// логики (вместо диагностической заглушки) стало возможным.
// -----------------------------------------------------------------------

$worker->onClose = function ($connection) use ($worker): void {
    if (isset($worker->sessionGuard)) {
        $worker->sessionGuard->handleConnectionClose($connection, $worker);
    }

    if (isset($worker->memoryAudit)) {
        $worker->memoryAudit->snapshot('connection_close', $worker, [
            'conn_id' => $connection->id ?? null,
            'user_id' => null,
        ]);
    }
};

Worker::runAll();
