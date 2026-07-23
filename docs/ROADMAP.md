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
Status: Current phase (10.0-10.5 done, 10.6 next — see docs/IMPLEMENTATION_STATUS.md for authoritative detail).

- EPIC-10.0 Protocol router
- EPIC-10.1 Packet validation
- EPIC-10.2 Protocol error handling
- EPIC-10.3 Auth packet integration
- EPIC-10.4 Lobby packet integration
- EPIC-10.5 Game packet integration
- EPIC-10.6 Admin packet integration
- EPIC-10.7 Protocol integration tests

---

# PHASE 11 — FRONTEND
Goal: Minimal playable client.

- EPIC-11.0 ws.js
- EPIC-11.1 app.js
- EPIC-11.2 Lobby UI
- EPIC-11.3 Game UI
- EPIC-11.4 Reconnect UI
- EPIC-11.5 Localization
- EPIC-11.6 Frontend integration tests

---

# PHASE 12 — RELEASE
Goal: Production readiness.

- EPIC-12.0 Full integration testing
- EPIC-12.1 Memory audit
- EPIC-12.2 Timer audit
- EPIC-12.3 Economy audit
- EPIC-12.4 State machine audit
- EPIC-12.5 Protocol audit
- EPIC-12.6 Load testing
- EPIC-12.7 Release Candidate
- EPIC-12.8 Version 1.0 Release — Status: Project Complete
