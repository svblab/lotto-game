# Orphaned-Methods Audit — 2026-07-28

Read-only audit of production call sites for methods that are implemented and
unit-tested but never invoked from live code paths. Origin: live-browser
reproduction of first-turn drawer button inactivity (no `your_turn`, no AFK
timer armed at game start).

## Confirmed gaps (Phase 13 scope)

| Method | Location | Issue |
|--------|----------|-------|
| `ReconnectService::ensureGameAfkTimer()` | `src/Game/ReconnectService.php` | Zero production callers. Game AFK timer never armed. |
| `GameService::sendYourTurn()` at start | `src/Game/GameService.php` `handleStartGame()` | Never called after `game_started` broadcast. Root cause of first-turn button bug. |
| `ensureGameAfkTimer` after rotation | `handleDrawBarrel()` | `sendYourTurn()` called (~line 530) but timer never (re-)armed. |
| `ensureGameAfkTimer` after drawer replacement | `ReconnectService::removePlayerFromGame()` (~431), `ApartmentService::finishApartment()` (~474) | `sendYourTurn()` called; AFK timer not armed. |

## Test gaps encoding the bug

| File | Lines / group | Issue |
|------|---------------|-------|
| `tests/Manual/test_game_start.php` | Group 7, lines 401–402 | `count($host->sent) === 1` / `count($p2->sent) === 1` — expects only `game_started`, not `your_turn`. |
| `tests/Manual/test_turn_system.php` | Group 4 | Asserts `your_turn` after `handleDrawBarrel`; does not assert `game_afk_timer_id`. |
| `tests/Manual/test_game_packet_routing.php` | TEST 2 | Checks `game_started` only; no `your_turn`/AFK side effects. |
| `tests/Manual/test_phase11_core_flows.php` | start_game flow | Same as above. |

No existing test covers the full seam: `handleStartGame()` → `game_started` →
`your_turn` → `afk_start` → `game_afk_timer_id`.

## Suspected gaps (separate epics)

| Area | Confidence | Notes |
|------|------------|-------|
| Admin kick/ban during apartment — no `allRequiredAnswered()` → `finishApartment()` | Logic gap confirmed | Self-heals via 10s apartment timer. EPIC-13.5. |
| Reconnect mid-turn — no `your_turn` resend | Spec ambiguous | EPIC-13.6 investigation. |
| `RoomManager::findRoomIdByUserId()` | Zero production callers | Only `test_lobby_integration.php`. EPIC-13.7. |

## Methods verified as wired (not orphaned)

- `sendYourTurn()` in `handleDrawBarrel()` rotation path — called, but without AFK arm.
- `tickGameAfk()`, `performAutoDraw()`, `stopGameAfkTimer()` — internal to ReconnectService; depend on `ensureGameAfkTimer` being called first.
