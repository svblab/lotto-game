# 021 — Live Roster Status and Ghost Entries (playing session)

## Status

Accepted

## Context

Manual QA (section F) found two live-session legend bugs (reconnect roster was
fixed in EPIC-17.1 / ADR-020):

1. When a player disconnects mid-game, other players' legend kept showing them
   as Online until the 15s reconnect timeout — no live status update was sent.
2. When a player was finally removed (timeout/AFK/kick/etc.), they vanished from
   the legend instead of becoming a "left" ghost entry.

Root cause:

- `ReconnectService::handleDisconnect()` set `status = 'disconnected'` but never
  broadcast to the room.
- `ReconnectService::restorePlayerConnection()` flipped status back to `active`
  on reconnect but likewise never broadcast.
- Client `onPlayerLeft()` unconditionally filtered the departing player out of
  `state.players` (in-game legend) regardless of `reason`.

ADR-020 deferred live ghosting and `removed` styling to this Epic.

## Decision

**Server (playing only):**

- New packet `player_status_changed` broadcast via `broadcastToRoom()` on
  disconnect (`status: "disconnected"`) and on successful reconnect from
  disconnected (`status: "active"`, only when `$wasDisconnected`).

**Client:**

- New handler `onPlayerStatusChanged()` updates `state.players[idx].status`.
- `onPlayerLeft()` in-game branch: mark entry `status: "removed"` with `reason`
  instead of filtering out (lobby `state.room.players` filter unchanged).
- `renderPlayerList()` / CSS / i18n: `status-removed` styling for ghost rows.

No protocol version bump; additive packet + client behavior only (same pattern
as ADR-009/014/017/020).

## Consequences

Positive:

- Live legend reflects Away immediately on disconnect and Online on reconnect.
- Removed players stay visible as ghost entries until `game_over`, consistent
  with reconnect roster (ADR-020).

Negative / limitations:

- `player_status_changed` is not sent during `waiting` or `apartment` — those
  phases use immediate removal via `player_left`.

Follow-up:

- MANUAL VERIFICATION REQUIRED (ANCHOR_RULES.md Part 15):
  1. Three players in a live game.
  2. One player closes tab — others see "Away" immediately (not Online).
  3. Same player reconnects within 15s — others see "Online" again.
  4. Remove one player (AFK/kick); remaining players see them as "Left" ghost,
     not missing from the legend.
