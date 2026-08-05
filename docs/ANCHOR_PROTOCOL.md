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
Codes: `error.invalid_json, error.auth_required, error.room_not_found, error.not_your_turn, error.server_full, error.room_full, error.room_limit, error.banned, error.cannot_moderate_admin, error.auth_invalid_username, error.auth_username_taken, error.auth_invalid_credentials, error.auth_invalid_token`

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

`error.banned` (ADR-007): reserved in the registry but **not emitted**.
Ban rejections use the dedicated `banned` packet type instead
(`{"type":"banned","until":...}`). Kept for forward compatibility;
do not remove without ADR approval.

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
{"room_id": 7, "players": 3, "max_players": 10, "has_password": false, "status": "waiting"}
```

### create_room
Client → Server
```json
{"action": "create_room", "max_players": 10, "password": "", "cards_count": 1}
```
`cards_count`: `1 or 2`

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
Server → Client
```json
{"type": "room_joined", "room_id": 7, "host": "player1", "status": "waiting", "bank": 0, "players": [], "host_timeout_start": 1704067150, "host_timeout_seconds": 120}
```
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

### player_left
Server → Room
```json
{"type": "player_left", "username": "player", "reason": "leave"}
```

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

---

## Game Start

### start_game
Client → Server. Host only.
```json
{"action": "start_game"}
```

### game_started
Server → Room. Own cards visible only to owner; foreign cards never contain numbers.
```json
{"type": "game_started", "bank": 40, "drawer_order": ["host", "player2", "player3"], "players": []}
```
Player entry, self:
```json
{"username": "player", "is_self": true, "cards": [], "masks": []}
```
Player entry, others:
```json
{"username": "player2", "is_self": false, "cards": null, "masks": []}
```

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
Client → Server
```json
{"action": "apartment_choice", "choice": "agree"}
```
or
```json
{"action": "apartment_choice", "choice": "refuse"}
```

---

## Game End

### game_over
Server → Room
```json
{"type": "game_over", "winner": "player", "reason": "victory", "prize": 120, "final_bank": 120, "statistics": []}
```
Statistics entry:
```json
{"username": "player", "paid": 20, "received": 120}
```

### last_survivor
Server → Room (same `game_over` packet, `reason` differs)
```json
{"type": "game_over", "winner": "player", "reason": "last_survivor", "prize": 80, "final_bank": 80, "statistics": []}
```

### no_survivors
Server → Room. Zero active players remain — stakes refunded, no winner, `prize` and `final_bank` are 0.
```json
{"type": "game_over", "winner": "", "reason": "no_survivors", "prize": 0, "final_bank": 0, "statistics": [{"username": "p1", "paid": 10, "received": 10}]}
```
`received` equals `paid` (stake return, not a prize).

---

## Reconnect

### reconnect_state
Server → Client. Reconnect is forbidden during apartment state.

Waiting room:
```json
{"type": "reconnect_state", "status": "waiting", "room_id": 5, "bank": 0, "drawn_all": [], "my_cards": null}
```
Playing:
```json
{"type": "reconnect_state", "status": "playing", "room_id": 5, "bank": 80, "drawn_all": [], "my_cards": [], "win_chances": {"player1": 50, "player2": 50}, "is_my_turn": true, "afk_start": 1704067200, "turn_seconds": 30, "auto_draws": 0}
```
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

### admin_stats_data
Server → Client
```json
{"type": "admin_stats_data", "online": 0, "memory_mb": 0, "rooms": []}
```

### admin_logs_data
Server → Client
```json
{"type": "admin_logs_data", "lines": []}
```

---

## Protocol Compatibility Rule
New packets may be added. Existing packet names, field names, and semantics may not be changed/renamed. Breaking changes require ADR approval.