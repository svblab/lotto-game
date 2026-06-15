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

    public const DAILY_BONUS = 100;

    public const RECONNECT_TIMEOUT = 15;

    public const LOBBY_HOST_TIMEOUT = 120;

    public const UNAUTHORIZED_TIMEOUT = 60;

    public const AUTHORIZED_TIMEOUT = 120;

    public const PROTOCOL_VERSION = 1;
}
