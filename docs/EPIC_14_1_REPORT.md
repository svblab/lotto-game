# EPIC-14.1 — Version 1.0 Release Report

**Date:** 2026-09-05  
**Repository:** https://github.com/svblab/lotto-game  
**Verified `origin/main`:** `2c699086f6c10af3cfc497199eb585576a6630c9` (PR #10 merge)  
**Branch:** `feature/epic-14-1-v1-release`  
**ADR required:** NO

## Release decision

**V1.0 — READY WITH UNVERIFIED GATES**

No code release blockers found. Automated regression passes. Local staging
backup/restore and live WebSocket smoke (register → create_room) pass. Mandatory
**production** operational gates (browser on deployed HTTPS/WSS, VPS smoke,
TLS/WSS end-to-end, production backup/restore, emergency restart) could not be
executed in this session — **no VPS SSH credentials available**.

**`v1.0` tag: NOT CREATED** (mandatory production gates remain unverified).

---

## Repository state

| Item | Value |
|------|-------|
| PR #10 merged | **YES** (`2c69908`, 2026-09-05) |
| Base SHA | `2c699086f6c10af3cfc497199eb585576a6630c9` |
| Target VPS | `box-963286` (documented verification host; **no SSH access this session**) |
| `git fetch` | **PASS** via `git -c http.sslBackend=schannel fetch origin main` |

---

## Operational gates

| Gate | Result | Evidence | Environment |
|------|--------|----------|-------------|
| Browser/E2E | **UNVERIFIED** | Browser MCP available; local `http://localhost:8888` loads UI but WS fails with production meta (`/ws` path) on plain `:8080` dev server — registration shows "Not connected". Full interactive journey not completed. | local dev |
| VPS smoke | **UNVERIFIED** | SSH to `box-963286` failed — no key/password; no production domain documented in repo | — |
| TLS/WSS | **UNVERIFIED** | No production HTTPS endpoint accessible; ADR-027/nginx config reviewed only | — |
| Authentication | **PASS** (local WS) | `ws_emulator` register → `auth_result` success | local Workerman |
| Lobby | **PASS** (local WS) | `ws_emulator` create_room → `room_joined` waiting | local Workerman |
| Gameplay | **UNVERIFIED** | Full browser game flow not executed; Phase 11 VPS protocol replay 145/145 (historical) | — |
| AFK/Turn | **UNVERIFIED** | Not exercised in this session | — |
| Apartment | **UNVERIFIED** | Not exercised in this session | — |
| Reconnect | **UNVERIFIED** | Not exercised in browser this session | — |
| Backup | **PASS** (staging) | PHP `VACUUM INTO` on local `game.db` → 20480 bytes; tables `users`, `sqlite_sequence` | local staging |
| Restore | **PASS** (staging) | Copy backup → open SQLite; `USERS_RESTORED=15` matches source; not against live production DB | local staging |
| Restart/Recovery | **UNVERIFIED** | Workerman restart not executed; no VPS systemd access | — |
| Persistence | **PASS** (staging) | Restored DB readable; user count preserved | local staging |
| Security | **PASS** (review) | No `.env` in repo; `LOTTO_ALLOWED_ORIGINS` tested in suite; admin auth tests pass | local |
| Automated regression | **PASS** | `php run_ALL_tests.php` — 59/59; `git diff --check` — PASS | local Windows |

---

## Findings → criteria

| Finding | Classification | Criterion |
|---------|----------------|-----------|
| No VPS SSH credentials | UNVERIFIED | VPS smoke, TLS/WSS, production restart |
| Local browser WS path mismatch (`/ws` meta vs `:8080` plain WS) | UNVERIFIED | Browser/E2E on local HTTP dev stack |
| Local backup/restore drill succeeds on staging copy | PASS | Backup/restore procedure validation |
| Live WS register + create_room via emulator | PASS | Auth + lobby over WebSocket |
| 59/59 automated tests pass | PASS | Regression gate |
| Phase 11 VPS evidence (11.1–11.6) on `box-963286` | Historical PASS | Performance/protocol (not re-run this session) |

**Release blockers:** none identified in code or automated tests.

---

## Validation executed

| Command | Result |
|---------|--------|
| `gh pr view 10` | MERGED @ `2c69908` |
| `git -c http.sslBackend=schannel fetch origin main` | PASS |
| `php run_ALL_tests.php` | 59/59 PASS |
| `git diff --check` | PASS |
| `php scripts/ws_emulator.php --replay` (register + create_room) | PASS |
| PHP `VACUUM INTO` backup + restore copy validation | PASS (staging) |
| `ssh_connect box-963286` | FAIL — no authentication |
| Browser register on `localhost:8888` | FAIL — Not connected (WS URL) |

---

## Changes

Documentation only — no code changes.

- `docs/EPIC_14_1_REPORT.md` (this file)
- `docs/ROADMAP.md` — EPIC-14.1 status
- `docs/IMPLEMENTATION_STATUS.md` — EPIC-14.1 sign-off block

---

## Production-readiness impact

**Proven this session:** Full automated suite; local live WS auth/lobby smoke;
staging backup/restore drill; security configuration review.

**Unverified (blocks `v1.0` tag):** Production browser E2E on HTTPS/WSS;
fresh VPS smoke; TLS/WSS certificate validation; production backup cron execution;
emergency restart on target host.

---

## Next steps

1. Obtain VPS SSH access (or production URL) and execute production smoke checklist from `docs/ADMIN_VPS_DEPLOY.md`.
2. Run browser E2E against production `https://<domain>/` + `wss://<domain>/ws`.
3. Execute production backup cron + restore drill on stopped service (non-destructive staging copy first).
4. If all mandatory gates PASS → merge release PR → tag annotated `v1.0` on `main`.
5. Post-release: monitoring per `docs/ADMIN_VPS_DEPLOY.md` §8 (logs, restart, backups).
