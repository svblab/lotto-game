# 033 — Admin panel: password rotation and account deletion

## Status

accepted

## Context

The admin UI needs three related but separable improvements:

1. **In-app admin password rotation** — replace reliance on the operational
   CLI script `change_admin_password.php` (documented historically; may be
   absent from a given checkout) with a WebSocket action usable by any
   authenticated admin account, not only username `admin`.
2. **Scalable rooms list presentation** — Epic B (UI-only): existing
   `admin_stats_data.rooms` / `room_list` entries already carry
   `room_id`, `players`, `max_players`, `has_password`, `status`
   (ADR-023 / `RoomManager::buildRoomListEntry()`). No protocol change.
3. **Scalable players list + hard account delete + bulk junk cleanup** —
   load/stress tests historically registered against the live `game.db`
   (not always `LOTTO_DB_PATH`), leaving junk usernames (`steady*`,
   `ramp_*`, `login_banned`, …). Operators need per-player Ban/Kick/Unban
   in a compact UI and a guarded delete path.

ANCHOR constraints:

- Rule 7: new actions/packets/error codes require this ADR.
- Rule 11: Epics A / B / C stay separate commits.
- Database Ownership: SQLite owns `users`; RAM owns rooms/players —
  deleting a `users` row must not leave the server reading that row for a
  still-seated or live connection.
- Rule 19 spirit: bulk delete is all-or-nothing inside one PDO transaction.

## Decision

### Epic B (no protocol) — confirmed UI-only

Replace the flat admin rooms table with a `<select>` whose options use the
existing room-list fields. Selecting a room reveals detail +
`admin_close_room`. **No new fields, actions, or packets.**

### Epic A — `admin_change_password`

**Action:**
```json
{"action":"admin_change_password","current_password":"...","new_password":"..."}
```

**Packet:**
```json
{"type":"admin_change_password_result","success":true,"message":"Password updated"}
```
On soft validation failure the server may either send this packet with
`success:false` **or** a structured `error` packet — see error codes below.
Prefer **`error.*` for rejectable conditions** (consistent with the rest of
the admin API) and **`admin_change_password_result` with `success:true`**
only on commit success. If current/new password fails validation, send the
matching `error.*` code (connection stays open).

**Server (`AdminService::handleChangePassword`):**

1. `assertAdmin()`.
2. Load the **acting** admin row by `$connection->userId` (never hardcode
   username `admin`).
3. `password_verify(current_password, row.password_hash)` — on failure:
   `error.admin_wrong_current_password`.
4. Validate `new_password` via shared `Lotto\Auth\PasswordPolicy::validateAdminPassword()`:
   - min length **10** characters (UTF-8 codepoints via `strlen` of valid UTF-8)
   - at least one Unicode letter (`\p{L}`) and one digit (`\p{N}` / ASCII digit)
   - no control characters (ASCII 0x00–0x1F, 0x7F)
   - byte length ≤ **72** (bcrypt input limit)
   - must be valid UTF-8
   - must differ from current password
   - On failure: `error.admin_password_invalid` with a specific `message`.
5. PDO transaction: `BEGIN` → `UPDATE users SET password_hash=? WHERE id=?`
   → re-`SELECT password_hash` → `password_verify(new, written_hash)` →
   `COMMIT`. Any failure → `ROLLBACK` and `error.invalid_json` (or the
   specific validation error if before UPDATE).
6. Log success in English via existing logger.

**Shared helper:** new class `Lotto\Auth\PasswordPolicy` (small, no I/O).
Registration password rules (**6–64 chars**) stay unchanged — out of scope.

**CLI script:** do **not** delete or modify `change_admin_password.php` if
present; leave it as emergency CLI fallback. If absent from the tree, do
not recreate it in this Epic.

### Epic C — delete + bulk delete

**Single delete action:**
```json
{"action":"admin_delete_user","user_id":15}
```

**Bulk delete action:**
```json
{"action":"admin_bulk_delete_users","user_ids":[15,16,17]}
```

No dedicated success packet — on success the client re-issues
`admin_get_users` (refresh `admin_users_data`). Failures use `error.*`.

**Guards (every target row, including each bulk member):**

1. `assertAdmin()` on the caller.
2. Reject `is_admin` targets → `error.cannot_moderate_admin`.
3. Reject if `user_id` is present in `$worker->userConnections` (live
   session) **or** seated in any room `$room['players']` →
   `error.admin_user_busy` with message requiring kick/leave first.
   **Do not auto-kick** — operator must clear presence explicitly so
   economy/removal paths stay intentional.
4. Reject unknown `user_id` → `error.room_not_found` is wrong semantically;
   use `error.admin_user_not_found`.

**Deletion semantics:** hard `DELETE FROM users WHERE id=?` inside a PDO
transaction. Bulk: validate **all** IDs first (collect failures); if any
guard fails, **abort entire batch** with no deletes (all-or-nothing). Then
one transaction deleting all IDs.

**RAM / history after delete:**

- Blocked while seated/online → no mid-game `users.coins` read for that id.
- `all_players_history` / `game_roster` may retain `username`/`user_id` from
  prior seats; those are RAM snapshots and must **not** re-query SQLite for
  display. Existing finish/refund paths that look up coins by `user_id` only
  run for users still in history **at payout time** while the row existed;
  after delete, a later admin close that refunds via `user_id` would fail —
  therefore **block delete while any room still lists that `user_id` in
  `players` OR `all_players_history` OR `game_roster`** (busy in the broad
  sense of “referenced by live room RAM”). If only historical finished
  rooms already destroyed exist, SQLite row may be removed safely.
- Display fallback if a stale UI cache shows a deleted id: client refresh
  removes them; server never invents `"deleted_user"` strings in this Epic.

**Bulk UI:** search/`admin_get_users` filters produce a candidate list;
“Delete matching” shows a **confirmation listing exact usernames** then
sends `admin_bulk_delete_users` with those `user_ids` only — never a raw
SQL LIKE from the server without that preview round-trip.

**Root cause (KNOWN GAP, not fixed here):** some load/stress scripts
register via the real registration path against the environment `game.db`
instead of isolating via `LOTTO_DB_PATH` / `LOTTO_TEST_CONFIG`. Flag in
`IMPLEMENTATION_STATUS.md` KNOWN GAPS; separate follow-up.

### Error codes (new)

| Code | Meaning |
|------|---------|
| `error.admin_wrong_current_password` | Current password mismatch |
| `error.admin_password_invalid` | New password fails `PasswordPolicy` |
| `error.admin_user_not_found` | Delete target id missing |
| `error.admin_user_busy` | Target online, seated, or still referenced in room RAM |

### Registry updates

Add actions `admin_change_password`, `admin_delete_user`,
`admin_bulk_delete_users`; packet `admin_change_password_result`; class
`PasswordPolicy`; error codes above — to `ANCHOR_CORE.md` Part 6 and
`ANCHOR_PROTOCOL.md` in the same pass as code.

## Consequences

Positive:

- Any admin can rotate their own password from the UI with write-verify
  transaction discipline.
- Operators can purge junk accounts without SSH/SQL.
- Rooms/players admin UI scales without protocol growth (Epic B).

Negative / follow-ups:

- CLI `change_admin_password.php` remains a separate emergency path (if
  present); two password UIs until operators standardize on WS.
- Busy-check including `all_players_history` may block delete until the
  room is destroyed — intentional safety.
- Load-test DB isolation remains a KNOWN GAP.
