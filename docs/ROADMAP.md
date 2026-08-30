# ROADMAP.md

## Purpose
Authoritative source for Epic numbering, implementation order, dependency order, and project completion status. If implementation order contradicts this doc, this doc is correct.

---

# PHASE 0 — FOUNDATION
Goal: Create the entire technical foundation before any gameplay implementation.
Status: Complete.

- EPIC-0.1 Project skeleton — Completed
- EPIC-0.2 Composer configuration — Completed
- EPIC-0.3 Database layer (Database.php) — Completed
- EPIC-0.4 Prepared statement registry (PreparedStatements.php) — Completed
- EPIC-0.5 Logger (Logger.php) — Completed
- EPIC-0.6 Constants (Constants.php) — Completed
- EPIC-0.7 SessionService (SessionService.php) — Completed
- EPIC-0.8 Infrastructure validation — Completed
- EPIC-0.9 Helpers (Helpers.php) — Completed

---

# PHASE 1 — AUTHENTICATION
Goal: Complete user identity lifecycle.
Status: Complete.

- EPIC-1.0 AuthService registration (AuthService.php) — Completed
- EPIC-1.1 AuthService login — username lookup, password verification, ban validation, daily bonus
- EPIC-1.2 Session validation flow — session restore, session verification
- EPIC-1.3 AuthHandler — register action, login action, reconnect action
- EPIC-1.4 Authentication integration tests

---

# PHASE 2 — ROOM LOBBY
Goal: Create and manage rooms.

- EPIC-2.0 RoomManager
- EPIC-2.1 Create room
- EPIC-2.2 Join room
- EPIC-2.3 Leave room
- EPIC-2.4 Room list
- EPIC-2.5 Host transfer
- EPIC-2.6 Lobby AFK system
- EPIC-2.7 Lobby integration tests

---

# PHASE 3 — LOTTO ENGINE
Goal: Implement pure lotto mathematics.

- EPIC-3.0 Card generator
- EPIC-3.1 Bag generator
- EPIC-3.2 Card validation
- EPIC-3.3 Bag validation
- EPIC-3.4 Engine test suite

---

# PHASE 4 — GAME START
Goal: Prepare room transition into gameplay.

- EPIC-4.0 Player card purchase logic
- EPIC-4.1 Game initialization
- EPIC-4.2 Bank creation
- EPIC-4.3 StartGame transaction
- EPIC-4.4 Game start protocol
- EPIC-4.5 Game initialization tests

---

# PHASE 5 — TURN SYSTEM
Goal: Implement barrel drawing.

- EPIC-5.0 Drawer queue
- EPIC-5.1 Drawer rotation
- EPIC-5.2 Draw barrel
- EPIC-5.3 Broadcast drawn barrel
- EPIC-5.4 Player card marking
- EPIC-5.5 Turn system tests

---

# PHASE 6 — VICTORY SYSTEM
Goal: Implement game completion.

- EPIC-6.0 Victory detection
- EPIC-6.1 Double victory detection
- EPIC-6.2 Prize calculation
- EPIC-6.3 Winner payout transaction
- EPIC-6.4 Game finish flow
- EPIC-6.5 Victory tests

---

# PHASE 7 — APARTMENT
Goal: Implement apartment mechanics.

- EPIC-7.0 Line detection
- EPIC-7.1 Apartment trigger
- EPIC-7.2 Apartment state
- EPIC-7.3 Apartment voting
- EPIC-7.4 Apartment payment transaction
- EPIC-7.5 Apartment timeout
- EPIC-7.6 Apartment integration tests

---

# PHASE 8 — RECONNECT & AFK
Goal: Implement player recovery and protection systems.

- EPIC-8.0 ReconnectService
- EPIC-8.1 Disconnect processing
- EPIC-8.2 Reconnect restoration
- EPIC-8.3 Game AFK protection
- EPIC-8.4 Auto draw
- EPIC-8.5 AFK removal
- EPIC-8.6 Reconnect tests

---

# PHASE 9 — ADMIN
Goal: Administrative control.

- EPIC-9.0 Admin authentication
- EPIC-9.1 Ban user
- EPIC-9.2 Unban user
- EPIC-9.3 Kick player
- EPIC-9.4 Close room
- EPIC-9.5 Logs access
- EPIC-9.6 Admin tests

---

# PHASE 10 — WEBSOCKET PROTOCOL
Goal: Connect services to protocol.
Status: Complete (10.0-10.7 all done — see docs/IMPLEMENTATION_STATUS.md for authoritative detail).

- EPIC-10.0 Protocol router
- EPIC-10.1 Packet validation
- EPIC-10.2 Protocol error handling
- EPIC-10.3 Auth packet integration
- EPIC-10.4 Lobby packet integration
- EPIC-10.5 Game packet integration
- EPIC-10.6 Admin packet integration
- EPIC-10.7 Protocol integration tests

---

# PHASE 11 — AUDITS & LOAD TESTING
Goal: Verify the completed server (Phases 0-10) end to end before building
a client against it — memory, timers, economy, state machine, protocol,
and load, all audited while the protocol is still cheap to change if an
audit surfaces something. Previously numbered Phase 12.0-12.6; reordered
ahead of Frontend (see note below).
Status: In progress (EPIC-11.0 started 2026-07-27; see docs/PHASE_11_REPORT.md).

- EPIC-11.0 Full integration testing — DONE
- EPIC-11.1 Memory audit — IN PROGRESS (instrumentation done; VPS 6h run pending)
- EPIC-11.2 Timer audit — IN PROGRESS (instrumentation done; VPS accelerated run pending)
- EPIC-11.3 Economy audit — IN PROGRESS (instrumentation done; VPS live-game run pending)
- EPIC-11.4 State machine audit — IN PROGRESS (instrumentation done; VPS live-game run pending)
- EPIC-11.5 Protocol audit — IN PROGRESS (instrumentation done; VPS live replay pending)
- EPIC-11.6 Load testing — IN PROGRESS (instrumentation done; VPS load runs pending)

See **TD-3** under TECHNICAL DEBT for VPS verification matrix (11.1–11.6).

---

# PHASE 12 — FRONTEND
Goal: Minimal playable client, built against a server already audited in
Phase 11. Previously numbered Phase 11; reordered after Audits & Load
Testing (see note below).

- EPIC-12.0 ws.js
- EPIC-12.1 app.js
- EPIC-12.2 Lobby UI
- EPIC-12.3 Game UI
- EPIC-12.4 Reconnect UI
- EPIC-12.5 Localization
- EPIC-12.6 Frontend integration tests

---

# PHASE 13 — GAME AFK WIRING & ORPHANED-METHOD FIXES
Goal: Wire the implemented-but-unconnected game AFK timer and first-turn
`your_turn` into production paths; close gaps from the 2026-07-28 orphaned-
methods audit (docs/AUDIT_ORPHANED_METHODS_2026-07-28.md). Reclaims Phase 13
number previously skipped (see note below).
Status: In progress.

- EPIC-13.0 ADR: Game AFK timer wiring decision — Completed
- EPIC-13.1 Wire first-turn your_turn + AFK arm into handleStartGame()
- EPIC-13.2 Wire AFK arm into handleDrawBarrel() turn rotation
- EPIC-13.3 Wire AFK arm into drawer-replacement paths
- EPIC-13.4 Test corrections + turn-start integration test
- EPIC-13.5 Apartment early-finish check on kick/ban removal
- EPIC-13.6 Investigation: reconnect mid-turn drawer turn-signal
- EPIC-13.7 Cleanup: dead utility RoomManager::findRoomIdByUserId() (optional)

---

# PHASE 14 — RELEASE
Goal: Production readiness — release candidate through v1.0. Previously
numbered EPIC-12.7/12.8 (Phase 12's own final two epics); given its own
phase number once Phase 12 was reassigned to Frontend (see note below).

- EPIC-14.0 Release Candidate
- EPIC-14.1 Version 1.0 Release — Status: Project Complete

---

## Note on Phase 11/12/14 reordering (2026-07-26)
Phases 11 and 12 were swapped from their original assignment (Frontend
was 11, Release-readiness audits were 12.0-12.6 of a combined "Release"
phase) — approved decision: audit the server thoroughly before building
a client against it, so a protocol change surfaced by an audit doesn't
mean redoing frontend work. The former Phase 12's last two epics
(Release Candidate, v1.0 Release) were promoted to their own phase
(14) once Phase 12 itself was reassigned to Frontend, rather than
awkwardly living inside the Frontend phase. Phase 13 was initially
skipped (2026-07-26) but reclaimed 2026-07-28 for game AFK wiring
fixes — see PHASE 13 above. None of the Phase 11/12/14 epics were
implemented before the 2026-07-26 reorder; that renumbering was
documentation-only and carries no migration risk.

---

# SYSTEM DEPLOYMENT

Independent workstream for generic multi-instance deployment (Docker Compose +
native systemd). Does **not** depend on TECHNICAL DEBT items unless an explicit
hard dependency is documented in `docs/IMPLEMENTATION_STATUS.md`.

Authoritative detail: ADR-036, ADR-037, `docs/LOCAL_ENVIRONMENT.md`.

```text
SYSTEM DEPLOYMENT

A   ADR-037 + explicit deploy/docker/ vs deploy/systemd/ separation
B1  Systemd instance identity and safety foundation
B2  Systemd installation
B3  Systemd removal and zero-artifact cleanup
C   Systemd update + healthcheck + resource limits
D   Documentation + integration/deployment tests
```

| Epic | Scope | Status |
|------|-------|--------|
| **A** | ADR-037; `deploy/docker/` vs `deploy/systemd/` layout; preserve Docker behaviour | **DONE** |
| **B1** | Instance-name validation, deterministic identity, paths, metadata, production guards, ownership model (no install/start) | **DONE** |
| **B2** | Dedicated service user, filesystem, config, DB, logs, port, unit, start/enable, init DB, healthcheck, idempotency | **DONE** |
| **B3** | Exact instance resolution, production guards, stop/disable, remove unit, daemon-reload, owned resources, conditional user removal, zero-artifact verification | **DONE** |
| **C** | Update, resource limits, healthcheck refinements, failure handling, DB preservation, optional minimal rollback | **DONE** |
| **D** | `LOCAL_ENVIRONMENT.md`, `ANCHOR_CORE.md`, README; systemd + Docker helper tests; multi-instance / coexistence VPS verification | **IMPLEMENTED** (docs + static/helper tests); **VPS multi-instance/coexistence verification pending** |

Sequence within this stream: **A → B1 → B2 → B3 → C → D**.

---

# TECHNICAL DEBT

Independent workstream. **NOT BLOCKING DEPLOYMENT** (no demonstrated hard
dependencies on SYSTEM DEPLOYMENT epics A–D).

```text
TECHNICAL DEBT

TD-1  ADR-023 / admin_stats_data reconciliation
TD-2  error.banned documentation/protocol debt (ADR-007)
TD-3  Phase 11 VPS verification (11.1–11.6)
```

| ID | Scope | Status | Priority | Blocks deployment? |
|----|-------|--------|----------|---------------------|
| **TD-1** | ADR-023 / `admin_stats_data` — documentation reconciliation | IMPLEMENTATION **DONE**; docs **RECONCILED** (2026-08-30); TEST COVERAGE **VERIFIED** (`test_admin_stats.php` 10/10) | Low | No |
| **TD-2** | `error.banned` vs `banned` packet — ADR-007 alignment | Runtime **IMPLEMENTED** (`error.banned` reserved/unused; `banned` packet canonical at login/reconnect/admin); DOCUMENTATION reconciliation only | Very Low | No |
| **TD-3** | Phase 11 VPS verification backlog | Instrumentation **IMPLEMENTED** for 11.1–11.6; mock/local tests pass; **VPS verification pending** for each sub-item | Medium (release readiness) | No |

### TD-3 — Phase 11 VPS verification matrix

| Sub-item | Instrumentation | Local/mock tests | VPS verification | Blocks release? | Blocks deployment? |
|----------|-----------------|------------------|------------------|-----------------|-------------------|
| **11.1** Memory | DONE (`MemoryAudit`) | PASS (`test_memory_audit.php`) | **PENDING** (6h `memory_stability_runner.php`) | Advisory | No |
| **11.2** Timer | DONE (`TimerAudit`) | PASS (`test_timer_audit.php` 20/20) | **PENDING** (`timer_accelerated_runner.php`) | Advisory | No |
| **11.3** Economy | DONE (`EconomyAudit`) | PASS (`test_economy_audit.php` 32/32) | **PENDING** (live-game log replay) | Advisory | No |
| **11.4** State machine | DONE (`StateMachineAudit`) | PASS (`test_state_machine_audit.php` 29/29) | **PENDING** (live-session log replay) | Advisory | No |
| **11.5** Protocol replay | DONE (`ws_emulator.php`, `test_protocol_audit.php`) | Static PASS (`test_protocol_completeness.php`) | **PENDING** (9 live WS tests on VPS) | Advisory | No |
| **11.6** Load testing | DONE (`LoadAudit`) | PASS (`test_load_audit.php`) | **PENDING** (4 VPS load scenarios) | Advisory | No |

Evidence: `docs/PHASE_11_REPORT.md`. No VPS runs were executed for this roadmap update.
