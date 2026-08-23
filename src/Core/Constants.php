<?php

namespace Lotto\Core;

/**
 * Глобальные константы проекта.
 * Единственный источник лимитов, таймаутов и базовых экономических значений.
 */
class Constants
{
    public const MAX_TOTAL_PLAYERS = 150;
    public const MAX_ROOMS = 30;

    public const BET_PER_CARD = 10;

    /** Coins added to bank when a non-immune player agrees during Apartment. */
    public const APARTMENT_PAYMENT = 5;

    /** Barrels drawn per active player's turn (GAME_RULES §3). */
    public const BARRELS_PER_TURN = 3;

    public const DAILY_BONUS = 100;

    public const RECONNECT_TIMEOUT = 15;

    public const LOBBY_HOST_TIMEOUT = 120;

    public const UNAUTHORIZED_TIMEOUT = 60;

    public const AUTHORIZED_TIMEOUT = 120;

    /** Global watchdog interval (server.php). */
    public const WATCHDOG_INTERVAL = 60;

    /** Apartment voting single-shot timeout (ApartmentService). */
    public const APARTMENT_TIMEOUT = 10;

    /** Strike 1 AFK window (auto_draws=0). */
    public const GAME_AFK_STRIKE1_SECONDS = 30;

    /** Strike 2 AFK window (auto_draws=1). */
    public const GAME_AFK_STRIKE2_SECONDS = 15;

    /** Strike 3 / removal AFK window (auto_draws=2). */
    public const GAME_AFK_STRIKE3_SECONDS = 5;

    /** Repeat interval for lobby/game AFK polling timers. */
    public const AFK_TICK_INTERVAL = 1;

    public const PROTOCOL_VERSION = 1;

    // ADR-003: Rate Limiting
    public const RATE_LIMIT_PACKETS_PER_WINDOW = 15;
    public const RATE_LIMIT_WINDOW_SECONDS = 1;

    // ADR-028 (EPIC-5a): per-username login lockout
    public const LOGIN_THROTTLE_MAX_ATTEMPTS = 5;
    public const LOGIN_THROTTLE_WINDOW_SECONDS = 300;
    public const LOGIN_THROTTLE_LOCKOUT_SECONDS = 900;

    // ADR-031: max distinct live authenticated user_ids per client IP bucket
    public const MAX_ACCOUNTS_PER_IP = 3;

    /**
     * Runtime cap for distinct live accounts per resolved client IP.
     * LOTTO_MAX_ACCOUNTS_PER_IP overrides when a positive integer; unset/invalid
     * keeps MAX_ACCOUNTS_PER_IP (3). Raise (e.g. 9999) to effectively disable.
     */
    public static function maxAccountsPerIp(): int
    {
        $raw = lottoRuntimeEnv('LOTTO_MAX_ACCOUNTS_PER_IP');
        if ($raw !== null && is_numeric($raw)) {
            $value = (int) $raw;
            if ($value > 0) {
                return $value;
            }
        }

        return self::MAX_ACCOUNTS_PER_IP;
    }

    public static function reconnectTimeout(): int
    {
        return self::envInt('LOTTO_RECONNECT_TIMEOUT', self::RECONNECT_TIMEOUT);
    }

    public static function lobbyHostTimeout(): int
    {
        return self::envInt('LOTTO_LOBBY_HOST_TIMEOUT', self::LOBBY_HOST_TIMEOUT);
    }

    public static function unauthorizedTimeout(): int
    {
        return self::envInt('LOTTO_UNAUTHORIZED_TIMEOUT', self::UNAUTHORIZED_TIMEOUT);
    }

    public static function authorizedTimeout(): int
    {
        return self::envInt('LOTTO_AUTHORIZED_TIMEOUT', self::AUTHORIZED_TIMEOUT);
    }

    public static function watchdogInterval(): int
    {
        return self::envInt('LOTTO_WATCHDOG_INTERVAL', self::WATCHDOG_INTERVAL);
    }

    public static function apartmentTimeout(): int
    {
        return self::envInt('LOTTO_APARTMENT_TIMEOUT', self::APARTMENT_TIMEOUT);
    }

    public static function gameAfkStrikeWindowSeconds(int $autoDraws): int
    {
        if ($autoDraws <= 0) {
            return self::envInt('LOTTO_GAME_AFK_STRIKE1', self::GAME_AFK_STRIKE1_SECONDS);
        }
        if ($autoDraws === 1) {
            return self::envInt('LOTTO_GAME_AFK_STRIKE2', self::GAME_AFK_STRIKE2_SECONDS);
        }

        return self::envInt('LOTTO_GAME_AFK_STRIKE3', self::GAME_AFK_STRIKE3_SECONDS);
    }

    public static function afkTickInterval(): float
    {
        $default = (float) self::AFK_TICK_INTERVAL;
        $env = getenv('LOTTO_AFK_TICK_INTERVAL');
        if ($env !== false && is_numeric($env) && (float) $env > 0) {
            return (float) $env;
        }

        return $default;
    }

    private static function envInt(string $key, int $default): int
    {
        $env = getenv($key);
        if ($env !== false && is_numeric($env)) {
            $value = (int) $env;
            if ($value > 0) {
                return $value;
            }
        }

        return $default;
    }
}
