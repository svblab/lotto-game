<?php

declare(strict_types=1);

namespace Lotto\Game;

use Lotto\Core\Constants;
use Lotto\Core\Logger;
use Lotto\Infrastructure\Database;
use Lotto\Infrastructure\PreparedStatements;

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
            \Workerman\Timer::del($room['lobby_afk_timer_id']);
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
            foreach ($activePlayers as $otherConnId => $other) {
                if ($otherConnId === $pConnId) {
                    // Свои карты и маски — видны
                    $masks = array_map(
                        fn($card) => array_map(
                            fn($row) => array_map(fn($cell) => false, $row),
                            $card
                        ),
                        $other['cards']
                    );
                    $playersPayload[] = [
                        'username' => $other['username'],
                        'is_self'  => true,
                        'cards'    => $other['cards'],
                        'masks'    => $masks,
                    ];
                } else {
                    // Чужие карты — null, только маски (тоже null по протоколу)
                    $masks = array_map(
                        fn($card) => array_map(
                            fn($row) => array_map(fn($cell) => false, $row),
                            $card
                        ),
                        $other['cards']
                    );
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

            $player['connection']->send(json_encode($packet));
        }
    }
    // -------------------------------------------------------------------------
    // EPIC-5.0  Send your_turn
    // -------------------------------------------------------------------------

    /**
     * Отправить {"type": "your_turn"} текущему drawer'у.
     * Также сбрасывает afk_start (таймер AFK начинается с этого момента).
     */
    public function sendYourTurn(array &$room): void
    {
        $drawerConnId = $room['active_drawer_conn_id'];
        if (!isset($room['players'][$drawerConnId])) {
            return;
        }
        $player = $room['players'][$drawerConnId];
        if ($player['status'] !== 'active') {
            return;
        }
        $room['players'][$drawerConnId]['afk_start'] = time();
        $player['connection']->send(json_encode(['type' => 'your_turn']));
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
     *   4. EPIC-5.2 — извлечь следующий бочонок из bag.
     *   5. EPIC-5.4 — markNumber(): отметить число на картах drawer'а.
     *   6. EPIC-5.3 — barrels_drawn broadcast всем активным игрокам.
     *   7. EPIC-5.1 — nextDrawer(), затем sendYourTurn() следующему.
     *
     * Победа и апартаменты — делегированы VictoryService / ApartmentService
     * в последующих фазах. Здесь только механика хода.
     */
    public function handleDrawBarrel(object $connection, object $worker): void
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
            sendError($connection, 'error.not_your_turn', 'Game is not in playing state');
            return;
        }

        if ($room['active_drawer_conn_id'] !== $connId) {
            sendError($connection, 'error.not_your_turn', 'It is not your turn to draw');
            return;
        }

        // --- 4. EPIC-5.2 Извлечь бочонок из мешка ---

        if (empty($room['bag'])) {
            // Мешок пуст — все числа вышли (не должно случиться до победы)
            $this->logger->warning("Room {$roomId}: bag is empty on draw_barrel");
            return;
        }

        $number = array_shift($room['bag']);
        $room['drawn_numbers'][] = $number;

        // Сбросить AFK счётчики drawer'а (успешный ручной ход)
        $room['players'][$connId]['afk_start']   = null;
        $room['players'][$connId]['strikes']      = 0;
        $room['players'][$connId]['auto_draws']   = 0;
        $room['players'][$connId]['last_action']  = time();

        // --- 5. EPIC-5.4 markNumber — отметить число на картах всех игроков ---
        // Каждый игрок отмечает вытянутое число на своих картах
        foreach ($room['players'] as $pConnId => $player) {
            if ($player['status'] === 'active') {
                $this->markNumber($room, $pConnId, $number);
            }
        }

        // --- 5b. EPIC-6.0/6.1 Victory check (priority: Victory > Apartment) ---
        $winners = $this->victory->checkAllVictories($room);
        if (!empty($winners)) {
            $result = $this->victory->calculatePrize($room['bank'], $winners);
            $this->finishGame($room, $roomId, $winners, $result['prizes'], $worker);
            return;
        }

        // --- 5c. EPIC-7.1 Apartment trigger check ---
        if ($this->apartment->shouldTrigger($room)) {
            $this->triggerApartment($room, $roomId, $worker);
            return; // turn loop paused
        }

        // --- 6. EPIC-5.3 barrels_drawn broadcast ---

        $remaining  = count($room['bag']);
        $nextDrawer = $this->peekNextDrawer($room);
        $nextDrawerUsername = null;
        if ($nextDrawer !== null && isset($room['players'][$nextDrawer])) {
            $nextDrawerUsername = $room['players'][$nextDrawer]['username'];
        }

        $packet = [
            'type'         => 'barrels_drawn',
            'numbers'      => [$number],
            'remaining'    => $remaining,
            'next_drawer'  => $nextDrawerUsername,
            'is_final'     => $remaining === 0,
        ];

        foreach ($room['players'] as $player) {
            if ($player['status'] === 'active') {
                $player['connection']->send(json_encode($packet));
            }
        }

        $this->logger->info(
            "Room {$roomId}: barrel {$number} drawn. Remaining: {$remaining}"
        );

        // --- 7. Передать ход следующему ---
        $this->nextDrawer($room);
        $this->sendYourTurn($room);
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