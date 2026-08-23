<?php

namespace Lotto\Core;

use Exception;

/**
 * Сервис логирования.
 * Все серверные сообщения записываются через этот класс.
 */
class Logger
{
    private const ALLOWED_LEVELS = ['INFO', 'WARNING', 'ERROR'];

    private string $logFile;

    /**
     * FIX-12: optional $logFilePath injection point, mirroring the FIX-4
     * precedent for Database::__construct(?PDO $pdo = null). Before this
     * fix, every `new Logger()` call — including ones made deep inside
     * test fixtures that otherwise correctly isolate themselves via
     * in-memory SQLite (e.g. tests/Manual/test_victory.php's makeSvc(),
     * which builds a real GameFinishService for GROUP 4/5/6) — wrote
     * straight into the production logs/server.log path with zero way to
     * redirect it. This caused real operational confusion on a live
     * deployment: a deliberately-rigged CHECK-constraint failure from an
     * isolated in-memory test database ended up logged into the actual
     * production log file, indistinguishable from a real game event.
     * Default (no argument) preserves the exact prior behavior — this is
     * purely additive, server.php's own `new Logger()` call needs no
     * change at all.
     */
    public function __construct(?string $logFilePath = null)
    {
        if ($logFilePath !== null) {
            $logDir = dirname($logFilePath);
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            $this->logFile = $logFilePath;
            return;
        }

        $logDir = dirname(__DIR__, 2) . '/logs';

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $this->logFile = $logDir . '/server.log';
    }

    /**
     * Default production log path (for tests verifying Logger wiring without writing).
     */
    public static function defaultLogPath(): string
    {
        return dirname(__DIR__, 2) . '/logs/server.log';
    }

    public function write(string $level, string $message): void
    {
        if (!in_array($level, self::ALLOWED_LEVELS, true)) {
            throw new Exception("Logger: invalid log level '{$level}'");
        }

        $timestamp = date('Y-m-d H:i:s');
        $line = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;

        $result = file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);

        if ($result === false) {
            throw new Exception("Logger: failed to write to log file '{$this->logFile}'");
        }
    }

    public function info(string $message): void
    {
        $this->write('INFO', $message);
    }

    public function warning(string $message): void
    {
        $this->write('WARNING', $message);
    }

    public function error(string $message): void
    {
        $this->write('ERROR', $message);
    }

    /**
     * Возвращает последние строки server.log.
     *
     * Используется административной подсистемой (EPIC-9.5).
     * При отсутствии или недоступности файла возвращает пустой массив.
     */
    public function getLastLines(int $limit = 100): array
    {
        if ($limit <= 0) {
            return [];
        }

        if (!is_file($this->logFile) || !is_readable($this->logFile)) {
            return [];
        }

        $lines = file(
            $this->logFile,
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
        );

        if ($lines === false) {
            return [];
        }

        return array_slice($lines, -$limit);
    }

    /**
     * Lines from server.log within the last N seconds (parsed timestamp prefix).
     */
    public function getLinesSinceSeconds(int $secondsAgo): array
    {
        if ($secondsAgo <= 0) {
            return [];
        }

        if (!is_file($this->logFile) || !is_readable($this->logFile)) {
            return [];
        }

        $cutoff = time() - $secondsAgo;
        $lines = file(
            $this->logFile,
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
        );

        if ($lines === false) {
            return [];
        }

        $out = [];
        foreach ($lines as $line) {
            if (!is_string($line) || $line === '') {
                continue;
            }
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $m)) {
                $ts = strtotime($m[1]);
                if ($ts !== false && $ts >= $cutoff) {
                    $out[] = $line;
                }
            }
        }

        return $out;
    }
}