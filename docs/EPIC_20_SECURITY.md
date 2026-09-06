# EPIC-20 — Production Security Verification Evidence (G8)

**Gate ID:** G8  
**Date (UTC):** 2026-09-06  
**Operator:** Cursor security audit session  
**Production domain:** `rusbingo.online`  
**VPS:** `186.246.50.81`  
**Production SHA (audited runtime):** `b3531d14871f3963c8f4489d85fa1551fbfaf2af` (`b3531d1`)

**Conclusion:** **EPIC-20 COMPLETE — G8 PASS**

---

## 1. Identity

| Field | Value |
|-------|--------|
| EPIC | 20 (G8 security gate) |
| Gate | G8 |
| Domain | `rusbingo.online` |
| VPS | `186.246.50.81` |
| Audited application SHA (VPS `/opt/lotto-game`) | `b3531d1` |
| Audit window (UTC) | ~06:31 |
| Repository HEAD at audit time (docs only ahead) | `730ef38` — no production runtime change |

---

## 2. Scope

Areas checked per release contract §13 and task specification:

- Repository/code security (auth, sessions, SQL, XSS patterns, dangerous functions)
- Secrets in Git tree
- Authentication (disposable accounts)
- Authorization (admin guard, unauthenticated actions)
- WebSocket / Origin policy / backend exposure
- HTTP/nginx sensitive paths
- Filesystem / SQLite permissions
- Network listeners / UFW
- Security headers / TLS
- Error/debug leakage (harmless invalid inputs)
- Dependencies (`composer audit` on VPS)
- Production configuration invariants (`LOTTO_*`, HTML meta)

---

## 3. Repository / code audit

| Area | Result |
|------|--------|
| Password storage | `password_hash` / `password_verify` (bcrypt `$2y$`); timing-safe failed-login path in `AuthService` |
| SQL access | Parameterized statements via `PreparedStatements`; no string-concatenated user SQL in `src/` |
| Dangerous functions in `src/` | `AdminSettingsService::exec()` for host emergency restart script only (admin-gated); no `eval` in application `src/` |
| Origin policy | `server.php` enforces `LOTTO_ALLOWED_ORIGINS`; rejects with `error.origin_forbidden` |
| Admin authorization | `AdminService::assertAdmin()` on all admin handlers |
| WebSocket auth | Actions require bound `userId`; unauthenticated `create_room` → `error.auth_required` |
| XSS (client) | Chat uses `textContent`; some `innerHTML` for static/templates — user chat not injected via `innerHTML` |
| Debug backdoors | No test/dev bypass found in production `server.php` bootstrap |
| Secrets in repo scan | No private keys / API tokens found in tracked source patterns reviewed |

**Result:** No P0/P1 code defect identified at audited SHA.

---

## 4. Authentication

Disposable account tests (loopback WS, valid Origin, masked client frames):

| Scenario | Result |
|----------|--------|
| Registration | `auth_result` success |
| Login wrong password | `error.auth_invalid_credentials` (generic message) |
| Login correct password | `auth_result` success |
| Unauthenticated `create_room` | `error.auth_required` |
| Authenticated `create_room` | success |
| Password at rest | bcrypt prefix `$2y$10$`, length 60 — **not plaintext** |

Brute-force / spraying: **not performed** (per constraints).

**Result:** **PASS**

---

## 5. Authorization

| Scenario | Result |
|----------|--------|
| Non-admin `admin_get_settings` after register/login | Denied — `error.not_your_turn` (access blocked server-side) |
| Admin account | Not used / not modified |

**Result:** **PASS** (server-side enforcement confirmed)

---

## 6. WebSocket security

| Check | Result |
|-------|--------|
| WSS `wss://rusbingo.online/ws` + Origin `https://rusbingo.online` | Handshake `101`, `hello` |
| Invalid Origin `https://evil.example` (public WSS) | Post-handshake `error` (not protocol bootstrap) |
| Invalid Origin (loopback) | `hello` = `error` / forbidden path |
| Workerman workers | **1** worker (+ 1 master) |
| Listener | `0.0.0.0:8080` (canonical); **UFW DENY IN 8080** |
| Alternate public WS endpoint | None observed |

**Result:** **PASS**

---

## 7. HTTP / nginx

| Path | HTTP | Content | Actual exposure |
|------|------|---------|-----------------|
| `/game.db` | 200 | SPA HTML (29493 B) | **not** SQLite file |
| `/backups/` | 200 | SPA HTML | **not** directory listing |
| `/backups/game_epic19_*.db` | 200 | SPA HTML | **not** backup bytes |
| `/.git/HEAD` | 200 | SPA HTML | **not** git object |
| `/composer.json` | 200 | SPA HTML | **not** composer manifest |
| `/logs/server.log` | 200 | SPA HTML | **not** log file |
| `/server.php`, `/src/` | 200 | SPA HTML | **not** source |

| Check | Result |
|-------|--------|
| HTTP → HTTPS | `301` redirect |
| nginx `root` | `/opt/lotto-game/public` only |
| `/ws` proxy | `proxy_pass http://127.0.0.1:8080` |

**Result:** **PASS** — sensitive assets not web-downloadable.

---

## 8. Filesystem / SQLite

| Path | Owner | Mode | Notes |
|------|-------|------|-------|
| `/opt/lotto-game/game.db` | `www-data:www-data` | `644` | Contract met (owner); world-readable locally |
| `/opt/lotto-game/logs/` | `www-data:www-data` | dir `755` | Not web-exposed |
| `/opt/lotto-game/backups/` | mixed | `644` files | One G6 artifact `root:root`; not web-exposed |
| World-writable app files | none found under `/opt/lotto-game` (depth 2) | | |
| Service user | `www-data` (not root) | | |

**Result:** **PASS** for V1.0 contractual minimum; see P2/P3 findings for hardening.

---

## 9. Network

| Listener | Observed |
|----------|----------|
| `0.0.0.0:443`, `:80` | nginx |
| `0.0.0.0:22` | sshd |
| `0.0.0.0:8080` | Workerman (UFW blocks inbound) |

**UFW:** allow 22/80/443; **deny 8080** in/out rules present.

**Result:** **PASS** per V1.0 architecture (loopback path via nginx; 8080 not Internet-exposed).

---

## 10. Headers / TLS

| Check | Result |
|-------|--------|
| Certificate CN | `rusbingo.online` |
| Issuer | Let's Encrypt |
| Validity | 2026-09-06 — 2026-12-05 |
| `Strict-Transport-Security` | **absent** |
| `Content-Security-Policy` | **absent** |
| `X-Content-Type-Options` | **absent** |
| `X-Frame-Options` / `frame-ancestors` | **absent** |
| nginx `ssl_protocols` | includes TLSv1, TLSv1.1, TLSv1.2, TLSv1.3 |

Absent headers and legacy TLS versions are **not** explicit V1.0 contract blockers (§13); classified as recommendations below.

**Result:** **PASS** (contractual minimum met)

---

## 11. Error / debug leakage

| Test | Result |
|------|--------|
| Failed login response | Generic credential error; no stack trace / SQL / paths |
| Invalid Origin | Application error code only |
| systemd environment | No `LOTTO_*_AUDIT` debug flags in unit |
| nginx 404-style unknown path | SPA HTML 200 (no server path disclosure) |

**Result:** **PASS**

---

## 12. Dependency review

| Field | Value |
|-------|--------|
| Method | `composer audit` on VPS `/opt/lotto-game` @ `b3531d1` |
| Result | **No security vulnerability advisories found** |
| Changes during audit | none |

---

## 13. Production configuration invariants

| Setting | Observed |
|---------|----------|
| `LOTTO_ALLOWED_ORIGINS` | `https://rusbingo.online` |
| `LOTTO_TRUSTED_PROXY_IPS` | `127.0.0.1,::1` |
| `lotto-ws-port` meta | `""` |
| `lotto-ws-path` meta | `/ws` |
| Production SHA after audit | `b3531d1` unchanged |

---

## 14. Findings

| ID | Severity | Finding | Evidence | Release impact |
|----|----------|---------|----------|----------------|
| G8-01 | P3 | Security headers (HSTS, CSP, X-Content-Type-Options, frame protection) not set on HTTPS responses | `curl -sI https://rusbingo.online/` | Non-blocking hardening |
| G8-02 | P3 | nginx `ssl_protocols` still allows TLSv1 / TLSv1.1 | `nginx -T` on VPS | Non-blocking; upgrade path for future hardening |
| G8-03 | P3 | Non-admin admin denial returns `error.not_your_turn` instead of dedicated admin error | WS authz test | Denied correctly; message code misleading only |
| G8-04 | P2 | `game.db` mode `644` world-readable to local OS users | `ls -la` on VPS | Evaluate before release; mitigated on single-tenant VPS, not web-exposed |
| G8-05 | P2 | G6 backup artifact owned `root:root` mode `644` in `backups/` | `ls -la backups/` | Local readability of hash-containing backup; not web-exposed |
| G8-06 | P3 | SPA fallback returns HTTP 200 for unknown paths (including sensitive-looking URLs) | path probe table | No underlying file served; monitor if routing changes |

**No P0/P1 security findings identified.**

---

## 15. Changes

```
No runtime, application, database, nginx, TLS, DNS, firewall, or production configuration changes were made.
Only the G8 evidence document was added/updated.
```

Disposable test users `epic20_g8a`, `epic20_g8b` created for tests and **removed** afterward.

---

## 16. Gate status

| Gate | Status |
|------|--------|
| **G8** | **PASS** |
| G0–G7 | PASS (unchanged) |
| G9–G11 | **unchanged** |

---

## 17. References

- [`docs/RELEASE_CONTRACT_V1.md`](RELEASE_CONTRACT_V1.md) §13
- [`docs/ADMIN_VPS_DEPLOY.md`](ADMIN_VPS_DEPLOY.md)
- [`docs/EPIC_20_RECOVERY.md`](EPIC_20_RECOVERY.md)
- [`docs/ANCHOR_PROTOCOL.md`](ANCHOR_PROTOCOL.md)
