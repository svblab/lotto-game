# Orphaned-Methods Audit — 2026-07-28

**Status: RESOLVED (Phase 13 / ADR-008, 2026-07-28).** This report is an
archived snapshot of gaps found during live-browser reproduction of first-turn
drawer inactivity. Do not treat the tables below as current production state.

Closed by:
- EPIC-13.0 ADR-008 — `GameService::startTurn()` atomically sends `your_turn`
  and arms AFK via `ReconnectService::ensureGameAfkTimer()`
- EPIC-13.1 — first-turn wiring in `handleStartGame()`
- EPIC-13.2 — AFK arm on `handleDrawBarrel()` rotation
- EPIC-13.3 — AFK arm on drawer replacement (`removePlayerFromGame`,
  `finishApartment`)
- EPIC-13.4 — test corrections (`your_turn` + `game_afk_timer_id`)

Still open (not this audit's original "orphaned methods"):
- EPIC-13.6 reconnect mid-turn drawer turn-signal — see IMPLEMENTATION_STATUS.md
  KNOWN GAPS (protocol / `your_turn` resend; not reproduced live)
- EPIC-13.5 apartment early-finish on kick/ban — implemented (self-heal timer
  remains as a backstop)

---

Read-only audit of production call sites for methods that are implemented and
unit-tested but never invoked from live code paths. Origin: live-browser
reproduction of first-turn drawer button inactivity (no `your_turn`, no AFK
timer armed at game start).

## Confirmed gaps (Phase 13 scope) — all wired

| Method | Location | Issue (historical) | Resolution |
|--------|----------|--------------------|------------|
| `ReconnectService::ensureGameAfkTimer()` | `src/Game/ReconnectService.php` | Zero production callers. Game AFK timer never armed. | Called from `GameService::startTurn()` (ADR-008). |
| `GameService::sendYourTurn()` at start | `src/Game/GameService.php` `handleStartGame()` | Never called after `game_started` broadcast. Root cause of first-turn button bug. | `handleStartGame()` calls `startTurn()`. |
| `ensureGameAfkTimer` after rotation | `handleDrawBarrel()` | `sendYourTurn()` called but timer never (re-)armed. | Rotation uses `startTurn()`. |
| `ensureGameAfkTimer` after drawer replacement | `ReconnectService::removePlayerFromGame()`, `ApartmentService::finishApartment()` | `sendYourTurn()` called; AFK timer not armed. | Both paths use `startTurn()`. |

## Test gaps encoding the bug — corrected in EPIC-13.4

| File | Lines / group | Issue (historical) |
|------|---------------|--------------------|
| `tests/Manual/test_game_start.php` | Group 7 | Expected only `game_started`, not `your_turn`. Now asserts `your_turn`. |
| `tests/Manual/test_turn_system.php` | Group 4 | Did not assert `game_afk_timer_id`. Now asserts AFK arm. |
| `tests/Manual/test_game_packet_routing.php` | TEST 2 | Checks `your_turn` after `game_started`. |
| `tests/Manual/test_phase11_core_flows.php` | start_game flow | Same as above. |
| `tests/Manual/test_game_start_turn_integration.php` | (new in 13.4) | Full seam: `handleStartGame()` → `game_started` → `your_turn` → `afk_start` → `game_afk_timer_id`. |

## Suspected gaps (separate epics)

| Area | Confidence | Notes |
|------|------------|-------|
| Admin kick/ban during apartment — no `allRequiredAnswered()` → `finishApartment()` | Logic gap was confirmed | EPIC-13.5: early-finish check; 10s apartment timer remains a self-heal backstop. |
| Reconnect mid-turn — no `your_turn` resend | Spec ambiguous | EPIC-13.6 investigation; still a KNOWN GAP. |
| `RoomManager::findRoomIdByUserId()` | Zero production callers | Only `test_lobby_integration.php`. EPIC-13.7 (optional cleanup). |

## Methods verified as wired (not orphaned)

- `sendYourTurn()` in `handleDrawBarrel()` rotation path — now via `startTurn()`, which also arms AFK.
- `tickGameAfk()`, `performAutoDraw()`, `stopGameAfkTimer()` — internal to ReconnectService; `ensureGameAfkTimer` is called from `startTurn()`.
