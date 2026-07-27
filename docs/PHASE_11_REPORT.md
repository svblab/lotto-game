# Phase 11 Report — Audits & Load Testing

**Date:** 2026-07-27  
**Repository:** https://github.com/svblab/lotto-game  
**Auditor session:** EPIC-11.0 complete; EPIC-11.1/11.2 instrumentation complete (VPS runs pending); EPIC-11.3–11.6 pending on VPS

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

## EPIC-11.1 — Memory Audit

**Status:** Instrumentation complete; mock regression tests pass. VPS long-duration run pending.

### Implementation

| Component | Purpose |
|-----------|---------|
| `src/Core/MemoryAudit.php` | Opt-in snapshots (`LOTTO_MEMORY_AUDIT=1`) → `logs/memory_audit.log` |
| `server.php` | Baseline at worker start, connection open/close, tracked actions, 30-min periodic timer |
| `RoomManager` | Snapshots on room create/destroy |
| `tests/Manual/test_memory_audit.php` | Mock-based regression (map cleanup, bounded growth) |
| `scripts/memory_stability_runner.php` | 6-hour VPS load test (Linux only) |
| `scripts/analyze_memory_log.php` | Validates ≤120% baseline threshold |

### Enabling on VPS

```bash
LOTTO_MEMORY_AUDIT=1 php server.php start
# Optional verbose per-packet logging:
LOTTO_MEMORY_AUDIT=1 LOTTO_MEMORY_AUDIT_VERBOSE=1 php server.php start
# Full 6-hour stability test:
php scripts/memory_stability_runner.php --duration=21600 --players=50 --games=10
# Analyze results:
php scripts/analyze_memory_log.php
```

### Preliminary static + mock results

- `test_memory_audit.php`: map cleanup and bounded memory growth verified (mock-based)
- Runtime maps (`$worker->rooms`, `$worker->userConnections`) keyed by ID and destroyed via RoomManager/ReconnectService paths
- No obvious unbounded array growth patterns in static review

**Remaining:** Run `memory_stability_runner.php` on Ubuntu VPS for 6-hour acceptance sign-off.

---

## EPIC-11.2 — Timer Audit (Instrumentation Complete)

**Status:** Instrumentation + mock tests complete; VPS accelerated run pending.

| Timer | Location | Constant / Env override |
|-------|----------|-------------------------|
| Global watchdog | `server.php` | `WATCHDOG_INTERVAL` / `LOTTO_WATCHDOG_INTERVAL` |
| Unauthorized timeout | `server.php` | `UNAUTHORIZED_TIMEOUT` / `LOTTO_UNAUTHORIZED_TIMEOUT` |
| Authorized timeout | `server.php` | `AUTHORIZED_TIMEOUT` / `LOTTO_AUTHORIZED_TIMEOUT` |
| Lobby AFK | `LobbyService.php` | `LOBBY_HOST_TIMEOUT` / `LOTTO_LOBBY_HOST_TIMEOUT` |
| Game AFK | `ReconnectService.php` | `GAME_AFK_WARN1/2/AUTO` / `LOTTO_GAME_AFK_*` |
| Apartment voting | `ApartmentService.php` | `APARTMENT_TIMEOUT` / `LOTTO_APARTMENT_TIMEOUT` |
| Reconnect grace | `ReconnectService.php` | `RECONNECT_TIMEOUT` / `LOTTO_RECONNECT_TIMEOUT` |
| Rate limit window | `server.php` | `RATE_LIMIT_WINDOW_SECONDS = 1` |

### Instrumentation (EPIC-11.2)

| File | Purpose |
|------|---------|
| `src/Core/TimerAudit.php` | Opt-in add/del/fire logging (`LOTTO_TIMER_AUDIT=1`) → `logs/timer_audit.log` |
| `src/Core/Helpers.php` | `lottoTimerAdd` / `lottoTimerDel` wrappers |
| `scripts/timer_accelerated_runner.php` | VPS accelerated scenarios (5s timeouts) |
| `scripts/analyze_timer_log.php` | Drift ±200ms + orphan timer check |

### Preliminary static + mock results

- `test_timer_audit.php`: **20/20 PASS** (utility, env overrides, cleanup, reconnect cancel)
- `test_timer_integrity.php`: **5/5 PASS** (FIX-6 reconnect timer cleanup regression)

**Remaining:** Run `timer_accelerated_runner.php` on Ubuntu VPS for live drift acceptance.

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
| Memory/timer/load audits | ⏳ EPIC-11.1/11.2 instrumented (VPS runs pending); 11.3–11.6 pending |
| Protocol docs synced | ⚠️ 3 low-priority gaps |

**Verdict:** Proceed with Phase 12 frontend development in parallel with completing EPIC-11.1–11.6 on VPS, **provided** the 8 live-server tests pass on Ubuntu after deploying P11-001 fix. Frontend should not depend on admin_stats_data or error.banned until those gaps are resolved.

---

## Files changed in this audit session

- `server.php` — EPIC-10.6 admin wiring + FIX-10 onClose cleanup
- `tests/Manual/test_admin_ban.php` — FIX-11 MockConnection::close()
- `tests/Manual/test_admin_integration.php` — FIX-11 SpyConnection::close()
- `tests/Manual/test_phase11_core_flows.php` — new EPIC-11.0 chained flow test
- `src/Core/MemoryAudit.php` — EPIC-11.1 memory instrumentation
- `tests/Manual/test_memory_audit.php` — EPIC-11.1 mock regression tests
- `scripts/memory_stability_runner.php` — EPIC-11.1 VPS long-duration test
- `scripts/analyze_memory_log.php` — EPIC-11.1 log analyzer
- `src/Core/TimerAudit.php` — EPIC-11.2 timer instrumentation
- `tests/Manual/test_timer_audit.php` — EPIC-11.2 mock regression tests
- `scripts/timer_accelerated_runner.php` — EPIC-11.2 VPS accelerated test
- `scripts/analyze_timer_log.php` — EPIC-11.2 drift analyzer
- `run_ALL_tests.php` — cross-platform runner with Windows SQLite + skip list
