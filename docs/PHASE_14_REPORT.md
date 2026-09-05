# Phase 14 — Release Candidate Report

**Date:** 2026-09-05  
**Repository:** https://github.com/svblab/lotto-game  
**Verified `origin/main`:** `a8794f3dbc5196b0008a943e1e3e865187c5d133` (via `gh api`; `git fetch` failed — see § Repository)  
**Branch:** `feature/phase-14-release-candidate`  
**ADR required:** NO

## Release decision

**RELEASE CANDIDATE — READY WITH UNVERIFIED OPERATIONAL GATES**

Code and automated validation are ready. Operational gates (browser/E2E, fresh VPS
smoke, backup/restore execution) were not verified in this session.

---

## Repository state

| Item | Value |
|------|-------|
| `origin/main` (verified) | `a8794f3dbc5196b0008a943e1e3e865187c5d133` |
| Base SHA | `a8794f3` (PR #9 merge on GitHub) |
| PR #9 merged | **YES** (`docs: reconcile Phase 12 frontend status`, 2026-09-05) |
| Branch HEAD | `feature/phase-14-release-candidate` |
| Working tree | clean |

**`git fetch` failure:** `SSL certificate OpenSSL verify result: self-signed certificate in certificate chain`. SSL verification was **not** disabled. Remote `main` SHA confirmed via `gh api repos/svblab/lotto-game/commits/main`. Local `main` ref is stale at `55e193b`; this branch is based on PR #9 content (`feature/phase-12-frontend` @ `6ed5bf6`, equivalent tree) plus Phase 13 doc reconciliation (`a1fb741`).

---

## Phase status summary

| Phase | Status | Evidence |
|-------|--------|----------|
| Phase 11 | Complete | `docs/PHASE_11_REPORT.md`; TD-3 VPS matrix 11.1–11.6 PASS |
| Phase 12 | Complete | PR #9; `docs/PHASE_12_REPORT.md`; frontend on `main` |
| Phase 13 | Complete | Code on `main`; doc reconciliation in this branch |
| Phase 14 | EPIC-14.0 sign-off | This report |

---

## Release validation matrix

| Area | Status | Evidence | Environment |
|------|--------|----------|-------------|
| Backend tests | **PASS** | `php run_ALL_tests.php` — 59/59 | local Windows |
| Frontend tests | **PASS** | `test_frontend_*.php`, `test_ws_url_resolution.php` | local |
| Protocol | **PASS** | `test_protocol_completeness.php` 99/99; `test_protocol_audit.php`; ANCHOR alignment review | local |
| Auth/Security | **PASS** | `test_auth_integration.php`, `test_login_throttle.php`, `test_server_bootstrap.php` (ADR-029 origin) | local |
| State machine | **PASS** | `test_state_machine_audit.php` 35/35 | local |
| Economy | **PASS** | `test_economy_audit.php`, `test_victory.php`, apartment/reconnect refund tests | local |
| Reconnect/AFK | **PASS** | `test_reconnect.php`, `test_turn_system.php`, `test_game_start_turn_integration.php` | local |
| Browser/E2E | **UNVERIFIED** | Browser MCP unavailable; static server + WS started locally but no interactive flow recorded | — |
| Persistence | **PASS** (review) | SQLite `game.db`; `init_db.php`; WAL documented in `ADMIN_VPS_DEPLOY.md` | config review |
| Backup/Restore | **UNVERIFIED** | Procedure documented (`sqlite3 .backup`); not executed this session | — |
| TLS/WSS | **UNVERIFIED** (fresh) | ADR-027 + nginx/Caddy configs reviewed; prior VPS WS tests in Phase 11 | config + Phase 11 |
| VPS deployment | **UNVERIFIED** (fresh) | No SSH session in this audit; Phase 11 VPS evidence on `box-963286` | Phase 11 |
| Restart/recovery | **UNVERIFIED** | systemd unit documented; emergency restart not executed this session | — |
| Security configuration | **PASS** (review) | No `.env` committed; `LOTTO_ALLOWED_ORIGINS` tested; admin auth tests pass | local |
| Load/performance | **PASS** | Phase 11 EPIC-11.6 evidence; no material code changes in this branch | Phase 11 VPS |

---

## Audit findings (by area)

### A. WebSocket / Protocol — PASS

- Packet flows align with `docs/ANCHOR_PROTOCOL.md` (auth, lobby, game, reconnect, admin, errors).
- `test_protocol_completeness.php` and routing tests pass.
- No undocumented protocol changes in this branch (documentation only).

### B. Frontend — PASS (automated); UNVERIFIED (browser)

- Complete client surface in `public/` (ws.js, app.js, ui.js, i18n.js).
- `onReconnectState` uses `is_my_turn` for turn restoration (EPIC-13.6 / ADR-017).
- Browser smoke flow not completed (tooling unavailable).

### C. Authentication / Session — PASS

- Register, login, reconnect, session supersede, rate limiting covered by tests.
- No password logging in production paths (grep review).

### D. Economy / Game Integrity — PASS

- Bank/balance conservation tests pass (victory, apartment, admin close/kick, reconnect no-survivors).

### E. State Machine — PASS

- Invalid transitions rejected; AFK/apartment/game-over paths tested.

### F. Persistence / Recovery — UNVERIFIED (backup/restore execution)

- SQLite location and WAL backup procedure documented.
- Actual backup/restore drill not performed in this session.

### G. Deployment / VPS — UNVERIFIED (fresh smoke)

- Production model reviewed: Workerman plain WS + nginx TLS (ADR-027).
- Phase 11 VPS evidence remains authoritative; no Phase 12/13/14 code regressions introduced here.

### H. Security — PASS (review)

- Origin allow-list (ADR-029) enforced when `LOTTO_ALLOWED_ORIGINS` set.
- Admin endpoints require `assertAdmin`.
- No secrets in repository; no debug flags in `server.php`.

### I. Resource / Performance — PASS (Phase 11 evidence)

- This branch contains documentation only; Phase 11 load/memory/timer evidence remains valid.

---

## Blockers

**None identified.** No code changes required for release candidate gate.

---

## Validation executed

| Command | Result |
|---------|--------|
| `php run_ALL_tests.php` | 59/59 PASS |
| `git diff --check` | PASS |
| `gh pr view 9` | MERGED |
| `gh api repos/svblab/lotto-game/commits/main` | `a8794f3` |
| `git fetch origin` | **FAIL** (SSL self-signed certificate) |

---

## Changes in this branch

1. Cherry-pick: Phase 13 status doc reconciliation (`3e18b50` → `a1fb741`)
2. Phase 14 release candidate report and status updates (this commit)

No protocol, economy, authentication, or frontend logic changes.

---

## Production-readiness impact

**Proven:** Full automated test suite passes on Windows; protocol/auth/state/economy
invariants hold; Phase 11–13 sign-off documentation reconciled; PR #9 merged on GitHub.

**Unverified:** Interactive browser E2E, fresh VPS smoke (TLS/WSS, lobby→game→reconnect),
backup/restore drill, emergency restart drill.

**Blocked:** Nothing.

---

## Next task

Per `docs/ROADMAP.md`: **EPIC-14.1 — Version 1.0 Release** after operational
gates are verified on target VPS (browser smoke, backup/restore, WSS smoke).
