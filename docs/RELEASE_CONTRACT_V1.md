# V1.0 Release Contract

**Document ID:** `RELEASE_CONTRACT_V1`
**Gate:** G0 (Release Contract)
**G0 status:** **PASS** (H1 approved 2026-09-06)
**Prepared against `main`:** `8c41891` (2026-09-06)
**G1 configuration (EPIC-16):** [`docs/G1_PRODUCTION_CONFIGURATION.md`](G1_PRODUCTION_CONFIGURATION.md) — **READY FOR HUMAN APPROVAL** (not PASS)

**Parent roadmap:** [`docs/ROADMAP_V1_PRODUCTION.md`](ROADMAP_V1_PRODUCTION.md)
**Production runbook:** [`docs/ADMIN_VPS_DEPLOY.md`](ADMIN_VPS_DEPLOY.md)

---

## 1. Purpose

This document is the **signable V1.0 release contract** (EPIC-15 / G0). It binds
operators, Human approvers, and Cursor to a single production architecture,
configuration model, operational expectations, and evidence rules **before** any
production gate (G1–G11) is executed.

This contract does **not**:

- approve production go-live;
- mark G0 PASS (requires H1);
- create a release tag;
- claim V1.0 readiness.

---

## 2. Canonical production architecture (V1.0)

The **only** canonical V1.0 production deployment:

```text
Linux VPS (Ubuntu 22.04 or 24.04 LTS)
  └── native application at /opt/lotto-game
        ├── systemd: lotto-server.service
        ├── process user: www-data
        ├── Workerman: plain WebSocket on loopback :8080
        ├── nginx: TLS termination, static SPA, proxy /ws → 127.0.0.1:8080
        └── SQLite: game.db (single writer, single worker)
```

Authoritative install and operations: **`docs/ADMIN_VPS_DEPLOY.md`**.  
Architecture alignment: **`docs/ANCHOR_CORE.md`** Part 1, **ADR-027**.

### Not canonical for V1.0 production

| Model | Role |
|-------|------|
| **Docker / Compose** (`deploy/docker/`) | Reproducible **test / staging** only — not deprecated |
| **Generic systemd multi-instance** (`deploy/systemd/`, `/opt/lotto-game-<name>/`) | Staging, second contour, experiments — not V1.0 production |

---

## 3. Application runtime expectations

| Item | V1.0 contract |
|------|----------------|
| **Language** | PHP **8.2+** CLI (`pdo_sqlite`, `mbstring`, `xml`, `curl`, `zip`) |
| **Framework** | Workerman **^5.1** (`composer.json`) |
| **Entry point** | `/usr/bin/php /opt/lotto-game/server.php start` |
| **Workers** | **Exactly one** Workerman worker — see §3.1 |
| **Web server for SPA** | nginx (or Caddy per README) — **no PHP-FPM** |
| **Game state** | In RAM inside the single worker; **restart ends all live rooms** |
| **Persistent state** | SQLite `game.db` only (accounts, coins, bans, admin password hash) |
| **Chat file transfers** | RAM-only on server — **no disk uploads** (ADR-030; `ADMIN_VPS_DEPLOY` §10) |
| **TLS** | Terminated at nginx/Caddy — **not** in Workerman (ADR-027) |
| **Public ports** | `22`, `80`, `443` only; **8080 must not** be exposed publicly |
| **Memory limits** | systemd `MemoryMax=400M`, `MemoryHigh=350M` (runbook default) |

### 3.1 Single-worker constraint (hard V1.0 requirement)

V1.0 production requires **exactly one** Workerman worker process:

- `Worker->count` must remain **1** (default in `server.php`).
- `Worker->count > 1` is **outside** the V1.0 release contract.
- Multiple workers on one `game.db` or one listen port are **not** supported.

Increasing worker count (horizontal scaling within one VPS) requires an **explicit
contract amendment** and re-evaluation of persistence, recovery, security, and E2E
gates (G5–G7, G4) before any production claim. This task does **not** change
`lotto-server.service` or `server.php`.


## 4. Canonical production filesystem layout (V1.0)

V1.0 **does not** migrate paths. The contract layout is the **current** monolith
under `/opt/lotto-game/`:

```text
/opt/lotto-game/
├── server.php              # Workerman bootstrap
├── init_db.php             # one-time DB + admin bootstrap (native)
├── game.db                 # SQLite (persistent; not in Git)
├── game.db-wal / -shm      # SQLite WAL sidecars (when active)
├── logs/
│   ├── server.log
│   └── admin_control.log   # emergency script
├── backups/                # online SQLite backups (cron)
├── public/                 # static SPA (index.html meta for WSS)
├── src/                    # application code
└── vendor/                 # Composer (--no-dev in production)
```

**systemd unit:** `/etc/systemd/system/lotto-server.service`  
**nginx site:** `/etc/nginx/sites-available/lotto-game` (symlink in `sites-enabled`)  
**TLS certs:** `/etc/letsencrypt/live/<domain>/` (Let's Encrypt)

### Post-V1.0 improvement (not part of this contract)

Filesystem separation to dedicated paths is **deferred until after V1.0**:

```text
/etc/lotto-game/       — configuration / environment file
/var/lib/lotto-game/   — database and persistent application data
/var/log/lotto-game/   — application logs
```

Until that post-V1.0 migration (EPIC-17 scope), all persistent application data
remains under `/opt/lotto-game/` as listed above. V1.0 gates G1–G11 are evaluated
against the monolith layout only.

---

## 5. Domain and public URL contract (V1.0)

Production hostname is **operator configuration**, not application source code.

### Production domain prerequisite (H3)

A **Human-approved production domain** is required **before** gates G2 and G3:

| Rule | Detail |
|------|--------|
| **H3** | Human provides or confirms the production domain |
| **G2 (DNS)** | Must **not** start without an explicitly approved production domain |
| **G3 (HTTPS/WSS)** | Must **not** start without an explicitly approved production domain |
| **G0** | Absence of a production domain is **not** a G0 defect — it is an expected H3 dependency |

Cursor and operators must **not** invent, assume, or select a production domain as
part of G0 preparation or contract drafting.

### Public URLs

For production domain `<domain>` (provided by Human, H3):

```text
Application (HTTPS):  https://<domain>/
WebSocket (WSS):      wss://<domain>/ws
```

Single hostname — **no separate WebSocket subdomain** unless Human documents an
architectural exception before G2.

### DNS (G2)

```text
A and/or AAAA  →  production VPS public IP
```

Ports **80** and **443** reachable from the Internet; **8080** not public.

### Forbidden in repository source

Production domain strings must **not** be committed in:

- `src/` (PHP application);
- `public/js/` (client logic);
- automated tests (use test/staging hostnames);
- deployment scripts except **placeholder** examples (`your-domain.com`).

Placeholders in `docs/ADMIN_VPS_DEPLOY.md` and nginx templates are allowed.

---

## 6. HTTPS contract (G3)

**Prerequisite:** Human-approved production domain (H3). G3 must not be evaluated
without it.

Production is **not** accepted on HTTP-only checks.

| Check | Requirement |
|-------|-------------|
| HTTP → HTTPS | Redirect when HTTPS vhost is active |
| HTTPS response | `200` on `/` (static SPA) |
| TLS certificate | Valid, unexpired (Let's Encrypt or equivalent) |
| Certificate chain | Complete chain served by nginx |
| Hostname validation | Certificate SAN/CN matches `<domain>` |

Cert renewal reloads **nginx only** — must not require `lotto-server` restart
(ADR-027).

---

## 7. WSS and WebSocket endpoint contract (G3)

**Prerequisite:** Human-approved production domain (H3). G3 must not be evaluated
without it.

| Layer | Contract |
|-------|----------|
| **Public endpoint** | `wss://<domain>/ws` on port **443** |
| **nginx** | `location /ws` → `proxy_pass http://127.0.0.1:8080` with Upgrade headers |
| **Workerman** | Plain `websocket://0.0.0.0:8080` (or `LOTTO_WS_PORT`, default 8080) |
| **Client meta** (`public/index.html`) | `lotto-ws-port=""`, `lotto-ws-path="/ws"` for HTTPS production |
| **URL resolution** | ADR-027: `https:` → `wss:`, omit port when meta port empty |
| **Origin validation** | `LOTTO_ALLOWED_ORIGINS=https://<domain>` in systemd unit |
| **Proxy client IP** | `LOTTO_TRUSTED_PROXY_IPS=127.0.0.1,::1` + nginx `X-Real-IP` / `X-Forwarded-For` |

### Browser minimum (G3 / G4)

Real browser on production domain must:

```text
load SPA → establish WSS (101) → authenticate → receive server events
```

---

## 8. Production configuration source (V1.0)

V1.0 uses **existing** configuration mechanisms — **no** `APP_ENV` / `APP_DOMAIN`
runtime variables are required for this release.

| Setting | V1.0 source | Notes |
|---------|-------------|-------|
| Allowed browser Origin | `Environment=LOTTO_ALLOWED_ORIGINS=…` in `lotto-server.service` | Must match `https://<domain>` exactly |
| Trusted proxy IPs | `Environment=LOTTO_TRUSTED_PROXY_IPS=…` | Default `127.0.0.1,::1` |
| Max accounts per IP | `LOTTO_MAX_ACCOUNTS_PER_IP` (optional) | Default 3 |
| Workerman port | `LOTTO_WS_PORT` (optional) | Default 8080; do not change without nginx sync |
| WSS client path/port | `<meta name="lotto-ws-port">`, `<meta name="lotto-ws-path">` in `public/index.html` | Production values: `content=""` and `content="/ws"` — see §8.1 |
| nginx `server_name` | `/etc/nginx/sites-available/lotto-game` | Operator-edited on VPS |
| TLS paths | certbot / Let's Encrypt under `/etc/letsencrypt/` | Outside Git |

### 8.1 `public/index.html` WebSocket meta-tags (production verification)

After **every** production `git pull` or code update, the operator **must** verify
before declaring the deployment successful:

```html
<meta name="lotto-ws-port" content="">
<meta name="lotto-ws-path" content="/ws">
```

| Check | Expected |
|-------|----------|
| `lotto-ws-port` | `content=""` (empty — WSS on port 443) |
| `lotto-ws-path` | `content="/ws"` |

Incorrect values (e.g. `8080`, empty path) break production WSS behind nginx.
This verification is part of the deployment acceptance checklist in
`docs/ADMIN_VPS_DEPLOY.md` §3.8 and §4. V1.0 does **not** replace this model with
`APP_DOMAIN` or other speculative configuration.

**Repository:** no `.env.example` exists today; production values live **only on
the VPS** (systemd unit + nginx). A unified `.env.example` / `APP_DOMAIN` layer is
**optional post-V1.0** documentation work (EPIC-16) — not a G0 blocker.

After any unit change: `systemctl daemon-reload` + `systemctl restart lotto-server`.

---

## 9. Secrets policy

### In Git (forbidden)

- production passwords, API tokens, TLS private keys, SSH private keys;
- `game.db` or backups containing production player data;
- admin bootstrap pending files with live credentials.

### On VPS (outside Git working tree)

| Secret / credential | V1.0 handling |
|---------------------|---------------|
| **Admin password** | Generated by `init_db.php` (stdout once) or changed in admin UI; stored as bcrypt in `game.db` |
| **Room passwords** | In SQLite only |
| **TLS private key** | `/etc/letsencrypt/` (root-managed) |
| **SSH keys** | Operator `~/.ssh/` — never passed to Cursor via prompt or repo files |

### Admin bootstrap by deployment model (OD-3)

| Deployment | Bootstrap |
|------------|-----------|
| **Canonical production** (`/opt/lotto-game`) | `sudo -u www-data php init_db.php` — password to terminal once (ADR-038 exempt) |
| **Docker / generic systemd** | AHPC (`admin-bootstrap.sh`) per ADR-038 |

Cursor must not receive production secrets through chat, Git, or project text files.

---

## 10. Database and persistence contract

| Item | Contract |
|------|----------|
| **Engine** | SQLite3 via PDO (`pdo_sqlite`) |
| **Path** | `/opt/lotto-game/game.db` |
| **Owner** | `www-data:www-data` |
| **Mode** | WAL may be active; use online backup, not raw file copy while running |
| **Init** | `init_db.php` as `www-data` before first `systemctl start` |
| **Integrity** | `sqlite3 game.db "PRAGMA integrity_check;"` → `ok` |

### Persistence gate (G5)

On production VPS at release SHA:

```text
create account → balance change → play → payout
→ systemctl restart lotto-server
→ login → verify persisted state
```

RAM-only room state is **not** expected to survive restart.

---

## 11. Backup and restore contract (G6)

Procedure: **`docs/ADMIN_VPS_DEPLOY.md` §7**.

| Item | Contract |
|------|----------|
| **Scope** | `game.db` (required); nginx/unit references documented separately |
| **Method** | `sqlite3 … ".backup '…'"` while server running (online backup) |
| **Storage** | `/opt/lotto-game/backups/` on VPS + **off-VPS copy** (operator responsibility) |
| **Restore** | Stop service → replace `game.db` → remove `-wal`/`-shm` → `chown www-data` → start |
| **Secrets in backups** | Backup artifacts contain player/admin **hashes**, not plaintext passwords — treat as sensitive |

### G6 PASS criteria (executed restore — not documentation)

G6 **cannot** be marked PASS from documentation-only evidence. The following are
**not sufficient** alone:

- existence of a backup script or cron line;
- backup configuration documented in runbooks;
- a backup file created without a verified restore test.

G6 **requires** a **real restore operation** executed on a production-like
filesystem, with post-restore verification of the database and application.

**Mandatory evidence fields** (Human H7):

```text
gate_id:              G6
git_sha:              <tested release SHA>
backup_artifact:      <path and identifier of backup file used>
restore_target:       <path where game.db was restored>
operator:             <human who executed restore>
date_time_utc:
procedure:            <steps executed>
db_integrity_check:   PRAGMA integrity_check result
application_verification:  <startup + functional smoke after restore>
result:               PASS | FAIL
```

Restore must be followed by SQLite integrity check and application startup /
functional smoke. Only then may G6 be marked PASS.

---

## 12. Restart and recovery expectations (G7)

| Action | Tool | Notes |
|--------|------|-------|
| **Normal restart** | `systemctl restart lotto-server` | Ends all live games |
| **Emergency restart** | `admin_emergency_control.sh` (root) | Stale PID / port stuck |
| **nginx reload** | `nginx -t && systemctl reload nginx` | After cert or vhost change |
| **Recovery verification** | WSS handshake + login smoke after restart | Required for G7 evidence |

Web admin "Restart" button does **not** replace SSH/`systemctl` on typical VPS
(`ADMIN_VPS_DEPLOY` §8.2).

---

## 13. Minimum security requirements (G8)

Before go-live, complete checklist against release SHA:

- SSH: key-based access preferred; document root login / password-auth policy
- Firewall: `ufw` — allow 22/80/443; **deny 8080**
- Exposed ports: only 22, 80, 443 from Internet
- nginx: TLS enabled; WebSocket proxy headers present
- `LOTTO_ALLOWED_ORIGINS` set to production `https://<domain>` (not empty)
- Rate limits / login throttle: enabled in application (ADR-003, ADR-028)
- Upload restrictions: chat files RAM-only; WS max package 2 MiB (ADR-030)
- File permissions: `game.db`, `logs/` owned by `www-data`
- Service user: `www-data` (not root)
- Debug: no permanent `LOTTO_*_AUDIT=1` in production unit
- Error leakage: no stack traces to clients in production config
- Repository: no accidental secrets in Git (manual review)
- Admin endpoints: WebSocket admin role only; emergency script root-only

Full gate evidence filed per §16.

---

## 14. Minimum observability requirements (G9)

No APM platform required for V1.0. Operator must be able to verify:

| Signal | Command / location |
|--------|-------------------|
| Service status | `systemctl status lotto-server` |
| systemd logs | `journalctl -u lotto-server` |
| Application log | `/opt/lotto-game/logs/server.log` |
| Workerman port | `ss -ltnp \| grep 8080` |
| nginx | `nginx -t`, `systemctl status nginx` |
| WSS | Browser DevTools or `wss://<domain>/ws` handshake |
| SQLite | `PRAGMA integrity_check` |
| Disk / memory | `df -h`, `free -h` |
| Backup age | `ls -lt /opt/lotto-game/backups/` |

**Runbook:** `docs/ADMIN_VPS_DEPLOY.md` §8–9, §11; roadmap stub in
`ROADMAP_V1_PRODUCTION.md` (full expansion in EPIC-20).

---

## 15. Release evidence requirements

Every **P0 gate** (G0–G11) requires a evidence record:

```text
gate_id:
git_sha:
environment:          # local | docker-staging | generic-systemd-staging | production-vps
os:
php_version:
deployment_mode:      # native-production | docker | generic-systemd
domain:               # or N/A
date_time_utc:
procedure:
expected:
actual:
result:               # PASS | FAIL
artifacts:            # paths, reports — no secrets
```

### Environment separation

| Evidence type | May satisfy |
|---------------|-------------|
| Local Windows / dev | G10 partial (regression only) |
| Docker staging (`deploy/docker/`) | Staging install / AHPC — **not** G1–G4 production |
| Generic systemd staging | D1 verification — **not** canonical production G1 |
| Phase 11 VPS (`box-963286`) | Historical advisory — **not** auto PASS for new SHA |
| Phase 14 RC report | Historical — **unverified operational gates** |
| **production-vps @ release SHA** | **G1–G7, G9, G4 browser E2E** |

**Rule:** old evidence does not carry forward without re-execution at the release SHA.

---

## 16. Git SHA and tag relationship

```text
release_candidate_sha  ──►  SHA selected when G1–G10 PASS on production VPS
tested_production_sha  ──►  MUST equal release_candidate_sha for all G1–G10 evidence
final_release_sha      ──►  MUST equal tested_production_sha at H8/H9 approval
v1.0 tag               ──►  Created ONLY after G11 (H9); MUST point to final_release_sha
```

| Artifact | When set | Rule |
|----------|----------|------|
| `release_candidate_sha` | After G10 PASS on production VPS | Single SHA for entire evidence bundle |
| `tested_production_sha` | Same session as G1–G10 | `tested_production_sha = release_candidate_sha` |
| `final_release_sha` | Human H8 approves RC | No new commits on `main` after RC without re-gating |
| **`v1.0.0` tag** (or project convention) | Human H9 after G11 | `tag^{commit} = final_release_sha` |

If `main` advances after RC selection, **all production gates must be re-run** at the
new SHA before H8/H9.

**Current state:** no RC SHA selected; no `v1.0` tag exists.

---

## 17. G0 acceptance checklist

G0 is **PREPARED** when all items below are true. G0 is **PASS** only after **H1**.

| # | Criterion | Status |
|---|-----------|--------|
| 1 | Canonical production architecture unambiguous (§2) | ✓ Prepared |
| 2 | Docker role unambiguous — test/staging only (§2) | ✓ Prepared |
| 3 | V1.0 domain/config contract documented (§5, §8) | ✓ Prepared |
| 4 | Filesystem layout fixed for V1.0 — `/opt/lotto-game` (§4) | ✓ Prepared |
| 5 | HTTPS/WSS/`/ws` contracts documented (§6–7) | ✓ Prepared |
| 6 | Persistence expectations documented (§10) | ✓ Prepared |
| 7 | Backup/restore expectations documented (§11) | ✓ Prepared |
| 8 | Restart/recovery expectations documented (§12) | ✓ Prepared |
| 9 | Minimum security requirements listed (§13) | ✓ Prepared |
| 10 | Minimum observability requirements listed (§14) | ✓ Prepared |
| 11 | Release evidence rules documented (§15) | ✓ Prepared |
| 12 | SHA / tag relationship documented (§16) | ✓ Prepared |
| 13 | Open decisions explicitly listed (§18) | ✓ Prepared |
| 14 | **No** production readiness claim in this document | ✓ Prepared |
| 15 | **Human H1 approval** recorded | ✓ **PASS** (2026-09-06) |

**G0 gate status:** **PASS** (H1 approved).

---

## 18. Open decisions (OD-1 – OD-5)

| ID | Status | V1.0 resolution |
|----|--------|-----------------|
| **OD-1** Filesystem layout | **RESOLVED** | V1.0 canonical layout is `/opt/lotto-game/` monolith (§4). `/etc`/`/var` split is post-V1.0. |
| **OD-2** `APP_ENV` / `APP_DOMAIN` | **RESOLVED** | Not required for V1.0. Contract: `LOTTO_*` + HTML meta + nginx (§8). Optional EPIC-16 later. |
| **OD-3** Native `init_db` vs AHPC | **RESOLVED** | Native production: `init_db.php`. Docker/generic systemd: AHPC (ADR-038). |
| **OD-4** Historical evidence | **RESOLVED** | Phase 11 / Phase 14 / Docker TLS reports are **historical**; G1–G10 must re-run @ release SHA (§15). |
| **OD-5** EPIC numbering | **RESOLVED** | Production stream EPIC-15–21 (`ROADMAP_V1_PRODUCTION.md`) ≠ feature `EPIC-15.x` AFK epics (`ROADMAP.md`). No renumbering. |

### Remaining human decisions (not OD blockers for G0 PREPARED)

| Item | Owner | When |
|------|-------|------|
| **H1** — approve this release contract | Human | **DONE** (G0 PASS) |
| Production domain `<domain>` | Human (H3) | Before G2 |
| VPS for production gates | Human (H2) | Before G1 |

---

## 19. Epic and gate map (reference)

| Production epic | Scope | Gate |
|-----------------|-------|------|
| **EPIC-15** | This release contract (G0, H1) | G0 |
| EPIC-16 | Optional config polish (`.env.example`, unified domain docs) | — |
| EPIC-17 | Production install automation / path migration (post-V1.0 layout) | G1 |
| EPIC-18 | Security & TLS verification | G3, G8 |
| EPIC-19 | E2E & recovery | G4, G5, G7 |
| EPIC-20 | Backup/restore execution & ops runbook | G6, G9 |
| EPIC-21 | RC, regression, tag, observation | G10, G11 |

Feature implementation epics remain in **`docs/ROADMAP.md`** (Phases 0–14).

---

## 20. References

- `docs/ROADMAP_V1_PRODUCTION.md` — gates G0–G11, phases, H1–H9
- `docs/ADMIN_VPS_DEPLOY.md` — install, backup, restart, env vars
- `docs/ANCHOR_CORE.md` — architecture SSOT
- `docs/LOCAL_ENVIRONMENT.md` — deployment models and tests
- `docs/ADR/027-reverse-proxy-tls-termination.md`
- `docs/ADR/036-docker-compose-deployment.md`
- `docs/ADR/038-admin-bootstrap-credential-delivery.md`
- `docs/PHASE_14_REPORT.md` — historical RC (superseded for gates)
