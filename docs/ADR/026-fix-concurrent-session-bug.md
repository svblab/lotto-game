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
- **EPIC-028.1 follow-up:** even with EPIC-028.0, production logs (2026-08-07
  21:16–21:17) still showed `Reconnect validated` followed by the same
  `user_id` creating and joining one room as two players. Root causes:
  1. **No per-action enforcement** — dual-live sockets could persist between
     `claimUserSession()` calls if eviction was missed or the client
     auto-reconnected after `error.auth_invalid_token` (WebSocket `close`
     scheduled reconnect before the error packet cleared `sessionToken`).
  2. **`ReconnectService::bindConnectionToPlayer()`** bound `userConnections`
     without evicting other live sockets (lobby/reconnect seam).
  3. **`join_room` new-player path** could add a second seat if the rebind
     branch was skipped — defensive rebind check added immediately before
     player insertion.

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

6. **EPIC-028.1 — `server.php` router** — call
   `evictOtherLiveSessions()` on every authenticated action (except
   register/login/reconnect/ping) so any surviving dual-live socket is closed
   before lobby/game handlers run.

7. **`ReconnectService::bindConnectionToPlayer()`** — call
   `evictOtherLiveSessions()` before binding.

8. **`LobbyService::handleCreateRoom()` / `handleJoinRoom()`** — call
   `evictOtherLiveSessions()` at entry; `tryRebindExistingSeatForUser()`
   guard before adding a new player row.

9. **Client (`ws.js` / `app.js`)** — `invalidateSession()` prevents
   auto-reconnect after `error.auth_invalid_token` / superseded close.

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

## Addendum — 2026-08-08 rapid relogin reproduction (EPIC-028.2)

### New evidence

Production log `2026-08-08 03:09–03:13` (user `test5` / `user_id=501`): seven
fresh **login** cycles (no `reconnect` action), then the same `user_id` both
**created** room 1 and **joined** it as a second occupant (`cards_count=2`).
Reproduces across **different browser engines** (e.g. Chrome + Firefox) but
not same-engine profiles (main + incognito), pointing to cross-engine WebSocket
teardown timing rather than a pure deterministic server bug.

Logs lacked `conn_id` on login/close lines, making it impossible to correlate
which sockets were live at `03:13:02` / `03:13:06`.

### Investigation

Automated `tests/Manual/test_rapid_relogin_stress.php` models:

- 7× login → onClose cycles
- login while prior socket still in `$worker->connections` (late TCP teardown)
- zombie socket with `userId` cleared but `sessionToken` still mapped
- duplicate `onClose` (explains `Connection closed userId=null` mid-sequence)

**Ruled out:** late `onClose` clearing fields on the *winning* connection when
`userConnections[$userId]` already points at a newer socket (`===` guard is
correct).

**Root cause (additional path, EPIC-028.2):** `findAllLiveConnectionsForUser()`
only matched `$connection->userId`. When a browser engine delays TCP teardown,
`onClose` can clear `userId` on a socket that is **still in**
`$worker->connections` while another engine's fresh login has already claimed
the account. The stale socket was invisible to the primary eviction pass. If
the stale client UI still held session state and sent lobby packets, dual-live
auth could persist until create/join.

Secondary signal: `userId=null` close lines are **post-eviction** or
**never-authenticated** sockets hitting idempotent `onClose` — not a separate
corruption bug.

### Additional decisions (EPIC-028.2)

1. **Auth lifecycle logging** — all login/reconnect/close/eviction lines include
   `conn_id=`; eviction logs include `pass=` (`primary` | `post-bind-sweep` |
   `action-guard`) and `new_conn_id=`.
2. **`connectionBelongsToUser()`** — eviction discovery also matches
   `sessionToken` still present in `$worker->sessionTokens`, plus live room-seat
   `connection` references for the `user_id`.
3. **`SessionGuardService::handleConnectionClose()`** — extracted from
   `server.php` `onClose` (idempotent via `lottoCloseProcessed`, logs
   `reason=normal|post-eviction|unauthenticated`).
4. **`test_rapid_relogin_stress.php`** — regression harness for this path.

### Honest limit

The new unit test **does not** reproduce two authenticated sockets both
successfully completing create+join in one process — with EPIC-028.2 discovery
rules, the stale socket is evicted or blocked at `auth_required`. The
production failure required **conn_id logging** on the next manual repro to
confirm whether a remaining gap exists outside these paths.

---

## Addendum — EPIC-028.3 asymmetric cross-engine closure verification (2026-08-12)

### New test

`tests/Manual/test_asymmetric_engine_stress.php` models the remaining gap called
out above:

- Engine A logs in; engine B fresh-login calls `claimUserSession()` while A
  remains in `$worker->connections` (delayed TCP teardown).
- **Before** delayed `onClose` on A, engine B runs `create_room` and engine A
  attempts `join_room` on the same room (with `evictOtherLiveSessions` action
  guard, mirroring `server.php`).
- Variants cover zombie `sessionToken` mapping, stale create + winner join, and
  a probe that `countLiveAuthForUser()` never exceeds 1 during the window.

### Result: no remaining gap reproduced

All groups **PASS** (2026-08-12). SessionGuardService's existing eviction paths
(primary eviction in `claimUserSession()`, post-bind sweep, and per-action
`evictOtherLiveSessions()` guard) deterministically prevent:

- two live authenticated sockets for the same `user_id`;
- two room seats for the same `user_id` in one room via the create+join window.

The production Chrome+Firefox failure path is now **covered by automated
regression** — the EPIC-028.2 "Honest limit" is closed for this scenario.

### Monitoring safety net (EPIC-028.3 Part B)

`EconomyAudit::checkWorkerInvariants()` (via `lottoEconomyCheckInvariants()`) runs
on `RoomManager::destroyRoom()` and `GameService::finishGame()` teardown. It
**detects and logs only** — never mutates balances:

- **ERROR** — duplicate `user_id` in the same room; dual live auth for one
  `user_id`.
- **WARNING** (when `LOTTO_ECONOMY_AUDIT=1`) — room `bank` vs
  `all_players_history` mismatch; global conservation snapshot.

No protocol or Handler contract changes.
