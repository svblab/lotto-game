<?php

declare(strict_types=1);

namespace Lotto\Game;

use Throwable;

use function Lotto\Core\lottoEconomyRecord;
use function Lotto\Core\lottoTimerDel;
use function Lotto\Core\lottoStateTransition;

/**
 * GameFinishService — Выделенный сервис финализации игры (ADR-002).
 * Устраняет технический долг и критические замечания аудита №1–9.
 */
final class GameFinishService
{
    private object $db;
    private object $stmts;
    private object $logger;

    public function __construct(
        object $db,
        object $stmts,
        object $logger
    ) {
        $this->db     = $db;
        $this->stmts  = $stmts;
        $this->logger = $logger;
    }

    /**
     * Основной метод финализации, расчёта статистики, рассылки пакетов и очистки памяти.
     *
     * @param array    $room           Ссылка на RAM-структуру комнаты.
     * @param int      $roomId         ID комнаты.
     * @param array    $winners        Ассоциативный массив победителей (connId => cardsCount).
     * @param array    $prizes         Ассоциативный массив выигрышей (connId => amount).
     * @param string   $reason         Причина завершения игры ('victory', 'last_survivor').
     * @param callable $roomDestroyer  Колбэк для безопасного удаления комнаты (Замечание 5).
     */
    public function finishGame(
        array &$room,
        int $roomId,
        array $winners,
        array $prizes,
        string $reason,
        callable $roomDestroyer
    ): void {
        $pdo = $this->db->getPdo();

        // --- Замечание 1 & 2. АТОМАРНАЯ ТРАНЗАКЦИЯ НАЧИСЛЕНИЯ ВЫИГРЫШЕЙ ---
        $bankBeforePayout = (int) ($room['bank'] ?? 0);

        if (!empty($prizes)) {
            try {
                $pdo->beginTransaction();

                foreach ($prizes as $connId => $prize) {
                    if ($prize <= 0) {
                        continue;
                    }

                    // Замечание 7: Защитное программирование на случай рассогласования структуры
                    if (!isset($room['players'][$connId])) {
                        // Замечание 8: Логирование пропуска отсутствующего победителя
                        $this->logger->warning("Room {$roomId}: Winner connection ID {$connId} is missing from players array.");
                        continue;
                    }

                    $userId = $room['players'][$connId]['user_id'] ?? 0;
                    if (!$userId) {
                        continue;
                    }

                    // Замечание 1: атомарный UPDATE через PreparedStatements (ANCHOR_RULES Part 7)
                    $stmt = $this->stmts->get('add_user_coins');
                    $stmt->execute([$prize, $userId]);

                    lottoEconomyRecord('prize', $userId, $prize, [
                        'room_id' => $roomId,
                        'reason'  => $reason,
                    ]);
                }

                $pdo->commit();

                $distributed = array_sum($prizes);
                $burned = max(0, $bankBeforePayout - $distributed);
                if ($burned > 0) {
                    lottoEconomyRecord('burn', 0, $burned, [
                        'room_id' => $roomId,
                        'reason'  => $reason,
                    ]);
                }
            } catch (Throwable $e) {
                $pdo->rollBack();
                $this->logger->error("Room {$roomId}: finishGame Сбой транзакции начисления: " . $e->getMessage());
                // Важно: протокол не содержит общего server_error кода.
                // Комната НЕ уничтожается, транзакция полностью откатилась.
                return;
            }
        }

        // --- Замечание 3 & 7. ФОРМИРОВАНИЕ СТАТИСТИКИ (Защищенный доступ) ---
        $fromStatus = $room['status'] ?? 'playing';
        lottoStateTransition($roomId, $fromStatus, 'finished', $reason);
        $room['status'] = 'finished';
        $room['bank']   = 0;

        $statistics = [];
        $history = $room['all_players_history'] ?? [];
        foreach ($history as $hist) {
            $statistics[] = [
                'username'   => $hist['username'] ?? 'unknown',
                'paid'       => $hist['total_paid'] ?? 0,
                'received'   => $prizes[$hist['conn_id'] ?? -1] ?? 0,
                '_user_id'   => (int) ($hist['user_id'] ?? 0),
            ];
        }

        // Дозапись текущих игроков, если их по какой-то причине не оказалось в истории
        if (isset($room['players']) && is_array($room['players'])) {
            foreach ($room['players'] as $connId => $player) {
                $username = $player['username'] ?? 'unknown';
                $inHistory = false;
                foreach ($statistics as $s) {
                    if ($s['username'] === $username) {
                        $inHistory = true;
                        break;
                    }
                }
                if (!$inHistory) {
                    $statistics[] = [
                        'username'   => $username,
                        'paid'       => $player['total_paid'] ?? 0,
                        'received'   => $prizes[$connId] ?? 0,
                        '_user_id'   => (int) ($player['user_id'] ?? 0),
                    ];
                }
            }
        }

        // ADR-016 §1: attach authoritative post-transaction balance per player.
        // Read-only — does not affect payout amounts or transaction boundaries.
        foreach ($statistics as &$stat) {
            $uid = $stat['_user_id'];
            unset($stat['_user_id']);
            if ($uid > 0) {
                $userStmt = $this->stmts->get('user_by_id');
                $userStmt->execute([$uid]);
                $row = $userStmt->fetch();
                if ($row !== false) {
                    $stat['coins'] = (int) $row['coins'];
                }
            }
        }
        unset($stat);

        // --- ВЫЧИСЛЕНИЕ ДАННЫХ ДЛЯ ОБРАТНОЙ СОВМЕСТИМОСТИ ПАКЕТА ---
        $winnerUsername = 'unknown';
        $displayPrize   = 0;
        $finalBank      = 0;

        if (!empty($winners)) {
            $winnerConnId   = array_key_first($winners);
            $winnerUsername = $room['players'][$winnerConnId]['username'] ?? 'unknown';
            $displayPrize   = $prizes[$winnerConnId] ?? 0;
            $finalBank      = (count($winners) === 1) ? $displayPrize : array_sum($prizes);
        }

        // --- Замечание 9. РАССЫЛКА ПАКЕТА GAME_OVER (Защита цикла) ---
        $packet = [
            'type'       => 'game_over',
            'winner'     => $winnerUsername,
            'reason'     => $reason,
            'prize'      => $displayPrize,
            'final_bank' => $finalBank,
            'statistics' => $statistics,
            'win_chance_history' => $room['win_chance_history'] ?? [],
        ];
        $packetJson = json_encode($packet);

        if (isset($room['players']) && is_array($room['players'])) {
            foreach ($room['players'] as $connId => $player) {
                if (isset($player['status']) && $player['status'] === 'active' && isset($player['connection'])) {
                    try {
                        $player['connection']->send($packetJson);
                    } catch (Throwable $sendError) {
                        // Замечание 9: Сбой отправки одному игроку не прерывает финализацию остальных
                        $this->logger->warning("Room {$roomId}: Failed sending game_over to connection {$connId}: " . $sendError->getMessage());
                    }
                }
            }
        }

        $this->logger->info(
            "Room {$roomId}: game over successfully processed. Winner: {$winnerUsername}, reason: {$reason}"
        );

        $this->cancelRoomTimers($room, $roomId);

        // --- Замечание 5. ИНКАПСУЛЯЦИЯ (Удаление через переданный callback-замыкание) ---
        $roomDestroyer();
    }

    /**
     * Zero active players — refund all participants and tear down the room
     * (ANCHOR_CORE Part 2 § No Survivors / § Economic Integrity Rule).
     */
    public function handleNoSurvivors(
        array &$room,
        int $roomId,
        callable $roomDestroyer,
        ?object $notifyConnection = null
    ): void {
        $this->snapshotRemainingPlayersToHistory($room);

        $bankBefore = (int) ($room['bank'] ?? 0);
        $totalRefunded = 0;
        $statistics = [];

        $pdo = $this->db->getPdo();
        try {
            $pdo->beginTransaction();
            foreach ($room['all_players_history'] as $connId => $hist) {
                $uid = (int) ($hist['user_id'] ?? 0);
                $refundAmount = (int) ($hist['total_paid'] ?? 0);
                $username = (string) ($hist['username'] ?? 'unknown');

                if ($uid > 0 && $refundAmount > 0) {
                    $add = $this->stmts->get('add_user_coins');
                    $add->execute([$refundAmount, $uid]);

                    lottoEconomyRecord('refund', $uid, $refundAmount, [
                        'room_id' => $roomId,
                        'reason'  => 'no_survivors',
                    ]);
                    $totalRefunded += $refundAmount;
                }

                $statistics[] = [
                    'username' => $username,
                    'paid'     => $refundAmount,
                    'received' => $refundAmount,
                    '_user_id' => $uid,
                ];

                $room['all_players_history'][$connId]['total_paid'] = 0;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $this->logger->error("Room {$roomId}: handleNoSurvivors refund failed: " . $e->getMessage());
            return;
        }

        // ADR-016 §1: attach authoritative post-transaction balance per player.
        // Read-only — does not affect payout amounts or transaction boundaries.
        foreach ($statistics as &$stat) {
            $uid = $stat['_user_id'];
            unset($stat['_user_id']);
            if ($uid > 0) {
                $userStmt = $this->stmts->get('user_by_id');
                $userStmt->execute([$uid]);
                $row = $userStmt->fetch();
                if ($row !== false) {
                    $stat['coins'] = (int) $row['coins'];
                }
            }
        }
        unset($stat);

        $burned = max(0, $bankBefore - $totalRefunded);
        if ($burned > 0) {
            lottoEconomyRecord('burn', 0, $burned, [
                'room_id' => $roomId,
                'reason'  => 'no_survivors',
            ]);
        }

        $fromStatus = $room['status'] ?? 'playing';
        lottoStateTransition($roomId, $fromStatus, 'destroyed', 'no_active_players');
        $room['bank'] = 0;

        $packet = json_encode([
            'type'       => 'game_over',
            'winner'     => '',
            'reason'     => 'no_survivors',
            'prize'      => 0,
            'final_bank' => 0,
            'statistics' => $statistics,
            'win_chance_history' => $room['win_chance_history'] ?? [],
        ]);

        foreach ($room['players'] ?? [] as $player) {
            if (isset($player['connection'])) {
                try {
                    $player['connection']->send($packet);
                } catch (Throwable $sendError) {
                    $this->logger->warning(
                        "Room {$roomId}: failed sending game_over (no_survivors): " . $sendError->getMessage()
                    );
                }
            }
        }

        if ($notifyConnection !== null) {
            try {
                $notifyConnection->send($packet);
            } catch (Throwable $sendError) {
                $this->logger->warning(
                    "Room {$roomId}: failed sending game_over to departing player: " . $sendError->getMessage()
                );
            }
        }

        $this->logger->info(
            "Room {$roomId}: no survivors, refunds={$totalRefunded}, burned={$burned}, no winner"
        );

        $this->cancelRoomTimers($room, $roomId);
        $roomDestroyer();
    }

    /**
     * Ensure disconnected stragglers are included before zero-survivor refund.
     *
     * @param array<string, mixed> $room
     */
    private function snapshotRemainingPlayersToHistory(array &$room): void
    {
        foreach ($room['players'] ?? [] as $connId => $player) {
            if (!isset($room['all_players_history'][$connId])) {
                $room['all_players_history'][$connId] = [
                    'user_id'    => $player['user_id'] ?? 0,
                    'username'   => $player['username'] ?? 'unknown',
                    'total_paid' => $player['total_paid'] ?? 0,
                    'cards_count' => (int) ($player['cards_count'] ?? 1),
                    'reason'     => null,
                ];
            }
        }
    }

    /**
     * @param array<string, mixed> $room
     */
    private function cancelRoomTimers(array $room, int $roomId): void
    {
        if (!empty($room['lobby_afk_timer_id'])) {
            try { lottoTimerDel((int) $room['lobby_afk_timer_id'], 'lobby_afk', ['room_id' => $roomId]); } catch (Throwable $t) {}
        }
        if (!empty($room['game_afk_timer_id'])) {
            try { lottoTimerDel((int) $room['game_afk_timer_id'], 'game_afk', ['room_id' => $roomId]); } catch (Throwable $t) {}
        }
        if (!empty($room['apartment_timer_id'])) {
            try { lottoTimerDel((int) $room['apartment_timer_id'], 'apartment', ['room_id' => $roomId]); } catch (Throwable $t) {}
        }
        if (isset($room['players']) && is_array($room['players'])) {
            foreach ($room['players'] as $connId => $player) {
                if (!empty($player['reconnect_timer'])) {
                    try {
                        lottoTimerDel((int) $player['reconnect_timer'], 'reconnect', [
                            'room_id' => $roomId,
                            'conn_id' => $connId,
                        ]);
                    } catch (Throwable $t) {}
                }
            }
        }
    }
}