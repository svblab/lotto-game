#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * scripts/analyze_timer_log.php
 *
 * EPIC-11.2 — Parse logs/timer_audit.log and verify timer drift / orphan state.
 *
 * Acceptance:
 *   - No orphaned timers (adds - dels >= fires for one-shot; active count = 0 at end)
 *   - Fired reconnect timers within ±tolerance of expected interval
 *
 * Usage:
 *   php scripts/analyze_timer_log.php [path/to/timer_audit.log] [--tolerance-ms=200]
 *
 * Exit codes:
 *   0 — pass
 *   1 — fail
 *   2 — usage / file error
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Lotto\Core\TimerAudit;

$args = array_slice($argv, 1);
$logPath = dirname(__DIR__) . '/logs/timer_audit.log';
$toleranceMs = 200;

foreach ($args as $arg) {
    if (str_starts_with($arg, '--tolerance-ms=')) {
        $toleranceMs = (int) substr($arg, strlen('--tolerance-ms='));
        if ($toleranceMs < 0) {
            fwrite(STDERR, "Invalid tolerance: must be >= 0\n");
            exit(2);
        }
    } elseif (!str_starts_with($arg, '--')) {
        $logPath = $arg;
    }
}

if (!is_file($logPath) || !is_readable($logPath)) {
    fwrite(STDERR, "Timer audit log not found or unreadable: {$logPath}\n");
    exit(2);
}

$lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines === false || count($lines) === 0) {
    fwrite(STDERR, "Timer audit log is empty: {$logPath}\n");
    exit(2);
}

$events = [];
foreach ($lines as $line) {
    if (!preg_match('/\[TIMER\]\s+(.+)$/', $line, $m)) {
        continue;
    }

    $fields = [];
    foreach (preg_split('/\s+/', trim($m[1])) as $pair) {
        if (!str_contains($pair, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $pair, 2);
        $fields[$k] = $v;
    }

    if (!isset($fields['event'], $fields['label'], $fields['timer_id'])) {
        continue;
    }

    $events[] = [
        'event'    => $fields['event'],
        'label'    => $fields['label'],
        'timer_id' => (int) $fields['timer_id'],
        'ts_us'    => isset($fields['ts_us']) ? (int) $fields['ts_us'] : null,
        'interval' => isset($fields['interval_s']) ? (float) $fields['interval_s'] : null,
        'line'     => $line,
    ];
}

if (count($events) === 0) {
    fwrite(STDERR, "No parseable [TIMER] events in {$logPath}\n");
    exit(2);
}

$stats = TimerAudit::parseLogStats($logPath);
$failures = [];

if ($stats['active'] > 0) {
    $failures[] = "orphaned timers at end of log: {$stats['active']} still active";
}

$pending = [];
foreach ($events as $event) {
    $id = $event['timer_id'];

    if ($event['event'] === 'add') {
        $pending[$id] = $event;
        continue;
    }

    if ($event['event'] === 'fire') {
        if (!isset($pending[$id])) {
            continue;
        }

        $add = $pending[$id];
        $expectedMs = ($add['interval'] ?? 0) * 1000;
        if ($expectedMs > 0 && $add['ts_us'] !== null && $event['ts_us'] !== null) {
            $actualMs = ($event['ts_us'] - $add['ts_us']) / 1000;
            $driftMs = abs($actualMs - $expectedMs);
            if ($driftMs > $toleranceMs) {
                $failures[] = sprintf(
                    'timer %d (%s): drift %.1fms (expected %.0fms, actual %.1fms, tolerance %dms)',
                    $id,
                    $event['label'],
                    $driftMs,
                    $expectedMs,
                    $actualMs,
                    $toleranceMs
                );
            }
        }

        if (in_array($add['label'], ['reconnect', 'apartment'], true)) {
            unset($pending[$id]);
        }
        continue;
    }

    if ($event['event'] === 'del') {
        unset($pending[$id]);
    }
}

echo "Timer audit analysis: {$logPath}\n";
echo sprintf(
    "Events: adds=%d dels=%d fires=%d active_end=%d tolerance=%dms\n",
    $stats['adds'],
    $stats['dels'],
    $stats['fires'],
    $stats['active'],
    $toleranceMs
);

if (count($failures) === 0) {
    echo "PASS: timer audit within acceptance criteria.\n";
    exit(0);
}

echo "FAIL:\n";
foreach ($failures as $failure) {
    echo "  - {$failure}\n";
}
exit(1);
