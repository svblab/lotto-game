<?php

namespace Lotto\Infrastructure;

use PDO;
use PDOStatement;
use InvalidArgumentException;

class PreparedStatements
{
    /**
     * Экземпляр нативного соединения базы данных.
     * @var PDO
     */
    private PDO $pdo;

    /**
     * Кэш скомпилированных выражений (состояний).
     * @var array<string, PDOStatement>
     */
    private array $statements = [];

    /**
     * Карта строго типизированных SQL-запросов проекта.
     * @var array<string, string>
     */
    private array $queries = [
        'user_by_username' => "SELECT id, username, password_hash, coins, is_admin, banned_until, last_daily_bonus FROM users WHERE username = ? LIMIT 1",
        
        'create_user' => "INSERT INTO users (username, password_hash, coins, is_admin, banned_until, last_daily_bonus) VALUES (?, ?, 500, 0, 0, 0)",
        
        'update_daily_bonus' => "UPDATE users SET coins = ?, last_daily_bonus = ? WHERE id = ?",
        
        'update_user_coins' => "UPDATE users SET coins = ? WHERE id = ?",
        
        'ban_user' => "UPDATE users SET banned_until = ? WHERE id = ?",
        
        'unban_user' => "UPDATE users SET banned_until = 0 WHERE id = ?"
    ];

    /**
     * Конструктор реестра выражений.
     * Принимает исключительно нативный экземпляр PDO (Принцип: Known dependencies only).
     *
     * @param PDO $pdo
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Получить кэшированное скомпилированное выражение по строгому текстовому ключу.
     *
     * @param string $key
     * @return PDOStatement
     * @throws InvalidArgumentException Если передан неизвестный ключ запроса
     */
    public function get(string $key): PDOStatement
    {
        if (!isset($this->queries[$key])) {
            throw new InvalidArgumentException("Unknown prepared statement key: {$key}");
        }

        if (!isset($this->statements[$key])) {
            $this->statements[$key] = $this->pdo->prepare($this->queries[$key]);
        }

        return $this->statements[$key];
    }
}
