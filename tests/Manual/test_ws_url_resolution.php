<?php

declare(strict_types=1);

/**
 * tests/Manual/test_ws_url_resolution.php
 *
 * ADR-027 — verifies client WebSocket URL resolution matches deploy meta tags
 * and reverse-proxy TLS convention (no hardcoded :8080 on HTTPS).
 *
 * Run: php tests/Manual/test_ws_url_resolution.php
 */

$root = dirname(__DIR__, 2);
$wsJs = @file_get_contents($root . '/public/js/ws.js');
$html = @file_get_contents($root . '/public/index.html');

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

/**
 * PHP mirror of public/js/ws.js resolveWsUrl() — keep in sync with ADR-027.
 *
 * @param array{portAttr: string|null, path: string} $cfg
 */
function resolveWsUrlPhp(bool $isHttps, string $host, array $cfg): string
{
    $proto = $isHttps ? 'wss:' : 'ws:';

    $portSuffix = '';
    if ($cfg['portAttr'] !== null && $cfg['portAttr'] !== '') {
        $portSuffix = ':' . $cfg['portAttr'];
    } elseif ($cfg['portAttr'] === null && !$isHttps) {
        $portSuffix = ':8080';
    }

    $path = $cfg['path'];
    $pathSuffix = $path !== '' ? ($path[0] === '/' ? $path : '/' . $path) : '';

    return $proto . '//' . $host . $portSuffix . $pathSuffix;
}

echo "=== ADR-027 WebSocket URL resolution tests ===\n\n";

if ($wsJs === false) {
    fail('public/js/ws.js readable');
} else {
    ok('public/js/ws.js readable');
    str_contains($wsJs, 'readWsDeployConfig') ? ok('ws.js exports readWsDeployConfig') : fail('ws.js exports readWsDeployConfig');
    str_contains($wsJs, 'lotto-ws-port') ? ok('ws.js reads lotto-ws-port meta') : fail('ws.js reads lotto-ws-port meta');
    str_contains($wsJs, 'lotto-ws-path') ? ok('ws.js reads lotto-ws-path meta') : fail('ws.js reads lotto-ws-path meta');
    !str_contains($wsJs, ':8080`') && !preg_match('/return `\$\{proto\}\/\/\$\{host\}:8080`/', $wsJs)
        ? ok('ws.js: no unconditional hardcoded :8080 return')
        : fail('ws.js: no unconditional hardcoded :8080 return');
}

if ($html === false) {
    fail('public/index.html readable');
} else {
    ok('public/index.html readable');
    str_contains($html, 'name="lotto-ws-port"') ? ok('index.html: lotto-ws-port meta present') : fail('index.html: lotto-ws-port meta present');
    str_contains($html, 'name="lotto-ws-path"') ? ok('index.html: lotto-ws-path meta present') : fail('index.html: lotto-ws-path meta present');
}

$cases = [
  ['HTTP dev explicit port meta', false, 'localhost', ['portAttr' => '8080', 'path' => ''], 'ws://localhost:8080'],
  ['HTTP dev no meta (default 8080)', false, 'localhost', ['portAttr' => null, 'path' => ''], 'ws://localhost:8080'],
  ['HTTPS prod proxy (empty port, /ws path)', true, 'game.example.com', ['portAttr' => '', 'path' => '/ws'], 'wss://game.example.com/ws'],
  ['HTTPS prod no meta (443 implicit)', true, 'game.example.com', ['portAttr' => null, 'path' => ''], 'wss://game.example.com'],
  ['HTTPS custom upstream port', true, 'game.example.com', ['portAttr' => '9443', 'path' => ''], 'wss://game.example.com:9443'],
  ['WS path without leading slash normalized', false, '127.0.0.1', ['portAttr' => '8080', 'path' => 'ws'], 'ws://127.0.0.1:8080/ws'],
];

foreach ($cases as [$label, $isHttps, $host, $cfg, $expected]) {
    $actual = resolveWsUrlPhp($isHttps, $host, $cfg);
    $actual === $expected ? ok($label) : fail($label, "expected {$expected}, got {$actual}");
}

$readme = @file_get_contents($root . '/README.md');
if ($readme === false) {
    fail('README.md readable');
} else {
    ok('README.md readable');
    !str_contains($readme, 'config/ssl.php') ? ok('README: config/ssl.php removed') : fail('README: config/ssl.php removed');
    str_contains($readme, 'reverse_proxy') || str_contains($readme, 'reverse proxy') || str_contains($readme, 'reverse-proxy')
        ? ok('README: documents reverse-proxy TLS')
        : fail('README: documents reverse-proxy TLS');
    str_contains($readme, 'location /ws') ? ok('README: nginx /ws example') : fail('README: nginx /ws example');
}

$adr = @file_get_contents($root . '/docs/ADR/027-reverse-proxy-tls-termination.md');
$adr && str_contains($adr, 'Accepted') ? ok('ADR-027 present and accepted') : fail('ADR-027 present and accepted');

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
