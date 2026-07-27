<?php

declare(strict_types=1);

/**
 * EPIC-12.6 — Frontend structure integration tests
 * Run: php tests/Manual/test_frontend_structure.php
 */

$root = dirname(__DIR__, 2);
$public = $root . '/public';

$required = [
    'index.html',
    'css/style.css',
    'js/ws.js',
    'js/app.js',
    'js/ui.js',
    'js/i18n.js',
    'locales/en.json',
    'locales/ru.json',
    'locales/es.json',
    'locales/fr.json',
    'locales/zh.json',
    'locales/tr.json',
];

$screenIds = [
    'auth-screen',
    'lobby-screen',
    'game-screen',
    'admin-panel',
    'rules-modal',
    'apartment-modal',
    'game-over-modal',
    'reconnect-overlay',
];

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

echo "=== EPIC-12.6 Frontend structure tests ===\n\n";

foreach ($required as $rel) {
    $path = $public . '/' . $rel;
    is_file($path) ? ok("file exists: $rel") : fail("file exists: $rel", 'missing');
}

$html = @file_get_contents($public . '/index.html');
if ($html === false) {
    fail('index.html readable');
} else {
    ok('index.html readable');
    foreach ($screenIds as $id) {
        str_contains($html, "id=\"$id\"") ? ok("screen id: $id") : fail("screen id: $id");
    }
    foreach (['js/i18n.js', 'js/ws.js', 'js/ui.js', 'js/app.js'] as $script) {
        str_contains($html, $script) ? ok("script tag: $script") : fail("script tag: $script");
    }
}

$ws = @file_get_contents($public . '/js/ws.js');
if ($ws && str_contains($ws, 'PING_INTERVAL_MS = 2500')) {
    ok('ws.js: ping interval 2.5s');
} else {
    fail('ws.js: ping interval 2.5s');
}

if ($ws && str_contains($ws, "sendAction('reconnect'")) {
    ok('ws.js: auto reconnect action');
} else {
    fail('ws.js: auto reconnect action');
}

$app = @file_get_contents($public . '/js/app.js');
if ($app && str_contains($app, 'animationQueue') && str_contains($app, '>= 3')) {
    ok('app.js: animation queue max 3');
} else {
    fail('app.js: animation queue max 3');
}

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
