# 034 — Bot opponent (human vs computer, single-player room mode)

## Status

accepted

## Context

Stakeholders want a host-only “Play vs Bot” mode: one human plays against a
server-controlled opponent in an otherwise normal room. Confirmed constraints
from Q&A:

- The bot is **RAM-only**, not a row in `$room['players']`, not in SQLite, and
  has no `user_id` / `session_token` / coins.
- Exactly one bot per room, only in human-vs-computer mode (never beside a
  second human).
- Bot stake is always `total_paid = 0` and never enters the bank; bot always
  plays with `cards_count = 2`.
- Bot turns have no `your_turn` / `turn_ready` / Game AFK; the server draws
  immediately on the bot’s turn (client still receives normal `barrels_drawn`
  and plays the slot animation).
- Apartment: bot immune if its row closes first; if the human’s row closes
  first, the bot is force-removed (`refuse`) immediately and cannot pay.
- Economy exceptions: bank **burns** on bot win; a RAM-only win streak can
  **mint** a double-bank bonus on the 3rd consecutive human win vs bot.
- ReconnectService needs no bot-aware disconnect/reconnect path.

ANCHOR constraints:

- Rule 7 / Part 1 Room Structure / Part 6 registries: new room key, protocol
  action/reason, and worker storage require this ADR before coding.
- Part 2 Economic Integrity Rule: coin creation/deletion forbidden except
  documented mechanics — bank-burn and streak mint must be named explicitly.
- Part 4 state machine: no new room/player states
  (`waiting | playing | apartment | finished` / `active | disconnected` only).
- Rule 11: decompose into separate Epics (do not ship as one diff).

**Numbering note:** ADR-033 is already taken (admin password rotation / account
management). This decision is **ADR-034**.

### Implementation risk (highest)

Every engine path that today iterates only `$room['players']` will miss the
bot unless it gains an **explicit parallel branch**. That includes turn
rotation, mark/victory, apartment required/immune, survivor counts, bank
formation, win-chance calculation, and roster packets. Coding must not start
until the per-subsystem fold-in below is followed.

---

## Decision

### 1. Entity model — `$room['bot']`

New nullable Room Structure key (default `null` on every room created by
`RoomManager::createRoom`):

```php
$room['bot'] = null | [
  'username'    => 'Bot',   // wire display name; always this literal
  'cards'       => [],      // real cards (victory/apartment realism)
  'cards_count' => 2,       // always 2, ignoring host's chosen cards_count
  'total_paid'  => 0,       // never contributes to bank
  'immune'      => false,   // apartment immune flag (same semantics as players)
  'drawing'     => false,   // true iff the bot is the current drawer
  'status'      => 'active' // 'active' while present; object cleared on removal
];
```

Invariants:

- Bot is **never** inserted into `$room['players']`, `drawer_order`,
  `userConnections`, or SQLite `users`.
- At most one bot; created only by the host action `play_vs_bot` when the room
  has exactly one seated human (`count($room['players']) === 1`).
- Destroying the room (host leave, game over, admin close, etc.) destroys the
  bot with it. Clearing the bot mid-game sets `$room['bot'] = null`.
- Bot is **not** written to `all_players_history` for refund purposes
  (`total_paid = 0` — nothing to refund). Optional ghost presence in client
  roster packets is protocol-only (see §8), not an economy history row.

**Drawer identity while bot is drawing:**
`$room['active_drawer_conn_id'] = null` and `$room['bot']['drawing'] = true`.
While a human is drawing: `active_drawer_conn_id = <human conn_id>` and
`bot['drawing'] = false` (or bot is null). Host and drawer concepts remain
independent; the bot is never host.

### 2. Availability and join policy

- Client: “Play vs Bot” control is **host-only**, always rendered, **disabled**
  whenever `count(players) > 1`. Re-evaluate enable/disable on every roster
  change (`player_joined` / `player_left` / `room_joined`), not only at room
  create.
- Server: `play_vs_bot` rejects if sender is not host, room is not `waiting`,
  or `count($room['players']) !== 1` (reuse existing reject codes — see §8).
- While `$room['bot'] !== null`, the room is closed to other humans:
  `join_room` → `error.room_full` (same effective “full” outcome as a 2-player
  room at capacity). No observers, no third player. Prefer checking
  `$room['bot'] !== null` explicitly (do not rely solely on `status`, because
  the bot object is the mode flag).
- Host leave / room destroy: unchanged room-exit paths; bot discarded with the
  room.

### 3. Cards, stake, bank

On `play_vs_bot` (atomic create-bot + start):

1. Build bot object (`cards_count = 2`, `total_paid = 0`).
2. Generate real cards for human (using human’s seated `cards_count`) and bot.
3. Deduct **only** the human’s stake in the start-game transaction
   (`coins -= human.total_paid`). Bot contributes nothing.
4. `bank = human.total_paid` only (equivalent to `sum(players[*].total_paid)`
   with bot excluded by design).
5. Transition `waiting → playing`, broadcast `game_started` (bot included in
   the wire roster as username `"Bot"` — §8).

`start_game` (human-vs-human) is unchanged: still requires ≥2 active humans
in `$room['players']`. Bot mode starts **only** via `play_vs_bot`.

### 4. Turn mechanics — subsystem fold-in

#### Conceptual turn order

Physical `drawer_order` remains **human conn_ids only** (here: single host).
Conceptually the cyclic order is:

```text
Host (human) → Bot → Host → Bot → …
```

Host always starts first (existing Drawer Order Rules). After the human’s
successful draw (and any victory/apartment resolution that leaves the game
in `playing`), the next drawer is the bot if `$room['bot']` is still active;
after the bot’s draw, the next drawer is the sole active human.

#### `GameTurnService` (and AFK owners)

| Concern | Bot fold-in |
|---------|-------------|
| `startTurn` / first turn after `play_vs_bot` | Human host first: existing `sendYourTurn` + Game AFK arm. Do **not** call `sendYourTurn` for the bot. |
| `nextDrawer` / `peekNextDrawer` | If bot present and current drawer was the human → select bot (`active_drawer_conn_id = null`, `bot['drawing'] = true`), then **immediately** run the server-side bot draw path (no wait). If current was bot → select the active human, then `startTurn` as today. |
| Bot draw path | Same barrel pick / `markNumber` / `barrels_drawn` broadcast / victory+apartment checks as `handleDrawBarrel`, but no `your_turn`, no `turn_ready`, no `afk_start` / strikes / `game_afk_timer_id` arm for the bot. Client animation comes from `barrels_drawn` alone. |
| `markNumber` | After every barrel (human or bot draw), mark **active humans in `players` and the bot** (if present). |
| `handleTurnReady` / nudge / AFK | Unchanged; they already require a real `players[$connId]` seat. Bot never enters those paths. |
| Game AFK (`ReconnectService::tickGameAfk` etc.) | Only when `active_drawer_conn_id` names a human in `players`. If `bot['drawing']`, AFK must not be armed and must not tick against the bot. |

**No artificial delay** on the server between “bot becomes drawer” and the bot
draw. Instant = server turn advancement only.

`ReconnectService` disconnect/reconnect seat logic: **no bot-aware changes**
(bot has no connection).

### 5. Apartment — subsystem fold-in

| Concern | Bot fold-in |
|---------|-------------|
| `hasLine` / `shouldTrigger` / `prepareApartment` | Scan active `$room['players']` **and** `$room['bot']` (if non-null). Set `bot['immune']` when the bot has a line. |
| Bot immune (bot row closed first) | Bot is not in the required-to-pay set. Human proceeds with normal `apartment_alert` + 10s `apartment_timer_id`. Bot never sends `apartment_choice`. |
| Bot required (human row closed first) | Bot **cannot** pay 5 coins. **Immediately** force-remove the bot (`reason: refuse`) — do **not** wait for the apartment timer for the bot’s decision. Human’s 10s countdown UI is unaffected if the human still needs to answer; if bot removal leaves zero opposing participants, end as `last_survivor` for the human without requiring the human to pay. |
| `finishApartment` survivor counts | “Active participants” for last-survivor / empty-room checks = active humans in `players` **plus** bot if still present. |
| Bot removal helper | Clear `$room['bot'] = null` (or equivalent). Do not attempt DB refund. Do not require a `connection` send target for the bot. Broadcast `player_left` with `username: "Bot"`, `reason: "refuse"` so the client roster updates (no `user_id`, or omit / null — document wire choice in Epic; prefer omitting `user_id` when absent). |

Victory still outranks apartment on the same barrel (existing rule).

### 6. Victory / bank / game_over — subsystem fold-in

| Concern | Bot fold-in |
|---------|-------------|
| `VictoryService::checkAllVictories` | Also scan `$room['bot']` cards/masks when bot present. Bot win is **not** keyed by conn_id into `players`. |
| Human wins (`victory`) | Existing payout: human receives bank. Counts toward bot win streak (§7). |
| Human wins (`last_survivor`) after bot refuse/removal | Existing last_survivor payout to the human. Counts toward bot win streak (§7). ADR-013 `auto_draws` gate applies only when the **triggering removal reason is `afk`** among humans — bot force-refuse is `refuse`, so last_survivor applies regardless of the human’s `auto_draws`. |
| Bot wins (closes 15 on one of its two cards) | **Bank burn:** set `bank = 0` without crediting any `user_id` and without refunding the human. Room → `finished` → destroy. Emit `game_over` with **`reason: "bot_win"`**, `winner: "Bot"`, `prize: 0`, `final_bank: 0` (burned; do not report the pre-burn bank as a prize). Statistics: human `paid` = stake, `received` = 0. |
| `calculateWinChances` / `barrels_drawn.win_chances` | Include username `"Bot"` when bot present. |
| Double-victory share math | Unchanged among human winners. A bot win ends the game via the burn path above; bot is never a payout recipient / share holder in `calculatePrize`. |

#### Open items — locked defaults (stakeholder may override later)

1. **Bot win resets human streak?** **YES.** A bot win is not a human win; set that human’s streak to 0.
2. **Disconnect/reconnect (not logout) resets streak?** **NO.** Only explicit logout, finishing a human-vs-human game, or a bot win resets it. Mid-streak disconnect-then-reconnect preserves the counter in `$worker->botWinStreaks`.

### 7. Win-streak bonus (double-bank mint)

- Storage: **RAM only** on the worker — `$worker->botWinStreaks[$userId] = int`
  (missing key ⇒ 0). Never SQLite.
- Increment +1 when the human wins vs bot via `victory` or `last_survivor`.
- On reaching **3**: pay the normal bank to the human, then credit an
  **additional** amount equal to that bank (server-minted emission). Streak
  resets to **0** immediately after the bonus is applied.
- Reset to 0 also on: (a) explicit **logout**; (b) finishing **any**
  human-vs-human game (`$room['bot']` was null for that finished game) for
  each human participant who receives/appears in that `game_over`; (c) bot
  win against that human (§6 default).

Named economy exceptions (must appear in ANCHOR_CORE Part 2):

1. **Bot-win bank burn** — deletion of room bank coins with no recipient and
   no refund.
2. **Bot win-streak double-bank mint** — creation of coins equal to the bank
   on the 3rd consecutive human win vs bot.

### 8. Protocol

**Action** (new; fits dedicated-action convention like `start_game` /
`nudge_turn`):

```json
{"action": "play_vs_bot"}
```

No payload. Server uses sender `connId` + room membership.

**Rejects** (no new error codes unless an Epic proves need):

| Condition | Code |
|-----------|------|
| Not in a room | `error.room_not_found` |
| Not host / not `waiting` / not exactly one seated human | `error.not_your_turn` (same host/phase bucket as `start_game`) |
| Join while bot present | `error.room_full` |

**`game_over` reason** (new wire value):

```json
{"type": "game_over", "winner": "Bot", "reason": "bot_win", "prize": 0, "final_bank": 0, "statistics": [...], "win_chance_history": [...]}
```

**Roster representation:** username `"Bot"` in `game_started.players`,
`barrels_drawn` / win-chance maps, `reconnect_state.players`, and
`player_left` on bot removal. **No `is_bot` field.** Client distinguishes the
bot by the reserved username.

**Username reservation:** registration **must reject** username `Bot`
(case-insensitive match on the literal `bot`) so a real account cannot collide
with the wire name. Prefer `error.auth_invalid_username` (reserved), not
`error.auth_username_taken`. One-line guard in `AuthService::register` (Epic 5
or a one-line prelude in Epic 1 — must land before or with first client
exposure of username `"Bot"`).

**`drawer_order` on the wire:** continues to list human usernames only; bot
turns are visible via `barrels_drawn.next_drawer` / whose cards mark, not via
inserting `"Bot"` into `drawer_order` unless an Epic finds a client bug — default
**do not** put `"Bot"` in `game_started.drawer_order` (physical order stays
human conn_ids → human usernames).

### 9. State machine confirmation (Part 4)

No new room or player states. Bot mode uses existing transitions:

- `play_vs_bot`: `waiting → playing` (same destination as `start_game`).
- Bot/human victory or last_survivor or bot_win: `playing|apartment → finished → destroyed`.
- Apartment with bot immune/required: still `playing → apartment → playing|finished`.

Allowed-action lists: add `play_vs_bot` under **waiting** only.

### 10. Epic decomposition (Rule 11)

Ship as **five separate Epics**, each with its own diff, `CHANGED:` /
`NOT CHANGED:` report, `IMPLEMENTATION_STATUS.md` entry, verification
(`tests/Manual/test_bot_opponent.php` or a per-Epic split), then git commit +
push (Rule 16):

| Epic | Scope |
|------|--------|
| **EPIC-034.1** | Bot entity + `play_vs_bot` start path + turn engine integration (§1, §3, §4) + username reservation + roster injection for `game_started` / mark / win chances. Highest risk. |
| **EPIC-034.2** | Apartment-with-bot resolution (§5). |
| **EPIC-034.3** | Victory / `bot_win` bank-burn economy path (§6). |
| **EPIC-034.4** | Win-streak tracking + double-bank mint (§7) — orthogonal once 034.1–034.3 exist. |
| **EPIC-034.5** | Client UI: “Play vs Bot” enable/disable + bot roster rendering (§2, §8). |

Do not combine Epics into one diff. Prefer extending existing services
(`LobbyService` / `GameService`, `GameTurnService`, `ApartmentService`,
`VictoryService` / `GameFinishService`, `AuthService`) over a new class; if
file-size limits force extraction, add any new class name to Part 6 via a
follow-up note in the Epic report before merging.

---

## Consequences

### Positive

- Single-player practice/progression mode without polluting `$room['players']`
  or SQLite with a fake user.
- Economy exceptions are explicit and auditable (burn + named mint).
- Protocol stays small: one action, one `game_over` reason, reserved username
  instead of `is_bot`.

### Negative / follow-up

- Permanent dual-read tax: every new gameplay feature that scans participants
  must remember `$room['bot']`.
- Win streak is process-local RAM — lost on server restart (accepted).
- Existing accounts named `Bot` / `bot` (if any) must be handled operationally
  before enabling registration reject in production (migrate/rename); greenfield
  DBs are fine.

### Anchor amendments (this ADR)

Update immediately on acceptance:

- `ANCHOR_CORE.md` Part 1 Room Structure + bot object; Drawer Order note;
  Ownership drawer note; Worker Storage `botWinStreaks`; Part 6 room keys +
  bot keys + actions/packets/reasons as needed.
- `ANCHOR_CORE.md` Part 2 Economic Integrity Rule — name **Bot-win bank burn**
  and **Bot win-streak double-bank mint**.
- `ANCHOR_CORE.md` Part 4 — `play_vs_bot` under waiting; confirm no new states.
- `ANCHOR_PROTOCOL.md` — `play_vs_bot`, `bot_win`, reserved username `Bot`.
