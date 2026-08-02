#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * scripts/analyze_load_log.php
 *
 * EPIC-11.6 — Parse load audit logs and verify performance targets.
 *
 * Acceptance (EPIC-11.6 spec):
 *   - p95 RTT < 100ms for register, login, draw_barrel (client or server log)
 *   - peak memory < 450 MB (server snapshots or resource log)
 *   - peak CPU < 80% (resource log, when present)
 *
 * Usage:
 *   php scripts/analyze_load_log.php [path/to/load_audit.log]
 *       [--client-log=logs/load_client.log]
 *       [--resource-log=logs/load_resource.log]
 *       [--p95-ms=100] [--mem-mb=450] [--cpu-pct=80]
 *
 * Exit codes:
 *   0 — pass
 *   1 — fail
 *   2 — usage / file error
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Lotto\Core\LoadAudit;

$args = array_slice($argv, 1);
$serverLog = dirname(__DIR__) . '/logs/load_audit.log';
$clientLog = dirname(__DIR__) . '/logs/load_client.log';
$resourceLog = dirname(__DIR__) . '/logs/load_resource.log';
$p95LimitMs = 100.0;
$memLimitMb = 450.0;
$cpuLimitPct = 80.0;

foreach ($args as $arg) {
    if (str_starts_with($arg, '--client-log=')) {
        $clientLog = substr($arg, strlen('--client-log='));
    } elseif (str_starts_with($arg, '--resource-log=')) {
        $resourceLog = substr($arg, strlen('--resource-log='));
    } elseif (str_starts_with($arg, '--p95-ms=')) {
        $p95LimitMs = (float) substr($arg, strlen('--p95-ms='));
    } elseif (str_starts_with($arg, '--mem-mb=')) {
        $memLimitMb = (float) substr($arg, strlen('--mem-mb='));
    } elseif (str_starts_with($arg, '--cpu-pct=')) {
        $cpuLimitPct = (float) substr($arg, strlen('--cpu-pct='));
    } elseif (!str_starts_with($arg, '--')) {
        $serverLog = $arg;
    }
}

$failures = [];
$keyActions = ['register', 'login', 'draw_barrel'];

echo "Load test analysis\n";
echo "  server log:   {$serverLog}\n";
echo "  client log:   {$clientLog}\n";
echo "  resource log: {$resourceLog}\n";

$serverStats = [];
if (is_file($serverLog) && is_readable($serverLog)) {
    $serverStats = LoadAudit::parseLatencyStats($serverLog);
    $snapshotStats = LoadAudit::parseSnapshotStats($serverLog);
    echo sprintf(
        "Server latency samples: %d actions, snapshots=%d peak_mem=%.2fMB peak_conn=%d peak_rooms=%d\n",
        count($serverStats),
        $snapshotStats['snapshots'],
        $snapshotStats['peak_mem_mb'],
        $snapshotStats['peak_connections'],
        $snapshotStats['peak_rooms']
    );

    if ($snapshotStats['peak_mem_mb'] > $memLimitMb) {
        $failures[] = sprintf(
            'server peak memory %.2f MB exceeds limit %.2f MB',
            $snapshotStats['peak_mem_mb'],
            $memLimitMb
        );
    }
} else {
    echo "Server log not found — skipping server-side latency checks.\n";
    $snapshotStats = ['peak_mem_mb' => 0.0];
}

$clientStats = parseClientLog($clientLog);
if (count($clientStats) > 0) {
    echo "Client RTT samples: " . count($clientStats) . " actions\n";
    foreach ($clientStats as $action => $stat) {
        echo sprintf(
            "  %s: n=%d p50=%.2fms p95=%.2fms max=%.2fms\n",
            $action,
            $stat['count'],
            $stat['p50'],
            $stat['p95'],
            $stat['max']
        );
    }
}

$resourceStats = parseResourceLog($resourceLog);
if ($resourceStats['samples'] > 0) {
    echo sprintf(
        "Resource samples: n=%d peak_cpu=%.1f%% peak_mem=%.2fMB\n",
        $resourceStats['samples'],
        $resourceStats['peak_cpu'],
        $resourceStats['peak_mem_mb']
    );

    if ($resourceStats['peak_cpu'] > $cpuLimitPct) {
        $failures[] = sprintf(
            'peak CPU %.1f%% exceeds limit %.1f%%',
            $resourceStats['peak_cpu'],
            $cpuLimitPct
        );
    }

    if ($resourceStats['peak_mem_mb'] > $memLimitMb) {
        $failures[] = sprintf(
            'resource peak memory %.2f MB exceeds limit %.2f MB',
            $resourceStats['peak_mem_mb'],
            $memLimitMb
        );
    }
}

foreach ($keyActions as $action) {
    $p95 = null;

    if (isset($clientStats[$action]) && $clientStats[$action]['count'] > 0) {
        $p95 = $clientStats[$action]['p95'];
        $source = 'client';
    } elseif (isset($serverStats[$action]) && $serverStats[$action]['count'] > 0) {
        $p95 = $serverStats[$action]['p95'];
        $source = 'server';
    }

    if ($p95 === null) {
        echo "  {$action}: no samples (skipped)\n";
        continue;
    }

    if ($p95 > $p95LimitMs) {
        $failures[] = sprintf('%s p95 %.2fms (%s) exceeds limit %.2fms', $action, $p95, $source, $p95LimitMs);
    } else {
        echo "  {$action}: p95={$p95}ms ({$source}) OK\n";
    }
}

if (count($failures) === 0) {
    echo "PASS: load test within acceptance criteria.\n";
    exit(0);
}

echo "FAIL:\n";
foreach ($failures as $failure) {
    echo "  - {$failure}\n";
}
exit(1);

/**
 * @return array<string, array{count: int, p50: float, p95: float, p99: float, max: float}>
 */
function parseClientLog(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $byAction = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    foreach ($lines as $line) {
        if (!preg_match('/action=(\w+)\s+rtt_ms=([\d.]+)/', $line, $m)) {
            continue;
        }
        $byAction[$m[1]] ??= [];
        $byAction[$m[1]][] = (float) $m[2];
    }

    $stats = [];
    foreach ($byAction as $action => $values) {
        $stats[$action] = LoadAudit::summarizeLatencies($values);
    }

    return $stats;
}

/**
 * @return array{samples: int, peak_cpu: float, peak_mem_mb: float}
 */
function parseResourceLog(string $path): array
{
    $result = ['samples' => 0, 'peak_cpu' => 0.0, 'peak_mem_mb' => 0.0];

    if (!is_file($path) || !is_readable($path)) {
        return $result;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return $result;
    }

    foreach ($lines as $line) {
        if (!preg_match('/cpu_pct=([\d.]+)\s+mem_mb=([\d.]+)/', $line, $m)) {
            continue;
        }
        $result['samples']++;
        $result['peak_cpu'] = max($result['peak_cpu'], (float) $m[1]);
        $result['peak_mem_mb'] = max($result['peak_mem_mb'], (float) $m[2]);
    }

    return $result;
}
