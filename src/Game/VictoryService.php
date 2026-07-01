<?php

declare(strict_types=1);

namespace Lotto\Game;

/**
 * VictoryService — EPIC-6.0 / 6.1 / 6.2
 *
 * Чистая математика победы. Forbidden: socket sending, db access, timers.
 *
 * Контракты (ANCHOR_CORE.md Part 2):
 *
 * Victory Condition:
 *   Игрок побеждает если все 15 чисел хотя бы одной карты закрыты маской.
 *
 * Double Victory:
 *   Обе карты одного игрока закрываются в одном ходу — 2 доли вместо 1.
 *   Нормальная победа = 1 доля.
 *
 * Prize calculation:
 *   share = floor(bank / total_shares)
 *   Остаток сжигается.
 *   Пример: bank=100, playerA double (2 shares) + playerB normal (1 share)
 *            total_shares=3, share=33, playerA=66, playerB=33, burned=1
 */
final class VictoryService
{
    // -------------------------------------------------------------------------
    // EPIC-6.0  Victory detection
    // -------------------------------------------------------------------------

    /**
     * Проверить победу для одного игрока после хода.
     *
     * @param  array $player  Player structure из $room['players'][$connId]
     * @return int  Количество выигравших карт (0 = нет победы, 1 = normal, 2 = double)
     */
    public function checkCardVictory(array $player): int
    {
        $cards = $player['cards'] ?? [];
        $masks = $player['masks'] ?? [];
        $wins  = 0;

        foreach ($cards as $cardIdx => $card) {
            if ($this->isCardComplete($card, $masks[$cardIdx] ?? [])) {
                $wins++;
            }
        }

        return $wins;
    }

    /**
     * Проверить всех активных игроков после вытягивания бочонка.
     * Возвращает массив победителей: [ connId => winsCount ]
     * Пустой массив = нет победителей.
     *
     * @param  array $room
     * @return array<int, int>  connId → число выигравших карт
     */
    public function checkAllVictories(array $room): array
    {
        $winners = [];

        foreach ($room['players'] as $connId => $player) {
            if ($player['status'] !== 'active') {
                continue;
            }
            $wins = $this->checkCardVictory($player);
            if ($wins > 0) {
                $winners[$connId] = $wins;
            }
        }

        return $winners;
    }

    // -------------------------------------------------------------------------
    // EPIC-6.1  Double victory detection (включена в checkCardVictory)
    // -------------------------------------------------------------------------
    // checkCardVictory() возвращает 2 если обе карты закрыты в одном ходу.
    // Внешний код (GameService::finishGame) передаёт winners после каждого хода,
    // поэтому "в одном ходу" гарантируется вызовом checkAllVictories сразу после draw.

    // -------------------------------------------------------------------------
    // EPIC-6.2  Prize calculation
    // -------------------------------------------------------------------------

    /**
     * Рассчитать призы для победителей.
     *
     * @param  int   $bank     Текущий банк комнаты.
     * @param  array $winners  connId → число выигравших карт (1 или 2).
     * @return array{prizes: array<int,int>, burned: int}
     *   prizes: connId → сумма приза
     *   burned: остаток который сжигается
     */
    public function calculatePrize(int $bank, array $winners): array
    {
        $totalShares = array_sum($winners); // sum of wins counts

        if ($totalShares === 0) {
            return ['prizes' => [], 'burned' => 0];
        }

        $share  = (int)floor($bank / $totalShares);
        $prizes = [];

        foreach ($winners as $connId => $shares) {
            $prizes[$connId] = $share * $shares;
        }

        $distributed = array_sum($prizes);
        $burned       = $bank - $distributed;

        return ['prizes' => $prizes, 'burned' => $burned];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Проверить что все числа карты закрыты маской.
     *
     * @param  array $card  int[3][9] — null = пустая ячейка
     * @param  array $mask  bool[3][9]
     * @return bool
     */
    private function isCardComplete(array $card, array $mask): bool
    {
        for ($row = 0; $row < 3; $row++) {
            for ($col = 0; $col < 9; $col++) {
                $cell = $card[$row][$col] ?? null;
                if ($cell === null) {
                    continue; // пустая ячейка — не учитывается
                }
                if (empty($mask[$row][$col])) {
                    return false; // число есть, маска не выставлена
                }
            }
        }
        return true;
    }
}
