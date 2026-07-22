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
 * СОЗНАТЕЛЬНО НЕ РЕАЛИЗОВАНО В ЭТОМ EPIC (Rule 11 Epic Isolation —
 * "Auth+Lobby, Lobby+Game, Game+Admin... в одном Epic запрещены"):
 *   - Маршрутизация auth-пакетов (register/login/reconnect)  → EPIC-10.3
 *   - Маршрутизация lobby-пакетов (create_room/join_room/...) → EPIC-10.4
 *   - Маршрутизация game-пакетов (draw_barrel/apartment_choice) → EPIC-10.5
 *   - Маршрутизация admin-пакетов (admin_*)                   → EPIC-10.6
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
 * $worker->onClose ниже — намеренная заглушка с TODO: полноценная
 * обработка disconnect требует ReconnectService, который согласно его
 * собственному конструктору зависит одновременно от LobbyService И
 * GameService — то есть подключение onClose к реальной бизнес-логике
 * возможно только после EPIC-10.4/10.5, не раньше.
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

    // Диспетчер намеренно пуст — маршруты подключаются по мере готовности
    // соответствующих модулей:
    //   register/login/reconnect        → EPIC-10.3 (AuthHandler уже существует)
    //   create_room/join_room/...       → EPIC-10.4 (LobbyHandler — создать)
    //   draw_barrel/apartment_choice    → EPIC-10.5 (GameHandler — создать)
    //   admin_*                         → EPIC-10.6 (AdminHandler — создать)
    match ($action) {
        default => sendError($connection, 'error.invalid_json', "Unknown or not-yet-wired action: {$action}"),
    };
};

// -----------------------------------------------------------------------
// onClose — TODO(EPIC-10.4/10.5): делегировать ReconnectService::
// handleDisconnect($connection, $worker) после того, как LobbyService и
// GameService (зависимости ReconnectService) будут подключены. До тех
// пор — только диагностическое логирование, без бизнес-логики
// (Rule 15: reconnect-логика запрещена в server.php напрямую).
// -----------------------------------------------------------------------

$worker->onClose = function ($connection) use ($worker): void {
    $worker->logger->info(
        'Connection closed (userId=' . ($connection->userId ?? 'null') . ')'
    );
    // TODO(EPIC-10.4/10.5): ReconnectService::handleDisconnect($connection, $worker);
};

Worker::runAll();
