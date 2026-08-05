# 018 — Realtime Bank Sync (barrels_drawn + bank_updated)

## Status

Accepted

## Context

Manual QA (section F): the bank amount in the room info bar was frozen at the
`game_started` value for the entire game. It only updated on `game_started` and
`reconnect_state`, even though the bank increases when players pay the Apartment
fee.

Root cause:

- `broadcastBarrelsDrawn()` did not include `bank`, so ordinary draws never
  refreshed the client cache (and could not pick up apartment-driven changes
  until the next draw).
- `finishApartment()` incremented `$room['bank']` in memory but never broadcast
  the new value; only `your_turn` followed (to the new drawer only), without
  `bank`.
- Client `state.bank` was only set in `onGameStarted` and `onReconnectState`.

## Decision

Two complementary fixes (incremental pattern, cf. ADR-014 `win_chances`):

1. Add `bank` to every `barrels_drawn` packet (`GameService::broadcastBarrelsDrawn`).
2. Broadcast `bank_updated` to the whole room immediately when apartment
   resolves and gameplay resumes (`ApartmentService::finishApartment`, game-
   continues branch only — not `last_survivor` / `no_survivors`, where
   `game_over.final_bank` follows).

Client: refresh `state.bank` from `barrels_drawn.bank` and handle `bank_updated`.

No protocol version bump; new field + new packet only.

## Consequences

Positive:

- Bank display stays accurate at apartment resolution and on every draw.
- Immune / non-participating players see the update without waiting for their
  turn.

Negative:

- One extra small broadcast per apartment resolution (acceptable).

MANUAL VERIFICATION REQUIRED (ANCHOR_RULES.md Part 15):

1. Three players; trigger Apartment (one player closes a row).
2. Have a non-participating (immune) player watch their header bank value.
3. When the paying player's Apartment modal clears, confirm the immune player's
   bank updates immediately — before anyone draws again.
