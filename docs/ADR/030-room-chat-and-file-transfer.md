# 030 — Room chat and consent-based 1-to-1 file transfer

## Status

accepted (feature branch `feature/room-chat-files` — demand-unvalidated;
isolated from release-track `main`)

## Context

Password-protected rooms (`room['password_hash'] !== null`) need a bounded
social channel: plain-text chat for everyone currently in the room, plus
optional 1-to-1 file transfer with an explicit accept/reject handshake.

Hard constraints from ANCHOR_CORE.md / the VPS profile:

- **Single Workerman worker**, 1 CPU / 500MB RAM — chat and files must not
  touch SQLite (Database Ownership scopes disk to users/coins/bans only).
- **No history** — messages and file bytes are RAM-only and transient;
  reconnect/rejoin does not replay prior chat.
- **File Size Policy** — new Handler ≤300 lines, new Service ≤500 lines;
  logic belongs in a new `src/Chat/` module, not Lobby/Game/Admin.
- **Timer Discipline** — a new timer type requires this ADR (Part 5 today
  only lists watchdog / lobby_afk / game_afk / apartment / reconnect).
- **Protocol / Class Names registries** (Part 6) must be updated in the
  same pass as code (prior backfills of ADR-022 / registry hygiene).

Existing timers for comparison (`src/Core/Constants.php`):

| Constant | Value | Nature |
|----------|-------|--------|
| `APARTMENT_TIMEOUT` | 10s | forced binary choice, already in flow |
| `RECONNECT_TIMEOUT` | 15s | automatic reconnect window |
| `GAME_AFK_STRIKE1_SECONDS` | 30s | first AFK strike |

A file-accept decision is a larger interrupt than apartment agree/refuse,
so the offer timeout must be **longer than 30s** but still bounded.

Workerman package-size check (this repo, `vendor/workerman`):

- `TcpConnection::$defaultMaxPackageSize = 10485760` (10 MiB)
- Per-connection `$maxPackageSize` is copied from that default in the
  constructor — so today a ~1.37 MiB base64 payload already fits.
- Project did **not** set an explicit override in `server.php` before
  this ADR; we set a tighter explicit cap (see Decision).

## Decision

### Module boundary (`src/Chat/`)

**Owns:**

- Password-room gate (`password_hash !== null`)
- Chat text broadcast to active players in the room
- File offer / accept / reject / data relay state machine
- Room transfer lock and offer/relay timers
- Dedicated file-action rate limit (separate from ADR-003)

**Forbidden:**

- Game, economy, apartment, victory, drawer, AFK logic
- Auth / session / ban logic
- Any SQLite / disk write of chat text or file bytes
- Broadcasting file bytes to the whole room

**Classes:**

| Class | Role | Soft limit |
|-------|------|------------|
| `Lotto\Chat\ChatHandler` | Route `room_message`, `file_offer`, `file_accept`, `file_reject`, `file_data` | ≤300 |
| `Lotto\Chat\ChatService` | Chat broadcast + password-room gate helpers | ≤500 |
| `Lotto\Chat\FileTransferService` | Offer/accept/reject/data, lock, timeouts, disconnect release | ≤500 |

Dependency direction: Core ← Chat. Chat may call `RoomManager` lookups and
Helpers (`sendJson` / `sendError` / `broadcastToRoom` / `lottoTimer*`).
Lobby/Game must not import Chat internals; disconnect/leave cleanup is
invoked via `$worker->fileTransferService` from the existing close/leave
call sites (thin public `releaseForConn` / `releaseForRoom`).

### Availability

Chat and file actions are allowed only when:

1. Sender is authenticated and seated as `status === 'active'` in a room
2. That room has `password_hash !== null`
3. Room `status` ∈ `{waiting, playing, apartment}` (not `finished`)

Passwordless rooms reject with `error.chat_unavailable`.

### Chat

- Action: `room_message` with `text` (string, trimmed, 1…`CHAT_MESSAGE_MAX_CHARS`)
- Broadcast packet `room_message` to all **active** players in the room
  (same audience as `broadcastToRoom`)
- No lock, no persistence, no replay on reconnect
- Client renders via `textContent` only (never `innerHTML`)

### File transfer state machine

Room field `file_transfer` is `null` (idle) or:

```php
[
  'state'              => 'offer_pending'|'relay_pending',
  'offer_id'           => string,   // opaque hex
  'sender_conn_id'     => int,
  'recipient_conn_id'  => int,
  'sender_username'    => string,
  'recipient_username' => string,
  'filename'           => string,   // sanitized basename
  'size_bytes'         => int,      // declared decoded size
  'timer_id'           => ?int,     // offer or relay timer
]
```

**States:** `idle` (`null`) | `offer_pending` | `relay_pending`

**Transitions:**

| From | Event | To | Side effects |
|------|-------|----|--------------|
| idle | valid `file_offer` | offer_pending | acquire lock; arm offer timer; send `file_offer` to recipient only |
| offer_pending | `file_accept` by recipient | relay_pending | cancel offer timer; arm relay timer; send `file_accepted` to sender only |
| offer_pending | `file_reject` by recipient | idle | cancel timer; send `file_rejected` to sender (`reason=declined`); release lock |
| offer_pending | offer timer fires | idle | send `file_offer_expired` to sender + recipient; release lock |
| offer_pending | sender disconnect / leave / remove | idle | cancel timer; notify recipient `file_offer_expired`; release lock |
| offer_pending | recipient disconnect / leave / remove | idle | cancel timer; notify sender `file_offer_expired`; release lock |
| relay_pending | valid `file_data` from sender | idle | decode+size-check; send `file_data` to recipient only; release lock |
| relay_pending | invalid `file_data` | idle | `error.file_invalid_payload` to sender; release lock (do not leave lock stuck) |
| relay_pending | relay timer fires | idle | notify both `file_offer_expired`; release lock |
| relay_pending | sender or recipient disconnect / leave / remove | idle | cancel timer; notify remaining party if still active; release lock |
| any | room `destroyRoom` | idle | cancel timer; drop struct (no packets; room gone) |

**Room-wide transfer lock:** while `file_transfer !== null`, any other
`file_offer` in that room is rejected with `error.file_transfer_busy`.
Chat remains unlocked.

**Bytes:** never buffered before accept. `file_data` is accepted only in
`relay_pending` from the offer's sender. Size is validated against
**decoded** byte length (`strlen(base64_decode(...))`), never against the
base64 string length. Decoded length must equal declared `size_bytes` and
be ≤ `FILE_MAX_BYTES`.

**Client safety (mandatory):** received files are exposed only via a
forced-download `<a download="safeName">` object URL. Never inline preview,
never `innerHTML`/`DOMParser` of content, never `target="_blank"` on blob
URLs (blocks scripted SVG/HTML from executing in the recipient tab).

### Protocol

**Actions (client → server):**

```json
{"action": "room_message", "text": "hello"}
{"action": "file_offer", "to_username": "bob", "filename": "notes.txt", "size_bytes": 1234}
{"action": "file_accept", "offer_id": "a1b2c3d4e5f60718"}
{"action": "file_reject", "offer_id": "a1b2c3d4e5f60718"}
{"action": "file_data", "offer_id": "a1b2c3d4e5f60718", "data": "<base64>"}
```

**Packets (server → client):**

```json
{"type": "room_message", "from": "alice", "text": "hello", "ts": 1704067200}
{"type": "file_offer", "offer_id": "a1b2c3d4e5f60718", "from": "alice", "filename": "notes.txt", "size_bytes": 1234}
{"type": "file_accepted", "offer_id": "a1b2c3d4e5f60718"}
{"type": "file_rejected", "offer_id": "a1b2c3d4e5f60718", "reason": "declined"}
{"type": "file_data", "offer_id": "a1b2c3d4e5f60718", "from": "alice", "filename": "notes.txt", "data": "<base64>"}
{"type": "file_offer_expired", "offer_id": "a1b2c3d4e5f60718"}
```

`file_rejected.reason` is always `"declined"` for an explicit reject
(distinct from expiry / disconnect, which use `file_offer_expired`).

**Error codes:**

| Code | When |
|------|------|
| `error.chat_unavailable` | Chat/file action in a passwordless room (or not in a room) |
| `error.chat_message_invalid` | Empty / oversized / non-string text |
| `error.file_transfer_busy` | Offer while room lock held |
| `error.file_too_large` | Declared or decoded size > `FILE_MAX_BYTES` |
| `error.file_recipient_invalid` | Recipient missing, self, or not active in room |
| `error.file_offer_invalid` | Unknown/mismatched offer_id or wrong actor |
| `error.file_invalid_payload` | Missing/bad base64, decoded size mismatch |
| `error.file_rate_limited` | Dedicated file-action rate limit exceeded (connection kept open) |

Additive field on `room_joined`: `has_password` (bool) so the client can
show the chat panel without inferring from the join password field.

### Constants

```php
CHAT_MESSAGE_MAX_CHARS = 500;
FILE_MAX_BYTES = 1048576;                 // 1 MiB decoded
FILE_OFFER_TIMEOUT = 60;                  // seconds
FILE_RELAY_TIMEOUT = 30;                  // seconds after accept
FILE_RATE_LIMIT_MAX = 3;                  // file_offer + file_data
FILE_RATE_LIMIT_WINDOW_SECONDS = 60;
WS_MAX_PACKAGE_SIZE = 2097152;            // 2 MiB explicit Workerman cap
```

**Timeout justification:** 60s offer timeout sits above AFK strike-1 (30s)
and well above apartment (10s) / reconnect (15s), matching “bigger ask,
still bounded”. Relay timeout 30s is enough to push ≤1 MiB on a slow
link without holding the room lock indefinitely.

**Rate-limit justification:** ADR-003’s 15 pkt/s still applies globally
(and still closes the socket on flood). File offers are small JSON and
would otherwise allow lock-churn harassment inside that budget. A
dedicated soft limit of **3 `file_offer`/`file_data` actions per 60s per
connection** rejects with `error.file_rate_limited` without closing —
orders of magnitude stricter for transfer spam while leaving chat under
the general limit. Accept/reject are not counted (they clear locks).

### Workerman max package size & memory footprint

- Confirmed default: **10 MiB**. Explicit project override: **2 MiB**
  (`TcpConnection::$defaultMaxPackageSize = WS_MAX_PACKAGE_SIZE` in
  `server.php` before `Worker::runAll()`).
- 1 MiB raw → ~1.37 MiB base64 + JSON envelope fits under 2 MiB with
  margin; 2 MiB is far below the previous 10 MiB default (smaller DoS
  surface).

**Worst-case RAM (file payloads only):**

- At most **one** in-flight file per room (room lock)
- `MAX_ROOMS = 30` → ≤ 30 × ~1.37 MiB ≈ **~41 MiB** of base64 in flight
  simultaneously (plus brief decode copies)
- Against a 500 MiB VPS budget this is acceptable (~8%); **no global
  concurrent-transfer cap** is required for v1. Revisit if MemoryAudit
  shows sustained pressure.

Connection runtime fields (file rate limit only):

```php
$connection->fileActionCount;
$connection->fileActionWindowStart;
```

### Timer type

New allowed timer type: `file_offer` (single-shot; reused for both offer
and relay deadlines — one timer_id stored on `file_transfer`).

- **Creator:** `FileTransferService` on offer / accept
- **Owner:** room (`file_transfer.timer_id`)
- **Destroyer:** accept, reject, successful/failed data, timeout callback,
  disconnect/leave release, `RoomManager::destroyRoom`

## Consequences

- ANCHOR_CORE.md Part 1/3/5/6 and ANCHOR_PROTOCOL.md gain Chat registries,
  constants, timer type, room key `file_transfer`, connection fields, and
  all new actions/packets/errors.
- Client shows chat only when `has_password === true`.
- Feature ships on `feature/room-chat-files` only; must not merge to
  `main` until demand is validated.
- Unilateral product decisions recorded for stakeholder review:
  1. Chat/file allowed in `waiting`/`playing`/`apartment` (not only lobby)
  2. Recipient addressed by `to_username` (globally unique)
  3. `CHAT_MESSAGE_MAX_CHARS = 500`
  4. `FILE_RELAY_TIMEOUT = 30` after accept
  5. No global concurrent-transfer cap beyond per-room lock
  6. Disconnect during reconnect grace **immediately** releases the lock
     (does not wait for `RECONNECT_TIMEOUT`)
