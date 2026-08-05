# 019 — Server-Side Win-Chance History (game_over chart)

## Status

Accepted

## Context

FIX-27 recorded win-chance history client-side (`state.winChanceHistory`) when
each browser received `barrels_drawn`. That limitation was noted as acceptable
(history lost on reconnect), but manual QA showed worse divergence: disconnected
clients missed broadcasts (`barrels_drawn` only goes to `active` players), so
charts differed in point count, turn index, and values. `game_over` carried no
history.

## Decision

- Add `win_chance_history` to Room Structure (`RoomManager::createRoom()`).
- Append one snapshot per `broadcastBarrelsDrawn()` that includes `win_chances`
  (same moments as today — not on `game_started`, reconnect, or apartment).
- Ship the full array in `game_over` (`finishGame()` and `handleNoSurvivors()`).
- Remove client-local `recordWinChanceSnapshot()` / `state.winChanceHistory`;
  `onGameOver()` uses `pkt.win_chance_history`.

No protocol version bump; new room key (authorized by this ADR) + new `game_over`
field only.

## Consequences

Positive:

- Identical end-game chart for every player regardless of disconnects.

Negative:

- Small per-room memory growth during play (one map per draw).

MANUAL VERIFICATION REQUIRED (ANCHOR_RULES.md Part 15):

1. Three browsers; mid-game, one player closes tab >15s and reconnects (misses
   1–2 draws).
2. Play to `game_over`.
3. Confirm all three result modals show the same chart (same points and values).
