# Phase 11 Report — Audits & Load Testing

**Date:** 2026-07-27  
**Repository:** https://github.com/svblab/lotto-game  
**Auditor session:** EPIC-11.0 complete; **EPIC-11.1–11.5 VPS verified (2026-09-02)**; **EPIC-11.6 BLOCKED / NEEDS CHANGES** — load scenarios executed; register p95 + peak CPU acceptance FAIL under diagnosis/fix (see § EPIC-11.6)

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

## EPIC-11.1 — Memory Audit (VPS Verified)

**Status:** Instrumentation complete; mock regression tests pass; **6-hour VPS memory/stability run PASS (2026-09-02)**.

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

### VPS memory / stability verification (2026-09-02)

| Item | Value |
|------|-------|
| Host | `box-963286` (Ubuntu 24.04, non-production verification VPS) |
| Method | SSH; dev checkout `~/lotto-game` |
| Commit | `6498144` (main post-PR #3 / EPIC-11.5) |
| Start | `2026-09-02T03:33:19Z` |
| End | ~`2026-09-02T09:33:25Z` (run log mtime; Stopping server then analyze) |
| Duration | 21600s planned; last progress `elapsed=21559s` `remaining=41s` then stop |
| Load | `--players=50 --games=10` |
| Baseline | 4.00 MB |
| Peak | 4.00 MB |
| Threshold | 120% → limit 4.80 MB |
| Snapshots | 644,587 (all `mem_mb=4.00`); violations **0** |
| Result | **PASS** (≤120% baseline) |

**Service health:** No crashes/restarts of the memory-stability runner; connected clients stayed at 50 for the run. Post-run leftover Workerman on port 8080 (dev checkout `~/lotto-game`) was stopped with `php server.php stop` — ports 8080/18080 clear; no production systemd unit was active.

**Official analyzer note:** `php scripts/analyze_memory_log.php` was **OOM-killed (exit 137)** on this ~543 MB VPS because `file()` loads the full ~98 MB audit log. A streaming equivalent analysis using the same ≤120% baseline acceptance logic **PASSED**. Limitation (document only; no analyzer rewrite in this epic): prefer streaming over `file()` for long runs.

### Preliminary static + mock results

- `test_memory_audit.php`: map cleanup and bounded memory growth verified (mock-based)
- Runtime maps (`$worker->rooms`, `$worker->userConnections`) keyed by ID and destroyed via RoomManager/ReconnectService paths
- No obvious unbounded array growth patterns in static review

**VPS acceptance:** Complete — see evidence table above.

---

## EPIC-11.2 — Timer Audit (VPS Verified)

**Status:** Instrumentation + mock tests complete; **VPS accelerated run PASS (2026-09-02)**.

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
| `scripts/timer_accelerated_runner.php` | VPS accelerated scenarios (5s timeouts); `play_vs_bot` then disconnect to arm reconnect |
| `scripts/analyze_timer_log.php` | One-shot reconnect drift ±200ms + orphan one-shot check |

### VPS accelerated timer verification (2026-09-02)

| Item | Value |
|------|-------|
| Host | `box-963286` (Ubuntu 24.04, non-production verification VPS) |
| Method | SSH; isolated tree from main `ef066d6` + harness fixes in this epic |
| Commit (base) | `ef066d6` (main post-PR #4 / EPIC-11.1) |
| Start / End | `2026-09-02T18:11:27Z` → `2026-09-02T18:11:36Z` |
| Scenario | register → create_room → `play_vs_bot` → disconnect → wait reconnect fire |
| Reconnect timeout | 5s (accelerated) |
| Reconnect drift | ~0.5 ms (expected 5000 ms, actual ~5000.5 ms, tolerance 200 ms) |
| One-shot | seen=1 fired=1 orphaned=0 |
| Result | **PASS** (`analyze_timer_log.php` exit 0) |

**Harness notes (fixed in this epic):** prior runner disconnected in `waiting` (no reconnect timer). Analyzer incorrectly treated periodic `global_watchdog` fires as drift/orphan failures. Runner now enters playing via `play_vs_bot`, stops Workerman cleanly, propagates analyze exit code, and auto-runs `init_db.php` when `game.db` lacks schema.

### Preliminary static + mock results

- `test_timer_audit.php`: **24/24 PASS** (utility, env overrides, cleanup, reconnect cancel)
- `test_timer_integrity.php`: **5/5 PASS** on environments with PDO SQLite (Windows without sqlite extension may fail late groups)

**VPS sign-off:** Complete — see table above.

---

## EPIC-11.3 — Economy Audit (VPS Verified)

**Status:** Instrumentation + mock regression complete; **VPS economy integrity + live stake path PASS (2026-09-02)**.

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

### VPS economy verification (2026-09-02)

| Item | Value |
|------|-------|
| Host | `box-963286` (Ubuntu 24.04, non-production verification VPS) |
| Commit | `85e9bbb` (main post-PR #5 / EPIC-11.2) |
| Integrity start/end | `2026-09-02T18:20:17Z` (same-second run) |
| Integrity scenarios | 4 (stake → prize/burn → apartment → refund) |
| Integrity log | 7 events: stake=2 prize=2 apartment=1 refund=1 burn=1 |
| Conservation | initial 1500; final coins 1499 + burned 1; replay PASS with `--initial=1:500,2:500,3:500` |
| Live Workerman | `LOTTO_ECONOMY_AUDIT=1` + `play_vs_bot` → stake `amount=-10` logged; analyze PASS |
| Result | **PASS** |

Commands:

```bash
php scripts/economy_integrity_runner.php --log=logs/economy_audit_runner.log
php scripts/analyze_economy_log.php logs/economy_audit_runner.log --initial=1:500,2:500,3:500
# Live path: LOTTO_ECONOMY_AUDIT=1 php server.php start → play_vs_bot → analyze
```

### Mock regression results

- `test_economy_audit.php`: **34/34 PASS** (Windows)
- `economy_integrity_runner.php`: **PASS** (4 scenarios, conservation holds)
- Existing: `test_victory.php` (40/40), `test_apartment.php` (32/32), `test_admin_integration.php` (20/20)

**VPS sign-off:** Complete — see table above.

---

## EPIC-11.4 — State Machine Audit (VPS Verified)

**Status:** Instrumentation + mock regression complete; **VPS live-session log replay PASS (2026-09-02)**.

| File | Purpose |
|------|---------|
| `src/Core/StateMachineAudit.php` | Opt-in transition logging (`LOTTO_STATE_AUDIT=1`) → `logs/state_machine_audit.log` |
| `src/Core/Helpers.php` | `lottoStateTransition` / `lottoStateReject` / `lottoPlayerStateTransition` |
| `tests/Manual/test_state_machine_audit.php` | Mock regression (room + player lifecycle) |
| `scripts/analyze_state_machine_log.php` | Log replay + ANCHOR_CORE Part 4 validation |

**Hooks:** `RoomManager`, `GameService`, `GameFinishService`, `ApartmentService`,
`ReconnectService`, `LobbyService`, `AdminService`

**Verified transitions (mock tests):**
- `created → waiting → playing` (start_game / play_vs_bot)
- `playing → apartment → playing` (apartment_complete / apartment_timeout)
- `playing/apartment → finished` (victory / last_survivor via GameFinishService)
- `finished → destroyed` (game_over_cleanup)
- Invalid: start_game while playing, draw_barrel in waiting, join_room in playing
- Player: `active ↔ disconnected` (connection_lost / reconnect)
- Host disconnect + reconnect preserves `host_conn_id`

### VPS state-machine verification (2026-09-02)

| Item | Value |
|------|-------|
| Host | `box-963286` (Ubuntu 24.04, non-production verification VPS) |
| Commit | `7032a4a` (main post-PR #6 / EPIC-11.3) |
| Mock | `test_state_machine_audit.php` **35/35 PASS** @ `2026-09-02T18:33:31Z` |
| Live window | `2026-09-02T18:34:08Z` → `2026-09-02T18:35:23Z` |
| Live Workerman | `LOTTO_STATE_AUDIT=1` + register → create_room → `play_vs_bot` → draws → disconnect |
| Live events | 3: `created→waiting` (`room_created`), `waiting→playing` (`play_vs_bot`), `active→disconnected` (`connection_lost`) |
| Analyze | `analyze_state_machine_log.php` **PASS** (exit 0) |
| Result | **PASS** |

Commands:

```bash
php tests/Manual/test_state_machine_audit.php
# Live path:
LOTTO_STATE_AUDIT=1 LOTTO_STATE_AUDIT_LOG=logs/state_machine_audit_live.log php server.php start
# register → create_room → play_vs_bot → draw_barrel ×N → disconnect
php scripts/analyze_state_machine_log.php logs/state_machine_audit_live.log
```

### Mock regression results

- `test_state_machine_audit.php`: **35/35 PASS** (Windows + VPS)

**VPS sign-off:** Complete — see table above.

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

## EPIC-11.6 — Load Testing (**BLOCKED / NEEDS CHANGES**)

**Status:** **BLOCKED / NEEDS CHANGES** — four VPS load scenarios executed (2026-09-02); `analyze_load_log.php` acceptance **FAIL**. Not DONE until analyzer PASS.

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

### Diagnosis (2026-09-03)

| Failure | Observed | Threshold | Root cause | Classification | Fix |
|---------|----------|-----------|------------|----------------|-----|
| register p95 | 153–160 ms | <100 ms | `handleRegister` ran `password_hash` + auto-`login`/`password_verify` (VPS bcrypt cost 10: ~63 ms + ~61 ms); server register p95 ≈ 154 ms | PRODUCT | `loginAfterRegister()` skips redundant same-request verify |
| steady/long peak CPU | 81.8–81.9% | <80% | `ps %cpu` lifetime average; first sample after register burst ≈ 82%; sustained long-run avg ≈ 1.4%, end ≈ 0.4% | TEST/HARNESS | Interval CPU via `/proc/[pid]/stat` tick deltas |

**Evidence:** VPS `php` bench — `PASSWORD_DEFAULT` = bcrypt **cost 10**; avg hash 62.78 ms, verify 60.62 ms, sum 123.40 ms. Long `load_resource.log`: max 81.9 → mid ~6% → end 0.4%.

**ADR required: NO** — hash algorithm/cost unchanged; protocol unchanged.

### Prior VPS load runs (2026-09-02, pre-fix)

| Item | Value |
|------|-------|
| Host | `box-963286` (Ubuntu 24.04, 1 CPU / ~543 MB RAM, non-production) |
| Base commit | `78e603a` (main post-PR #7) |
| Harness | `442c789` / docs `3ae6eb9` |
| Mock | `test_load_audit.php` **30/30 PASS** |

| Scenario | Window (UTC) | analyze | Key metrics |
|----------|--------------|---------|-------------|
| storm | `19:14:29`–`19:14:37` | FAIL | register p95 154.55 ms; draw_barrel 1.73 ms; mem 4.00 MB |
| ramp | `19:15:26`–`19:16:41` | FAIL | register p95 159.85 ms; peak CPU 69.6%; mem 4.00 MB |
| steady | `19:17:31`–`19:47:47` | FAIL | register p95 158.56 ms; draw_barrel 3.38 ms; peak CPU 81.8% |
| long | `19:47:50`–`20:47:59` | FAIL | register p95 153.63 ms; draw_barrel 2.07 ms; peak CPU 81.9% |

**Re-verification:** Pending after product + harness fixes — storm/ramp/steady/long must all analyzer-PASS before DONE.

---

## Open KNOWN GAPS (updated 2026-08-30 roadmap audit)

See `docs/IMPLEMENTATION_STATUS.md` § TECHNICAL DEBT and § KNOWN GAPS:

- **TD-1:** `admin_stats_data` — **implementation complete**; documentation/status reconciliation pending (Low, not blocking deployment).
- **TD-2:** `error.banned` — reserved/unused per ADR-007; `banned` packet canonical (Very Low, documentation only).
- Real-WS test log noise (low) — unchanged.
- Phase 11 VPS verification (TD-3) — **11.1–11.5 DONE**; **11.6 BLOCKED / NEEDS CHANGES** (acceptance FAIL; diagnosis + fixes in progress).

---

## Phase 12 Readiness

| Criterion | Status |
|-----------|--------|
| All protocol actions wired | ✅ Fixed (P11-001) |
| Unit/integration tests pass | ✅ 28/28 on Windows |
| Live WS tests pass | ✅ **145/145 PASS** on VPS (`box-963286`, `f2c9cfb`, 2026-09-02) |
| Memory/timer/load audits | ✅ **11.1–11.5 VPS verified**; ⛔ **11.6 BLOCKED** (register p95 / peak CPU acceptance FAIL) |
| Protocol docs synced | ✅ `admin_stats_data` implemented (TD-1 docs reconciliation only); `error.banned` reserved per ADR-007 (TD-2) |

**Verdict:** Proceed with Phase 12 frontend development in parallel with completing remaining EPIC-11.6 VPS load verification (TD-3). Live-server protocol tests, memory/stability, timer drift, economy integrity, and state-machine live-session replay are verified on Ubuntu VPS. `admin_stats_data` is implemented end-to-end; remaining TD-1 work is documentation reconciliation only.

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
