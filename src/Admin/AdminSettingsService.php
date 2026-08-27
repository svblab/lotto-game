<?php

declare(strict_types=1);

namespace Lotto\Admin;

use Lotto\Core\MemoryAudit;
use Lotto\Core\ServerRuntimeSettings;

use function Lotto\Core\sendError;
use function Lotto\Core\sendJson;

/**
 * Admin runtime settings and emergency server restart (CLI script).
 */
final class AdminSettingsService
{
    private AdminService $adminService;
    private ?object $logger;

    public function __construct(AdminService $adminService, ?object $logger = null)
    {
        $this->adminService = $adminService;
        $this->logger = $logger;
    }

    /**
     * {"action":"admin_get_settings"}
     */
    public function handleGetSettings(array $data, object $connection, object $worker): void
    {
        if (!$this->adminService->assertAdmin($connection)) {
            return;
        }

        $stats = MemoryAudit::collect($worker);

        sendJson($connection, [
            'type'                => 'admin_settings_data',
            'online'              => $stats['user_connections'],
            'memory_mb'           => (int) round($stats['mem_bytes'] / (1024 * 1024)),
            'max_accounts_per_ip' => ServerRuntimeSettings::snapshot($worker)['max_accounts_per_ip'],
            'bet_per_card'        => ServerRuntimeSettings::snapshot($worker)['bet_per_card'],
            'apartment_payment'   => ServerRuntimeSettings::snapshot($worker)['apartment_payment'],
            'restart_supported'   => self::isHostRestartSupported(),
        ]);
    }

    /**
     * {"action":"admin_set_settings","max_accounts_per_ip":3,"bet_per_card":10,"apartment_payment":5}
     */
    public function handleSetSettings(array $data, object $connection, object $worker): void
    {
        if (!$this->adminService->assertAdmin($connection)) {
            return;
        }

        $error = ServerRuntimeSettings::apply($worker, $data);
        if ($error !== null) {
            sendError($connection, 'error.invalid_json', $error);
            return;
        }

        if ($this->logger !== null) {
            $snap = ServerRuntimeSettings::snapshot($worker);
            $this->logger->info(
                'Admin user_id=' . (int) ($connection->userId ?? 0)
                . ' updated server settings: max_accounts_per_ip=' . $snap['max_accounts_per_ip']
                . ' bet_per_card=' . $snap['bet_per_card']
                . ' apartment_payment=' . $snap['apartment_payment']
            );
        }

        $this->handleGetSettings($data, $connection, $worker);
    }

    /**
     * Emergency restart uses admin_emergency_control.sh (bash + systemd/pgrep).
     * Windows has no supported host path — callers get an explicit error.
     */
    public static function isHostRestartSupported(): bool
    {
        return DIRECTORY_SEPARATOR !== '\\';
    }

    /**
     * {"action":"admin_restart_server"}
     */
    public function handleRestartServer(array $data, object $connection, object $worker): void
    {
        if (!$this->adminService->assertAdmin($connection)) {
            return;
        }

        if (!self::isHostRestartSupported()) {
            sendJson($connection, [
                'type'    => 'admin_restart_result',
                'success' => false,
                'message' => 'Server restart from the admin panel is not supported on Windows. Use php scripts/start_server.php restart.',
            ]);
            return;
        }

        $projectRoot = dirname(__DIR__, 2);
        $script = $projectRoot . DIRECTORY_SEPARATOR . 'admin_emergency_control.sh';

        if (!is_file($script)) {
            sendJson($connection, [
                'type'    => 'admin_restart_result',
                'success' => false,
                'message' => 'admin_emergency_control.sh not found',
            ]);
            return;
        }

        if ($this->logger !== null) {
            $this->logger->warning(
                'Admin user_id=' . (int) ($connection->userId ?? 0) . ' initiated server restart'
            );
        }

        sendJson($connection, [
            'type'    => 'admin_restart_result',
            'success' => true,
            'message' => 'Server restart initiated',
        ]);

        $logFile = $projectRoot . '/logs/admin_control.log';
        $cmd = 'nohup bash ' . escapeshellarg($script) . ' restart >> '
            . escapeshellarg($logFile) . ' 2>&1 &';
        exec($cmd);
    }
}
