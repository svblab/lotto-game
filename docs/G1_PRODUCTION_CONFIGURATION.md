# G1 — Production Configuration Verification (EPIC-16)

**Gate:** G1 (configuration layer; full VPS install evidence is separate)  
**G1 status:** **READY FOR HUMAN APPROVAL** — not PASS  
**G0:** **PASS** (H1 approved release contract)  
**Verified against `main`:** `8c41891` (2026-09-06)  
**H3 (production domain):** **PENDING** — no domain selected; G2/G3 not started

**Contract:** [`docs/RELEASE_CONTRACT_V1.md`](RELEASE_CONTRACT_V1.md)  
**Runbook:** [`docs/ADMIN_VPS_DEPLOY.md`](ADMIN_VPS_DEPLOY.md)  
**Templates:** [`deploy/native/`](../deploy/native/)

---

## 1. Scope of this verification

EPIC-16 verifies that the **approved V1.0 configuration model** is implemented in
code and documentation. This task does **not**:

- deploy to production VPS;
- configure DNS or live TLS;
- invent or select a production domain;
- mark G1 PASS (requires Human approval + later VPS evidence at release SHA).

G1 PASS (full gate per `ROADMAP_V1_PRODUCTION.md`) additionally requires canonical
install on operator VPS @ release SHA — blocked until **H2** (VPS) and **H3** (domain).

---

## 2. V1.0 configuration model (as verified)

| Setting | Mechanism | Production value (when H3 known) |
|---------|-----------|----------------------------------|
| Browser Origin | `LOTTO_ALLOWED_ORIGINS` (systemd `Environment=`) | `https://<domain>` |
| Trusted proxy | `LOTTO_TRUSTED_PROXY_IPS` | `127.0.0.1,::1` |
| WSS port (client) | `<meta name="lotto-ws-port">` | `content=""` |
| WSS path (client) | `<meta name="lotto-ws-path">` | `content="/ws"` |
| Workerman listen | `server.php` + optional `LOTTO_WS_PORT` | `8080` on loopback |
| TLS | nginx (not Workerman) | `443` / Let's Encrypt |
| Public WSS URL | derived | `wss://<domain>/ws` |

**Not used:** `APP_ENV`, `APP_DOMAIN`, `.env` file loader, PHP-FPM.

---

## 3. Domain audit

| Location | Finding |
|----------|---------|
| `src/` | No production domain strings |
| `public/js/` | No hardcoded `wss://` or production hostnames |
| `public/index.html` | Production meta only; no domain in HTML |
| `server.php` | No domain; `LOTTO_ALLOWED_ORIGINS` from env |
| Tests | `game.example.com` only as test fixture (`test_ws_url_resolution.php`) |
| `deploy/native/*.example` | `your-domain.com` placeholders only |
| `deploy/docker/` | `rusbingo.online` in **comments** only (`common.sh`) |
| Historical reports | `rusbingo.online` in verification docs — not runtime config |

**H3:** Production domain not provided — expected; not a G1 configuration defect.

---

## 4. WebSocket `/ws` audit

| Check | Result |
|-------|--------|
| `public/index.html` committed meta | `lotto-ws-port=""`, `lotto-ws-path="/ws"` |
| `public/js/ws.js` `resolveWsUrl()` | Reads meta; HTTPS + empty port + `/ws` → `wss://<host>/ws` |
| No hardcoded `:8080` on HTTPS | Verified in `ws.js` and tests |
| No `/websocket` alternate path | None in repo |
| Server binds plain WS | `websocket://0.0.0.0:8080` (`server.php`) |
| nginx template | `location /ws` → `127.0.0.1:8080` with Upgrade headers |

**Local dev note:** Repository ships **production** meta in `index.html`. Plain HTTP
dev without nginx uses `ws://host/ws` (no `:8080`) unless operator sets
`lotto-ws-port="8080"` and `lotto-ws-path=""` locally — documented in `README.md` §3.5.

---

## 5. Origin and trusted-proxy audit

| Check | Result |
|-------|--------|
| `LOTTO_ALLOWED_ORIGINS` | Parsed in `server.php`; empty = allow all (dev-safe default) |
| Production expectation | Non-empty `https://<domain>` in systemd unit (operator) |
| Origin rejection | `error.origin_forbidden` before `hello` (ADR-029) |
| `LOTTO_TRUSTED_PROXY_IPS` | Default `127.0.0.1,::1` when unset (`IpAccountLimitService`) |
| Forwarded headers | Only trusted when peer IP in list (ADR-031) |
| nginx headers | `X-Real-IP`, `X-Forwarded-For`, `X-Forwarded-Proto` in templates |

**Production-safe rule:** empty `LOTTO_ALLOWED_ORIGINS` on a public VPS is a
**misconfiguration** (G8), not the shipped repository default for production install.

---

## 6. systemd contract audit

| Requirement | Implementation |
|-------------|----------------|
| One worker | `server.php` line `$worker->count = 1` (hardcoded) |
| Working directory | `/opt/lotto-game` in runbook + `deploy/native/lotto-server.service.example` |
| User | `www-data` |
| Entrypoint | `/usr/bin/php /opt/lotto-game/server.php start` |
| Config | `Environment=LOTTO_*` in unit (no separate env file for V1.0) |
| Restart | `Restart=always`, `RestartSec=5` |
| No PHP-FPM | Not used |
| No Docker | Native unit only for canonical production |

Generic `deploy/systemd/service.template` is **not** canonical V1.0 (multi-instance).

---

## 7. nginx contract audit

| Requirement | Template / runbook |
|-------------|-------------------|
| HTTPS public endpoint | `listen 443 ssl` — cert paths placeholder until H3 |
| `/ws` upgrade | `location /ws` with `Upgrade` / `Connection` |
| Upstream | `proxy_pass http://127.0.0.1:8080` |
| Forwarding headers | Present in `deploy/native/nginx-lotto-game.example.conf` and `ADMIN_VPS_DEPLOY.md` |
| Static SPA `/` | `root /opt/lotto-game/public` |
| HTTP → HTTPS redirect | Port 80 server block |

Live TLS not configured in this task (H3 pending).

---

## 8. Secrets and hygiene

| Check | Result |
|-------|--------|
| `.env` / `.env.example` in repo | None (configuration via systemd + nginx on VPS) |
| TLS keys in Git | None |
| Committed passwords | None found |
| Duplicate config mechanisms | Single model: `LOTTO_*` + meta + nginx/systemd templates |
| Stale `APP_ENV`/`APP_DOMAIN` in code | None in PHP/JS |

---

## 9. G1 readiness checklist

| # | Criterion | Status |
|---|-----------|--------|
| 1 | Configuration model matches approved contract | ✓ Verified |
| 2 | No hardcoded production domain in application source | ✓ Verified |
| 3 | WebSocket client contract `/ws` + empty port meta | ✓ Verified |
| 4 | `server.php` single worker (`count = 1`) | ✓ Verified |
| 5 | nginx topology consistent with ADR-027 | ✓ Verified |
| 6 | Origin / trusted-proxy production path documented | ✓ Verified |
| 7 | Secrets outside Git | ✓ Verified |
| 8 | Runbook + native templates match implementation | ✓ Verified |
| 9 | Configuration tests pass | ✓ See §10 |
| 10 | VPS install @ domain (full G1 gate evidence) | ⏳ Blocked — H2/H3 |
| 11 | **Human G1 approval** | ⏳ Pending |

**G1 (EPIC-16 configuration):** **READY FOR HUMAN APPROVAL**  
**G1 (full roadmap gate with VPS evidence):** not PASS until H2/H3 and operator install.

---

## 10. Tests executed

| Test | Command | Result |
|------|---------|--------|
| WS URL / meta / ADR-027 | `php tests/Manual/test_ws_url_resolution.php` | **21/21 PASS** |
| Frontend structure | `php tests/Manual/test_frontend_structure.php` | **55/55 PASS** |
| Origin allow-list (ADR-029) | `php tests/Manual/test_server_bootstrap.php` (tests 9–11) | **24/24 PASS** (full file) |

VPS-only / domain-only tests (G2/G3/G4) not executed — H3 not provided.

---

## 11. Human actions required

| ID | Action | When |
|----|--------|------|
| **H1** | Approve release contract | **DONE** (G0 PASS) |
| **G1 config review** | Approve this configuration verification | Before EPIC-17 VPS install |
| **H2** | Provide production VPS | Before full G1 VPS evidence |
| **H3** | Provide production domain | Before G2/G3 |
| **H5** | Approve deployment configuration on VPS | After domain known |

---

## 12. References

- ADR-027 — reverse-proxy TLS / WSS
- ADR-029 — `LOTTO_ALLOWED_ORIGINS`
- ADR-031 — `LOTTO_TRUSTED_PROXY_IPS`
- ADR-038 — native `init_db` vs AHPC (unchanged)
