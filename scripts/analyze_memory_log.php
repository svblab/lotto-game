#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * scripts/analyze_memory_log.php
 *
 * EPIC-11.1 — Parse logs/memory_audit.log and verify memory stability.
 *
 * Acceptance: memory must stabilise within 120% of baseline (after warm-up).
 *
 * Usage:
 *   php scripts/analyze_memory_log.php [path/to/memory_audit.log] [--threshold=1.20]
 *
 * Exit codes:
 *   0 — pass (within threshold)
 *   1 — fail (monotonic growth or exceeds threshold)
 *   2 — usage / file error
 */

$args = array_slice($argv, 1);
$logPath = dirname(__DIR__) . '/logs/memory_audit.log';
$threshold = 1.20;

foreach ($args as $arg) {
    if (str_starts_with($arg, '--threshold=')) {
        $threshold = (float) substr($arg, strlen('--threshold='));
        if ($threshold <= 0) {
            fwrite(STDERR, "Invalid threshold: must be > 0\n");
            exit(2);
        }
    } elseif (!str_starts_with($arg, '--')) {
        $logPath = $arg;
    }
}

if (!is_file($logPath) || !is_readable($logPath)) {
    fwrite(STDERR, "Memory audit log not found or unreadable: {$logPath}\n");
    exit(2);
}

$lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines === false || count($lines) === 0) {
    fwrite(STDERR, "Memory audit log is empty: {$logPath}\n");
    exit(2);
}

$snapshots = [];
foreach ($lines as $line) {
    if (!preg_match('/\[MEMORY\]\s+(.+)$/', $line, $m)) {
        continue;
    }
    $fields = [];
    foreach (preg_split('/\s+/', trim($m[1])) as $pair) {
        if (str_contains($pair, '=')) {
            [$k, $v] = explode('=', $pair, 2);
            $fields[$k] = $v;
        }
    }
    if (!isset($fields['mem_mb'], $fields['event'])) {
        continue;
    }
    $snapshots[] = [
        'event'   => $fields['event'],
        'mem_mb'  => (float) $fields['mem_mb'],
        'peak_mb' => isset($fields['peak_mb']) ? (float) $fields['peak_mb'] : null,
        'rooms'   => isset($fields['rooms']) ? (int) $fields['rooms'] : null,
        'line'    => $line,
    ];
}

if (count($snapshots) === 0) {
    fwrite(STDERR, "No parseable [MEMORY] snapshots in {$logPath}\n");
    exit(2);
}

$baseline = null;
foreach ($snapshots as $snap) {
    if ($snap['event'] === 'worker_start') {
        $baseline = $snap['mem_mb'];
        break;
    }
}
if ($baseline === null) {
    $baseline = $snapshots[0]['mem_mb'];
}

$warmupCount = min(3, count($snapshots));
$warmupEnd = $snapshots[$warmupCount - 1]['mem_mb'];
$effectiveBaseline = max($baseline, $warmupEnd);
$limitMb = $effectiveBaseline * $threshold;

$maxMem = 0.0;
$violations = [];
$monotonicStreak = 0;
$prevMem = null;

foreach ($snapshots as $i => $snap) {
    $maxMem = max($maxMem, $snap['mem_mb']);
    if ($i < $warmupCount) {
        $prevMem = $snap['mem_mb'];
        continue;
    }
    if ($snap['mem_mb'] > $limitMb) {
        $violations[] = sprintf(
            'snapshot #%d event=%s mem_mb=%.2f exceeds limit %.2f (%.0f%% of baseline %.2f)',
            $i + 1,
            $snap['event'],
            $snap['mem_mb'],
            $limitMb,
            $threshold * 100,
            $effectiveBaseline
        );
    }
    if ($prevMem !== null && $snap['mem_mb'] > $prevMem + 0.01) {
        $monotonicStreak++;
    } else {
        $monotonicStreak = 0;
    }
    $prevMem = $snap['mem_mb'];
}

echo "Memory audit analysis: {$logPath}\n";
echo str_repeat('-', 60) . "\n";
echo "Snapshots parsed:     " . count($snapshots) . "\n";
echo "Baseline (worker):    {$baseline} MB\n";
echo "Effective baseline:   {$effectiveBaseline} MB (after warm-up)\n";
echo "Threshold:            " . ($threshold * 100) . "%\n";
echo "Limit:                " . number_format($limitMb, 2) . " MB\n";
echo "Peak observed:        " . number_format($maxMem, 2) . " MB\n";
echo str_repeat('-', 60) . "\n";

if (count($violations) > 0) {
    echo "FAIL: " . count($violations) . " snapshot(s) exceed threshold:\n";
    foreach (array_slice($violations, 0, 10) as $v) {
        echo "  - {$v}\n";
    }
    if (count($violations) > 10) {
        echo "  ... and " . (count($violations) - 10) . " more\n";
    }
    exit(1);
}

if ($monotonicStreak >= 10) {
    echo "FAIL: monotonic memory increase detected ({$monotonicStreak} consecutive rises)\n";
    exit(1);
}

echo "PASS: memory within " . ($threshold * 100) . "% of baseline after warm-up.\n";
exit(0);
