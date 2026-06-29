<?php

declare(strict_types=1);

namespace Lotto\Game;

/**
 * LottoEngine — pure math for Russian Lotto.
 *
 * Card layout: 3 rows × 9 columns.
 *   Column 0: numbers  1– 9  (9 values)
 *   Column 1: numbers 10–19  (10 values)
 *   ...
 *   Column 8: numbers 80–90  (11 values)
 *   Each row has exactly 5 filled cells and 4 null (empty) cells.
 *   Total per card: 15 unique numbers.
 *
 * Bag: integers 1–90 in random order.
 *
 * Forbidden: DB, connections, rooms, timers.
 */
final class LottoEngine
{
    // -------------------------------------------------------------------------
    // EPIC-3.0  Card generator
    // -------------------------------------------------------------------------

    /**
     * Generate one Russian Lotto card.
     *
     * @return array<int, array<int, int|null>>  [3][9], null = empty cell.
     */
    public function generateCard(): array
    {
        // Algorithm: generate a valid column-assignment mask first, then fill numbers.
        //
        // A valid mask is a binary matrix [3][9] where:
        //   - Each row has exactly 5 ones  (5 numbers per row)
        //   - Each column has 1 or 2 ones  (at least 1 number per column is NOT required
        //     by all rulesets, but we enforce >=1 for standard Russian Lotto)
        //   - Total ones = 15
        //
        // We build the mask by assigning 15 "slots" across 9 columns (each col gets 1 or 2)
        // and then distributing slots to rows such that each row gets exactly 5.
        // This is done via a guaranteed-valid construction:
        //
        //   1. Create a flat list of 15 column indices (each col appears 1 or 2 times).
        //   2. Shuffle the list.
        //   3. Assign first 5 to row 0, next 5 to row 1, last 5 to row 2.
        //   4. Retry if any column appears twice in the same row (extremely rare).

        $mask = $this->generateMask();

        // Fill numbers: for each column, pick (count of 1s in that column) random numbers
        // from the column pool, sort them, assign top-to-bottom.
        $grid = array_fill(0, 3, array_fill(0, 9, null));

        for ($col = 0; $col < 9; $col++) {
            [$min, $max] = $this->columnRange($col);
            $pool = range($min, $max);
            $pool = $this->cryptoShuffle($pool);

            // Rows that have a 1 in this column, sorted ascending
            $activeRows = [];
            for ($row = 0; $row < 3; $row++) {
                if ($mask[$row][$col]) {
                    $activeRows[] = $row;
                }
            }
            sort($activeRows);

            // Assign sorted numbers to sorted rows (smaller number → lower row index)
            $nums = array_slice($pool, 0, count($activeRows));
            sort($nums);
            foreach ($activeRows as $i => $row) {
                $grid[$row][$col] = $nums[$i];
            }
        }

        return $grid;
    }

    /**
     * Generate a valid 3×9 boolean mask:
     *   - Each row has exactly 5 trues.
     *   - Each column has exactly 1 or 2 trues.
     *   - Total trues = 15.
     *
     * Strategy:
     *   Build a flat array of 15 column indices (6 cols appear twice, 3 cols appear once),
     *   shuffle it, split into 3 rows of 5. Retry if a column appears twice in one row.
     *   Expected retries: near zero in practice.
     *
     * @return bool[3][9]
     */
    private function generateMask(): array
    {
        // 6 columns get 2 slots, 3 columns get 1 slot → total 6*2 + 3*1 = 15
        // Randomly decide which 6 cols get 2 slots.
        $colOrder = array_values($this->cryptoShuffle(range(0, 8)));
        $doubleCols = array_slice($colOrder, 0, 6); // 6 columns with count=2
        $singleCols = array_slice($colOrder, 6, 3); // 3 columns with count=1

        // Build flat slot list
        $slots = [];
        foreach ($doubleCols as $col) {
            $slots[] = $col;
            $slots[] = $col;
        }
        foreach ($singleCols as $col) {
            $slots[] = $col;
        }
        // $slots has 15 elements

        // Shuffle and split into rows; retry if collision (same col twice in one row)
        $maxAttempts = 1000;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $slots = array_values($this->cryptoShuffle($slots));

            $row0 = array_slice($slots, 0, 5);
            $row1 = array_slice($slots, 5, 5);
            $row2 = array_slice($slots, 10, 5);

            // Check: no column appears twice in the same row
            if (count($row0) === count(array_unique($row0))
                && count($row1) === count(array_unique($row1))
                && count($row2) === count(array_unique($row2))
            ) {
                // Valid mask found
                $mask = array_fill(0, 3, array_fill(0, 9, false));
                foreach ($row0 as $col) { $mask[0][$col] = true; }
                foreach ($row1 as $col) { $mask[1][$col] = true; }
                foreach ($row2 as $col) { $mask[2][$col] = true; }
                return $mask;
            }
        }

        // Should never happen, but satisfy static analysis
        throw new \RuntimeException('LottoEngine: failed to generate valid card mask after 1000 attempts.');
    }

    // -------------------------------------------------------------------------
    // EPIC-3.1  Bag generator
    // -------------------------------------------------------------------------

    /**
     * Generate a shuffled bag of numbers 1–90.
     *
     * @return int[]
     */
    public function generateBag(): array
    {
        $bag = range(1, 90);
        return $this->cryptoShuffle($bag);
    }

    // -------------------------------------------------------------------------
    // EPIC-3.2  Card validator
    // -------------------------------------------------------------------------

    /**
     * Validate structural invariants of a card.
     *
     * @param  mixed $card
     * @return bool
     */
    public function validateCard(mixed $card): bool
    {
        if (!is_array($card) || count($card) !== 3) {
            return false;
        }

        $allNumbers = [];

        for ($row = 0; $row < 3; $row++) {
            if (!isset($card[$row]) || !is_array($card[$row]) || count($card[$row]) !== 9) {
                return false;
            }

            $rowCount = 0;
            for ($col = 0; $col < 9; $col++) {
                $cell = $card[$row][$col] ?? null;
                if ($cell === null) {
                    continue;
                }
                if (!is_int($cell)) {
                    return false;
                }
                // Column range check
                [$min, $max] = $this->columnRange($col);
                if ($cell < $min || $cell > $max) {
                    return false;
                }
                // Uniqueness check
                if (in_array($cell, $allNumbers, true)) {
                    return false;
                }
                $allNumbers[] = $cell;
                $rowCount++;
            }

            if ($rowCount !== 5) {
                return false;
            }
        }

        return count($allNumbers) === 15;
    }

    // -------------------------------------------------------------------------
    // EPIC-3.3  Bag validator
    // -------------------------------------------------------------------------

    /**
     * Validate a bag: exactly numbers 1–90 each appearing once.
     *
     * @param  mixed $bag
     * @return bool
     */
    public function validateBag(mixed $bag): bool
    {
        if (!is_array($bag) || count($bag) !== 90) {
            return false;
        }
        $sorted = $bag;
        sort($sorted);
        return $sorted === range(1, 90);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * [min, max] number range for the given column index.
     */
    private function columnRange(int $col): array
    {
        if ($col === 0) {
            return [1, 9];
        }
        if ($col === 8) {
            return [80, 90];
        }
        return [$col * 10, $col * 10 + 9];
    }

    /**
     * Fisher-Yates shuffle using CSPRNG (random_int).
     *
     * Replaces PHP's shuffle() which uses the Mersenne Twister (non-cryptographic).
     * random_int() draws from /dev/urandom (Linux) — cryptographically secure.
     *
     * @param  array<mixed> $arr
     * @return array<mixed>
     * @throws \RuntimeException  If the OS entropy source is unavailable.
     */
    private function cryptoShuffle(array $arr): array
    {
        $n = count($arr);
        for ($i = $n - 1; $i > 0; $i--) {
            try {
                $j = random_int(0, $i);
            } catch (\Exception $e) {
                throw new \RuntimeException(
                    'LottoEngine: entropy source unavailable, cannot shuffle securely.',
                    0,
                    $e
                );
            }
            [$arr[$i], $arr[$j]] = [$arr[$j], $arr[$i]];
        }
        return $arr;
    }
}
