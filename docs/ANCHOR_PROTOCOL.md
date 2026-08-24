# ANCHOR_PROTOCOL.md

Never changes. Contains all incoming/outgoing packets and JSON formats.

## Purpose
Defines all WebSocket packets. If implementation contradicts this doc, the doc is correct — fix the code.

## General Rules
All packets are JSON. Every packet must contain `{"type": "packet_name"}`.

---

## Error Packet
Server → Client
```json
{"type": "error", "code": "error_code", "message": "optional text"}
```
Codes: `error.invalid_json, error.auth_required, error.room_not_found, error.not_your_turn, error.already_nudged, error.server_full, error.room_full, error.room_limit, error.banned, error.cannot_moderate_admin, error.auth_invalid_username, error.auth_username_taken, error.auth_invalid_credentials, error.auth_invalid_token, error.auth_rate_limited, error.auth_too_many_accounts_same_network, error.chat_unavailable, error.chat_message_invalid, error.file_transfer_busy, error.file_too_large, error.file_recipient_invalid, error.file_offer_invalid, error.file_invalid_payload, error.file_rate_limited, error.admin_wrong_current_password, error.admin_password_invalid, error.admin_user_not_found, error.admin_user_busy`

`error.invalid_json` (ADR-003): sent for malformed JSON or missing/invalid
`action` field. The connection is NOT closed — the client remains
connected and may send further packets. Abuse via repeated malformed
packets is bounded separately by rate limiting (ANCHOR_CORE.md Part 1 §
Connection Runtime Fields), not by closing on the first offense.

`error.server_full` vs `error.room_full` (ADR-004): `error.server_full`
is reserved exclusively for the global `MAX_TOTAL_PLAYERS` limit (whole
server at capacity). `error.room_full` is used when a specific, otherwise
valid room has reached its own `max_players`. The two conditions are
distinct and must never share a code — a client needs to tell "try a
different room" apart from "nothing to do right now". When joining a
room, the server checks server-wide capacity before per-room capacity,
so `error.server_full` always takes precedence if both are true.

`error.auth_required` (ADR-006): sent once, generically, by the router —
before action dispatch, not duplicated per-handler — for any `action`
other than `register`, `login`, `reconnect`, or `ping` when the
connection is not yet authenticated (`$connection->userId === null`).
Those four actions are the only ones a client can send before
authenticating; every other action requires an established session.

`error.auth_rate_limited` (ADR-028): sent when per-username login failure
throttling has locked the account after too many failed attempts in a rolling
window. The optional `message` field uses the same generic text as invalid
credentials (`Invalid username or password`) — the server MUST NOT expose
remaining lockout time, attempt count, or whether the username exists.

`error.auth_too_many_accounts_same_network` (ADR-031): sent when a **new**
login (or register auto-login) would exceed `MAX_ACCOUNTS_PER_IP` distinct
live authenticated `user_id`s already connected from the same remote IP
(`$worker->userConnections` live count). **Not** sent on `reconnect`. Unlike
ADR-028, the `message` must be **honest and specific** (e.g.
`Too many accounts are already signed in from this network.`) — do not disguise
as invalid credentials. The connection is NOT closed.

`error.banned` (ADR-007): reserved in the registry but **not emitted**.
Ban rejections use the dedicated `banned` packet type instead
(`{"type":"banned","until":...}`). Kept for forward compatibility;
do not remove without ADR approval.

`error.already_nudged` (ADR-032): sent when a seated player sends
`nudge_turn` a second time in the same turn. Distinct from
`error.not_your_turn` so the client can silently disable the nudge
control without a generic "not your turn" toast. Self-nudge, non-playing
room, and inactive/non-seated sender use existing codes (`error.not_your_turn`
/ `error.room_not_found`) — see ADR-032.

`error.file_rate_limited` (ADR-030): dedicated soft limit on
`file_offer`/`file_data` (`FILE_RATE_LIMIT_MAX` per
`FILE_RATE_LIMIT_WINDOW_SECONDS`). Connection is NOT closed (unlike
ADR-003's hard close).

`error.admin_wrong_current_password` (ADR-033): `admin_change_password` when
`current_password` does not match the acting admin's stored hash.

`error.admin_password_invalid` (ADR-033): new password fails
`PasswordPolicy::validateAdminPassword()` (or missing fields / same as
current). The `message` field carries a specific reason.

`error.admin_user_not_found` (ADR-033): delete target `user_id` does not
exist in SQLite.

`error.admin_user_busy` (ADR-033): delete target is live online, seated in
a room, or still referenced in room RAM (`players` /
`all_players_history` / `game_roster`) — kick/leave/destroy first.

---

## WebSocket Close Codes

Beyond the standard RFC 6455 close codes, this project defines
application-specific codes in the 4000-4999 private-use range
(RFC 6455 §7.4.2):

| Code | Meaning | Companion packet |
|------|---------|-------------------|
| 4001 | Global connection limit (`MAX_TOTAL_PLAYERS`) reached at connection time (ADR-005) | `error.server_full` (sent first, then the connection closes with this code) |

A close code is delivered at the WebSocket protocol layer (visible via
the client's `onclose` event, e.g. browser `WebSocket.onclose.code`) and
is not a substitute for the JSON `error` packet — both are sent, in that
order, so clients that only inspect one or the other still get the
information. Do not reuse 4001 for any other condition; new
application-specific close codes require an ADR and a new entry in this
table.

---

## Connection Phase

### hello
Server → Client, immediately after connection.
```json
{"type": "hello", "protocol_version": 1}
```

---

## Authentication

### register
Client → Server
```json
{"action": "register", "username": "player", "password": "secret"}
```
Username `Bot` is reserved (ADR-034, case-insensitive match on the literal
`bot`) and must be rejected with `error.auth_invalid_username` so it cannot
collide with the bot opponent wire display name.

### login
Client → Server
```json
{"action": "login", "username": "player", "password": "secret"}
```

### reconnect
Client → Server
```json
{"action": "reconnect", "token": "session_token"}
```

### auth_result
Server → Client
```json
{"type": "auth_result", "success": true, "user_id": 15, "username": "player", "coins": 500, "is_admin": false, "session_token": "..."}
```

### banned
Server → Client
```json
{"type": "banned", "until": 4102444800}
```

---

## Heartbeat

### ping
Client → Server. No response required.
```json
{"action": "ping"}
```

---

## Lobby

### room_list
Client → Server
```json
{"action": "room_list"}
```
Server → Client
```json
{"type": "room_list", "rooms": []}
```
Room entry:
```json
{"room_id": 7, "players": 3, "max_players": 10, "has_password": false, "status": "waiting", "speed_mode": "slow"}
```
`speed_mode` (ADR-035): `"slow"` \| `"fast"` so lobby clients can filter/pick
by animation profile.
### create_room
Client → Server
```json
{"action": "create_room", "max_players": 10, "password": "", "cards_count": 1, "speed_mode": "slow"}
```
`cards_count`: `1 or 2`
`speed_mode` (ADR-035, optional): `"slow"` \| `"fast"`. Omitted ⇒ `"slow"`.
Frozen for the room lifetime. Invalid value → `error.invalid_json`.

### join_room
Client → Server
```json
{"action": "join_room", "room_id": 7, "password": "", "cards_count": 2}
```

### leave_room
Client → Server
```json
{"action": "leave_room"}
```

### room_joined
Server → Client. `players[]` includes each seated player's `cards_count` (public); card numbers do not exist until `start_game`.
```json
{"type": "room_joined", "room_id": 7, "host": "player1", "status": "waiting", "bank": 0, "bet_per_card": 10, "has_password": true, "speed_mode": "slow", "players": [], "host_timeout_start": 1704067150, "host_timeout_seconds": 120}
```
`has_password` (ADR-030): `true` when `password_hash !== null`; drives client chat panel visibility.
`speed_mode` (ADR-035): `"slow"` \| `"fast"` — client slot-animation profile.
`host_timeout_start` / `host_timeout_seconds`: present when a lobby host is assigned (≥2 players); countdown until host AFK transfer.
Player entry:
```json
{"username": "player", "cards_count": 2, "status": "active"}
```

### player_joined
Server → Room
```json
{"type": "player_joined", "username": "player", "cards_count": 1, "host": "player1", "host_timeout_start": 1704067150, "host_timeout_seconds": 120}
```
When ≥2 players are seated, includes current lobby host AFK deadline (`host.last_action` + `LOBBY_HOST_TIMEOUT`) so all clients stay in sync.
Does **not** include `speed_mode` (ADR-035) — room-wide mode is delivered via
`room_joined` / `room_list` / `reconnect_state`.

### player_left
Server → Room
```json
{"type": "player_left", "username": "player", "reason": "leave", "user_id": 15}
```
`user_id` (optional additive field, FIX-30): identifies the removed seat so clients
can distinguish self-removal from another connection sharing the same username.

### player_status_changed
Server → Room (playing only). Live roster status update when a player's
connection state changes without full removal — disconnect (Away) or reconnect
(back Online). Full removal still uses `player_left`; the client converts that
to a ghost entry (`status: "removed"`, ADR-021).
```json
{"type": "player_status_changed", "username": "player", "status": "disconnected"}
```
`status`: `"disconnected"` when a playing player loses connection (before the
reconnect-timeout removal); `"active"` when they reconnect. Sent only to
`status === "active"` players via `broadcastToRoom()`.

### host_changed
Server → Room
```json
{"type": "host_changed", "host": "player1", "host_timeout_start": 1704067150, "host_timeout_seconds": 120}
```
`host_timeout_start` / `host_timeout_seconds`: included when `host` is non-empty (lobby host AFK window).
Sent to every active player in the room whenever `host_conn_id` changes
(e.g. lobby-AFK host timeout via `transferHost()`). See ADR-009.

### bank_updated
Server → Room. Sent immediately after the Apartment phase resolves and
gameplay resumes (not sent on `last_survivor` / `no_survivors` — the bank is
already final in the following `game_over` packet in those cases).
```json
{"type": "bank_updated", "bank": 85}
```

### balance_updated
Server → single Client (NOT a room broadcast — see `bank_updated` for the
shared room total). Sent whenever the server changes a specific player's
`users.coins` outside of `game_over` / `reconnect_state` (kick refund,
`admin_close_room` refund, Apartment payment) and that player has a live
connection to notify.
```json
{"type": "balance_updated", "coins": 615}
```

---

## Game Start

### start_game
Client → Server. Host only. Requires ≥2 seated humans. Does not create a bot.
```json
{"action": "start_game"}
```

### play_vs_bot
Client → Server. Host only (ADR-034). Allowed only in `waiting` with exactly
one seated human. Creates `$room['bot']`, starts the game (human stake only;
bot `cards_count = 2`, `total_paid = 0`), and transitions `waiting → playing`.
While the bot is present, `join_room` is rejected with `error.room_full`.
```json
{"action": "play_vs_bot"}
```
Rejects: not in a room → `error.room_not_found`; not host / not `waiting` /
not exactly one seated human → `error.not_your_turn` (same host/phase bucket
as `start_game`).

### game_started
Server → Room (per-player payload).

**Card visibility (all phases):**
- `cards_count` is visible to every client for every player in the roster (lobby and in-game).
- Card **numbers** (`cards` array) are visible only to the owning player (`is_self: true`).
- Foreign entries use `cards: null`; opponents receive only `masks` (mark state, no numbers).

```json
{"type": "game_started", "bank": 40, "drawer_order": ["host", "player2", "player3"], "players": []}
```
Player entry, self:
```json
{"username": "player", "is_self": true, "cards_count": 2, "cards": [], "masks": []}
```
Player entry, others:
```json
{"username": "player2", "is_self": false, "cards_count": 1, "cards": null, "masks": []}
```
`masks` length equals `cards_count` for every entry; foreign `masks` start all-`false` and do not reveal card numbers.

In bot matches (ADR-034), the roster includes an entry with `username: "Bot"`,
`cards_count: 2`, and the same card-visibility rules (human never receives bot
card numbers). There is no `is_bot` field — the reserved username identifies
the bot.

---

## Turn System

### your_turn
Server → Client
```json
{"type": "your_turn", "afk_start": 1704067150, "turn_seconds": 30, "auto_draws": 0}
```
`afk_start`: unix timestamp when the drawer turn (and AFK countdown) began; omitted until client sends `turn_ready` after slot animation.
`turn_seconds`: seconds allowed for **this strike's** inactivity window before an AFK strike (30 / 15 / 5 per ADR-012, keyed to current `auto_draws`).
`auto_draws`: prior auto-draw count for this player (0–2); third strike removes the player.

### draw_barrel
Client → Server
```json
{"action": "draw_barrel"}
```

### turn_ready
Client → Server. Current drawer signals slot animation finished; server sets `afk_start` and re-sends `your_turn` with AFK fields.
```json
{"action": "turn_ready"}
```

### nudge_turn
Client → Server (ADR-032). A non-drawer seated in the `playing` room sends a
one-per-turn "hurry up" signal to the current drawer. No payload — server
uses the sender's `connId` and `room['active_drawer_conn_id']`. Must not
alter AFK timer fields.
```json
{"action": "nudge_turn"}
```

### nudge_received
Server → current drawer only (never broadcast). Private notification that
another player nudged them.
```json
{"type": "nudge_received", "from": "username"}
```
`from`: nudging player's username.

### barrels_drawn
Server → Room
```json
{"type": "barrels_drawn", "numbers": [15, 44, 81], "remaining": 57, "next_drawer": "player2", "is_final": false, "bank": 80, "win_chances": {"player1": 42, "player2": 58}}
```
`numbers`: 1-3 values.
`bank` (ADR-018): current room bank; included on every draw so the info bar
stays accurate without waiting for reconnect.
`win_chances` (optional, ADR-014): comparative exponential win-chance percent per
`username` (float, one decimal; sum 100%; informational only; omitted on victory-ending draw).

### afk_warning
Server → Client. Sent to the current drawer when the per-turn timeout is reached.
```json
{"type": "afk_warning", "strike": 1, "afk_start": 1704067150, "turn_seconds": 30, "auto_draws": 0}
```
`strike`: `1` or `2` (warning before auto-draw). Strike `3` triggers immediate removal without a warning packet.
`turn_seconds`: current strike's inactivity window (30 for strike 1, 15 for strike 2).
`auto_draws`: count of prior auto-draws before this strike.

---

## Apartment

### apartment_alert
Server → Room
```json
{"type": "apartment_alert", "required": true, "time_left": 10}
```
`required`: `true = must answer`, `false = immune`

### apartment_choice
Client → Server. May be sent multiple times per player until the apartment timer expires; the **last** choice before expiry is final.

`agree` — record intent to pay 5 coins when the phase ends (payment not taken until timer expiry).

`refuse` — record intent to leave when the phase ends (removal not applied until timer expiry).

```json
{"action": "apartment_choice", "choice": "agree"}
```
or
```json
{"action": "apartment_choice", "choice": "refuse"}
```

The apartment phase always runs for the full `time_left` window. Game resumes only after the apartment timer fires (or the room is destroyed).

---

## Game End

### game_over
Server → Room
```json
{"type": "game_over", "winner": "player", "reason": "victory", "prize": 120, "final_bank": 120, "statistics": [], "win_chance_history": [{"turn_number": 1, "chances": {"player": 50}}]}
```
`win_chance_history` (ADR-019): complete server-recorded sequence of `win_chances`
snapshots for the whole game (one entry per `barrels_drawn` broadcast that included
`win_chances`), identical for every recipient — replaces prior client-local
accumulation.
Statistics entry:
```json
{"username": "player", "paid": 20, "received": 120, "coins": 610}
```
`coins` (ADR-016 §1): the player's actual post-transaction `users.coins` balance,
read from the database after the payout/refund commits. Always present when the
player's `user_id` was resolvable (the normal case).

### last_survivor
Server → Room (same `game_over` packet, `reason` differs)
```json
{"type": "game_over", "winner": "player", "reason": "last_survivor", "prize": 80, "final_bank": 80, "statistics": [], "win_chance_history": [{"turn_number": 1, "chances": {"player": 50}}]}
```

### no_survivors
Server → Room. Zero active players remain — stakes refunded, no winner, `prize` and `final_bank` are 0.
```json
{"type": "game_over", "winner": "", "reason": "no_survivors", "prize": 0, "final_bank": 0, "statistics": [{"username": "p1", "paid": 10, "received": 10}], "win_chance_history": []}
```
`received` equals `paid` (stake return, not a prize).

### bot_win
Server → Room (ADR-034). The bot closed all 15 numbers on one of its cards.
The room bank is **burned** (not paid to the bot, not refunded to the human).
`prize` and `final_bank` are 0. Winner display name is the reserved
username `"Bot"`. No `is_bot` field.
```json
{"type": "game_over", "winner": "Bot", "reason": "bot_win", "prize": 0, "final_bank": 0, "statistics": [{"username": "player", "paid": 20, "received": 0, "coins": 480}], "win_chance_history": []}
```

---

## Reconnect

### reconnect_state
Server → Client. Reconnect is forbidden during apartment state.

Waiting room:
```json
{"type": "reconnect_state", "status": "waiting", "room_id": 5, "bank": 0, "bet_per_card": 10, "speed_mode": "slow", "coins": 490, "drawn_all": [], "my_cards": null}
```
Playing (`my_cards` / `my_masks` — own cards only; `players[].cards_count` visible for all):
```json
{"type": "reconnect_state", "status": "playing", "room_id": 5, "bank": 80, "speed_mode": "fast", "coins": 490, "drawn_all": [], "my_cards": [], "players": [{"username": "player1", "cards_count": 1, "status": "active"}, {"username": "player2", "cards_count": 2, "status": "removed", "reason": "disconnect"}], "win_chances": {"player1": 50, "player2": 50}, "is_my_turn": true, "afk_start": 1704067200, "turn_seconds": 30, "auto_draws": 0}
```
`speed_mode` (ADR-035): present on waiting and playing reconnect payloads so
the client restores the correct slot-animation profile after reload.
`coins` (ADR-016 §2): the reconnecting player's current `users.coins` balance,
read fresh from the database — resyncs any change that happened while disconnected
(daily bonus, admin adjustment, etc.) that the client would otherwise not see until
an unrelated packet happened to refresh it. Present when the player's `user_id`
was resolvable and DB access was available; absent otherwise.
`players` (playing, ADR-020): roster of every player who has been part of this
game — currently active/disconnected players (`status: "active"|"disconnected"`)
plus a ghost entry per fully-removed player (`status: "removed"`, `reason`: the
original removal reason, or `null` for bulk end-of-game snapshots) — kept until
the room is destroyed, so a reconnecting client always sees who played, not just
who remains.
`win_chances` (optional, ADR-014): same semantics as `barrels_drawn`; included
for `status === "playing"` so reconnecting clients restore opponent indicators.
`is_my_turn` / `afk_start` / `turn_seconds` / `auto_draws` (ADR-017): included
when the reconnecting player is the current `active_drawer_conn_id` — same
semantics as the `your_turn` packet's non-deferred variant (`afk_start` always
present, no `turn_ready` round-trip expected). Otherwise only `is_my_turn: false`
is sent (no AFK fields).

---

## Administration

### admin_ban_user
Client → Server
```json
{"action": "admin_ban_user", "user_id": 15, "duration": "1d"}
```
Allowed `duration`: `1d, 3d, permanent`

### admin_unban_user
Client → Server
```json
{"action": "admin_unban_user", "user_id": 15}
```

### admin_kick_user
Client → Server
```json
{"action": "admin_kick_user", "user_id": 15}
```

### admin_close_room
Client → Server
```json
{"action": "admin_close_room", "room_id": 7}
```

### admin_get_logs
Client → Server
```json
{"action": "admin_get_logs"}
```

### admin_get_stats
Client → Server
```json
{"action": "admin_get_stats"}
```

### admin_get_users
Client → Server
```json
{"action": "admin_get_users", "search": "alice", "online_only": false, "banned_only": false, "limit": 200}
```

### admin_get_settings
Client → Server
```json
{"action": "admin_get_settings"}
```

### admin_set_settings
Client → Server. All fields optional; omitted fields are unchanged.
```json
{"action": "admin_set_settings", "max_accounts_per_ip": 3, "bet_per_card": 10, "apartment_payment": 5}
```

### admin_restart_server
Client → Server. On success the host runs `admin_emergency_control.sh restart` (disconnects all clients).
```json
{"action": "admin_restart_server"}
```

### admin_stats_data
Server → Client. Emitted only in response to `admin_get_stats`.
```json
{"type": "admin_stats_data", "online": 0, "memory_mb": 0, "rooms": []}
```

### admin_users_data
Server → Client. Emitted only in response to `admin_get_users`.
```json
{"type": "admin_users_data", "users": [{"id": 1, "username": "alice", "coins": 500, "is_admin": false, "banned_until": 0, "online": true, "room_id": 7, "banned": false}]}
```

### admin_logs_data
Server → Client
```json
{"type": "admin_logs_data", "lines": []}
```
Lines are filtered to the last 24 hours (parsed from log timestamps).

### admin_settings_data
Server → Client. Response to `admin_get_settings` and successful `admin_set_settings`.
```json
{"type": "admin_settings_data", "online": 0, "memory_mb": 0, "max_accounts_per_ip": 3, "bet_per_card": 10, "apartment_payment": 5}
```

### admin_restart_result
Server → Client. Response to `admin_restart_server`.
```json
{"type": "admin_restart_result", "success": true, "message": "Server restart initiated"}
```

---

## Room Chat & File Transfer (ADR-030)

Available only inside password-protected rooms (`password_hash !== null`).
Never persisted to SQLite or disk. No history on reconnect/rejoin.

### room_message (action)
Client → Server
```json
{"action": "room_message", "text": "hello"}
```

### room_message (packet)
Server → Room (active players only)
```json
{"type": "room_message", "from": "alice", "text": "hello", "ts": 1704067200}
```

### file_offer (action)
Client → Server. Metadata only — no file bytes.
```json
{"action": "file_offer", "to_username": "bob", "filename": "notes.txt", "size_bytes": 1234}
```

### file_offer (packet)
Server → Recipient only
```json
{"type": "file_offer", "offer_id": "a1b2c3d4e5f60718", "from": "alice", "filename": "notes.txt", "size_bytes": 1234}
```

### file_accept
Client → Server
```json
{"action": "file_accept", "offer_id": "a1b2c3d4e5f60718"}
```

### file_accepted
Server → Sender only (prompt to send bytes)
```json
{"type": "file_accepted", "offer_id": "a1b2c3d4e5f60718"}
```

### file_reject
Client → Server
```json
{"action": "file_reject", "offer_id": "a1b2c3d4e5f60718"}
```

### file_rejected
Server → Sender only. Distinct from generic `error` — recipient explicitly declined.
```json
{"type": "file_rejected", "offer_id": "a1b2c3d4e5f60718", "reason": "declined"}
```

### file_data (action)
Client → Server. Sent only after `file_accepted`. `data` is base64 of raw bytes.
```json
{"action": "file_data", "offer_id": "a1b2c3d4e5f60718", "data": "<base64>"}
```

### file_data (packet)
Server → Recipient only
```json
{"type": "file_data", "offer_id": "a1b2c3d4e5f60718", "from": "alice", "filename": "notes.txt", "data": "<base64>"}
```

### file_offer_expired
Server → Affected party/parties. Offer timed out, relay timed out, or peer disconnected/left mid-transfer.
```json
{"type": "file_offer_expired", "offer_id": "a1b2c3d4e5f60718"}
```

---

## Protocol Compatibility Rule
New packets may be added. Existing packet names, field names, and semantics may not be changed/renamed. Breaking changes require ADR approval.

## Admin password & account deletion (ADR-033)

### admin_change_password
Client → Server. Rotates the acting admin's own password.
```json
{"action": "admin_change_password", "current_password": "old-secret-1", "new_password": "new-secret-12"}
```

### admin_change_password_result
Server → Client. Emitted only on successful commit.
```json
{"type": "admin_change_password_result", "success": true, "message": "Password updated"}
```
Validation failures use `error.admin_wrong_current_password` /
`error.admin_password_invalid` instead of this packet.

### admin_delete_user
Client → Server. Hard-delete one non-admin account that is not busy.
```json
{"action": "admin_delete_user", "user_id": 15}
```
On success the client should re-request `admin_get_users`.

### admin_bulk_delete_users
Client → Server. All-or-nothing hard-delete of the listed ids.
```json
{"action": "admin_bulk_delete_users", "user_ids": [15, 16, 17]}
```
On success the client should re-request `admin_get_users`.