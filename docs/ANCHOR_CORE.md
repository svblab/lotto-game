# ANCHOR_CORE.md

## Purpose
This document is the consolidated Single Source of Truth (SSOT) for the "Russian Lotto" multiplayer browser game project. It merges and synchronizes all architectural, economic, structural, state, timer, and naming constraints from all anchor sub-files. All discrepancies and contradictions have been systematically resolved.

If any implementation or code contradicts this document, the code is considered erroneous and must be fixed.

---

# PART 1. ANCHOR_ARCHITECTURE.md

## Project
Multiplayer browser game "Russian Lotto"

Stack:
* PHP 8.x
* Workerman WebSocket
* SQLite3 (PDO)
* Vanilla JavaScript

Deployment:
* Ubuntu 22.04
* VPS (1 CPU / 500 MB RAM)
* WebSocket Port: 8080
* Single Workerman worker

---

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
```

---

## Runtime Memory Layout

```text
Worker
│
├── rooms
├── userConnections
├── db
└── logger
```

---

## userConnections Structure

```php
$worker->userConnections[
    userId
] = $connection;
```

Purpose:
* protection from double login
* fast user lookup
* reconnect support

---

## Room Structure

```php
$worker->rooms[$roomId] = [

    'room_id' => int,

    'host_conn_id' => int,

    'bet_per_card' => 10,

    'max_players' => int,

    'password_hash' => ?string,

    'status' => 'waiting'
              | 'playing'
              | 'apartment'
              | 'finished',

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

---

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

    'status' =>
        'active'
        | 'disconnected',

    'session_token' => string,

    'reconnect_timer' => null,

    'connection' => object,

    'immune' => bool
];
```

---

## Connection Runtime Fields

Each authenticated connection must contain:

```php
$connection->userId;
$connection->username;
$connection->isAdmin;

$connection->sessionToken;

$connection->lastPing;
```

---

## Room States

Allowed room states:

```text
waiting
playing
apartment
finished
```

No other states are allowed.

---

## Player States

Allowed player states:

```text
active
disconnected
```

No other states are allowed.
Removal reasons are not player states.

---

## Removal Reasons

Allowed reasons:

```text
leave
disconnect
afk
refuse
banned
kicked
admin_close
```

Reasons are transient events.
Reasons are never stored as player status.

---

## Ownership Rules

Host is always:
```php
host_conn_id
```

Current drawer is always:
```php
active_drawer_conn_id
```

These concepts must never be merged.

---

## Drawer Order Rules

The queue is stored in:
```php
drawer_order
```

Rules:
1. Host always starts first.
2. Remaining players are added FIFO.
3. Removed players are skipped.
4. Disconnected players are skipped.
5. Queue is cyclic.

---

## Room Destruction Rules

A room must be destroyed if:
1. No players remain.
2. Game finished.
3. Admin closed room.

Before destruction:
* all timers are cancelled;
* reconnect timers are cancelled;
* room removed from memory.

---

## Timer Registry

Room-level timers:
```php
game_afk_timer_id
apartment_timer_id
lobby_afk_timer_id
```

Player-level timers:
```php
reconnect_timer
```

No additional timer fields may be introduced without ADR.

---

## Database Ownership

SQLite is source of truth for:
* users
* passwords
* coins
* bans

RAM is source of truth for:
* rooms
* cards
* bags
* timers
* game state

---

## Logging Rules

Comments:
```text
Russian language
```

Logs:
```text
English language
```

Mandatory format:
```text
[YYYY-MM-DD HH:MM:SS] [LEVEL] message
```

---

## File Size Policy

Target sizes:
```text
server.php <= 500 lines

handler <= 300 lines

service <= 500 lines
```

If exceeded:
Create new module.
Do not grow existing file indefinitely.

---

## Architectural Rule

Business logic is forbidden inside:
* server.php
* bootstrap files

Business logic belongs only to:
* Handlers
* Services
* Engine

---

# PART 2. ANCHOR_ECONOMY.md

## Purpose
This section defines all coin movements.
If implementation contradicts this section, the economy specifications here are correct and the code must be fixed.

---

## Currency

Single currency:
```text
coins
```

Fractional values are forbidden.
All operations use integers.

---

## Source of Truth

User balance is stored only in SQLite.
```sql
users.coins
```

RAM copies are informational.
SQLite balance is authoritative.

---

## Initial Balance

New user:
```text
500 coins
```

---

## Daily Bonus

Reward:
```text
100 coins
```

Requirements:
```text
user is not admin
86400 seconds passed
```

Applied only during login.

---

## Card Price

Fixed value:
```text
BET_PER_CARD = 10
```

---

## Room Entry Cost

Player chooses:
```text
1 card
or
2 cards
```

Cost:
```text
cards_count * BET_PER_CARD
```

Examples:
```text
1 card = 10
2 cards = 20
```

---

## Reservation Rule

Joining room does not deduct coins.
Creating room does not deduct coins.
Coins remain on user balance.

---

## Start Game Deduction

Coins are deducted only during:
```text
startGame()
```

For every player:
```text
coins -= total_paid
```

Transaction required.
All deductions succeed, or all deductions fail. Partial deduction is forbidden.

---

## Bank Creation

Initial bank:
```text
sum(all total_paid)
```

Example:
```text
player1 = 10
player2 = 20
player3 = 10

bank = 40
```

---

## Bank Ownership

Bank belongs to room.
Not to host.
Not to drawer.
Not to winner.
Until game end.

---

## Apartment Payment

Apartment may trigger once.
Maximum:
```text
1 time per game
```

Required player:
```text
agree
```

adds:
```text
10 coins
```
to bank.

Effects:
```text
bank += 10

player.total_paid += 10
```

Transaction required.

---

## Apartment Refusal

Refusal causes removal.
Already paid coins remain in bank.
No refund.

---

## Apartment Timeout

Equivalent to:
```text
refuse
```

---

## Disconnect

Disconnect does not refund money.
Player remains eligible for reconnect.

---

## Reconnect Timeout

Reason:
```text
disconnect
```

Coins remain in bank.
No refund.

---

## Leave During Game

Reason:
```text
leave
```

Coins remain in bank.
No refund.

---

## AFK Removal

Reason:
```text
afk
```

Coins remain in bank.
No refund.

---

## Ban

Reason:
```text
banned
```

Coins remain in bank.
No refund.

---

## Kick

Reason:
```text
kicked
```

Player receives refund.
Refund amount:
```text
player.total_paid
```

Effects:
```text
bank -= total_paid

coins += total_paid
```

Transaction required.

---

## Admin Close Room

Reason:
```text
admin_close
```

All players receive:
```text
100% refund
```
including:
```text
apartment payments
```

Refund source:
```text
all_players_history
```

---

## Victory Condition

Player wins if:
```text
all 15 numbers
on at least one card
are closed
```

Victory immediately ends game.

---

## Normal Victory

One winner.
Prize:
```text
entire bank
```

Effects:
```text
winner.coins += bank
```

After payout:
```text
bank = 0
```

---

## Double Victory

Definition:
Two cards of same player complete during same draw action.

Weight:
```text
2 shares
```

Normal winner:
```text
1 share
```

Formula:
```text
floor(
 bank
 /
(total_shares)
)
```

Example:
```text
bank = 100

playerA double win

shares = 2

reward = floor(100 / 2)

playerA receives 100
```

Example:
```text
bank = 100

playerA double win
playerB normal win

shares = 3

share = floor(100 / 3)

playerA receives 66

playerB receives 33
```

Remainder:
```text
burned
```
Never distributed.
Never stored.

---

## Apartment vs Victory

Priority:
```text
Victory
>
Apartment
```

If same barrel causes both:
Victory wins.
Apartment ignored.
No additional payments.

---

## Last Survivor

Condition:
Exactly one active player remains.

Prize:
```text
entire bank
```

Effects:
```text
winner.coins += bank
```

After payout:
```text
bank = 0
```

---

## No Survivors

Condition:
Zero active players remain.

Action:
Refund all participants.

Source:
```text
all_players_history
```

Refund amount:
```text
player.total_paid
```
including:
```text
apartment payments
```

After refund:
```text
bank = 0
```

Room destroyed.

---

## Economic Integrity Rule

At any time:
```text
bank
+
sum(user balances)
+
burned remainder
```
must be explainable.

Coin creation is forbidden.
Coin duplication is forbidden.
Coin deletion is forbidden.

Except:
```text
daily bonus

burned division remainder
```
which are intentional mechanics.

---

## Mandatory Transactions

SQLite transaction required for:
```text
startGame()

apartment payment

kick refund

admin_close_room

victory payout

last_survivor payout

zero-survivor refund
```

No economic operation may update `bank` and `users.coins` independently. Both changes must succeed together, or fail together.

---

# PART 3. ANCHOR_FILE_STRUCTURE.md

## Purpose
This section defines the mandatory project structure. If implementation contradicts this section, the file structure here is correct and the code must be reorganized.

---

## Project Root

```text
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

---

## Architectural Rule

Business logic is forbidden inside:
```text
server.php
init_db.php
```
These files are bootstrap only.

Allowed responsibilities for `server.php`:
* Workerman startup
* dependency wiring
* handler registration

Allowed responsibilities for `init_db.php`:
* database initialization
* schema creation
* admin creation

---

## Source Directory

```text
src/

├── Core/
├── Auth/
├── Lobby/
├── Game/
├── Admin/
├── Infrastructure/
```

---

## Core Module

```text
src/Core/

ConnectionManager.php
RoomManager.php
Logger.php

Helpers.php
Constants.php
```

Responsibilities:
```text
room lookup
user lookup
helper functions
constants
logging
```

Forbidden:
```text
game logic
economy logic
admin logic
```

---

## Auth Module

```text
src/Auth/

AuthHandler.php

AuthService.php

SessionService.php
```

Responsibilities:
```text
register
login
logout
session tokens
daily bonus
```

Forbidden:
```text
room logic
game logic
```

---

## Lobby Module

```text
src/Lobby/

LobbyHandler.php

LobbyService.php
```

Responsibilities:
```text
room creation
room join
room leave

host transfer

lobby AFK
```

Forbidden:
```text
draw barrel

victory

apartment
```

---

## Game Module

```text
src/Game/

GameHandler.php

GameService.php

LottoEngine.php

VictoryService.php

ApartmentService.php

ReconnectService.php
```

Responsibilities:
```text
game start

draw barrel

mark numbers

victory detection

apartment

reconnect
```

Forbidden:
```text
authentication

admin actions
```

---

## LottoEngine

Purpose: Pure mathematics.

Allowed:
```text
card generation

bag generation
```

Forbidden:
```text
database

connections

rooms

timers
```

---

## VictoryService

Purpose: Victory calculations only.

Allowed:
```text
victory detection

double victory

share calculation
```

Forbidden:
```text
socket sending

database access
```

---

## ApartmentService

Purpose: Apartment mechanics only.

Allowed:
```text
line detection

pause logic

response tracking
```

Forbidden:
```text
victory logic

authentication
```

---

## ReconnectService

Purpose: Reconnect management.

Allowed:
```text
disconnect handling

reconnect lookup

state restore
```

Forbidden:
```text
game start

victory
```

---

## Admin Module

```text
src/Admin/

AdminHandler.php

AdminService.php
```

Responsibilities:
```text
kick

ban

unban

close room

logs
```

Forbidden:
```text
game mechanics
```

---

## Infrastructure Module

```text
src/Infrastructure/

Database.php

PreparedStatements.php
```

Responsibilities:
```text
PDO initialization

statement cache
```

Forbidden:
```text
business logic
```

---

## Public Directory

```text
public/

index.html

css/
js/
img/
locales/
```

---

## CSS

```text
public/css/

style.css
```
Single entry point.

---

## JavaScript

```text
public/js/

app.js

ws.js

ui.js

i18n.js
```

Responsibilities:
* `app.js`: application bootstrap
* `ws.js`: websocket layer
* `ui.js`: screen rendering
* `i18n.js`: translations

---

## Locales

```text
public/locales/

en.json
ru.json
es.json
fr.json
zh.json
tr.json
```

---

## Documentation

```text
docs/

ANCHOR_CORE.md

ANCHOR_PROTOCOL.md

ANCHOR_RULES.md

IMPLEMENTATION_STATUS.md

ADR/
```

---

## Logs

```text
logs/

server.log
```
Rotated logs:
```text
server_YYYYMMDD.log
```

---

## Patches

```text
patches/
```
Purpose: Store all generated diffs.
Format:
```text
EPIC-3.4.patch
EPIC-4.2.patch
```

---

## Tests

```text
tests/
```
Contains:
```text
manual scenarios

test cases

future automation
```

---

## File Size Limits

Target: `300-500 lines` per file.
Warning threshold: `700 lines`
Mandatory refactoring threshold: `1000 lines`
No file may exceed: `1500 lines` without ADR.

---

## Epic Modification Rule

Single Epic may modify: `1-3 files` normally.
If Epic requires `4+ files`, the model must stop.
Required response:
```text
Epic is too large.

Additional decomposition required.
```

---

## Patch Rule

Code changes must be delivered as: `diff -u`
Returning full file content is forbidden, except for new file creation or explicit user request.

---

## Dependency Direction

Allowed:
```text
Core
 ↑
Auth
Lobby
Game
Admin
```

Forbidden:
```text
Game -> Auth

Admin -> Game internals

Lobby -> Apartment internals
```

Modules communicate only through services, public methods, and events. No direct access to private internals.

---

# PART 4. ANCHOR_STATE_MACHINE.md

## Purpose
This section defines all valid runtime states. If implementation contradicts this section, the state machine rules here are correct and the code must be fixed. No implicit states or hidden transitions may be introduced.

---

## Part 1. Room State Machine

Allowed room states:
```text
waiting
playing
apartment
finished
```
No additional room states are allowed.

### ROOM STATE: waiting
Meaning: Room exists. Game has not started. Cards are not generated. Bank equals zero.
Allowed actions: `room_list`, `join_room`, `leave_room`, `start_game`, `reconnect`, `ping`
Forbidden actions: `draw_barrel`, `apartment_choice`
Possible transitions:
```text
waiting ├─ start_game ──► playing
waiting ├─ no players remain ──► destroyed
waiting ├─ admin_close_room ──► destroyed
```

### ROOM STATE: playing
Meaning: Main game loop active. Cards exist. Bag exists. Bank exists. Current drawer exists.
Allowed actions: `draw_barrel`, `leave_room`, `ping`, `reconnect`
Forbidden actions: `join_room`, `start_game`, `apartment_choice`
Possible transitions:
```text
playing ├─ apartment detected ──► apartment
playing ├─ winner found ──► finished
playing ├─ last survivor ──► finished
playing ├─ admin_close_room ──► destroyed
playing ├─ no active players ──► destroyed
```

### ROOM STATE: apartment
Meaning: Apartment event active. Game loop paused. No barrel drawing allowed. Waiting for required responses.
Allowed actions: `apartment_choice`, `ping`
Forbidden actions: `draw_barrel`, `start_game`, `join_room`
Reconnect: `forbidden`
Possible transitions:
```text
apartment ├─ all required responses received ──► playing
apartment ├─ apartment timer expired ──► playing
apartment ├─ winner found ──► finished
apartment ├─ last survivor ──► finished
apartment ├─ admin_close_room ──► destroyed
```

### ROOM STATE: finished
Meaning: Game result finalized. Prizes distributed. No gameplay allowed.
Allowed actions: `none`
Immediately after entering: `destroy room`
Possible transitions:
```text
finished ──► destroyed
```

---

## Part 2. Player State Machine

Allowed player states:
```text
active
disconnected
```
No other player states are allowed. Removal reasons are not player states.

### PLAYER STATE: active
Meaning: Player currently connected. May perform actions.
Possible transitions:
```text
active ├─ connection lost ──► disconnected
active ├─ leave ──► removed
active ├─ afk ──► removed
active ├─ refuse ──► removed
active ├─ kicked ──► removed
active ├─ banned ──► removed
```

### PLAYER STATE: disconnected
Meaning: Player temporarily absent. Reconnect timer active.
Allowed actions: `reconnect`
Forbidden actions: `draw_barrel`, `apartment_choice`, `leave_room`
Possible transitions:
```text
disconnected ├─ reconnect ──► active
disconnected ├─ timeout ──► removed
```

---

## Part 3. Reconnect Rules

Reconnect is allowed only if:
```text
room.state == waiting
```
or
```text
room.state == playing
```

Reconnect is forbidden if:
```text
room.state == apartment
```
or
```text
room.state == finished
```

---

## Part 4. Removal Rules

Removal is not a state; removal is an event.
Allowed reasons: `leave`, `disconnect`, `afk`, `refuse`, `kicked`, `banned`, `admin_close`
After removal, the player must not remain in `$room['players']`.
The player may remain only in `$room['all_players_history']`.

---

## Part 5. Host Rules

Host ownership: `host_conn_id`
Host may change only if: host leaves, host disconnected permanently, host removed, host banned, host kicked, or host afk removed.
New host: next active player in FIFO order.

---

## Part 6. Drawer Rules

Drawer ownership: `active_drawer_conn_id`
Drawer may change on: successful draw, afk auto draw, or drawer removal.
Host and drawer are independent concepts and may refer to different players.

---

## Part 7. Apartment Priority

Victory has higher priority than apartment. If the same barrel causes victory and apartment, the result is victory; apartment must not start.

---

## Part 8. Room Destruction

Room destruction is terminal. After destruction, `unset($worker->rooms[$roomId]);` must be executed.
All room timers, reconnect timers, and AFK timers are cancelled. Destroyed rooms cannot be restored; a new room must be created.

---

# PART 5. ANCHOR_TIMERS.md

## Purpose
This section defines all timers used by the server. If implementation contradicts this section, the timer specification here is correct and the code must be fixed. No additional timer types may be introduced.

---

## General Rules

Timer implementation:
```php
use Workerman\Timer;
```

Allowed timer types:
```text
watchdog
lobby_afk
game_afk
apartment
reconnect
```
No other timer types are allowed.

---

## Timer Ownership Rule

Every timer must have exactly one owner: `connection`, `player`, `room`, or `server`.
Timers must be cancellable and all timer IDs must be stored. Anonymous unmanaged timers are forbidden.

---

## Timer Storage

Room-level timers:
```php
$room['lobby_afk_timer_id']
$room['game_afk_timer_id']
$room['apartment_timer_id']
```

Player-level timers:
```php
$player['reconnect_timer']
```
No timer IDs may be stored elsewhere.

---

## Global Watchdog Timer

Owner: `server`
Count: exactly one for entire process.
Interval: `60 seconds`
Purpose: Close dead connections.

Checks:
Authorized connection: `now - lastPing > 120` -> connection close.
Unauthorized connection: `now - lastPing > 60` -> connection close.
Created: `onWorkerStart`
Destroyed: worker shutdown

---

## Lobby AFK Timer

Owner: `room`
Exists only in `waiting` state.
Purpose: Prevent inactive host.
Created when: room has `>= 2 players` and host becomes responsible for starting game.
Interval: `1 second repeat`
Checks: `time() - host.last_action`
Threshold: `120 seconds`
Action: Transfer host ownership to the next active player in FIFO order.
Destroyed when: game starts, room destroyed, or player count < 2.
Only one `lobby_afk` timer may exist per room.

---

## Game AFK Timer

Owner: `room`
Exists only in `playing` state.
Purpose: Protect turn order.
Count: exactly one per room.
Interval: `1 second repeat`
Tracked player: `active_drawer_conn_id`
Created when: first `your_turn` sent. Also reused after turn change.
Timer is never recreated; it continuously runs while the target player changes.

---

## Game AFK Thresholds

Measure: `time() - player.afk_start`

Threshold 1 (15 seconds): `strike = 1`, warning sent.
Threshold 2 (25 seconds): `strike = 2`, warning sent.
Threshold 3 (30 seconds): auto draw executed, `auto_draws++`, `strikes = 0`.

Three auto draws (`auto_draws >= 3`): `removePlayerFromGame(..., 'afk')`.
Successful manual draw: `auto_draws = 0`, `strikes = 0`.
Destroyed when: room leaves `playing` state or room is destroyed.

---

## Apartment Timer

Owner: `room`
Exists only in `apartment` state.
Count: maximum one per room.
Created when: apartment starts.
Duration: `10 seconds` single-shot.
Purpose: Limit response time.
Expiration effect: All unanswered required players become `refuse`. Game is then resumed or finished according to the state machine.
Destroyed when: all required responses received, room destroyed, or timer expires.

---

## Reconnect Timer

Owner: `player`
Exists only for `disconnected` players.
Created when: connection lost and `room.state == waiting` or `room.state == playing`.
Duration: `15 seconds` single-shot.
Expiration action: `removePlayerFromLobby(...)` or `removePlayerFromGame(...)` with reason `disconnect`.
Destroyed when: player reconnects, player removed, or room destroyed.
Reconnect timer is forbidden in `apartment` state.

---

## Timer State Restrictions

* `waiting`: `watchdog`, `lobby_afk`, `reconnect` allowed.
* `playing`: `watchdog`, `game_afk`, `reconnect` allowed.
* `apartment`: `watchdog`, `apartment` allowed.
* `finished`: `watchdog` only.

---

## Room Destruction Cleanup

Before `unset($worker->rooms[$roomId]);`, the following cleanup must execute:

```php
if (!empty($room['lobby_afk_timer_id']))
    Timer::del($room['lobby_afk_timer_id']);

if (!empty($room['game_afk_timer_id']))
    Timer::del($room['game_afk_timer_id']);

if (!empty($room['apartment_timer_id']))
    Timer::del($room['apartment_timer_id']);
```

Then:
```php
foreach ($room['players'] as $player)
{
    if (!empty($player['reconnect_timer']))
    {
        Timer::del($player['reconnect_timer']);
    }
}
```

---

## Timer Integrity Rules

* A timer may not exist without an owner.
* A destroyed owner may not keep timers.
* A timer may not be created twice.
* A timer may not survive room destruction.
* A reconnect timer may not survive player removal.
* A room may not have two `game_afk` timers, two `apartment` timers, or two `lobby_afk` timers simultaneously.

---

## Mandatory Validation
During code review, every timer must answer: Who creates it? Who owns it? Who destroys it? What happens if the owner disappears? If any answer is unknown, the implementation is invalid.

---

# PART 6. NAMING_REGISTRY.md

## Purpose
Single source of truth for all project names. If implementation introduces alternative naming, this registry is correct and the code must be fixed.

---

## General Rules

Language: `English only`
Variables: `camelCase` (e.g., `$userId`, `$roomId`, `$cardsCount`)
Array keys: `snake_case` (e.g., `room_id`, `host_conn_id`, `cards_count`, `session_token`)
Methods: `camelCase` (e.g., `startGame()`, `removePlayerFromGame()`)
Classes: `PascalCase` (e.g., `GameService`, `RoomManager`)
Constants: `UPPER_SNAKE_CASE` (e.g., `MAX_ROOMS`, `MAX_TOTAL_PLAYERS`, `BET_PER_CARD`)

---

## Database Names

### Table: users
Fields:
```sql
id
username
password_hash
coins
is_admin
banned_until
last_daily_bonus
```
No alternative names allowed.

---

## Global Constants

```php
MAX_ROOMS
MAX_TOTAL_PLAYERS
BET_PER_CARD
DAILY_BONUS
RECONNECT_TIMEOUT
LOBBY_HOST_TIMEOUT
UNAUTHORIZED_TIMEOUT
AUTHORIZED_TIMEOUT
PROTOCOL_VERSION
```

---

## Connection Properties

Connection object: `$connection`
Allowed properties:
```php
$connection->userId
$connection->username
$connection->isAdmin
$connection->sessionToken
$connection->lastPing
```
No additional business fields allowed.

---

## Worker Storage

Rooms: `$worker->rooms`
User connections: `$worker->userConnections`
* Key: `userId`
* Value: `$connection`

---

## Room Structure Keys

Room variable: `$room`
Room identifier: `$roomId`

Allowed keys:
```php
room_id
host_conn_id
bet_per_card
max_players
password_hash
status
bank
apartment_fired
pause_for_apartment
apartment_responses
active_drawer_conn_id
drawer_order
bag
drawn_numbers
players
all_players_history
lobby_afk_timer_id
game_afk_timer_id
apartment_timer_id
```
No additional persistent keys allowed without ADR.

---

## Room States
Allowed values: `waiting`, `playing`, `apartment`, `finished`

---

## Player Structure Keys

Player variable: `$player`
Allowed keys:
```php
user_id
username
cards
cards_count
total_paid
last_action
afk_start
strikes
auto_draws
status
session_token
reconnect_timer
connection
immune
```

---

## Player States
Allowed values: `active`, `disconnected`

---

## Player Removal Reasons
Allowed values: `leave`, `disconnect`, `afk`, `refuse`, `kicked`, `banned`, `admin_close`

---

## Card Variables
* Single card: `$card`
* Multiple cards: `$cards`
* Card count: `$cardsCount`
* Mask: `$mask`
* Multiple masks: `$masks`

---

## Bag Variables
* Bag: `$bag`
* Drawn numbers: `$drawnNumbers`
* All drawn history: `$drawnAll`
* Current barrel: `$currentNumber`

---

## Economy Variables
* User balance: `$coins`
* Room bank: `$bank`
* Prize: `$prize`
* Share: `$share`
* Total payment: `$totalPaid`

---

## Service Classes
Allowed names:
```php
AuthService
LobbyService
GameService
VictoryService
ApartmentService
ReconnectService
AdminService
SessionService
```

---

## Handler Classes
Allowed names:
```php
AuthHandler
LobbyHandler
GameHandler
AdminHandler
```

---

## Core Classes
Allowed names:
```php
ConnectionManager
RoomManager
Logger
Constants
```

---

## Infrastructure Classes
Allowed names:
```php
Database
PreparedStatements
```

---

## Lotto Engine
Class: `LottoEngine`
Methods:
```php
generateCard()
generateBag()
```
No alternative names allowed.

---

## Mandatory Helper Functions
Allowed names:
```php
sendJson()
sendError()
broadcastToRoom()
serverLog()
```

---

## Room Lifecycle Functions
Allowed names:
```php
createRoom()
destroyRoom()
```

---

## Lobby Functions
Allowed names:
```php
joinRoom()
leaveRoom()
startGame()
transferHost()
```

---

## Game Functions
Allowed names:
```php
drawBarrel()
processBarrel()
markNumber()
checkVictory()
triggerApartment()
nextDrawer()
```

---

## Removal Functions
Allowed names:
```php
removePlayerFromLobby()
removePlayerFromGame()
removePlayerFromApartment()
```
No generic `removePlayer()` allowed.

---

## Reconnect Functions
Allowed names:
```php
handleDisconnect()
handleReconnect()
buildReconnectState()
```

---

## Apartment Functions
Allowed names:
```php
startApartment()
finishApartment()
processApartmentChoice()
```

---

## Victory Functions
Allowed names:
```php
checkCardVictory()
calculatePrize()
finishGame()
```

---

## Timer Variables
* Global timer: `$watchdogTimerId`
* Room timers:
  * `$room['lobby_afk_timer_id']`
  * `$room['game_afk_timer_id']`
  * `$room['apartment_timer_id']`
* Player timer: `$player['reconnect_timer']`

---

## Protocol Packet Types
Allowed packet names:
```text
hello
auth_result
error
room_list
room_joined
player_joined
player_left
game_started
your_turn
barrels_drawn
apartment_alert
reconnect_state
game_over
banned
admin_stats_data
admin_logs_data
```

---

## Protocol Actions
Allowed action names:
```text
register
login
reconnect
ping
room_list
create_room
join_room
leave_room
start_game
draw_barrel
apartment_choice
admin_ban_user
admin_unban_user
admin_kick_user
admin_close_room
admin_get_logs
```

---

## Logging Function
Only `serverLog()` allowed.
Log levels: `INFO`, `WARNING`, `ERROR`

---

## Forbidden Naming
Forbidden examples:
```php
removeUser()
deletePlayer()
kickUser()
roomID()
playerID()
CreateRoom()
DRAW_BARREL()
game_state
gameStatus
```

Use only names defined in this registry. Any new name affecting architecture, protocol, economy, timers or state machine requires ADR approval.
