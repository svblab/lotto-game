# Phase 11 Report — Audits & Load Testing

**Date:** 2026-07-27  
**Repository:** https://github.com/svblab/lotto-game  
**Auditor session:** EPIC-11.0 complete; EPIC-11.1/11.2/11.3/11.4 instrumentation complete (VPS runs pending); **EPIC-11.5 VPS live WS verified (2026-09-02)**; EPIC-11.6 instrumentation complete (VPS load runs pending)

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

## EPIC-11.3 — Economy Audit (Instrumentation Complete)

**Status:** Instrumentation + mock regression complete; VPS live-game log replay pending.

| File | Purpose |
|------|---------|
| `src/Core/EconomyAudit.php` | Opt-in financial logging (`LOTTO_ECONOMY_AUDIT=1`) → `logs/economy_audit.log` |
| `src/Core/Helpers.php` | `lottoEconomyRecord()` helper |
| `scripts/economy_integrity_runner.php` | Multi-scenario conservation check (stake/prize/burn/apartment/refund) |
| `scripts/analyze_economy_log.php` | Log replay + duplicate tx_id check |

### Transaction sites (all wrapped in SQLite transactions)

| Service | Operation | Audit op |
|---------|-----------|----------|
| `GameService` | startGame stakes | `stake` |
| `GameFinishService` | winner payout | `prize` |
| `GameFinishService` | remainder destruction | `burn` |
| `ApartmentService` | apartment payment | `apartment` |
| `ApartmentService` | no-survivors refund | `refund` |
| `AdminService` | kick/close room refund | `refund` |

### Mock regression results

- `test_economy_audit.php`: **32/32 PASS**
- `economy_integrity_runner.php`: **PASS** (4 scenarios, conservation holds)
- Existing: `test_victory.php` (40/40), `test_apartment.php` (32/32), `test_admin_integration.php` (20/20)

**Remaining:** Run with `LOTTO_ECONOMY_AUDIT=1` on VPS during live games; verify via `analyze_economy_log.php --initial=...`.

---

## EPIC-11.4 — State Machine Audit (Instrumentation Complete)

**Status:** Instrumentation complete 2026-07-27; VPS live-session validation pending.

**Implemented:**
- `StateMachineAudit` utility (`LOTTO_STATE_AUDIT=1` → `logs/state_machine_audit.log`)
- Transition graph per ANCHOR_CORE.md Part 4 (room + player states)
- Hooks at: `RoomManager`, `GameService`, `GameFinishService`, `ApartmentService`,
  `ReconnectService`, `LobbyService`, `AdminService`
- `test_state_machine_audit.php`: **29/29 PASS**
- `scripts/analyze_state_machine_log.php` — replay + sequence validation

**Verified transitions (mock tests):**
- `created → waiting → playing` (start_game)
- `playing → apartment → playing` (apartment_complete / apartment_timeout)
- `playing/apartment → finished` (victory / last_survivor via GameFinishService)
- `finished → destroyed` (game_over_cleanup)
- Invalid: start_game while playing, draw_barrel in waiting, join_room in playing
- Player: `active ↔ disconnected` (connection_lost / reconnect)
- Host disconnect + reconnect preserves `host_conn_id`

**Remaining:** Run with `LOTTO_STATE_AUDIT=1` on VPS during live games; verify via `analyze_state_machine_log.php`.

---

## EPIC-11.5 — Protocol Audit (VPS Verified)

**Status:** Documentation aligned; live audit tests **PASS on Ubuntu VPS** (2026-09-02).

### VPS live WebSocket verification

| Item | Value |
|------|-------|
| Host | `box-963286` (Ubuntu 24.04.4 LTS, non-production verification VPS) |
| Method | SSH MCP; dev checkout `~/lotto-game` (Workerman subprocess tests) |
| Commit | `f2c9cfb` (main post-PR #2 merge) |
| Date | 2026-09-02 |

Eight live-server manual tests — **145 passed, 0 failed**:

| Test | Result |
|------|--------|
| `test_server_bootstrap.php` | 24/24 PASS |
| `test_packet_validation.php` | 11/11 PASS |
| `test_auth_packet_routing.php` | 18/18 PASS |
| `test_lobby_packet_routing.php` | 23/23 PASS |
| `test_game_packet_routing.php` | 22/22 PASS |
| `test_admin_packet_routing.php` | 15/15 PASS |
| `test_session_lifecycle.php` | 13/13 PASS |
| `test_protocol_audit.php` | 19/19 PASS |

Reproduction:

```bash
cd ~/lotto-game && git fetch origin main && git reset --hard FETCH_HEAD
composer install -q
for f in test_server_bootstrap test_packet_validation test_auth_packet_routing \
  test_lobby_packet_routing test_game_packet_routing test_admin_packet_routing \
  test_session_lifecycle test_protocol_audit; do
  php tests/Manual/$f.php
done
```

`ws_emulator.php` remains available for manual session replay (`--send`, `--replay`, `--interactive`).

### Documentation fixes (ADR-007)

| Warning | Item | Resolution |
|---------|------|------------|
| W1 | `afk_warning` packet used but not in ANCHOR_CORE registry | **Resolved** — added to ANCHOR_CORE.md + ANCHOR_PROTOCOL.md |
| W2 | `admin_stats_data` declared but never emitted | **Resolved (implementation)** — ADR-023 / EPIC-23.0; server + client wired; `test_admin_stats.php` 10/10. **Documentation stale** (TD-1) |
| W3 | `error.banned` declared but unused | **Documented** — reserved per ADR-007; `banned` packet is canonical (TD-2, very low) |

`test_protocol_completeness.php`: **50/50 PASS**; warnings limited to `error.banned` reserved semantics (W3).

### New tooling

| Component | Purpose |
|-----------|---------|
| `tests/Manual/test_protocol_audit.php` | 7 live WS acceptance tests (extensibility, room_full, auth guards) |
| `scripts/ws_emulator.php` | CLI emulator: `--send`, `--replay session.jsonl`, `--interactive` |

### Live-server test coverage

- `test_protocol_audit.php` — EPIC-11.5 acceptance criteria not covered by routing tests
- Routing/bootstrap suite: `test_server_bootstrap.php`, `test_packet_validation.php`, `test_*_packet_routing.php`, `test_session_lifecycle.php`

All listed tests verified on VPS — see table above.

---

## EPIC-11.6 — Load Testing (Instrumentation Complete)

**Status:** Instrumentation complete 2026-07-27 — VPS load runs pending on 1 CPU / 512 MB target.

**Targets (from spec):**
- 100–150 concurrent connections
- 10–20 simultaneous games
- p95 response time < 100 ms (register, login, draw_barrel)
- CPU < 80%, memory < 450 MB at peak

**Tooling:**

| File | Purpose |
|------|---------|
| `src/Core/LoadAudit.php` | Server-side handler latency + periodic snapshots (`LOTTO_LOAD_AUDIT=1`) |
| `scripts/load_test_runner.php` | VPS scenarios: `ramp`, `steady`, `storm`, `long` |
| `scripts/analyze_load_log.php` | Validates p95/CPU/memory acceptance criteria |
| `tests/Manual/test_load_audit.php` | 30 mock regression tests (Windows) |

**VPS commands:**

```bash
php scripts/load_test_runner.php --scenario=ramp --players=100 --games=10 --duration=300
php scripts/load_test_runner.php --scenario=steady --duration=1800
php scripts/load_test_runner.php --scenario=storm
php scripts/load_test_runner.php --scenario=long --duration=3600
```

**Action:** Run all four scenarios on Ubuntu VPS; review `analyze_load_log.php` output for sign-off.

---

## Open KNOWN GAPS (updated 2026-08-30 roadmap audit)

See `docs/IMPLEMENTATION_STATUS.md` § TECHNICAL DEBT and § KNOWN GAPS:

- **TD-1:** `admin_stats_data` — **implementation complete**; documentation/status reconciliation pending (Low, not blocking deployment).
- **TD-2:** `error.banned` — reserved/unused per ADR-007; `banned` packet canonical (Very Low, documentation only).
- Real-WS test log noise (low) — unchanged.
- Phase 11 VPS verification (TD-3) — **11.5 DONE** (live WS on VPS); 11.1–11.4 and 11.6 VPS runs still pending.

---

## Phase 12 Readiness

| Criterion | Status |
|-----------|--------|
| All protocol actions wired | ✅ Fixed (P11-001) |
| Unit/integration tests pass | ✅ 28/28 on Windows |
| Live WS tests pass | ✅ **145/145 PASS** on VPS (`box-963286`, `f2c9cfb`, 2026-09-02) |
| Memory/timer/load audits | ⏳ EPIC-11.1/11.2/11.3/11.4 instrumented (VPS runs pending); **11.5 VPS verified**; 11.6 instrumented (VPS load runs pending) |
| Protocol docs synced | ✅ `admin_stats_data` implemented (TD-1 docs reconciliation only); `error.banned` reserved per ADR-007 (TD-2) |

**Verdict:** Proceed with Phase 12 frontend development in parallel with completing EPIC-11.1–11.6 VPS verification (TD-3), **provided** the live-server tests pass on Ubuntu after deploying P11-001 fix. `admin_stats_data` is implemented end-to-end; remaining TD-1 work is documentation reconciliation only.

---

## Files changed in this audit session

- `server.php` — EPIC-10.6 admin wiring + FIX-10 onClose cleanup
- `tests/Manual/test_admin_ban.php` — FIX-11 MockConnection::close()
- `tests/Manual/test_admin_integration.php` — FIX-11 SpyConnection::close()
- `tests/Manual/test_phase11_core_flows.php` — new EPIC-11.0 chained flow test
- `src/Core/StateMachineAudit.php` — EPIC-11.4 state machine instrumentation
- `tests/Manual/test_state_machine_audit.php` — EPIC-11.4 mock regression tests
- `scripts/analyze_state_machine_log.php` — EPIC-11.4 log validator
- `scripts/analyze_memory_log.php` — EPIC-11.1 log analyzer
- `src/Core/TimerAudit.php` — EPIC-11.2 timer instrumentation
- `tests/Manual/test_timer_audit.php` — EPIC-11.2 mock regression tests
- `scripts/timer_accelerated_runner.php` — EPIC-11.2 VPS accelerated test
- `scripts/analyze_timer_log.php` — EPIC-11.2 drift analyzer
- `src/Core/LoadAudit.php` — EPIC-11.6 load test instrumentation
- `scripts/load_test_runner.php` — EPIC-11.6 VPS load scenarios
- `scripts/analyze_load_log.php` — EPIC-11.6 acceptance validator
- `tests/Manual/test_load_audit.php` — EPIC-11.6 mock regression tests
- `run_ALL_tests.php` — cross-platform runner with Windows SQLite + skip list
