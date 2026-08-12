# 029 — WebSocket Origin allow-list (optional)

## Status

Accepted

## Context

`server.php` `onWebSocketConnected` accepted every completed WebSocket
handshake without inspecting the HTTP `Origin` header. Any web page on any
domain could open a socket and speak the lotto protocol (subject to existing
rate limits and auth guards).

### Auth model and CSWSH severity (honest assessment)

This project uses **token-based** sessions, not cookie-based ambient auth:

- `AuthService::login()` returns a `session_token` in the JSON response; the
  client stores it in JavaScript (`localStorage` / in-memory) and sends it on
  `reconnect` — not via `HttpOnly` cookies.
- `SessionGuardService::claimUserSession()` binds `$connection->sessionToken`
  and `$worker->sessionTokens` after explicit `login` / `register` /
  `reconnect` with a known token string.

Therefore the classic **Cross-Site WebSocket Hijacking (CSWSH)** outcome —
a malicious page silently riding the victim's browser cookies to act as the
logged-in user — **does not apply** here. An attacker page cannot obtain the
victim's `session_token` without XSS or social engineering.

### Residual risk (what Origin filtering still helps with)

| Risk | Severity | Notes |
|------|----------|-------|
| Connection-slot / worker resource consumption | Low–medium | Foreign origins can open sockets up to `MAX_TOTAL_PLAYERS` (ADR-005) and send pre-auth traffic (`register`/`login`/`ping`) within ADR-003 rate limits |
| Unauthenticated protocol spam | Low | Bounded by 15 packets/sec/connection; still wastes CPU parsing JSON |
| Defense-in-depth / OWASP guidance | Low | Origin checks are recommended for browser-facing WebSockets even when cookies are not used |
| Non-browser clients (no `Origin`) | N/A | CLI tools, tests, and native clients typically omit `Origin`; allow-list is opt-in |

This is **not** a critical vulnerability given the token model; it is a
**deployment hardening** control for production browser traffic.

## Decision

1. **Configuration:** `LOTTO_ALLOWED_ORIGINS` — comma-separated list of
   allowed origin strings (e.g. `https://lotto.example.com,http://localhost:8080`).
   Read via `lottoRuntimeEnv()` (same surface as `LOTTO_WS_PORT`).
   **Unset or empty = allow all** (preserves local dev and existing tests).

2. **Enforcement point:** Top of `onWebSocketConnected`, after the
   `MAX_TOTAL_PLAYERS` gate (ADR-005) and **before** connection-field init
   and `hello`.

3. **Origin source:** Workerman 5.x passes an HTTP `Request` as the second
   callback argument: `$request->header('origin')`. Fallback:
   `$_SERVER['HTTP_ORIGIN']` when the request object is unavailable.

4. **When allow-list is non-empty:**
   - Exact string match (case-sensitive) against one list entry.
   - Missing or non-matching `Origin` → reject (strict mode).
   - Send `error.origin_forbidden` (JSON error packet), then
     `closeWithCode($connection, 4002, 'origin_forbidden')` — same
     belt-and-suspenders pattern as ADR-005 / `error.server_full`.
   - Log `WARNING` via `$worker->logger` (origin value + `conn_id`).

5. **Worker storage:** Parsed list cached on `$worker->allowedOrigins`
   (`null` = allow all, `array` = strict list) in `onWorkerStart` — not a
   Connection Runtime Field.

6. **No Handler/Service changes** — bootstrap / connection lifecycle only.

## Consequences

**Positive:**

- Production operators can restrict browser WebSocket sources without code
  changes.
- Default-off behavior avoids breaking dev, tests, and non-browser clients.

**Negative:**

- Operators must list every legitimate browser origin (scheme + host + port).
- Reverse-proxy deployments must ensure the browser `Origin` reflects the
  public site URL the user visits (usually automatic).
- Strict mode rejects connections with no `Origin` header (intentional).

**Compatibility:** New env var only; no protocol packet shape changes for
allowed connections. New error code `error.origin_forbidden` and WS close
code `4002` documented here (protocol registry update optional follow-up).

## Amendments to other documents

- `README.md` § deployment — document `LOTTO_ALLOWED_ORIGINS`.
- `docs/LOCAL_ENVIRONMENT.md` — document `LOTTO_ALLOWED_ORIGINS`.
