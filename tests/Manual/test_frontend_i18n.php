<?php

declare(strict_types=1);

/**
 * EPIC-12.6 — Frontend i18n parity tests
 * Run: php tests/Manual/test_frontend_i18n.php
 */

$root = dirname(__DIR__, 2);
$localeDir = $root . '/public/locales';
$langs = ['en', 'ru', 'es', 'fr', 'zh', 'tr'];

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

/** @return array<string, mixed> */
function flattenKeys(array $data, string $prefix = ''): array
{
    $out = [];
    foreach ($data as $key => $value) {
        $path = $prefix === '' ? (string)$key : "$prefix.$key";
        if (is_array($value)) {
            $out += flattenKeys($value, $path);
        } else {
            $out[$path] = $value;
        }
    }
    return $out;
}

echo "=== EPIC-12.6 Frontend i18n tests ===\n\n";

$enPath = $localeDir . '/en.json';
if (!is_file($enPath)) {
    fail('en.json exists');
    exit(1);
}

$en = json_decode((string)file_get_contents($enPath), true);
if (!is_array($en)) {
    fail('en.json valid JSON');
    exit(1);
}
ok('en.json valid JSON');

$enKeys = array_keys(flattenKeys($en));
ok('en.json key count: ' . count($enKeys));

foreach ($langs as $lang) {
    $path = $localeDir . "/$lang.json";
    if (!is_file($path)) {
        fail("locale file: $lang.json");
        continue;
    }
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data)) {
        fail("locale JSON: $lang");
        continue;
    }
    $keys = array_keys(flattenKeys($data));
    $missing = array_diff($enKeys, $keys);
    $extra = array_diff($keys, $enKeys);
    if ($missing === [] && $extra === []) {
        ok("locale parity: $lang (" . count($keys) . ' keys)');
    } else {
        fail("locale parity: $lang", 'missing=' . count($missing) . ' extra=' . count($extra));
    }
}

// Error code coverage for ANCHOR_PROTOCOL.md registry
$errorKeys = array_filter($enKeys, static fn($k) => str_starts_with($k, 'errors.'));
$requiredErrors = [
    'auth_required', 'room_not_found', 'not_your_turn', 'server_full', 'room_full',
    'auth_invalid_credentials', 'auth_username_taken', 'invalid_json',
];
foreach ($requiredErrors as $code) {
    in_array("errors.$code", $errorKeys, true)
        ? ok("error key: errors.$code")
        : fail("error key: errors.$code");
}

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
