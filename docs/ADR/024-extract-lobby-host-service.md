# 024 — Extract LobbyHostService from LobbyService

## Status

Accepted

This ADR is written and implemented in the same Epic (EPIC-026.0), following
the pattern used for ADR-015 / GameTurnService (EPIC-024.0): analysis and
extraction land together rather than as a separate approval gate.

## Context

`src/Lobby/LobbyService.php` is **954 lines** — the largest file in the
project, with no prior ADR tracking its decomposition. It exceeds the
**700-line warning** threshold in `ANCHOR_CORE.md` § File Size Policy.

The lobby-host-lifecycle / lobby-AFK-timer cluster (`transferHost`,
`closeRoomAfkExhausted`, `startLobbyAfkTimer`, `stopLobbyAfkTimer`,
`promoteLobbyHost`, `touchLobbyHostActivity`, `suspendLobbyHost`,
`broadcastLobbyAfkSync`, `broadcastHostChanged`, `lobbyHostTimeoutFields`,
`resolveLobbyHostUsername`) is a cohesive ~312-line block with a clear
internal call graph, distinct from room create/join/leave handlers.

## Decision

Extract that cluster into `src/Lobby/LobbyHostService.php`, using the
**delegate-facade pattern from ADR-015**:

- `LobbyService` keeps one-line delegates for the 8 methods still called
  from outside the cluster or from non-moving `LobbyService` methods.
- `LobbyHostService` does **not** depend on `LobbyService` back — two
  small helpers (`buildPlayerLeftPacket`, `broadcastRoomList` /
  `buildRoomListPacket`) are duplicated verbatim to avoid circular
  dependency (same rationale as ADR-015).
- Three methods with no external callers (`closeRoomAfkExhausted`,
  `stopLobbyAfkTimer`, `broadcastHostChanged`) move with no delegate left
  on `LobbyService`.

`server.php` wires `LobbyHostService` before `LobbyService` (third
constructor argument).

## Consequences

**Positive:**

- `LobbyService.php` drops from ~954 lines to ~640–680 — below the
  700-line warning threshold.
- Host/AFK logic is isolated for future maintenance without touching
  join/leave/create paths.

**Negative / honest limits:**

- This alone does **not** reach the 500-line target; `handleJoinRoom` and
  `handleCreateRoom` remain large individual methods worth a future look
  if further reduction is desired.
- Two helpers are duplicated (intentional trade-off vs. circular deps).

**Compatibility:** Internal refactor only — no protocol change, no rename
of any public-facing method signature on `LobbyService`.
