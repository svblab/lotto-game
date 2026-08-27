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
     * @param array         $room                  Ссылка на RAM-структуру комнаты.
     * @param int           $roomId                ID комнаты.
     * @param array         $winners               Ассоциативный массив победителей (connId => cardsCount).
     * @param array         $prizes                Ассоциативный массив выигрышей (connId => amount).
     * @param string        $reason                Причина завершения игры ('victory', 'last_survivor').
     * @param callable      $roomDestroyer         Колбэк для безопасного удаления комнаты (Замечание 5).
     * @param object|null   $worker                Worker — needed for ADR-034 botWinStreaks (optional for legacy tests).
     * @param bool          $countsTowardBotStreak True when this finish is a human win vs bot (victory/last_survivor).
     */
    public function finishGame(
        array &$room,
        int $roomId,
        array $winners,
        array $prizes,
        string $reason,
        callable $roomDestroyer,
        ?object $worker = null,
        bool $countsTowardBotStreak = false
    ): void {
        $pdo = $this->db->getPdo();

        // --- Замечание 1 & 2. АТОМАРНАЯ ТРАНЗАКЦИЯ НАЧИСЛЕНИЯ ВЫИГРЫШЕЙ ---
        $bankBeforePayout = (int) ($room['bank'] ?? 0);

        // ADR-034 §7: resolve mint before the PDO transaction so bank + mint
        // credit in one commit; streak RAM update happens only after success.
        $mintByConn = [];
        $streakAfterByUser = []; // userId => new streak int, or null to unset
        if ($worker !== null) {
            $this->ensureBotWinStreaks($worker);
            if ($countsTowardBotStreak && in_array($reason, ['victory', 'last_survivor'], true)) {
                [$mintByConn, $streakAfterByUser] = $this->planBotWinStreakMint(
                    $room,
                    $winners,
                    $bankBeforePayout,
                    $worker
                );
            }
        }

        $payoutCommitted = true;
        if (!empty($prizes) || !empty($mintByConn)) {
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

                // ADR-034 §7: double-bank mint (same transaction as bank payout).
                foreach ($mintByConn as $connId => $mint) {
                    if ($mint <= 0 || !isset($room['players'][$connId])) {
                        continue;
                    }
                    $userId = (int) ($room['players'][$connId]['user_id'] ?? 0);
                    if ($userId <= 0) {
                        continue;
                    }
                    $stmt = $this->stmts->get('add_user_coins');
                    $stmt->execute([$mint, $userId]);
                    lottoEconomyRecord('mint', $userId, $mint, [
                        'room_id' => $roomId,
                        'reason'  => 'bot_win_streak',
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

        // Apply streak RAM updates only after a successful payout path (or no-op payout).
        if ($payoutCommitted && $worker !== null && $countsTowardBotStreak) {
            foreach ($streakAfterByUser as $uid => $streakVal) {
                if ($streakVal === null) {
                    unset($worker->botWinStreaks[$uid]);
                } else {
                    $worker->botWinStreaks[$uid] = $streakVal;
                }
            }
        }

        // HvH finish: reset streak for every human who appears in game_over.
        if ($worker !== null && !$countsTowardBotStreak) {
            $this->resetBotWinStreaksForRoomHumans($room, $worker);
        }

        // --- Замечание 3 & 7. ФОРМИРОВАНИЕ СТАТИСТИКИ (Защищенный доступ) ---
        $fromStatus = $room['status'] ?? 'playing';
        lottoStateTransition($roomId, $fromStatus, 'finished', $reason);
        $room['status'] = 'finished';
        $room['bank']   = 0;

        // received = bank prize + streak mint (mint is emission, not from bank).
        $statPrizes = $prizes;
        foreach ($mintByConn as $connId => $mint) {
            $statPrizes[$connId] = ($statPrizes[$connId] ?? 0) + $mint;
        }
        $statistics = $this->buildVictoryStatistics($room, $statPrizes, $mintByConn);

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
            // prize/final_bank remain the bank payout; mint shows only in statistics.received.
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
        if ($countsTowardBotStreak) {
            $packet['vs_bot'] = true;
        }
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
     * ADR-034 §6 / EPIC-034.3: bot completed a card — bank burn, no payout.
     *
     * Deliberately separate from finishGame()/calculatePrize(): the bot is never
     * a prize recipient. Coins leave the economy with no users.coins credit.
     * Streak storage: unset($worker->botWinStreaks[$userId]) — missing key ⇒ 0
     * (same convention EPIC-034.4 will read).
     */
    public function finishBotWin(
        array &$room,
        int $roomId,
        object $worker,
        callable $roomDestroyer
    ): void {
        $bankBefore = (int) ($room['bank'] ?? 0);

        // RAM-only burn — no PDO touch to users.coins.
        $room['bank'] = 0;
        if ($bankBefore > 0) {
            lottoEconomyRecord('burn', 0, $bankBefore, [
                'room_id' => $roomId,
                'reason'  => 'bot_win',
            ]);
        }

        // Reset human streak(s) for when EPIC-034.4 builds increment/mint on top.
        if (!isset($worker->botWinStreaks) || !is_array($worker->botWinStreaks)) {
            $worker->botWinStreaks = [];
        }
        foreach ($room['players'] ?? [] as $player) {
            $uid = (int) ($player['user_id'] ?? 0);
            if ($uid > 0) {
                unset($worker->botWinStreaks[$uid]);
            }
        }

        $fromStatus = $room['status'] ?? 'playing';
        lottoStateTransition($roomId, $fromStatus, 'finished', 'bot_win');
        $room['status'] = 'finished';

        // Empty prizes ⇒ received=0 for every human; bot is not in game_roster/players.
        $statistics = $this->buildVictoryStatistics($room, []);
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

        $packet = [
            'type'               => 'game_over',
            'winner'             => 'Bot',
            'reason'             => 'bot_win',
            'prize'              => 0,
            'final_bank'         => 0,
            'statistics'         => $statistics,
            'win_chance_history' => $room['win_chance_history'] ?? [],
        ];
        $packetJson = json_encode($packet);

        if (isset($room['players']) && is_array($room['players'])) {
            foreach ($room['players'] as $connId => $player) {
                if (isset($player['status']) && $player['status'] === 'active' && isset($player['connection'])) {
                    try {
                        $player['connection']->send($packetJson);
                    } catch (Throwable $sendError) {
                        $this->logger->warning(
                            "Room {$roomId}: Failed sending game_over (bot_win) to connection {$connId}: "
                            . $sendError->getMessage()
                        );
                    }
                }
            }
        }

        $this->logger->info(
            "Room {$roomId}: bot_win bank burn processed. burned={$bankBefore}"
        );

        $this->cancelRoomTimers($room, $roomId);
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
     * @param array<string, mixed> $room
     * @param array<int, int>      $prizes
     * @param array<int, int>      $streakMintByConn
     * @return list<array{username: string, paid: int, received: int, _user_id: int, streak_mint?: int}>
     */
    private function buildVictoryStatistics(array $room, array $prizes, array $streakMintByConn = []): array
    {
        $roster = $room['game_roster'] ?? [];
        if (!empty($roster)) {
            $statistics = [];
            foreach ($roster as $connId => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $statistics[] = $this->buildStatEntry(
                    $room,
                    (int) $connId,
                    $entry,
                    $prizes,
                    (int) ($streakMintByConn[(int) $connId] ?? 0)
                );
            }

            return $statistics;
        }

        // Fallback for legacy/direct finishGame calls: only players who actually staked.
        $statistics = [];
        $seen = [];
        foreach ($room['all_players_history'] ?? [] as $connId => $hist) {
            if ((int) ($hist['total_paid'] ?? 0) <= 0) {
                continue;
            }
            $username = (string) ($hist['username'] ?? 'unknown');
            $seen[$username] = true;
            $statistics[] = $this->buildStatRow(
                $username,
                (int) ($hist['total_paid'] ?? 0),
                $prizes[$connId] ?? 0,
                (int) ($hist['user_id'] ?? 0),
                (int) ($streakMintByConn[(int) $connId] ?? 0)
            );
        }
        foreach ($room['players'] ?? [] as $connId => $player) {
            $username = (string) ($player['username'] ?? 'unknown');
            if (isset($seen[$username])) {
                continue;
            }
            $statistics[] = $this->buildStatRow(
                $username,
                (int) ($player['total_paid'] ?? 0),
                $prizes[$connId] ?? 0,
                (int) ($player['user_id'] ?? 0),
                (int) ($streakMintByConn[(int) $connId] ?? 0)
            );
        }

        return $statistics;
    }

    /**
     * @param array<string, mixed> $room
     * @param array<string, mixed> $rosterEntry
     * @param array<int, int>      $prizes
     * @return array{username: string, paid: int, received: int, _user_id: int, streak_mint?: int}
     */
    private function buildStatEntry(
        array $room,
        int $connId,
        array $rosterEntry,
        array $prizes,
        int $streakMint = 0
    ): array {
        $username = (string) ($rosterEntry['username'] ?? 'unknown');
        $userId   = (int) ($rosterEntry['user_id'] ?? 0);
        $paid     = 0;

        if (isset($room['players'][$connId])) {
            $player   = $room['players'][$connId];
            $username = (string) ($player['username'] ?? $username);
            $userId   = (int) ($player['user_id'] ?? $userId);
            $paid     = (int) ($player['total_paid'] ?? 0);
        } elseif (isset($room['all_players_history'][$connId])) {
            $hist     = $room['all_players_history'][$connId];
            $username = (string) ($hist['username'] ?? $username);
            $userId   = (int) ($hist['user_id'] ?? $userId);
            $paid     = (int) ($hist['total_paid'] ?? 0);
        }

        return $this->buildStatRow($username, $paid, $prizes[$connId] ?? 0, $userId, $streakMint);
    }

    /**
     * @return array{username: string, paid: int, received: int, _user_id: int, streak_mint?: int}
     */
    private function buildStatRow(
        string $username,
        int $paid,
        int $received,
        int $userId,
        int $streakMint = 0
    ): array {
        $row = [
            'username' => $username,
            'paid'     => $paid,
            'received' => $received,
            '_user_id' => $userId,
        ];
        if ($streakMint > 0) {
            $row['streak_mint'] = $streakMint;
        }

        return $row;
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

    private function ensureBotWinStreaks(object $worker): void
    {
        if (!isset($worker->botWinStreaks) || !is_array($worker->botWinStreaks)) {
            $worker->botWinStreaks = [];
        }
    }

    /**
     * ADR-034 §7: plan mint + next streak values for human winners vs bot.
     * Streak RAM is applied only after the payout transaction commits.
     *
     * @param array<int, int> $winners
     * @return array{0: array<int, int>, 1: array<int, int|null>} mintByConn, streakAfterByUser
     */
    private function planBotWinStreakMint(
        array $room,
        array $winners,
        int $bankBeforePayout,
        object $worker
    ): array {
        $mintByConn = [];
        $streakAfterByUser = [];

        foreach ($winners as $connId => $_shares) {
            if (!isset($room['players'][$connId])) {
                continue;
            }
            $uid = (int) ($room['players'][$connId]['user_id'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $next = (int) ($worker->botWinStreaks[$uid] ?? 0) + 1;
            if ($next >= 3) {
                // Double-bank mint = room bank before payout; then streak → 0.
                if ($bankBeforePayout > 0) {
                    $mintByConn[(int) $connId] = $bankBeforePayout;
                }
                $streakAfterByUser[$uid] = null;
            } else {
                $streakAfterByUser[$uid] = $next;
            }
        }

        return [$mintByConn, $streakAfterByUser];
    }

    /**
     * ADR-034 §7: finishing any human-vs-human game resets streak for every
     * human who appears in that game_over (roster / seated / history stake).
     */
    private function resetBotWinStreaksForRoomHumans(array $room, object $worker): void
    {
        $this->ensureBotWinStreaks($worker);
        $userIds = [];

        foreach ($room['game_roster'] ?? [] as $entry) {
            if (is_array($entry)) {
                $uid = (int) ($entry['user_id'] ?? 0);
                if ($uid > 0) {
                    $userIds[$uid] = true;
                }
            }
        }
        foreach ($room['players'] ?? [] as $player) {
            $uid = (int) ($player['user_id'] ?? 0);
            if ($uid > 0) {
                $userIds[$uid] = true;
            }
        }
        foreach ($room['all_players_history'] ?? [] as $hist) {
            $uid = (int) ($hist['user_id'] ?? 0);
            if ($uid > 0) {
                $userIds[$uid] = true;
            }
        }

        foreach (array_keys($userIds) as $uid) {
            unset($worker->botWinStreaks[$uid]);
        }
    }
}