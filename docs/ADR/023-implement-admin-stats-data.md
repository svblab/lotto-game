# 023 — Implement admin_stats_data

## Status

Accepted

## Context

`admin_stats_data` has been declared in the protocol registry since ADR-007 /
EPIC-11.5. It was tracked as a KNOWN GAP in `IMPLEMENTATION_STATUS.md` with
zero emission sites. On 2026-08-05 the user decided to implement the feature
rather than deprecate it.

The companion client action `admin_get_stats` was never added to the allowed
actions list in `ANCHOR_CORE.md` while the feature remained unimplemented.

## Decision

1. **Add `admin_get_stats` action** — wired through `AdminHandler` to
   `AdminService::handleGetStats()`, guarded by the existing `assertAdmin()`.
2. **Emit `admin_stats_data` only in response to `admin_get_stats`** — no
   unsolicited pushes.
3. **`rooms` field** — reuse `RoomManager::buildRoomListEntry()` for each open
   room (same shape as `room_list` entries). The "Active Rooms" admin UI
   already works via `room_list` independently; this packet's `rooms` field is
   for protocol-shape completeness and future admin tooling, not the current
   UI's primary data source.
4. **`online` / `memory_mb`** — sourced the same way as `LoadAudit` /
   `MemoryAudit`: `user_connections` count from `$worker->userConnections`, and
   `memory_get_usage(true)` converted to integer megabytes.

4. **`online` / `memory_mb`** — sourced the same way as `LoadAudit` /
   `MemoryAudit`: `user_connections` count from `$worker->userConnections`, and
   `memory_get_usage(true)` converted to integer megabytes.

Client-side wiring was originally deferred to follow-up Epic 023.1; as of the
2026-08-30 repository audit it is **already complete** (`app.js`:
`refreshAdminData()` → `admin_get_stats`; `onAdminStats` → `UI().setAdminStats` /
`renderAdminRooms`; EPIC-033B admin rooms UI). Epic 023.1 is **obsolete**.

## Consequences

Positive:

- Closes the long-standing `admin_stats_data` KNOWN GAP without protocol rename
  or version bump.
- Reuses existing `buildRoomListEntry()` — no duplication of `room_list`
  logic.
- Metrics align with existing audit instrumentation.
- Client wiring complete (server + client + `test_admin_stats.php`).

Negative / limitations:

- `memory_mb` is an integer snapshot (not the two-decimal string used in audit
  logs).
- Some status documents (PHASE_11_REPORT W2, former KNOWN GAPS) may remain stale
  until TD-1 documentation reconciliation.

Compatibility: this Epic only implements an already-declared action/packet pair —
no rename, no protocol version bump.
