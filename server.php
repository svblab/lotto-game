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
 * СОЗНАТЕЛЬНО НЕ РЕАЛИЗОВАНО (Rule 11 Epic Isolation —
 * "Auth+Lobby, Lobby+Game, Game+Admin... в одном Epic запрещены"):
 *   - Маршрутизация admin-пакетов (admin_*) → EPIC-10.6
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

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Core/Helpers.php';

use Workerman\Worker;
use Workerman\Timer;
use Lotto\Core\Constants;
use Lotto\Core\Logger;
use Lotto\Infrastructure\Database;
use Lotto\Infrastructure\PreparedStatements;
use Lotto\Auth\SessionService;
use Lotto\Auth\AuthService;
use Lotto\Auth\AuthHandler;
use Lotto\Core\RoomManager;
use Lotto\Lobby\LobbyService;
use Lotto\Lobby\LobbyHandler;
use Lotto\Game\LottoEngine;
use Lotto\Game\VictoryService;
use Lotto\Game\ApartmentService;
use Lotto\Game\GameFinishService;
use Lotto\Game\GameService;
use Lotto\Game\GameHandler;
use Lotto\Game\ReconnectService;

use function Lotto\Core\sendJson;
use function Lotto\Core\sendError;
use function Lotto\Core\closeWithCode;

// -----------------------------------------------------------------------
// Worker bootstrap (ANCHOR_CORE.md Part 1 — single Workerman worker,
// LOCAL_ENVIRONMENT.md — ws://localhost:8080)
// -----------------------------------------------------------------------

$worker = new Worker('websocket://0.0.0.0:8080');
$worker->count = 1;
$worker->name = 'LottoGameServer';

$worker->onWorkerStart = function (Worker $worker): void {
    // Инфраструктура (Rule 15: dependency wiring разрешён в server.php)
    $worker->db     = new Database();
    $worker->logger = new Logger();

    // EPIC-10.3 (Auth packet routing): AuthHandler уже реализован
    // (EPIC-1.3) — здесь только сборка зависимостей и подключение к
    // router'у, никакой новой бизнес-логики.
    $statements     = new PreparedStatements($worker->db->getPdo());
    $sessionService = new SessionService();
    $authService    = new AuthService($worker->db, $statements, $worker->logger, $sessionService);
    $worker->authHandler = new AuthHandler($authService, $sessionService, $worker->logger);

    // EPIC-10.4 (Lobby packet routing): LobbyService уже реализован
    // (EPIC-2.x) — здесь только сборка зависимостей и подключение к
    // router'у, никакой новой lobby-логики.
    $worker->roomManager  = new RoomManager($worker->logger);
    $worker->lobbyService = new LobbyService($worker->roomManager, $worker->logger);
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
    $worker->gameService = new GameService(
        $worker->db,
        $statements,
        $lottoEngine,
        $worker->logger,
        $victoryService,
        $apartmentService,
        $gameFinishService
    );
    $worker->gameHandler = new GameHandler($worker->gameService);

    // ReconnectService (EPIC-8.0) — конструктор требует LobbyService И
    // GameService одновременно; оба теперь собраны (EPIC-10.4 + EPIC-10.5
    // выше), поэтому подключение стало возможно только сейчас, не раньше
    // (см. комментарий у $worker->onClose и Rule 11 Epic Isolation).
    $worker->reconnectService = new ReconnectService(
        $worker->lobbyService,
        $worker->gameService,
        $worker->logger
    );

    // Runtime-память (ANCHOR_CORE.md § Runtime Memory Layout / Worker Storage)
    $worker->rooms           = [];
    $worker->userConnections = [];
    $worker->sessionTokens   = [];

    $worker->logger->info('LottoGameServer started (protocol_version=' . Constants::PROTOCOL_VERSION . ')');

    // Global Watchdog Timer (ANCHOR_CORE.md Part 5 § Global Watchdog Timer)
    // Owner: server. Count: 1 для всего процесса. Interval: 60s.
    // Закрывает мёртвые соединения по порогам AUTHORIZED/UNAUTHORIZED_TIMEOUT.
    // Создан в onWorkerStart, уничтожается вместе с процессом воркера —
    // отдельного Timer::del() не требуется (Worker shutdown = timer stop).
    Timer::add(60, function () use ($worker): void {
        $now = time();

        foreach ($worker->connections as $connection) {
            $lastPing = $connection->lastPing ?? $now;
            $isAuthorized = !empty($connection->userId);

            $threshold = $isAuthorized
                ? Constants::AUTHORIZED_TIMEOUT
                : Constants::UNAUTHORIZED_TIMEOUT;

            if (($now - $lastPing) > $threshold) {
                $worker->logger->info(
                    "Watchdog: closing dead connection (userId=" .
                    ($connection->userId ?? 'null') . ", idle=" . ($now - $lastPing) . "s)"
                );
                $connection->close();
            }
        }
    });
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
// -----------------------------------------------------------------------

$worker->onWebSocketConnected = function ($connection) use ($worker): void {
    if (count($worker->connections) > Constants::MAX_TOTAL_PLAYERS) {
        sendError($connection, 'error.server_full', 'Server is full');
        closeWithCode($connection, 4001, 'server_full');
        return;
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

    sendJson($connection, [
        'type'             => 'hello',
        'protocol_version' => Constants::PROTOCOL_VERSION,
    ]);
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
        $worker->logger->info(
            'Rate limit exceeded, closing connection (userId=' .
            ($connection->userId ?? 'null') . ", count={$connection->packetCount})"
        );
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
        // ANCHOR_PROTOCOL.md § Heartbeat: "No response required".
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
        if (is_string($token) && $token !== '') {
            $worker->reconnectService->handleReconnect($token, $connection, $worker);
        }
        return;
    }

    // Диспетчер: auth (EPIC-10.3), lobby (EPIC-10.4) и game (EPIC-10.5)
    // подключены. reconnect обработан отдельно выше. Остаётся:
    //   admin_*   → EPIC-10.6 (AdminHandler — создать)
    match ($action) {
        'register'         => $worker->authHandler->handleRegister($data, $connection, $worker),
        'login'            => $worker->authHandler->handleLogin($data, $connection, $worker),
        'room_list'        => $worker->lobbyHandler->handleRoomList($connection, $worker),
        'create_room'      => $worker->lobbyHandler->handleCreateRoom($data, $connection, $worker),
        'join_room'        => $worker->lobbyHandler->handleJoinRoom($data, $connection, $worker),
        'leave_room'       => $worker->lobbyHandler->handleLeaveRoom($connection, $worker),
        'start_game'       => $worker->gameHandler->handleStartGame($connection, $worker),
        'draw_barrel'      => $worker->gameHandler->handleDrawBarrel($connection, $worker),
        'apartment_choice' => $worker->gameHandler->handleApartmentChoice($data, $connection, $worker),
        default            => sendError($connection, 'error.invalid_json', "Unknown or not-yet-wired action: {$action}"),
    };
};

// -----------------------------------------------------------------------
// onClose — EPIC-10.5: делегирует ReconnectService::handleDisconnect().
// Оба зависимых сервиса ReconnectService (LobbyService, GameService)
// теперь собраны в onWorkerStart, поэтому подключение реальной бизнес-
// логики (вместо диагностической заглушки) стало возможным.
// -----------------------------------------------------------------------

$worker->onClose = function ($connection) use ($worker): void {
    $worker->logger->info(
        'Connection closed (userId=' . ($connection->userId ?? 'null') . ')'
    );
    $worker->reconnectService->handleDisconnect($connection, $worker);
};

Worker::runAll();
