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
  'game_afk_timer_id' => null,
  'apartment_timer_id' => null,
  'lobby_afk_timer_id' => null,
  'active_drawer_conn_id' => null,
  'drawer_order' => [],
  'bag' => [],
  'drawn_numbers' => [],
  'players' => [],
  'all_players_history' => []
];
```

## Player Structure
```php
$room['players'][$connId] = [
  'user_id' => int,
  'username' => string,
  'cards' => [],
  'cards_count' => 1|2,
  'total_paid' => int,
  'last_action' => int,
  'afk_start' => null,
  'strikes' => 0,
  'auto_draws' => 0,
  'status' => 'active'|'disconnected',
  'session_token' => string,
  'reconnect_timer' => null,
  'connection' => object,
  'immune' => bool
];
```

## Connection Runtime Fields
```php
$connection->userId;
$connection->username;
$connection->isAdmin;
$connection->sessionToken;
$connection->lastPing;
$connection->packetCount;       // ADR-003: rate limiting, окно 1s
$connection->packetWindowStart; // ADR-003: rate limiting, окно 1s
```
No additional business fields allowed beyond those listed here (see ADR-003 for the rate-limiting pair).

## Room States
Allowed: `waiting | playing | apartment | finished`. No others.

## Player States
Allowed: `active | disconnected`. No others. Removal reasons are NOT states.

## Removal Reasons
Allowed: `leave, disconnect, afk, refuse, banned, kicked, admin_close`. Transient events, never stored as player status.

## Ownership Rules
Host = `host_conn_id`. Current drawer = `active_drawer_conn_id`. Never merge these concepts.

## Drawer Order Rules
Stored in `drawer_order`:
1. Host always starts first.
2. Remaining players added FIFO.
3. Removed players skipped.
4. Disconnected players skipped.
5. Queue is cyclic.

## Room Destruction Rules
Destroy room if: no players remain | game finished | admin closed room.
Before destruction: cancel all timers (room + reconnect), remove room from memory.

## Timer Registry
Room-level: `game_afk_timer_id, apartment_timer_id, lobby_afk_timer_id`.
Player-level: `reconnect_timer`.
No additional timer fields without ADR.

## Database Ownership
SQLite = source of truth for: users, passwords, coins, bans.
RAM = source of truth for: rooms, cards, bags, timers, game state.

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
Causes removal (reason `refuse`). Already-paid coins remain in bank — no refund.

## Apartment Timeout
Equivalent to `refuse`.

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
Exactly one active player remains → takes entire bank: `winner.coins += bank; bank = 0`.

## No Survivors
Zero active players remain → refund all participants (from `all_players_history`) their `total_paid` (including apartment payments). `bank = 0`. Room destroyed.

## Economic Integrity Rule
At any time, `bank + sum(user balances) + burned remainder` must be explainable. Coin creation/duplication/deletion forbidden, except daily bonus and burned division remainder (intentional mechanics).

## Mandatory Transactions
SQLite transaction required for: `startGame()`, apartment payment, kick refund, `admin_close_room`, victory payout, last_survivor payout, zero-survivor refund. No operation may update `bank` and `users.coins` independently — both succeed or both fail.

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
src/Core/ Auth/ Lobby/ Game/ Admin/ Infrastructure/
```

### Core (ConnectionManager.php, RoomManager.php, Logger.php, Helpers.php, Constants.php)
Responsibilities: room/user lookup, helpers, constants, logging.
Forbidden: game/economy/admin logic.

### Auth (AuthHandler.php, AuthService.php, SessionService.php)
Responsibilities: register, login, logout, session tokens, daily bonus.
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
Allowed: Core ← Auth, Lobby, Game, Admin.
Forbidden: Game→Auth, Admin→Game internals, Lobby→Apartment internals.
Modules communicate only via services/public methods/events — no private internals access.

---

# PART 4. STATE MACHINE

If code contradicts this section, the spec here is correct — fix the code. No implicit states/hidden transitions.

## Room State Machine
Allowed states: `waiting | playing | apartment | finished`. No others.

**waiting**: Room exists, game not started, no cards, bank=0.
Allowed: `room_list, join_room, leave_room, start_game, reconnect, ping`.
Forbidden: `draw_barrel, apartment_choice`.
Transitions: `start_game → playing`; `no players remain → destroyed`; `admin_close_room → destroyed`.

**playing**: Main loop active, cards/bag/bank/drawer exist.
Allowed: `draw_barrel, leave_room, ping, reconnect`.
Forbidden: `join_room, start_game, apartment_choice`.
Transitions: `apartment detected → apartment`; `winner found → finished`; `last survivor → finished`; `admin_close_room → destroyed`; `no active players → destroyed`.

**apartment**: Apartment event active, loop paused, no barrel drawing, waiting on required responses.
Allowed: `apartment_choice, ping`. Forbidden: `draw_barrel, start_game, join_room`. Reconnect forbidden.
Transitions: `all required responses received → playing`; `apartment timer expired → playing`; `winner found → finished`; `last survivor → finished`; `admin_close_room → destroyed`.

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
Drawer ownership = `active_drawer_conn_id`. Changes on: successful draw, afk auto draw, or drawer removal. Host and drawer are independent.

## Apartment Priority
Victory > apartment. Same barrel causing both → victory; apartment must not start.

## Room Destruction
Terminal. `unset($worker->rooms[$roomId])` executed. All room/reconnect/AFK timers cancelled. Destroyed rooms cannot be restored.

---

# PART 5. TIMERS

If code contradicts this section, the spec here is correct — fix the code. No additional timer types without ADR.

## General Rules
Implementation: `Workerman\Timer`.
Allowed types: `watchdog, lobby_afk, game_afk, apartment, reconnect`. No others.

## Timer Ownership
Every timer has exactly one owner: connection, player, room, or server. All timer IDs stored and cancellable. No anonymous/unmanaged timers.

## Timer Storage
Room-level: `lobby_afk_timer_id, game_afk_timer_id, apartment_timer_id`.
Player-level: `reconnect_timer`. No timer IDs stored elsewhere.

## Global Watchdog Timer
Owner: server. Count: 1 for entire process. Interval: 60s. Purpose: close dead connections.
Checks: authorized `now-lastPing>120` → close; unauthorized `now-lastPing>60` → close.
Created: `onWorkerStart`. Destroyed: worker shutdown.

## Lobby AFK Timer
Owner: room. Exists only in `waiting`. Purpose: prevent inactive host.
Created when: room has `>=2 players` and host responsible for starting.
Interval: 1s repeat. Check: `time()-host.last_action`. Threshold: 120s.
Action: transfer host to next active player FIFO.
Destroyed when: game starts, room destroyed, or player count <2. Max one per room.

## Game AFK Timer
Owner: room. Exists only in `playing`. Count: exactly 1/room. Interval: 1s repeat.
Tracks: `active_drawer_conn_id`. Created on first `your_turn`, reused after turn change — never recreated.

### Thresholds (measured as `time()-player.afk_start`)
- 15s: strike=1, warning sent.
- 25s: strike=2, warning sent.
- 30s: auto draw, `auto_draws++`, `strikes=0`.
- `auto_draws>=3`: `removePlayerFromGame(..., 'afk')`.
- Successful manual draw: `auto_draws=0, strikes=0`.
Destroyed when room leaves `playing` or room destroyed.

## Apartment Timer
Owner: room. Exists only in `apartment`. Max 1/room. Created on apartment start. Duration: 10s single-shot.
Purpose: limit response time. Expiration: unanswered required players → `refuse`; game resumes/finishes per state machine.
Destroyed when: all required responses received, room destroyed, or expires.

## Reconnect Timer
Owner: player. Exists only for `disconnected`. Created on connection loss when `room.state ∈ {waiting, playing}`.
Duration: 15s single-shot. Expiration → `removePlayerFromLobby(...)` / `removePlayerFromGame(...)` reason `disconnect`.
Destroyed when: player reconnects, removed, or room destroyed. Forbidden in `apartment` state.

## Timer State Restrictions
- `waiting`: watchdog, lobby_afk, reconnect.
- `playing`: watchdog, game_afk, reconnect.
- `apartment`: watchdog, apartment.
- `finished`: watchdog only.

## Room Destruction Cleanup
Before `unset($worker->rooms[$roomId])`:
```php
if (!empty($room['lobby_afk_timer_id'])) Timer::del($room['lobby_afk_timer_id']);
if (!empty($room['game_afk_timer_id']))  Timer::del($room['game_afk_timer_id']);
if (!empty($room['apartment_timer_id'])) Timer::del($room['apartment_timer_id']);
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
All PHP classes belong to `Lotto\...` (e.g. `Lotto\Core`, `Lotto\Auth`, `Lotto\Lobby`, `Lotto\Game`, `Lotto\Admin`, `Lotto\Infrastructure`).
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
RATE_LIMIT_PACKETS_PER_WINDOW, RATE_LIMIT_WINDOW_SECONDS
```

## Connection Properties
`$connection->userId, ->username, ->isAdmin, ->sessionToken, ->lastPing, ->packetCount, ->packetWindowStart` (последние два — ADR-003, rate limiting). No additional business fields.

## Worker Storage
`$worker->rooms`, `$worker->userConnections` (key=`userId`, value=`$connection`).

## Room Structure Keys (allowed, no others without ADR)
```
room_id, host_conn_id, bet_per_card, max_players, password_hash, status, bank,
apartment_fired, pause_for_apartment, apartment_responses, active_drawer_conn_id,
drawer_order, bag, drawn_numbers, players, all_players_history,
lobby_afk_timer_id, game_afk_timer_id, apartment_timer_id
```
Room states: `waiting, playing, apartment, finished`.

## Player Structure Keys (allowed)
```
user_id, username, cards, cards_count, total_paid, last_action, afk_start,
strikes, auto_draws, status, session_token, reconnect_timer, connection, immune
```
Player states: `active, disconnected`.
Removal reasons: `leave, disconnect, afk, refuse, kicked, banned, admin_close`.

## Variable Conventions
- Cards: `$card` / `$cards`, count `$cardsCount`, mask `$mask` / `$masks`.
- Bag: `$bag`, `$drawnNumbers`, `$drawnAll`, current barrel `$currentNumber`.
- Economy: `$coins` (balance), `$bank`, `$prize`, `$share`, `$totalPaid`.
- Timers: global `$watchdogTimerId`; room `$room['lobby_afk_timer_id']`, `$room['game_afk_timer_id']`, `$room['apartment_timer_id']`; player `$player['reconnect_timer']`.

## Class Names (allowed only)
- Services: `AuthService, LobbyService, GameService, VictoryService, ApartmentService, ReconnectService, AdminService, SessionService`
- Handlers: `AuthHandler, LobbyHandler, GameHandler, AdminHandler`
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
game_started, your_turn, barrels_drawn, apartment_alert, reconnect_state,
game_over, banned, admin_stats_data, admin_logs_data
```

## Protocol Actions (allowed)
```
register, login, reconnect, ping, room_list, create_room, join_room, leave_room,
start_game, draw_barrel, apartment_choice, admin_ban_user, admin_unban_user,
admin_kick_user, admin_close_room, admin_get_logs
```

## Logging
Only `serverLog()`. Levels: `INFO, WARNING, ERROR`.

## Forbidden Naming Examples
```
removeUser(), deletePlayer(), kickUser(), roomID(), playerID(),
CreateRoom(), DRAW_BARREL(), game_state, gameStatus
```
