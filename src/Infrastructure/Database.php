<?php

namespace Lotto\Infrastructure;

use PDO;
use Exception;

class Database
{
    /**
     * @var PDO
     */
    private PDO $pdo;

    /**
     * Конструктор инициализирует соединение с базой данных SQLite
     * со строгими настройками, зафиксированными в EPIC-0.6.
     *
     * FIX-4: опциональный параметр $pdo — точка внедрения зависимости для
     * тестов (in-memory SQLite), без которой GameFinishService (ADR-002,
     * строгая типизация Database) невозможно честно сконструировать в
     * тестах без reflection (запрещённого ANCHOR_RULES.md Part 22).
     * Без аргумента поведение полностью идентично прежнему — открывается
     * game.db с теми же PRAGMA. На момент этого фикса `new Database()`
     * нигде в проекте не вызывается напрямую (server.php/init_db.php ещё
     * не реализованы — Phase 10), обратная совместимость не нарушена.
     */
    public function __construct(?PDO $pdo = null)
    {
        if ($pdo !== null) {
            $this->pdo = $pdo;
            return;
        }

        $runtime = $GLOBALS['__lotto_runtime_config']['LOTTO_DB_PATH'] ?? null;
        if (!is_string($runtime) || $runtime === '') {
            $runtime = null;
        }

        $envPath = $runtime ?? (
            (isset($_ENV['LOTTO_DB_PATH']) && is_string($_ENV['LOTTO_DB_PATH']) && $_ENV['LOTTO_DB_PATH'] !== '')
                ? $_ENV['LOTTO_DB_PATH']
                : getenv('LOTTO_DB_PATH')
        );
        $dbPath = (is_string($envPath) && $envPath !== '')
            ? $envPath
            : dirname(__DIR__, 2) . '/game.db';
        
        $this->pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // Настройки из манифеста EPIC-0.6
        $this->pdo->exec("PRAGMA foreign_keys = ON;");
        $this->pdo->exec("PRAGMA journal_mode = WAL;");
        $this->pdo->exec("PRAGMA busy_timeout = 5000;");
    }

    /**
     * КРИТИЧЕСКИЙ КОНТРАКТ: Возвращает нативный экземпляр соединения PDO.
     * Категорически запрещено удалять или переименовывать этот метод.
     *
     * @return PDO
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Проверка активности соединения.
     *
     * @return bool
     */
    public function ping(): bool
    {
        try {
            return $this->pdo->query("SELECT 1") !== false;
        } catch (Exception $e) {
            return false;
        }
    }
}

