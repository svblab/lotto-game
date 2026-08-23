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
    'admin-screen',
    'rules-modal',
    'apartment-modal',
    'game-over-modal',
    'reconnect-overlay',
    'session-superseded-overlay',
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

if ($ws && str_contains($ws, 'RECONNECT_DELAY_MS')) {
    ok('ws.js: auto reconnect scheduling');
} else {
    fail('ws.js: auto reconnect scheduling');
}

if ($ws && str_contains($ws, 'simulateTransportDrop')) {
    ok('ws.js: simulateTransportDrop for F2 QA');
} else {
    fail('ws.js: simulateTransportDrop for F2 QA');
}

$app = @file_get_contents($public . '/js/app.js');
if ($app && str_contains($app, "sendAction('reconnect'")) {
    ok('app.js: auto reconnect action on socket open');
} else {
    fail('app.js: auto reconnect action on socket open');
}
if ($app && str_contains($app, 'animationQueue') && str_contains($app, '>= 3')) {
    ok('app.js: animation queue max 3');
} else {
    fail('app.js: animation queue max 3');
}
if ($app && str_contains($app, 'me.coins != null') && !str_contains($app, 'me.paid + me.received')) {
    ok('app.js: onGameOver uses authoritative coins only (no arithmetic fallback)');
} else {
    fail('app.js: onGameOver uses authoritative coins only (no arithmetic fallback)');
}

if ($app && str_contains($app, "e.key !== 'F2'") && str_contains($app, 'simulateTransportDrop')) {
    ok('app.js: F2 in-game reconnect QA hotkey');
} else {
    fail('app.js: F2 in-game reconnect QA hotkey');
}

if ($html && str_contains($html, 'win-chance-fill') && str_contains($html, 'game-over-chart')) {
    ok('index.html: win-chance bar and game-over chart');
} else {
    fail('index.html: win-chance bar and game-over chart');
}

$ui = file_get_contents(__DIR__ . '/../../public/js/ui.js');
if ($ui && str_contains($ui, 'createElementNS') && str_contains($ui, 'polyline')) {
    ok('ui.js: SVG line chart for game-over');
} else {
    fail('ui.js: SVG line chart for game-over');
}

if ($app && str_contains($app, 'hasPersistedSession') && str_contains($app, 'return hasPersistedSession()')) {
    ok('app.js: reconnect uses persisted localStorage token');
} else {
    fail('app.js: reconnect uses persisted localStorage token');
}

if ($app && str_contains($app, 'handleSessionSuperseded') && str_contains($app, 'location.reload()')) {
    ok('app.js: superseded session shows wait overlay then reloads');
} else {
    fail('app.js: superseded session shows wait overlay then reloads');
}

if ($ui && str_contains($ui, 'showSessionSupersededOverlay')) {
    ok('ui.js: session superseded overlay with countdown');
} else {
    fail('ui.js: session superseded overlay with countdown');
}

if ($html && str_contains($html, 'id="admin-online"') && str_contains($html, 'id="admin-memory"')) {
    ok('index.html: admin live stats elements');
} else {
    fail('index.html: admin live stats elements');
}

if ($html && str_contains($html, 'id="admin-users-select"') && str_contains($html, 'id="admin-user-search"')) {
    ok('index.html: admin user select elements');
} else {
    fail('index.html: admin user select elements');
}

if ($html && str_contains($html, 'id="admin-delete-user-btn"') && str_contains($html, 'id="admin-bulk-delete-btn"')) {
    ok('index.html: admin delete controls');
} else {
    fail('index.html: admin delete controls');
}

if ($html && !str_contains($html, 'id="admin-user-id"')) {
    ok('index.html: admin-user-id removed');
} else {
    fail('index.html: admin-user-id removed');
}

if ($ui && str_contains($ui, 'renderAdminUsersTable') && str_contains($ui, 'getSelectedAdminUserId') && str_contains($ui, 'getDeletableAdminUsers')) {
    ok('ui.js: admin user select helpers');
} else {
    fail('ui.js: admin user select helpers');
}

if ($app && str_contains($app, 'admin_get_settings') && str_contains($app, 'admin_settings_data')) {
    ok('app.js: admin settings wired');
} else {
    fail('app.js: admin settings wired');
}

if ($app && str_contains($app, 'admin_restart_server') && str_contains($app, 'admin_restart_result')) {
    ok('app.js: admin restart wired');
} else {
    fail('app.js: admin restart wired');
}

if ($app && str_contains($app, 'admin_get_users') && str_contains($app, 'admin_users_data')) {
    ok('app.js: admin_get_users wired');
} else {
    fail('app.js: admin_get_users wired');
}

if ($html && !str_contains($html, 'admin.statsHint')) {
    ok('index.html: admin.statsHint removed');
} else {
    fail('index.html: admin.statsHint removed');
}

$adminI18nKeys = ['liveStats', 'online', 'memory', 'searchUser', 'selectUser', 'onlineOnly', 'bannedOnly'];
foreach (['en', 'ru', 'es', 'fr', 'zh', 'tr'] as $lang) {
    $localePath = $public . "/locales/$lang.json";
    $localeData = json_decode((string)file_get_contents($localePath), true);
    if (!is_array($localeData)) {
        fail("locale admin keys: $lang", 'invalid JSON');
        continue;
    }
    $admin = $localeData['admin'] ?? [];
    $missing = array_filter($adminI18nKeys, static fn($k) => !isset($admin[$k]));
    if ($missing === []) {
        ok("locale admin keys: $lang");
    } else {
        fail("locale admin keys: $lang", 'missing ' . implode(', ', $missing));
    }
    if (isset($admin['statsHint'])) {
        fail("locale admin keys: $lang", 'statsHint still present');
    }
}

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
