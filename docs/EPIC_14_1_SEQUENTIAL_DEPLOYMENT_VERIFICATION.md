# EPIC-14.1 — Sequential Deployment Verification

**Date:** 2026-09-05  
**Branch:** `feature/epic-14-1-sequential-deployment-verification`  
**VPS:** `box-963286` (`186.246.50.81`) — operator-reinstalled clean Ubuntu 22.04.5 LTS  
**Tested commit:** `2c699086f6c10af3cfc497199eb585576a6630c9` (`origin/main`)

---

## 1. Baseline

| Item | Value |
|------|-------|
| Repository base | `origin/main` @ `2c699086f6c10af3cfc497199eb585576a6630c9` |
| Branch | `feature/epic-14-1-sequential-deployment-verification` |
| VPS | `box-963286` / `186.246.50.81` |
| OS | Ubuntu 22.04.5 LTS, 1 CPU, ~539 MiB RAM, 9.6 GB disk |
| Clean VPS baseline | **PASS** — no PHP, Docker, nginx, `/opt/lotto-game`, `lotto-server`, `/tmp/lotto-game-*`, or RUSBINGO listeners (only `:22`) |
| Starting commit | `2c69908` |
| Operator test domain | **Not provided** — TLS/WSS/browser production gates **UNVERIFIED** |

---

## 2. Deployment A — Native/systemd

| Item | Result |
|------|--------|
| Deployment commit | `2c699086f6c10af3cfc497199eb585576a6630c9` |
| Runbook | `docs/ADMIN_VPS_DEPLOY.md` (§3 packages, clone, init_db, systemd, nginx HTTP) |
| **Result** | **Smoke PASS** — full production TLS path **UNVERIFIED** (no domain) |
| Service | `lotto-server.service` — `active` |
| Application path | `/opt/lotto-game` |
| HTTPS | **UNVERIFIED** — no hostname; HTTP `200` on `http://186.246.50.81/` |
| WSS | **UNVERIFIED** — no Let's Encrypt / `wss://` path |
| Browser | **UNVERIFIED** — no trusted TLS + domain |
| Authentication | **PASS** — register, login, invalid login, duplicate register (WS via nginx with `Origin: http://186.246.50.81`) |
| Lobby | **PASS** — `create_room` returned success |
| Gameplay | **UNVERIFIED** on live `:8080` — not exercised end-to-end on production DB |
| AFK | **UNVERIFIED** on live server |
| Apartment | **UNVERIFIED** on live server |
| Reconnect | **UNVERIFIED** on live server |
| Backup | **PASS** — `sqlite3 … ".backup"` per ADMIN_VPS_DEPLOY §7 |
| Restore | **PASS** — stop service, replace `game.db`, clear WAL/SHM, restart |
| Restart | **PASS** — `systemctl restart lotto-server` → `active` |
| Persistence | **PASS** — SQLite users survive restart |
| Security | **Partial PASS** — `LOTTO_ALLOWED_ORIGINS` enforced (`error.origin_forbidden` without Origin); `www-data` service user; §3.7 `ufw` **not applied** (avoid SSH risk on disposable host) |
| Regression | **51/59** test files on VPS PHP **8.1.2** — 8 failures: `False` standalone type requires PHP **8.2+** (`test_game_start.php` et al.). Windows dev host: 59/59 on newer PHP. |

**Native deployment smoke:** PASS  
**TLS/WSS production gate:** UNVERIFIED  
**Browser production gate:** UNVERIFIED  

---

## 3. Native cleanup

| Item | Result |
|------|--------|
| Native deployment removed | **PASS** — `rm -rf /opt/lotto-game` |
| systemd unit removed | **PASS** — `lotto-server.service` disabled and deleted |
| nginx configuration removed | **PASS** — `sites-available` / `sites-enabled` lotto-game removed |
| runtime removed | **PASS** — no Workerman on `:8080` |
| VPS ready for Docker | **PASS** |

---

## 4. Deployment B — Docker

| Item | Result |
|------|--------|
| Deployment commit | `2c699086f6c10af3cfc497199eb585576a6630c9` |
| Docker/Compose definition | `deploy/docker/install.sh`, `compose.yaml`, `Dockerfile` (PHP 8.4, bind `127.0.0.1:8080`) |
| **Result** | **FAIL** at `init_db.php` — `SQLSTATE[HY000] [14] unable to open database file` |
| Containers | Image `lotto-game:default` builds; app container **never reaches healthy** |
| HTTPS | **N/A** — ADR-027: TLS external to container; not deployed |
| WSS | **UNVERIFIED** |
| Browser | **UNVERIFIED** |
| Authentication | **FAIL** — install blocked before runtime |
| Lobby / Gameplay / AFK / Apartment / Reconnect | **FAIL** — no running instance |
| Backup | **N/A** — repository documents no Docker-specific backup procedure |
| Restore | **N/A** |
| Restart | **N/A** |
| Persistence | **N/A** |
| Security | **N/A** — container never started |
| Regression | `bash deploy/docker/tests/run_tests.sh` — static checks pass; **live integration FAIL** (same `init_db` error) |

**Root cause (confirmed):** Fresh Docker named volume mounts at `/app/data` as `root:root` `drwxr-xr-x`. Compose service runs as `user: "1000:1000"` (`read_only: true`). `is_writable('/app/data')` → **false** for uid 1000; `init_db.php` cannot create `game.db`.

```text
docker run --rm -u 1000:1000 -v lotto-default-data:/app/data lotto-game:default \
  php -r "var_dump(is_writable('/app/data'));"
# bool(false)
```

---

## 5. Comparison

| Capability | Native/systemd | Docker |
|---|---|---|
| Clean deployment | PASS | **FAIL** |
| Application startup | PASS | **FAIL** |
| Authentication | PASS (WS smoke) | **FAIL** |
| Lobby | PASS (create_room) | **FAIL** |
| Gameplay | UNVERIFIED (live) | **FAIL** |
| AFK | UNVERIFIED | **FAIL** |
| Apartment | UNVERIFIED | **FAIL** |
| Reconnect | UNVERIFIED | **FAIL** |
| Persistence | PASS | **FAIL** |
| Backup | PASS | N/A (undocumented) |
| Restore | PASS | N/A |
| Restart | PASS | **FAIL** |
| HTTPS | UNVERIFIED | UNVERIFIED |
| TLS | UNVERIFIED | UNVERIFIED |
| WSS | UNVERIFIED | UNVERIFIED |
| Browser/E2E | UNVERIFIED | UNVERIFIED |
| Security | Partial PASS | N/A |
| Regression | 51/59 (PHP 8.1) | Static helpers PASS; live install FAIL |

---

## 6. Findings

### Confirmed

- Clean VPS baseline after operator reinstall (Ubuntu 22.04.5).
- Canonical native model (`ADMIN_VPS_DEPLOY.md`) deploys independently: packages → `/opt/lotto-game` → `lotto-server.service` → nginx HTTP + `/ws` proxy.
- Native removal leaves no RUSBINGO systemd/nginx/app residue before Docker phase.
- Docker image builds on 512 MiB VPS; failure is **runtime permissions**, not build OOM.
- `LOTTO_ALLOWED_ORIGINS` blocks direct WS clients without Origin (expected security behavior).

### Unverified

- HTTPS / Let's Encrypt / `wss://<domain>/ws` (no operator domain).
- Browser E2E through production URL.
- Full gameplay/AFK/apartment/reconnect on live native `game.db`.
- Docker backup/restore (not documented for Compose path).

### Defects

1. **Docker fresh-volume permissions** — `deploy/docker/install.sh` `compose run … init_db.php` fails on clean VPS because uid 1000 cannot write to new named volume mount. Reproducible on `box-963286` 2026-09-05. Blocks Question 1 for Docker.
2. **ADMIN_VPS_DEPLOY PHP version gap** — Ubuntu 22.04 `apt` PHP is 8.1; eight regression tests require PHP 8.2+ (`false` standalone type). Runbook does not pin PHP ≥ 8.2.

### Documentation gaps

- No Docker-specific SQLite backup/restore runbook (report as gap, not code defect).
- `ADMIN_VPS_DEPLOY.md` should state minimum PHP 8.2+ if regression suite is a release gate.

---

## 7. ADR

```text
ADR required: NO
Reason: Failures are implementation/deployment-script defects within existing ADR-036/037
architecture, not undocumented model conflicts. Fix belongs in deploy/docker (volume
permissions on first init) and optionally ADMIN_VPS_DEPLOY PHP version note — not a new ADR.
```

---

## 8. Documentation changes

| File | Change |
|------|--------|
| `docs/EPIC_14_1_SEQUENTIAL_DEPLOYMENT_VERIFICATION.md` | This report |
| `docs/IMPLEMENTATION_STATUS.md` | EPIC-14.1 sequential verification summary |
| `docs/DEPLOYMENT_DOCUMENTATION_AUDIT.md` | Link + VPS experiment outcome |

No application code changed.

---

## 9. DoD

| Item | Status |
|------|--------|
| Protocol unchanged | PASS (no code changes) |
| ADR trigger evaluated | PASS |
| Clean VPS verified before deploy | PASS |
| Native deploy independent | PASS (smoke) |
| Native removed before Docker | PASS |
| Docker deploy independent | **FAIL** |
| Both models reproducible (Question 1) | **FAIL** (Docker) |
| Production TLS/WSS/browser gates | UNVERIFIED |
| Evidence recorded without secrets | PASS |
| PR #11 not merged / v1.0 not tagged | PASS |

---

## 10. Release decision

```text
V1.0 — BLOCKED
```

Native smoke path works, but Docker clean deployment fails reproducibly, PHP 8.1 regression gap exists on documented Ubuntu 22.04 packages, and production TLS/WSS/browser gates remain unverified without a domain.

---

## 11. Commit / PR

- Commit: (on branch)
- PR: (to be created)
- Merge status: **NOT merged**
- Ready for review: YES

---

## 12. Next smallest action

Next smallest action: Fix `deploy/docker/install.sh` (or compose init step) so the first `init_db.php` run can write to a fresh named volume as uid 1000 — e.g. one-shot root init with `chown 1000:1000 /app/data`, or documented volume permission bootstrap — then re-run this sequential verification on the same disposable VPS.
