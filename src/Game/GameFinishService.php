<?php

declare(strict_types=1);

namespace Lotto\Game;

use Lotto\Infrastructure\Database;
use Lotto\Infrastructure\PreparedStatements;
use Lotto\Core\Logger;
use Workerman\Timer;
use Throwable;

/**
 * GameFinishService — Выделенный сервис финализации игры (ADR-002).
 * Устраняет технический долг и критические замечания аудита №1–9.
 */
final class GameFinishService
{
    private Database $db;
    private PreparedStatements $stmts;
    private Logger $logger;

    /**
     * Замечание 6: Строгая типизация системных зависимостей вместо object.
     */
    public function __construct(
        Database $db,
        PreparedStatements $stmts,
        Logger $logger
    ) {
        $this->db    = $db;
        $this->stmts = $stmts;
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
                }

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                $this->logger->error("Room {$roomId}: finishGame Сбой транзакции начисления: " . $e->getMessage());
                // Важно: протокол не содержит общего server_error кода.
                // Комната НЕ уничтожается, транзакция полностью откатилась.
                return;
            }
        }

        // --- Замечание 3 & 7. ФОРМИРОВАНИЕ СТАТИСТИКИ (Защищенный доступ) ---
        $room['status'] = 'finished';
        $room['bank']   = 0;

        $statistics = [];
        $history = $room['all_players_history'] ?? [];
        foreach ($history as $hist) {
            $statistics[] = [
                'username'   => $hist['username'] ?? 'unknown',
                'paid'       => $hist['total_paid'] ?? 0,
                'received'   => $prizes[$hist['conn_id'] ?? -1] ?? 0,
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
                    ];
                }
            }
        }

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

        // --- Замечание 4. УПРАВЛЕНИЕ ТАЙМЕРАМИ (Полная очистка утечек памяти) ---
        if (!empty($room['lobby_afk_timer_id'])) {
            try { Timer::del($room['lobby_afk_timer_id']); } catch (Throwable $t) {}
        }
        if (!empty($room['game_afk_timer_id'])) {
            try { Timer::del($room['game_afk_timer_id']); } catch (Throwable $t) {}
        }
        if (!empty($room['apartment_timer_id'])) {
            try { Timer::del($room['apartment_timer_id']); } catch (Throwable $t) {}
        }
        if (isset($room['players']) && is_array($room['players'])) {
            foreach ($room['players'] as $player) {
                if (!empty($player['reconnect_timer'])) {
                    try { Timer::del($player['reconnect_timer']); } catch (Throwable $t) {}
                }
            }
        }

        // --- Замечание 5. ИНКАПСУЛЯЦИЯ (Удаление через переданный callback-замыкание) ---
        $roomDestroyer();
    }
}