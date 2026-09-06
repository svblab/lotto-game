# EPIC-17 — Canonical Production Deployment Evidence

**Date (UTC):** 2026-09-06  
**Operator:** Human (H2/H3) + Cursor deployment session  
**PRODUCTION_DEPLOY_SHA:** `b3531d14871f3963c8f4489d85fa1551fbfaf2af` (`b3531d1`)  
**Branch:** `main` (detached HEAD on VPS at deploy SHA)  
**VPS:** `186.246.50.81` (H2 approved)  
**Domain:** `rusbingo.online` (H3 approved)

**Conclusion:** **EPIC-17 COMPLETE** — production deployment established; applicable evidence recorded; remaining release gates still open.

---

## A. Release identity

| Field | Value |
|-------|--------|
| `PRODUCTION_DEPLOY_SHA` | `b3531d14871f3963c8f4489d85fa1551fbfaf2af` |
| Local `main` at task start | `b3531d1` (clean, pushed) |
| VPS `/opt/lotto-game` HEAD | `b3531d1` (detached) |
| Deployment timestamp (UTC) | 2026-09-06 ~06:09–06:13 |
| Working tree (local) | clean at task start |

---

## B. VPS preflight

| Check | Result |
|-------|--------|
| OS | Ubuntu 24.04.4 LTS |
| Hostname | `rusbingo.online` (static) |
| CPU | 1 core |
| RAM | ~543 MiB total |
| Disk `/` | 8.7G, 22% used |
| Pre-existing `/opt/lotto-game` | **none** |
| Pre-existing `lotto-server` | **none** |
| nginx (before) | inactive |
| Docker | not installed |
| DNS `A rusbingo.online` | `186.246.50.81` |

---

## C. Installation

| Item | Result |
|------|--------|
| Path | `/opt/lotto-game` |
| PHP | 8.3.6 CLI, `pdo_sqlite` present |
| Composer | 2.10.3 |
| Dependencies | `composer install --no-dev` (Workerman 5.2.2) |
| SQLite DB | `/opt/lotto-game/game.db` (`www-data`) |
| Bootstrap | native `init_db.php` as `www-data` (ADR-038 exempt path) |
| Service user | `www-data` |
| Workerman `$worker->count` | **1** (source `server.php:170`) |
| Processes | 1 master + 1 worker (Workerman normal) |
| Listen | `0.0.0.0:8080` (UFW denies public 8080) |

**Admin credentials:** generated on VPS by `init_db.php` — **not** recorded in Git or this document. Operator retrieves via secure VPS access if not already saved.

---

## D. systemd

Unit: `/etc/systemd/system/lotto-server.service`

```ini
Environment=LOTTO_ALLOWED_ORIGINS=https://rusbingo.online
Environment=LOTTO_TRUSTED_PROXY_IPS=127.0.0.1,::1
WorkingDirectory=/opt/lotto-game
User=www-data
ExecStart=/usr/bin/php /opt/lotto-game/server.php start
MemoryMax=400M
Restart=always
```

| Check | Result |
|-------|--------|
| `systemctl is-active lotto-server` | `active` |
| PHP-FPM | not used |
| Docker | not used |

---

## E. nginx / TLS

| Item | Value |
|------|--------|
| `server_name` | `rusbingo.online` |
| HTTPS | `200` on `/` (Content-Length 29493) |
| HTTP | `301` → HTTPS |
| Certificate | Let's Encrypt; CN=`rusbingo.online`; valid 2026-09-06 — 2026-12-05 |
| `/ws` proxy | `127.0.0.1:8080` with Upgrade + forwarding headers |
| Certbot renew | scheduled (certbot deploy) |

Config: `/etc/nginx/sites-available/lotto-game` (matches `deploy/native/nginx-lotto-game.example.conf` with live domain).

---

## F. WebSocket / smoke

| Check | Command / method | Result |
|-------|------------------|--------|
| Meta tags | `grep lotto-ws public/index.html` | `port=""`, `path="/ws"` |
| WSS handshake | `wss://rusbingo.online/ws` + Origin `https://rusbingo.online` | `101`, `hello` JSON |
| Origin reject | WSS + Origin `https://evil.example` | `error.origin_forbidden` (after 101 upgrade) |
| Static CSS | `curl -sI https://rusbingo.online/css/style.css` | `200` |
| Register/login (loopback smoke) | WS masked client, restart | register + login after restart OK |
| Server log fatals | `logs/server.log`, `journalctl` | no startup fatals |

---

## G. Persistence (G5 baseline)

| Step | Result |
|------|--------|
| Register `epic17_*` user | `auth_result` success, coins=500 |
| `systemctl restart lotto-server` | service `active` |
| `PRAGMA integrity_check` | `ok` |
| Login same user after restart | `auth_result` success, coins=500 |
| Live rooms after restart | cleared (expected per contract) |

---

## H. Gate status (this task only)

| Gate | Status | Evidence |
|------|--------|----------|
| **G1** (canonical install on VPS @ SHA) | **PASS** | This document §C–F @ `b3531d1` |
| **G2** (DNS) | **PASS** | `dig A rusbingo.online` → `186.246.50.81`; hostname match |
| **G3** (HTTPS/WSS) | **PASS** | HTTPS 200/301; LE cert; WSS `/ws` hello @ domain |
| **G5** (persistence restart) | **PASS** | §G — DB integrity + login after restart |
| **G4** Browser E2E | **NOT RUN** | separate gate — Human browser required |
| **G6** Backup/restore | **NOT RUN** | requires executed restore |
| **G7** Recovery | **NOT RUN** | separate controlled failure test |
| **G8** Security | **NOT RUN** | full checklist gate |
| **G9** Observability | **NOT RUN** | operator runbook gate |
| **G10** Final regression | **NOT RUN** | `run_ALL_tests.php` @ release SHA |
| **G11** Release approval | **NOT RUN** | Human go-live |

---

## I. Remaining gates (prerequisites)

| Gate | Prerequisite |
|------|----------------|
| **G4** | Browser E2E on `https://rusbingo.online` (two clients, full gameplay path) |
| **G6** | Execute backup → restore with evidence (H7) |
| **G7** | Controlled recovery scenarios |
| **G8** | Security checklist @ SHA |
| **G9** | Operator observability validation |
| **G10** | `run_ALL_tests.php` on VPS as `www-data` @ `b3531d1` |
| **G11** / **H9** | Human release approval; tag after all gates |

---

## J. Deviations

**No deviations** from `docs/ADMIN_VPS_DEPLOY.md` or `docs/RELEASE_CONTRACT_V1.md`.

Notes (not deviations):

- VPS git checkout is **detached HEAD** at `b3531d1` (expected for pinned SHA).
- Workerman listens `0.0.0.0:8080` per runbook; UFW blocks public 8080.
- G5 smoke used loopback WS with `X-Forwarded-For` (trusted-proxy path); production browser traffic uses nginx headers.

---

## K. Firewall

```
ufw: allow 22, 80, 443; deny 8080
```

---

## References

- `docs/ADMIN_VPS_DEPLOY.md`
- `docs/RELEASE_CONTRACT_V1.md`
- `docs/G1_PRODUCTION_CONFIGURATION.md`
- `deploy/native/`
