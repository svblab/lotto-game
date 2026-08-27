<?php

declare(strict_types=1);

namespace Lotto\Game;

use Lotto\Core\Constants;
use Lotto\Core\ServerRuntimeSettings;
use Lotto\Core\Logger;
use Lotto\Infrastructure\Database;
use Lotto\Infrastructure\PreparedStatements;

use function Lotto\Core\lottoTimerDel;

use function Lotto\Core\lottoEconomyRecord;
use function Lotto\Core\lottoEconomyCheckInvariants;
use function Lotto\Core\lottoStateTransition;
use function Lotto\Core\lottoStateReject;
use function Lotto\Core\sendError;
use function Lotto\Core\broadcastToRoom;
use function Lotto\Core\sendJson;

/**
 * GameService — EPIC-4.0 / 4.1 / 4.2 / 4.3 / 4.4
 *
 * Отвечает за старт игры: покупку карт, инициализацию игры,
 * формирование банка, транзакционное списание монет и рассылку game_started.
 *
 * Контракт пакетов ANCHOR_PROTOCOL.md § Game Start:
 *   start_game   → Client → Server (только хост)
 *   game_started → Server → Room
 *
 * Экономика (ANCHOR_CORE.md Part 2):
 *   - BET_PER_CARD = 10.
 *   - Резервирование при входе: монеты НЕ списываются.
 *   - Списание в startGame(): all-or-nothing транзакция.
 *   - bank = sum(all total_paid).
 *
 * Состояние (ANCHOR_CORE.md Part 4):
 *   waiting → playing (start_game или play_vs_bot).
 *
 * Архитектура:
 *   - Генерация карт: LottoEngine::generateCard().
 *   - Генерация мешка: LottoEngine::generateBag().
 *   - БД: только через PreparedStatements / PDO.
 *   - Бизнес-логика: только здесь, не в server.php.
 */
final class GameService
{
    private object $db;
    private object $stmts;
    private LottoEngine $engine;
    private object $logger;
    private VictoryService $victory;
    private ApartmentService $apartment;
    private GameFinishService $finishService;
    private GameTurnService $turnService;
    private ?ReconnectService $reconnectService = null;

    public function __construct(
        object $db,
        object $stmts,
        LottoEngine $engine,
        object $logger,
        VictoryService $victory,
        ApartmentService $apartment,
        GameFinishService $finishService,
        GameTurnService $turnService
    ) {
        $this->db        = $db;
        $this->stmts     = $stmts;
        $this->engine    = $engine;
        $this->logger    = $logger;
        $this->victory   = $victory;
        $this->apartment = $apartment;
        $this->finishService = $finishService;
        $this->turnService = $turnService;
    }

    /**
     * Post-construction wiring (ADR-008): breaks circular dep with ReconnectService.
     */
    public function setReconnectService(ReconnectService $reconnectService): void
    {
        $this->reconnectService = $reconnectService;
    }

    // -------------------------------------------------------------------------
    // EPIC-4.0 / 4.1 / 4.2 / 4.3 / 4.4  handleStartGame
    // -------------------------------------------------------------------------

    /**
     * Обрабатывает пакет {"action": "start_game"}.
     *
     * Шаги:
     *   1. Auth guard.
     *   2. Найти комнату по conn_id хоста.
     *   3. Проверки: хост, статус waiting, минимум 2 игрока.
     *   4. EPIC-4.0 — вычислить total_paid для каждого игрока (cards_count * BET_PER_CARD).
     *   5. Проверить достаточность баланса у всех игроков.
     *   6. EPIC-4.3 — транзакционно списать монеты (all-or-nothing).
     *   7. EPIC-4.1 — инициализировать игру: bag, drawn_numbers, status=playing,
     *                  назначить карты, сбросить AFK-поля.
     *   8. EPIC-4.2 — bank = sum(total_paid).
     *   9. EPIC-4.4 — разослать game_started всем игрокам комнаты.
     *
     * @param object $connection  Workerman-соединение хоста.
     * @param object $worker      Workerman Worker (доступ к $worker->rooms).
     */
    public function handleStartGame(object $connection, object $worker): void
    {
        // --- 1. Auth guard ---
        if (empty($connection->userId)) {
            sendError($connection, 'error.auth_required', 'Authentication required');
            return;
        }

        $connId = $connection->id;

        // --- 2. Найти комнату ---
        $roomId = null;
        foreach ($worker->rooms as $rid => $r) {
            if (isset($r['players'][$connId])) {
                $roomId = $rid;
                break;
            }
        }

        if ($roomId === null) {
            sendError($connection, 'error.room_not_found', 'You are not in a room');
            return;
        }

        $room = &$worker->rooms[$roomId];

        // --- 3. Проверки ---

        // Только хост может запустить игру
        if ($room['host_conn_id'] !== $connId) {
            sendError($connection, 'error.not_your_turn', 'Only the host can start the game');
            return;
        }

        // Комната должна быть в статусе waiting
        if ($room['status'] !== 'waiting') {
            lottoStateReject($roomId, $room['status'], 'start_game', 'error.not_your_turn');
            sendError($connection, 'error.not_your_turn', 'Game already started');
            return;
        }

        // Минимум 2 активных игрока
        $activePlayers = array_filter(
            $room['players'],
            fn($p) => $p['status'] === 'active'
        );

        if (count($activePlayers) < 2) {
            sendError($connection, 'error.not_your_turn', 'Need at least 2 players to start');
            return;
        }

        // --- 4. EPIC-4.0 Вычислить total_paid, собрать данные для транзакции ---

        // Получить текущий баланс каждого игрока из БД
        $playerPayments = []; // connId => ['user_id', 'total_paid', 'current_coins']

        foreach ($activePlayers as $pConnId => $player) {
            $betPerCard = ServerRuntimeSettings::betPerCard($worker, $room);
            $totalPaid = $player['cards_count'] * $betPerCard;

            $stmt = $this->stmts->get('user_by_id');
            $stmt->execute([$player['user_id']]);
            $row = $stmt->fetch();

            if ($row === false) {
                sendError($connection, 'error.not_your_turn', 'Player data not found');
                return;
            }

            // --- 5. Проверить достаточность баланса ---
            if ((int)$row['coins'] < $totalPaid) {
                sendError(
                    $connection,
                    'error.not_your_turn',
                    "Player {$player['username']} has insufficient coins"
                );
                return;
            }

            $playerPayments[$pConnId] = [
                'user_id'       => $player['user_id'],
                'total_paid'    => $totalPaid,
                'current_coins' => (int)$row['coins'],
            ];
        }

        // --- 6. EPIC-4.3 Транзакционное списание монет (all-or-nothing) ---

        $pdo = $this->db->getPdo();

        try {
            $pdo->beginTransaction();

            foreach ($playerPayments as $pConnId => $payment) {
                $newCoins = $payment['current_coins'] - $payment['total_paid'];
                $stmt = $this->stmts->get('update_user_coins');
                $stmt->execute([$newCoins, $payment['user_id']]);

                // Обновить total_paid в RAM
                $room['players'][$pConnId]['total_paid'] = $payment['total_paid'];
            }

            $pdo->commit();

            foreach ($playerPayments as $pConnId => $payment) {
                lottoEconomyRecord('stake', $payment['user_id'], -$payment['total_paid'], [
                    'room_id' => $roomId,
                ]);
            }
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $this->logger->error('startGame: transaction failed: ' . $e->getMessage());
            sendError($connection, 'error.not_your_turn', 'Failed to process payment');
            return;
        }

        // --- 7. EPIC-4.1 Инициализировать игру ---

        // Генерировать мешок
        $room['bag']          = $this->engine->generateBag();
        $room['drawn_numbers'] = [];
        $room['status']       = 'playing';
        lottoStateTransition($roomId, 'waiting', 'playing', 'start_game');

        $room['game_roster'] = [];
        foreach ($activePlayers as $pConnId => $player) {
            $room['game_roster'][$pConnId] = [
                'user_id'  => (int) $player['user_id'],
                'username' => (string) $player['username'],
            ];
        }

        // Назначить карты каждому активному игроку
        foreach ($activePlayers as $pConnId => $player) {
            $cards = [];
            for ($i = 0; $i < $player['cards_count']; $i++) {
                $cards[] = $this->engine->generateCard();
            }
            $room['players'][$pConnId]['cards'] = $cards;

            // Инициализировать маски — bool[cardsCount][3][9], все false
            $masks = [];
            foreach ($cards as $card) {
                $masks[] = array_map(
                    fn($row) => array_fill(0, 9, false),
                    $card
                );
            }
            $room['players'][$pConnId]['masks'] = $masks;

            // Сбросить AFK-поля
            $room['players'][$pConnId]['last_action'] = time();
            $room['players'][$pConnId]['afk_start']   = null;
            $room['players'][$pConnId]['strikes']      = 0;
            $room['players'][$pConnId]['auto_draws']   = 0;
        }

        // Первый drawer — хост (первый в drawer_order)
        $room['active_drawer_conn_id'] = $room['drawer_order'][0] ?? $connId;

        // Остановить lobby AFK таймер
        if (!empty($room['lobby_afk_timer_id'])) {
            lottoTimerDel((int) $room['lobby_afk_timer_id'], 'lobby_afk', ['room_id' => $roomId]);
            $room['lobby_afk_timer_id'] = null;
        }

        // --- 8. EPIC-4.2 Банк = сумма всех total_paid ---

        $bank = 0;
        foreach ($room['players'] as $player) {
            $bank += $player['total_paid'];
        }
        $room['bank'] = $bank;

        $this->logger->info(
            "Game started in room {$roomId}. Players: " . count($activePlayers) .
            ", Bank: {$bank}"
        );

        // --- 9. EPIC-4.4 Разослать game_started ---

        $drawerOrder = array_filter(
            $room['drawer_order'],
            fn($cid) => isset($room['players'][$cid]) &&
                        $room['players'][$cid]['status'] === 'active'
        );
        $drawerOrder = array_values($drawerOrder);

        // Преобразовать conn_id → username для drawer_order
        $drawerUsernames = array_map(
            fn($cid) => $room['players'][$cid]['username'],
            $drawerOrder
        );

        // Разослать каждому игроку персональный пакет
        // (свои карты видны только себе; cards_count — всем — ANCHOR_PROTOCOL.md § Game Start)
        foreach ($room['players'] as $pConnId => $player) {
            if ($player['status'] !== 'active') {
                continue;
            }

            $playersPayload = [];
            foreach ($activePlayers as $otherConnId => $_) {
                $other = $room['players'][$otherConnId];
                $masks = $this->buildInitialMasks($other['cards']);

                if ($otherConnId === $pConnId) {
                    $playersPayload[] = [
                        'username'    => $other['username'],
                        'is_self'     => true,
                        'cards'       => $other['cards'],
                        'masks'       => $masks,
                        'cards_count' => (int) $other['cards_count'],
                    ];
                } else {
                    $playersPayload[] = [
                        'username'    => $other['username'],
                        'is_self'     => false,
                        'cards'       => null,
                        'masks'       => $masks,
                        'cards_count' => (int) $other['cards_count'],
                    ];
                }
            }

            $packet = [
                'type'         => 'game_started',
                'bank'         => $room['bank'],
                'drawer_order' => $drawerUsernames,
                'players'      => $playersPayload,
            ];

            sendJson($player['connection'], $packet);
        }

        // EPIC-13.1 — first drawer your_turn + game AFK timer (ADR-008, prompt.md § start)
        $this->startTurn($room, $worker, $roomId);
    }

    /**
     * {"action": "play_vs_bot"} — атомарно создать бота и стартовать (ADR-034 §3).
     *
     * start_game не вызывается и не меняется: отдельный путь, ровно 1 человек.
     * Списание PDO — только строка человека. Банк = human.total_paid.
     */
    public function handlePlayVsBot(object $connection, object $worker): void
    {
        if (empty($connection->userId)) {
            sendError($connection, 'error.auth_required', 'Authentication required');
            return;
        }

        $connId = $connection->id;

        $roomId = null;
        foreach ($worker->rooms as $rid => $r) {
            if (isset($r['players'][$connId])) {
                $roomId = $rid;
                break;
            }
        }

        if ($roomId === null) {
            sendError($connection, 'error.room_not_found', 'You are not in a room');
            return;
        }

        $room = &$worker->rooms[$roomId];

        if ($room['host_conn_id'] !== $connId
            || $room['status'] !== 'waiting'
            || count($room['players']) !== 1
            || ($room['password_hash'] ?? null) !== null
        ) {
            if ($room['status'] !== 'waiting') {
                lottoStateReject($roomId, $room['status'], 'play_vs_bot', 'error.not_your_turn');
            }
            sendError($connection, 'error.not_your_turn', 'Cannot start a bot match');
            return;
        }

        $human = $room['players'][$connId];
        $betPerCard = ServerRuntimeSettings::betPerCard($worker, $room);
        $totalPaid = $human['cards_count'] * $betPerCard;

        $stmt = $this->stmts->get('user_by_id');
        $stmt->execute([$human['user_id']]);
        $row = $stmt->fetch();

        if ($row === false) {
            sendError($connection, 'error.not_your_turn', 'Player data not found');
            return;
        }

        if ((int)$row['coins'] < $totalPaid) {
            sendError($connection, 'error.not_your_turn', 'Insufficient coins');
            return;
        }

        $pdo = $this->db->getPdo();
        $humanUserId = (int) $human['user_id'];
        $newCoins = (int)$row['coins'] - $totalPaid;

        try {
            $pdo->beginTransaction();
            $upd = $this->stmts->get('update_user_coins');
            $upd->execute([$newCoins, $humanUserId]);
            $room['players'][$connId]['total_paid'] = $totalPaid;
            $pdo->commit();
            lottoEconomyRecord('stake', $humanUserId, -$totalPaid, [
                'room_id' => $roomId,
            ]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $this->logger->error('play_vs_bot: transaction failed: ' . $e->getMessage());
            sendError($connection, 'error.not_your_turn', 'Failed to process payment');
            return;
        }

        $humanCards = [];
        for ($i = 0; $i < $human['cards_count']; $i++) {
            $humanCards[] = $this->engine->generateCard();
        }
        $room['players'][$connId]['cards'] = $humanCards;
        $humanMasks = [];
        foreach ($humanCards as $card) {
            $humanMasks[] = array_map(
                fn($rowCells) => array_fill(0, 9, false),
                $card
            );
        }
        $room['players'][$connId]['masks'] = $humanMasks;
        $room['players'][$connId]['last_action'] = time();
        $room['players'][$connId]['afk_start']   = null;
        $room['players'][$connId]['strikes']      = 0;
        $room['players'][$connId]['auto_draws']   = 0;

        $botCards = [];
        for ($i = 0; $i < 2; $i++) {
            $botCards[] = $this->engine->generateCard();
        }
        $botMasks = [];
        foreach ($botCards as $card) {
            $botMasks[] = array_map(
                fn($rowCells) => array_fill(0, 9, false),
                $card
            );
        }

        $room['bot'] = [
            'username'    => 'Bot',
            'cards'       => $botCards,
            'cards_count' => 2,
            'total_paid'  => 0,
            'immune'      => false,
            'drawing'     => false,
            'status'      => 'active',
            'masks'       => $botMasks,
        ];

        $room['bag']           = $this->engine->generateBag();
        $room['drawn_numbers'] = [];
        $room['status']        = 'playing';
        lottoStateTransition($roomId, 'waiting', 'playing', 'play_vs_bot');

        $room['game_roster'] = [
            $connId => [
                'user_id'  => $humanUserId,
                'username' => (string) $human['username'],
            ],
        ];

        $room['active_drawer_conn_id'] = $room['drawer_order'][0] ?? $connId;
        $room['bank'] = $totalPaid;

        if (!empty($room['lobby_afk_timer_id'])) {
            lottoTimerDel((int) $room['lobby_afk_timer_id'], 'lobby_afk', ['room_id' => $roomId]);
            $room['lobby_afk_timer_id'] = null;
        }

        $this->logger->info(
            "Bot match started in room {$roomId}. Bank: {$totalPaid}"
        );

        $drawerUsernames = array_values(array_map(
            fn($cid) => $room['players'][$cid]['username'],
            array_filter(
                $room['drawer_order'],
                fn($cid) => isset($room['players'][$cid]) &&
                            $room['players'][$cid]['status'] === 'active'
            )
        ));

        $botRosterEntry = [
            'username'    => 'Bot',
            'is_self'     => false,
            'cards'       => null,
            'masks'       => $this->buildInitialMasks($botCards),
            'cards_count' => 2,
        ];

        $humanPayload = [
            'username'    => $room['players'][$connId]['username'],
            'is_self'     => true,
            'cards'       => $humanCards,
            'masks'       => $this->buildInitialMasks($humanCards),
            'cards_count' => (int) $room['players'][$connId]['cards_count'],
        ];

        sendJson($room['players'][$connId]['connection'], [
            'type'         => 'game_started',
            'bank'         => $room['bank'],
            'drawer_order' => $drawerUsernames,
            'players'      => [$humanPayload, $botRosterEntry],
        ]);

        $this->startTurn($room, $worker, $roomId);
    }

    /**
     * @param list<list<list<int|null>>> $cards
     * @return list<list<list<bool>>>
     */
    private function buildInitialMasks(array $cards): array
    {
        return array_map(
            fn($card) => array_map(
                fn($row) => array_map(fn($cell) => false, $row),
                $card
            ),
            $cards
        );
    }
    // -------------------------------------------------------------------------
    // EPIC-5.0  Send your_turn (delegated to GameTurnService — ADR-015)
    // -------------------------------------------------------------------------

    public function sendYourTurn(array &$room, bool $deferAfkStart = false): void
    {
        $this->turnService->sendYourTurn($room, $deferAfkStart);
    }

    public function handleTurnReady(object $connection, object $worker): void
    {
        $this->turnService->handleTurnReady($connection, $worker);
    }

    public function handleNudgeTurn(object $connection, object $worker): void
    {
        $this->turnService->handleNudgeTurn($connection, $worker);
    }

    public function startTurn(array &$room, object $worker, int $roomId, bool $deferAfkStart = false): void
    {
        $this->turnService->startTurn($room, $worker, $roomId, $deferAfkStart);
    }

    public function resumeAfterApartment(array &$room, object $worker, int $roomId): void
    {
        $this->turnService->resumeAfterApartment($room, $worker, $roomId);
    }

    // -------------------------------------------------------------------------
    // EPIC-5.1  Drawer rotation (delegated to GameTurnService — ADR-015)
    // -------------------------------------------------------------------------

    public function nextDrawer(array &$room): void
    {
        $this->turnService->nextDrawer($room);
    }

    // -------------------------------------------------------------------------
    // EPIC-5.2 / 5.3 / 5.4  Draw barrel + mark + broadcast (delegated — ADR-015)
    // -------------------------------------------------------------------------

    public function handleDrawBarrel(object $connection, object $worker, bool $fromAutoDraw = false): void
    {
        $this->turnService->handleDrawBarrel($connection, $worker, $fromAutoDraw);
    }

    /**
     * Comparative win-chance map for protocol packets (ADR-014).
     *
     * @param array<int, array> $players
     * @return array<string, float>
     */
    public function calculateWinChances(array $players, ?string $roomStatus = null): array
    {
        return $this->victory->calculateWinChances($players, $this->logger, $roomStatus);
    }

    public function markNumber(array &$room, int $connId, int $number): void
    {
        $this->turnService->markNumber($room, $connId, $number);
    }

    // -------------------------------------------------------------------------
    // EPIC-7.2  Apartment trigger (delegated to ApartmentService)
    // -------------------------------------------------------------------------

    /**
     * Делегирует запуск апартамента в ApartmentService.
     * Передаёт $this чтобы ApartmentService мог вызвать finishGame() и sendYourTurn().
     */
    public function triggerApartment(array &$room, int $roomId, object $worker): void
    {
        $this->apartment->triggerApartment($room, $roomId, $worker, $this);
    }

    /**
     * Публичный proxy для handleApartmentChoice — вызывается из server.php/handler.
     */
    public function handleApartmentChoice(object $connection, object $worker, string $choice): void
    {
        $this->apartment->handleApartmentChoice($connection, $worker, $choice, $this);
    }

    // -------------------------------------------------------------------------
    // EPIC-6.3 / 6.4  Winner payout transaction + game finish flow
    // -------------------------------------------------------------------------

    /**
     * Завершить игру: транзакционно выплатить призы, разослать game_over, уничтожить комнату.
     *
     * @param array  &$room
     * @param int    $roomId
     * @param array  $winners  connId → число выигравших карт
     * @param array  $prizes   connId → сумма приза
     * @param object $worker
     */
    public function finishGame(
        array &$room,
        int $roomId,
        array $winners,
        array $prizes,
        object $worker,
        string $reason = 'victory',
        ?bool $countsTowardBotStreak = null
    ): void {
        // Prefer explicit flag (apartment may have already cleared $room['bot']).
        $vsBot = $countsTowardBotStreak ?? (isset($room['bot']) && is_array($room['bot']));
        $this->finishService->finishGame(
            $room,
            $roomId,
            $winners,
            $prizes,
            $reason,
            function () use ($worker, $roomId) {
                lottoEconomyCheckInvariants($worker, 'game_finish:' . $roomId);
                unset($worker->rooms[$roomId]);
                if (isset($worker->lobbyService)) {
                    $worker->lobbyService->broadcastRoomList($worker);
                }
            },
            $worker,
            $vsBot
        );
    }

    /**
     * Zero active players — refund all participants and destroy the room.
     */
    public function handleNoSurvivors(
        array &$room,
        int $roomId,
        object $worker,
        ?object $notifyConnection = null
    ): void {
        $this->finishService->handleNoSurvivors(
            $room,
            $roomId,
            function () use ($worker, $roomId) {
                lottoEconomyCheckInvariants($worker, 'no_survivors:' . $roomId);
                unset($worker->rooms[$roomId]);
                if (isset($worker->lobbyService)) {
                    $worker->lobbyService->broadcastRoomList($worker);
                }
            },
            $notifyConnection
        );
    }

    /**
     * ADR-034 §6 / EPIC-034.3: bot win — bank burn, no payout.
     */
    public function finishBotWin(array &$room, int $roomId, object $worker): void
    {
        $this->finishService->finishBotWin(
            $room,
            $roomId,
            $worker,
            function () use ($worker, $roomId) {
                lottoEconomyCheckInvariants($worker, 'bot_win:' . $roomId);
                unset($worker->rooms[$roomId]);
                if (isset($worker->lobbyService)) {
                    $worker->lobbyService->broadcastRoomList($worker);
                }
            }
        );
    }
}