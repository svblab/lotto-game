<?php

declare(strict_types=1);

/**
 * EPIC-031b / ADR-031 Part A — per-account tab ownership (mirrors public/js/app.js).
 *
 * Run: php tests/Manual/test_tab_ownership.php
 */

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

function assert_true(bool $cond, string $label, string $reason = ''): void
{
    $cond ? ok($label) : fail($label, $reason);
}

/** Mirrors app.js parseOwnerRecord */
function parseOwnerRecord(?string $raw): ?array
{
    if ($raw === null || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (is_array($decoded) && isset($decoded['tabId']) && is_string($decoded['tabId']) && $decoded['userId'] !== null) {
        return ['tabId' => $decoded['tabId'], 'userId' => (int) $decoded['userId']];
    }
    if ($raw !== '' && $raw[0] !== '{') {
        return ['tabId' => $raw, 'userId' => null];
    }
    return null;
}

function writeOwnerRecord(string $tabId, int $userId): string
{
    return json_encode(['tabId' => $tabId, 'userId' => $userId], JSON_THROW_ON_ERROR);
}

function isSessionOwnerTab(string $myTabId, ?int $myUserId, ?array $owner): bool
{
    if ($myTabId === '' || $owner === null || $owner['userId'] === null || $myUserId === null) {
        return false;
    }
    return $myTabId === $owner['tabId'] && $myUserId === $owner['userId'];
}

function shouldRelinquishToOtherTab(string $myTabId, ?int $myUserId, ?array $owner): bool
{
    if (isSessionOwnerTab($myTabId, $myUserId, $owner)) {
        return false;
    }
    if ($myUserId === null || $owner === null || $owner['userId'] === null) {
        return false;
    }
    return $owner['userId'] === $myUserId;
}

echo "=== test_tab_ownership.php (EPIC-031b / ADR-031 Part A) ===\n\n";

// JSON owner record round-trip
$raw = writeOwnerRecord('tab-a', 42);
$parsed = parseOwnerRecord($raw);
assert_true($parsed !== null && $parsed['tabId'] === 'tab-a' && $parsed['userId'] === 42, 'parse JSON owner record');

// Legacy bare tab id — not same-account provable
$legacy = parseOwnerRecord('legacy-tab-uuid');
assert_true($legacy !== null && $legacy['userId'] === null, 'legacy bare tab id has null userId');
assert_true(
    !isSessionOwnerTab('legacy-tab-uuid', 1, $legacy),
    'legacy owner: isSessionOwnerTab false even when tab ids match'
);
assert_true(
    !shouldRelinquishToOtherTab('other-tab', 1, $legacy),
    'legacy owner: shouldRelinquish false (fail-safe)'
);

// (a) Different accounts in shared localStorage — no relinquish
$ownerB = parseOwnerRecord(writeOwnerRecord('tab-b', 2));
assert_true(
    !shouldRelinquishToOtherTab('tab-a', 1, $ownerB),
    '(a) different userId: shouldRelinquish false'
);
assert_true(
    !isSessionOwnerTab('tab-a', 1, $ownerB),
    '(a) different userId: not session owner'
);

// (b) Same account, second tab claims ownership — graceful handoff
$ownerSameUser = parseOwnerRecord(writeOwnerRecord('tab-b', 5));
assert_true(
    shouldRelinquishToOtherTab('tab-a', 5, $ownerSameUser),
    '(b) same userId other tab: shouldRelinquish true'
);
assert_true(
    isSessionOwnerTab('tab-b', 5, $ownerSameUser),
    '(b) claiming tab is session owner'
);

// Owner tab unchanged — no relinquish
$ownerSelf = parseOwnerRecord(writeOwnerRecord('tab-a', 7));
assert_true(
    !shouldRelinquishToOtherTab('tab-a', 7, $ownerSelf),
    'owner tab: shouldRelinquish false'
);

// Not logged in — no relinquish
assert_true(
    !shouldRelinquishToOtherTab('tab-a', null, $ownerSameUser),
    'no logged-in user: shouldRelinquish false'
);

echo "\n--- MANUAL VERIFICATION (browser) ---\n";
echo "1. Two Incognito windows (same partition): login user A in W1, user B in W2.\n";
echo "   Expected: W1 stays logged in as A (no silent kick).\n";
echo "2. Same user: login in W1, open W2 same account.\n";
echo "   Expected: W1 shows auth screen (same-account handoff).\n";

echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
