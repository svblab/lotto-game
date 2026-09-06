# EPIC-19 — Production Backup / Restore Evidence (G6)

**Gate ID:** G6  
**Date (UTC):** 2026-09-06  
**Operator:** Cursor operational verification session  
**Production SHA:** `b3531d14871f3963c8f4489d85fa1551fbfaf2af` (`b3531d1`)  
**VPS:** `186.246.50.81` (`rusbingo.online`)  
**Procedure:** `docs/ADMIN_VPS_DEPLOY.md` §7 + `docs/RELEASE_CONTRACT_V1.md` §11

**Conclusion:** **EPIC-19 COMPLETE — G6 PASS**

---

## A. Identity

| Field | Value |
|-------|--------|
| Production SHA | `b3531d14871f3963c8f4489d85fa1551fbfaf2af` |
| VPS | `186.246.50.81` / hostname `rusbingo.online` |
| Timestamp (UTC) | 2026-09-06 ~06:21 |
| Operator | Cursor G6 session |
| Source database | `/opt/lotto-game/game.db` |
| Database owner | `www-data:www-data` (mode `644` after restore) |

### Pre-procedure state (recorded)

| Check | Result |
|-------|--------|
| VPS SHA | `b3531d1` — **match** |
| `lotto-server` | `active` |
| `nginx` | `active` |
| Live DB integrity | `PRAGMA integrity_check` → `ok` |
| Pre-existing users | `admin`, `epic17_92c19235` |

---

## B. Restoration marker (non-sensitive)

Before backup, registered disposable user:

| Field | Value |
|-------|--------|
| Username | `epic19_g6marker` |
| User ID | `4` |
| Coins | `500` |
| Purpose | Identifiable row to verify backup captured state and restore recovered it |

Password and session tokens were **not** recorded in this document.

---

## C. Backup

| Field | Value |
|-------|--------|
| Method | SQLite online backup: `sqlite3 /opt/lotto-game/game.db ".backup '…'"` (server **running**) |
| Timestamp (UTC) | `20260906_062115` |
| Artifact | `/opt/lotto-game/backups/game_epic19_20260906_062115.db` |
| Size | `20480` bytes |
| SHA-256 | `7e2e298509e886a7dbf81fb93b2f1c024e1433b99547a201047a74faf27a147c` |
| Source integrity (before backup) | `ok` |
| Backup integrity (`PRAGMA integrity_check`) | `ok` |
| Marker in backup | `epic19_g6marker` id `4`, coins `500` — **present** |
| Result | **PASS** |

---

## D. Restore

Procedure followed runbook §7 exactly (service stopped for file replacement):

| Step | Action |
|------|--------|
| 1 | Additional safety copy: `/opt/lotto-game/backups/game_epic19_safety_20260906_062115.db` |
| 2 | `systemctl stop lotto-server` |
| 3 | Simulated data loss: `DELETE` marker user from live `game.db` (verified absent) |
| 4 | `cp` backup artifact → `/opt/lotto-game/game.db` |
| 5 | `rm -f game.db-wal game.db-shm` |
| 6 | `chown www-data:www-data game.db` |
| 7 | `systemctl start lotto-server` |

| Field | Value |
|-------|--------|
| Restore target | `/opt/lotto-game/game.db` (live production path) |
| Post-restore integrity | `PRAGMA integrity_check` → `ok` |
| Marker after restore | `epic19_g6marker` id `4`, coins `500` — **restored** |
| `systemctl is-active lotto-server` | `active` |
| HTTPS smoke | `curl -sI https://rusbingo.online/` → HTTP/2 `200` |
| WSS / app smoke | Loopback WS `hello` + `login` for `epic19_g6marker` → `success=true`, coins `500` |
| Result | **PASS** |

---

## E. Safety

| Check | Result |
|-------|--------|
| Original live DB preserved before restore | **yes** — safety copy `game_epic19_safety_20260906_062115.db` |
| Permissions after restore | `www-data:www-data`, mode `644` |
| Temporary DB in live path | **none** — only canonical `game.db` |
| Public HTTP access to `game.db` | **not exposed** — nginx returns SPA `index.html` (not SQLite bytes) |
| Public HTTP access to `/backups/` | **not exposed** — same SPA fallback; backups live outside `public/` |
| Backup artifacts committed to Git | **no** |
| Production SHA changed | **no** — still `b3531d1` |

---

## F. Cleanup

| Item | Action | Result |
|------|--------|--------|
| Disposable user `epic19_g6marker` | Deleted from `game.db` (service stopped briefly) | **removed** |
| G6 backup artifacts on VPS | Left in `/opt/lotto-game/backups/` (canonical location) | retained for operator |
| `admin`, `epic17_92c19235` | untouched | unchanged |
| Service health after cleanup | `lotto-server` `active`; DB integrity `ok` | **healthy** |

---

## G. Gate status

| Gate | Status |
|------|--------|
| **G6** | **PASS** |
| G0 | PASS |
| G1 | PASS |
| G2 | PASS |
| G3 | PASS |
| G4 | PASS |
| G5 | PASS |
| G7–G11 | **unchanged** (not evaluated in EPIC-19) |

---

## H. Verification metadata

| Check | Result |
|-------|--------|
| Runtime application code changed | **no** |
| nginx/TLS/DNS/Docker changed | **no** |
| Schema / game logic changed | **no** |
| V1.0 READY declared | **no** |

---

## I. References

- [`docs/ADMIN_VPS_DEPLOY.md`](ADMIN_VPS_DEPLOY.md) §7
- [`docs/RELEASE_CONTRACT_V1.md`](RELEASE_CONTRACT_V1.md) §11
- [`docs/EPIC_17_PRODUCTION_DEPLOYMENT.md`](EPIC_17_PRODUCTION_DEPLOYMENT.md)
- [`docs/EPIC_18_BROWSER_E2E.md`](EPIC_18_BROWSER_E2E.md)
