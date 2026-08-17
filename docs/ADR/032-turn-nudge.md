# 032 — Per-turn social nudge (hurry-up signal)

## Status

accepted

## Context

While a room is in `playing` and a specific player is the current drawer
(`room['active_drawer_conn_id']`), other seated players have no protocol way
to signal "hurry up". The only time pressure today is the server-authoritative
Game AFK timer (`player.afk_start`, `turn_seconds` derived from
`GAME_AFK_STRIKE1/2/3_SECONDS` / `auto_draws` — ADR-012).

A social nudge is useful UX, but it must not become a vector to shorten,
extend, or otherwise manipulate another player's turn. Any coupling to
`afk_start`, strike windows, `auto_draws`, or `game_afk_timer_id` would let
the room harass a drawer into an earlier auto-draw or removal.

Constraints:

- ANCHOR_CORE.md Part 1 § Player Structure / Part 6 registries: new player
  key and protocol names require an ADR.
- ANCHOR_CORE.md Part 5 § Game AFK Timer: no additional timer types; existing
  AFK fields are the sole turn-timing authority.
- ANCHOR_RULES.md Rule 7: new protocol actions/packets need spec/ADR approval
  (this document).
- File Size Policy: `GameHandler.php` (handler ≤300) and `GameTurnService.php`
  (service ≤500) must stay within limits; logic belongs in the turn cluster
  (ADR-015), not a new Game file.

## Decision

### Feature

Any **other** connected, `active` player seated in the same `playing` room
may send the current drawer exactly **one** lightweight "hurry up" signal
per turn. This is social/UX only.

### Protocol

**Client → server action** (no payload; server uses sender `connId` and the
room's `active_drawer_conn_id`):

```json
{"action": "nudge_turn"}
```

**Server → drawer packet** (private; never broadcast to the room):

```json
{"type": "nudge_received", "from": "username"}
```

`from` is the nudging player's `username`.

### Server rules (all must hold; otherwise reject)

1. A room exists for the sender and `status === 'playing'`.
2. Sender is a connected `active` player in that room's `$room['players']`
   (not a spectator, not already eliminated / absent from `players`, not
   `disconnected`).
3. Sender `connId` is **not** `active_drawer_conn_id` (cannot nudge yourself;
   enforced server-side even if the client hides the button).
4. At most one successful nudge per player per turn, tracked by transient
   RAM flag `room['players'][$connId]['nudged_this_turn']` (missing treated
   as `false`). Reset to `false` for **all** seated players in
   `GameTurnService::startTurn()` — the same point a new drawer is notified
   and AFK is (re)armed via `sendYourTurn()`. Not reset in
   `handleTurnReady()` / the same-turn `sendYourTurn(false)` re-arm, so the
   once-per-turn cap survives the slot-animation round-trip.
5. On success: set the sender's flag, then send `nudge_received` **only** to
   the current drawer's connection. Do not write `afk_start`, `strikes`,
   `auto_draws`, `last_action`, `turn_seconds`, or `game_afk_timer_id`.

### Error-code choices

| Condition | Code | Why |
|-----------|------|-----|
| Sender not seated in any room (eliminated / spectator / unknown conn) | `error.room_not_found` | Same as `draw_barrel` when the sender has no room; "not in this room" has no `room_id` in the payload, so "not in any room" is the only addressable case. |
| Room not `playing`; sender not `active`; no current drawer | `error.not_your_turn` | Existing "wrong phase / wrong actor for this action" bucket (`draw_barrel` already uses it for non-playing and non-drawer). |
| Sender **is** the current drawer (self-nudge) | `error.not_your_turn` | Inverse of `draw_barrel`'s actor check: this action is for non-drawers. A dedicated `error.cannot_nudge_self` was rejected to avoid registry sprawl; the client never shows the control to the drawer, so this path is protocol abuse only. |
| Second (or later) nudge from the same player in the same turn | `error.already_nudged` | **New.** Needs a distinct code so the client can silently disable the button without a jarring generic toast (`error.not_your_turn` would be misleading here). |

Unauthenticated connections are rejected by the existing server.php auth
gate (`error.auth_required`) before dispatch, matching other game actions.

### Placement

- `GameHandler::handleNudgeTurn()` — routing only (same thin pattern as
  `handleTurnReady()`).
- `GameService::handleNudgeTurn()` — required passthrough: `GameHandler`
  only holds `GameService` (cannot call `GameTurnService` without a
  constructor signature change, which is forbidden).
- `GameTurnService::handleNudgeTurn()` — validation, flag, private packet.
- `server.php` action dispatcher + ANCHOR_CORE Part 6 Protocol Actions
  (do not omit the action from the allowed list — `turn_ready` lesson).

### Client (this pass)

In-page toast (`showToast`) naming who nudged, plus a 6th `LottoSound`
key `nudge`. No Vibration API, no Web Notifications API (deferred).

## Consequences

Positive:

- Drawer gets a private, once-per-turn social prompt without AFK coupling.
- Anti-spam is server-authoritative; a desynced client retry is
  `error.already_nudged`.
- Additive protocol: old clients ignore unknown `nudge_received`; they
  simply never send `nudge_turn`. No `PROTOCOL_VERSION` bump.

Negative / limitations:

- After apartment resume, `startTurn()` resets flags for the same drawer;
  clients that already disabled the button on the previous `barrels_drawn`
  may stay disabled until the next draw. Harmless (server would accept);
  not a timing exploit.
- Reconnect keeps `nudged_this_turn` on the player record (conn id may
  change; the flag lives on the player array). A reconnect cannot buy a
  second nudge in the same turn.

Follow-up (out of scope): Vibration API / Web Notifications API.
