# 028 — Auth abuse throttling (login lockout)

## Status

Accepted (login lockout — EPIC-5a). Register throttling deferred to EPIC-5b.

## Context

ADR-003 limits inbound **packet volume** per connection (15 packets/sec). That
does not cap **failed login attempts per username**: with `MAX_TOTAL_PLAYERS`
(150) concurrent connections, each allowed 15 packets/sec, an attacker can spread
password guesses across many sockets against one account while staying under the
generic rate limit.

Username enumeration via response timing was partially mitigated in commit
`007712c` (dummy `password_verify()` when no DB row exists). A complementary
control is needed for online brute-force against known usernames.

Per ANCHOR_CORE.md § Database Ownership, ephemeral throttle counters live in
**RAM** on the Workerman worker — not SQLite.

## Decision (EPIC-5a — per-username login lockout)

**1. `LoginThrottleService`** (Auth module) holds in-memory state keyed by
exact `username` string:

| Constant | Default | Rationale |
|----------|---------|-----------|
| `LOGIN_THROTTLE_MAX_ATTEMPTS` | 5 | Legitimate users may mistype 2–3 times; 5 failures before lockout |
| `LOGIN_THROTTLE_WINDOW_SECONDS` | 300 | Rolling 5-minute window for counting failures |
| `LOGIN_THROTTLE_LOCKOUT_SECONDS` | 900 | 15-minute cooldown after threshold exceeded |

**Algorithm:**

- On failed login (`Invalid username or password` — unknown user or wrong
  password): increment failure count for that username within the current window.
  If the window elapsed, reset count to 1 with a new `window_start`.
- When failures reach `MAX_ATTEMPTS` within the window: set `locked_until =
  now + LOCKOUT_SECONDS` (counter reset for next window after lockout).
- While `locked_until > now`: reject login immediately with exception message
  `Auth rate limited` (mapped to `error.auth_rate_limited` in `AuthHandler`).
- On **successful** login: clear all throttle state for that username.
- State is stored on `$worker->loginThrottle` (single instance per worker process).

**2. Client-facing error:** `error.auth_rate_limited` with message
`Invalid username or password` — **no** remaining lockout time, attempt count,
or username-exists hint in the packet.

**3. Timing hardening (007712c):** On lockout, `AuthService::login()` still runs
`password_verify()` against the real user hash when the row exists, or the
dummy hash when it does not — matching the invalid-credential paths. Full remote
timing parity is **not** guaranteed (DB lookup still runs; network jitter remains).

**4. Restart trade-off:** Worker restart clears all throttle maps. Protection
applies to sustained online attacks between restarts; not a persistent ban list.

**5. Wiring:** `server.php` constructs `LoginThrottleService`, assigns
`$worker->loginThrottle`, injects into `AuthService` constructor. No business
logic in `server.php` beyond DI.

## Register throttling — deferred to EPIC-5b

*(Placeholder for a future addendum. Not designed or implemented in EPIC-5a.)*

Server-wide registration caps and/or per-IP limits will be specified here after
auditing whether Workerman exposes a reliable client IP behind the project's
reverse-proxy deployment. **Do not implement register throttling until EPIC-5b.**

## Consequences

**Positive:**

- Bounds online password-guessing per username without DB writes.
- Complements ADR-003 packet rate limiting.
- Generic client error avoids leaking lockout metadata.

**Negative:**

- RAM-only: restart resets counters.
- Per-worker: single-process deployment (`count = 1`) — acceptable per
  ANCHOR_CORE.md.
- Locked-out attacker can still try **other** usernames (by design).

**Compatibility:** New error code `error.auth_rate_limited` added to
`ANCHOR_PROTOCOL.md`. No changes to successful `auth_result` shape.
