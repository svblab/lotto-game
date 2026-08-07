# 025 — Extract SessionGuardService from AuthHandler

## Status

Accepted

This ADR is written and implemented in the same Epic (EPIC-027.0), following
the pattern used for ADR-015 / GameTurnService (EPIC-024.0) and ADR-024 /
LobbyHostService (EPIC-026.0): analysis and extraction land together rather
than as a separate approval gate.

## Context

`src/Auth/AuthHandler.php` is **462 lines** — far above this codebase's own
**Handler = thin routing layer** convention (`GameHandler.php` 81 lines,
`LobbyHandler.php` 46, `AdminHandler.php` 86). It also exceeds the
**300-line handler limit** in `ANCHOR_CORE.md` § File Size Policy.

FIX-30 ("Fix concurrent multi-session auth") added a substantial, self-
contained concurrent-session-eviction feature directly into the handler
instead of a dedicated service. The cluster has a clean natural seam:

- `claimUserSession()` is the **only** public entry point called from
  outside (3 call sites in `handleRegister()`, `handleLogin()`,
  `handleReconnect()`).
- `evictConnection()`, `removeConnectionFromRoom()`,
  `findAllLiveConnectionsForUser()`, `isConnectionLive()`,
  `revokeTokensForUser()` are called only from within that cluster.
- `removeConnectionFromRoom()` accesses `$worker->roomManager` /
  `$worker->lobbyService` / `$worker->reconnectService` via the `$worker`
  parameter — no extra constructor dependencies beyond `Logger`.

Unlike `ApartmentService.php` (694 lines) and `AdminService.php` (584 lines),
which were evaluated and found to be single, cohesive responsibilities
(apartment-phase state machine; admin moderation actions) without a clean
non-artificial seam, this extraction has an obvious boundary and fixes a
genuine architectural inconsistency.

## Decision

Extract the concurrent-session-eviction cluster into
`src/Auth/SessionGuardService.php`, injected into `AuthHandler` as a 4th
constructor dependency. The 3 external call sites are rewired directly to
`$this->sessionGuard->claimUserSession(...)` — **no delegate-facade layer**
(unlike ADR-015 / ADR-024), since there is exactly one public entry point
and zero risk of circular dependency.

`server.php` wires `SessionGuardService` immediately before `AuthHandler`.

## Consequences

**Positive:**

- `AuthHandler.php` drops from ~462 lines to ~285–290 — back under the
  300-line handler limit in `ANCHOR_CORE.md` § File Size Policy.
- Concurrent-session eviction is isolated for maintenance without touching
  register/login/reconnect routing paths.
- Simpler than EPIC-024.0/026.0: no circular-dependency concern, no
  duplicated helper methods.

**Negative / honest limits:**

- `bindConnection()` remains in `AuthHandler` per extraction scope (only
  reachable from the moved cluster; a private copy lives in
  `SessionGuardService` so `claimUserSession()` body stays verbatim).
- `ApartmentService.php` and `AdminService.php` remain large by design —
  force-splitting them for line count alone is not recommended.

**Compatibility:** Internal refactor only — no protocol change, no rename of
any public-facing handler method signature.
