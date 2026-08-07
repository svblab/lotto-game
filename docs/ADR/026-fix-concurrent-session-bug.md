# 026 — Fix concurrent session bug (dual live sockets)

## Status

Accepted

## Context

Manual QA (2026-08-07) reproduced two browser contexts authenticated as the
same `user_id` simultaneously: one socket created a room while another joined
that room as a second occupant, with economy side-effects (duplicate coin
credits).

Automated investigation (EPIC-028.0) showed:

- Stale-token reconnect after a fresh login elsewhere is **correctly rejected**
  (`revokeTokensForUser` in `SessionGuardService::claimUserSession()` works).
- The user's initial hypothesis (stale token still accepted at reconnect) was
  **not** the root cause in the tested paths.
- The real gap is a **split-brain auth state**: `join_room`'s same-user rebind
  path (`LobbyService::handleJoinRoom` → `ReconnectService::rebindSeat`) moved
  the room seat to the new connection via `bindConnectionToPlayer()` **without
  evicting the prior live socket**. That left two connections with
  `$connection->userId` set for one account. The auth guard in `server.php`
  keys off per-connection `userId`, not `userConnections` alone, so both
  sockets remained fully authenticated.
- A secondary contributor: `onClose` cleared `userConnections[$userId]` but
  left Connection Runtime Fields (`userId`, `sessionToken`, etc.) on the closed
  socket object, making zombie sockets harder to reason about during the next
  claim pass.
- A tertiary bug in `removeConnectionFromRoom()`: when evicting a superseded
  socket after `rebindSeat`, the `user_id` fallback located the **re-keyed**
  seat (bound to the winning connection) and removed it from the lobby instead
  of no-oping — because the old `conn_id` was no longer in the room map.

`ReconnectService::bindConnectionToPlayer()` setting `userConnections` without
going through eviction was safe only when `claimUserSession()` had already run
on the login/reconnect path — not when lobby rebind was the first operation
binding the second socket.

## Decision

1. **`SessionGuardService::claimUserSession()`** — add a post-bind sweep that
   evicts any remaining live socket with the same `userId` (belt-and-suspenders
   after the primary `findAllLiveConnectionsForUser` pass).

2. **`LobbyService::handleJoinRoom()`** — on same-user rebind: `rebindSeat` first (re-key
   the room seat onto the joining socket), then call
   `$worker->sessionGuard->evictOtherLiveSessions()` so the superseded live
   socket is closed without deleting the seat that was just moved.

3. **`server.php` `onClose`** — clear Connection Runtime Fields on the closing
   socket after releasing `userConnections`, matching `onWebSocketConnected`
   initialization.

4. **`server.php` DI** — expose `$worker->sessionGuard` for lobby-layer
   hardening (same pattern as `$worker->lobbyService`, `$worker->reconnectService`).

5. **`SessionGuardService::removeConnectionFromRoom()`** — when resolving a
   seat via `user_id` fallback, verify the player entry's `connection` object
   matches the socket being evicted; skip removal if the seat was already
   re-keyed to another live connection.

No protocol or packet contract changes.

## Consequences

**Positive:**

- Closes the dual-live-socket path that allowed one user to create and join
  the same room from two connections.
- Hardens the single-session invariant at both the auth layer and the lobby
  rebind seam.
- `onClose` auth-field cleanup makes `findAllLiveConnectionsForUser` scans
  trustworthy.

**Negative / honest limits:**

- `create_room`'s `removeExistingSeatForUser()` can still vacate another
  connection's room seat without closing that socket; the post-bind sweep and
  join-time `claimUserSession` now prevent that socket from remaining
  authenticated alongside the winner.
- Economy regressions from other paths (e.g. daily-bonus timing) were not
  observed in automated repro and are out of scope unless re-reported.

**Compatibility:** Internal bugfix only — no ANCHOR_PROTOCOL.md /
ANCHOR_CORE.md contract changes.
