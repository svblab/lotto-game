# Phase 11 Report — Audits & Load Testing

**Date:** 2026-07-27  
**Repository:** https://github.com/svblab/lotto-game  
**Auditor session:** EPIC-11.0 started; EPIC-11.1–11.6 pending on VPS

---

## Executive Summary

Phase 11 audit began with EPIC-11.0 (Full Integration Testing). A **critical wiring gap** was discovered and fixed: `AdminHandler` and all five `admin_*` actions were documented as complete in Phase 10 but were **never wired into `server.php`**. After applying the missing EPIC-10.6 patch and FIX-11 test mock updates, **25/25 unit/integration test files pass** on Windows (with 8 live-server tests skipped — they require Ubuntu/VPS).

**Recommendation:** Server is **conditionally ready** for Phase 12 (Frontend) after live-server regression on Ubuntu VPS confirms the 8 subprocess WebSocket tests pass. Remaining audit epics (memory, timer, economy, state machine, protocol live replay, load testing) should run on the target VPS before production.

---

## EPIC-11.0 — Full Integration Testing

### Test suite execution

| Category | Files | Result |
|----------|-------|--------|
| Unit / mock integration (Windows) | 25 | **25/25 PASS** |
| Live WebSocket subprocess (Ubuntu only) | 8 | **SKIP on Windows** — run on VPS |
| New chained flow test | `test_phase11_core_flows.php` | **17/17 PASS** |

**Runner:** `php run_ALL_tests.php` (cross-platform; enables SQLite on Windows automatically)

### Critical issue found and fixed

| ID | Severity | Issue | Fix |
|----|----------|-------|-----|
| P11-001 | **Critical** | `server.php` missing EPIC-10.6 admin routing — all `admin_*` actions returned `error.invalid_json` | Applied AdminService/AdminHandler wiring + 5 dispatcher arms |
| P11-002 | **High** | `test_admin_ban.php` / `test_admin_integration.php` MockConnection missing `close()` after FIX-11 | Added `close()` stub to mock classes |
| P11-003 | **Medium** | FIX-10 `onClose` userConnections cleanup missing from `server.php` | Added `unset($worker->userConnections[...])` on close |

### New integration coverage

`tests/Manual/test_phase11_core_flows.php` chains:

- register → login → create_room → join_room → start_game
- Invalid transitions: start_game while playing, draw_barrel in waiting
- Non-host start_game rejection
- Rate limit constant verification

Existing per-module tests already cover: apartment, reconnect/AFK, admin actions, turn system, victory, timer integrity, protocol static completeness.

### Live-server tests (VPS required)

These spawn `php server.php start` and connect via real WebSocket. **Workerman forking fails on Windows**; must run on Ubuntu 24.04 VPS:

- `test_server_bootstrap.php`
- `test_packet_validation.php`
- `test_auth_packet_routing.php`
- `test_lobby_packet_routing.php`
- `test_game_packet_routing.php`
- `test_admin_packet_routing.php`
- `test_session_lifecycle.php`

**Action:** Run `bash run_ALL_tests.sh` or `php run_ALL_tests.php` on VPS before Phase 12 sign-off.

---

## EPIC-11.1 — Memory Audit (Pending)

**Status:** Not started — requires VPS long-duration run.

**Baseline plan:**
- Record `memory_get_usage()` / `memory_get_peak_usage()` at worker start
- Instrument after: connection open, packet processing, room create/destroy, game finish
- Verify `$worker->rooms` and `$worker->userConnections` cleanup on disconnect and game end
- 6-hour stability test with snapshots every 30 minutes

**Preliminary static review:** No obvious unbounded array growth patterns found; runtime maps are keyed by room_id/user_id and destroyed via RoomManager/ReconnectService paths (verified in unit tests).

---

## EPIC-11.2 — Timer Audit (Pending)

**Status:** Static inventory complete; accelerated live tests pending.

| Timer | Location | Constant |
|-------|----------|----------|
| Global watchdog | `server.php` | 60s interval |
| Unauthorized timeout | `server.php` | `UNAUTHORIZED_TIMEOUT = 60` |
| Authorized timeout | `server.php` | `AUTHORIZED_TIMEOUT = 120` |
| Lobby AFK | `LobbyService.php` | `lobby_afk_timer_id` |
| Game AFK | `ReconnectService.php` | `game_afk_timer_id` |
| Apartment voting | `ApartmentService.php` | `apartment_timer_id` |
| Reconnect grace | `ReconnectService.php` | `RECONNECT_TIMEOUT = 15` |
| Rate limit window | `server.php` | `RATE_LIMIT_WINDOW_SECONDS = 1` |

`test_timer_integrity.php`: **5/5 PASS** (mock-based cleanup verification).

---

## EPIC-11.3 — Economy Audit (Pending)

**Status:** Partial — covered by existing unit tests; full integrity script pending.

Transaction sites identified in: `GameService`, `GameFinishService`, `ApartmentService`, `AdminService`.

Existing passing tests:
- `test_victory.php` (40/40) — payout scenarios
- `test_apartment.php` (32/32) — apartment deductions
- `test_admin_integration.php` (20/20) — refund integrity, no double-refund

**Remaining:** Script to sum all balances before/after multi-game simulation; log replay verification.

---

## EPIC-11.4 — State Machine Audit (Pending)

**Status:** Partial — core transitions verified in `test_phase11_core_flows.php` and module tests.

Verified transitions:
- waiting → playing (start_game)
- playing → error on duplicate start
- waiting → error on draw_barrel

**Remaining:** apartment → finished, automatic timeout transitions, host disconnect recovery.

---

## EPIC-11.5 — Protocol Audit (Pending)

**Status:** Static audit complete; live replay pending.

`test_protocol_completeness.php`: **50/50 PASS**, 3 warnings (known documentation debt):

| Warning | Item | Action |
|---------|------|--------|
| W1 | `afk_warning` packet used but not in ANCHOR_CORE registry | Add to docs (EPIC-11.5) |
| W2 | `admin_stats_data` declared but never emitted | Assign epic or exclude |
| W3 | `error.banned` declared but unused (`banned` packet covers it) | Assign usage or exclude |

After P11-001 fix: all 5 admin actions wired; `$worker->adminHandler` instantiated.

---

## EPIC-11.6 — Load Testing (Pending)

**Status:** Not started — requires VPS with 1 CPU / 512 MB RAM target environment.

**Targets (from spec):**
- 100–150 concurrent connections
- 10–20 simultaneous games
- p95 response time < 100 ms
- CPU < 80%, memory < 450 MB at peak

---

## Open KNOWN GAPS (unchanged)

See `docs/IMPLEMENTATION_STATUS.md` § KNOWN GAPS:
- Real-WS test log noise in production log (low)
- `afk_warning` documentation debt (low)
- `admin_stats_data` unimplemented (low)
- `error.banned` unused (low)

---

## Phase 12 Readiness

| Criterion | Status |
|-----------|--------|
| All protocol actions wired | ✅ Fixed (P11-001) |
| Unit/integration tests pass | ✅ 25/25 on Windows |
| Live WS tests pass | ⏳ Run on VPS |
| Memory/timer/load audits | ⏳ EPIC-11.1–11.6 |
| Protocol docs synced | ⚠️ 3 low-priority gaps |

**Verdict:** Proceed with Phase 12 frontend development in parallel with completing EPIC-11.1–11.6 on VPS, **provided** the 8 live-server tests pass on Ubuntu after deploying P11-001 fix. Frontend should not depend on admin_stats_data or error.banned until those gaps are resolved.

---

## Files changed in this audit session

- `server.php` — EPIC-10.6 admin wiring + FIX-10 onClose cleanup
- `tests/Manual/test_admin_ban.php` — FIX-11 MockConnection::close()
- `tests/Manual/test_admin_integration.php` — FIX-11 SpyConnection::close()
- `tests/Manual/test_phase11_core_flows.php` — new EPIC-11.0 chained flow test
- `run_ALL_tests.php` — cross-platform runner with Windows SQLite + skip list
