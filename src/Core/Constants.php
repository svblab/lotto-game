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

    /** Game AFK strike thresholds — cumulative inactivity (ReconnectService::tickGameAfk). */
    public const GAME_AFK_STRIKE1_SECONDS = 30;
    public const GAME_AFK_STRIKE2_SECONDS = 45;
    public const GAME_AFK_STRIKE3_SECONDS = 50;

    /** @deprecated Use GAME_AFK_STRIKE1_SECONDS */
    public const GAME_AFK_WARN1_SECONDS = self::GAME_AFK_STRIKE1_SECONDS;
    /** @deprecated Use GAME_AFK_STRIKE2_SECONDS */
    public const GAME_AFK_WARN2_SECONDS = self::GAME_AFK_STRIKE2_SECONDS;
    /** @deprecated Use GAME_AFK_STRIKE3_SECONDS */
    public const GAME_AFK_AUTO_SECONDS = self::GAME_AFK_STRIKE3_SECONDS;

    /** Repeat interval for lobby/game AFK polling timers. */
    public const AFK_TICK_INTERVAL = 1;

    public const PROTOCOL_VERSION = 1;

    // ADR-003: Rate Limiting
    public const RATE_LIMIT_PACKETS_PER_WINDOW = 15;
    public const RATE_LIMIT_WINDOW_SECONDS = 1;

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

    public static function gameAfkStrike1Seconds(): int
    {
        return self::envInt('LOTTO_GAME_AFK_STRIKE1', self::envInt('LOTTO_GAME_AFK_WARN1', self::GAME_AFK_STRIKE1_SECONDS));
    }

    public static function gameAfkStrike2Seconds(): int
    {
        return self::envInt('LOTTO_GAME_AFK_STRIKE2', self::envInt('LOTTO_GAME_AFK_WARN2', self::GAME_AFK_STRIKE2_SECONDS));
    }

    public static function gameAfkStrike3Seconds(): int
    {
        return self::envInt('LOTTO_GAME_AFK_STRIKE3', self::envInt('LOTTO_GAME_AFK_AUTO', self::GAME_AFK_STRIKE3_SECONDS));
    }

    /** @deprecated Use gameAfkStrike1Seconds() */
    public static function gameAfkWarn1Seconds(): int
    {
        return self::gameAfkStrike1Seconds();
    }

    /** @deprecated Use gameAfkStrike2Seconds() */
    public static function gameAfkWarn2Seconds(): int
    {
        return self::gameAfkStrike2Seconds();
    }

    /** @deprecated Use gameAfkStrike3Seconds() */
    public static function gameAfkAutoSeconds(): int
    {
        return self::gameAfkStrike3Seconds();
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
