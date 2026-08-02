<?php

declare(strict_types=1);

namespace Lotto\Game;

use Lotto\Core\Constants;
use Lotto\Core\Logger;
use Lotto\Infrastructure\Database;
use Lotto\Infrastructure\PreparedStatements;

use function Lotto\Core\lottoTimerDel;

use function Lotto\Core\lottoEconomyRecord;
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
 *   waiting → playing (только через startGame).
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
    private ?ReconnectService $reconnectService = null;

    public function __construct(
        object $db,
        object $stmts,
        LottoEngine $engine,
        object $logger,
        VictoryService $victory,
        ApartmentService $apartment,
        GameFinishService $finishService
    ) {
        $this->db        = $db;
        $this->stmts     = $stmts;
        $this->engine    = $engine;
        $this->logger    = $logger;
        $this->victory   = $victory;
        $this->apartment = $apartment;
        $this->finishService = $finishService;
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
            $totalPaid = $player['cards_count'] * Constants::BET_PER_CARD;

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
        // (свои карты видны только себе — ANCHOR_PROTOCOL.md § Game Start)
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
                        'username' => $other['username'],
                        'is_self'  => true,
                        'cards'    => $other['cards'],
                        'masks'    => $masks,
                    ];
                } else {
                    $playersPayload[] = [
                        'username' => $other['username'],
                        'is_self'  => false,
                        'cards'    => null,
                        'masks'    => $masks,
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
    // EPIC-5.0  Send your_turn
    // -------------------------------------------------------------------------

    /**
     * Отправить {"type": "your_turn"} текущему drawer'у.
     * AFK-таймер стартует сразу, либо откладывается до turn_ready (после анимации).
     */
    public function sendYourTurn(array &$room, bool $deferAfkStart = false): void
    {
        $drawerConnId = $room['active_drawer_conn_id'];
        if (!isset($room['players'][$drawerConnId])) {
            return;
        }
        $player = $room['players'][$drawerConnId];
        if ($player['status'] !== 'active') {
            return;
        }
        $room['players'][$drawerConnId]['strikes'] = 0;
        $autoDraws = (int)($room['players'][$drawerConnId]['auto_draws'] ?? 0);
        $turnSeconds = Constants::gameAfkTurnSeconds();

        if ($deferAfkStart) {
            $room['players'][$drawerConnId]['afk_start'] = null;
            $packet = [
                'type'         => 'your_turn',
                'turn_seconds' => $turnSeconds,
                'auto_draws'   => $autoDraws,
            ];
        } else {
            $room['players'][$drawerConnId]['afk_start'] = time();
            $packet = [
                'type'         => 'your_turn',
                'afk_start'    => $room['players'][$drawerConnId]['afk_start'],
                'turn_seconds' => $turnSeconds,
                'auto_draws'   => $autoDraws,
            ];
        }

        $room['players'][$drawerConnId]['connection']->send(json_encode($packet));
    }

    /**
     * Клиент завершил анимацию хода — запускаем AFK-таймер drawer'а.
     */
    public function handleTurnReady(object $connection, object $worker): void
    {
        if (empty($connection->userId)) {
            sendError($connection, 'error.auth_required', 'Authentication required');
            return;
        }

        $connId = $connection->id;
        $roomId = null;
        foreach ($worker->rooms as $rid => $room) {
            if (isset($room['players'][$connId])) {
                $roomId = (int) $rid;
                break;
            }
        }

        if ($roomId === null) {
            return;
        }

        $room = &$worker->rooms[$roomId];
        if (($room['status'] ?? null) !== 'playing') {
            return;
        }
        if (($room['active_drawer_conn_id'] ?? null) !== $connId) {
            return;
        }
        if (!empty($room['players'][$connId]['afk_start'])) {
            return;
        }

        $this->sendYourTurn($room, false);
    }

    /**
     * EPIC-13.0/13.1 (ADR-008): atomically notify drawer and arm game AFK timer.
     */
    public function startTurn(array &$room, object $worker, int $roomId, bool $deferAfkStart = false): void
    {
        $this->sendYourTurn($room, $deferAfkStart);
        if ($this->reconnectService !== null) {
            $this->reconnectService->ensureGameAfkTimer($worker, $roomId);
        }
    }

    // -------------------------------------------------------------------------
    // EPIC-5.1  Drawer rotation
    // -------------------------------------------------------------------------

    /**
     * Установить следующего активного drawer'а в $room['active_drawer_conn_id'].
     *
     * Правила (ANCHOR_CORE.md § Drawer Order Rules):
     *   - Очередь циклическая, обход по drawer_order.
     *   - Пропускаются: отсутствующие в players, disconnected.
     *   - Если активных нет — active_drawer_conn_id = null.
     */
    public function nextDrawer(array &$room): void
    {
        $order = $room['drawer_order'];
        $count = count($order);
        if ($count === 0) {
            $room['active_drawer_conn_id'] = null;
            return;
        }

        // Найти текущую позицию в очереди
        $currentConnId = $room['active_drawer_conn_id'];
        $currentPos    = array_search($currentConnId, $order);
        if ($currentPos === false) {
            $currentPos = 0;
        }

        // Циклический обход начиная со следующей позиции
        for ($i = 1; $i <= $count; $i++) {
            $nextPos   = ($currentPos + $i) % $count;
            $nextConnId = $order[$nextPos];

            if (!isset($room['players'][$nextConnId])) {
                continue; // игрок удалён
            }
            if ($room['players'][$nextConnId]['status'] !== 'active') {
                continue; // disconnected — пропустить
            }

            $room['active_drawer_conn_id'] = $nextConnId;
            return;
        }

        // Нет активных игроков
        $room['active_drawer_conn_id'] = null;
    }

    // -------------------------------------------------------------------------
    // EPIC-5.2 / 5.3 / 5.4  Draw barrel + mark + broadcast
    // -------------------------------------------------------------------------

    /**
     * Обрабатывает пакет {"action": "draw_barrel"}.
     *
     * Шаги:
     *   1. Auth guard.
     *   2. Найти комнату.
     *   3. Проверки: статус playing, это ход текущего drawer'а.
     *   4. EPIC-5.2 — извлечь до BARRELS_PER_TURN бочонков из bag, по одному.
     *   5. После каждого: markNumber(), победа, «Квартира» (остаток хода отменяется).
     *   6. EPIC-5.3 — barrels_drawn broadcast (1–3 числа).
     *   7. EPIC-5.1 — nextDrawer(), затем sendYourTurn() следующему.
     */
    public function handleDrawBarrel(object $connection, object $worker, bool $fromAutoDraw = false): void
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

        if ($room['status'] !== 'playing') {
            lottoStateReject($roomId, $room['status'], 'draw_barrel', 'error.not_your_turn');
            sendError($connection, 'error.not_your_turn', 'Game is not in playing state');
            return;
        }

        if ($room['active_drawer_conn_id'] !== $connId) {
            sendError($connection, 'error.not_your_turn', 'It is not your turn to draw');
            return;
        }

        if (empty($room['bag'])) {
            $this->logger->warning("Room {$roomId}: bag is empty on draw_barrel");
            return;
        }

        // Ручной ход сбрасывает AFK-счётчики; автоход сохраняет auto_draws.
        $room['players'][$connId]['afk_start']   = null;
        $room['players'][$connId]['strikes']      = 0;
        $room['players'][$connId]['last_action']  = time();
        if (!$fromAutoDraw) {
            $room['players'][$connId]['auto_draws'] = 0;
        }

        $drawnThisTurn = [];

        // --- 4–5. EPIC-5.2: до 3 бочонков, обработка каждого по отдельности ---
        for ($i = 0; $i < Constants::BARRELS_PER_TURN; $i++) {
            if (empty($room['bag'])) {
                break;
            }

            $number = array_shift($room['bag']);
            $room['drawn_numbers'][] = $number;
            $drawnThisTurn[] = $number;

            foreach ($room['players'] as $pConnId => $player) {
                if ($player['status'] === 'active') {
                    $this->markNumber($room, $pConnId, $number);
                }
            }

            $winners = $this->victory->checkAllVictories($room);
            if (!empty($winners)) {
                $result = $this->victory->calculatePrize($room['bank'], $winners);
                $remaining = count($room['bag']);
                $this->broadcastBarrelsDrawn($room, $drawnThisTurn, null, true, $remaining);
                $this->logger->info(
                    "Room {$roomId}: barrels [" . implode(', ', $drawnThisTurn) . "] drawn, victory"
                );
                $this->finishGame($room, $roomId, $winners, $result['prizes'], $worker);
                return;
            }

            if ($this->apartment->shouldTrigger($room)) {
                $remaining = count($room['bag']);
                $currentDrawerUsername = $room['players'][$connId]['username'] ?? null;
                $this->broadcastBarrelsDrawn($room, $drawnThisTurn, $currentDrawerUsername, false, $remaining);
                $this->logger->info(
                    "Room {$roomId}: barrels [" . implode(', ', $drawnThisTurn) . "] drawn, apartment"
                );
                $this->triggerApartment($room, $roomId, $worker);
                return;
            }
        }

        // --- 6. EPIC-5.3 barrels_drawn broadcast ---
        $remaining = count($room['bag']);
        $nextDrawer = $this->peekNextDrawer($room);
        $nextDrawerUsername = null;
        if ($nextDrawer !== null && isset($room['players'][$nextDrawer])) {
            $nextDrawerUsername = $room['players'][$nextDrawer]['username'];
        }

        $this->broadcastBarrelsDrawn(
            $room,
            $drawnThisTurn,
            $nextDrawerUsername,
            $remaining === 0,
            $remaining
        );

        $this->logger->info(
            "Room {$roomId}: barrels [" . implode(', ', $drawnThisTurn) . "] drawn. Remaining: {$remaining}"
        );

        // --- 7. Передать ход следующему ---
        $this->nextDrawer($room);
        $this->startTurn($room, $worker, $roomId, true);
    }

    /**
     * Разослать barrels_drawn всем активным игрокам комнаты.
     *
     * @param int[] $numbers 1–3 вытянутых бочонка текущего хода
     */
    private function broadcastBarrelsDrawn(
        array &$room,
        array $numbers,
        ?string $nextDrawerUsername,
        bool $isFinal,
        int $remaining
    ): void {
        $packet = [
            'type'         => 'barrels_drawn',
            'numbers'      => $numbers,
            'remaining'    => $remaining,
            'next_drawer'  => $nextDrawerUsername,
            'is_final'     => $isFinal,
        ];

        foreach ($room['players'] as $player) {
            if ($player['status'] === 'active') {
                $player['connection']->send(json_encode($packet));
            }
        }
    }

    // -------------------------------------------------------------------------
    // EPIC-5.4  markNumber
    // -------------------------------------------------------------------------

    /**
     * Отметить вытянутое число на картах указанного игрока.
     *
     * Проходит по всем картам игрока, ищет число в нужной колонке,
     * устанавливает соответствующую маску в true.
     *
     * @param array  &$room
     * @param int    $connId  conn_id игрока
     * @param int    $number  вытянутый бочонок
     */
    public function markNumber(array &$room, int $connId, int $number): void
    {
        if (!isset($room['players'][$connId])) {
            return;
        }

        $cards = $room['players'][$connId]['cards'];
        $masks = $room['players'][$connId]['masks'] ?? [];

        // Определить колонку числа
        $col = $this->numberToColumn($number);

        foreach ($cards as $cardIdx => $card) {
            for ($row = 0; $row < 3; $row++) {
                if (($card[$row][$col] ?? null) === $number) {
                    $masks[$cardIdx][$row][$col] = true;
                }
            }
        }

        $room['players'][$connId]['masks'] = $masks;
    }

    /**
     * Определить индекс колонки (0-8) для числа 1-90.
     * Зеркалирует LottoEngine::columnRange().
     */
    private function numberToColumn(int $number): int
    {
        if ($number <= 9)  return 0;
        if ($number >= 80) return 8;
        return (int)floor($number / 10);
    }

    /**
     * Вернуть conn_id следующего активного drawer'а БЕЗ изменения состояния.
     * Используется для формирования next_drawer в пакете barrels_drawn.
     */


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
        string $reason = 'victory'
    ): void {
        $this->finishService->finishGame(
            $room,
            $roomId,
            $winners,
            $prizes,
            $reason,
            function () use ($worker, $roomId) {
                unset($worker->rooms[$roomId]);
            }
        );
    }

    /**
     * Zero active players — refund all participants and destroy the room.
     */
    public function handleNoSurvivors(array &$room, int $roomId, object $worker): void
    {
        $this->finishService->handleNoSurvivors(
            $room,
            $roomId,
            function () use ($worker, $roomId) {
                unset($worker->rooms[$roomId]);
            }
        );
    }

    private function peekNextDrawer(array $room): ?int
    {
        $order      = $room['drawer_order'];
        $count      = count($order);
        $currentPos = array_search($room['active_drawer_conn_id'], $order);
        if ($currentPos === false) {
            $currentPos = 0;
        }

        for ($i = 1; $i <= $count; $i++) {
            $nextPos    = ($currentPos + $i) % $count;
            $nextConnId = $order[$nextPos];
            if (!isset($room['players'][$nextConnId])) {
                continue;
            }
            if ($room['players'][$nextConnId]['status'] !== 'active') {
                continue;
            }
            return $nextConnId;
        }
        return null;
    }
}