# EPIC-14.1 — Version 1.0 Release Report

**Date:** 2026-09-05 (updated after VPS reconciliation)  
**Repository:** https://github.com/svblab/lotto-game  
**Verified `origin/main`:** `2c699086f6c10af3cfc497199eb585576a6630c9` (PR #10)  
**Branch:** `feature/epic-14-1-v1-release`  
**ADR required:** NO

## Release decision

**V1.0 — READY WITH UNVERIFIED GATES**

No code release blockers. Automated regression passes. VPS operational verification
partially completed on the documented **non-production** verification host
`box-963286` (`186.246.50.81`). The host does **not** match the production
deployment model in `docs/ADMIN_VPS_DEPLOY.md` (no `/opt/lotto-game`, no
`lotto-server.service`, no nginx/TLS). Production-style gates (HTTPS/WSS,
browser E2E on `/ws`, documented systemd backup cron) remain **UNVERIFIED**.

**`v1.0` tag: NOT CREATED.**

---

## VPS initial state (read-only audit)

| Item | Observed |
|------|----------|
| Hostname | `box-963286` |
| IP | `186.246.50.81` |
| OS | Ubuntu 24.04, kernel `6.8.0-138-generic` |
| SSH | `root` + project key (works) |
| `lotto-server.service` | **Not installed** (`Unit could not be found`) |
| `/opt/lotto-game` | **Absent** |
| nginx / TLS | **Not installed** (no `:80`/`:443` listeners) |
| Active process | Workerman on `:8080` from `/tmp/lotto-game-criteria-reframe/server.php` (EPIC-11.6 dev checkout, owner `cursor-user`) |
| `~/lotto-game` | Present at commit `6498144` (stale); **not** the running instance |
| Active `game.db` | `/tmp/lotto-game-criteria-reframe/game.db` (20480→28672 bytes, 52 users) |
| `sqlite3` CLI | Not installed (`apt` package absent) |
| Backup cron | Not configured for production path |

**Classification:** **development-only environment** with **deployment drift**
from `ADMIN_VPS_DEPLOY.md`. Consistent with Phase 11 docs labelling this host
**non-production verification VPS** — not the documented production target.

---

## Deployment reconciliation

`docs/ADMIN_VPS_DEPLOY.md` requires:

- `/opt/lotto-game`, `www-data`, `lotto-server.service`
- nginx + Let's Encrypt + domain
- `wss://<domain>/ws` via reverse proxy
- backups under `/opt/lotto-game/backups`

**Action taken:** No production deployment performed — `ADMIN_VPS_DEPLOY.md` §2
requires a registered domain and DNS before HTTPS/TLS setup. This VPS has **no
public hostname or certificate**. Deploying the full production stack without a
domain would not satisfy documented WSS/browser gates and could conflict with
the active EPIC-11.6 dev Workerman on `:8080`.

**Dev instance on `:8080`:** Confirmed as this repository (`/tmp/lotto-game-criteria-reframe`).
Stopped only for a **controlled restart test** (see Restart/Recovery); restarted
immediately as `cursor-user` dev instance. No data loss.

---

## Operational gates

| Gate | Result | Evidence | Environment |
|------|--------|----------|-------------|
| Browser/E2E | **UNVERIFIED** | No HTTPS endpoint; production `/ws` path unavailable | — |
| VPS smoke (dev) | **PASS** | `ws_emulator` register+create_room → `auth_result` + `room_joined` on `186.246.50.81:8080` | VPS dev |
| VPS smoke (production) | **UNVERIFIED** | No `/opt/lotto-game`, no nginx | VPS |
| TLS/WSS | **UNVERIFIED** | No nginx, no `:443`, no certificate | VPS |
| Authentication | **PASS** | WS login `ep141vps` → `auth_result` post-restart | VPS dev |
| Lobby | **PASS** | `create_room` → `room_joined` (dev WS) | VPS dev |
| Gameplay | **UNVERIFIED** | Full game lifecycle not exercised in browser | — |
| AFK/Turn / Apartment / Reconnect | **UNVERIFIED** | Not exercised this session | — |
| Backup | **PASS** (dev DB) | PHP `VACUUM INTO` on active DB → 28672 bytes; 52 users; tables `users`, `sqlite_sequence` | VPS staging path |
| Restore | **PASS** (staging) | Copy backup → readable; user count preserved | VPS staging |
| Restart/Recovery (documented) | **UNVERIFIED** | `lotto-server.service` absent | — |
| Restart/Recovery (dev) | **PASS** | Killed master PID 68474; restarted `php server.php start -d`; WS login OK; DB 52 users | VPS dev |
| Persistence (SQLite) | **PASS** | User count 52 before/after dev restart | VPS dev |
| systemd | **UNVERIFIED** (production) | Unit not installed | VPS |
| Security (production) | **UNVERIFIED** | No `LOTTO_ALLOWED_ORIGINS` production stack | VPS |
| Security (review) | **PASS** | Local test suite + config review | local |
| Automated regression | **PASS** | `php run_ALL_tests.php` — 59/59 | local |

---

## Findings → criteria

| Finding | Classification | Criterion |
|---------|----------------|-----------|
| SSH works (`root@186.246.50.81`) | Corrects prior report | VPS access |
| VPS is non-production dev/audit host | DOCUMENTATION GAP | Production deployment target |
| No `/opt/lotto-game` / `lotto-server` / nginx | Deployment drift | Production operational gates |
| No domain/TLS on test VPS | UNVERIFIED | TLS/WSS, Browser/E2E |
| Dev Workerman on `:8080` operational | PASS | VPS application smoke (dev) |
| Active DB backup/restore drill | PASS | Backup/restore (staging) |
| Dev process restart + SQLite persistence | PASS | Restart/persistence (dev only) |
| `sqlite3` CLI not installed | NON-BLOCKING DEFECT | Production backup cron per runbook |

---

## Validation executed

| Command | Result |
|---------|--------|
| SSH audit `root@186.246.50.81` | PASS |
| `php scripts/ws_emulator.php --host 186.246.50.81 --port 8080 --replay` | register+create_room PASS |
| Post-restart login WS smoke | `auth_result` PASS |
| PHP backup drill on active `game.db` | BACKUP_SIZE=28672, USERS=52 |
| Dev Workerman controlled restart | PASS |
| `php run_ALL_tests.php` | 59/59 PASS |
| `git diff --check` | PASS |

---

## Changes

Documentation only (this update). No repository code/config changes.

---

## Production-readiness impact

**Proven:** VPS reachable; dev Workerman accepts WS auth/lobby; SQLite persists
across dev process restart; staging backup/restore on active dev database.

**Unverified (blocks `v1.0` tag):** Production deployment per `ADMIN_VPS_DEPLOY.md`;
HTTPS/WSS/browser on `/ws`; `lotto-server.service` restart procedure; production
backup cron.

---

## Next steps

1. Provision production VPS per `ADMIN_VPS_DEPLOY.md` (domain, nginx, certbot,
   `/opt/lotto-game`, `lotto-server.service`) **or** designate a host with existing
   production stack.
2. Run browser E2E on `https://<domain>/` + `wss://<domain>/ws`.
3. Execute production backup cron + restore drill on stopped service.
4. If all mandatory gates PASS → merge PR #11 → tag `v1.0` on `main`.
