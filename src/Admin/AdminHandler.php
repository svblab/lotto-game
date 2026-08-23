<?php

declare(strict_types=1);

namespace Lotto\Admin;

/**
 * AdminHandler — EPIC-10.6
 *
 * Обрабатывает WebSocket-пакеты администрирования: admin_ban_user,
 * admin_unban_user, admin_kick_user, admin_close_room, admin_get_logs,
 * admin_get_stats.
 * Транслирует входящие пакеты ANCHOR_PROTOCOL.md в вызовы AdminService
 * (Phase 9 — бизнес-логика уже реализована и покрыта тестами). Никакой
 * новой бизнес-логики здесь нет — только маршрутизация, тот же паттерн,
 * что GameHandler (EPIC-10.5) и LobbyHandler (EPIC-10.4).
 *
 * AdminService::assertAdmin() (уже реализованный guard: auth_required
 * для неаутентифицированных, not_your_turn для не-админов) вызывается
 * ВНУТРИ каждого handleXxx() метода AdminService — AdminHandler не
 * дублирует эту проверку.
 *
 * Обратить внимание: не все методы AdminService принимают $worker —
 * handleUnbanUser() и handleGetLogs() работают только с $data/$connection
 * (unban — чистая DB-операция без затрагивания runtime-комнат; logs — не
 * связаны ни с одной комнатой). AdminHandler сохраняет эти сигнатуры как
 * есть, не унифицируя искусственно (Rule 6 Hidden Refactoring Prohibition
 * запрещает менять уже принятые сигнатуры без необходимости).
 */
final class AdminHandler
{
    private AdminService $adminService;
    private AdminSettingsService $adminSettings;

    public function __construct(AdminService $adminService, AdminSettingsService $adminSettings)
    {
        $this->adminService = $adminService;
        $this->adminSettings = $adminSettings;
    }

    /**
     * {"action": "admin_ban_user", "user_id": 15, "duration": "1d"|"3d"|"permanent"}
     */
    public function handleBanUser(array $data, object $connection, object $worker): void
    {
        $this->adminService->handleBanUser($data, $connection, $worker);
    }

    /**
     * {"action": "admin_unban_user", "user_id": 15}
     */
    public function handleUnbanUser(array $data, object $connection): void
    {
        $this->adminService->handleUnbanUser($data, $connection);
    }

    /**
     * {"action": "admin_kick_user", "user_id": 15}
     */
    public function handleKickUser(array $data, object $connection, object $worker): void
    {
        $this->adminService->handleKickUser($data, $connection, $worker);
    }

    /**
     * {"action": "admin_close_room", "room_id": 7}
     */
    public function handleCloseRoom(array $data, object $connection, object $worker): void
    {
        $this->adminService->handleCloseRoom($data, $connection, $worker);
    }

    /**
     * {"action": "admin_get_users", "search": "alice", "online_only": false, "banned_only": false}
     */
    public function handleGetUsers(array $data, object $connection, object $worker): void
    {
        $this->adminService->handleGetUsers($data, $connection, $worker);
    }

    /**
     * {"action": "admin_get_logs"}
     */
    public function handleGetLogs(array $data, object $connection): void
    {
        $this->adminService->handleGetLogs($data, $connection);
    }

    /**
     * {"action": "admin_get_stats"}
     */
    public function handleGetStats(array $data, object $connection, object $worker): void
    {
        $this->adminService->handleGetStats($data, $connection, $worker);
    }

    /**
     * {"action": "admin_get_settings"}
     */
    public function handleGetSettings(array $data, object $connection, object $worker): void
    {
        $this->adminSettings->handleGetSettings($data, $connection, $worker);
    }

    /**
     * {"action": "admin_set_settings", ...}
     */
    public function handleSetSettings(array $data, object $connection, object $worker): void
    {
        $this->adminSettings->handleSetSettings($data, $connection, $worker);
    }

    /**
     * {"action": "admin_restart_server"}
     */
    public function handleRestartServer(array $data, object $connection, object $worker): void
    {
        $this->adminSettings->handleRestartServer($data, $connection, $worker);
    }

    /**
     * ADR-033: {"action": "admin_change_password", "current_password": "...", "new_password": "..."}
     */
    public function handleChangePassword(array $data, object $connection): void
    {
        $this->adminService->handleChangePassword($data, $connection);
    }
}
