#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * scripts/analyze_economy_log.php
 *
 * EPIC-11.3 — Parse logs/economy_audit.log, replay transactions, verify conservation.
 *
 * Usage:
 *   php scripts/analyze_economy_log.php [path/to/economy_audit.log] [--initial=10:500,20:500]
 *
 * --initial  Optional starting balances (user_id:coins pairs) for replay verification.
 *            When omitted, only structural checks run (event counts, duplicate tx_id).
 *
 * Exit codes:
 *   0 — pass
 *   1 — fail
 *   2 — usage / file error
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Lotto\Core\EconomyAudit;

$args = array_slice($argv, 1);
$logPath = dirname(__DIR__) . '/logs/economy_audit.log';
$initialBalances = [];

foreach ($args as $arg) {
    if (str_starts_with($arg, '--initial=')) {
        $pairs = explode(',', substr($arg, strlen('--initial=')));
        foreach ($pairs as $pair) {
            if (!str_contains($pair, ':')) {
                continue;
            }
            [$uid, $coins] = explode(':', $pair, 2);
            $initialBalances[(int) $uid] = (int) $coins;
        }
    } elseif (!str_starts_with($arg, '--')) {
        $logPath = $arg;
    }
}

if (!is_file($logPath) || !is_readable($logPath)) {
    fwrite(STDERR, "Economy audit log not found or unreadable: {$logPath}\n");
    exit(2);
}

$events = EconomyAudit::parseLog($logPath);
if (count($events) === 0) {
    fwrite(STDERR, "No parseable [ECONOMY] events in {$logPath}\n");
    exit(2);
}

$stats = EconomyAudit::parseLogStats($logPath);
$failures = [];

$txIds = [];
foreach ($events as $event) {
    if (isset($txIds[$event['tx_id']])) {
        $failures[] = "duplicate tx_id: {$event['tx_id']}";
    }
    $txIds[$event['tx_id']] = true;

    if (!in_array($event['op'], EconomyAudit::OPERATIONS, true)) {
        $failures[] = "unknown operation: {$event['op']} (tx {$event['tx_id']})";
    }
}

if (count($initialBalances) > 0) {
    $replay = EconomyAudit::replay($events, $initialBalances);
    if (!EconomyAudit::verifyConservation(
        $initialBalances,
        $replay['balances'],
        $replay['burned'],
        $replay['room_banks']
    )) {
        $initialTotal = array_sum($initialBalances);
        $finalTotal = array_sum($replay['balances']) + $replay['burned'] + array_sum($replay['room_banks']);
        $failures[] = "conservation violated: initial={$initialTotal} final={$finalTotal} burned={$replay['burned']}";
    }
}

echo "Economy audit analysis: {$logPath}\n";
echo sprintf(
    "Events: %d (stake=%d prize=%d apartment=%d refund=%d burn=%d)\n",
    $stats['events'],
    $stats['ops']['stake'],
    $stats['ops']['prize'],
    $stats['ops']['apartment'],
    $stats['ops']['refund'],
    $stats['ops']['burn']
);

if (count($initialBalances) > 0) {
    $replay = EconomyAudit::replay($events, $initialBalances);
    echo "Replay: burned={$replay['burned']} active_room_banks=" . json_encode($replay['room_banks']) . "\n";
}

if (count($failures) === 0) {
    echo "PASS: economy audit within acceptance criteria.\n";
    exit(0);
}

echo "FAIL:\n";
foreach ($failures as $failure) {
    echo "  - {$failure}\n";
}
exit(1);
