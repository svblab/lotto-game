# ANCHOR_CORE.md

## Purpose
SSOT for "Russian Lotto" multiplayer game (architecture, economy, file structure, state machine, timers, naming). If code contradicts this doc, the doc is correct — fix the code.

---

# PART 1. ARCHITECTURE

Stack: PHP 8.x, Workerman WebSocket, SQLite3 (PDO), Vanilla JS.
Deploy: Ubuntu 22.04, VPS 1 CPU/500MB RAM, WS port 8080, single Workerman worker.

## Global Constants
```php
MAX_TOTAL_PLAYERS = 150;
MAX_ROOMS = 30;
BET_PER_CARD = 10;
DAILY_BONUS = 100;
RECONNECT_TIMEOUT = 15;
LOBBY_HOST_TIMEOUT = 120;
UNAUTHORIZED_TIMEOUT = 60;
AUTHORIZED_TIMEOUT = 120;
RATE_LIMIT_PACKETS_PER_WINDOW = 15;  // ADR-003
RATE_LIMIT_WINDOW_SECONDS = 1;       // ADR-003
LOGIN_THROTTLE_MAX_ATTEMPTS = 5;     // ADR-028
LOGIN_THROTTLE_WINDOW_SECONDS = 300; // ADR-028
LOGIN_THROTTLE_LOCKOUT_SECONDS = 900; // ADR-028
MAX_ACCOUNTS_PER_IP = 3;             // ADR-031
CHAT_MESSAGE_MAX_CHARS = 500;        // ADR-030
FILE_MAX_BYTES = 1048576;            // ADR-030 (1 MiB decoded)
FILE_OFFER_TIMEOUT = 60;             // ADR-030
FILE_RELAY_TIMEOUT = 30;             // ADR-030
FILE_RATE_LIMIT_MAX = 3;             // ADR-030
FILE_RATE_LIMIT_WINDOW_SECONDS = 60; // ADR-030
WS_MAX_PACKAGE_SIZE = 2097152;       // ADR-030 (2 MiB Workerman cap)
```

## Runtime Memory Layout
```
Worker → rooms, userConnections, db, logger
```

## userConnections
```php
$worker->userConnections[userId] = $connection;
```
Purpose: prevent double login, fast user lookup, reconnect support.

## Room Structure
```php
$worker->rooms[$roomId] = [
  'room_id' => int,
  'host_conn_id' => int,
  'bet_per_card' => 10,
  'max_players' => int,
  'password_hash' => ?string,
  'status' => 'waiting'|'playing'|'apartment'|'finished',
  'bank' => int,
  'apartment_fired' => bool,
  'pause_for_apartment' => bool,
  'apartment_responses' => [],
  'win_chance_history' => [],
  'game_afk_timer_id' => null,
  'apartment_timer_id' => null,
  'lobby_afk_timer_id' => null,
  'active_drawer_conn_id' => null,
  'drawer_order' => [],
  'bag' => [],
  'drawn_numbers' => [],
  'players' => [],
  'all_players_history' => [],
  'file_transfer' => null,  // ADR-030: null | offer/relay struct (RAM-only)
  // ADR-034: reserved, not yet created by RoomManager — see
  // IMPLEMENTATION_STATUS.md EPIC-034 (Planned). Do not treat as live.
  'bot' => null,            // shape when EPIC-034.1 lands: bot object (not in players)
  'speed_mode' => 'slow'    // ADR-035: 'slow'|'fast' — client animation profile
];
```

**Reserved keys (ADR-022):** present on every room created by `RoomManager` but
not read by production logic — kept for snapshot/fixture compatibility (same
pattern as `error.banned` in ADR-007):

- `bet_per_card` — initialized to `Constants::BET_PER_CARD` (10). Stake
  calculation uses the constant directly, not this field.
- `pause_for_apartment` — always `false`. Apartment pause is represented by
  `status === 'apartment'`; this flag is never toggled in production.

**Bot object (ADR-034) — reserved / not yet implemented:** documented target
shape for EPIC-034.1+. **`RoomManager::createRoom()` does not initialize
`$room['bot']` today.** Until EPIC-034.1 ships, treat this key as registry-
reserved only (same spirit as `error.banned` in ADR-007). See
`IMPLEMENTATION_STATUS.md` § EPIC-034 (Planned).

When implemented, `$room['bot']` is `null` unless the host started
human-vs-computer via `play_vs_bot`. The bot is **not** an entry in
`$room['players']`, has no `user_id` / `session_token` / SQLite row / coins,
and never appears in `drawer_order`. Shape when present:

```php
$room['bot'] = [
  'username'    => 'Bot',
  'cards'       => [],
  'cards_count' => 2,
  'total_paid'  => 0,
  'immune'      => false,
  'drawing'     => false,
  'status'      => 'active'
];
```

While the bot is the current drawer: `active_drawer_conn_id = null` and
`bot['drawing'] = true`. Engine subsystems that iterate participants must use
an explicit parallel bot branch (see ADR-034 §4–§6).

**Speed mode (ADR-035):** `$room['speed_mode']` is `'slow'` (default) or
`'fast'`. Chosen only at `create_room`, frozen for the room lifetime. Affects
**client slot-animation timing only** — server draw/AFK semantics unchanged.

## Player Structure
```php
$room['players'][$connId] = [
  'user_id' => int,
  'username' => string,
  'cards' => [],
  'cards_count' => 1|2,
  'total_paid' => int,
  'last_action' => int,
  'host_activity_at' => int,
  'afk_start' => null,
  'strikes' => 0,
  'auto_draws' => 0,
  'nudged_this_turn' => false,
  'status' => 'active'|'disconnected',
  'session_token' => string,
  'reconnect_timer' => null,
  'connection' => object,
  'immune' => bool
];
```

`nudged_this_turn` (ADR-032): per-player, RAM-only, default `false` if
absent. Set on a successful `nudge_turn`; reset to `false` for every
seated player in `GameTurnService::startTurn()`. Never persisted; never
affects AFK fields.

## Connection Runtime Fields
```php
$connection->userId;
$connection->username;
$connection->isAdmin;
$connection->sessionToken;
$connection->lastPing;
$connection->packetCount;       // ADR-003: rate limiting, окно 1s
$connection->packetWindowStart; // ADR-003: rate limiting, окно 1s
$connection->clientRemoteIp;    // ADR-031: resolved client IP for IP-account cap bucketing
$connection->fileActionCount;       // ADR-030: file_offer/file_data rate limit
$connection->fileActionWindowStart; // ADR-030: file action rate-limit window
```
No additional business fields allowed beyond those listed here (see ADR-003 for the rate-limiting pair; ADR-031 for `clientRemoteIp`; ADR-030 for the file-action pair).

## Room States
Allowed: `waiting | playing | apartment | finished`. No others.

## Player States
Allowed: `active | disconnected`. No others. Removal reasons are NOT states.

## Removal Reasons
Allowed: `leave, disconnect, afk, refuse, banned, kicked, admin_close`. Transient events, never stored as player status.

## Ownership Rules
Host = `host_conn_id`. Current drawer = `active_drawer_conn_id` when a human
is drawing; when the bot is drawing (ADR-034, **after EPIC-034.1**),
`active_drawer_conn_id` is `null` and `$room['bot']['drawing'] === true`.
Never merge host and drawer concepts. The bot is never host.
(ADR-034 bot drawer path is reserved — not live until EPIC-034.1.)

## Drawer Order Rules
Stored in `drawer_order` (human `conn_id`s only — the bot is never stored here):
1. Host always starts first.
2. Remaining players added FIFO.
3. Removed players skipped.
4. Disconnected players skipped.
5. Queue is cyclic.
6. ADR-034 (bot present, **EPIC-034.1+**): conceptual rotation is Host → Bot →
   Host → …; after the human draw the next drawer is the bot (immediate
   server draw); after the bot draw the next drawer is the sole active human
   in `drawer_order`.

## Room Destruction Rules
Destroy room if: no players remain | game finished | admin closed room |
lobby host-candidate queue exhausted (ADR-011 — all seated players timed out
as host without `start_game`).
Before destruction: cancel all timers (room + reconnect), remove room from memory.

## Timer Registry
Room-level: `game_afk_timer_id, apartment_timer_id, lobby_afk_timer_id`.
Player-level: `reconnect_timer`.
No additional timer fields without ADR.

## Database Ownership
SQLite = source of truth for: users, passwords, coins, bans.
RAM = source of truth for: rooms, cards, bags, timers, game state,
chat messages in flight, file-transfer offers/bytes (ADR-030 — never
written to SQLite or disk).

## Logging Rules
Comments: Russian. Logs: English.
Format: `[YYYY-MM-DD HH:MM:SS] [LEVEL] message`

## File Size Policy
`server.php <= 500 lines`, `handler <= 300 lines`, `service <= 500 lines`. If exceeded, create a new module — do not grow indefinitely.

## Architectural Rule
Business logic forbidden in `server.php` / bootstrap files. Belongs only to Handlers, Services, Engine.

---

# PART 2. ECONOMY

If code contradicts this section, the spec here is correct — fix the code.

## Currency
Single currency `coins`. Integers only — fractional values forbidden.

## Source of Truth
`users.coins` in SQLite is authoritative. RAM copies are informational.

## Initial Balance
New user: `500 coins`.

## Daily Bonus
`100 coins`, if `user is not admin` AND `86400 seconds passed`. Applied only during login.

## Card Price
`BET_PER_CARD = 10` (fixed).

## Room Entry Cost
Player chooses 1 or 2 cards. Cost = `cards_count * BET_PER_CARD` (1 card=10, 2 cards=20).

## Reservation Rule
Joining/creating a room does NOT deduct coins. Coins remain on user balance.

## Start Game Deduction
Deducted only in `startGame()`: for every player, `coins -= total_paid`. Transaction required — all-or-nothing, no partial deduction.

## Bank Creation
Initial bank = `sum(all total_paid)`. Example: 10+20+10 = bank 40.

## Bank Ownership
Bank belongs to the room — not host, drawer, or winner — until game end.

## Apartment Payment
Triggers at most once per game. Required (non-immune) player who chooses `agree` adds `5 coins` to bank: `bank += 5; player.total_paid += 5`. Transaction required.

## Apartment Refusal
Final choice `refuse` causes removal (reason `refuse`) when the apartment phase ends. Already-paid coins remain in bank — no refund.

## Apartment Timeout
Equivalent to `refuse` for players who never sent a choice. Players who sent `agree` or `refuse` may change their choice until the timer expires; only the last choice counts.

## Disconnect
No refund. Player remains eligible for reconnect.

## Reconnect Timeout
Reason `disconnect`. Coins remain in bank, no refund.

## Leave During Game
Reason `leave`. Coins remain in bank, no refund.

## AFK Removal
Reason `afk`. Coins remain in bank, no refund.

## Ban
Reason `banned`. Coins remain in bank, no refund.

## Kick
Reason `kicked`. Player refunded `total_paid`: `bank -= total_paid; coins += total_paid`. Transaction required.

## Admin Close Room
Reason `admin_close`. All players get 100% refund (including apartment payments), sourced from `all_players_history`.

## Victory Condition
Player wins if all 15 numbers on at least one card are closed. Victory ends game immediately.

## Normal Victory
One winner takes entire bank: `winner.coins += bank; bank = 0`.

## Double Victory
Two cards of same player complete in the same draw = 2 shares; a normal winner = 1 share.
`share = floor(bank / total_shares)`. Remainder is burned (never distributed/stored).
Example: bank=100, playerA double win, shares=2 → playerA receives 100.
Example: bank=100, playerA double (2 shares) + playerB normal (1 share), total 3 shares → share=floor(100/3)=33 → playerA=66, playerB=33, remainder 1 burned.

## Apartment vs Victory
Priority: Victory > Apartment. If same barrel causes both, victory wins, apartment ignored, no additional payments.

## Last Survivor
Exactly one active **human** remains and no opposing bot remains → that human
takes entire bank: `winner.coins += bank; bank = 0`.
When a bot is present (ADR-034, **EPIC-034.1+**), the bot counts as an opposing
participant for this check; removing the bot with one human left yields
`last_survivor` for the human (including immediate apartment `refuse` of the
bot). Reserved until EPIC-034 ships — see `IMPLEMENTATION_STATUS.md` EPIC-034
(Planned).
**Qualifying condition (ADR-013):** when the triggering removal reason is `afk`, the
survivor must have `auto_draws === 0` (no AFK auto-draws this game). If the survivor
has `auto_draws > 0`, treat as § No Survivors (refund via `handleNoSurvivors()`). Removal
reasons `disconnect`, `leave`, `refuse`, `kicked`, and `banned` are unaffected — last
survivor payout applies regardless of the survivor's `auto_draws`.

## No Survivors
Zero active players remain → refund all participants (from `all_players_history`) their `total_paid` (including apartment payments). `bank = 0`. Room destroyed.

## Economic Integrity Rule
At any time, `bank + sum(user balances) + burned remainder` must be explainable.
Coin creation/duplication/deletion forbidden, except daily bonus, burned
division remainder, and the following ADR-034 intentional mechanics
(**accepted design; not live until EPIC-034.3 / EPIC-034.4**):

- **Bot-win bank burn** — when the bot wins (`game_over` reason `bot_win`),
  the room bank is destroyed (neither paid to the bot nor refunded to the
  human); `bank = 0` with no `users.coins` credit.
- **Bot win-streak double-bank mint** — on a human’s 3rd consecutive win
  against the bot, after the normal bank payout the server credits an
  additional amount equal to that bank (genuine emission); streak counter
  is RAM-only (`$worker->botWinStreaks`).

## Bot opponent economy (ADR-034)
Reserved / not yet implemented — see `IMPLEMENTATION_STATUS.md` EPIC-034
(Planned). Target rules when EPIC-034 ships:
- Bot `total_paid` is always 0; bank at start = human stake only.
- Human `victory` / `last_survivor` vs bot: normal bank payout; increments
  `$worker->botWinStreaks[$userId]`.
- Bot win: bank burn + `reason: bot_win`; that human’s streak resets to 0.
- Streak also resets on explicit logout and on finishing any human-vs-human
  game; disconnect/reconnect without logout does **not** reset the streak.

## Mandatory Transactions
SQLite transaction required for: `startGame()`, apartment payment, kick refund,
`admin_close_room`, victory payout, last_survivor payout, zero-survivor refund.
ADR-034 additions (**when EPIC-034 ships**): `play_vs_bot` human stake
deduction, streak double-bank mint (same transaction as the accompanying
payout when possible). Bot-win bank burn does not credit any `users.coins`
(bank cleared in RAM only). No operation may update `bank` and `users.coins`
independently when both are involved — both succeed or both fail.

---

# PART 3. FILE STRUCTURE

If code contradicts this section, reorganize the code.

## Project Root
```
lotto-game/
├── src/
├── public/
├── docs/
├── logs/
├── patches/
├── tests/
├── server.php
├── init_db.php
├── composer.json
├── README.md
```

## Bootstrap Rule
Business logic forbidden in `server.php`, `init_db.php`.
`server.php` allowed: Workerman startup, dependency wiring, handler registration.
`init_db.php` allowed: db init, schema creation, admin creation.

## src/ Modules
```
src/Core/ Auth/ Lobby/ Game/ Admin/ Chat/ Infrastructure/
```

### Core (ConnectionManager.php, RoomManager.php, Logger.php, Helpers.php, Constants.php)
Responsibilities: room/user lookup, helpers, constants, logging.
Forbidden: game/economy/admin logic.

### Auth (AuthHandler.php, AuthService.php, SessionService.php, LoginThrottleService.php, IpAccountLimitService.php)
Responsibilities: register, login, logout, session tokens, daily bonus, per-IP
live account cap at login (ADR-031).
Forbidden: room logic, game logic.

### Lobby (LobbyHandler.php, LobbyService.php)
Responsibilities: room create/join/leave, host transfer, lobby AFK.
Forbidden: draw barrel, victory, apartment.

### Game (GameHandler.php, GameService.php, LottoEngine.php, VictoryService.php, ApartmentService.php, ReconnectService.php)
Responsibilities: game start, draw barrel, mark numbers, victory detection, apartment, reconnect.
Forbidden: authentication, admin actions.

- **LottoEngine**: pure math — card/bag generation. Forbidden: db, connections, rooms, timers.
- **VictoryService**: victory detection, double victory, share calculation. Forbidden: socket sending, db access.
- **ApartmentService**: line detection, pause logic, response tracking. Forbidden: victory logic, authentication.
- **ReconnectService**: disconnect handling, reconnect lookup, state restore. Forbidden: game start, victory.

### Admin (AdminHandler.php, AdminService.php)
Responsibilities: kick, ban, unban, close room, logs. Forbidden: game mechanics.

### Chat (ChatHandler.php, ChatService.php, FileTransferService.php) — ADR-030
Responsibilities: password-room chat broadcast; consent-based 1-to-1 file
offer/accept/reject/data relay; room transfer lock; file-action rate limit.
Forbidden: game/economy/apartment/victory/auth/admin logic; any SQLite or
disk persistence of chat text or file bytes.

### Infrastructure (Database.php, PreparedStatements.php)
Responsibilities: PDO init, statement cache. Forbidden: business logic.

## public/
```
public/
├── index.html
├── css/style.css            (single entry point)
├── js/app.js, ws.js, ui.js, i18n.js
│     app.js: bootstrap | ws.js: websocket layer
│     ui.js: screen rendering | i18n.js: translations
├── img/
└── locales/ en.json ru.json es.json fr.json zh.json tr.json
```

## docs/
```
docs/ ANCHOR_CORE.md ANCHOR_PROTOCOL.md ANCHOR_RULES.md IMPLEMENTATION_STATUS.md ADR/
```

## logs/
`server.log`, rotated as `server_YYYYMMDD.log`.

## patches/
All generated diffs, format `EPIC-3.4.patch`.

## tests/
Manual scenarios, test cases, future automation.

## File Size Limits
Target 300-500 lines/file. Warning at 700. Mandatory refactor at 1000. Hard max 1500 without ADR.

## Epic Modification Rule
One Epic modifies 1-3 files normally. If 4+ files required, model must STOP and respond: "Epic is too large. Additional decomposition required."

## Patch Rule
Changes delivered as `diff -u`. Full file content forbidden except new files or explicit user request.

## Dependency Direction
Allowed: Core ← Auth, Lobby, Game, Admin, Chat.
Forbidden: Game→Auth, Admin→Game internals, Lobby→Apartment internals,
Chat→Game/Lobby/Admin internals.
Modules communicate only via services/public methods/events — no private internals access.

---

# PART 4. STATE MACHINE

If code contradicts this section, the spec here is correct — fix the code. No implicit states/hidden transitions.

## Room State Machine
Allowed states: `waiting | playing | apartment | finished`. No others.

**waiting**: Room exists, game not started, no cards, bank=0.
Allowed: `room_list, join_room, leave_room, start_game, reconnect, ping`,
and when `password_hash !== null`: `room_message, file_offer, file_accept, file_reject, file_data` (ADR-030).
ADR-034 `play_vs_bot` is **registry-reserved** (allowed once EPIC-034.1
ships) — not dispatched today.
Forbidden: `draw_barrel, apartment_choice`.
Transitions: `start_game → playing`; `no players remain → destroyed`;
`admin_close_room → destroyed`.
ADR-034 target (EPIC-034.1+): `play_vs_bot → playing` (creates `$room['bot']`);
while `$room['bot'] !== null`, `join_room` is rejected (`error.room_full`).

**playing**: Main loop active, cards/bag/bank/drawer exist.
Allowed: `draw_barrel, leave_room, ping, reconnect, nudge_turn`,
and when `password_hash !== null`: `room_message, file_offer, file_accept, file_reject, file_data` (ADR-030).
Forbidden: `join_room, start_game, apartment_choice`
(and `play_vs_bot` once that action exists).
Transitions: `apartment detected → apartment`; `winner found → finished`;
`last survivor → finished`; `admin_close_room → destroyed`;
`no active players → destroyed`.
ADR-034 target (EPIC-034.3+): `bot win → finished` (reason `bot_win`).
No new room states for bot mode — bot is a room field, not a state.

**apartment**: Apartment event active, loop paused, no barrel drawing, waiting on required responses.
Allowed: `apartment_choice, ping`,
and when `password_hash !== null`: `room_message, file_offer, file_accept, file_reject, file_data` (ADR-030).
Forbidden: `draw_barrel, start_game, join_room`
(and `play_vs_bot` once that action exists). Reconnect forbidden.
Transitions: `apartment timer expired → playing`; `winner found → finished`;
`last survivor → finished`; `admin_close_room → destroyed`.
ADR-034 target (EPIC-034.2+): last survivor after immediate bot `refuse`
removal.

**finished**: Result finalized, prizes distributed, no gameplay. Allowed: none. Immediately destroyed.
Transition: `finished → destroyed`.

## Player State Machine
Allowed: `active | disconnected`. No others. Removal reasons are NOT states.

**active**: connected, may act.
Transitions: `connection lost → disconnected`; `leave/afk/refuse/kicked/banned → removed`.

**disconnected**: temporarily absent, reconnect timer active.
Allowed: `reconnect`. Forbidden: `draw_barrel, apartment_choice, leave_room`.
Transitions: `reconnect → active`; `timeout → removed`.

## Reconnect Rules
Allowed only if `room.state ∈ {waiting, playing}`. Forbidden if `room.state ∈ {apartment, finished}`.

## Removal Rules
Removal is an event, not a state. Reasons: `leave, disconnect, afk, refuse, kicked, banned, admin_close`.
After removal, player must not remain in `$room['players']`; may remain only in `all_players_history`.

## Host Rules
Host ownership = `host_conn_id`. Changes only if host leaves/disconnects permanently/removed/banned/kicked/afk-removed. New host = next active player FIFO.

## Drawer Rules
Drawer ownership = `active_drawer_conn_id` when a human is drawing; when the
bot is drawing (ADR-034, **EPIC-034.1+**), `active_drawer_conn_id` is `null`
and `$room['bot']['drawing'] === true`. Changes on: successful draw, afk auto
draw, drawer removal, or bot↔human handoff after a draw. Host and drawer are
independent. The bot is never host and never receives `your_turn` / Game AFK.
(Bot drawer path reserved — not live until EPIC-034.1.)

## Apartment Priority
Victory > apartment. Same barrel causing both → victory; apartment must not start.

## Room Destruction
Terminal. `unset($worker->rooms[$roomId])` executed. All room/reconnect/AFK timers cancelled. Destroyed rooms cannot be restored.

---

# PART 5. TIMERS

If code contradicts this section, the spec here is correct — fix the code. No additional timer types without ADR.

## General Rules
Implementation: `Workerman\Timer`.
Allowed types: `watchdog, lobby_afk, game_afk, apartment, reconnect, file_offer` (ADR-030). No others.

## Timer Ownership
Every timer has exactly one owner: connection, player, room, or server. All timer IDs stored and cancellable. No anonymous/unmanaged timers.

## Timer Storage
Room-level: `lobby_afk_timer_id, game_afk_timer_id, apartment_timer_id`.
Player-level: `reconnect_timer`.
File transfer (ADR-030): `file_transfer.timer_id` when `file_transfer !== null`
(offer or relay deadline; type label `file_offer`). No other timer IDs.

## Global Watchdog Timer
Owner: server. Count: 1 for entire process. Interval: 60s. Purpose: close dead connections.
Checks: authorized `now-lastPing>120` → close; unauthorized `now-lastPing>60` → close.
Created: `onWorkerStart`. Destroyed: worker shutdown.

## Lobby AFK Timer
Owner: room. Exists only in `waiting`. Purpose: prevent inactive host.
Created when: room has `>=2 players` and host responsible for starting.
Interval: 1s repeat. Check: `time()-host.host_activity_at`. Threshold: 120s.
`host_activity_at` tracks genuine lobby host interaction only — not updated
by `ping` (ADR-010); `last_action` remains for connection liveness.
Action: transfer host to the next active player FIFO — strictly the
next untried candidate positioned after the current host in
`drawer_order`. Rotation is forward-only: a player who already held
host and timed out is never re-selected while any later candidate
remains untried (ADR-011). If no untried active candidate remains
after the current host's position, the queue is exhausted: the room is
destroyed and every remaining player is removed with reason `afk`
(existing `player_left` packet, existing reason — ANCHOR_CORE.md Part 1
§ Removal Reasons; no new packet/reason introduced).
Destroyed when: game starts, room destroyed, or player count <2. Max one per room.

## Game AFK Timer
Owner: room. Exists only in `playing`. Count: exactly 1/room. Interval: 1s repeat.
Tracks: `active_drawer_conn_id`. Created on first `your_turn`, reused after turn change — never recreated.

### Thresholds (measured as `time()-player.afk_start` per turn; ADR-012)
- `auto_draws=0`: strike 1 — window **30s** (`LOTTO_GAME_AFK_STRIKE1`) → `afk_warning`, auto-draw, player stays (`auto_draws=1`).
- `auto_draws=1`: strike 2 — window **15s** (`LOTTO_GAME_AFK_STRIKE2`) → `afk_warning`, auto-draw, player stays (`auto_draws=2`).
- `auto_draws=2`: strike 3 — window **5s** (`LOTTO_GAME_AFK_STRIKE3`) → `removePlayerFromGame(..., 'afk')` (no auto-draw).
- Successful manual draw: `strikes=0`, `auto_draws=0`, `afk_start` reset for next drawer via `your_turn`.
- On turn change: `strikes=0`, `afk_start=time()` for new drawer (`auto_draws` preserved per player).
Destroyed when room leaves `playing` or room destroyed.

## Apartment Timer
Owner: room. Exists only in `apartment`. Max 1/room. Created on apartment start. Duration: 10s single-shot.
Purpose: limit response time and keep all clients in sync. Expiration: unanswered required players → `refuse`; all recorded refusals applied; agreeing players charged; game resumes/finishes per state machine.
Destroyed when: timer expires (`finishApartment`), room destroyed, or admin closes room. Not destroyed early when all players have answered.

## Reconnect Timer
Owner: player. Exists only for `disconnected`. Created on connection loss when `room.state ∈ {waiting, playing}`.
Duration: 15s single-shot. Expiration → `removePlayerFromLobby(...)` / `removePlayerFromGame(...)` reason `disconnect`.
Destroyed when: player reconnects, removed, or room destroyed. Forbidden in `apartment` state.

## File Offer / Relay Timer (ADR-030)
Owner: room. Exists only while `file_transfer !== null` in a password-protected room.
Count: at most 1/room. Single-shot. Label: `file_offer`.
- Offer phase: duration `FILE_OFFER_TIMEOUT` (60s). Expiration → `file_offer_expired` to parties, release lock.
- Relay phase: duration `FILE_RELAY_TIMEOUT` (30s) after accept. Expiration → same release path.
Destroyed when: accept (re-armed for relay), reject, successful/failed `file_data`, timeout, sender/recipient disconnect or leave, or room destroyed.

## Timer State Restrictions
- `waiting`: watchdog, lobby_afk, reconnect, file_offer (ADR-030).
- `playing`: watchdog, game_afk, reconnect, file_offer (ADR-030).
- `apartment`: watchdog, apartment, file_offer (ADR-030).
- `finished`: watchdog only.

## Room Destruction Cleanup
Before `unset($worker->rooms[$roomId])`:
```php
if (!empty($room['lobby_afk_timer_id'])) Timer::del($room['lobby_afk_timer_id']);
if (!empty($room['game_afk_timer_id']))  Timer::del($room['game_afk_timer_id']);
if (!empty($room['apartment_timer_id'])) Timer::del($room['apartment_timer_id']);
if (!empty($room['file_transfer']['timer_id'])) Timer::del($room['file_transfer']['timer_id']); // ADR-030
foreach ($room['players'] as $player) {
    if (!empty($player['reconnect_timer'])) Timer::del($player['reconnect_timer']);
}
```

## Timer Integrity Rules
- No timer without an owner.
- A destroyed owner keeps no timers.
- A timer is never created twice.
- No timer survives room destruction.
- No reconnect timer survives player removal.
- A room never has two `game_afk`, `apartment`, or `lobby_afk` timers simultaneously.

## Mandatory Validation
Every timer must answer: Who creates it? Who owns it? Who destroys it? What happens if the owner disappears? Unknown answer = invalid implementation.

---

# PART 6. NAMING REGISTRY

If code introduces alternative naming, this registry is correct — fix the code. Any new name affecting architecture/protocol/economy/timers/state machine requires ADR.

## General
Language: English only.
- Variables: `camelCase` (`$userId`, `$roomId`, `$cardsCount`)
- Array keys: `snake_case` (`room_id`, `host_conn_id`, `cards_count`, `session_token`)
- Methods: `camelCase` (`startGame()`, `removePlayerFromGame()`)
- Classes: `PascalCase` (`GameService`, `RoomManager`)
- Constants: `UPPER_SNAKE_CASE` (`MAX_ROOMS`, `MAX_TOTAL_PLAYERS`, `BET_PER_CARD`)

## Root Namespace
```php
Lotto\
```
All PHP classes belong to `Lotto\...` (e.g. `Lotto\Core`, `Lotto\Auth`, `Lotto\Lobby`, `Lotto\Game`, `Lotto\Admin`, `Lotto\Chat`, `Lotto\Infrastructure`).
Forbidden: `App\`, `Application\`, `Project\`, or any other root namespace.
Composer PSR-4 mapping is authoritative:
```json
{"autoload": {"psr-4": {"Lotto\\": "src/"}}}
```
Changing root namespace requires ADR. If code contains another root namespace, the model must stop and report a namespace inconsistency. Models default to `App\...` (Laravel/Symfony habit) — actively guard against this.

## Database: users table
Fields: `id, username, password_hash, coins, is_admin, banned_until, last_daily_bonus`. No alternative names.

## Global Constants (names)
```
MAX_ROOMS, MAX_TOTAL_PLAYERS, BET_PER_CARD, DAILY_BONUS, RECONNECT_TIMEOUT,
LOBBY_HOST_TIMEOUT, UNAUTHORIZED_TIMEOUT, AUTHORIZED_TIMEOUT, PROTOCOL_VERSION,
RATE_LIMIT_PACKETS_PER_WINDOW, RATE_LIMIT_WINDOW_SECONDS,
LOGIN_THROTTLE_MAX_ATTEMPTS, LOGIN_THROTTLE_WINDOW_SECONDS, LOGIN_THROTTLE_LOCKOUT_SECONDS,
MAX_ACCOUNTS_PER_IP,
CHAT_MESSAGE_MAX_CHARS, FILE_MAX_BYTES, FILE_OFFER_TIMEOUT, FILE_RELAY_TIMEOUT,
FILE_RATE_LIMIT_MAX, FILE_RATE_LIMIT_WINDOW_SECONDS, WS_MAX_PACKAGE_SIZE
```

## Connection Properties
`$connection->userId, ->username, ->isAdmin, ->sessionToken, ->lastPing, ->packetCount, ->packetWindowStart` (последние два — ADR-003, rate limiting), `->clientRemoteIp` (ADR-031, IP-account cap bucketing), `->fileActionCount, ->fileActionWindowStart` (ADR-030, file-action rate limit). No additional business fields.

## Worker Storage
`$worker->rooms`, `$worker->userConnections` (key=`userId`, value=`$connection`).
`$worker->botWinStreaks` (ADR-034): **reserved, not yet created** — see
`IMPLEMENTATION_STATUS.md` EPIC-034 (Planned). Target: key=`userId`,
value=`int` consecutive wins vs bot; RAM-only; missing key means 0.

## Room Structure Keys (allowed, no others without ADR)
```
room_id, host_conn_id, bet_per_card, max_players, password_hash, status, bank,
apartment_fired, pause_for_apartment, apartment_responses, win_chance_history,
active_drawer_conn_id,
drawer_order, bag, drawn_numbers, players, all_players_history,
lobby_afk_timer_id, game_afk_timer_id, apartment_timer_id,
file_transfer, bot, speed_mode
```
Reserved (ADR-022): `bet_per_card`, `pause_for_apartment` — see § Room Structure
reserved keys above; remain in the registry but are not consumed at runtime.

`file_transfer` (ADR-030): `null` when idle; otherwise RAM-only offer/relay
struct (`state`, `offer_id`, `sender_conn_id`, `recipient_conn_id`,
`sender_username`, `recipient_username`, `filename`, `size_bytes`, `timer_id`).
Never persisted.

`bot` (ADR-034): **reserved, not yet created by `RoomManager::createRoom()`** —
see `IMPLEMENTATION_STATUS.md` EPIC-034 (Planned). Target shape when EPIC-034.1
lands: `null` | RAM-only bot object (`username`, `cards`, `cards_count`,
`total_paid`, `immune`, `drawing`, `status`). Never an entry in `players`.
Never persisted.

`speed_mode` (ADR-035): `'slow'` \| `'fast'` (default `'slow'`). Set at
`create_room` only; frozen for the room lifetime. Client animation profile
only — not read by draw/AFK engine paths.

Test-only hook (ADR-022): `_apartment_participants` — leading underscore by
convention; never created by production code paths, only read defensively by
`ApartmentService::getParticipants()` if a test harness has already set it.
Not part of the production room lifecycle.

Room states: `waiting, playing, apartment, finished`.

## Player Structure Keys (allowed)
```
user_id, username, cards, cards_count, total_paid, last_action, host_activity_at, afk_start,
strikes, auto_draws, nudged_this_turn, status, session_token, reconnect_timer, connection, immune
```
Player states: `active, disconnected`.
Removal reasons: `leave, disconnect, afk, refuse, kicked, banned, admin_close`.

## Variable Conventions
- Cards: `$card` / `$cards`, count `$cardsCount`, mask `$mask` / `$masks`.
- Bag: `$bag`, `$drawnNumbers`, `$drawnAll`, current barrel `$currentNumber`.
- Economy: `$coins` (balance), `$bank`, `$prize`, `$share`, `$totalPaid`.
- Timers: global `$watchdogTimerId`; room `$room['lobby_afk_timer_id']`, `$room['game_afk_timer_id']`, `$room['apartment_timer_id']`; player `$player['reconnect_timer']`.

## Class Names (allowed only)
- Services: `AuthService, LoginThrottleService, IpAccountLimitService, LobbyService, GameService, VictoryService, ApartmentService, ReconnectService, AdminService, SessionService, ChatService, FileTransferService`
- Auth helpers: `PasswordPolicy` (ADR-033)
- Handlers: `AuthHandler, LobbyHandler, GameHandler, AdminHandler, ChatHandler`
- Core: `ConnectionManager, RoomManager, Logger, Constants`
- Infrastructure: `Database, PreparedStatements`
- Engine: `LottoEngine` with methods `generateCard(), generateBag()`

## Function Names (allowed only)
- Helpers: `sendJson(), sendError(), broadcastToRoom(), serverLog()`
- Room lifecycle: `createRoom(), destroyRoom()`
- Lobby: `joinRoom(), leaveRoom(), startGame(), transferHost()`
- Game: `drawBarrel(), processBarrel(), markNumber(), checkVictory(), triggerApartment(), nextDrawer()`
- Removal: `removePlayerFromLobby(), removePlayerFromGame(), removePlayerFromApartment()` (no generic `removePlayer()`)
- Reconnect: `handleDisconnect(), handleReconnect(), buildReconnectState()`
- Apartment: `startApartment(), finishApartment(), processApartmentChoice()`
- Victory: `checkCardVictory(), calculatePrize(), finishGame()`

## Protocol Packet Types (allowed)
```
hello, auth_result, error, room_list, room_joined, player_joined, player_left,
player_status_changed, host_changed, bank_updated, balance_updated, game_started,
your_turn, barrels_drawn, afk_warning, nudge_received, apartment_alert, reconnect_state, game_over,
banned, admin_stats_data, admin_users_data, admin_logs_data, admin_settings_data, admin_restart_result,
admin_change_password_result,
room_message, file_offer, file_accepted, file_rejected, file_data, file_offer_expired
```

`game_over.reason` values: `victory`, `last_survivor`, `no_survivors`.
ADR-034 `bot_win` is **registry-reserved** (not emitted until EPIC-034.3) —
see `IMPLEMENTATION_STATUS.md` EPIC-034 (Planned).

## Protocol Actions (allowed)
```
register, login, reconnect, ping, room_list, create_room, join_room, leave_room,
start_game, play_vs_bot, draw_barrel, turn_ready, nudge_turn, apartment_choice, admin_ban_user, admin_unban_user,
admin_kick_user, admin_close_room, admin_get_logs, admin_get_stats, admin_get_users,
admin_get_settings, admin_set_settings, admin_restart_server,
admin_change_password, admin_delete_user, admin_bulk_delete_users,
room_message, file_offer, file_accept, file_reject, file_data
```

`play_vs_bot` (ADR-034): **registry-reserved**, not dispatched until
EPIC-034.1 — see `IMPLEMENTATION_STATUS.md` EPIC-034 (Planned).
## Logging
Only `serverLog()`. Levels: `INFO, WARNING, ERROR`.

## Forbidden Naming Examples
```
removeUser(), deletePlayer(), kickUser(), roomID(), playerID(),
CreateRoom(), DRAW_BARREL(), game_state, gameStatus
```
