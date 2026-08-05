# 020 — Reconnect Roster with Ghosts (playing `reconnect_state`)

## Status

Accepted

## Context

Manual QA (section F) found that after reconnecting mid-game, the player's
roster/legend (usernames, card counts, statuses) was empty — the client had no
server data to rebuild it from. `ReconnectService::buildReconnectState()` never
included a `players` key in the `playing` branch; the client fell back to stale
in-memory `state.room.players`, which is empty after a full page reload (the most
common real-world reconnect scenario).

Separately, the product decision is that fully removed players should remain
visible as "left" ghosts until `game_over` rather than vanishing from the roster.
EPIC-17.0 (commit 1be6c1b) enriched `all_players_history` with `cards_count` and
`reason` on every write — this ADR is the first consumer of those fields for
reconnect roster reconstruction.

Constraints: ANCHOR_RULES.md Rule 6 (scoped file changes); additive protocol
change only (same pattern as ADR-014/017/018/019).

## Decision

Extend `reconnect_state` (playing) with a `players` array:

- **Present players**: reuse existing `buildLobbyPlayersList()` against
  `$room['players']` (`status: "active"|"disconnected"`, `cards_count`).
- **Removed players**: new `buildGamePlayersGhosts()` sourced from
  `all_players_history` entries whose `conn_id` is no longer in `$room['players']`
  (`status: "removed"`, `reason` echoed from history).

Client: `onReconnectState()` playing branch reads `pkt.players` instead of stale
`state.room.players`.

No protocol version bump; new optional field on `reconnect_state` (playing) only.

## Consequences

Positive:

- Reconnecting clients always receive a complete roster — active, disconnected,
  and removed players — regardless of page reload.
- Ghost entries carry `cards_count` and `reason` for future UI treatment.

Negative / limitations:

- Visual styling for `status: "removed"` and live (non-reconnect) in-session
  ghosting on `player_left` are deferred to EPIC-17.2 — this ADR covers data
  shape only.

Follow-up:

- MANUAL VERIFICATION REQUIRED (ANCHOR_RULES.md Part 15):
  1. Three players; start a game.
  2. Remove one via AFK auto-removal or admin kick mid-game.
  3. A different player closes and reopens their browser tab.
  4. Confirm roster/legend shows the removed player as a distinct "left" entry
     (not silently missing) and the two remaining players with correct card
     counts and statuses.
