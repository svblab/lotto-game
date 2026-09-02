#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * scripts/analyze_timer_log.php
 *
 * EPIC-11.2 — Parse logs/timer_audit.log and verify timer drift / orphan state.
 *
 * Acceptance:
 *   - No orphaned one-shot timers (reconnect / apartment / file_offer without fire or del)
 *   - Fired one-shot timers within ±tolerance of expected interval (add -> first fire)
 *   - Persistent/periodic timers (watchdog, AFK ticks, audit periodics) are expected to
 *     remain active until process stop and are excluded from orphan/drift checks
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

/** @var list<string> One-shot labels subject to drift + orphan acceptance */
$oneShotLabels = ['reconnect', 'apartment', 'file_offer'];

$pending = [];
$oneShotSeen = 0;
$oneShotFired = 0;

foreach ($events as $event) {
    $id = $event['timer_id'];
    $label = $event['label'];
    $isOneShot = in_array($label, $oneShotLabels, true);

    if ($event['event'] === 'add') {
        $pending[$id] = $event;
        if ($isOneShot) {
            $oneShotSeen++;
        }
        continue;
    }

    if ($event['event'] === 'fire') {
        if (!isset($pending[$id])) {
            continue;
        }

        $add = $pending[$id];
        $addIsOneShot = in_array($add['label'], $oneShotLabels, true);

        // Drift: only first fire of one-shot timers (periodic fires are not add->N*interval).
        if ($addIsOneShot) {
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
            $oneShotFired++;
            unset($pending[$id]);
        }
        continue;
    }

    if ($event['event'] === 'del') {
        unset($pending[$id]);
    }
}

$orphanedOneShots = 0;
foreach ($pending as $left) {
    if (in_array($left['label'], $oneShotLabels, true)) {
        $orphanedOneShots++;
        $failures[] = sprintf(
            'orphaned one-shot timer %d (%s) - no fire/del before end of log',
            $left['timer_id'],
            $left['label']
        );
    }
}

if ($oneShotSeen === 0) {
    $failures[] = 'no one-shot timer (reconnect/apartment/file_offer) observed - harness did not exercise reconnect grace';
}

echo "Timer audit analysis: {$logPath}\n";
echo sprintf(
    "Events: adds=%d dels=%d fires=%d active_end=%d one_shot_seen=%d one_shot_fired=%d orphaned_one_shot=%d tolerance=%dms\n",
    $stats['adds'],
    $stats['dels'],
    $stats['fires'],
    $stats['active'],
    $oneShotSeen,
    $oneShotFired,
    $orphanedOneShots,
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
