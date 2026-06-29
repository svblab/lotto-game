<?php

declare(strict_types=1);

namespace Lotto\Game;

use Lotto\Core\Constants;
use Lotto\Core\Logger;
use Lotto\Infrastructure\Database;
use Lotto\Infrastructure\PreparedStatements;

use function Lotto\Core\sendError;
use function Lotto\Core\broadcastToRoom;

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

    public function __construct(
        object $db,
        object $stmts,
        LottoEngine $engine,
        object $logger
    ) {
        $this->db     = $db;
        $this->stmts  = $stmts;
        $this->engine = $engine;
        $this->logger = $logger;
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
}
