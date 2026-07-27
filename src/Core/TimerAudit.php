<?php

declare(strict_types=1);

namespace Lotto\Core;

/**
 * EPIC-11.2 — Timer audit instrumentation.
 *
 * Opt-in via LOTTO_TIMER_AUDIT=1. Writes structured events to
 * logs/timer_audit.log (microsecond timestamps for drift analysis).
 *
 * Optional log path override:
 *   - env LOTTO_TIMER_AUDIT_LOG
 */
final class TimerAudit
{
    private const DEFAULT_LOG_FILENAME = 'timer_audit.log';

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

        $envPath = getenv('LOTTO_TIMER_AUDIT_LOG');
        if (is_string($envPath) && $envPath !== '') {
            return $envPath;
        }

        return dirname(__DIR__, 2) . '/logs/' . self::DEFAULT_LOG_FILENAME;
    }

    public static function isEnabled(): bool
    {
        $flag = getenv('LOTTO_TIMER_AUDIT');
        return $flag === '1' || $flag === 'true';
    }

    public function recordAdd(string $label, int $timerId, float $interval, array $context = []): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $this->writeEvent('add', $label, $timerId, $context + [
            'interval_s' => self::formatInterval($interval),
        ]);
    }

    public function recordDel(string $label, int $timerId, array $context = []): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $this->writeEvent('del', $label, $timerId, $context);
    }

    public function recordFire(string $label, int $timerId, array $context = []): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $this->writeEvent('fire', $label, $timerId, $context);
    }

    public static function formatInterval(float $seconds): string
    {
        return number_format($seconds, 3, '.', '');
    }

    /**
     * @return array{active: int, adds: int, dels: int, fires: int}
     */
    public static function parseLogStats(string $logPath): array
    {
        $stats = ['active' => 0, 'adds' => 0, 'dels' => 0, 'fires' => 0];
        if (!is_file($logPath)) {
            return $stats;
        }

        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return $stats;
        }

        $active = [];
        foreach ($lines as $line) {
            if (!preg_match('/\[TIMER\]\s+event=(\w+)\s+label=([^\s]+)\s+timer_id=(\d+)/', $line, $m)) {
                continue;
            }
            $event = $m[1];
            $timerId = (int) $m[3];
            if ($event === 'add') {
                $stats['adds']++;
                $active[$timerId] = true;
            } elseif ($event === 'del') {
                $stats['dels']++;
                unset($active[$timerId]);
            } elseif ($event === 'fire') {
                $stats['fires']++;
            }
        }

        $stats['active'] = count($active);
        return $stats;
    }

    private function writeEvent(string $event, string $label, int $timerId, array $context): void
    {
        $parts = [
            'event=' . $event,
            'label=' . $label,
            'timer_id=' . $timerId,
            'ts_us=' . (string) (int) (microtime(true) * 1_000_000),
        ];

        foreach ($context as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $parts[] = "{$key}=" . (is_scalar($value) ? $value : json_encode($value));
        }

        $this->writeLine(implode(' ', $parts));
    }

    private function writeLine(string $message): void
    {
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $line = '[' . date('Y-m-d H:i:s') . '] [TIMER] ' . $message . PHP_EOL;
        $result = file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);

        if ($result === false) {
            $this->logger->error('TimerAudit: failed to write to log file ' . $this->logFile);
        }
    }
}
