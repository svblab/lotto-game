<?php

declare(strict_types=1);

/**
 * EPIC-3.4 — LottoEngine test suite
 * Manual test: php tests/Manual/test_lotto_engine.php
 */

// Bootstrap
require_once __DIR__ . '/../../vendor/autoload.php';

use Lotto\Game\LottoEngine;

$engine = new LottoEngine();
$passed = 0;
$failed = 0;

function ok(string $label): void {
    global $passed;
    $passed++;
    echo "[PASS] $label\n";
}

function fail(string $label, string $reason = ''): void {
    global $failed;
    $failed++;
    echo "[FAIL] $label" . ($reason ? " — $reason" : '') . "\n";
}

function assert_true(bool $cond, string $label, string $reason = ''): void {
    $cond ? ok($label) : fail($label, $reason);
}

// ---------------------------------------------------------------------------
// CSPRNG NOTE
// ---------------------------------------------------------------------------
// generateCard() and generateBag() use cryptoShuffle() (Fisher-Yates + random_int).
// Failure of random_int() throws RuntimeException — not tested here because it
// requires a broken OS entropy source (/dev/urandom unavailable), which cannot
// be simulated without mocking. The 100-card and 20-bag iterations below
// implicitly confirm that random_int() works in the current environment.

// ---------------------------------------------------------------------------
// BAG TESTS (EPIC-3.1 / EPIC-3.3)
// ---------------------------------------------------------------------------

$bag = $engine->generateBag();

assert_true(is_array($bag),          'Bag: is array');
assert_true(count($bag) === 90,      'Bag: 90 elements');
assert_true($engine->validateBag($bag), 'Bag: validateBag passes on generated bag');

// All values 1-90 present
$sorted = $bag;
sort($sorted);
assert_true($sorted === range(1, 90), 'Bag: contains 1–90 exactly once');

// Bag is shuffled (with overwhelming probability not sorted)
assert_true($bag !== range(1, 90),    'Bag: not in sequential order (shuffled)');

// validateBag — invalid cases
assert_true(!$engine->validateBag([]),                       'Bag: empty array is invalid');
assert_true(!$engine->validateBag(range(1, 89)),            'Bag: 89 elements is invalid');
assert_true(!$engine->validateBag(range(1, 91)),            'Bag: 91 elements is invalid');
$dup = range(1, 90); $dup[0] = 2;
assert_true(!$engine->validateBag($dup),                    'Bag: duplicate values is invalid');
$wrong = range(0, 89);
assert_true(!$engine->validateBag($wrong),                  'Bag: range 0–89 is invalid');

// Generate multiple bags and check uniqueness
$bags = [];
for ($i = 0; $i < 20; $i++) {
    $b = $engine->generateBag();
    assert_true($engine->validateBag($b), "Bag: iteration $i passes validate");
    $key = implode(',', $b);
    $bags[$key] = true;
}
assert_true(count($bags) > 1, 'Bag: 20 bags are not all identical (shuffled)');

// ---------------------------------------------------------------------------
// CARD TESTS (EPIC-3.0 / EPIC-3.2)
// ---------------------------------------------------------------------------

$card = $engine->generateCard();

assert_true(is_array($card),            'Card: is array');
assert_true(count($card) === 3,         'Card: 3 rows');
assert_true(count($card[0]) === 9,      'Card: row 0 has 9 columns');
assert_true(count($card[1]) === 9,      'Card: row 1 has 9 columns');
assert_true(count($card[2]) === 9,      'Card: row 2 has 9 columns');
assert_true($engine->validateCard($card), 'Card: validateCard passes on generated card');

// Row count invariant
for ($row = 0; $row < 3; $row++) {
    $numCount = count(array_filter($card[$row], fn($c) => $c !== null));
    assert_true($numCount === 5, "Card: row $row has exactly 5 numbers");
}

// Total 15 unique numbers
$all = [];
for ($row = 0; $row < 3; $row++) {
    for ($col = 0; $col < 9; $col++) {
        if ($card[$row][$col] !== null) $all[] = $card[$row][$col];
    }
}
assert_true(count($all) === 15,           'Card: total 15 numbers');
assert_true(count(array_unique($all)) === 15, 'Card: all 15 numbers are unique');

// Column range invariant
$columnRanges = [
    0 => [1, 9],
    1 => [10, 19],
    2 => [20, 29],
    3 => [30, 39],
    4 => [40, 49],
    5 => [50, 59],
    6 => [60, 69],
    7 => [70, 79],
    8 => [80, 90],
];
for ($col = 0; $col < 9; $col++) {
    [$min, $max] = $columnRanges[$col];
    for ($row = 0; $row < 3; $row++) {
        $cell = $card[$row][$col];
        if ($cell !== null) {
            assert_true(
                $cell >= $min && $cell <= $max,
                "Card: cell[$row][$col]=$cell in range $min-$max"
            );
        }
    }
}

// Values 1-90
assert_true(min($all) >= 1 && max($all) <= 90, 'Card: all values in range 1–90');

// validateCard — invalid cases
assert_true(!$engine->validateCard([]),              'Card: empty array invalid');
assert_true(!$engine->validateCard([[1,2,3]]),       'Card: wrong structure invalid');

// Row with 6 numbers (too many)
$bad = $engine->generateCard();
$bad[0] = array_fill(0, 9, 1); // 9 numbers in row 0
assert_true(!$engine->validateCard($bad),            'Card: row with 9 numbers invalid');

// Wrong column range
$badRange = $engine->generateCard();
for ($col = 0; $col < 9; $col++) {
    for ($row = 0; $row < 3; $row++) {
        if ($badRange[$row][$col] !== null) {
            $badRange[$row][$col] = 999; // out of range
            break 2;
        }
    }
}
assert_true(!$engine->validateCard($badRange),       'Card: out-of-range number invalid');

// Column invariants: each column has >=1 number, numbers sorted top-to-bottom ascending
$colInvariantOk = true;
for ($col = 0; $col < 9; $col++) {
    $colNumbers = [];
    for ($row = 0; $row < 3; $row++) {
        if ($card[$row][$col] !== null) {
            $colNumbers[] = $card[$row][$col];
        }
    }
    // Each column must have at least 1 number
    if (count($colNumbers) === 0) {
        $colInvariantOk = false;
        break;
    }
    // Numbers in column must be sorted ascending top-to-bottom
    $sortedColNumbers = $colNumbers;
    sort($sortedColNumbers);
    if ($colNumbers !== $sortedColNumbers) {
        $colInvariantOk = false;
        break;
    }
}
assert_true($colInvariantOk, 'Card: column invariants (>=1 number, sorted asc)');

// Generate 100 cards, all must pass validation
for ($i = 0; $i < 100; $i++) {
    $c = $engine->generateCard();
    assert_true($engine->validateCard($c), "Card: iteration $i validateCard");
}

// ---------------------------------------------------------------------------
// MASK COMPATIBILITY TEST
// ---------------------------------------------------------------------------
// Masks are boolean grids [3][9] — initially all false.
// LottoEngine produces cards; mask is created externally. Just verify structure.

$mask = [];
for ($row = 0; $row < 3; $row++) {
    $mask[$row] = array_fill(0, 9, false);
}
assert_true(count($mask) === 3 && count($mask[0]) === 9, 'Mask: 3×9 structure OK');

// ---------------------------------------------------------------------------
// RESULTS
// ---------------------------------------------------------------------------

$total = $passed + $failed;
echo "\n--- EPIC-3.4 LottoEngine Test Suite ---\n";
echo "$passed / $total PASSED\n";
if ($failed > 0) {
    echo "$failed FAILED\n";
    exit(1);
}
exit(0);
