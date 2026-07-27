<?php

namespace Lotto\Core;

/**
 * EPIC-11.1 — Memory audit instrumentation.
 *
 * Opt-in via environment variable LOTTO_MEMORY_AUDIT=1.
 * Writes structured snapshots to logs/memory_audit.log (separate from
 * server.log to keep production logs readable).
 *
 * Optional log path override (mirrors FIX-12 Logger DI-seam):
 *   - constructor ?string $logFilePath
 *   - env LOTTO_MEMORY_AUDIT_LOG (used by server.php when set)
 *
 * Verbose per-packet logging: LOTTO_MEMORY_AUDIT_VERBOSE=1 (logs every
 * handled action in server.php onMessage; off by default).
 */
final class MemoryAudit
{
    private const DEFAULT_LOG_FILENAME = 'memory_audit.log';

    /** Actions that mutate runtime maps — always logged when audit is on. */
    private const TRACKED_ACTIONS = [
        'register',
        'login',
        'reconnect',
        'create_room',
        'join_room',
        'leave_room',
        'start_game',
        'draw_barrel',
        'apartment_choice',
        'admin_close_room',
        'admin_kick_user',
        'admin_ban_user',
    ];

    private Logger $logger;
    private string $logFile;

    public function __construct(Logger $logger, ?string $logFilePath = null)
    {
        $this->logger = $logger;
        $this->logFile = self::resolveLogPath($logFilePath);
    }

    public static function resolveLogPath(?string $logFilePath = null): string
    {
        if ($logFilePath !== null && $logFilePath !== '') {
            return $logFilePath;
        }

        $envPath = getenv('LOTTO_MEMORY_AUDIT_LOG');
        if (is_string($envPath) && $envPath !== '') {
            return $envPath;
        }

        return dirname(__DIR__, 2) . '/logs/' . self::DEFAULT_LOG_FILENAME;
    }

    public static function isEnabled(): bool
    {
        $flag = getenv('LOTTO_MEMORY_AUDIT');
        return $flag === '1' || $flag === 'true';
    }

    public static function isVerbose(): bool
    {
        $flag = getenv('LOTTO_MEMORY_AUDIT_VERBOSE');
        return $flag === '1' || $flag === 'true';
    }

    public static function shouldLogAction(string $action): bool
    {
        if (!self::isEnabled()) {
            return false;
        }
        if (self::isVerbose()) {
            return true;
        }
        return in_array($action, self::TRACKED_ACTIONS, true);
    }

    /**
     * Record a memory snapshot. No-op when audit is disabled.
     */
    public function snapshot(string $event, ?object $worker = null, array $context = []): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $memBytes = memory_get_usage(true);
        $peakBytes = memory_get_peak_usage(true);

        $parts = [
            'event=' . $event,
            'mem_mb=' . self::formatMb($memBytes),
            'peak_mb=' . self::formatMb($peakBytes),
        ];

        foreach (self::workerContext($worker) as $key => $value) {
            $parts[] = "{$key}={$value}";
        }

        foreach ($context as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $parts[] = "{$key}=" . (is_scalar($value) ? $value : json_encode($value));
        }

        $this->writeLine(implode(' ', $parts));
    }

    /**
     * Format bytes as megabytes with two decimal places (for tests/reports).
     */
    public static function formatMb(int $bytes): string
    {
        return number_format($bytes / (1024 * 1024), 2, '.', '');
    }

    /**
     * @return array{mem_bytes: int, peak_bytes: int, rooms: int, connections: int, user_connections: int, session_tokens: int}
     */
    public static function collect(?object $worker = null): array
    {
        $ctx = self::workerContext($worker);

        return [
            'mem_bytes'        => memory_get_usage(true),
            'peak_bytes'       => memory_get_peak_usage(true),
            'rooms'            => $ctx['rooms'],
            'connections'      => $ctx['connections'],
            'user_connections' => $ctx['user_connections'],
            'session_tokens'   => $ctx['session_tokens'],
        ];
    }

    /**
     * @return array{rooms: int, connections: int, user_connections: int, session_tokens: int}
     */
    private static function workerContext(?object $worker): array
    {
        if ($worker === null) {
            return [
                'rooms'            => 0,
                'connections'      => 0,
                'user_connections' => 0,
                'session_tokens'   => 0,
            ];
        }

        return [
            'rooms'            => count($worker->rooms ?? []),
            'connections'      => count($worker->connections ?? []),
            'user_connections' => count($worker->userConnections ?? []),
            'session_tokens'   => count($worker->sessionTokens ?? []),
        ];
    }

    private function writeLine(string $message): void
    {
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $line = '[' . date('Y-m-d H:i:s') . '] [MEMORY] ' . $message . PHP_EOL;
        $result = file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);

        if ($result === false) {
            $this->logger->error('MemoryAudit: failed to write to log file ' . $this->logFile);
        }
    }
}
