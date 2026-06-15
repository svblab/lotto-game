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
     */
    public function __construct()
    {
        $dbPath = dirname(__DIR__, 2) . '/game.db';
        
        $this->pdo = new PDO("sqlite:" . $dbPath, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // Настройки из манифеста EPIC-0.6
        $this->pdo->exec("PRAGMA foreign_keys = ON;");
        $this->pdo->exec("PRAGMA journal_mode = WAL;");
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

