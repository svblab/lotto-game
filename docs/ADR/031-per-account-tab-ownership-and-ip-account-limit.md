# 031 — Per-account tab ownership and IP account limit

## Status

Accepted (documentation — EPIC-031a). Client fix EPIC-031b and server guard
EPIC-031c follow this ADR.

## Numbering note

ADR **030** is intentionally **not** used on `main`. The separate
`feature/room-chat-files` branch may already claim **030** for an unrelated
feature. When branches merge, reconcile numbering so **030** stays with
chat-files and **031** stays with this work — do not renumber without an
explicit merge plan.

## Context

Two different concerns were conflated into one client-side mechanism, producing
a real bug:

1. **Legitimate UX (same account, multiple tabs):** when the same user opens a
   second tab, the first tab should gracefully hand off session ownership —
   this behavior is correct and must be preserved.

2. **Anti-fraud (multi-accounting):** limiting how many distinct accounts can
   operate from one network — this cannot be solved in `localStorage` because
   another browser profile bypasses it entirely.

### Client bug (confirmed in code)

`public/js/app.js` uses a single global `localStorage` key
`lotto_active_tab_id` (`STORAGE_OWNER_TAB`) storing only a tab id, not scoped
to `user_id`.

`claimSessionOwnership()` (via `markActiveSession()` on successful login /
reconnect) unconditionally overwrites that key. Any other tab in the **same
localStorage partition** receives a `storage` event and calls
`relinquishSessionToOtherTab()` — even when the other tab is a **different
account**. That function clears state and shows the auth screen with **no**
toast or explanation.

Repro: two Incognito windows share one localStorage partition (standard browser
behavior). Logging in as account B in the second window silently force-logs-out
account A in the first — with no user-facing reason.

This mechanism provides **zero** multi-accounting protection; a different
browser profile bypasses it completely.

### Server gap

No server-side limit exists on how many **distinct** authenticated `user_id`s
can be live from one remote IP. `SessionGuardService` enforces one connection
per user (ADR-026), not one user per IP.

Per ANCHOR_CORE.md § Database Ownership, this guard uses **live** worker RAM
(`$worker->userConnections` cross-referenced with connection remote IP) — not a
new SQLite table.

## Decision — Part A (client UX, EPIC-031b)

**1. Per-account tab ownership record**

Store the owning `user_id` alongside the tab id in the `STORAGE_OWNER_TAB`
mechanism. Recommended shape: JSON value `{"tabId":"<uuid>","userId":<int>}`
in the existing key (one key, both fields). Alternative: a second key is
allowed if equivalent.

**2. `storage` event handler**

Call `relinquishSessionToOtherTab()` only when the new owner's `userId`
**matches** the current tab's logged-in `userId` (true same-account handoff).

If a **different** account claims ownership in another tab sharing the same
partition (e.g. second Incognito window, different user), the current tab's
session must **not** be affected — both accounts run independently.

**3. Legacy / malformed values**

Bare legacy tab-id strings or records missing `userId` must **not** trigger
relinquish (fail-safe: do not kick unless same-account ownership is provable).

**4. Same-account handoff UX**

When relinquish is semantically correct, keep today's graceful behavior (clear
active flag, disconnect, show auth screen). No new toast required in EPIC-031b
unless product requests it later.

**Files:** `public/js/app.js` only.

## Decision — Part B (server anti-fraud, EPIC-031c)

**1. Constant `MAX_ACCOUNTS_PER_IP`**

| Constant | Value | Rationale |
|----------|-------|-----------|
| `MAX_ACCOUNTS_PER_IP` | **3** | Stakeholder confirmed limit must **not** be 1 (family/office/CGNAT). Value 2 still false-positives a typical three-person household on one Wi‑Fi. **3** is the midpoint of the approved 2–4 range: allows a normal shared home network while blocking a fourth **distinct** live account from the same IP — the abuse pattern this heuristic targets. Value 4 would allow a comfortable “main + three alts” setup. |

Defined in `src/Core/Constants.php` and registered in ANCHOR_CORE.md Part 6.

**2. `IpAccountLimitService` (Auth module)**

New small helper class. Stateless: no persistent maps on `$worker`.

Algorithm on **new authentication** (see scope below):

- Resolve remote IP via Workerman `TcpConnection::getRemoteIp()` (confirm exact
  API against `vendor/workerman` during implementation, same as ADR-029 Origin
  work).
- Iterate `$worker->userConnections`; for each live connection, compare
  `getRemoteIp()` to the incoming connection's IP.
- Count **distinct** `user_id` keys already registered (each key is one live
  session per ADR-001 / ADR-026).
- If the logging-in `user_id` is **already** among those distinct ids at that
  IP, allow (re-login / session takeover — count does not increase).
- If accepting this login would make distinct ids at that IP **exceed**
  `MAX_ACCOUNTS_PER_IP`, reject.

**3. Enforcement point**

`AuthHandler` — after successful credential validation, **before**
`claimUserSession()`:

- `handleLogin()` — always checked.
- `handleRegister()` auto-login path — checked (new `user_id` joining the IP's
  live set; same as login).
- `handleReconnect()` — **not** checked (reconnect is not a new account joining
  the IP count).

Do **not** grow `AuthService::login()` (already near handler size budget);
handler-level guard keeps Auth module boundaries clean.

No `server.php` change: construct `IpAccountLimitService` inside
`AuthHandler` (stateless helper).

**4. Client-facing error (honest, not disguised)**

Unlike ADR-028 login lockout (generic invalid credentials to prevent username
enumeration), the user **must** understand why they were blocked.

| Field | Value |
|-------|-------|
| `code` | `error.auth_too_many_accounts_same_network` |
| `message` | `Too many accounts are already signed in from this network.` (or equivalent clear English; may be surfaced via i18n later) |

Mapped in `AuthHandler::mapLoginError()` (or adjacent helper). Connection stays
open; no new WebSocket close code.

**5. Registry updates (EPIC-031a)**

- `ANCHOR_PROTOCOL.md` § Error Packet — new code + paragraph.
- `ANCHOR_CORE.md` Part 1 § Global Constants and Part 6 § Global Constants
  (names), § Class Names (`IpAccountLimitService`), Auth module file list.

## Limitations (honest)

This is a **heuristic**, not a guarantee:

| Scenario | Effect |
|----------|--------|
| Shared NAT / CGNAT / office networks | **Over-trigger** — unrelated legitimate users may share one public IP; `3` reduces but does not eliminate false lockouts |
| VPN / proxy / multi-hop routing | **Under-trigger** — one actor can obtain many IPs and run many accounts |
| Different browser profiles / devices off-LAN | **Bypass** — unaffected by IP counting |
| Worker restart | Live IP counts reset (ephemeral RAM, same as other connection registry state) |
| Reverse proxy without correct IP forwarding | `getRemoteIp()` may reflect proxy IP only — deployment must align with ADR-027 TLS/proxy docs |

Do not oversell this as complete multi-accounting prevention. Part A fixes a
real client bug; Part B adds a proportionate server-side friction layer.

## Consequences

**Positive:**

- Different accounts in one localStorage partition no longer kick each other
  (Part A).
- Same-account multi-tab handoff remains correct (Part A).
- Server rejects excessive distinct live accounts per IP with an honest error
  (Part B).
- Reconnect at cap is not blocked (Part B).
- No new SQLite tables; fits Database Ownership rules.

**Negative:**

- IP heuristic false positives/negatives (see Limitations).
- `MAX_ACCOUNTS_PER_IP = 3` may need tuning after production observation.
- Client must handle new error code in a future UI pass (EPIC-031c may add
  manual tests only; i18n optional follow-up).

**Follow-up epics:**

- EPIC-031b — `public/js/app.js` + manual tab-ownership tests.
- EPIC-031c — `Constants.php`, `IpAccountLimitService.php`, `AuthHandler.php`,
  manual IP-limit tests.

## Out of scope

- Persistent IP ban lists or SQLite audit tables.
- Changing `SessionGuardService` single-session-per-user rules.
- Register throttling beyond the IP account cap (ADR-028 EPIC-5b placeholder).
- Cookie / CSWSH changes (ADR-029 covers Origin; token model unchanged).

---

## Addendum — Trusted proxy client IP resolution (EPIC-031c-a / EPIC-031c-b)

### Status

Accepted (folded into EPIC-031c before server guard implementation).

### Problem

ADR-027 production deploy terminates TLS at nginx/Caddy and proxies to plain
`ws://127.0.0.1:8080`. Raw `TcpConnection::getRemoteIp()` on the Workerman
side then returns the **proxy** address (`127.0.0.1`) for every connection.
Without correction, `MAX_ACCOUNTS_PER_IP` would apply to the **entire server**
in production — a functional break, not a heuristic limitation.

### Decision — trust boundary

**1. Resolved client IP (`clientRemoteIp`)**

At WebSocket handshake (`onWebSocketConnected`), resolve and store on the
connection as `$connection->clientRemoteIp` (ANCHOR_CORE.md § Connection
Runtime Fields). All IP-account counting uses this bucketing key, not raw
`getRemoteIp()` alone.

**2. When TCP peer is a trusted proxy**

If `getRemoteIp()` is in `LOTTO_TRUSTED_PROXY_IPS` (env via
`lottoRuntimeEnv()`, default `127.0.0.1,::1` — matches ADR-027 local proxy):

- Read real client IP from handshake `Request::header()`:
  1. First valid IP in `X-Forwarded-For` (leftmost entry)
  2. Else valid `X-Real-IP`
- **Trust** these headers only from trusted peers — the proxy is the trust
  boundary.

**3. When TCP peer is NOT a trusted proxy (direct WS connect)**

- Use raw `getRemoteIp()` only.
- **Do not** read or trust `X-Forwarded-For` / `X-Real-IP` — a direct client
  could forge them.

**4. Trusted proxy, unresolvable client IP**

If peer is trusted but neither header yields a valid IP (missing, empty,
forged non-IP):

- `Logger::write('WARNING', ...)` with `conn_id` and peer IP.
- Bucketing key: sentinel `__trusted_proxy_unresolved__` (constant in
  `IpAccountLimitService`) — all such connections share **one** bucket;
  `MAX_ACCOUNTS_PER_IP` still applies (fail-safe, not unlimited).
- Do not crash.

**5. Implementation placement**

- `IpAccountLimitService` — resolve logic, counting, sentinel constant.
- `server.php` — one `attachClientRemoteIp($connection, $request)` call at
  handshake (after Origin gate, with connection field init).
- `AuthHandler` — enforcement before `claimUserSession()` on login/register
  auto-login only.

**6. Deployment**

nginx example in `README.md` already sets `X-Forwarded-For` and `X-Real-IP`.
Caddy `reverse_proxy` forwards `X-Forwarded-For` by default. Document
`LOTTO_TRUSTED_PROXY_IPS` alongside `LOTTO_ALLOWED_ORIGINS`.

### Limitations table (updated)

| Scenario | Effect |
|----------|--------|
| Shared NAT / CGNAT / office networks | **Over-trigger** — unrelated users may share one public IP; `3` mitigates but does not eliminate |
| VPN / proxy / multi-hop routing | **Under-trigger** — many IPs per actor |
| Different browser profiles / devices off-LAN | **Bypass** |
| Worker restart | Live IP counts reset (ephemeral RAM) |
| Reverse proxy TLS (ADR-027) | **Handled** — trusted-proxy + `X-Forwarded-For` / `X-Real-IP` when peer is trusted; nginx/Caddy examples document required headers |
| Trusted proxy without client IP headers | **Fail-safe** — sentinel bucket + WARNING; not unlimited |

Replace the prior Limitations table row “Reverse proxy without correct IP
forwarding” with the two rows above in operational docs; the original table
in this ADR’s main body remains historical context for EPIC-031a.

