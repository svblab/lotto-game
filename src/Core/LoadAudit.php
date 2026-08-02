<?php

declare(strict_types=1);

namespace Lotto\Core;

/**
 * EPIC-11.6 — Load test audit instrumentation.
 *
 * Opt-in via LOTTO_LOAD_AUDIT=1. Writes structured events to
 * logs/load_audit.log (server-side handler latency + periodic snapshots).
 *
 * Optional log path override:
 *   - env LOTTO_LOAD_AUDIT_LOG
 */
final class LoadAudit
{
    private const DEFAULT_LOG_FILENAME = 'load_audit.log';

    /** Actions used for p95 acceptance (auth + typical game mark/draw). */
    public const TRACKED_ACTIONS = [
        'register',
        'login',
        'room_list',
        'create_room',
        'join_room',
        'start_game',
        'draw_barrel',
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

        $envPath = getenv('LOTTO_LOAD_AUDIT_LOG');
        if (is_string($envPath) && $envPath !== '') {
            return $envPath;
        }

        return dirname(__DIR__, 2) . '/logs/' . self::DEFAULT_LOG_FILENAME;
    }

    public static function isEnabled(): bool
    {
        $flag = getenv('LOTTO_LOAD_AUDIT');
        return $flag === '1' || $flag === 'true';
    }

    public function recordLatency(string $action, float $latencyMs, ?object $worker = null, array $context = []): void
    {
        if (!self::isEnabled()) {
            return;
        }

        if (!in_array($action, self::TRACKED_ACTIONS, true)) {
            return;
        }

        $parts = [
            'event=latency',
            'action=' . $action,
            'latency_ms=' . self::formatMs($latencyMs),
            'ts_us=' . (string) hrtime(true),
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

    public function snapshot(string $event, ?object $worker = null, array $context = []): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $memBytes = memory_get_usage(true);
        $peakBytes = memory_get_peak_usage(true);

        $parts = [
            'event=snapshot',
            'label=' . $event,
            'mem_mb=' . MemoryAudit::formatMb($memBytes),
            'peak_mb=' . MemoryAudit::formatMb($peakBytes),
            'ts_us=' . (string) hrtime(true),
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

    public static function formatMs(float $ms): string
    {
        return number_format($ms, 2, '.', '');
    }

    /**
     * @return array<string, array{count: int, p50: float, p95: float, p99: float, max: float}>
     */
    public static function parseLatencyStats(string $logPath): array
    {
        $byAction = [];

        if (!is_file($logPath)) {
            return $byAction;
        }

        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return $byAction;
        }

        foreach ($lines as $line) {
            if (!preg_match('/\[LOAD\]\s+(.+)$/', $line, $m)) {
                continue;
            }

            $fields = self::parseFields($m[1]);
            if (($fields['event'] ?? '') !== 'latency' || !isset($fields['action'], $fields['latency_ms'])) {
                continue;
            }

            $action = $fields['action'];
            $byAction[$action] ??= [];
            $byAction[$action][] = (float) $fields['latency_ms'];
        }

        $stats = [];
        foreach ($byAction as $action => $values) {
            $stats[$action] = self::summarizeLatencies($values);
        }

        return $stats;
    }

    /**
     * @return array{peak_mem_mb: float, peak_connections: int, peak_rooms: int, snapshots: int}
     */
    public static function parseSnapshotStats(string $logPath): array
    {
        $result = [
            'peak_mem_mb'      => 0.0,
            'peak_connections' => 0,
            'peak_rooms'       => 0,
            'snapshots'        => 0,
        ];

        if (!is_file($logPath)) {
            return $result;
        }

        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return $result;
        }

        foreach ($lines as $line) {
            if (!preg_match('/\[LOAD\]\s+(.+)$/', $line, $m)) {
                continue;
            }

            $fields = self::parseFields($m[1]);
            if (($fields['event'] ?? '') !== 'snapshot') {
                continue;
            }

            $result['snapshots']++;
            if (isset($fields['mem_mb'])) {
                $result['peak_mem_mb'] = max($result['peak_mem_mb'], (float) $fields['mem_mb']);
            }
            if (isset($fields['connections'])) {
                $result['peak_connections'] = max($result['peak_connections'], (int) $fields['connections']);
            }
            if (isset($fields['rooms'])) {
                $result['peak_rooms'] = max($result['peak_rooms'], (int) $fields['rooms']);
            }
        }

        return $result;
    }

    /**
     * @param array<float> $values
     * @return array{count: int, p50: float, p95: float, p99: float, max: float}
     */
    public static function summarizeLatencies(array $values): array
    {
        if (count($values) === 0) {
            return ['count' => 0, 'p50' => 0.0, 'p95' => 0.0, 'p99' => 0.0, 'max' => 0.0];
        }

        sort($values);
        $count = count($values);

        return [
            'count' => $count,
            'p50'   => self::percentile($values, 50),
            'p95'   => self::percentile($values, 95),
            'p99'   => self::percentile($values, 99),
            'max'   => $values[$count - 1],
        ];
    }

    /**
     * @param array<float> $sortedValues
     */
    public static function percentile(array $sortedValues, float $p): float
    {
        $count = count($sortedValues);
        if ($count === 0) {
            return 0.0;
        }

        $index = (int) ceil(($p / 100) * $count) - 1;
        return $sortedValues[max(0, min($index, $count - 1))];
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

    /**
     * @return array<string, string>
     */
    private static function parseFields(string $payload): array
    {
        $fields = [];
        foreach (preg_split('/\s+/', trim($payload)) as $pair) {
            if (!str_contains($pair, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $pair, 2);
            $fields[$k] = $v;
        }

        return $fields;
    }

    private function writeLine(string $message): void
    {
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $line = '[' . date('Y-m-d H:i:s') . '] [LOAD] ' . $message . PHP_EOL;
        $result = file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);

        if ($result === false) {
            $this->logger->error('LoadAudit: failed to write to log file ' . $this->logFile);
        }
    }
}
