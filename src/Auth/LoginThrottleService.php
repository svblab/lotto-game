<?php

declare(strict_types=1);

namespace Lotto\Auth;

use Lotto\Core\Constants;

/**
 * EPIC-5a / ADR-028 — in-memory per-username login failure throttling (RAM only).
 */
final class LoginThrottleService
{
    /** @var callable(): int */
    private $nowFn;

    /** @var array<string, array{failures: int, window_start: int, locked_until: int}> */
    private array $state = [];

    /**
     * @param callable(): int|null $nowFn Injectable clock for tests (default: time()).
     */
    public function __construct(?callable $nowFn = null)
    {
        $this->nowFn = $nowFn ?? static fn(): int => time();
    }

    public function isLocked(string $username): bool
    {
        $key = $this->key($username);
        $entry = $this->state[$key] ?? null;
        if ($entry === null) {
            return false;
        }

        $now = ($this->nowFn)();
        if ($entry['locked_until'] > $now) {
            return true;
        }

        if ($entry['locked_until'] > 0) {
            unset($this->state[$key]);
        }

        return false;
    }

    public function recordFailure(string $username): void
    {
        if ($this->isLocked($username)) {
            return;
        }

        $key = $this->key($username);
        $now = ($this->nowFn)();
        $window = Constants::LOGIN_THROTTLE_WINDOW_SECONDS;
        $max = Constants::LOGIN_THROTTLE_MAX_ATTEMPTS;
        $lockout = Constants::LOGIN_THROTTLE_LOCKOUT_SECONDS;

        $entry = $this->state[$key] ?? ['failures' => 0, 'window_start' => $now, 'locked_until' => 0];

        if (($now - $entry['window_start']) >= $window) {
            $entry = ['failures' => 0, 'window_start' => $now, 'locked_until' => 0];
        }

        $entry['failures']++;

        if ($entry['failures'] >= $max) {
            $entry['locked_until'] = $now + $lockout;
            $entry['failures'] = 0;
            $entry['window_start'] = $now;
        }

        $this->state[$key] = $entry;
    }

    public function recordSuccess(string $username): void
    {
        unset($this->state[$this->key($username)]);
    }

    private function key(string $username): string
    {
        return $username;
    }
}
