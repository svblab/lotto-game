<?php

declare(strict_types=1);

/**
 * EPIC-12.6 — Frontend pure-logic tests (markNumber, winChance)
 * Run: php tests/Manual/test_frontend_logic.php
 */

$passed = 0;
$failed = 0;

function ok(string $label): void
{
    global $passed;
    $passed++;
    echo "[PASS] $label\n";
}

function fail(string $label, string $reason = ''): void
{
    global $failed;
    $failed++;
    echo "[FAIL] $label" . ($reason ? " — $reason" : '') . "\n";
}

function assert_true(bool $cond, string $label, string $reason = ''): void
{
    $cond ? ok($label) : fail($label, $reason);
}

function numberToColumn(int $number): int
{
    if ($number <= 9) {
        return 0;
    }
    if ($number >= 80) {
        return 8;
    }
    return (int)floor($number / 10);
}

/** Mirrors public/js/ui.js markNumberOnCards */
function markNumberOnCards(array $cards, array $masks, int $number): array
{
    $next = $masks;
    foreach ($cards as $ci => $card) {
        for ($r = 0; $r < 3; $r++) {
            for ($c = 0; $c < 9; $c++) {
                if (($card[$r][$c] ?? null) === $number) {
                    $next[$ci][$r][$c] = true;
                }
            }
        }
    }
    return $next;
}

function calcWinChance(array $masks): int
{
    $marked = 0;
    foreach ($masks as $card) {
        foreach ($card as $row) {
            foreach ($row as $cell) {
                if ($cell) {
                    $marked++;
                }
            }
        }
    }
    return (int)round(($marked / 15) * 100);
}

echo "=== EPIC-12.6 Frontend logic tests ===\n\n";

assert_true(numberToColumn(1) === 0, 'column: 1 -> 0');
assert_true(numberToColumn(10) === 1, 'column: 10 -> 1');
assert_true(numberToColumn(90) === 8, 'column: 90 -> 8');

$card = [
    [1, null, null, null, null, null, null, null, null],
    [null, 12, null, null, null, null, null, null, null],
    [null, null, 23, null, null, null, null, null, null],
];
$masks = [[
    [false, false, false, false, false, false, false, false, false],
    [false, false, false, false, false, false, false, false, false],
    [false, false, false, false, false, false, false, false, false],
]];

$after = markNumberOnCards([$card], $masks, 12);
assert_true($after[0][1][1] === true, 'markNumber: marks 12');
assert_true($after[0][0][0] === false, 'markNumber: does not mark unrelated');

$after2 = markNumberOnCards([$card], $after, 1);
assert_true($after2[0][0][0] === true, 'markNumber: marks 1');

assert_true(calcWinChance($after2) === 13, 'winChance: 2/15 ≈ 13%');

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
