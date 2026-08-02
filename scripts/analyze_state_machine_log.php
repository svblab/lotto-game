#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * scripts/analyze_state_machine_log.php
 *
 * EPIC-11.4 — Parse logs/state_machine_audit.log and verify transition sequence.
 *
 * Usage:
 *   php scripts/analyze_state_machine_log.php [path/to/state_machine_audit.log]
 *
 * Exit codes:
 *   0 — pass
 *   1 — validation failures
 *   2 — usage / file error
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Lotto\Core\StateMachineAudit;

$logPath = dirname(__DIR__) . '/logs/state_machine_audit.log';

foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--')) {
        $logPath = $arg;
    }
}

if (!is_file($logPath) || !is_readable($logPath)) {
    fwrite(STDERR, "State machine audit log not found or unreadable: {$logPath}\n");
    exit(2);
}

$events = StateMachineAudit::parseLog($logPath);
if (count($events) === 0) {
    fwrite(STDERR, "No parseable [STATE] events in {$logPath}\n");
    exit(2);
}

$failures = StateMachineAudit::validateLog($events);

$transitions = 0;
$rejects = 0;
$playerTransitions = 0;
foreach ($events as $event) {
    match ($event['event'] ?? '') {
        'transition' => $transitions++,
        'reject' => $rejects++,
        'player_transition' => $playerTransitions++,
        default => null,
    };
}

echo "State machine audit log: {$logPath}\n";
echo "Events: " . count($events) . " (transitions={$transitions}, rejects={$rejects}, player={$playerTransitions})\n";

if (count($failures) > 0) {
    echo "FAIL — " . count($failures) . " validation error(s):\n";
    foreach ($failures as $msg) {
        echo "  - {$msg}\n";
    }
    exit(1);
}

echo "PASS — all transitions conform to ANCHOR_CORE.md Part 4\n";
exit(0);
