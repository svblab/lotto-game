# 017 — Reconnect Turn Restoration (active drawer UI)

## Status

Accepted

## Context

Manual QA (section F, three browsers) confirmed: when a player reconnects while
they are the active drawer (`active_drawer_conn_id` matches their new connection
after re-keying), the client showed "waiting for {self}'s turn" instead of the
Draw button and AFK countdown. The server AFK timer continued correctly; the
player could not act until auto-draw fired or their next natural turn arrived
(a fresh `your_turn` packet fixed the UI).

Root cause:

- `ReconnectService::restorePlayerConnection()` already restarts `afk_start` for
  the reconnecting active drawer before `buildReconnectState()` runs.
- `GameService::sendYourTurn()` is never invoked on the reconnect path — only on
  game start, turn rotation, and apartment resume.
- `buildReconnectState()` did not expose turn ownership or AFK parameters.
- Client `onReconnectState()` never set `state.isMyTurn` / `state.pendingTurnPkt`,
  so `syncTurnUi()` fell into the waiting branch.

Constraints: ANCHOR_RULES.md Rule 6 (no unrelated refactors); additive protocol
change only (same pattern as ADR-014 `win_chances` on `reconnect_state`).

## Decision

Extend the `reconnect_state` playing payload with turn-state fields instead of
calling `sendYourTurn()` on reconnect:

- `is_my_turn` (bool): true when reconnecting `conn_id` equals
  `active_drawer_conn_id`.
- When true, also include `afk_start`, `turn_seconds`, and `auto_draws` — same
  semantics as the non-deferred `your_turn` variant (client must not send
  `turn_ready`; server timer is already running).

Client: in `onReconnectState()` playing branch, set `state.isMyTurn` and
`state.pendingTurnPkt` from these fields before `syncTurnUi()`.

No protocol version bump; new optional fields only.

## Consequences

Positive:

- Reconnecting active drawer immediately sees Draw button and AFK countdown
  aligned with the server timer.
- No duplicate `your_turn` or `turn_ready` round-trip; AFK timer not restarted.

Negative / limitations:

- `winChanceHistory` and other client-only state still not restored on reconnect
  (pre-existing scope).

Follow-up:

- MANUAL VERIFICATION REQUIRED (ANCHOR_RULES.md Part 15):
  1. Two browser tabs: host + one other player; start a 2-player game.
  2. Host draws a barrel (becomes / remains active drawer).
  3. Host closes the browser tab completely.
  4. Within 15 seconds, reopen and wait for auto-reconnect.
  5. Confirm Draw button and AFK countdown appear immediately (no "waiting for
     turn" for self); host can draw before auto-draw fires.
