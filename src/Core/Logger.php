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

    public function __construct()
    {
        $logDir = dirname(__DIR__, 2) . '/logs';

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $this->logFile = $logDir . '/server.log';
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
}
