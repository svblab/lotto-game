# 027 — Reverse-proxy TLS termination for WSS

## Status

Accepted

## Context

`README.md` § "3. SSL (wss)" documented a production path where TLS is terminated
directly by the Workerman worker via `config/ssl.php` (`local_cert` / `local_pk`,
port 8443). `server.php` never read that file and always bound plain
`websocket://0.0.0.0:8080`, so the documented WSS deployment did not work.

Separately, `public/js/ws.js` `resolveWsUrl()` hardcoded port `8080` for all
protocols (`https:` → `wss:` but still `:8080`), which would not match the
documented `8443` even if native Workerman TLS were added.

The project targets a **1 CPU / 500 MB RAM VPS** (`ANCHOR_CORE.md` Part 1).
Native Workerman TLS would:

- Add OpenSSL handshake and buffer overhead inside the single PHP worker process
  that already holds all rooms, timers, and game state in RAM.
- Require changes to `server.php` (already ~600 lines, above the 500-line bootstrap
  target) plus client URL logic, README, tests, and certificate-renewal coupling
  (`systemctl restart lotto-server` on every cert rotation).
- Touch more than three files for a complete end-to-end fix.

Reverse-proxy TLS termination (nginx or Caddy in front of plain
`ws://127.0.0.1:8080`) is the standard pattern on small VPS hosts: the proxy
handles TLS efficiently, certbot renewals reload only the proxy, and the
Workerman worker stays a plain WebSocket listener on localhost.

## Decision

1. **TLS/WSS is terminated by an external reverse proxy** (nginx or Caddy), not
   by Workerman. The worker continues to listen on `websocket://0.0.0.0:8080`
   (or `LOTTO_WS_PORT`), bound to localhost-facing proxy upstream only in
   production firewall policy.

2. **Remove `config/ssl.php` from deployment documentation.** No native Workerman
   SSL context is implemented or planned unless a future ADR supersedes this one.

3. **Client WebSocket URL** is derived at runtime from:
   - page protocol (`https:` → `wss:`, else `ws:`),
   - optional deploy-time `<meta name="lotto-ws-port">` and
     `<meta name="lotto-ws-path">` in `public/index.html`,
   - sensible defaults: HTTP without meta → `:8080`; HTTPS with empty/absent port
     meta → omit port (proxy on 443).

4. **README.md** documents reproducible nginx and Caddy examples proxying
   `wss://your-domain.com/ws` → `ws://127.0.0.1:8080`.

No protocol packet contracts, action names, or Handler/Service business logic
changes.

## Consequences

**Positive:**

- Lower RAM/CPU risk on the constrained VPS — TLS stays out of the game worker.
- Cert renewal does not require restarting the Workerman process.
- `server.php` bootstrap stays unchanged; deployment docs match runtime behaviour.
- HTTPS production clients connect to `wss://host/ws` (port 443) instead of a
  hardcoded `:8080`.

**Negative:**

- Operators must install and maintain nginx/Caddy in addition to the systemd
  worker unit.
- WebSocket path prefix (`/ws`) must match between proxy config and
  `lotto-ws-path` meta (documented in README).

**Compatibility:** Bootstrap/deployment/client-transport only — no
`ANCHOR_PROTOCOL.md` or economy changes.
