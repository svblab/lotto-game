<?php

declare(strict_types=1);

namespace Lotto\Core;

use Workerman\Worker;

/**
 * Workerman Worker with declared runtime fields.
 *
 * Application code used to assign these as dynamic properties on
 * Workerman\Worker (deprecated in PHP 8.2, removed in PHP 9).
 */
final class LottoWorker extends Worker
{
    public ?object $db = null;
    public ?object $logger = null;
    public ?object $loginThrottle = null;
    public ?object $sessionGuard = null;
    public ?object $ipAccountLimit = null;
    public ?object $authHandler = null;
    public ?object $roomManager = null;
    public ?object $lobbyService = null;
    public ?object $lobbyHandler = null;
    public ?object $gameService = null;
    public ?object $gameHandler = null;
    public ?object $reconnectService = null;
    public ?object $adminHandler = null;
    public ?object $chatService = null;
    public ?object $fileTransferService = null;
    public ?object $chatHandler = null;
    public ?object $memoryAudit = null;
    public ?object $timerAudit = null;
    public ?object $economyAudit = null;
    public ?object $stateAudit = null;
    public ?object $loadAudit = null;

    /** @var array<int, array<string, mixed>> */
    public array $rooms = [];

    /** @var array<int, object> user_id → connection */
    public array $userConnections = [];

    /** @var array<string, int> token → user_id */
    public array $sessionTokens = [];

    /** @var array<int, int> user_id → consecutive wins vs bot */
    public array $botWinStreaks = [];

    /** @var array{max_accounts_per_ip:int,bet_per_card:int,apartment_payment:int}|null */
    public ?array $serverSettings = null;

    /** @var list<string>|null */
    public ?array $allowedOrigins = null;
}
