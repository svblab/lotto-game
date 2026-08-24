# 035 — Room game-speed mode (slow / fast)

## Status

accepted

## Context

Hosts want a faster barrel-reveal experience for experienced players without
changing server draw timing, AFK thresholds, or draw semantics. Animation
duration is entirely a client concern; AFK still arms only after `turn_ready`
following animation completion (a shorter animation simply starts AFK sooner).

Rule 7 requires an ADR for a new Room Structure key and additive protocol
fields. No `PROTOCOL_VERSION` bump (additive only).

## Decision

### Room field

```text
speed_mode: "slow" | "fast"   // default "slow"
```

- Set only at `create_room` (optional field; omitted ⇒ `"slow"`).
- Frozen for the room lifetime (same lock pattern as stake/`cards_count` seat
  choice after create — host must create a new room to change mode).
- Stored on the room in RAM (`RoomManager::createRoom` initializes `"slow"`;
  `LobbyService::handleCreateRoom` may overwrite after validation).

### Protocol (additive)

| Surface | Change |
|---------|--------|
| `create_room` | Optional `speed_mode`: `"slow"` \| `"fast"` |
| `room_list` entry | `speed_mode` |
| `room_joined` | `speed_mode` |
| `reconnect_state` (waiting + playing) | `speed_mode` |
| `player_joined` | **No** `speed_mode` — packet is player-centric; joiners already receive the room field via `room_joined` |

Invalid `speed_mode` → `error.invalid_json`.

### Client timing

- **Slow:** unchanged (~3s interval between the three number reveals).
- **Fast:** simultaneous sharp spin-up; total ≈3s — 1.0s spin-up, 0.5s full
  spin, then reels stop left-to-right at 0.5s intervals. Gold match-pulse
  (`match-flash` / Фаза 7) omitted in fast mode; marks still apply instantly.
- Audio unchanged in both modes (follow-up prompt later).
- Do **not** change `GameTurnService` / `LottoEngine` / Game AFK logic.

### Epic

Single Epic (EPIC-035): server field wiring + client animation/UI/i18n.
Decompose only if new code exceeds ~500 lines across an unmanageable file set.

## Consequences

- Existing clients/tests that omit `speed_mode` keep slow behavior.
- Lobby list can show mode so players pick fast rooms deliberately.
- Reconnecting clients restore the correct animation profile from
  `reconnect_state` without re-deriving it.
