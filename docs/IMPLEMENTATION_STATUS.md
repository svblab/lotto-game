# Implementation Status тАФ Lotto Game Project

## EPIC-035 тАФ Room game-speed mode (ADR-035) (2026-08-24)

Status: Completed

- [DONE] ADR-035 accepted; `speed_mode` on Room Structure + registries
- [DONE] Server: `create_room` optional `speed_mode` (default `slow`); wire into
  `room_list`, `room_joined`, `reconnect_state` (waiting + playing)
- [DONE] Client: create-room select, room list column, room panel label
- [DONE] Fast animation profile (~3s total, LтЖТR stops); slow unchanged
- [DONE] Gold pulse omitted in fast mode; audio unchanged
- [DONE] i18n keys in en/ru/es/fr/zh/tr

CHANGED:
- docs/ADR/035-room-game-speed-mode.md (new)
- docs/ANCHOR_CORE.md (Room Structure + Part 6 `speed_mode`)
- docs/ANCHOR_PROTOCOL.md (`create_room`, `room_list`, `room_joined`, `reconnect_state`)
- src/Core/RoomManager.php
- src/Lobby/LobbyService.php
- src/Game/ReconnectService.php
- public/js/app.js
- public/js/ui.js
- public/index.html
- public/locales/{en,ru,es,fr,zh,tr}.json
- docs/IMPLEMENTATION_STATUS.md

NOT CHANGED:
- GameTurnService / LottoEngine draw logic
- Game AFK / ReconnectService AFK timers
- animationQueue max-3 semantics
- spin.mp3 / reveal.mp3 behavior
- PROTOCOL_VERSION
- `player_joined` payload (mode via `room_joined` only)

Commit: 5974582
Notes: Single Epic (client animation + one room field). Also landed on
`feature/room-chat-files` (341e420 / 0049c6e).

VERIFICATION:
MANUAL VERIFICATION REQUIRED
1. Create a **fast** room (`speed_mode=fast`). Start a 2-player game. On
   "Draw barrel", stopwatch the full drawтЖТall-three-revealed sequence.
   Expected: тЙИ3s total; reels stop left-to-right; cards mark without gold pulse.
2. Create a **slow** room (default). Confirm existing ~3s-per-number reveal
   spacing and gold pulse still present.
3. Mid-game in a fast room: reload / reconnect. Confirm UI still uses fast
   animation (from `reconnect_state.speed_mode`) without recreating the room.
4. Lobby `room_list`: confirm Speed column shows Slow/Fast for each room.
5. Omit `speed_mode` in a raw `create_room` packet тЖТ room behaves as slow.

---

## EPIC-033C тАФ Admin players delete + dropdown (ADR-033) (2026-08-23)

Status: Completed

- [DONE] `admin_delete_user` / `admin_bulk_delete_users` with busy guards (online / players / history / roster)
- [DONE] All-or-nothing bulk PDO transaction; no auto-kick
- [DONE] Players admin UI: table тЖТ `<select>` + detail; Ban/Unban/Kick/Delete + Delete matching preview
- [DONE] Manual test `tests/Manual/test_admin_delete_user.php`
- [DONE] KNOWN GAP noted: load/stress tests writing junk into live `game.db`

Files:
- src/Admin/AdminService.php
- src/Admin/AdminHandler.php
- server.php
- public/index.html
- public/js/ui.js
- public/js/app.js
- public/locales/*.json
- tests/Manual/test_admin_delete_user.php
- docs/IMPLEMENTATION_STATUS.md

Commit: 5974582
Notes: Registries already listed delete actions/errors from ADR-033 Epic A pass.

VERIFICATION:
- `php tests/Manual/test_admin_delete_user.php`
- MANUAL тАФ admin Players dropdown select тЖТ Ban/Unban/Kick/Delete
- MANUAL тАФ filter junk usernames тЖТ Delete matching тЖТ confirm list тЖТ purge
- MANUAL тАФ delete rejected while target online or still in room RAM

CHANGED:
- Hard delete + bulk delete WS paths; players list UI; delete i18n; tests; KNOWN GAP note
NOT CHANGED:
- Ban/kick/unban semantics; auto-kick on delete; load-test DB isolation (KNOWN GAP)

## EPIC-033B тАФ Admin rooms dropdown (ADR-033) (2026-08-23)

Status: Completed

- [DONE] Replace admin rooms flat table with `<select>` + detail + Close
- [DONE] Reuse existing `admin_stats_data.rooms` / `room_list` fields only (no protocol)
- [DONE] Preserve selection across refresh when room still exists

Files:
- public/index.html
- public/js/ui.js
- public/js/app.js
- public/css/style.css
- public/locales/*.json
- docs/IMPLEMENTATION_STATUS.md

Commit: 5974582
Notes: UI-only per ADR-033 Epic B. `admin_close_room` unchanged.

VERIFICATION:
- MANUAL тАФ open admin panel тЖТ Active Rooms shows dropdown; empty state when no rooms
- MANUAL тАФ select a room тЖТ detail shows id / players / status / lock; Close enabled
- MANUAL тАФ Close room тЖТ room removed on next stats/list refresh; selection clears if gone
- MANUAL тАФ Refresh rooms preserves selection when room still present

CHANGED:
- Admin rooms list presentation (table тЖТ select + detail)
NOT CHANGED:
- Protocol / server / `admin_close_room`; players moderation UI; password rotation

## EPIC-033A тАФ Admin password rotation (ADR-033) (2026-08-23)

Status: Completed

- [DONE] ADR-033 accepted (password rotation + account deletion design; Epic B UI-only confirmed)
- [DONE] `PasswordPolicy::validateAdminPassword()` shared helper (registration rules untouched)
- [DONE] `admin_change_password` / `admin_change_password_result` + error codes
- [DONE] Admin UI modal (current / new / confirm)
- [DONE] CLI `change_admin_password.php` left alone as emergency fallback (may be absent)

Files:
- docs/ADR/033-admin-panel-password-rotation-and-account-management.md
- docs/ANCHOR_CORE.md
- docs/ANCHOR_PROTOCOL.md
- docs/IMPLEMENTATION_STATUS.md
- src/Auth/PasswordPolicy.php
- src/Admin/AdminService.php
- src/Admin/AdminHandler.php
- src/Infrastructure/PreparedStatements.php
- server.php
- public/index.html
- public/js/app.js
- public/locales/*.json
- tests/Manual/test_admin_change_password.php

Commit: 5974582
Notes: Acting admin only (by userId). Write-verify transaction before COMMIT.

VERIFICATION:
- `php tests/Manual/test_admin_change_password.php`
- MANUAL тАФ admin panel тЖТ Change admin password; wrong current / weak new / success login with new password

CHANGED:
- Admin password rotation WS path + ADR-033 + registries + UI modal + tests
NOT CHANGED:
- Registration password rules; CLI change_admin_password.php; ban/kick/delete; rooms/players list UI

## EPIC-032a тАФ Nudge voice i18n (client) (2026-08-23)

Status: Completed

- [DONE] `nudge_received` voice line resolves `audio/nudge_<lang>.mp3` from recipient's current `LottoI18n.getLang()` on every play.
- [DONE] Migrated `nudge.mp3` тЖТ `nudge_en.mp3`; missing per-language files fall back to English without breaking other sounds.
- [DONE] Per-language cache keys (`nudge_en`, `nudge_ru`, тАж) so language switches within an open tab apply on the next nudge.

Files:
- public/js/sound.js
- public/audio/nudge_en.mp3 (renamed from nudge.mp3)
- public/audio/README.md
- docs/IMPLEMENTATION_STATUS.md

Commit: 2a42674
Notes: Client-only; no ADR/protocol/server changes (ADR-032 server rules unchanged). Voice recordings for `ru/es/fr/zh/tr` are out of scope тАФ fallback to `nudge_en.mp3` until added.

VERIFICATION:
- MANUAL тАФ with `getLang() === 'en'`, `nudge_received` plays `nudge_en.mp3`.
- MANUAL тАФ with `getLang()` set to `ru` (no `nudge_ru.mp3` on disk), `nudge_en.mp3` plays instead.
- MANUAL тАФ switch language via selector without reload; next `nudge_received` uses new language.
- MANUAL тАФ other sounds (`spin`, `apartment`, `reveal`, `match`, `victory`, `defeat`) unchanged.

## Frontend sound тАФ volume slider + loop cues (2026-08-17)

Status: Completed

- [DONE] `LottoSound.setVolume()` / `getVolume()` with `localStorage` persistence (`lotto_sound_volume`, default 0.7); volume slider in game top-bar.
- [DONE] `startLoop()` / `stopLoop()` for spin (loops until last drum reveals) and apartment (required players only).
- [DONE] Spin: `startSlotsWaiting()` тЖТ `startLoop('spin')`. Normal stop is inside `revealSlot()` at the tick that commits the last drumтАЩs number (`!isSlotsSpinning()` after removing that drumтАЩs `spinning` class) тАФ not after `await revealSlot()` returns (that Promise waits an extra 450ms) and not in `app.js` after the for-loop. Safety-net / interruption stops: `stopSlotsWaiting()`, `resetSlots()`, `idleSlot()` (only when no drum still spinning).
- [DONE] Apartment: `startLoop('apartment')` when `required === true`; stops on timeout, `hideApartment()` (game over, bank update, reset to lobby).
- [DONE] 7th sound key `apartment`; `public/audio/README.md` loopability guidance for `spin.mp3` and `apartment.mp3`.

Files:
- public/js/sound.js
- public/js/ui.js
- public/index.html
- public/css/style.css
- public/audio/README.md
- docs/IMPLEMENTATION_STATUS.md

VERIFICATION:
MANUAL VERIFICATION REQUIRED тАФ browser audio timing cannot be verified by the PHP test suite; see Epic completion QA checklist in chat / manual steps below.
- Volume slider changes audible level in real time; mute silences regardless of volume.
- Spin loop starts when drums spin; stops when last drum reveals or on reset/reconnect mid-spin (F1/F2 hooks).
- Apartment sound only for `required` players; stops on timeout, hideApartment paths; no leak into next turn.

## Lobby projected bank (pre-game) (2026-08-17)

Status: Completed

- [DONE] `bet_per_card` on `room_joined` (`LobbyService::buildRoomJoinedPacket`).
- [DONE] `bet_per_card` on waiting `reconnect_state`
  (`ReconnectService::buildReconnectState`) тАФ fixes hard-refresh reconnect path.
- [DONE] Client `#room-bank-label` shows projected bank in `waiting`
  (`sum(cards_count) * bet_per_card`); real `room.bank` once game starts.
- [DONE] `test_lobby_integration.php` + `test_reconnect.php` projection assertions.

Files:
- src/Lobby/LobbyService.php
- src/Game/ReconnectService.php
- docs/ANCHOR_PROTOCOL.md
- public/js/app.js
- public/js/ui.js
- tests/Manual/test_lobby_integration.php
- tests/Manual/test_reconnect.php

VERIFICATION:
- `php tests/Manual/test_lobby_integration.php`
- `php tests/Manual/test_reconnect.php`

## EPIC-032 тАФ Turn nudge (ADR-032) (2026-08-17)

Status: Completed

- [DONE] Once-per-turn social `nudge_turn` from non-drawer to current drawer.
- [DONE] Private `nudge_received` packet; AFK timer fields never written.
- [DONE] Client button + toast + 6th `LottoSound` key `nudge` (no vibration / Web Notifications).

Docs (already on `main` as `8eeb649`): ADR-032, ANCHOR_PROTOCOL.md, ANCHOR_CORE.md Part 6 registries.

Files:
- src/Game/GameHandler.php
- src/Game/GameService.php
- src/Game/GameTurnService.php
- src/Core/StateMachineAudit.php
- server.php
- public/index.html
- public/js/app.js
- public/js/ui.js
- public/js/sound.js
- public/css/style.css
- public/audio/README.md
- public/locales/*.json
- tests/Manual/test_turn_nudge.php (new)
- docs/IMPLEMENTATION_STATUS.md

Commit: (implementation тАФ pending user commit; docs `8eeb649`)
Notes: `GameHandler` 89 / 300; `GameTurnService` 500 / 500; `GameService` 496 / 500.

VERIFICATION:
- `php tests/Manual/test_turn_nudge.php` тАФ 32/32 passed
- `php tests/Manual/test_turn_system.php` тАФ 59/59 passed
- `php tests/Manual/test_game_start_turn_integration.php` тАФ 11/11 passed

## EPIC-031c-b тАФ IP account limit server guard (ADR-031) (2026-08-16)

Status: Completed

- [DONE] `IpAccountLimitService` with trusted-proxy trust boundary
  (`LOTTO_TRUSTED_PROXY_IPS`, X-Forwarded-For / X-Real-IP, sentinel bucket).
- [DONE] `MAX_ACCOUNTS_PER_IP = 3` in `Constants.php`; guard on login/register
  auto-login in `AuthHandler`; `clientRemoteIp` attached at handshake in
  `server.php`.
- [DONE] Manual tests `test_ip_account_limit.php` (unit (e)(f)(g) + WS (c)(d)).

Files:
- src/Core/Constants.php
- src/Auth/IpAccountLimitService.php (new)
- src/Auth/AuthHandler.php
- server.php
- tests/Manual/test_ip_account_limit.php (new)
- tests/Manual/test_* (AuthHandler constructor + IpAccountLimitService DI)

VERIFICATION:
- `php tests/Manual/test_ip_account_limit.php`
- `php tests/Manual/test_tab_ownership.php`

## EPIC-031c-a тАФ Trusted proxy IP docs + registry (ADR-031) (2026-08-16)

Status: Completed

- [DONE] ADR-031 addendum: trust boundary, sentinel fallback, updated limitations.
- [DONE] `clientRemoteIp` in ANCHOR_CORE Connection Runtime Fields.
- [DONE] README ┬з3.8 + LOCAL_ENVIRONMENT `LOTTO_TRUSTED_PROXY_IPS`.

Files:
- docs/ADR/031-per-account-tab-ownership-and-ip-account-limit.md
- docs/ANCHOR_CORE.md
- README.md
- docs/LOCAL_ENVIRONMENT.md

## EPIC-031b тАФ Per-account tab ownership client fix (ADR-031) (2026-08-16)

Status: Completed

- [DONE] `STORAGE_OWNER_TAB` stores `{tabId, userId}`; relinquish only on
  same-account handoff; legacy bare tab id fail-safe.
- [DONE] `tests/Manual/test_tab_ownership.php`.

Files:
- public/js/app.js
- tests/Manual/test_tab_ownership.php (new)

VERIFICATION:
- `php tests/Manual/test_tab_ownership.php`
- Manual browser: two Incognito accounts coexist; same-account second tab handoff.

## EPIC-031a тАФ Per-account tab ownership + IP limit ADR (ADR-031) (2026-08-16)

Status: Completed

- [DONE] ADR-031 documents Part A (client per-account tab ownership) and Part B
  (server `MAX_ACCOUNTS_PER_IP` guard); numbering skips 030 on `main` (possible
  claim on `feature/room-chat-files`).
- [DONE] Protocol + core registries: `error.auth_too_many_accounts_same_network`,
  `MAX_ACCOUNTS_PER_IP = 3`, `IpAccountLimitService` in Auth module / Part 6.

Files:
- docs/ADR/031-per-account-tab-ownership-and-ip-account-limit.md (new)
- docs/ANCHOR_PROTOCOL.md
- docs/ANCHOR_CORE.md

Notes: Documentation only тАФ no PHP/JS implementation in this epic.
Follow-up: EPIC-031b (`public/js/app.js`), EPIC-031c (server guard + tests).

CHANGED:
- Error code registry and ADR-031 narrative for honest IP-cap rejection at login
- Global constant `MAX_ACCOUNTS_PER_IP` and Auth module class registry

NOT CHANGED:
- `public/js/app.js`, `src/Auth/*`, `src/Core/Constants.php`, tests

VERIFICATION:
- Manual: confirm ADR-031 sections match approved stakeholder spec (Part A/B split,
  `MAX_ACCOUNTS_PER_IP = 3`, reconnect exempt, register auto-login in scope).
- Manual: grep `ANCHOR_PROTOCOL.md` for `error.auth_too_many_accounts_same_network`.
- Manual: grep `ANCHOR_CORE.md` for `MAX_ACCOUNTS_PER_IP` and `IpAccountLimitService`.

## Client game sounds + mute toggle (2026-08-15)

- [DONE] `public/js/sound.js` тАФ HTML5 Audio preload/play for 5 optional clips in
  `public/audio/` (`spin`, `reveal`, `match`, `defeat`, `victory`); missing files
  fail silently via `error` listener + `.play().catch()`.
- [DONE] Triggers: `startSlotsWaiting`, `revealSlot` (at number reveal),
  per-barrel match in `animateBarrelsDrawn`, `onGameOver` (victory if
  `received > 0`; defeat only when `reason === 'victory'` and `received === 0`;
  **no sound** for `no_survivors`).
- [DONE] Mute toggle `#sound-mute-btn` in game top-bar; `localStorage`
  `lotto_sound_muted` (default ON).

Files: `public/js/sound.js`, `public/audio/README.md`, `public/js/ui.js`,
`public/js/app.js`, `public/index.html`, `public/css/style.css`

## Apartment locale fix + drawn-strip/card marks (2026-08-15)

- [DONE] Fixed apartment UI copy: `apartment.required` / `apartment.agree` showed
  10 coins in all 6 locales; server charges flat `APARTMENT_PAYMENT = 5` per
  ANCHOR_CORE.md / `ApartmentService`.
- [DONE] Drawn-number strip: segments matching player's marked card numbers
  render red (`--chip`); other drawn segments stay gold.
- [DONE] Card cell mark: red circle replaced with diagonal cross (`::before` +
  `::after` lines, `--chip` color).

Files: `public/locales/*.json`, `public/js/ui.js`, `public/js/app.js`,
`public/css/style.css`, `public/index.html`

## Auth/lobby header + drawn-number strip UI (2026-08-15)

- [DONE] Product feedback: auth screen shows logo only (removed redundant
  `<h1 data-i18n="app.title">`); lobby header shows plain `app.title` text
  between balance and language buttons (removed lobby logo image).
- [DONE] Replaced `#drawn-history` chip list with a responsive 1тАУ90 horizontal
  segment strip (`renderDrawnHistory` in `ui.js`); drawn segments use `--gold`,
  undrawn stay neutral; number tooltip on `:hover`/`:focus` via CSS `::after`.

Files:
- public/index.html
- public/css/style.css
- public/js/ui.js

CHANGED:
- Auth: removed duplicate title heading; lobby: logo тЖТ `span.top-bar-title`
  with existing `app.title` i18n key (no new locale keys)
- `#drawn-history`: 90 flex segments, `aria-label` per segment, full-array
  correctness on all three `renderDrawnHistory` call sites in `app.js`

NOT CHANGED:
- `public/js/app.js`, protocol, server, locale JSON files
- `.logo-img` / `.logo-placeholder` base rules (auth still uses them)
- `.drawn-chip` CSS left in place (unused)

VERIFICATION:
- `renderDrawnHistory` receives full `state.drawnAll` on draw, reset, and
  `reconnect_state` тАФ each call rebuilds all 90 segment states via `Set`
- Responsive width: `flex: 1 1 0` on 90 segments; ~3px/segment at 320px viewport
- Tooltip: CSS-only `::after` on `:hover` and `:focus` (keyboard + touch tap)

## Optional image asset wiring (2026-08-13)

- [DONE] Wired optional `public/img/` assets into the frontend with graceful
  degradation when files are absent (fresh clone = unchanged appearance).

Files:
- public/index.html (`<img>` logos + chip probe script)
- public/css/style.css (logo-img, felt-bg layer, chip icon)

Assets:
- **logo.png** тАФ `<img src="img/logo.png">`; `onerror` calls `replaceWith()` a
  `<div class="logo-placeholder">` (or `.small` variant), restoring the exact
  pre-integration gradient box with no broken-image icon.
- **felt-bg.png** тАФ comma-separated `background-image` on `body` (texture +
  existing radial gradient); `background-color: var(--felt-dark)` base.
- **chip.png** тАФ inline probe adds `html.has-chip-icon`; CSS replaces ЁЯкЩ emoji.
- **barrel.png** тАФ not wired (see notes).

Notes: No binary assets committed. `barrel.png` skipped: `.drawn-chip` history
entries are compact number pills (~0.8rem); a 64├Ч64 icon would clutter layout.

CHANGED:
- Auth/lobby logo placeholders тЖТ optional `<img>` with onerror fallback
- `body` background layers for optional felt texture
- Balance `.coins` icon when `chip.png` exists

NOT CHANGED:
- `public/js/ui.js`, `public/js/app.js` (barrel history rendering)
- Protocol, server, locale files
- Binary files in `public/img/`

VERIFICATION:
- Fresh clone (no PNGs): gradient logos, felt gradient, ЁЯкЩ emoji unchanged
- With PNGs deployed: logos, texture, chip icon appear

## Rules modal copy rewrite (2026-08-13)

- [DONE] Expanded `rules.*Body` strings in all 6 locale files (en, ru, es, fr,
  zh, tr) with player-facing explanations of core loop, economy/bank, cards,
  Apartment, victory split, and reconnect. Fixed incorrect apartment fee (was
  10 in copy; actual is 5 coins). Removed English "reconnect" leak in ru.

Files:
- public/locales/en.json, ru.json, es.json, fr.json, zh.json, tr.json

Notes: Content-only тАФ no i18n key changes, no .php/.js edits. Numbers verified
against `Constants.php`, `ApartmentService::APARTMENT_PAYMENT`, `PreparedStatements`
(create_user 500 coins), `docs/GAME_RULES.md`, `docs/ANCHOR_CORE.md` Part 2.

CHANGED:
- `rules.introBody`, `rules.economyBody`, `rules.cardsBody`,
  `rules.apartmentBody`, `rules.victoryBody`, `rules.reconnectBody` (├Ч6 locales)

NOT CHANGED:
- `renderRules()` / `public/js/ui.js`, any `.php` file, i18n key structure

VERIFICATION:
- `php tests/Manual/test_frontend_i18n.php`

## Rules modal close-button contrast fix (2026-08-13)

- [DONE] Scoped `.rules-panel .icon-btn { color: var(--wood); }` so the "тЬХ"
  glyph is visible on the pale-gold panel background (`#f5e6a8`). Base
  `.icon-btn` keeps `color: var(--cream)` for dark wood/felt contexts.

Files:
- public/css/style.css

Notes: Presentational only тАФ no protocol/ADR/JS changes. Old contrast
cream (`#f5e6c8`) vs panel bg: **1.02:1** (fails WCAG). New wood
(`#5c3d1e`) vs panel bg: **7.83:1** (passes AA 3:1 for UI graphics).

CHANGED:
- `#rules-close-btn` glyph color inside `.rules-panel`

NOT CHANGED:
- Global `.icon-btn` base color
- `#auth-lang-btn`, `#rules-btn-auth`, `#rules-btn-lobby` (dark backgrounds)
- `#join-room-close-btn` (parent `.join-room-panel` keeps default wood panel)
- `#admin-close-btn` (parent `.admin-panel` keeps default wood panel)
- app.js / ui.js event handlers

## WebSocket Origin allow-list (ADR-029) (2026-08-12)

- [DONE] Optional `LOTTO_ALLOWED_ORIGINS` gate in `onWebSocketConnected`
  (bootstrap only; default allow-all).
Files:
- docs/ADR/029-websocket-origin-allowlist.md (new)
- server.php (`$worker->allowedOrigins`, Origin check before hello)
- tests/Manual/test_server_bootstrap.php (TEST 9тАУ11)
- README.md ┬з3.7, docs/LOCAL_ENVIRONMENT.md

Notes: Token-based auth blocks classic CSWSH cookie riding; control addresses
residual resource/spam risk and defense-in-depth. Reject path:
`error.origin_forbidden` + WS close 4002 (ADR-005 pattern).

CHANGED:
- `onWebSocketConnected` Origin inspection when env list is non-empty

NOT CHANGED:
- Auth/Lobby/Game/Admin Handlers or Services

VERIFICATION:
- `php tests/Manual/test_server_bootstrap.php`
- VPS: `sudo -u www-data php run_ALL_tests.php` (manual тАФ agent has no SSH)

## Admin assertAdmin SQLite freshness (2026-08-12)

- [DONE] `AdminService::assertAdmin()` re-reads `users.is_admin` and
  `banned_until` from SQLite via `user_auth_fields_by_id` on each admin action.
Files:
- src/Admin/AdminService.php (`fetchUserAuthFields()`, demotion/ban sync)
- tests/Manual/test_admin_auth.php (groups 4тАУ6: demotion, still-admin, banned)

Notes: Stale `$connection->isAdmin` cleared on demotion/ban; subsequent calls
fail fast without DB. Client sees `error.not_your_turn` on demotion (no hint);
active ban uses existing `banned` packet. One SQLite read per admin action when
`isAdmin` is true тАФ acceptable (admin panel is low-frequency, not polled in a
tight loop).

CHANGED:
- Admin guard authoritative against SQLite

NOT CHANGED:
- AdminHandler, register/login flows, game/lobby handlers

VERIFICATION:
- `php tests/Manual/test_admin_auth.php`
- `php run_ALL_tests.php`

## EPIC-5a тАФ Per-username login lockout (ADR-028) (2026-08-12)

- [DONE] RAM-only `LoginThrottleService`; wired into `AuthService` / `server.php`;
  `error.auth_rate_limited` in protocol registry (generic client message only).
Files:
- docs/ADR/028-auth-abuse-throttling.md (new)
- src/Auth/LoginThrottleService.php (new)
- src/Core/Constants.php (LOGIN_THROTTLE_* constants)
- docs/ANCHOR_CORE.md (constants + class registry)
- docs/ANCHOR_PROTOCOL.md (`error.auth_rate_limited`)
- src/Auth/AuthService.php (throttle check; preserves 007712c timing path)
- src/Auth/AuthHandler.php (`error.auth_rate_limited` mapping; generic message)
- server.php (`$worker->loginThrottle` DI)
- tests/Manual/test_login_throttle.php (new)

Notes: Defaults тАФ 5 failures / 300s window / 900s lockout. Register throttling
deferred to EPIC-5b (placeholder section in ADR-028 only). Client receives
`Invalid username or password` for rate-limited logins тАФ no attempt count or
remaining lockout time exposed.

CHANGED:
- Login failure throttling per username (Auth module, RAM-only)
- Protocol error code `error.auth_rate_limited`

NOT CHANGED:
- Register flow / `handleRegister()` / register throttling
- SessionGuard, reconnect, lobby/game handlers

VERIFICATION:
- `php tests/Manual/test_login_throttle.php`
- `php run_ALL_tests.php`

## AuthService login timing hardening (2026-08-12)

- [DONE] Constant-time password_verify path for unknown usernames in login()
Files:
- src/Auth/AuthService.php (diff тАФ dummy bcrypt hash on missing user row)
- tests/Manual/test_auth_integration.php (diff тАФ identical error-message assertion)

Notes: When username is not found, `password_verify()` still runs against a
precomputed dummy bcrypt hash before throwing тАФ reduces username enumeration via
response-time analysis. External login() contract unchanged.

CHANGED:
- AuthService::login() internal timing path only

NOT CHANGED:
- AuthHandler.php, SessionGuardService.php, exception messages, return shape

VERIFICATION:
- `php tests/Manual/test_auth_integration.php` тАФ PASS
- Full suite: `php run_ALL_tests.php` (see run below)

## ANCHOR_CORE Part 6 registry back-fill (2026-08-12)

- [DONE] Back-fill missing protocol registry entries in ANCHOR_CORE.md Part 6
Files:
- docs/ANCHOR_CORE.md (diff тАФ `turn_ready` action; `player_status_changed`,
  `admin_users_data` packet types)

Notes: Pure documentation/registry sync тАФ no code changes. `turn_ready` was
implemented (server.php, GameHandler, ANCHOR_PROTOCOL.md ADR-017) but omitted
from the Part 6 action list. Packet sweep found two additional omissions already
documented in ANCHOR_PROTOCOL.md and implemented in production code.

CHANGED:
- ANCHOR_CORE.md Part 6 ┬з Protocol Actions тАФ added `turn_ready`
- ANCHOR_CORE.md Part 6 ┬з Protocol Packet Types тАФ added `player_status_changed`,
  `admin_users_data`

NOT CHANGED:
- Any `.php` source files
- ANCHOR_PROTOCOL.md packet contracts

VERIFICATION:
- `git diff --stat` shows only `.md` files modified

## EPIC-028.3 тАФ Asymmetric cross-engine session closure + economy invariant net (2026-08-12)

- [DONE] Close ADR-026 reproduction gap; add EconomyAudit structural safety net
Files:
- tests/Manual/test_asymmetric_engine_stress.php (new тАФ asymmetric teardown create+join stress)
- src/Core/EconomyAudit.php (diff тАФ `checkWorkerInvariants()` duplicate-seat/dual-auth checks)
- src/Core/Helpers.php (diff тАФ `lottoEconomyCheckInvariants()` helper)
- src/Core/RoomManager.php (diff тАФ invariant scan on `destroyRoom()`)
- src/Game/GameService.php (diff тАФ invariant scan on `finishGame()` teardown)
- docs/ADR/026-fix-concurrent-session-bug.md (append тАФ EPIC-028.3 addendum)
- tests/Manual/test_economy_audit.php (diff тАФ invariant check regression group)

Notes: Part A stress test models delayed onClose after fresh login with both
sockets attempting create_room/join_room тАФ **no gap reproduced**; existing
SessionGuardService sweeps close the window. Part B adds detect-and-log-only
invariant monitoring (no balance mutation).

CHANGED:
- ADR-026 "Honest limit" superseded by EPIC-028.3 closure verification addendum.
- EconomyAudit structural checks on room destroy and game finish.

NOT CHANGED:
- SessionGuardService logic (no fix required тАФ test proves current sweeps sufficient).
- Protocol packets, Handler/Service business rules.

VERIFICATION:
- `php tests/Manual/test_asymmetric_engine_stress.php` тАФ PASS
- `php tests/Manual/test_economy_audit.php` тАФ PASS
- Full suite: `php run_ALL_tests.php` (see run below).

## TLS/WSS documentation-vs-code fix (2026-08-12)

- [DONE] Close TLS/WSS documentation-vs-code mismatch (ADR-027)
Files:
- docs/ADR/027-reverse-proxy-tls-termination.md (new тАФ reverse-proxy TLS decision)
- README.md (diff тАФ ┬з3 rewritten: nginx/Caddy WSS via proxy; `config/ssl.php` removed)
- public/index.html (diff тАФ `lotto-ws-port` / `lotto-ws-path` deploy meta tags)
- public/js/ws.js (diff тАФ `resolveWsUrl()` reads meta; no hardcoded `:8080` on HTTPS)
- tests/Manual/test_ws_url_resolution.php (new тАФ URL resolution + README/ADR checks)

Notes: Chose **option (b) reverse-proxy TLS termination** over native Workerman TLS
(lower RAM/CPU risk on 1 CPU / 500 MB VPS; avoids growing `server.php`; cert renew
reloads proxy only). `server.php` unchanged тАФ still plain `websocket://0.0.0.0:8080`.
Production clients use `wss://host/ws` (443) when meta tags set per README ┬з3.

CHANGED:
- README SSL section meaning: **was** native Workerman `config/ssl.php` on port 8443;
  **now** external nginx/Caddy terminates TLS, worker stays plain WS on 8080.
- Client transport URL derivation from deploy meta + page protocol.

NOT CHANGED:
- `server.php` bootstrap, protocol packets, Handlers/Services business logic.

VERIFICATION:
- `php tests/Manual/test_ws_url_resolution.php` тАФ PASS
- Full suite: `php run_ALL_tests.php` (see run below).

## Phase 18 тАФ FIX-30 Multi-session auth hardening (2026-08-06)

- [DONE] FIX-30 Concurrent multi-session auth bug (single account, multiple browsers)
Files:
- src/Auth/AuthHandler.php (diff тАФ `claimUserSession()`, evict superseded connections)
- src/Auth/AuthService.php (diff тАФ session registry moved out of `login()`)
- server.php (diff тАФ ownership-safe `userConnections` cleanup on `onClose`)
- src/Lobby/LobbyService.php (diff тАФ one seat per `user_id`, `user_id` on `player_left`)
- src/Game/ReconnectService.php (diff тАФ `rebindSeat()`, `user_id` on `player_left`)
- public/js/app.js (diff тАФ `player_left` by `user_id`; superseded session message)
- public/locales/en.json, ru.json (diff тАФ `auth_session_superseded`)
- tests/Manual/test_single_session.php, test_session_lifecycle.php, test_multi_session.php
- docs/ADR/001.md, docs/ANCHOR_PROTOCOL.md

Notes: ADR-001 amended тАФ newest login/reconnect wins (evict prior live session) instead
of reject-only second login. Prevents dual authenticated sockets and duplicate room
seats; `player_left` no longer resets unrelated clients with the same username.

VERIFICATION:
- `php tests/Manual/test_single_session.php` тАФ PASS
- `php tests/Manual/test_session_lifecycle.php` тАФ PASS
- `php tests/Manual/test_multi_session.php` тАФ PASS
- MANUAL: Browser A login тЖТ close тЖТ Browser B login тЖТ reopen A тЖТ only one session;
  leave from one client does not spuriously reset the other.

## Phase 18 тАФ Client Balance Persistence (2026-08-02)

- [DONE] FIX-29 Browser-reopen reconnect + SVG line chart (game-over)
Files:
- public/js/app.js (diff тАФ `hasPersistedSession()`; no token wipe on init; reconnect on WS open)
- public/js/ui.js (diff тАФ SVG `polyline` line chart in `renderWinChanceChart`)
- public/index.html (diff тАФ chart container div instead of canvas)
- public/css/style.css (diff тАФ `.win-chance-line-chart` styles)
- public/locales/*.json (diff тАФ `game.chartTurn`)
- src/Game/ReconnectService.php (diff тАФ `adoptSessionTokenForUser()`)
- server.php (diff тАФ login тЖТ adopt token + `handleReconnect()`)
- tests/Manual/test_reconnect.php (diff тАФ GROUP 3c adopt token)
- tests/Manual/test_frontend_structure.php (diff тАФ SVG chart + reconnect checks)

Notes: Closing the browser clears sessionStorage but kept localStorage token was
wiped on next visit (`init()` + sessionStorage gate). Reconnect now attempts on
any persisted token. Login after manual re-auth adopts the new `session_token` onto
the disconnected room player so `handleReconnect()` can match. Game-over chart
rendered as SVG line chart (grid, axes, polylines, point markers, legend).

CHANGED:
- Client: auto-reconnect on page load when `lotto_session_token` exists
- Server: `login` action tries room restore after token adoption
- Game-over modal: canvas chart тЖТ SVG line chart

NOT CHANGED:
- 15s playing disconnect grace, lobby immediate removal, F2 QA hotkey

VERIFICATION:
- `php tests/Manual/test_reconnect.php` тАФ PASS (GROUP 3c).
- `php tests/Manual/test_frontend_structure.php` тАФ PASS.
- MANUAL: start game тЖТ close browser tab тЖТ reopen within 15s тЖТ auto-rejoin room;
  finish game тЖТ game-over modal shows multi-line SVG chart.

- [DONE] FIX-28 Exponential win-chance formula (LottoEngine)
Files:
- src/Game/LottoEngine.php (diff тАФ `calculateWinChances()` static, exponential weights)
- src/Game/VictoryService.php (diff тАФ delegate to LottoEngine; username wire map)
- src/Game/GameService.php (diff тАФ pass `room.status` for apartment immune ├Ч1.1)
- src/Game/ReconnectService.php (diff тАФ same)
- public/js/ui.js (diff тАФ bar shows 1-decimal server percent)
- tests/Manual/test_lotto_engine.php, test_victory.php, test_turn_system.php
- docs/ADR/014.md (amendment), docs/ANCHOR_PROTOCOL.md, docs/GAME_RULES.md

Notes: Replaces ADR-014 inverse-moves formula with `turnsToWin = 15 тИТ bestCardClosed`,
`weight = exp(тИТ0.25 ├Ч turnsToWin)`, normalize to 1 decimal summing 100%. Active only;
disconnected excluded; complete card тЖТ 100% for winner(s). Wire keys remain `username`.

CHANGED:
- `LottoEngine::calculateWinChances()` тАФ core math
- `VictoryService::calculateWinChances()` тАФ conn_id тЖТ username for packets

NOT CHANGED:
- Victory/payout logic, client progress-bar placement (FIX-27), game-over chart

VERIFICATION:
- `php tests/Manual/test_lotto_engine.php` тАФ PASS (+winChance engine group).
- `php tests/Manual/test_victory.php` тАФ PASS (GROUP 3b updated).
- `php tests/Manual/test_turn_system.php` тАФ PASS (win_chances numeric, sum 100%).

- [DONE] FIX-27 Win-chance progress bar + game-over probability chart
Files:
- public/index.html (diff тАФ win-chance track/fill; game-over chart canvas + legend)
- public/css/style.css (diff тАФ bar gradient styles, chart panel)
- public/js/ui.js (diff тАФ `updateWinChanceBar`, `renderWinChanceChart`; players list without %)
- public/js/app.js (diff тАФ track `winChanceHistory` from server `win_chances` snapshots)
- public/locales/*.json (diff тАФ `game.winChanceHistory`)
- docs/GAME_RULES.md (diff тАФ single bar location, sidebar fields, end-game graph)

Notes: Win chance shown only above slot machine as redтЖТblue progress bar (server
comparative % from `barrels_drawn.win_chances`). Player sidebar: nickname, cards,
status only. Client records per-turn snapshots for game-over line chart (no protocol
change). History lost on mid-game refresh/reconnect тАФ acceptable client-only scope.

CHANGED:
- Personal win-chance UI: progress bar with `winChanceBarColor()` gradient
- `renderGamePlayers()` / sidebar: no win-chance column
- `showGameOver()` renders turn-indexed probability chart after statistics table

NOT CHANGED:
- `win_chances` server calculation (ADR-014), protocol packets, payout logic

VERIFICATION:
- `php tests/Manual/test_frontend_structure.php` тАФ PASS.
- MANUAL: 2-player game тЖТ bar updates after each draw; sidebar has no %; game-over
  modal shows multi-line chart when тЙе2 turns recorded.

- [DONE] FIX-26 F2 in-game reconnect QA hotkey + guard fix on page refresh
Files:
- public/js/ws.js (diff тАФ `simulateTransportDrop()` closes WS without `intentionalClose`)
- public/js/app.js (diff тАФ F2 key during `playing`; reconnect guard loads persisted user)
- public/locales/*.json (diff тАФ `dev.f2Disconnect`, `dev.f2PlayingOnly`)
- docs/LOCAL_ENVIRONMENT.md (diff тАФ F1/F2 manual reconnect steps)
- tests/Manual/test_frontend_structure.php (diff тАФ F2 + simulateTransportDrop checks)

Notes: F2 reconnect manual test (playing-phase disconnect within 15s) had no way to
trigger transport loss without closing the tab (which clears sessionStorage and
aborts auto-reconnect). F2 simulates a drop while keeping the tab session alive.
Reconnect guard now calls `ensureUserProfile()` before timing out so F5 refresh
during a game does not spuriously clear localStorage while `reconnect_state` is
in flight.

CHANGED:
- `LottoSocket.simulateTransportDrop()` for QA
- F2 handler: only when `state.inGame`; toast + auto-reconnect path
- `startReconnectGuard()`: load persisted profile, 10s timeout, skip clear if user restored

NOT CHANGED:
- Server reconnect/disconnect logic, 15s playing grace, F1 lobby immediate removal

VERIFICATION:
- `php tests/Manual/test_frontend_structure.php` тАФ PASS (+2 assertions).
- MANUAL (F2): 2-player game тЖТ press F2 тЖТ reconnect overlay тЖТ game restored with toast
  ┬лSession restored┬╗; if your turn, draw button enabled (FIX-17). F2 in lobby shows hint only.

- [DONE] FIX-25 Quick Start pseudo-random room pick when multiple eligible
Files:
- public/js/ui.js (diff тАФ `pickQuickStartRoom()` filters + random choice among eligible)
- public/js/app.js (diff тАФ `doQuickStart()` uses `pickQuickStartRoom` instead of `.find()`)
- docs/GAME_RULES.md (diff тАФ ┬з2 quick start multi-room behavior)

Notes: Previously Quick Start always joined the first eligible room in `room_list` order.
Now: 0 eligible тЖТ error; 1 тЖТ that room; 2+ тЖТ `Math.floor(Math.random() * n)`.

CHANGED:
- `pickQuickStartRoom()` helper exported from UI module
- `doQuickStart()` uses pseudo-random selection

NOT CHANGED:
- Eligibility rules (`waiting`, no password, not full), join_room flow, server room_list

VERIFICATION:
- MANUAL: create 2+ open waiting rooms without password тЖТ Quick Start joins a random one
  across repeated clicks (before joining); single room тЖТ always that room.

- [DONE] FIX-24 No lobby reconnect grace тАФ waiting disconnect removes player immediately
Files:
- src/Game/ReconnectService.php (diff тАФ `handleDisconnect()` waiting тЖТ `removePlayerFromLobby`; timer callback playing-only)
- public/js/app.js (diff тАФ `auth_result` clears stale room; `player_left` reason `disconnect` resets lobby)
- tests/Manual/test_reconnect.php (diff тАФ GROUP 1b waiting immediate removal; GROUP 2 playing timeout)
- tests/Manual/test_timer_audit.php (diff тАФ GROUP 4 split waiting vs playing)

Notes: Closes reconnect F1: disconnected lobby player stayed in `room['players']` as
`disconnected` with 15s timer, inflating room_list counts and allowing stale reconnect.
User rule: no reconnect tracking in lobby; only after `start_game` (`playing`). Page-refresh
re-key in waiting (active before onClose) unchanged via `handleReconnect()` GROUP 3b.
ANCHOR_CORE.md still lists reconnect in `waiting` тАФ behavior intentionally overridden per
user instruction (Rule 1); doc sync deferred.

CHANGED:
- Lobby disconnect: immediate `removePlayerFromLobby(..., 'disconnect')` + `broadcastRoomList`
- Client: clear `state.room` on `auth_result` when not restored to room; handle self `disconnect` `player_left`

NOT CHANGED:
- Playing-state 15s reconnect timer, apartment immediate removal, in-game `reconnect_state`

VERIFICATION:
- `php tests/Manual/test_reconnect.php` тАФ **111/111 PASS** (+GROUP 1b, GROUP 2 retargeted).
- `php tests/Manual/test_timer_audit.php` тАФ **24/24 PASS**.
- MANUAL (F1): join room in lobby, disconnect tab тЖТ player gone from room_list immediately;
  reconnect shows lobby without stale membership; can join same room again.

- [DONE] FIX-23 Game-over modal lists all winners on shared victory
Files:
- public/js/ui.js (diff тАФ `showGameOver()` derives winners from `statistics[].received > 0`)
- public/locales/*.json (diff тАФ `game.winnersLine` plural headline, 6 locales)

Notes: `game_over.winner` is a single string (first winner, backward-compat field). Bank/payout
table was already correct via `statistics`. Modal headline now joins all usernames with
`received > 0` and shows `final_bank` for multi-winner shared prize total.

CHANGED:
- `showGameOver()`: multi-winner headline from statistics; `winnersLine` i18n key

NOT CHANGED:
- `game_over` protocol, `GameFinishService` payout math, statistics table rendering

VERIFICATION:
- MANUAL: trigger double victory (2+ winners same barrel) тАФ modal headline names all
  winners and total shared prize matches table sum; single-winner unchanged.

- [DONE] FIX-22 Apartment alert shown immediately (not behind barrel animation)
Files:
- public/js/app.js (diff тАФ `onApartmentAlert()` no longer uses `enqueueAnimation`)

Notes: Non-immune players were kicked to lobby before seeing agree/refuse: server
`apartment_timer` (10s per ANCHOR_CORE) starts when `apartment_alert` is sent, but
the client queued the modal behind `barrels_drawn` slot animation (~8s+). Server timed
out тЖТ `player_left` reason=refuse тЖТ `resetToLobby()` before modal appeared.

CHANGED:
- `onApartmentAlert()`: call `UI().showApartment()` synchronously on packet receipt

NOT CHANGED:
- Server apartment timer duration (10s), `ApartmentService` logic, protocol, animation queue for barrels

VERIFICATION:
- MANUAL: 2тАУ3 player game, trigger apartment тАФ required (non-immune) player sees
  agree/refuse modal immediately with full ~10s countdown; not thrown to lobby until
  timeout/refuse. Immune player sees wait screen immediately.
- No automated test (client UI timing).

- [DONE] FIX-21 GAME_RULES.md ┬з5 ┬л╨Ъ╨▓╨░╤А╤В╨╕╤А╨░┬╗ direction and payment amount corrected
Files:
- docs/GAME_RULES.md (diff тАФ swap immune/required categories; 10 тЖТ 5 coins)

Notes: Documentation-only correction paired with FIX-20. Prior GAME_RULES.md ┬з5 had
immunity/payment backwards (source of FIX-19's wrong direction). Payment now matches
`ApartmentService::APARTMENT_PAYMENT` (5) and ANCHOR_CORE.md.

VERIFICATION:
- Manual review тАФ immune = closed-row players; required = all others; 5 coins; 10s timer unchanged.

- [DONE] FIX-20 Apartment immunity direction corrected (reversal of FIX-19)
Files:
- src/Game/ApartmentService.php (diff тАФ `prepareApartment()`: closed row тЖТ immune; no line тЖТ required)
- tests/Manual/test_apartment.php (diff тАФ GROUP 3/5 assertions flipped to match)

Notes: **Direction correction.** FIX-19 correctly wired `hasLine()` but used inverted
semantics copied from GAME_RULES.md ┬з5 (which was itself backwards). User-confirmed
correct design (Rule 1 authority): players WITH a closed row earned immunity (triggered
the event); players WITHOUT must pay APARTMENT_PAYMENT (5). Do NOT revert to FIX-19
direction even if an old GAME_RULES snapshot suggests otherwise тАФ see FIX-21.

CHANGED:
- `prepareApartment()`: `immune = hasLine`, `required = !hasLine`

NOT CHANGED:
- `hasLine()`, `shouldTrigger()`, `finishApartment()` post-agree `immune=true`, payment amount

VERIFICATION:
- `php tests/Manual/test_apartment.php` тАФ **51/51 PASS** (GROUP 3/5 assertions flipped).
- MANUAL: closed-row player sees immune wait screen; others see agree/refuse.

- [SUPERSEDED тАФ direction wrong] FIX-19 Apartment immunity computed from hasLine() at trigger time
Files:
- src/Game/ApartmentService.php (diff тАФ `prepareApartment()` derives required/immune from `hasLine()`, persists `player['immune']`)
- tests/Manual/test_apartment.php (diff тАФ GROUP 3 real cards/masks; 3-player regression)

Notes: Introduced `hasLine()`-based immunity (good) but inverted who pays vs who is immune
(wrong тАФ copied from backwards GAME_RULES.md ┬з5). Corrected by FIX-20/FIX-21.

- [DONE] FIX-18 Persist post-game_over balance to localStorage (client-only)
Files:
- public/js/app.js (diff тАФ `onGameOver()` calls `persistUser()` after updating `state.user.coins`)

Notes: Server-side payout math confirmed correct (GameFinishService / game.db).
Symptom "coins not given out" was stale localStorage after refresh: `onGameOver()`
updated in-memory balance and DOM but never persisted. `ensureUserProfile()` reads
localStorage on reconnect/refresh.

CHANGED:
- `onGameOver()`: call `persistUser(state.user)` after `paid`/`received` arithmetic

NOT CHANGED:
- `game_over` / `reconnect_state` protocol, server economy, GameFinishService,
  GameService bank/payout, PreparedStatements

VERIFICATION:
- MANUAL VERIFICATION REQUIRED: play 2тАУ3 player game to victory; confirm winner
  balance on screen; hard-refresh тЖТ balance matches post-win (not pre-game).
- Repeat for loser (paid > 0, received = 0) тАФ deduction survives refresh.

- [PROPOSED] ADR-016 Server-authoritative client balance (`coins` field)
Files:
- docs/ADR/016.md (new, Status: Proposed)

Notes: Proposes additive `coins` on `game_over.statistics[]`, `reconnect_state`,
and optional `balance_updated` for admin/apartment paths. Implementation blocked
until ADR accepted (Rule 7). FIX-18 is interim mitigation only.

VERIFICATION:
- N/A тАФ ADR draft for user review. Post-approval verification matrix in ADR ┬зImplementation Epic.

## Phase 17 тАФ Compliance Audit Fixes (2026-08-02)

- [DONE] FIX-17 Reconnecting active drawer restores draw-button UI (client-only)
Files:
- public/js/app.js (diff тАФ `onReconnectState()` playing branch sets `state.isMyTurn` from `current_drawer`)

Notes: `reconnect_state.current_drawer` was already correct; `syncTurnUi()` branches
on `state.isMyTurn`, which was only set by `your_turn`. No protocol change. Server
AFK re-arm in `ReconnectService::restorePlayerConnection()` unchanged; no in-flight
auto-draw race тАФ `current_drawer` reflects `active_drawer_conn_id` at reconnect time.

CHANGED:
- `onReconnectState()` playing: derive `isMyTurn`, then `syncTurnUi()`

NOT CHANGED:
- `reconnect_state` payload, server reconnect/AFK logic, `your_turn` handler

VERIFICATION:
- MANUAL VERIFICATION REQUIRED: 2-player game, player A's turn, disconnect tab,
  reconnect within 15s тЖТ draw button visible/enabled; non-drawer sees waiting state.
- No automated test (client UI).

- [DONE] EPIC-9.3b Host transfer on player removal during apartment state
Files:
- src/Game/ApartmentService.php (diff тАФ `removePlayerFromApartment()` host FIFO reassignment + `host_changed` broadcast)
- tests/Manual/test_apartment.php (diff тАФ GROUP 10 host-refuse scenario)

Notes: Closes KNOWN GAP from EPIC-9.3 (`removePlayerFromApartment` stale `host_conn_id`).
Mirrors `ReconnectService::removePlayerFromGame()` FIFO-over-`drawer_order` logic.
`host_changed` broadcast matches `LobbyService` pattern (ADR-009); lobby timeout fields
omitted тАФ not applicable in apartment phase.

CHANGED:
- Host reassignment when removed conn was `host_conn_id`
- `broadcastHostChanged()` / `resolveHostUsername()` private helpers

NOT CHANGED:
- `removePlayerFromGame()` host path, lobby host transfer, Room/Player structure

VERIFICATION:
- `php tests/Manual/test_apartment.php` тАФ **45/45 PASS** (was 40; +GROUP 10).

- [DONE] EPIC-17.1 GAME_RULES.md win-chance description aligned with ADR-014
Files:
- docs/GAME_RULES.md (diff тАФ ┬з2 comparative win-chance wording)

Notes: Documentation-only. Player-facing language; no formula reproduction.

VERIFICATION:
- Manual review against ADR-014 ┬з Formula тАФ concept accurate, no new claims.

- [PENDING USER DECISION] EPIC-17.2 Protocol registry cleanup (`admin_stats_data`, `error.banned`)
Status: Blocked тАФ requires explicit path (implement vs deprecate via ADR). See ISSUE 4 in audit prompt.

- [PROPOSED] ADR-015 GameTurnService extraction draft (GameService file-size policy)
Files:
- docs/ADR/015.md (new, Status: Proposed)

Notes: No code extraction in this pass (Epic Isolation). Decomposition proposal only.

VERIFICATION:
- N/A тАФ written ADR for user review.

## Phase 16 тАФ Comparative Win-Chance (Server-Side)

- [DONE] EPIC-16.1 Comparative win-chance calculation and protocol wiring (ADR-014)
Files:
- docs/ADR/014.md (new)
- docs/ANCHOR_PROTOCOL.md (diff тАФ `win_chances` on `barrels_drawn` / `reconnect_state`)
- src/Game/VictoryService.php (diff тАФ `calculateWinChances()`)
- src/Game/GameService.php (diff тАФ wire into `broadcastBarrelsDrawn()`; passthrough; skip on victory draw)
- src/Game/ReconnectService.php (diff тАФ `reconnect_state` playing branch)
- public/js/app.js (diff тАФ opponents use server `win_chances`; self indicator unchanged)
- tests/Manual/test_victory.php (diff тАФ GROUP 7 unit tests)
- tests/Manual/test_turn_system.php (diff тАФ GROUP 7 integration)
- tests/Manual/test_reconnect.php (diff тАФ `MockGameService::calculateWinChances`; reconnect_state assert)

Notes: Fixes silently broken opponent win-chance (~0% always) by moving comparative
move-distance formula server-side. Informational only тАФ zero changes to victory
detection, prize calculation, apartment, AFK, economy, or state machine.
Opponent card numbers remain hidden; only coarse percentage exposed.

VERIFICATION:
- `php tests/Manual/test_victory.php` тАФ **48/48 PASS** (was 40; +GROUP 3b unit tests).
- `php tests/Manual/test_turn_system.php` тАФ **47/47 PASS** (was 42; +GROUP 7).
- `php tests/Manual/test_reconnect.php` тАФ **107/107 PASS** (+2 reconnect_state asserts).
- `php run_ALL_tests.php` тАФ baseline unchanged; no victory/prize regressions.

## Phase 15 тАФ AFK Audit Fixes (Fresh Findings)

- [DONE] EPIC-15.4 AFK-cascade last survivor excludes equally idle player (ADR-013)
Files:
- docs/ADR/013.md (new)
- docs/ANCHOR_CORE.md (diff тАФ ┬з Last Survivor qualifying condition for AFK removal)
- docs/GAME_RULES.md (diff тАФ Last Survivor vs mutual-AFK refund wording)
- src/Game/ReconnectService.php (diff тАФ `removePlayerFromGame()` AFK + survivor `auto_draws>0` тЖТ `handleNoSurvivors()`)
- tests/Manual/test_reconnect.php (diff тАФ GROUP 5 engaged survivor; 5b/5c both-idle refund; 5d non-afk unchanged)
- tests/Manual/test_timer_integrity.php (diff тАФ noop `handleNoSurvivors` mock for TEST 6b)

Notes: Closes economy loophole where second-to-last player removed for `afk` paid entire bank to a
survivor who had themselves accumulated `auto_draws > 0`. Option A (ADR-013): reuse existing
`handleNoSurvivors()` refund path; no new Player Structure field. Removal reasons `disconnect`,
`leave`, `refuse`, `kicked`, `banned` unchanged. `ApartmentService::removePlayerFromApartment()` has
no `count(active)===1` last-survivor branch тАФ out of scope.

VERIFICATION:
- `php tests/Manual/test_reconnect.php` тАФ **105/105 PASS** (was 77; +GROUP 5b/5c/5d, GROUP 5 split).
- `php tests/Manual/test_timer_integrity.php` тАФ **14/14 PASS**.
- `php tests/Manual/test_admin_kick.php` тАФ **39/39 PASS** (no double-refund regression).
- `php run_ALL_tests.php` тАФ **32/41** files pass (baseline unchanged; `test_timer_integrity` fixed).

- [DONE] EPIC-15.1 Zero-active no-survivors refund during playing (economic integrity)
Files:
- src/Game/GameFinishService.php (diff тАФ `handleNoSurvivors()`, `cancelRoomTimers()`, `snapshotRemainingPlayersToHistory()`; constructor `object` deps for testability)
- src/Game/GameService.php (diff тАФ `handleNoSurvivors()` passthrough)
- src/Game/ReconnectService.php (diff тАФ `count(active)===0` тЖТ refund path; unified active-player dispatch; removed dead `destroyRoom()`)
- src/Game/ApartmentService.php (diff тАФ delegate no-survivors to `GameService`; fix `removePlayerFromApartment` empty path; `sendJson` import)
- tests/Manual/test_reconnect.php (diff тАФ GROUP 8/8b no-survivors + refund assertions)
- tests/Manual/test_apartment.php (diff тАФ GROUP 9 apartment empty-path refund; `makeSvc()` wires real `GameFinishService`)

Notes: Closes ANCHOR_CORE Part 2 ┬з No Survivors / ┬з Economic Integrity Rule gap where
`removePlayerFromGame()` called bare `destroyRoom()` when `count(active)===0` or
`empty(players)` тАФ coins lost, zombie rooms with disconnected stragglers. Chose option (a):
refund logic centralized in `GameFinishService` (ADR-002 payout owner). Disconnected
stragglers snapshotted into `all_players_history` before refund; reconnect timers cancelled;
`bank` explicitly zeroed.

VERIFICATION:
- `php tests/Manual/test_reconnect.php` тАФ **65/65 PASS** (was 52; +GROUP 8/8b).
- `php tests/Manual/test_apartment.php` тАФ **38/38 PASS** (was 36; +GROUP 9).
- `php run_ALL_tests.php` тАФ **32/41** files pass (baseline 31/41 pre-epic; `test_apartment.php` fixed).

- [DONE] EPIC-15.2 Progressive game AFK strike windows 30s / 15s / 5s (ADR-012)
Files:
- docs/ADR/012.md (new)
- docs/ANCHOR_CORE.md (diff тАФ ┬з Game AFK Timer thresholds table)
- docs/ANCHOR_PROTOCOL.md (diff тАФ `turn_seconds` semantics for `your_turn` / `afk_warning`)
- src/Core/Constants.php (diff тАФ `gameAfkStrikeWindowSeconds()`; removed dead flat-30 helpers)
- src/Game/ReconnectService.php (diff тАФ `tickGameAfk()` per-strike window lookup)
- src/Game/GameService.php (diff тАФ `sendYourTurn()` / packet `turn_seconds` per `auto_draws`)
- tests/Manual/test_reconnect.php (diff тАФ GROUP 4/4b/4c/5/6 boundary + `turn_seconds` assertions)
- tests/Manual/test_timer_audit.php (diff тАФ `LOTTO_GAME_AFK_STRIKE1/2/3` env override tests)

Notes: `auto_draws` semantics unchanged (ADR-008). Client (`public/js/ui.js`) already uses
server `turn_seconds` тАФ no hardcoded 30s dependency beyond falsy fallback.

VERIFICATION:
- `php tests/Manual/test_reconnect.php` тАФ **71/71 PASS** (strike 1тЙе30s, strike 2тЙе15s, strike 3тЙе5s boundaries; `turn_seconds` 30/15 in packets).
- `php tests/Manual/test_timer_audit.php` тАФ **22/22 PASS**.
- `php run_ALL_tests.php` тАФ **32/41** files pass (no new failures vs EPIC-15.1 sign-off).

## Phase 14 тАФ AFK Timer Audit Fixes

- [DONE] EPIC-14.9 GAME_RULES.md: align lobby AFK activity examples with allowlist
Files:
- docs/GAME_RULES.md (diff тАФ ┬з4 ┬л╨Т ╨╗╨╛╨▒╨▒╨╕┬╗: drop misleading ┬л╨Э╨░╤З╨░╤В╤М ╨╕╨│╤А╤Г┬╗ example;
  list `room_list` / create / join / leave; note start_game ends waiting phase)

Notes: Documentation-only polish. Matches EPIC-14.5 `$lobbyHostActivityActions` in
server.php. No code or test changes.

VERIFICATION:
- Manual review against ANCHOR_CORE.md ┬з Lobby AFK Timer and ADR-010 тАФ consistent.

- [DONE] EPIC-14.8 Fix stale ADR-007 citations in lobby integration test comments
Files:
- tests/Manual/test_lobby_integration.php (diff тАФ SUITE 5 comments: ADR-007 тЖТ ADR-011)

Notes: Comment-only traceability cleanup (ADR-011 retroactive doc). No logic change.
Grep confirmed no remaining incorrect ┬лADR-007┬╗ / ┬лA7 spec┬╗ citations outside
legitimate ADR-007 subjects (`error.banned`, `afk_warning` protocol audit).

VERIFICATION:
- `php tests/Manual/test_lobby_integration.php` тАФ 133/133 PASS (unchanged logic).

- [DONE] EPIC-14.6 Clear stale lobby joined message on leave room
Files:
- public/js/app.js (diff тАФ `resetToLobby()` clears `#lobby-message`)

Notes: Cosmetic UI fix only; unrelated to AFK timing logic. Stale ┬л╨Т╤Л ╨▓ ╨║╨╛╨╝╨╜╨░╤В╨╡
#N┬╗ text persisted after `leave_room` because `onRoomJoined` set the message but
`resetToLobby()` did not clear it.

VERIFICATION:
- Manual UI: leave room тЖТ `#lobby-message` empty; lobby timers unaffected.
- `php tests/Manual/test_lobby_integration.php` тАФ 133/133 PASS (no test change).
- `php run_ALL_tests.php` тАФ 30/41 test files PASS (11 pre-existing failures
  unrelated to this one-line client fix; same baseline as EPIC-14.1 sign-off).

- [DONE] EPIC-14.5 Fix lobby AFK 120s display and turn passing after start_game
Files:
- server.php (diff тАФ `hello` packet gains `server_time`; `touchLobbyHostActivity`
  restricted to waiting-room lobby-action allowlist: `room_list`, `create_room`,
  `join_room`, `leave_room` тАФ excludes `start_game` and all in-game/admin actions)
- public/js/app.js (diff тАФ server clock skew from `hello`; `onHostChanged` ignored
  while `state.inGame`)
- public/js/ui.js (diff тАФ `setServerClockSkew` / `serverNowSec()` for lobby and
  game AFK countdown displays)
- src/Game/ReconnectService.php (diff тАФ `reconnect_state` `host_timeout_start`
  sourced from `host_activity_at`, not stale `last_action`)
- src/Lobby/LobbyService.php (diff тАФ `startLobbyAfkTimer()` refreshes
  `host_activity_at` + broadcasts on arm; `touchLobbyHostActivity` broadcasts via
  `broadcastHostChanged` only)
- tests/Manual/test_lobby_integration.php (diff тАФ SUITE 7: timer arm sets full
  120s window assertion)

Notes: Closes residual EPIC-14.1 gap where `touchLobbyHostActivity` was wired
unconditionally for every action (including `start_game`), which re-broadcast
`host_changed` during game start and broke turn passing. Client clock skew caused
lobby countdown to open at ~105s instead of 120s when client clock led server.

VERIFICATION:
- `php tests/Manual/test_lobby_integration.php` тАФ 133/133 PASS (includes SUITE 7
  ┬лtimer arm sets full 120s window┬╗ + SUITE 8 ping-immunity from EPIC-14.1).
- `php run_ALL_tests.php` тАФ 30/41 test files PASS (11 pre-existing failures on
  Windows dev host: live WS subprocess tests, `sendJson` bootstrap gaps in some
  apartment/admin manual tests тАФ unchanged from EPIC-14.1 baseline).

- [DONE] EPIC-14.4 Update GAME_RULES.md AFK section to match per-turn model (ADR-008)
Files:
- docs/GAME_RULES.md (diff тАФ ┬з4 AFK: per-turn 30s threshold, cross-turn strike counting)

Notes: Documentation-only. Aligned with ANCHOR_CORE.md ┬з Game AFK Timer and ADR-008.

VERIFICATION:
- Manual review against ANCHOR_CORE.md ┬з Game AFK Timer тАФ wording consistent.

- [DONE] EPIC-14.3 Cancel game_afk_timer immediately on apartment transition
Files:
- src/Game/ApartmentService.php (diff тАФ explicit game_afk_timer_id cancel in triggerApartment)
- tests/Manual/test_apartment.php (diff тАФ GROUP 5b assertion; mock_timer bootstrap)

Notes: Defensive self-stop in ReconnectService::tickGameAfk() retained.

VERIFICATION:
- `php tests/Manual/test_apartment.php` тАФ all PASS (including GROUP 5b)

- [DONE] EPIC-14.2 Lobby AFK: document forward-only rotation + queue exhaustion (ADR-011)
Files:
- docs/ADR/011.md (╨╜╨╛╨▓╤Л╨╣ тАФ retroactive ADR for host rotation + room destruction)
- docs/ANCHOR_CORE.md (diff тАФ Room Destruction Rules 4th bullet; ADR-011 citation)
- src/Lobby/LobbyService.php (diff тАФ comment/citation corrections only)

Notes: No runtime behavior change. Replaces incorrect ADR-007 / "A7 spec" citations.

VERIFICATION:
- `php tests/Manual/test_lobby_integration.php` тАФ 132/132 PASS (unmodified logic)

- [DONE] EPIC-14.1 Lobby AFK timer: separate host_activity_at from ping keepalive
Files:
- docs/ADR/010.md (╨╜╨╛╨▓╤Л╨╣ тАФ host_activity_at Player Structure key)
- docs/ANCHOR_CORE.md (diff тАФ Player Structure, Lobby AFK Timer, Naming Registry)
- src/Lobby/LobbyService.php (diff тАФ host_activity_at, touchLobbyHostActivity, timer check)
- server.php (diff тАФ ping no longer syncs lobby AFK; touchLobbyHostActivity on real actions)
- tests/Manual/test_lobby_integration.php (diff тАФ SUITE 8 ping-immunity regression)

Notes: `ping` still updates `last_action` for connection liveness; lobby AFK reads
`host_activity_at` only (ADR-010). Game AFK unchanged.

VERIFICATION:
- `php tests/Manual/test_lobby_integration.php` тАФ all PASS (including SUITE 8)
- `php run_ALL_tests.php` тАФ 0 failures

---

## Phase 13 тАФ Game AFK Wiring & Orphaned-Method Fixes

- [DONE] EPIC-13.0 ADR: Game AFK timer wiring decision
Files:
- docs/AUDIT_ORPHANED_METHODS_2026-07-28.md (╨╜╨╛╨▓╤Л╨╣ тАФ archived audit report)
- docs/ADR/008.md (╨╜╨╛╨▓╤Л╨╣ тАФ startTurn + setter wiring decision)
- docs/ROADMAP.md (diff тАФ Phase 13 added, skip note updated)

Decision: ADR-008 option (c) тАФ `GameService::startTurn()` atomically sends
`your_turn` and arms AFK timer via post-construction `setReconnectService()`.

- [DONE] EPIC-13.1 Wire first-turn your_turn + AFK arm into handleStartGame()
Files:
- src/Game/GameService.php (diff тАФ startTurn, setReconnectService, handleStartGame)
- server.php (diff тАФ setReconnectService wiring)

Verification: `php tests/Manual/test_game_start.php` тАФ 46/46 PASS (Group 7 lines
401тАУ402 updated; Group 10 afk_start assertion updated for drawer).

- [DONE] EPIC-13.2 Wire AFK arm into handleDrawBarrel() turn rotation
Files:
- src/Game/GameService.php (diff тАФ handleDrawBarrel uses startTurn)

Verification: `php tests/Manual/test_turn_system.php` тАФ 38/38 PASS. Group 4
flagged for EPIC-13.4: added afk_start assertion on next drawer.

- [DONE] EPIC-13.3 Wire AFK arm into drawer-replacement paths
Files:
- src/Game/ReconnectService.php (diff тАФ removePlayerFromGame uses startTurn)
- src/Game/ApartmentService.php (diff тАФ finishApartment uses startTurn)

Verification: `php tests/Manual/test_reconnect.php` 20/20, `test_apartment.php` 32/32.

- [DONE] EPIC-13.4 Test corrections + turn-start integration test
Files:
- tests/Manual/test_game_start.php (diff тАФ Group 7/10 assertions)
- tests/Manual/test_turn_system.php (diff тАФ Group 4 afk_start)
- tests/Manual/test_game_packet_routing.php (diff тАФ TEST 2 your_turn)
- tests/Manual/test_phase11_core_flows.php (diff тАФ your_turn assertion)
- tests/Manual/test_game_start_turn_integration.php (╨╜╨╛╨▓╤Л╨╣ тАФ 7/7 PASS)
- tests/Manual/test_admin_ban.php (diff тАФ MockApartmentService stub)

Verification: `php run_ALL_tests.php` тАФ 41/41 test files PASS (local Windows
dev host, 2026-07-28). VPS `./run_ALL_tests.sh` initially failed with FIX-16
(8 live WS subprocess tests тАФ server.php fatal on missing bootstrap helper);
re-verify on VPS after `0de46d0` тАФ see FIX-16.

- [DONE] EPIC-13.5 Apartment early-finish check on kick/ban removal
Files:
- src/Game/ApartmentService.php (diff тАФ bindGameService, maybeFinishApartmentEarly)
- src/Admin/AdminService.php (diff тАФ kick/ban apartment paths)
- server.php (diff тАФ bindGameService)
- tests/Manual/test_admin_kick.php (diff тАФ TEST 9 early-finish scenario)

Verification: test_admin_kick TEST 9 PASS; test_apartment 32/32.

- [DONE] EPIC-13.6 Investigation: reconnect mid-turn drawer turn-signal
Finding: **Frontend does NOT self-activate draw button from reconnect_state.**
`onReconnectState` (playing) calls `UI().setDrawButton(false, false)` and
`reconnect_state` carries no active-drawer field. Reconnecting drawer needs
separate `your_turn` resend or protocol extension тАФ deferred to follow-up Epic.

- [DONE] EPIC-13.7 Cleanup: RoomManager::findRoomIdByUserId()
Decision: **(b) intentionally-retained utility** тАФ docblock updated; no
production consumer planned; test coverage in test_lobby_integration.php kept.

**Process deviation (Rule 16 тАФ Git Checkpoint Rule):** Phase 13 commits on
branch `cursor/epic-11-1-vps-ws-test-isolation` did not strictly follow the
one-Epic-one-commit convention. EPIC-13.3 appears in **two** commit messages:
`8cd1434` (`EPIC-13.3 wire-afk-drawer-replacement` тАФ ReconnectService only)
and `f4cf0f4` (`EPIC-13.2-13.3 wire-afk-turn-rotation-and-apartment-resume`
тАФ ApartmentService `finishApartment`). EPIC-13.2 landed inside `b203493`
(`EPIC-13.1 start-game-first-turn`) because `handleDrawBarrel()` and
`handleStartGame()` share `GameService.php` in a single diff. All epics are
implemented and verified; numbering in commit messages is authoritative for
audit only тАФ see DECISION LOG 2026-07-28.

---

- [IN PROGRESS] EPIC-11.6 Load testing (Phase 11 тАФ instrumentation complete 2026-07-27; VPS load runs pending)
Files:
- src/Core/LoadAudit.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ opt-in handler latency + snapshots тЖТ logs/load_audit.log)
- server.php (diff тАФ LoadAudit wiring, onMessage latency recording, periodic snapshots)
- scripts/load_test_runner.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ ramp/steady/storm/long VPS scenarios)
- scripts/analyze_load_log.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ p95/CPU/memory acceptance validator)
- tests/Manual/test_load_audit.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ 30 mock regression tests)
- docs/PHASE_11_REPORT.md (diff тАФ EPIC-11.6 section updated)

Implemented:
- LoadAudit utility: LOTTO_LOAD_AUDIT=1 logs per-action handler latency_ms and
  periodic snapshots (mem, connections, rooms) for EPIC-11.6 targets.
- load_test_runner.php: four scenarios (ramp, steady, storm, long) with
  realistic register/room/game flows; client RTT тЖТ logs/load_client.log;
  CPU/memory sampling тЖТ logs/load_resource.log.
- analyze_load_log.php: validates p95 < 100ms (register/login/draw_barrel),
  peak memory < 450 MB, peak CPU < 80%.
- test_load_audit.php: utility, percentile math, client/resource log parsing.

Verification (Windows dev host):
- test_load_audit.php: 30/30 PASS
- load_test_runner.php: requires Linux/VPS (Workerman)
- analyze_load_log.php: runs after VPS load_test_runner completion

Remaining: Run load scenarios on Ubuntu VPS (1 CPU / 512 MB target):
  php scripts/load_test_runner.php --scenario=ramp --players=100 --games=10 --duration=300
  php scripts/load_test_runner.php --scenario=steady --duration=1800
  php scripts/load_test_runner.php --scenario=storm
  php scripts/load_test_runner.php --scenario=long --duration=3600

Next in Phase 11: Complete EPIC-11.1тАУ11.6 VPS sign-off runs per docs/PHASE_11_REPORT.md.

- [IN PROGRESS] EPIC-11.5 Protocol audit (Phase 11 тАФ instrumentation complete 2026-07-27; VPS live replay pending)
Files:
- docs/ANCHOR_CORE.md (diff тАФ afk_warning added to packet registry)
- docs/ANCHOR_PROTOCOL.md (diff тАФ afk_warning packet spec, error.banned note)
- docs/ADR/007.md (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ documentation alignment decisions)
- tests/Manual/test_protocol_audit.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ 7 live WS acceptance tests)
- scripts/ws_emulator.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ CLI client emulator + replay)
- tests/Manual/test_protocol_completeness.php (diff тАФ afk_warning gap closed)
- docs/PHASE_11_REPORT.md (diff тАФ EPIC-11.5 section updated)

Implemented:
- afk_warning registered in ANCHOR_CORE.md and documented in ANCHOR_PROTOCOL.md
  (ADR-007); closes W1 from static audit.
- error.banned documented as reserved/unused (ADR-007); `banned` packet is the
  canonical ban-rejection channel.
- test_protocol_audit.php: hello/protocol_version, extra-field extensibility,
  authenticated unknown action, missing fields, room_full live, auth_required.
- ws_emulator.php: --send, --replay (.jsonl), --interactive modes for
  protocol replay and manual audit.
- test_protocol_completeness.php: 50/50 PASS, 2 warnings (admin_stats_data,
  error.banned reserved тАФ both documented KNOWN GAPS).

Verification (Windows dev host):
- test_protocol_completeness.php: 50/50 PASS, 2 warnings (expected)
- test_protocol_audit.php: requires Linux/VPS (live Workerman subprocess)
- Full suite: php run_ALL_tests.php тАФ 29/29 test files passed (Windows;
  9 live-server tests skipped)

Remaining: Run test_protocol_audit.php on Ubuntu VPS; use ws_emulator.php
for session replay during live-game protocol sign-off.

- [IN PROGRESS] EPIC-11.4 State machine audit (Phase 11 тАФ instrumentation complete 2026-07-27; VPS live-game run pending)
Files:
- src/Core/StateMachineAudit.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ opt-in state transition logging тЖТ logs/state_machine_audit.log)
- src/Core/Helpers.php (diff тАФ lottoStateTransition/lottoStateReject/lottoPlayerStateTransition)
- server.php (diff тАФ StateMachineAudit wiring)
- src/Core/RoomManager.php, src/Game/GameService.php, src/Game/GameFinishService.php,
  src/Game/ApartmentService.php, src/Game/ReconnectService.php, src/Lobby/LobbyService.php,
  src/Admin/AdminService.php (diff тАФ transition/rejection hooks)
- tests/Manual/test_state_machine_audit.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ 29 mock regression tests)
- scripts/analyze_state_machine_log.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ log replay + transition validation)
- docs/PHASE_11_REPORT.md (diff тАФ EPIC-11.4 section updated)

Implemented:
- StateMachineAudit utility: LOTTO_STATE_AUDIT=1 logs room transitions, player
  transitions, and rejected actions per ANCHOR_CORE.md Part 4.
- Transition graph encoded: waitingтЖТplayingтЖТapartmentтЖТplayingтЖТfinishedтЖТdestroyed.
- Instrumentation at all status mutation sites + key rejection guards.
- test_state_machine_audit.php: utility, valid/invalid transitions, apartment
  cycle, apartment timeout, host disconnect/reconnect, join_room guard.
- analyze_state_machine_log.php: parse log, verify sequence against spec.

Verification (Windows dev host):
- test_state_machine_audit.php: 29/29 PASS
- Full suite: php run_ALL_tests.php тАФ 28/28 test files passed
- Existing state tests unchanged: test_phase11_core_flows.php (17/17),
  test_apartment.php (32/32), test_reconnect.php (20/20)

Remaining: Enable LOTTO_STATE_AUDIT=1 on VPS during live multi-game sessions;
run analyze_state_machine_log.php after sessions for full sign-off.

Next in Phase 11: EPIC-11.5 Protocol audit, then 11.6 per
docs/prompt phase 11 detail.md and docs/PHASE_11_REPORT.md.

- [IN PROGRESS] EPIC-11.3 Economy audit (Phase 11 тАФ instrumentation complete 2026-07-27; VPS live-game run pending)
Files:
- src/Core/EconomyAudit.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ opt-in financial event logging тЖТ logs/economy_audit.log)
- src/Core/Helpers.php (diff тАФ lottoEconomyRecord() helper)
- server.php (diff тАФ EconomyAudit wiring)
- src/Game/GameService.php, src/Game/GameFinishService.php, src/Game/ApartmentService.php,
  src/Admin/AdminService.php (diff тАФ audit hooks on stake/prize/burn/apartment/refund)
- tests/Manual/test_economy_audit.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ 32 mock regression tests)
- scripts/economy_integrity_runner.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ multi-scenario conservation check)
- scripts/analyze_economy_log.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ log replay + duplicate tx_id check)
- docs/PHASE_11_REPORT.md (diff тАФ EPIC-11.3 section updated)

Implemented:
- EconomyAudit utility: LOTTO_ECONOMY_AUDIT=1 logs stake/prize/apartment/refund/burn
  with tx_id, user_id, room_id, signed amount, microsecond timestamp.
- Transaction sites instrumented: startGame stakes, finishGame prizes+burn,
  apartment payments, admin kick/close refunds, no-survivors refunds.
- Conservation invariant: sum(user coins) + room banks + burned = initial total.
- test_economy_audit.php: utility, replay, VictoryService math, GameFinishService integration.
- economy_integrity_runner.php: 4-scenario chain (stake тЖТ prize/burn тЖТ apartment тЖТ refund).
- analyze_economy_log.php: parse log, optional --initial replay verification.

Verification (Windows dev host):
- test_economy_audit.php: 32/32 PASS
- economy_integrity_runner.php: PASS
- Existing economy tests unchanged: test_victory.php (40/40), test_apartment.php (32/32),
  test_admin_integration.php (20/20)

Remaining: Enable LOTTO_ECONOMY_AUDIT=1 on VPS during live multi-game sessions;
run analyze_economy_log.php with --initial balances for full sign-off.

Next in Phase 11: EPIC-11.5 Protocol audit, then 11.6 per
docs/prompt phase 11 detail.md and docs/PHASE_11_REPORT.md.

- [IN PROGRESS] EPIC-11.2 Timer audit (Phase 11 тАФ instrumentation complete 2026-07-27; VPS accelerated run pending)
Files:
- src/Core/TimerAudit.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ opt-in timer lifecycle logging тЖТ logs/timer_audit.log)
- src/Core/Constants.php (diff тАФ env-resolved timeout accessors + AFK/APARTMENT constants)
- src/Core/Helpers.php (diff тАФ lottoTimerAdd/lottoTimerDel wrappers with audit hooks)
- server.php (diff тАФ TimerAudit wiring, watchdog uses env-resolved timeouts)
- src/Lobby/LobbyService.php, src/Game/ReconnectService.php, src/Game/ApartmentService.php,
  src/Game/GameService.php, src/Game/GameFinishService.php, src/Core/RoomManager.php
  (diff тАФ all Timer::add/del migrated to lottoTimer* wrappers)
- tests/Manual/test_timer_audit.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ 20 mock regression tests)
- tests/Manual/mock_timer.php (diff тАФ fire()/fireAll() for accelerated mock tests)
- scripts/timer_accelerated_runner.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ VPS accelerated timer scenarios)
- scripts/analyze_timer_log.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ drift ┬▒200ms + orphan check)
- tests/Manual/ws_test_harness.php (diff тАФ LOTTO_TIMER_AUDIT_LOG isolation)
- docs/PHASE_11_REPORT.md (diff тАФ EPIC-11.2 section updated)

Implemented:
- TimerAudit utility: LOTTO_TIMER_AUDIT=1 logs add/del/fire with microsecond timestamps.
- Env overrides for accelerated VPS testing: LOTTO_RECONNECT_TIMEOUT,
  LOTTO_LOBBY_HOST_TIMEOUT, LOTTO_UNAUTHORIZED_TIMEOUT, LOTTO_AUTHORIZED_TIMEOUT,
  LOTTO_APARTMENT_TIMEOUT, LOTTO_GAME_AFK_WARN1/WARN2/AUTO, LOTTO_WATCHDOG_INTERVAL,
  LOTTO_AFK_TICK_INTERVAL.
- lottoTimerAdd/lottoTimerDel: single instrumentation seam for all production timers.
- test_timer_audit.php: TimerAudit utility, env overrides, RoomManager cleanup,
  reconnect schedule/cancel, lobby AFK start/stop, single-shot fire semantics.
- VPS tooling: timer_accelerated_runner.php (5s reconnect default) +
  analyze_timer_log.php (acceptance: no orphans, drift тЙд200ms).

Verification (Windows dev host):
- test_timer_audit.php: 20/20 PASS
- test_timer_integrity.php: 5/5 PASS (FIX-6 regression, unchanged)
- Full suite: php run_ALL_tests.php тАФ 26/26 test files passed

Remaining: Run timer_accelerated_runner.php on Ubuntu VPS for live drift
acceptance sign-off per EPIC-11.2 acceptance criteria.

Next in Phase 11: EPIC-11.5 Protocol audit, then 11.6 per
docs/prompt phase 11 detail.md and docs/PHASE_11_REPORT.md.

- [IN PROGRESS] EPIC-11.1 Memory audit (Phase 11 тАФ instrumentation complete 2026-07-27; VPS 6h run pending)
Files:
- src/Core/MemoryAudit.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ opt-in memory snapshots тЖТ logs/memory_audit.log)
- server.php (diff тАФ worker_start/connection/packet/periodic snapshots)
- src/Core/RoomManager.php (diff тАФ room_created/room_destroyed snapshots)
- tests/Manual/test_memory_audit.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ mock regression: map cleanup, bounded growth)
- scripts/memory_stability_runner.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ 6-hour VPS load test, Linux only)
- scripts/analyze_memory_log.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ validates тЙд120% baseline threshold)
- docs/PHASE_11_REPORT.md (diff тАФ EPIC-11.1 section updated)

Implemented:
- MemoryAudit utility: LOTTO_MEMORY_AUDIT=1 enables structured snapshots;
  LOTTO_MEMORY_AUDIT_VERBOSE=1 logs every handled action (default: tracked
  lifecycle actions only).
- FIX-13 (ownership): MemoryAudit now accepts optional log path (constructor
  param + LOTTO_MEMORY_AUDIT_LOG env), mirroring FIX-12 Logger DI-seam.
  test_memory_audit.php writes to sys_get_temp_dir() only; production
  logs/memory_audit.log hash verified unchanged after test run.
- server.php: baseline at worker start, connection open/close, packet
  snapshots for state-mutating actions, 30-minute periodic timer.
- RoomManager: snapshots on createRoom/destroyRoom.
- test_memory_audit.php: map cleanup, timer orphan check, 50-cycle
  create/destroy bounded-growth test, log file write verification.
- VPS tooling: memory_stability_runner.php (6h default) +
  analyze_memory_log.php (acceptance: memory тЙд120% baseline).

Verification (Windows dev host):
- test_memory_audit.php: all groups PASS
- Full suite: php run_ALL_tests.php (includes new test file)

Remaining: Run memory_stability_runner.php on Ubuntu VPS for 6-hour
acceptance sign-off per EPIC-11.1 acceptance criteria.

FIX-14 (VPS test isolation, 2026-07-27): Live WS tests now use port 18080
and temp-dir logs via tests/Manual/ws_test_harness.php тАФ no collision with
production lotto-server.service on 8080, no writes to root-owned logs/.
test_helpers_runner.php scenario 4 no longer writes to production server.log.
test_logger.php removed (superseded by FIX-12). server.php accepts
LOTTO_WS_PORT, LOTTO_SERVER_LOG, LOTTO_WORKERMAN_LOG_FILE,
LOTTO_WORKERMAN_PID_FILE env vars for test subprocess isolation.

Next in Phase 11: EPIC-11.5 Protocol audit, then 11.6 per
docs/prompt phase 11 detail.md and docs/PHASE_11_REPORT.md.

<<<<<<< HEAD
- [DONE] EPIC-11.0 Full integration testing (Phase 11 audit, 2026-07-27)
=======
- [DONE] EPIC-11.0 Full integration testing (Phase 11 audit тАФ 2026-07-27)
>>>>>>> cursor/epic-11-1-vps-ws-test-isolation
Files:
- tests/Manual/test_admin_ban.php (diff тАФ FIX-11 MockConnection::close())
- tests/Manual/test_admin_integration.php (diff тАФ FIX-11 SpyConnection::close())
- tests/Manual/test_phase11_core_flows.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ chained authтЖТlobbyтЖТgame flows)
- run_ALL_tests.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ cross-platform runner, SQLite on Windows)
- docs/PHASE_11_REPORT.md (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ consolidated Phase 11 audit report)

тЪая╕П CORRECTION (2026-07-27, post-VPS regression): ╨┐╤А╨╡╨┤╤Л╨┤╤Г╤Й╨░╤П ╨▓╨╡╤А╤Б╨╕╤П ╤Н╤В╨╛╨╣
╨╖╨░╨┐╨╕╤Б╨╕ ╨╛╤И╨╕╨▒╨╛╤З╨╜╨╛ ╤Г╤В╨▓╨╡╤А╨╢╨┤╨░╨╗╨░, ╤З╤В╨╛ `server.php` ╨▒╤Л╨╗ ╨╕╨╖╨╝╨╡╨╜╤С╨╜ ╨▓ ╤А╨░╨╝╨║╨░╤Е ╨┤╨░╨╜╨╜╨╛╨│╨╛
Epic ╨┤╨╗╤П ╤Г╤Б╤В╤А╨░╨╜╨╡╨╜╨╕╤П "╨║╤А╨╕╤В╨╕╤З╨╡╤Б╨║╨╛╨│╨╛ ╨┐╤А╨╛╨▒╨╡╨╗╨░ P11-001" (admin_* wiring
╤П╨║╨╛╨▒╤Л ╨╛╤В╤Б╤Г╤В╤Б╤В╨▓╨╛╨▓╨░╨╗). ╨н╤В╨╛ ╨▒╤Л╨╗╨╛ ╨╗╨╛╨╢╨╜╤Л╨╝ ╤Б╤А╨░╨▒╨░╤В╤Л╨▓╨░╨╜╨╕╨╡╨╝, ╨┐╨╛╨╗╤Г╤З╨╡╨╜╨╜╤Л╨╝ ╨╜╨░
Windows-╨╛╨║╤А╤Г╨╢╨╡╨╜╨╕╨╕ ╤Б ╨╜╨╡╤Б╨╕╨╜╤Е╤А╨╛╨╜╨╕╨╖╨╕╤А╨╛╨▓╨░╨╜╨╜╨╛╨╣ ╨╗╨╛╨║╨░╨╗╤М╨╜╨╛╨╣ ╨║╨╛╨┐╨╕╨╡╨╣: ╤А╨╡╨░╨╗╤М╨╜╤Л╨╣
`server.php` ╤Г╨╢╨╡ ╤Б╨╛╨┤╨╡╤А╨╢╨░╨╗ ╨┐╨╛╨╗╨╜╤Л╨╣ admin-╤А╨╛╤Г╤В╨╕╨╜╨│ ╤Б 2026-07-25
(commit 5ad67d5, EPIC-10.6). ╨Ф╨╕╤Д ╨║╨╛╨╝╨╝╨╕╤В╨░ 6efede1 (git show --stat)
╨┐╨╛╨┤╤В╨▓╨╡╤А╨╢╨┤╨░╨╡╤В, ╤З╤В╨╛ `server.php` ╨▓ ╨╜╤С╨╝ ╨Э╨Х ╨╝╨╡╨╜╤П╨╗╤Б╤П. Rule 22 (Test
Philosophy) ╤В╤А╨╡╨▒╤Г╨╡╤В, ╤З╤В╨╛╨▒╤Л ╨║╨░╨╢╨┤╤Л╨╣ ╤Д╨╕╨║╤Б ╨▒╤Л╨╗ ╨┐╨╛╨┤╤В╨▓╨╡╤А╨╢╨┤╤С╨╜ ╨║╨░╨║
non-false-positive ╨┤╨╛ ╨╖╨░╨╜╨╡╤Б╨╡╨╜╨╕╤П ╨▓ ╤Б╤В╨░╤В╤Г╤Б; ╨┤╨╗╤П P11-001 ╤Н╤В╨╛ ╨┐╤А╨░╨▓╨╕╨╗╨╛ ╨▒╤Л╨╗╨╛
╨╜╨░╤А╤Г╤И╨╡╨╜╨╛. ╨Ч╨░╨┐╨╕╤Б╤М ╨╕╤Б╨┐╤А╨░╨▓╨╗╨╡╨╜╨░ ╨╖╨░╨┤╨╜╨╕╨╝ ╤З╨╕╤Б╨╗╨╛╨╝; ╤Б╨░╨╝ ╨║╨╛╨┤ admin-╤А╨╛╤Г╤В╨╕╨╜╨│╨░
╨┐╨╛╨┤╤В╨▓╨╡╤А╨╢╨┤╤С╨╜ ╤А╨░╨▒╨╛╤З╨╕╨╝ (╤Б╨╝. Verification ╨╜╨╕╨╢╨╡) тАФ ╤А╨╡╨│╤А╨╡╤Б╤Б╨╕╨╕ ╨┐╨╛ ╤Б╤Г╤Й╨╡╤Б╤В╨▓╤Г ╨╜╨╡╤В,
╨╛╤И╨╕╨▒╨╛╤З╨╜╨╛╨╣ ╨▒╤Л╨╗╨░ ╤В╨╛╨╗╤М╨║╨╛ ╨░╤В╤А╨╕╨▒╤Г╤Ж╨╕╤П ╨╕╨╖╨╝╨╡╨╜╨╡╨╜╨╕╤П.

Implemented:
- FIX-11 mock close() ╨▓╨╛╤Б╤Б╤В╨░╨╜╨╛╨▓╨╗╨╡╨╜ ╨▓ test_admin_ban.php ╨╕
  test_admin_integration.php (AdminService::handleBanUser() ╨╖╨░╨║╤А╤Л╨▓╨░╨╡╤В
  ╨╛╨╜╨╗╨░╨╣╨╜-╤Ж╨╡╨╗╤М тАФ ╨▒╨╡╨╖ close() ╤В╨╡╤Б╤В╤Л ╨┐╨░╨┤╨░╨╗╨╕ Fatal error).
- ╨Э╨╛╨▓╤Л╨╣ test_phase11_core_flows.php: registerтЖТloginтЖТcreate_roomтЖТjoin_roomтЖТ
  start_game, invalid state transitions, rate-limit constants тАФ 17/17 PASSED.
- docs/PHASE_11_REPORT.md: ╨┐╨╡╤А╨▓╤Л╨╣ consolidated ╨╛╤В╤З╤С╤В Phase 11 (╤В╤А╨╡╨▒╤Г╨╡╤В
  ╤Б╨▓╨╡╤А╨║╨╕ ╤Б CORRECTION ╨▓╤Л╤И╨╡ ╨▓ ╤З╨░╤Б╤В╨╕ P11-001).

Verification:
- ╨Я╤А╨╡╨┤╨▓╨░╤А╨╕╤В╨╡╨╗╤М╨╜╨╛ (Windows dev host, php run_ALL_tests.php): 25/25
  runnable test files PASSED, 8 live-WS subprocess tests SKIP
  (Workerman ╤В╤А╨╡╨▒╤Г╨╡╤В Linux).
- ╨Ю╨Ъ╨Ю╨Э╨з╨Р╨в╨Х╨Ы╨м╨Э╨Ю (Ubuntu VPS, root@box-918838:/opt/lotto-game,
  php run_ALL_tests.php, 2026-07-27): ╨┐╨╛╨╗╨╜╤Л╨╣ ╤А╨╡╨│╤А╨╡╤Б╤Б ╨▓╤Б╨╡╤Е 31 ╤Д╨░╨╣╨╗╨╛╨▓ тАФ
  **31/31 test files PASSED**, ╨▓╨║╨╗╤О╤З╨░╤П ╨▓╤Б╨╡ 8 ╤А╨░╨╜╨╡╨╡ ╨┐╤А╨╛╨┐╤Г╤Й╨╡╨╜╨╜╤Л╤Е live-WS
  subprocess ╤В╨╡╤Б╤В╨╛╨▓ (test_server_bootstrap 18/18, test_packet_validation
  11/11, test_auth_packet_routing 18/18, test_lobby_packet_routing
  23/23, test_game_packet_routing 21/21, test_admin_packet_routing
  15/15, test_session_lifecycle 6/6, test_protocol_completeness
  50/50 + 3 known warnings). ╨н╤В╨╛ ╨┐╨╡╤А╨▓╨╛╨╡ ╨┐╨╛╨┤╤В╨▓╨╡╤А╨╢╨┤╨╡╨╜╨╕╨╡ ╨▓╤Б╨╡╨╣ Phase 10/11
  ╤Ж╨╡╨┐╨╛╤З╨║╨╕ ╨╜╨░ ╤А╨╡╨░╨╗╤М╨╜╨╛╨╝ Workerman-╨┐╤А╨╛╤Ж╨╡╤Б╤Б╨╡ ╤Б ╨╝╨╛╨╝╨╡╨╜╤В╨░ EPIC-10.7 тАФ
  admin-╤А╨╛╤Г╤В╨╕╨╜╨│ (EPIC-10.6) ╨╕ ╨▓╤Б╤П ╨╛╤Б╤В╨░╨╗╤М╨╜╨░╤П ╨┐╤А╨╛╤В╨╛╨║╨╛╨╗╤М╨╜╨░╤П ╨╝╨░╤А╤И╤А╤Г╤В╨╕╨╖╨░╤Ж╨╕╤П
  ╨┐╨╛╨┤╤В╨▓╨╡╤А╨╢╨┤╨╡╨╜╤Л ╤А╨░╨▒╨╛╤З╨╕╨╝╨╕ end-to-end ╨╜╨░ ╤Ж╨╡╨╗╨╡╨▓╨╛╨╣ ╨┐╨╗╨░╤В╤Д╨╛╤А╨╝╨╡, ╨╜╨╡ ╤В╨╛╨╗╤М╨║╨╛
  ╤Б╤В╨░╤В╨╕╤З╨╡╤Б╨║╨╕/╨╜╨░ ╨╝╨╛╨║╨░╤Е.

- [DONE] EPIC-10.1 Packet validation
Files:
- docs/ADR/003-rate-limiting-and-invalid-json-policy.md (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗)
- docs/ANCHOR_CORE.md (diff тАФ Connection Runtime Fields + Global Constants,
  Part 1 ╨╕ Part 6, ╤Б╨╛╨│╨╗╨░╤Б╨╜╨╛ ADR-003)
- docs/ANCHOR_PROTOCOL.md (diff тАФ ╤Г╤В╨╛╤З╨╜╨╡╨╜╨╕╨╡ ╤Б╨╡╨╝╨░╨╜╤В╨╕╨║╨╕ error.invalid_json)
- src/Core/Constants.php (diff тАФ RATE_LIMIT_PACKETS_PER_WINDOW=15,
  RATE_LIMIT_WINDOW_SECONDS=1)
- server.php (diff тАФ ╤А╨╡╨░╨╗╨╕╨╖╨░╤Ж╨╕╤П rate limiting ╨▓ onMessage, ╨╕╨╜╨╕╤Ж╨╕╨░╨╗╨╕╨╖╨░╤Ж╨╕╤П
  packetCount/packetWindowStart ╨▓ onWebSocketConnected)
- tests/Manual/test_packet_validation.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗)
- .gitignore (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ ╨┐╨╛╨┐╤Г╤В╨╜╨╛ ╨╛╨▒╨╜╨░╤А╤Г╨╢╨╡╨╜╤Л ╤Б╨╗╤Г╤З╨░╨╣╨╜╨╛ ╨╖╨░╨║╨╛╨╝╨╝╨╕╤З╨╡╨╜╨╜╤Л╨╡
  ╤А╨░╨╜╤В╨░╨╣╨╝-╨░╤А╤В╨╡╤Д╨░╨║╤В╤Л game.db-shm/game.db-wal/workerman.*.pid)

Implemented:
- ADR-003 ╨╖╨░╨║╤А╤Л╨▓╨░╨╡╤В ╨╛╨▒╨░ KNOWN GAP, ╨╖╨░╤Д╨╕╨║╤Б╨╕╤А╨╛╨▓╨░╨╜╨╜╤Л╤Е ╨┐╤А╨╕ ╨┐╤А╨╡-Phase-10 ╨░╤Г╨┤╨╕╤В╨╡:
  1. Rate limiting (docs/prompt.md): > RATE_LIMIT_PACKETS_PER_WINDOW (15)
     ╨┐╨░╨║╨╡╤В╨╛╨▓ ╨╖╨░ RATE_LIMIT_WINDOW_SECONDS (1) ╤Б╨╡╨║╤Г╨╜╨┤╤Г ╨╛╤В ╨╛╨┤╨╜╨╛╨│╨╛ ╤Б╨╛╨╡╨┤╨╕╨╜╨╡╨╜╨╕╤П
     тЖТ ╨╜╨╡╨╝╨╡╨┤╨╗╨╡╨╜╨╜╨╛╨╡ ╨╖╨░╨║╤А╤Л╤В╨╕╨╡ ╨С╨Х╨Ч error-╨┐╨░╨║╨╡╤В╨░. ╨б╤З╨╕╤В╨░╨╡╤В ╨Ы╨о╨С╨л╨Х ╨▓╤Е╨╛╨┤╤П╤Й╨╕╨╡
     ╤Б╨╛╨╛╨▒╤Й╨╡╨╜╨╕╤П (╨▓╨░╨╗╨╕╨┤╨╜╤Л╨╡/╨╜╨╡╨▓╨░╨╗╨╕╨┤╨╜╤Л╨╡/ping) тАФ ╨╕╨╜╨║╤А╨╡╨╝╨╡╨╜╤В ╨┤╨╛ json_decode.
  2. Invalid-JSON policy (╨┐╤А╨╛╤В╨╕╨▓╨╛╤А╨╡╤З╨╕╨╡ prompt.md "╨╖╨░╨║╤А╤Л╤В╤М ╤Б╨╛╨╡╨┤╨╕╨╜╨╡╨╜╨╕╨╡" vs
     ANCHOR_PROTOCOL.md error.invalid_json): ╤А╨╡╤И╨╡╨╜╨╛ ╨▓ ╨┐╨╛╨╗╤М╨╖╤Г
     ANCHOR_PROTOCOL.md тАФ ╨║╨╛╨┤ ╨╛╤И╨╕╨▒╨║╨╕ ╨┐╤А╨╡╨┤╨┐╨╛╨╗╨░╨│╨░╨╡╤В, ╤З╤В╨╛ ╨║╨╗╨╕╨╡╨╜╤В ╨╡╨│╨╛ ╨┐╨╛╨╗╤Г╤З╨╕╤В
     ╨╕ ╤А╨░╨╖╨▒╨╡╤А╤С╤В, ╨╖╨╜╨░╤З╨╕╤В ╤Б╨╛╨╡╨┤╨╕╨╜╨╡╨╜╨╕╨╡ ╨Э╨Х ╨╖╨░╨║╤А╤Л╨▓╨░╨╡╤В╤Б╤П. ╨Я╨╛╨┤╨║╤А╨╡╨┐╨╗╨╡╨╜╨╛ ╨┐╤А╨╡╤Ж╨╡╨┤╨╡╨╜╤В╨╛╨╝
     error.server_full (╤Г╨╢╨╡ ╤А╨╡╨░╨╗╨╕╨╖╨╛╨▓╨░╨╜ ╨▓ LobbyService ╤З╨╡╤А╨╡╨╖ sendError(),
     ╨╜╨╡ ╤З╨╡╤А╨╡╨╖ ╤А╨░╨╖╤А╤Л╨▓). ╨Ч╨░╤Й╨╕╤В╤Г ╨╛╤В ╤Д╨╗╤Г╨┤╨░ ╨╝╨░╨╗╤Д╨╛╤А╨╝╨╡╨┤-JSON ╨╛╨▒╨╡╤Б╨┐╨╡╤З╨╕╨▓╨░╨╡╤В rate
     limiting, ╨░ ╨╜╨╡ ╤А╨░╨╖╤А╤Л╨▓ ╨╜╨░ ╨┐╨╡╤А╨▓╨╛╨╝ ╨╜╨╡╨▓╨░╨╗╨╕╨┤╨╜╨╛╨╝ ╨┐╨░╨║╨╡╤В╨╡.
- ╨Ю╨▒╨░ ╤А╨╡╤И╨╡╨╜╨╕╤П ╤Д╨╛╤А╨╝╨░╨╗╨╕╨╖╨╛╨▓╨░╨╜╤Л ╨║╨░╨║ ADR-003 ╨╕ ╨╛╤В╤А╨░╨╢╨╡╨╜╤Л ╨▓ ANCHOR_CORE.md
  (╨╜╨╛╨▓╤Л╨╡ Connection Runtime Fields: packetCount, packetWindowStart) ╨╕
  ANCHOR_PROTOCOL.md (╤П╨▓╨╜╨╛╨╡ ╤Г╤В╨╛╤З╨╜╨╡╨╜╨╕╨╡ ╨┐╤А╨╛ error.invalid_json).

Verification (╨┐╨╛╨╗╨╜╨╛╤Б╤В╤М╤О ╨░╨▓╤В╨╛╨╝╨░╤В╨╕╤З╨╡╤Б╨║╨░╤П, ╤А╨╡╨░╨╗╤М╨╜╤Л╨╣ WebSocket-╨║╨╗╨╕╨╡╨╜╤В):
- tests/Manual/test_packet_validation.php тАФ 11/11 PASSED, 5 ╤Б╤Ж╨╡╨╜╨░╤А╨╕╨╡╨▓:
  1. ╨а╨╛╨▓╨╜╨╛ 15 ╨╜╨╡╨▓╨░╨╗╨╕╨┤╨╜╤Л╤Е ╨┐╨░╨║╨╡╤В╨╛╨▓ тАФ ╨▓╤Б╨╡ ╨┐╨╛╨╗╤Г╤З╨░╤О╤В error.invalid_json,
     ╤Б╨╛╨╡╨┤╨╕╨╜╨╡╨╜╨╕╨╡ ╨╢╨╕╨▓╨╛.
  2. 16-╨╣ ╨┐╨░╨║╨╡╤В ╨▓ ╤В╨╛╨╝ ╨╢╨╡ ╨╛╨║╨╜╨╡ тАФ ╨╖╨░╨║╤А╤Л╤В╨╕╨╡ ╨С╨Х╨Ч error-╨┐╨░╨║╨╡╤В╨░ (╨╛╤В╨╗╨╕╤З╨░╨╡╤В╤Б╤П ╨╛╤В
     ╤В╨░╨╣╨╝╨░╤Г╤В╨░ ╤З╨╡╤А╨╡╨╖ feof()-╨┐╤А╨╛╨▓╨╡╤А╨║╤Г, ╨╜╨╡ ╤В╨╛╨╗╤М╨║╨╛ ╨┐╨╛ ╨╛╤В╤Б╤Г╤В╤Б╤В╨▓╨╕╤О ╨╛╤В╨▓╨╡╤В╨░).
  3. Rate limit ╤Б╤З╨╕╤В╨░╨╡╤В ping ╨╜╨░╤А╨░╨▓╨╜╨╡ ╤Б ╨┐╤А╨╛╤З╨╕╨╝╨╕ (╨╜╨╡ ╨┤╨╡╨╗╨░╨╡╤В ╨╕╤Б╨║╨╗╤О╤З╨╡╨╜╨╕╤П ╨┤╨╗╤П
     ╨▓╨░╨╗╨╕╨┤╨╜╤Л╤Е action) тАФ 15 ping ╨╛╨║, 16-╨╣ ╨╖╨░╨║╤А╤Л╨▓╨░╨╡╤В ╤Б╨╛╨╡╨┤╨╕╨╜╨╡╨╜╨╕╨╡.
  4. ╨Ю╨║╨╜╨╛ ╤А╨╡╨░╨╗╤М╨╜╨╛ ╤Б╨▒╤А╨░╤Б╤Л╨▓╨░╨╡╤В╤Б╤П тАФ burst 15+╨┐╨░╤Г╨╖╨░>1s+burst 15 ╨╜╨╡ ╤Б╤Г╨╝╨╝╨╕╤А╤Г╨╡╤В╤Б╤П
     ╨▓ ╨╖╨░╨║╤А╤Л╤В╨╕╨╡.
  5. ╨Х╨┤╨╕╨╜╨╕╤З╨╜╤Л╨╣ ╨╜╨╡╨▓╨░╨╗╨╕╨┤╨╜╤Л╨╣ JSON ╨╜╨╡ ╨╖╨░╨║╤А╤Л╨▓╨░╨╡╤В ╤Б╨╛╨╡╨┤╨╕╨╜╨╡╨╜╨╕╨╡ (╨▒╨░╨╖╨╛╨▓╤Л╨╣ ADR-003
     ╤Б╤Ж╨╡╨╜╨░╤А╨╕╨╣ ╨▓╨╜╨╡ ╨║╨╛╨╜╤В╨╡╨║╤Б╤В╨░ rate limit).
- ╨Я╤А╨╛╨│╨╜╨░╨╜╨╛ 3 ╤А╨░╨╖╨░ ╨┐╨╛╨┤╤А╤П╨┤ тАФ ╤Б╤В╨░╨▒╨╕╨╗╤М╨╜╨╛, ~4s ╨║╨░╨╢╨┤╤Л╨╣, ╨▒╨╡╨╖ ╨╖╨╛╨╝╨▒╨╕-╨┐╤А╨╛╤Ж╨╡╤Б╤Б╨╛╨▓.
- ╨Я╨╛╨╗╨╜╤Л╨╣ ╤А╨╡╨│╤А╨╡╤Б╤Б ╨┐╨╛ ╨▓╤Б╨╡╨╝ 25 ╤Д╨░╨╣╨╗╨░╨╝ tests/Manual/*.php (╨▒╤Л╨╗╨╛ 24, ╨┤╨╛╨▒╨░╨▓╨╗╨╡╨╜
  test_packet_validation.php) тАФ 0 failed.

PHASE 10 тАФ WEBSOCKET PROTOCOL: IN PROGRESS (10.0, 10.1 done). ╨б╨╗╨╡╨┤╤Г╤О╤Й╨╕╨╣:
EPIC-10.2 Protocol error handling.

- [DONE] EPIC-10.0 Protocol router
Files:
- server.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗, 175 ╤Б╤В╤А╨╛╨║)
- tests/Manual/test_server_bootstrap.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗, 227 ╤Б╤В╤А╨╛╨║)

Implemented:
- Workerman bootstrap: websocket://0.0.0.0:8080, single worker (count=1),
  ╤Б╨╛╨│╨╗╨░╤Б╨╜╨╛ LOCAL_ENVIRONMENT.md ╨╕ ANCHOR_CORE.md Part 1.
- onWorkerStart: ╨╕╨╜╨╕╤Ж╨╕╨░╨╗╨╕╨╖╨░╤Ж╨╕╤П Database/Logger/RoomManager-╤Б╨╛╨▓╨╝╨╡╤Б╤В╨╕╨╝╨╛╨╣
  runtime-╨┐╨░╨╝╤П╤В╨╕ ($worker->rooms/userConnections/sessionTokens = []),
  Global Watchdog Timer (60s, ╨╖╨░╨║╤А╤Л╤В╨╕╨╡ ╨╝╤С╤А╤В╨▓╤Л╤Е ╤Б╨╛╨╡╨┤╨╕╨╜╨╡╨╜╨╕╨╣ ╨┐╨╛ ╨┐╨╛╤А╨╛╨│╨░╨╝
  AUTHORIZED_TIMEOUT/UNAUTHORIZED_TIMEOUT тАФ ANCHOR_CORE.md Part 5).
- onWebSocketConnected (╨╜╨╡ onConnect тАФ handshake ╨╜╨░ ╤Н╤В╨╛╤В ╨╝╨╛╨╝╨╡╨╜╤В ╤Г╨╢╨╡
  ╨╖╨░╨▓╨╡╤А╤И╤С╨╜, ╤З╤В╨╛ ╨┐╨╛╨┤╤В╨▓╨╡╤А╨╢╨┤╨╡╨╜╨╛ ╨┤╨╛╨║╨▒╨╗╨╛╨║╨╛╨╝ Workerman "Emitted after websocket
  handshake"): ╨╕╨╜╨╕╤Ж╨╕╨░╨╗╨╕╨╖╨░╤Ж╨╕╤П Connection Runtime Fields (userId/username/
  isAdmin/sessionToken/lastPing), ╨╜╨╡╨╝╨╡╨┤╨╗╨╡╨╜╨╜╨░╤П ╨╛╤В╨┐╤А╨░╨▓╨║╨░ hello
  {"type":"hello","protocol_version":1}.
- onMessage: ╨▒╨╡╨╖╨╛╨┐╨░╤Б╨╜╤Л╨╣ json_decode (╨╜╨╡-╨╛╨▒╤К╨╡╨║╤В тЖТ error.invalid_json),
  ping ╨▒╨╡╨╖ ╨╛╤В╨▓╨╡╤В╨░ (ANCHOR_PROTOCOL.md ┬з Heartbeat), ╨┐╤Г╤Б╤В╨╛╨╣ action-╨┤╨╕╤Б╨┐╨╡╤В╤З╨╡╤А
  (match/default тЖТ error.invalid_json ╨┤╨╗╤П ╨╗╤О╨▒╨╛╨│╨╛ ╨╡╤Й╤С ╨╜╨╡ ╨┐╨╛╨┤╨║╨╗╤О╤З╤С╨╜╨╜╨╛╨│╨╛ action).
- onClose: ╨┤╨╕╨░╨│╨╜╨╛╤Б╤В╨╕╤З╨╡╤Б╨║╨╛╨╡ ╨╗╨╛╨│╨╕╤А╨╛╨▓╨░╨╜╨╕╨╡ + ╤П╨▓╨╜╤Л╨╣ TODO тАФ ╨┐╨╛╨╗╨╜╨░╤П ╤А╨╡╨║╨╛╨╜╨╜╨╡╨║╤В-
  ╨╗╨╛╨│╨╕╨║╨░ ╨╜╨╡╨▓╨╛╨╖╨╝╨╛╨╢╨╜╨░ ╨▓ ╤Н╤В╨╛╨╝ Epic (╤Б╨╝. ╨╜╨╕╨╢╨╡).

╨б╨╛╨╖╨╜╨░╤В╨╡╨╗╤М╨╜╨╛ ╨Э╨Х ╤А╨╡╨░╨╗╨╕╨╖╨╛╨▓╨░╨╜╨╛ (Rule 11 Epic Isolation):
- ╨Ь╨░╤А╤И╤А╤Г╤В╨╕╨╖╨░╤Ж╨╕╤П auth/lobby/game/admin-╨┐╨░╨║╨╡╤В╨╛╨▓ тАФ EPIC-10.3/10.4/10.5/10.6.
  AuthHandler ╤Г╨╢╨╡ ╤Б╤Г╤Й╨╡╤Б╤В╨▓╤Г╨╡╤В (Phase 1), ╨╜╨╛ ╨╜╨╡ ╨┐╨╛╨┤╨║╨╗╤О╤З╤С╨╜.
  LobbyHandler/GameHandler/AdminHandler ╨╡╤Й╤С ╨┐╤А╨╡╨┤╤Б╤В╨╛╨╕╤В ╤Б╨╛╨╖╨┤╨░╤В╤М.
- Rate limiting (>15 ╨┐╨░╨║╨╡╤В╨╛╨▓/╤Б╨╡╨║) ╨╕ ╤В╨╛╤З╨╜╨░╤П policy ╨╜╨╡╨▓╨░╨╗╨╕╨┤╨╜╨╛╨│╨╛ JSON тАФ
  EPIC-10.1 (╤А╨╡╤И╨╡╨╜╨╛ ╤Б ╨┐╨╛╨╗╤М╨╖╨╛╨▓╨░╤В╨╡╨╗╨╡╨╝ ╤П╨▓╨╜╨╛, ╤Б╨╝. KNOWN GAPS).
- onClose тЖТ ReconnectService::handleDisconnect() ╨╜╨╡ ╨┐╨╛╨┤╨║╨╗╤О╤З╤С╨╜: ╤Б╨░╨╝
  ╨║╨╛╨╜╤Б╤В╤А╤Г╨║╤В╨╛╤А ReconnectService ╤В╤А╨╡╨▒╤Г╨╡╤В ╨Ю╨Ф╨Э╨Ю╨Т╨а╨Х╨Ь╨Х╨Э╨Э╨Ю LobbyService ╨Ш
  GameService тАФ ╨┐╨╛╨┤╨║╨╗╤О╤З╨╕╤В╤М ╨╡╨│╨╛ ╨▓ server.php ╤А╨░╨╜╤М╤И╨╡ EPIC-10.4/10.5
  ╨╛╨╖╨╜╨░╤З╨░╨╗╨╛ ╨▒╤Л ╨╜╨░╤А╤Г╤И╨╕╤В╤М Rule 11 (Auth+Lobby+Game ╨▓ ╨╛╨┤╨╜╨╛╨╝ Epic).

Verification (╨░╨▓╤В╨╛╨╝╨░╤В╨╕╤З╨╡╤Б╨║╨░╤П, ╨┐╨╛╨╗╨╜╨╛╤Б╤В╤М╤О ╤Б╨░╨╝╨╛╨┤╨╛╤Б╤В╨░╤В╨╛╤З╨╜╨░╤П):
- tests/Manual/test_server_bootstrap.php ╨┐╨╛╨┤╨╜╨╕╨╝╨░╨╡╤В server.php ╨║╨░╨║
  ╤А╨╡╨░╨╗╤М╨╜╤Л╨╣ ╨┐╨╛╨┤╨┐╤А╨╛╤Ж╨╡╤Б╤Б (proc_open), ╨╛╨▒╤Й╨░╨╡╤В╤Б╤П ╤Б ╨╜╨╕╨╝ ╤З╨╡╤А╨╡╨╖ ╤Б╨╛╨▒╤Б╤В╨▓╨╡╨╜╨╜╨╛╤А╤Г╤З╨╜╨╛
  ╨╜╨░╨┐╨╕╤Б╨░╨╜╨╜╤Л╨╣ RFC6455 WebSocket-╨║╨╗╨╕╨╡╨╜╤В (╨▒╨╡╨╖ ╨▓╨╜╨╡╤И╨╜╨╕╤Е ╨▒╨╕╨▒╨╗╨╕╨╛╤В╨╡╨║) ╨┐╨╛
  ╨╜╨░╤Б╤В╨╛╤П╤Й╨╡╨╝╤Г TCP-╤Б╨╛╨║╨╡╤В╤Г ╨╜╨░ 127.0.0.1:8080, ╨╖╨░╤В╨╡╨╝ ╨║╨╛╤А╤А╨╡╨║╤В╨╜╨╛ ╨╛╤Б╤В╨░╨╜╨░╨▓╨╗╨╕╨▓╨░╨╡╤В
  ╨┐╤А╨╛╤Ж╨╡╤Б╤Б (SIGTERM тЖТ graceful shutdown, SIGKILL ╨║╨░╨║ fallback).
- ╨а╨╡╨╖╤Г╨╗╤М╤В╨░╤В: 8/8 PASSED. ╨Я╤А╨╛╨│╨╜╨░╨╜ ╨┤╨▓╨░╨╢╨┤╤Л ╨┐╨╛╨┤╤А╤П╨┤ тАФ ╨┐╨╛╤А╤В ╨║╨╛╤А╤А╨╡╨║╤В╨╜╨╛
  ╨╛╤Б╨▓╨╛╨▒╨╛╨╢╨┤╨░╨╡╤В╤Б╤П ╨╝╨╡╨╢╨┤╤Г ╨╖╨░╨┐╤Г╤Б╨║╨░╨╝╨╕.
- ╨а╤Г╤З╨╜╨░╤П ╨┐╤А╨╛╨▓╨╡╤А╨║╨░ `php server.php start` тАФ Workerman ╨┐╨╛╨┤╨╜╨╕╨╝╨░╨╡╤В╤Б╤П,
  ╤В╨░╨▒╨╗╨╕╤Ж╨░ ╨▓╨╛╤А╨║╨╡╤А╨╛╨▓ ╨┐╨╛╨║╨░╨╖╤Л╨▓╨░╨╡╤В [OK], graceful stop ╨┐╨╛ SIGTERM.
- тЪая╕ПтЖТтЬЕ ╨Ш╨б╨Я╨а╨Р╨Т╨Ы╨Х╨Э╨Ю (2026-07-21): ╨┐╨╡╤А╨▓╨░╤П ╨▓╨╡╤А╤Б╨╕╤П test_server_bootstrap.php
  ╨╖╨░╨▓╨╕╤Б╨░╨╗╨░ ╨╜╨░ VPS (╤В╤А╨╡╨▒╨╛╨▓╨░╨╗╤Б╤П Ctrl+C). ╨Я╤А╨╕╤З╨╕╨╜╨░ тАФ ╨║╨╗╨░╤Б╤Б╨╕╤З╨╡╤Б╨║╨╕╨╣ proc_open
  deadlock: stdout/stderr ╨┤╨╛╤З╨╡╤А╨╜╨╡╨│╨╛ ╨┐╤А╨╛╤Ж╨╡╤Б╤Б╨░ ╤И╨╗╨╕ ╨▓ pipe, ╨║╨╛╤В╨╛╤А╤Л╨╣ ╨╜╨╕╨║╨╛╨│╨┤╨░
  ╨╜╨╡ ╨▓╤Л╤З╨╕╤В╤Л╨▓╨░╨╗╤Б╤П; ╨Ю╨б-╨▒╤Г╤Д╨╡╤А ╨┐╨░╨╣╨┐╨░ ╨╖╨░╨┐╨╛╨╗╨╜╤П╨╗╤Б╤П ╨▓╤Л╨▓╨╛╨┤╨╛╨╝ Workerman, ╨┤╨╛╤З╨╡╤А╨╜╨╕╨╣
  ╨┐╤А╨╛╤Ж╨╡╤Б╤Б ╨▒╨╗╨╛╨║╨╕╤А╨╛╨▓╨░╨╗╤Б╤П ╨╜╨░ write() ╨┤╨╛ ╤А╨╡╨░╨╗╤М╨╜╨╛╨│╨╛ ╨▒╨╕╨╜╨┤╨╕╨╜╨│╨░ ╨┐╨╛╤А╤В╨░. ╨Т ╨┐╨╡╤Б╨╛╤З╨╜╨╕╤Ж╨╡
  ╨╜╨╡ ╨▓╨╛╤Б╨┐╤А╨╛╨╕╨╖╨▓╨╛╨┤╨╕╨╗╨╛╤Б╤М ╨╕╨╖-╨╖╨░ ╨╜╨╡╨▒╨╛╨╗╤М╤И╨╛╨│╨╛ ╨╛╨▒╤К╤С╨╝╨░ ╨▓╤Л╨▓╨╛╨┤╨░, ╨┐╨╛╨╝╨╡╤Й╨░╨▓╤И╨╡╨│╨╛╤Б╤П ╨▓ ╨▒╤Г╤Д╨╡╤А.
  ╨Ш╤Б╨┐╤А╨░╨▓╨╗╨╡╨╜╨╛: ╨▓╤Л╨▓╨╛╨┤ ╨┤╨╛╤З╨╡╤А╨╜╨╡╨│╨╛ ╨┐╤А╨╛╤Ж╨╡╤Б╤Б╨░ ╤В╨╡╨┐╨╡╤А╤М ╨╕╨┤╤С╤В ╨▓ ╤Д╨░╨╣╨╗╤Л (['file', ...],
  ╨╜╨╡ ['pipe', ...] тАФ ╨╖╨░╨┐╨╕╤Б╤М ╨▓ ╤Д╨░╨╣╨╗ ╨╜╨╡ ╨▒╨╗╨╛╨║╨╕╤А╤Г╨╡╤В╤Б╤П ╨┐╨╛ ╨╛╨▒╤К╤С╨╝╤Г), ╨╛╨┐╤А╨╛╤Б ╨┐╨╛╤А╤В╨░
  ╨▓╨╝╨╡╤Б╤В╨╛ ╤Д╨╕╨║╤Б╨╕╤А╨╛╨▓╨░╨╜╨╜╨╛╨│╨╛ sleep, ╨┤╨╕╨░╨│╨╜╨╛╤Б╤В╨╕╨║╨░ stdout/stderr ╨┐╤А╨╕ ╤Б╨▒╨╛╨╡ ╨▒╨╕╨╜╨┤╨╕╨╜╨│╨░,
  ╨╢╤С╤Б╤В╨║╨╕╨╣ watchdog ╨┐╨╛ SIGALRM (HARD_TIMEOUT_SECONDS=20) ╨║╨░╨║ ╨┐╨╛╤Б╨╗╨╡╨┤╨╜╨╕╨╣
  ╤А╤Г╨▒╨╡╨╢ тАФ ╤Б╨║╤А╨╕╨┐╤В ╤Д╨╕╨╖╨╕╤З╨╡╤Б╨║╨╕ ╨╜╨╡ ╨╝╨╛╨╢╨╡╤В ╨╖╨░╨▓╨╕╤Б╨╜╤Г╤В╤М ╨╜╨░╨▓╤Б╨╡╨│╨┤╨░. ╨Я╤А╨╛╨▓╨╡╤А╨╡╨╜╨╛ 5
  ╨┐╤А╨╛╨│╨╛╨╜╨╛╨▓ ╨┐╨╛╨┤╤А╤П╨┤ (~3-4s ╨║╨░╨╢╨┤╤Л╨╣) + ╨╛╤В╨┤╨╡╨╗╤М╨╜╨╛ ╨┐╤Г╤В╤М ╨┤╨╕╨░╨│╨╜╨╛╤Б╤В╨╕╨║╨╕ ╨┐╤А╨╕ ╨╖╨░╨▓╨╡╨┤╨╛╨╝╨╛
  ╨╜╨╡╤А╨░╨▒╨╛╤З╨╡╨╝ ╨┐╨╛╤А╤В╨╡ (5s, ╤З╨╕╤Б╤В╨╛╨╡ ╤Б╨╛╨╛╨▒╤Й╨╡╨╜╨╕╨╡ ╨╛╨▒ ╨╛╤И╨╕╨▒╨║╨╡, ╨▒╨╡╨╖ ╨╖╨░╨▓╨╕╤Б╨░╨╜╨╕╤П).
- тЪая╕ПтЖТтЬЕ ╨Ш╨б╨Я╨а╨Р╨Т╨Ы╨Х╨Э╨Ю (╨▓╤В╨╛╤А╨╛╨╣ ╤А╨░╤Г╨╜╨┤, ╤В╨╛╤В ╨╢╨╡ ╨┤╨╡╨╜╤М): ╨┐╨╛╤Б╨╗╨╡ ╨┐╨╡╤А╨▓╨╛╨│╨╛ ╤Д╨╕╨║╤Б╨░ ╤В╨╡╤Б╤В
  ╨▓╤Б╤С ╨╡╤Й╤С ╨┐╨░╨┤╨░╨╗ ╨╜╨░ VPS тАФ "WS handshake failed" ╤Б ╨┐╤Г╤Б╤В╤Л╨╝ ╨╛╤В╨▓╨╡╤В╨╛╨╝.
  ╨Я╤А╨╕╤З╨╕╨╜╨░: ╨╛╤Б╨╕╤А╨╛╤В╨╡╨▓╤И╨╕╨╣ ╨┐╤А╨╛╤Ж╨╡╤Б╤Б server.php ╤Б ╨Я╨Х╨а╨Т╨Ю╨Щ (╨╖╨░╨▓╨╕╤Б╤И╨╡╨╣) ╨┐╨╛╨┐╤Л╤В╨║╨╕
  ╨╛╤Б╤В╨░╨╗╤Б╤П ╨╢╨╕╤В╤М ╨╕ ╨┤╨╡╤А╨╢╨░╤В╤М ╨┐╨╛╤А╤В 8080 (Workerman stdout ╤З╨╡╤Б╤В╨╜╨╛ ╨┐╨╕╤Б╨░╨╗
  "already running"), ╨░ ╤В╨╡╤Б╤В ╨┐╨╛ ╨╛╤И╨╕╨▒╨║╨╡ ╨┐╨╛╨┤╨║╨╗╤О╤З╨░╨╗╤Б╤П ╨║ ╨н╨в╨Ю╨Ь╨г ╤Б╤В╨░╤А╨╛╨╝╤Г
  ╨┐╤А╨╛╤Ж╨╡╤Б╤Б╤Г ╨▓╨╝╨╡╤Б╤В╨╛ ╤Б╨▓╨╛╨╡╨│╨╛ ╤Б╨▓╨╡╨╢╨╡╤Б╨╛╨╖╨┤╨░╨╜╨╜╨╛╨│╨╛. ╨Ш╤Б╨┐╤А╨░╨▓╨╗╨╡╨╜╨╛: ╨┐╨╡╤А╨╡╨┤ ╤Б╤В╨░╤А╤В╨╛╨╝
  ╤В╨╡╤Б╤В ╤В╨╡╨┐╨╡╤А╤М ╤Б╨░╨╝ ╨▓╤Л╨╖╤Л╨▓╨░╨╡╤В `php server.php stop` (idempotent, ╨▒╨╡╨╖╨╛╨┐╨░╤Б╨╜╨╛
  ╨┐╤А╨╕ ╨╛╤В╤Б╤Г╤В╤Б╤В╨▓╨╕╨╕ ╨╖╨░╨┐╤Г╤Й╨╡╨╜╨╜╨╛╨│╨╛ ╨┐╤А╨╛╤Ж╨╡╤Б╤Б╨░) ╨┤╨╗╤П ╨│╨░╤А╨░╨╜╤В╨╕╤А╨╛╨▓╨░╨╜╨╜╨╛ ╤З╨╕╤Б╤В╨╛╨│╨╛
  ╤Б╨╛╤Б╤В╨╛╤П╨╜╨╕╤П, ╨┐╨╗╤О╤Б ╤П╨▓╨╜╨░╤П ╨┤╨╕╨░╨│╨╜╨╛╤Б╤В╨╕╨║╨░ "already running" ╤Б ╨┐╨╛╨┤╤Б╨║╨░╨╖╨║╨╛╨╣
  ╤А╤Г╤З╨╜╨╛╨╣ ╨║╨╛╨╝╨░╨╜╨┤╤Л ╨╜╨░ ╤Б╨╗╤Г╤З╨░╨╣, ╨╡╤Б╨╗╨╕ self-healing ╨╜╨╡ ╤Б╤А╨░╨▒╨╛╤В╨░╨╡╤В. ╨Я╤А╨╛╨▓╨╡╤А╨╡╨╜╨╛:
  ╨▓╤А╤Г╤З╨╜╤Г╤О ╤Б╨╛╨╖╨┤╨░╨╜ ╨╛╤Б╨╕╤А╨╛╤В╨╡╨▓╤И╨╕╨╣ ╨┐╤А╨╛╤Ж╨╡╤Б╤Б тЖТ ╤В╨╡╤Б╤В ╤Б╨░╨╝ ╨╡╨│╨╛ ╨┐╨╛╨│╨░╤Б╨╕╨╗ ╨╕ ╤Б╤В╨░╤А╤В╨╛╨▓╨░╨╗
  ╨╖╨░╨╜╨╛╨▓╨╛ тАФ 8/8 PASSED, ╨▒╨╡╨╖ ╨╖╨╛╨╝╨▒╨╕-╨┐╤А╨╛╤Ж╨╡╤Б╤Б╨╛╨▓ ╨┐╨╛╤Б╨╗╨╡. 3 ╨┤╨╛╨┐╨╛╨╗╨╜╨╕╤В╨╡╨╗╤М╨╜╤Л╤Е
  ╨┐╤А╨╛╨│╨╛╨╜╨░ ╤Б ╤З╨╕╤Б╤В╨╛╨│╨╛ ╤Б╨╛╤Б╤В╨╛╤П╨╜╨╕╤П тАФ ╤Б╤В╨░╨▒╨╕╨╗╤М╨╜╨╛ 8/8, ~3-4s ╨║╨░╨╢╨┤╤Л╨╣.
- ╨Я╨╛╨╗╨╜╤Л╨╣ ╤А╨╡╨│╤А╨╡╤Б╤Б ╨┐╨╛ ╨▓╤Б╨╡╨╝ 24 ╤Д╨░╨╣╨╗╨░╨╝ tests/Manual/*.php (╨▒╤Л╨╗ 23, ╨┤╨╛╨▒╨░╨▓╨╗╨╡╨╜
  test_server_bootstrap.php) тАФ 0 failed.

PHASE 10 тАФ WEBSOCKET PROTOCOL: IN PROGRESS (10.0 done, 10.1 Packet
validation next тАФ ╨▓╨║╨╗╤О╤З╨░╨╡╤В ╤А╨╡╤И╨╡╨╜╨╕╨╡ ╨┐╨╛ rate limiting ╨╕ invalid-JSON policy)

- [DONE] EPIC-9.6 Admin integration tests
Files:
- src/Admin/AdminService.php (diff тАФ FIX-3, ╤Б╨╝. ╨╜╨╕╨╢╨╡)
- tests/Manual/test_admin_logs.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗)
- tests/Manual/test_admin_integration.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗)
- test_logger.php (╤Г╨┤╨░╨╗╤С╨╜ ╨╕╨╖ ╨║╨╛╤А╨╜╤П ╨┐╤А╨╛╨╡╨║╤В╨░)

Implemented:
- tests/Manual/test_admin_logs.php: assert-based ╨▓╨╡╤А╨╕╤Д╨╕╨║╨░╤Ж╨╕╤П AdminService::handleGetLogs()
  (guard auth_required/not_your_turn, ╨┐╨░╨║╨╡╤В admin_logs_data, ╨╛╤В╤Б╤Г╤В╤Б╤В╨▓╨╕╨╡ logger, ╤Б╤А╨╡╨╖
  limit=100 ╤З╨╡╤А╨╡╨╖ Logger::getLastLines(), ╤А╨╡╨░╨╗╤М╨╜╤Л╨╣ Logger ╨┐╤А╨╛╤В╨╕╨▓ ╤Д╨░╨╣╨╗╨░). ╨Ч╨░╨║╤А╤Л╨▓╨░╨╡╤В
  ╨┐╤А╨╛╨▒╨╡╨╗ ╨▓╨╡╤А╨╕╤Д╨╕╨║╨░╤Ж╨╕╨╕ EPIC-9.5 тАФ ╨┐╤А╨╡╨╢╨╜╨╕╨╣ tests/Manual/test_logger.php ╨▒╤Л╨╗ print_r()
  ╤Б╨╝╨╛╤Г╨║-╤Б╨║╤А╨╕╨┐╤В╨╛╨╝ ╨▒╨╡╨╖ assert'╨╛╨▓ ╨╕ ╨╜╨╡ ╨┐╤А╨╛╨▓╨╡╤А╤П╨╗ AdminService ╨▓╨╛╨╛╨▒╤Й╨╡.
- tests/Manual/test_admin_integration.php: ╨║╤А╨╛╤Б╤Б-╤Б╤Ж╨╡╨╜╨░╤А╨╕╨╕ ╨╝╨╡╨╢╨┤╤Г admin-╨┤╨╡╨╣╤Б╤В╨▓╨╕╤П╨╝╨╕
  (test_admin_ban/unban/kick/close_room.php ╨┐╨╛╨║╤А╤Л╨▓╨░╤О╤В ╨║╨╛╨╜╤В╤А╨░╨║╤В╤Л ╨║╨░╨╢╨┤╨╛╨│╨╛ ╨┤╨╡╨╣╤Б╤В╨▓╨╕╤П
  ╨Ш╨Ч╨Ю╨Ы╨Ш╨а╨Ю╨Т╨Р╨Э╨Э╨Ю; ╤Н╤В╨╛╤В ╤Д╨░╨╣╨╗ ╨┐╤А╨╛╨▓╨╡╤А╤П╨╡╤В ╨┐╨╛╤Б╨╗╨╡╨┤╨╛╨▓╨░╤В╨╡╨╗╤М╨╜╨╛╤Б╤В╨╕ ╨╕╨╖ ╨╜╨╡╤Б╨║╨╛╨╗╤М╨║╨╕╤Е ╨┤╨╡╨╣╤Б╤В╨▓╨╕╨╣ ╨▓
  ╨╛╨┤╨╜╨╛╨╣ ╨║╨╛╨╝╨╜╨░╤В╨╡, ╨│╨┤╨╡ ╨╕╨╜╨▓╨░╤А╨╕╨░╨╜╤В ╤Н╨║╨╛╨╜╨╛╨╝╨╕╨║╨╕ ╨╝╨╛╨╢╨╡╤В ╨╜╨░╤А╤Г╤И╨░╤В╤М╤Б╤П ╨╜╨░ ╤Б╤В╤Л╨║╨╡ ╨║╨╛╨╜╤В╤А╨░╨║╤В╨╛╨▓).

╨Ю╨▒╨╜╨░╤А╤Г╨╢╨╡╨╜ ╨╕ ╨╕╤Б╨┐╤А╨░╨▓╨╗╨╡╨╜ ╨▒╨░╨│ (FIX-3, ╤Б╨╝. ╤Б╨╡╨║╤Ж╨╕╤О PATCHES):
- handleKickUser() ╤А╨╡╤Д╨░╨╜╨┤╨╕╨╗ total_paid ╨╕ ╤Г╨╝╨╡╨╜╤М╤И╨░╨╗ bank, ╨╜╨╛ ╨╜╨╡ ╨╛╨▒╨╜╤Г╨╗╤П╨╗ total_paid
  ╨╕╨│╤А╨╛╨║╨░ ╨▓ ╨┐╨░╨╝╤П╤В╨╕. ╨Ф╨╡╨╗╨╡╨│╨░╤В ╤Г╨┤╨░╨╗╨╡╨╜╨╕╤П (removePlayerFromLobby/Game/Apartment) ╨┐╨╕╤Б╨░╨╗
  ╨▓ all_players_history ╨б╨в╨Р╨а╨Ю╨Х (╨┤╨╛╤А╨╡╤Д╨░╨╜╨┤╨╜╨╛╨╡) ╨╖╨╜╨░╤З╨╡╨╜╨╕╨╡ total_paid. ╨Я╨╛╤Б╨╗╨╡╨┤╤Г╤О╤Й╨╕╨╣
  admin_close_room() ╨▒╨╡╨╖╤Г╤Б╨╗╨╛╨▓╨╜╨╛ ╤А╨╡╤Д╨░╨╜╨┤╨╕╨╗ total_paid ╨╕╨╖ ╨╕╤Б╤В╨╛╤А╨╕╨╕ ╨║╨░╨╢╨┤╨╛╨╝╤Г ╤Г╤З╨░╤Б╤В╨╜╨╕╨║╤Г тАФ
  ╨║╨╕╨║╨╜╤Г╤В╤Л╨╣ ╨╕╨│╤А╨╛╨║ ╨┐╨╛╨╗╤Г╤З╨░╨╗ ╨┤╨╡╨╜╤М╨│╨╕ ╨┤╨▓╨░╨╢╨┤╤Л. ╨Э╨░╤А╤Г╤И╨╡╨╜╨╕╨╡ Economic Integrity Rule
  (ANCHOR_CORE.md Part 2).
- Regression-╤В╨╡╤Б╤В (TEST 1 ╨╕ TEST 3 ╨▓ test_admin_integration.php) ╨┐╤А╨╛╨▓╨╡╤А╨╡╨╜ ╨╜╨░
  ╨╗╨╛╨╢╨╜╨╛╨┐╨╛╨╗╨╛╨╢╨╕╤В╨╡╨╗╤М╨╜╨╛╤Б╤В╤М: ╨▓╤А╨╡╨╝╨╡╨╜╨╜╨╛ ╨╛╤В╨║╨░╤В╤Л╨▓╨░╨╗╤Б╤П FIX-3 тЖТ ╤В╨╡╤Б╤В ╨┤╨░╨╗ 5 ╤З╨╡╤Б╤В╨╜╤Л╤Е FAIL
  (520 ╨┐╤А╨╛╤В╨╕╨▓ ╤Д╨░╨║╤В╨╕╤З╨╡╤Б╨║╨╕╤Е 540, 40 ╨┐╤А╨╛╤В╨╕╨▓ 60); ╨┐╨╛╤Б╨╗╨╡ ╨▓╨╛╤Б╤Б╤В╨░╨╜╨╛╨▓╨╗╨╡╨╜╨╕╤П ╤Д╨╕╨║╤Б╨░ тАФ ╤Б╨╜╨╛╨▓╨░
  20/20 PASSED.

Manual verification:
- test_admin_logs.php: 16/16 PASSED
- test_admin_integration.php: 20/20 PASSED
- ╨а╨╡╨│╤А╨╡╤Б╤Б╨╕╤П ╨┐╤А╨╛╤В╨╕╨▓ ╨▓╤Б╨╡╤Е ╤Б╤Г╤Й╨╡╤Б╤В╨▓╤Г╤О╤Й╨╕╤Е admin-╤В╨╡╤Б╤В╨╛╨▓ ╨┐╨╛╤Б╨╗╨╡ FIX-3:
  test_admin_auth.php 8/8, test_admin_ban.php 9/9, test_admin_unban.php 8/8,
  test_admin_kick.php 37/37, test_admin_close_room.php 28/28 тАФ ╨▓╤Б╨╡ ╤З╨╕╤Б╤В╤Л.

PHASE 9 тАФ ADMIN: COMPLETE (9.0тАУ9.6 done)

Integration tests:
48 / 48 PASSED (auth)
90 / 90 PASSED (lobby)
164 / 164 PASSED (lotto engine) тАФ ╤Б╨╝. KNOWN GAPS: ╤В╨╡╤Б╤В╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ ╨┐╨░╨┤╨░╨╡╤В ╨┐╨╛ ╨╜╨╡╨╖╨░╨▓╨╕╤Б╨╕╨╝╨╛╨╣ ╨┐╤А╨╕╤З╨╕╨╜╨╡
44 / 44 PASSED (game start) тАФ ╤Б╨╝. KNOWN GAPS: ╤В╨╡╤Б╤В╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ ╨┐╨░╨┤╨░╨╡╤В ╨┐╨╛ ╨╜╨╡╨╖╨░╨▓╨╕╤Б╨╕╨╝╨╛╨╣ ╨┐╤А╨╕╤З╨╕╨╜╨╡
37 / 37 PASSED (turn system)
38 / 38 PASSED (victory system) тАФ ╤Б╨╝. KNOWN GAPS: ╤В╨╡╤Б╤В╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ ╨┐╨░╨┤╨░╨╡╤В ╨┐╨╛ ╨╜╨╡╨╖╨░╨▓╨╕╤Б╨╕╨╝╨╛╨╣ ╨┐╤А╨╕╤З╨╕╨╜╨╡
32 / 32 PASSED (apartment)
15 / 15 PASSED (reconnect)
8 / 8 PASSED (admin auth)
9 / 9 PASSED (admin ban)
8 / 8 PASSED (admin unban)
37 / 37 PASSED (admin kick)
28 / 28 PASSED (admin close room)
16 / 16 PASSED (admin logs)
20 / 20 PASSED (admin integration)

Next planned Epic: EPIC-10.0 Protocol router (╨╕╤Б╤В╨╛╤А╨╕╤З╨╡╤Б╨║╨░╤П ╨╖╨░╨┐╨╕╤Б╤М ╨╜╨░ ╨╝╨╛╨╝╨╡╨╜╤В ╨╖╨░╨▓╨╡╤А╤И╨╡╨╜╨╕╤П EPIC-9.6 тАФ ╨▓╤Л╨┐╨╛╨╗╨╜╨╡╨╜╨╛, ╤Б╨╝. ╨╖╨░╨┐╨╕╤Б╤М ╨▓╤Л╤И╨╡)

- [DONE] EPIC-9.5 Logs access
Files:
- src/Core/Logger.php
- src/Admin/AdminService.php

Implemented:
- AdminService::handleGetLogs().
- Admin authentication via assertAdmin().
- Protocol packet admin_logs_data.
- Logger::getLastLines() for reading last log entries.

Notes:
- Returns up to 100 most recent lines from logs/server.log.
- Missing or unreadable log file returns an empty array.
- Action is logged through Logger::info().

Manual verification:
- logger writes INFO/WARNING/ERROR correctly
- getLastLines() returns latest log entries
- limit parameter verified
- missing log file returns empty array
- admin endpoint returns admin_logs_data
- non-admin access denied

Limitations:
- Reads the log using file(); optimized tail-reading is intentionally deferred.
- Packet routing will be integrated during Phase 10 (Admin packet integration).

- [DONE] EPIC-9.4 Close room тАФ AdminService::handleCloseRoom()
Files:
- src/Admin/AdminService.php (diff тАФ ╨┤╨╛╨▒╨░╨▓╨╗╨╡╨╜ handleCloseRoom())
- tests/Manual/test_admin_close_room.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗)
Notes:
- 28/28 ╤В╨╡╤Б╤В╨╛╨▓ ╨┐╤А╨╛╨╣╨┤╨╡╨╜╨╛ (php test_admin_close_room.php)
- ╨Я╨╛╨║╤А╤Л╤В╨╛: ╨╖╨░╨║╤А╤Л╤В╨╕╨╡ waiting-╨║╨╛╨╝╨╜╨░╤В╤Л ╨▒╨╡╨╖ ╤А╨╡╤Д╨░╨╜╨┤╨╛╨▓ ╨┐╤А╨╕ total_paid=0,
  ╨╖╨░╨║╤А╤Л╤В╨╕╨╡ playing-╨║╨╛╨╝╨╜╨░╤В╤Л ╤Б ╨┐╨╛╨╗╨╜╤Л╨╝ ╨▓╨╛╨╖╨▓╤А╨░╤В╨╛╨╝ ╤Б╤А╨╡╨┤╤Б╤В╨▓,
  ╨▓╨╛╨╖╨▓╤А╨░╤В ╤А╨░╨╜╨╡╨╡ ╤Г╨┤╨░╨╗╤С╨╜╨╜╤Л╨╝ ╨╕╨│╤А╨╛╨║╨░╨╝ ╤З╨╡╤А╨╡╨╖ all_players_history,
  ╤Г╨▓╨╡╨┤╨╛╨╝╨╗╨╡╨╜╨╕╨╡ ╤В╨╛╨╗╤М╨║╨╛ active-╨╕╨│╤А╨╛╨║╨╛╨▓ (disconnected ╨╜╨╡ ╨┐╨╛╨╗╤Г╤З╨░╤О╤В packet, ╨╜╨╛ ╨┐╨╛╨╗╤Г╤З╨░╤О╤В refund),
  room_not_found, guard ╨┤╨╗╤П ╨╜╨╡-╨░╨┤╨╝╨╕╨╜╨╕╤Б╤В╤А╨░╤В╨╛╤А╨░,
  rollback ╨┐╤А╨╕ ╨╛╤И╨╕╨▒╨║╨╡ refund-╤В╤А╨░╨╜╨╖╨░╨║╤Ж╨╕╨╕ (coins/bank ╨╜╨╡ ╨╕╨╖╨╝╨╡╨╜╤П╤О╤В╤Б╤П, destroyRoom ╨╜╨╡ ╨▓╤Л╨╖╤Л╨▓╨░╨╡╤В╤Б╤П,
  ╨║╨╛╨╝╨╜╨░╤В╨░ ╤Б╨╛╤Е╤А╨░╨╜╤П╨╡╤В╤Б╤П, PDO transaction ╨║╨╛╤А╤А╨╡╨║╤В╨╜╨╛ ╨╛╤В╨║╨░╤В╤Л╨▓╨░╨╡╤В╤Б╤П)
- ╨н╨║╨╛╨╜╨╛╨╝╨╕╨║╨░: ANCHOR_CORE.md Part 2 ┬з Admin Close Room тАФ
  ╨▓╤Б╨╡╨╝ ╤Г╤З╨░╤Б╤В╨╜╨╕╨║╨░╨╝ ╨▓╨╛╨╖╨▓╤А╨░╤Й╨░╨╡╤В╤Б╤П 100% total_paid (╨▓╨║╨╗╤О╤З╨░╤П apartment payments),
  ╨╕╤Б╤В╨╛╤З╨╜╨╕╨║ ╨┤╨░╨╜╨╜╤Л╤Е тАФ all_players_history, ╨╛╨┐╨╡╤А╨░╤Ж╨╕╤П ╨▓╤Л╨┐╨╛╨╗╨╜╤П╨╡╤В╤Б╤П ╨▓ ╨╛╨┤╨╜╨╛╨╣ PDO-╤В╤А╨░╨╜╨╖╨░╨║╤Ж╨╕╨╕

PHASE 9 тАФ ADMIN: IN PROGRESS (9.0/9.1/9.2/9.3/9.4 done, 9.5 Logs access next)

Integration tests:
48 / 48 PASSED (auth)
90 / 90 PASSED (lobby)
164 / 164 PASSED (lotto engine)
44 / 44 PASSED (game start)
37 / 37 PASSED (turn system)
38 / 38 PASSED (victory system)
32 / 32 PASSED (apartment)
37 / 37 PASSED (admin kick)
28 / 28 PASSED (admin close room)

Next planned Epic: EPIC-9.5 Logs access

- [DONE] EPIC-9.3 Kick player тАФ AdminService::handleKickUser()
Files:
- src/Admin/AdminService.php (diff тАФ ╨┤╨╛╨▒╨░╨▓╨╗╨╡╨╜ ╨┐╨░╤А╨░╨╝╨╡╤В╤А $db ╨▓ ╨║╨╛╨╜╤Б╤В╤А╤Г╨║╤В╨╛╤А + handleKickUser())
- tests/Manual/test_admin_kick.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗)
Notes:
- 37/37 ╤В╨╡╤Б╤В╨╛╨▓ ╨┐╤А╨╛╨╣╨┤╨╡╨╜╨╛ (php test_admin_kick.php)
- ╨Я╨╛╨║╤А╤Л╤В╨╛: waiting ╨▒╨╡╨╖ total_paid (╨╜╨╡╤В ╤А╨╡╤Д╨░╨╜╨┤╨░), kick ╤Е╨╛╤Б╤В╨░ ╨▓ waiting тЖТ transferHost(),
  playing ╤Б ╤А╨╡╤Д╨░╨╜╨┤╨╛╨╝ (users.coins += total_paid, bank -= total_paid, removePlayerFromGame
  ╤Б reason='kicked'), apartment ╤Б ╤А╨╡╤Д╨░╨╜╨┤╨╛╨╝ (removePlayerFromApartment ╤Б reason='kicked'),
  cannot_moderate_admin (╨╜╨╡╨╗╤М╨╖╤П ╨║╨╕╨║╨╜╤Г╤В╤М ╨░╨┤╨╝╨╕╨╜╨░), room_not_found (╤Ж╨╡╨╗╤М ╨╜╨╡ ╨▓ ╨║╨╛╨╝╨╜╨░╤В╨╡),
  not_your_turn guard (╨╜╨╡-╨░╨┤╨╝╨╕╨╜), rollback ╨┐╤А╨╕ ╤Б╨▒╨╛╨╡ refund-╤В╤А╨░╨╜╨╖╨░╨║╤Ж╨╕╨╕ (bank/room ╨╜╨╡ ╤В╤А╨╛╨╜╤Г╤В╤Л,
  delegation ╨╜╨╡ ╨▓╤Л╨╖╨▓╨░╨╜, no dangling PDO transaction)
- ╨н╨║╨╛╨╜╨╛╨╝╨╕╨║╨░: ANCHOR_CORE.md Part 2 ┬з Kick тАФ bank -= total_paid; coins += total_paid,
  ╤В╤А╨░╨╜╨╖╨░╨║╤Ж╨╕╤П ╨╛╨▒╤П╨╖╨░╤В╨╡╨╗╤М╨╜╨░, ╤А╨╡╨░╨╗╨╕╨╖╨╛╨▓╨░╨╜╨╛ ╤З╨╡╤А╨╡╨╖ ╤Б╤Г╤Й╨╡╤Б╤В╨▓╤Г╤О╤Й╨╕╨╣ stmt 'add_user_coins'
- ╨Ъ╨╛╨╜╤Б╤В╤А╤Г╨║╤В╨╛╤А AdminService ╤А╨░╤Б╤И╨╕╤А╨╡╨╜ nullable-╨┐╨░╤А╨░╨╝╨╡╤В╤А╨╛╨╝ $db (╨╛╨▒╤А╨░╤В╨╜╨░╤П ╤Б╨╛╨▓╨╝╨╡╤Б╤В╨╕╨╝╨╛╤Б╤В╤М
  ╤Б╨╛╤Е╤А╨░╨╜╨╡╨╜╨░ тАФ ╤Б╤Г╤Й╨╡╤Б╤В╨▓╤Г╤О╤Й╨╕╨╡ ╨▓╤Л╨╖╨╛╨▓╤Л ╤Б 5 ╨░╤А╨│╤Г╨╝╨╡╨╜╤В╨░╨╝╨╕ ╨╜╨╡ ╨╗╨╛╨╝╨░╤О╤В╤Б╤П)

тЪая╕П KNOWN GAP (RESOLVED EPIC-9.3b, 2026-08-02):
~~removePlayerFromApartment() (ApartmentService) ╨╜╨╡ ╨▓╤Л╨┐╨╛╨╗╨╜╤П╨╡╤В host transfer ╨┐╤А╨╕
kick/ban ╤Е╨╛╤Б╤В╨░ ╨▓ apartment-╤Б╨╛╤Б╤В╨╛╤П╨╜╨╕╨╕~~ тАФ fixed in EPIC-9.3b.

тЪая╕П KNOWN GAP (historical, EPIC-9.3):
removePlayerFromApartment() (ApartmentService) ╨╜╨╡ ╨▓╤Л╨┐╨╛╨╗╨╜╤П╨╗ host transfer ╨┐╤А╨╕
kick/ban ╤Е╨╛╤Б╤В╨░ ╨▓ apartment-╤Б╨╛╤Б╤В╨╛╤П╨╜╨╕╨╕, ╤Е╨╛╤В╤П ANCHOR_CORE.md Host Rules ╨╜╨░╨╖╤Л╨▓╨░╨╡╤В
'kicked'/'banned' ╨▓╨░╨╗╨╕╨┤╨╜╤Л╨╝╨╕ ╨┐╤А╨╕╤З╨╕╨╜╨░╨╝╨╕ ╤Б╨╝╨╡╨╜╤Л ╤Е╨╛╤Б╤В╨░. ╨в╨╛╤В ╨╢╨╡ ╨┐╤А╨╛╨▒╨╡╨╗ ╨┐╤А╨╕╤Б╤Г╤В╤Б╤В╨▓╤Г╨╡╤В
╨╕ ╨▓ ╤Б╤Г╤Й╨╡╤Б╤В╨▓╤Г╤О╤Й╨╡╨╝ handleBanUser() ╨┤╨╗╤П 'waiting' (╨╜╨╡ ╨╕╤Б╨┐╤А╨░╨▓╨╗╤П╨╗╤Б╤П тАФ ╨▓╨╜╨╡ scope
EPIC-9.3, Epic Isolation). **Apartment path closed EPIC-9.3b; waiting ban path
still open.**

PHASE 9 тАФ ADMIN: IN PROGRESS (9.0/9.1/9.2/9.3 done, 9.4 Close room next)

Integration tests:
48 / 48 PASSED (auth)
90 / 90 PASSED (lobby)
164 / 164 PASSED (lotto engine)
44 / 44 PASSED (game start)
37 / 37 PASSED (turn system)
38 / 38 PASSED (victory system)
32 / 32 PASSED (apartment)
37 / 37 PASSED (admin kick)

Next planned Epic: EPIC-9.4 Close room

- [DONE] EPIC-9.2 Unban user
Files:
- src/Admin/AdminService.php (diff)
- tests/manual/test_admin_unban.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗)
Notes:
- ╨а╨╡╨░╨╗╨╕╨╖╨╛╨▓╨░╨╜ handleUnbanUser() ╨┤╨╗╤П admin_unban_user
- Guard: ╤В╨╛╨╗╤М╨║╨╛ admin (assertAdmin)
- ╨Т╨░╨╗╨╕╨┤╨░╤Ж╨╕╤П: user_id > 0
- DB: PreparedStatements key unban_user (banned_until=0)
- Manual tests: 8/8 PASSED

- [DONE] EPIC-9.1 Ban user
Files:
- src/Admin/AdminService.php (diff)
- src/Infrastructure/PreparedStatements.php (╨┤╨╛╨▒╨░╨▓╨╗╨╡╨╜ user_admin_by_id)
- tests/manual/test_admin_ban.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗)
Notes:
- ╨а╨╡╨░╨╗╨╕╨╖╨╛╨▓╨░╨╜ handleBanUser() ╤Б duration: 1d / 3d / permanent
- ╨Ч╨░╨┐╤А╨╡╤В ╨▒╨░╨╜╨░ ╨░╨┤╨╝╨╕╨╜╨╕╤Б╤В╤А╨░╤В╨╛╤А╨░: error.cannot_moderate_admin
- ╨Ф╨╗╤П ╨╛╨╜╨╗╨░╨╣╨╜-╤Ж╨╡╨╗╨╕ ╨╛╤В╨┐╤А╨░╨▓╨╗╤П╨╡╤В╤Б╤П ╨┐╨░╨║╨╡╤В banned {until}
- ╨г╨┤╨░╨╗╨╡╨╜╨╕╨╡ ╨╕╨╖ ╨║╨╛╨╝╨╜╨░╤В╤Л ╨┐╨╛ ╤Б╤В╨░╤В╤Г╤Б╤Г:
  waiting -> removePlayerFromLobby(..., 'banned')
  playing -> removePlayerFromGame(..., 'banned')
  apartment -> removePlayerFromApartment(..., 'banned')
- Manual tests: 9/9 PASSED

- [DONE] EPIC-9.0 Admin authentication
Files:
- src/Admin/AdminService.php (╤А╨╡╨░╨╗╨╕╨╖╨╛╨▓╨░╨╜)
- tests/manual/test_admin_auth.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗)
Notes:
- ╨Ф╨╛╨▒╨░╨▓╨╗╨╡╨╜ ╨╡╨┤╨╕╨╜╤Л╨╣ admin guard: AdminService::assertAdmin(object $connection): bool
- ╨Ъ╨╛╨╜╤В╤А╨░╨║╤В: unauthenticated -> error.auth_required, non-admin -> error.not_your_turn
- Manual tests: 8/8 PASSED

- [DONE] EPIC-8.6 Reconnect tests
Files:
- tests/manual/test_reconnect.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗)
Notes:
- 15/15 ╤В╨╡╤Б╤В╨╛╨▓ ╨┐╤А╨╛╨╣╨┤╨╡╨╜╨╛
- ╨Я╨╛╨║╤А╤Л╤В╨╛: disconnect->disconnected+timer, waiting-timeout removal, reconnect restore,
  reconnect_state payload, game AFK warning, auto-draw, afk removal

- [DONE] EPIC-8.5 AFK removal тАФ ReconnectService::removePlayerFromGame(..., 'afk')
- [DONE] EPIC-8.4 Auto draw тАФ ReconnectService::performAutoDraw()
- [DONE] EPIC-8.3 Game AFK protection тАФ ReconnectService::ensureGameAfkTimer()/tickGameAfk()
- [DONE] EPIC-8.2 Reconnect restoration тАФ ReconnectService::handleReconnect()
- [DONE] EPIC-8.1 Disconnect processing тАФ ReconnectService::handleDisconnect()
- [DONE] EPIC-8.0 ReconnectService тАФ src/Game/ReconnectService.php (╤А╨╡╨░╨╗╨╕╨╖╨░╤Ж╨╕╤П)
Files (8.0тАУ8.5):
- src/Game/ReconnectService.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗, ╤А╨╡╨░╨╗╨╕╨╖╨╛╨▓╨░╨╜)
Notes:
- ╨а╨╡╨░╨╗╨╕╨╖╨╛╨▓╨░╨╜╤Л reconnect timers (15s, single-shot) ╨╕ ╨▓╨╛╤Б╤Б╤В╨░╨╜╨╛╨▓╨╗╨╡╨╜╨╕╨╡ ╨╕╨│╤А╨╛╨║╨░ ╨┐╨╛ session_token
- ╨а╨╡╨░╨╗╨╕╨╖╨╛╨▓╨░╨╜╨░ game AFK ╨╖╨░╤Й╨╕╤В╨░ ╤Б ╨┐╨╛╤А╨╛╨│╨░╨╝╨╕ 15/25/30╤Б, auto draw ╨╕ ╤Г╨┤╨░╨╗╨╡╨╜╨╕╨╡╨╝ ╨┐╨╛ afk ╨┐╤А╨╕ 3 ╨░╨▓╤В╨╛╤Е╨╛╨┤╨░╤Е

PHASE 8 тАФ RECONNECT & AFK: COMPLETE (service + manual tests)

- [DONE] EPIC-7.6 Apartment integration tests
Files:
- tests/Manual/test_apartment.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗)
Notes:
- 32/32 ╤В╨╡╤Б╤В╨╛╨▓ ╨┐╤А╨╛╨╣╨┤╨╡╨╜╨╛
- ╨Я╨╛╨║╤А╤Л╤В╨╛: hasLine, shouldTrigger, prepareApartment, allRequiredAnswered,
  alert broadcast, agreeтЖТpayment, refuseтЖТremoval, re-trigger blocked

- [DONE] EPIC-7.5 Apartment timeout тАФ ApartmentService::onApartmentTimeout()
- [DONE] EPIC-7.4 Apartment payment transaction тАФ ApartmentService::finishApartment()
- [DONE] EPIC-7.3 Apartment voting тАФ ApartmentService::handleApartmentChoice()
- [DONE] EPIC-7.2 Apartment state тАФ ApartmentService::triggerApartment()
- [DONE] EPIC-7.1 Apartment trigger тАФ ApartmentService::shouldTrigger() / prepareApartment()
- [DONE] EPIC-7.0 Line detection тАФ ApartmentService::hasLine()
Files (7.0тАУ7.5):
- src/Game/ApartmentService.php (470 ╤Б╤В╤А╨╛╨║ тАФ ╨┐╨╛╨╗╨╜╤Л╨╣ ╨╛╤А╨║╨╡╤Б╤В╤А╨░╤В╨╛╤А)
- src/Game/GameService.php (735 ╤Б╤В╤А╨╛╨║ тАФ ╤В╨╛╨╜╨║╨╕╨╡ ╨┤╨╡╨╗╨╡╨│╨░╤В╨╛╤А╤Л)
Notes:
- ApartmentService ╤А╨░╤Б╤И╨╕╤А╨╡╨╜ ╨┤╨╛ ╨╛╤А╨║╨╡╤Б╤В╤А╨░╤В╨╛╤А╨░ (db, stmts, logger ╨▓ ╨║╨╛╨╜╤Б╤В╤А╤Г╨║╤В╨╛╤А╨╡)
- GameService ╤Б╨╛╨║╤А╨░╤Й╤С╨╜ ╤Б 985 ╨┤╨╛ 735 ╤Б╤В╤А╨╛╨║
- GameService::handleApartmentChoice() / triggerApartment() тАФ ╨┐╤Г╨▒╨╗╨╕╤З╨╜╤Л╨╡ ╨┤╨╡╨╗╨╡╨│╨░╤В╨╛╤А╤Л

PHASE 7 тАФ APARTMENT: COMPLETE
PHASE 8 тАФ RECONNECT & AFK: COMPLETE

Integration tests:
48 / 48 PASSED (auth)
90 / 90 PASSED (lobby)
164 / 164 PASSED (lotto engine)
44 / 44 PASSED (game start)
37 / 37 PASSED (turn system)
38 / 38 PASSED (victory system)
32 / 32 PASSED (apartment)

Next planned Epic: EPIC-8.0 ReconnectService

- [DONE] EPIC-7.6 Apartment integration tests
Files:
- tests/Manual/test_apartment.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗)
Notes:
- 32/32 ╤В╨╡╤Б╤В╨╛╨▓ ╨┐╤А╨╛╨╣╨┤╨╡╨╜╨╛
- ╨Я╨╛╨║╤А╤Л╤В╨╛: hasLine (empty/full/partial), shouldTrigger (line/fired/disconnected),
  prepareApartment (status, flags, required), allRequiredAnswered,
  alert broadcast (required/immune), agreeтЖТpayment (bank, immune, commit),
  refuseтЖТremoval (player_left, drawer_order), re-trigger blocked

- [DONE] EPIC-7.5 Apartment timeout тАФ GameService::onApartmentTimeout()
- [DONE] EPIC-7.4 Apartment payment transaction тАФ finishApartment() PDO
- [DONE] EPIC-7.3 Apartment voting тАФ GameService::handleApartmentChoice()
- [DONE] EPIC-7.2 Apartment state тАФ GameService::triggerApartment()
- [DONE] EPIC-7.1 Apartment trigger тАФ ApartmentService::shouldTrigger() / prepareApartment()
- [DONE] EPIC-7.0 Line detection тАФ ApartmentService::hasLine()
Files (7.0тАУ7.5):
- src/Game/ApartmentService.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗, 222 ╤Б╤В╤А╨╛╨║╨╕)
- src/Game/GameService.php (diff, 985 ╤Б╤В╤А╨╛╨║)
Notes:
- Victory > Apartment: ╨┐╤А╨╛╨▓╨╡╤А╨║╨░ ╨┐╨╛╨▒╨╡╨┤╤Л ╨╕╨┤╤С╤В ╨┤╨╛ shouldTrigger() ╨▓ handleDrawBarrel()
- immune=true ╨┐╨╛╤Б╨╗╨╡ agree тАФ ╨┐╨╛╨▓╤В╨╛╤А╨╜╤Л╨╣ ╨░╨┐╨░╤А╤В╨░╨╝╨╡╨╜╤В ╨╜╨╡ ╤В╤А╨╡╨▒╤Г╨╡╤В ╨┐╨╗╨░╤В╤Л
- apartment_fired тАФ at most once per game

PHASE 7 тАФ APARTMENT: COMPLETE

тЪая╕П KNOWN GAP тАФ ADR REQUIRED:
GameService 985 ╤Б╤В╤А╨╛╨║ тАФ ╨▓╨┐╨╗╨╛╤В╨╜╤Г╤О ╨║ mandatory refactor (1000).
╨Ъ╨░╨╜╨┤╨╕╨┤╨░╤В╤Л ╨╜╨░ ╨┤╨╡╨║╨╛╨╝╨┐╨╛╨╖╨╕╤Ж╨╕╤О: finishGame(), handleNoSurvivors() тЖТ ╨╛╤В╨┤╨╡╨╗╤М╨╜╤Л╨╣ GameFinishService.
╨Э╨╡╨╛╨▒╤Е╨╛╨┤╨╕╨╝╨╛ ╨┤╨╛ ╨╜╨░╤З╨░╨╗╨░ Phase 8.

Integration tests:
48 / 48 PASSED (auth)
90 / 90 PASSED (lobby)
164 / 164 PASSED (lotto engine)
44 / 44 PASSED (game start)
37 / 37 PASSED (turn system)
38 / 38 PASSED (victory system)
32 / 32 PASSED (apartment)

Next planned Epic: EPIC-8.0 ReconnectService
тЪая╕П Before Phase 8: ADR for GameService decomposition required.

- [DONE] EPIC-6.5 Victory system tests
Files:
- tests/Manual/test_victory.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗)
Notes:
- 38/38 ╤В╨╡╤Б╤В╨╛╨▓ ╨┐╤А╨╛╨╣╨┤╨╡╨╜╨╛
- ╨Я╨╛╨║╤А╤Л╤В╨╛: checkCardVictory (0/1/2 wins), checkAllVictories (disconnected skip),
  calculatePrize (floor division, remainder burn, double+normal),
  finishGame (payout, room destruction, game_over broadcast, DB rollback),
  full draw-until-victory integration test

- [DONE] EPIC-6.4 Game finish flow тАФ GameService::finishGame()
- [DONE] EPIC-6.3 Winner payout transaction тАФ all-or-nothing PDO
- [DONE] EPIC-6.2 Prize calculation тАФ VictoryService::calculatePrize()
- [DONE] EPIC-6.1 Double victory detection тАФ ╨▓╤Б╤В╤А╨╛╨╡╨╜╨░ ╨▓ checkCardVictory()
- [DONE] EPIC-6.0 Victory detection тАФ VictoryService::checkCardVictory() / checkAllVictories()
Files (6.0тАУ6.4):
- src/Game/VictoryService.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗, 146 ╤Б╤В╤А╨╛╨║)
- src/Game/GameService.php (diff, 703 ╤Б╤В╤А╨╛╨║╨╕)
Notes:
- markNumber() ╨▓ handleDrawBarrel() ╨┐╤А╨╕╨╝╨╡╨╜╤П╨╡╤В╤Б╤П ╨║╨╛ ╨▓╤Б╨╡╨╝ ╨░╨║╤В╨╕╨▓╨╜╤Л╨╝ ╨╕╨│╤А╨╛╨║╨░╨╝
- GameService 703 ╤Б╤В╤А╨╛╨║╨╕ тАФ ╨╖╨╛╨╜╨░ warning; finishGame() ╨║╨░╨╜╨┤╨╕╨┤╨░╤В ╨╜╨░ ADR-╨┤╨╡╨║╨╛╨╝╨┐╨╛╨╖╨╕╤Ж╨╕╤О

PHASE 6 тАФ VICTORY SYSTEM: COMPLETE

Integration tests:
48 / 48 PASSED (auth)
90 / 90 PASSED (lobby)
164 / 164 PASSED (lotto engine)
44 / 44 PASSED (game start)
37 / 37 PASSED (turn system)
38 / 38 PASSED (victory system)

Next planned Epic: EPIC-7.0 Line detection (Apartment)
- [DONE] EPIC-5.5 Turn system tests
Files:
- tests/Manual/test_turn_system.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗)
Notes:
- 37/37 ╤В╨╡╤Б╤В╨╛╨▓ ╨┐╤А╨╛╨╣╨┤╨╡╨╜╨╛
- ╨Я╨╛╨║╤А╤Л╤В╨╛: sendYourTurn, nextDrawer (cyclic, skip disconnected, skip removed, null),
  handleDrawBarrel (guards, bag, drawn_numbers, AFK reset, broadcast, rotation),
  markNumber (column mapping, multi-cell, unknown number),
  full 2-player 3-turn cycle

- [DONE] EPIC-5.4 Player card marking тАФ GameService::markNumber()
- [DONE] EPIC-5.3 Broadcast drawn barrel тАФ barrels_drawn packet
- [DONE] EPIC-5.2 Draw barrel тАФ GameService::handleDrawBarrel()
- [DONE] EPIC-5.1 Drawer rotation тАФ GameService::nextDrawer()
- [DONE] EPIC-5.0 Drawer queue тАФ GameService::sendYourTurn()
Files (5.0тАУ5.4):
- src/Game/GameService.php (diff, 564 ╤Б╤В╤А╨╛╨║╨╕)
Notes:
- masks ╨╕╨╜╨╕╤Ж╨╕╨░╨╗╨╕╨╖╨╕╤А╤Г╤О╤В╤Б╤П ╨▓ handleStartGame (bool[cardsCount][3][9], ╨▓╤Б╨╡ false)
- markNumber() ╨┐╤Г╨▒╨╗╨╕╤З╨╜╤Л╨╣ тАФ ╨╕╤Б╨┐╨╛╨╗╤М╨╖╤Г╨╡╤В╤Б╤П VictoryService ╨▓ Phase 6
- peekNextDrawer() ╨┐╤А╨╕╨▓╨░╤В╨╜╤Л╨╣ тАФ ╤В╨╛╨╗╤М╨║╨╛ ╨┤╨╗╤П next_drawer ╨▓ ╨┐╨░╨║╨╡╤В╨╡ barrels_drawn

PHASE 5 тАФ TURN SYSTEM: COMPLETE

Integration tests:
48 / 48 PASSED (auth)
90 / 90 PASSED (lobby)
164 / 164 PASSED (lotto engine)
44 / 44 PASSED (game start)
37 / 37 PASSED (turn system)

Next planned Epic: EPIC-6.0 Victory detection
- [DONE] EPIC-4.5 Game initialization tests
Files:
- tests/Manual/test_game_start.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗)
Notes:
- 44/44 ╤В╨╡╤Б╤В╨╛╨▓ ╨┐╤А╨╛╨╣╨┤╨╡╨╜╨╛
- ╨Я╨╛╨║╤А╤Л╤В╨╛: auth guard, room guard, host guard, status guard, min players,
  insufficient coins, bank calculation, bag generation, card assignment,
  transaction commit, game_started packet (is_self, cards, masks, drawer_order),
  AFK fields reset

- [DONE] EPIC-4.4 Game start protocol тАФ GameService::handleStartGame()
- [DONE] EPIC-4.3 StartGame transaction тАФ all-or-nothing PDO transaction
- [DONE] EPIC-4.2 Bank creation тАФ bank = sum(total_paid)
- [DONE] EPIC-4.1 Game initialization тАФ status=playing, bag, cards, drawer
- [DONE] EPIC-4.0 Player card purchase logic тАФ total_paid = cards_count ├Ч BET_PER_CARD
Files (4.0тАУ4.4):
- src/Game/GameService.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗, 301 ╤Б╤В╤А╨╛╨║╨░)
- src/Infrastructure/PreparedStatements.php (╨┤╨╛╨▒╨░╨▓╨╗╨╡╨╜ user_by_id)

PHASE 4 тАФ GAME START: COMPLETE

Integration tests:
48 / 48 PASSED (auth)
90 / 90 PASSED (lobby)
164 / 164 PASSED (lotto engine)
44 / 44 PASSED (game start)

Next planned Epic: EPIC-5.0 Drawer queue
- [DONE] EPIC-3.4 Engine test suite
Files:
- tests/Manual/test_lotto_engine.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗)
Notes:
- 164/164 ╤В╨╡╤Б╤В╨╛╨▓ ╨┐╤А╨╛╨╣╨┤╨╡╨╜╨╛
- ╨Я╨╛╨║╤А╤Л╤В╤Л: generateCard, generateBag, validateCard, validateBag
- 100 ╨╕╤В╨╡╤А╨░╤Ж╨╕╨╣ generateCard, 20 ╨╕╤В╨╡╤А╨░╤Ж╨╕╨╣ generateBag
- ╨Ъ╨╛╨╗╨╛╨╜╨╛╤З╨╜╤Л╨╡ ╨╕╨╜╨▓╨░╤А╨╕╨░╨╜╤В╤Л: >=1 ╤З╨╕╤Б╨╗╨╛ ╨╜╨░ ╤Б╤В╨╛╨╗╨▒╨╡╤Ж, ╤Б╨╛╤А╤В╨╕╤А╨╛╨▓╨║╨░ top-to-bottom
- CSPRNG: Fisher-Yates + random_int() ╨▓╨╛ ╨▓╤Б╨╡╤Е shuffle-╨╛╨┐╨╡╤А╨░╤Ж╨╕╤П╤Е

- [DONE] EPIC-3.3 Bag validator тАФ LottoEngine::validateBag()
- [DONE] EPIC-3.2 Card validator тАФ LottoEngine::validateCard()
- [DONE] EPIC-3.1 Bag generator тАФ LottoEngine::generateBag()
- [DONE] EPIC-3.0 Card generator тАФ LottoEngine::generateCard() (mask-based ╨░╨╗╨│╨╛╤А╨╕╤В╨╝)
Files (3.0тАУ3.3):
- src/Game/LottoEngine.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗, ╨╖╨░╨╝╨╡╨╜╨╡╨╜╨░ ╨╖╨░╨│╨╗╤Г╤И╨║╨░)

PHASE 3 тАФ LOTTO ENGINE: COMPLETE

- [DONE] EPIC-2.7 Lobby integration tests
Files:
- tests/Manual/test_lobby_integration.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗)
- tests/Manual/mock_timer.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗)
Notes:
- 90/90 ╤В╨╡╤Б╤В╨╛╨▓ ╨┐╤А╨╛╨╣╨┤╨╡╨╜╨╛
- ╨Я╨╛╨║╤А╤Л╤В╨╛: RoomManager, handleCreateRoom, handleJoinRoom, handleLeaveRoom,
  removePlayerFromLobby, all_players_history, transferHost, handleRoomList,
  Lobby AFK Timer (MockTimer stub ╨▒╨╡╨╖ event loop)
- Workerman\Timer ╨┐╨╛╨┤╨╝╨╡╨╜╤С╨╜ ╤З╨╡╤А╨╡╨╖ mock_timer.php (namespace stub)
- ╨д╤Г╨╜╨║╤Ж╨╕╨╛╨╜╨░╨╗╤М╨╜╤Л╨╣ WebSocket ╤В╨╡╤Б╤В ╨╛╤В╨╗╨╛╨╢╨╡╨╜ ╨┤╨╛ EPIC-10.x (server.php ╨╜╨╡ ╤Б╨╛╨╖╨┤╨░╨╜)

Commit: EPIC-2.7 lobby-integration-tests

- [DONE] EPIC-2.6 Lobby AFK system
Files:
- src/Lobby/LobbyService.php (diff)
Notes:
- startLobbyAfkTimer(): ╨╛╤В╨╝╨╡╨╜╤П╨╡╤В ╨┐╤А╨╡╨┤╤Л╨┤╤Г╤Й╨╕╨╣ тЖТ Timer::add(1s repeat) тЖТ ╨┐╤А╨╛╨▓╨╡╤А╤П╨╡╤В time()-host.last_action >= 120s тЖТ transferHost()
- stopLobbyAfkTimer(): Timer::del + lobby_afk_timer_id = null
- handleJoinRoom(): ╨▓╤Л╨╖╨╛╨▓ startLobbyAfkTimer() ╨║╨╛╨│╨┤╨░ count(players) >= 2
- handleLeaveRoom(): ╨▓╤Л╨╖╨╛╨▓ stopLobbyAfkTimer() ╨║╨╛╨│╨┤╨░ count(players) < 2 ╨┐╨╛╤Б╨╗╨╡ ╤Г╨┤╨░╨╗╨╡╨╜╨╕╤П
- destroyRoom() ╤Г╨╢╨╡ ╨╛╤В╨╝╨╡╨╜╤П╨╡╤В ╤В╨░╨╣╨╝╨╡╤А тАФ ╨┤╤Г╨▒╨╗╨╕╤А╨╛╨▓╨░╨╜╨╕╤П ╨╜╨╡╤В
- ╨Ф╨╛╨▒╨░╨▓╨╗╨╡╨╜ use Workerman\Timer
- Known gap ╨╖╨░╨║╤А╤Л╤В (╨╖╨░╤Д╨╕╨║╤Б╨╕╤А╨╛╨▓╨░╨╜ ╨▓ EPIC-2.3)
- ╨д╤Г╨╜╨║╤Ж╨╕╨╛╨╜╨░╨╗╤М╨╜╤Л╨╣ ╤В╨╡╤Б╤В (WebSocket) ╨╛╤В╨╗╨╛╨╢╨╡╨╜ ╨┤╨╛ EPIC-10.x (server.php ╨╜╨╡ ╤Б╨╛╨╖╨┤╨░╨╜)

Commit: EPIC-2.6 lobby-afk-system

- [DONE] EPIC-2.5 Host transfer
Files:
- src/Lobby/LobbyService.php (╤А╨╡╨░╨╗╨╕╨╖╨╛╨▓╨░╨╜╨╛ ╨▓ ╤А╨░╨╝╨║╨░╤Е EPIC-2.3)
Notes:
- transferHost(): FIFO ╨┐╨╛ drawer_order ╤Б╤А╨╡╨┤╨╕ active тЖТ ╨╜╨╛╨▓╤Л╨╣ host_conn_id
- ╨Т╤Л╨╖╤Л╨▓╨░╨╡╤В╤Б╤П ╨╕╨╖ handleLeaveRoom() ╨┐╤А╨╕ $wasHost === true
- ╨Х╤Б╨╗╨╕ ╨╜╨╡╤В ╨░╨║╤В╨╕╨▓╨╜╤Л╤Е ╨╕╨│╤А╨╛╨║╨╛╨▓ тЖТ destroyRoom()
- ╨Ю╤В╨┤╨╡╨╗╤М╨╜╨╛╨│╨╛ ╨║╨╛╨┤╨░ ╨╜╨╡ ╨┐╨╛╤В╤А╨╡╨▒╨╛╨▓╨░╨╗╨╛╤Б╤М: ╨╗╨╛╨│╨╕╨║╨░ ╨┐╨╛╨║╤А╤Л╤В╨░ EPIC-2.3

Commit: (╨▓╤Е╨╛╨┤╨╕╤В ╨▓ EPIC-2.3 leave-room)

- [DONE] EPIC-2.4 Room list
Files:
- src/Lobby/LobbyService.php (diff)
Notes:
- handleRoomList(): auth guard тЖТ ╨╕╤В╨╡╤А╨░╤Ж╨╕╤П $worker->rooms тЖТ buildRoomListEntry() тЖТ room_list ╨┐╨░╨║╨╡╤В
- ╨Т╨╛╨╖╨▓╤А╨░╤Й╨░╤О╤В╤Б╤П ╨▓╤Б╨╡ ╨║╨╛╨╝╨╜╨░╤В╤Л ╨▓ ╨╗╤О╨▒╨╛╨╝ ╤Б╤В╨░╤В╤Г╤Б╨╡ (waiting / playing / apartment)
- ╨д╨╛╤А╨╝╨╕╤А╨╛╨▓╨░╨╜╨╕╨╡ entry ╨┤╨╡╨╗╨╡╨│╨╕╤А╨╛╨▓╨░╨╜╨╛ RoomManager::buildRoomListEntry() (EPIC-2.0)
- ╨Я╤А╨╛╤В╨╛╨║╨╛╨╗: {"type":"room_list","rooms":[...]} тАФ ANCHOR_PROTOCOL.md ┬з Lobby

Commit: EPIC-2.4 room-list

- [DONE] EPIC-2.3 Leave room
Files:
- src/Lobby/LobbyService.php (diff)
Notes:
- handleLeaveRoom(): auth тЖТ findRoomIdByConnId тЖТ guard status=waiting тЖТ removePlayerFromLobby тЖТ transferHost ╨╡╤Б╨╗╨╕ ╤Г╤И╤С╨╗ ╤Е╨╛╤Б╤В
- removePlayerFromLobby(): ╨╖╨░╨┐╨╕╤Б╤М ╨▓ all_players_history тЖТ unset players тЖТ ╨╛╤З╨╕╤Б╤В╨║╨░ drawer_order тЖТ destroyRoom ╨╡╤Б╨╗╨╕ ╨┐╤Г╤Б╤В╨╛ тЖТ broadcast player_left ╨░╨║╤В╨╕╨▓╨╜╤Л╨╝
- transferHost(): FIFO ╨┐╨╛ drawer_order ╤Б╤А╨╡╨┤╨╕ active тЖТ destroyRoom ╨╡╤Б╨╗╨╕ ╨╜╨╡╤В ╨░╨║╤В╨╕╨▓╨╜╤Л╤Е
- ╨Я╤А╨╛╤В╨╛╨║╨╛╨╗: player_left {type, username, reason} тАФ ╤В╨╛╨╗╤М╨║╨╛ ╨░╨║╤В╨╕╨▓╨╜╤Л╨╝, ╨╜╨╡ ╤Г╤Е╨╛╨┤╤П╤Й╨╡╨╝╤Г
- ╨н╨║╨╛╨╜╨╛╨╝╨╕╨║╨░: ╨╝╨╛╨╜╨╡╤В╤Л ╨╜╨╡ ╨╖╨░╤В╤А╨╛╨╜╤Г╤В╤Л (total_paid=0 ╨▓ waiting)
- Known gap: lobby_afk_timer_id ╨┐╤А╨╕ count<2 ╨╜╨╡ ╨╛╤В╨╝╨╡╨╜╤П╨╡╤В╤Б╤П тАФ ╤Г╤Б╤В╤А╨░╨╜╤П╨╡╤В╤Б╤П ╨▓ EPIC-2.6

Commit: 5974582 (git commit -m "EPIC-2.3 leave-room")

Commit: 5974582 (git commit -m "EPIC-2.0 room-manager")

- [DONE] EPIC-2.1 Create room
Files:
- src/Lobby/LobbyService.php

Notes:
- handleCreateRoom(): ╨▓╨░╨╗╨╕╨┤╨░╤Ж╨╕╤П ╨╗╨╕╨╝╨╕╤В╨╛╨▓ тЖТ bcrypt ╨┐╨░╤А╨╛╨╗╤М тЖТ RoomManager::createRoom() тЖТ player entry тЖТ room_joined
- ╨Я╤А╨╛╨▓╨╡╤А╨║╨╕: MAX_ROOMS, MAX_TOTAL_PLAYERS, cards_count тИИ {1,2}, max_players тИИ [2..10]
- ╨Ь╨╛╨╜╨╡╤В╤Л ╨╜╨╡ ╤Б╨┐╨╕╤Б╤Л╨▓╨░╤О╤В╤Б╤П (Reservation Rule, ANCHOR_CORE Part 2)
- drawer_order ╨╕╨╜╨╕╤Ж╨╕╨░╨╗╨╕╨╖╨╕╤А╤Г╨╡╤В╤Б╤П ╤Е╨╛╤Б╤В╨╛╨╝ (ANCHOR_CORE ┬з Drawer Order Rules)
- ╨Ъ╨░╤А╤В╤Л ╨╜╨╡ ╨╜╨░╨╖╨╜╨░╤З╨░╤О╤В╤Б╤П тАФ ╨┤╨╡╨╗╨╡╨│╨╕╤А╨╛╨▓╨░╨╜╨╛ start_game() (EPIC-4.1)

Commit: 5974582 (git commit -m "EPIC-2.1 create-room")

- [DONE] EPIC-2.2 Join room
Files:
- src/Lobby/LobbyService.php (diff)
Notes:
- handleJoinRoom(): auth тЖТ room exists тЖТ status=waiting тЖТ not full тЖТ MAX_TOTAL_PLAYERS тЖТ password тЖТ cards_count тЖТ player entry тЖТ room_joined тЖТ broadcast player_joined
- ╨Я╨░╤А╨╛╨╗╤М: password_verify(bcrypt)
- drawer_order: FIFO append (ANCHOR_CORE ┬з Drawer Order Rules)
- room_joined тЖТ ╨▓╤Е╨╛╨┤╤П╤Й╨╡╨╝╤Г; player_joined тЖТ ╨╛╤Б╤В╨░╨╗╤М╨╜╤Л╨╝ ╨░╨║╤В╨╕╨▓╨╜╤Л╨╝
Commit: 5974582

---

## PRE-BUILT COMPONENTS

### PRE-BUILT-1 тАФ Reconnect Token Infrastructure
Status: Completed (╨╕╨╖╨╛╨╗╨╕╤А╨╛╨▓╨░╨╜, ╨┐╨╛╨║╨░ ╨╜╨╡ ╨╕╤Б╨┐╨╛╨╗╤М╨╖╤Г╨╡╤В╤Б╤П)

Files:
- src/Auth/ReconnectTokenService.php

Notes:
- ╨У╨╡╨╜╨╡╤А╨░╤Ж╨╕╤П ╨╕ ╨▓╨░╨╗╨╕╨┤╨░╤Ж╨╕╤П 64-╤Б╨╕╨╝╨▓╨╛╨╗╤М╨╜╤Л╤Е HEX ╤В╨╛╨║╨╡╨╜╨╛╨▓ ╨┐╨╡╤А╨╡╨┐╨╛╨┤╨║╨╗╤О╤З╨╡╨╜╨╕╤П.
- ╨Э╨╡ ╨╕╨╜╤В╨╡╨│╤А╨╕╤А╨╛╨▓╨░╨╜ ╨▓ ╤В╨╡╨║╤Г╤Й╨╕╨╣ ╨┐╤А╨╛╤В╨╛╨║╨╛╨╗.
- ╨Я╨╗╨░╨╜╨╕╤А╤Г╨╡╨╝╤Л╨╣ ╨┐╨╛╤В╤А╨╡╨▒╨╕╤В╨╡╨╗╤М: EPIC-8.0 ReconnectService.

---

## PATCHES

## FIX-12 тАФ Test loggers writing into the production log file
Status: Completed
Date: 2026-07-25

Found during: a live operational incident, not a proactive audit this
time. A permission-ownership mismatch (`game.db`/`workerman.log`/
`logs/server.log` left root-owned after test runs executed as root
against the live VPS, while the production `lotto-server.service` runs
as `www-data`) caused a real crash-loop on the production service.
While diagnosing that, a confusing `[ERROR] ... CHECK constraint failed:
coins <= 200` line was found in `logs/server.log` тАФ alarming at first
glance, since no such constraint exists in the real schema
(`docs/... .schema users` confirmed no CHECK clause). Traced to its
actual source rather than assumed.

Files:
- src/Core/Logger.php (diff тАФ optional `?string $logFilePath = null`
  constructor parameter, mirroring the FIX-4 precedent for
  `Database::__construct(?PDO $pdo = null)`. Default (no argument)
  preserves exact prior behavior тАФ server.php's own `new Logger()` needs
  no change at all.)
- tests/Manual/test_login.php (diff)
- tests/Manual/test_register.php (diff)
- tests/Manual/test_session_service.php (diff)
- tests/Manual/test_single_session.php (diff)
- tests/Manual/test_victory.php (diff тАФ the actual source of the
  incident's confusing ERROR line)
- tests/Manual/test_admin_logs.php (diff)
- tests/Manual/test_admin_integration.php (diff)
- tests/Manual/test_logger.php (DELETED тАФ see below)

Problem:
- `Logger::__construct()` hardcoded the log path to `logs/server.log`
  with no way to inject a different one. Any test constructing a real
  `Logger` (not a `MockLogger`) тАФ which several do purely incidentally,
  as a required dependency of `AuthService`/`GameFinishService`/
  `AdminService`, with no interest in testing logging itself тАФ wrote
  straight into the shared production log on every run.
- `tests/Manual/test_victory.php`'s `makeSvc()` (added in FIX-4) builds a
  real `GameFinishService` over an isolated **in-memory** SQLite database
  specifically to test transaction rollback via a deliberately-rigged
  `CHECK(coins <= 200)` constraint тАФ genuinely correct DB isolation. But
  it paired that with a real, default-path `Logger`, so the rigged
  failure's error message still landed in the real `logs/server.log`,
  indistinguishable from an actual production incident. The existing
  code comment at that exact line already said "╨┐╨╛╨▒╨╛╤З╨╜╤Л╨╣ ╤Н╤Д╤Д╨╡╨║╤В тАФ ╨╖╨░╨┐╨╕╤Б╤М
  ╨▓ logs/server.log" (side effect тАФ writes to logs/server.log) тАФ
  correctly identified by whoever wrote FIX-4, but never fixed, and this
  is exactly what it eventually caused.
- Two more genuine instances found by a full sweep of every `new
  Logger(...)` call site project-wide (not just the one that caused the
  incident): `test_admin_logs.php` TEST 6 and `test_admin_integration.php`
  TEST 4 both wrote real marker/action lines into the shared log purely
  as a side effect of exercising `Logger::getLastLines()`/
  `AdminService`, with no reason to specifically target the production
  path.
- A latent, interesting side-discovery: `test_lobby_integration.php` and
  `test_auth_integration.php` already called `new Logger('/dev/null')` тАФ
  an earlier session's own attempt to solve exactly this problem. It
  never worked: PHP does not error when extra arguments are passed to a
  zero-parameter constructor (unlike stricter-arity languages), so the
  `'/dev/null'` argument was silently discarded and the call was
  equivalent to `new Logger()` the whole time. This fix makes that
  existing, previously-non-functional intent actually work, at zero
  additional cost тАФ no code change needed in either file.
- `tests/Manual/test_logger.php` (distinct from the project-root copy
  already deleted in a much earlier session, per the 2026-07-03 decision
  log entry) was a leftover duplicate: a print_r()-based smoke script
  with zero assertions, explicitly documented by this project's own
  EPIC-9.6 entry and by `test_admin_logs.php`'s own header comment as
  superseded. It ran on every `run_ALL_tests.sh` pass, wrote generic
  "test 1"/"test 2"/"test 3" lines into the real log, and contributed
  nothing (no pass/fail signal at all) тАФ deleted.

Deliberately NOT changed: `tests/Manual/test_helpers_runner.php`
Scenario 4 and `server.php` itself. Scenario 4's entire purpose is
verifying that the *default* `Logger` path is genuinely
`logs/server.log` тАФ redirecting it would break the one thing it's
testing. `server.php`'s own `new Logger()` is correct production code by
definition.

Known, explicitly out-of-scope for this fix (lower severity, different
category тАФ see decision log): the real-WS-client subprocess tests
(`test_auth_packet_routing.php`, `test_lobby_packet_routing.php`,
`test_game_packet_routing.php`, `test_admin_packet_routing.php`,
`test_session_lifecycle.php`, `test_packet_validation.php`,
`test_server_bootstrap.php`) each spawn a genuine `php server.php start`
subprocess to exercise real end-to-end routing тАФ that subprocess's
`Logger` is unmodified production code, correctly writing to the real
log path, because it *is* the real server. This still leaves
test-generated `INFO`/`WARNING` lines with test-like usernames
(`fix10_user1`, `e106_admin`, etc.) in the production log тАФ clearly
identifiable as test noise, not the confusing false-`ERROR` class of
problem this fix targets. Properly isolating it would require making
`server.php`'s own log path configurable (an env var, defaulting to the
current path) and updating all seven harnesses to set it тАФ a larger,
separate change touching production code, left for an explicit future
decision rather than folded in here silently.

Verified:
- All 5 originally-affected tests re-run individually with an MD5 hash
  of `logs/server.log` captured before and after each тАФ byte-identical
  in every case (confirmed no write occurred).
- Full `run_ALL_tests.sh` re-run with the same before/after hash check
  across the *entire* suite тАФ the only lines that appear afterward
  originate from the real-WS-subprocess tests described above (expected,
  out of scope), not from any of the fixed files.
- `test_helpers_runner.php` re-run in isolation тАФ still correctly writes
  to and reads back from the real default path, confirming `Logger`'s
  default behavior is byte-for-byte unchanged.
- Every affected test's pass count matches its previously-documented
  count exactly (40/40 victory, 91/91 lobby integration, 55/55 auth
  integration, 20/20 admin integration, etc.) тАФ no behavior change, only
  the log destination.
- Full regression across all 29 remaining `tests/Manual/*.php` files (30
  minus the deleted `test_logger.php`) тАФ 0 failed.

No ADR required тАФ no protocol, economy, timer, or room/player structure
touched. Purely a test-isolation and logging-infrastructure fix.

Diff: patches/FIX-12-Logger.patch, patches/FIX-12-test-login.patch,
patches/FIX-12-test-register.patch, patches/FIX-12-test-session-service.patch,
patches/FIX-12-test-single-session.patch, patches/FIX-12-test-victory.patch,
patches/FIX-12-test-admin-logs.patch, patches/FIX-12-test-admin-integration.patch

## FIX-16 тАФ server.php bootstrap helper missing from committed Helpers.php
Status: Completed
Date: 2026-07-28

Found during: full `./run_ALL_tests.sh` on the Ubuntu VPS at the end of
EPIC-13.4 sign-off тАФ not during local Windows dev, where the committed
`run_ALL_tests.php` still skips the eight live-WS-subprocess tests via
`$skipOnWindows` (FIX-15 intent documented in `docs/LOCAL_ENVIRONMENT.md`
but the bootstrap helpers themselves were never committed).

Background (FIX-15): `lottoBootstrapPhpExtensions()` and `lottoPhpIniArgs()`
were developed locally for Windows SQLite bootstrap and child-process
`proc_open` spawning. They lived only in an **uncommitted** diff to
`src/Core/Helpers.php` alongside local edits to `run_ALL_tests.php`.

Breaking commit: `b203493` (EPIC-13.1) added
`lottoBootstrapPhpExtensions()` to `server.php:109` (and the corresponding
`use function` import) тАФ copied from the local uncommitted state тАФ without
the function definition being present in the repository. On Linux/VPS the
call is a no-op when defined, but **fatal when undefined**.

Symptom on VPS (`/opt/lotto-game`, `./run_ALL_tests.sh` after `git pull`
to Phase 13 HEAD before this fix):
- Eight live WS subprocess tests failed with
  `server.php did not bind port тАж in time (running=no)`.
- stderr on every spawned `server.php`:
  `PHP Fatal error: Call to undefined function
  Lotto\Core\lottoBootstrapPhpExtensions() in server.php:109`.

Affected tests (all subprocess-spawned `server.php`):
`test_admin_packet_routing.php`, `test_auth_packet_routing.php`,
`test_game_packet_routing.php`, `test_lobby_packet_routing.php`,
`test_packet_validation.php`, `test_server_bootstrap.php`,
`test_session_lifecycle.php`, `test_protocol_audit.php`.

Files:
- src/Core/Helpers.php (diff тАФ add `lottoBootstrapPhpExtensions()` and
  `lottoPhpIniArgs()`; both no-op / empty-array on Linux)

Fix commit: `0de46d0` тАФ `Fix missing lottoBootstrapPhpExtensions in committed
Helpers.php.`

Verified:
- Fresh `git clone` from GitHub at `0de46d0` (branch
  `cursor/epic-11-1-vps-ws-test-isolation`, no workspace-local files):
  `php server.php start` with isolated `LOTTO_WS_PORT` reaches Workerman
  `[ok]` тАФ no fatal error (Windows dev host, 2026-07-28; `composer install`
  not available in agent environment тАФ vendor copied from lockfile-matched
  tree for bind test only).
- Local workspace `php run_ALL_tests.php` at `0de46d0`+: **41/41** test
  files PASS (Windows dev host, 2026-07-28; uses uncommitted runner with
  FIX-15 Windows WS enablement).
- VPS `./run_ALL_tests.sh` after `git pull` to `0de46d0`:
  **MANUAL VERIFICATION REQUIRED** тАФ agent has no SSH access to
  `/opt/lotto-game`. Expected: all `tests/Manual/test_*.php` pass (41 files
  at HEAD); the eight subprocess tests above must reach port bind.

Process lesson (same class as FIX-12): local-only or root-owned artifacts
masked a production-breaking gap until the VPS-authoritative test run.
Any symbol `server.php` calls must be committed **in the same commit or an
earlier one** before the call lands. Uncommitted helper functions
referenced by committed entrypoints are a release blocker тАФ Windows skips
are not a substitute for Ubuntu sign-off per `LOCAL_ENVIRONMENT.md`.

No ADR required тАФ no protocol, economy, timer, or room/player structure
touched. Purely a missing-dependency / process-discipline fix.

Diff: commit `0de46d0` (src/Core/Helpers.php only)

## EPIC-10.7 тАФ Protocol integration tests
Status: Completed
Date: 2026-07-24

Files:
- tests/Manual/test_protocol_completeness.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ 50 assertions)

Scope, per explicit user direction: this Epic checks that everything
ANCHOR_CORE.md/ANCHOR_PROTOCOL.md *declare* is actually *present* on the
server side тАФ a completeness/coverage audit, not a re-test of business
logic. Business logic is already exhaustively covered: every module has
its own real-WS-client routing test (test_auth_packet_routing.php,
test_lobby_packet_routing.php, test_game_packet_routing.php,
test_admin_packet_routing.php) plus dozens of Phase-specific unit tests.
Re-testing that logic here would be redundant, not thorough.

Deliberately a static source-cross-reference test, not a live-server one
тАФ it parses the actual registries out of docs/ANCHOR_CORE.md and
docs/ANCHOR_PROTOCOL.md at run time (not a hardcoded copy of them), so it
stays honest against drift in either direction: it would catch a future
session adding a new packet/action to the docs without implementing it,
*or* implementing something without documenting it (Rule 27 Naming
Authority). Checks:
1. Every declared Protocol Action reachable in server.php's dispatcher.
2. Every declared Protocol Packet Type emitted somewhere in src/ or
   server.php.
3. Every declared Error Code used somewhere.
4. Reverse check: packet types emitted in code but not declared anywhere.
5. All four protocol handlers (AuthHandler/LobbyHandler/GameHandler/
   AdminHandler) actually instantiated in server.php's onWorkerStart.

Result: 50/50 PASSED, 0 failed, 3 warnings тАФ all three warnings match
already-documented KNOWN GAPS, no new surprises:
- `admin_stats_data` (packet type): declared, zero emission sites тАФ
  already flagged (2026-07-03 audit) as unimplemented/no Epic assigned.
- `afk_warning` (packet type): emitted (ReconnectService, EPIC-8.3), not
  declared in the registry тАФ already flagged as documentation debt.
- `error.banned` (error code): declared, zero usage sites тАФ **new
  finding this Epic**. Not a functional gap: the dedicated `banned`
  packet type (`{"type":"banned","until":...}`) already covers every
  ban-rejection path (login, reconnect since FIX-11, admin notification)
  тАФ `error.banned` appears to be a redundant/unused declaration in the
  Error Packet Codes registry, never actually needed once the dedicated
  packet type existed. Logged as a new KNOWN GAP (low priority,
  documentation-only) rather than touched: ANCHOR_PROTOCOL.md states it
  "Never changes," and removing a declared code тАФ even an unused one тАФ
  is arguably a change to that document; left for an explicit user
  decision (same treatment as the admin_stats_data gap: either assign it
  a purpose or formally deprecate it).

No code defects found by this Epic тАФ confirms the wiring built across
EPIC-10.0-10.6 is genuinely complete against the declared protocol
surface, not just working for the specific scenarios the routing tests
happened to cover.

PHASE 10 тАФ WEBSOCKET PROTOCOL: COMPLETE (10.0-10.7 all done).

Diff: patches/EPIC-10.7-test-protocol-completeness.patch (new file, full
content тАФ see also tests/Manual/test_protocol_completeness.php directly)


Status: Completed
Date: 2026-07-24

Files:
- src/Admin/AdminHandler.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ thin wrapper ╨╜╨░╨┤ AdminService,
  ╤В╨╛╤В ╨╢╨╡ ╨┐╨░╤В╤В╨╡╤А╨╜ ╤З╤В╨╛ GameHandler/LobbyHandler)
- server.php (diff тАФ AdminService/AdminHandler dependency wiring in
  onWorkerStart, ╨▓╤Б╨╡ 5 admin_* actions ╨┤╨╛╨▒╨░╨▓╨╗╨╡╨╜╤Л ╨▓ dispatcher; ╤Б╨╝. FIX-11
  ╨╜╨╕╨╢╨╡ тАФ ╤З╨░╤Б╤В╤М ╤Н╤В╨╛╨│╨╛ ╨╢╨╡ diff)
- src/Admin/AdminService.php (diff тАФ FIX-11, ╤Б╨╝. ╨╜╨╕╨╢╨╡)
- src/Auth/AuthHandler.php (diff тАФ FIX-11, ban check in handleReconnect())
- src/Auth/AuthService.php (diff тАФ FIX-11, getUserById() returns
  banned_until now too)
- src/Infrastructure/PreparedStatements.php (diff тАФ FIX-11, extended
  user_auth_fields_by_id to include banned_until)
- tests/Manual/test_admin_ban.php (diff тАФ FIX-11, MockConnection needs a
  close() method now that handleBanUser() actually calls it)
- tests/Manual/test_admin_integration.php (diff тАФ FIX-11, same fix for
  SpyConnection)
- tests/Manual/test_admin_packet_routing.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ 15 assertions,
  real WS client, covers both EPIC-10.6 routing and FIX-11 regression
  scenarios together since FIX-11 was found while probing this Epic's own
  wiring тАФ same pattern as EPIC-10.5/FIX-9)

AdminService already existed and was fully tested (Phase 9) тАФ the routing
part of this Epic is, like every other EPIC-10.x, pure dependency wiring.
The one thing that made this wiring non-trivial: AdminService's
constructor takes 7 nullable dependencies (stmts, logger, lobbyService,
reconnectService, apartmentService, db, roomManager), and several of them
degrade silently rather than erroring if omitted тАФ missing
lobbyService/reconnectService/apartmentService means a banned/kicked
online player is never actually removed from their room (money still
moves correctly, but a "ghost" player entry lingers); missing roomManager
means admin_close_room falls back to a raw unset() that skips ALL timer
cleanup, the exact class of bug FIX-6 fixed elsewhere. All seven are now
wired. $apartmentService is deliberately the same local variable already
in scope from the EPIC-10.5 block (never stored as a $worker property,
since only GameService needed it there) rather than retroactively
touching completed EPIC-10.5 code тАФ captured by closure scope instead.

Found and fixed during this Epic's audit (FIX-11) тАФ proactively looking
for another FIX-9/FIX-10-class interaction bug before shipping, per user
request:

Problem (three compounding gaps, all in the ban path specifically тАФ
handleKickUser() was already correct):
1. AdminService::handleBanUser()'s structural room-removal
   (findPlayerMembership() + removePlayerFromLobby/Game/Apartment) was
   nested INSIDE `if (isset($worker->userConnections[$targetUserId]))`.
   Before FIX-10, that map entry was never cleared on disconnect, so this
   accidentally always ran for anyone who'd ever been online. After
   FIX-10 correctly started clearing it on genuine disconnect, a banned
   player who happened to be mid-reconnect-window (disconnected, not yet
   timed out) at ban time no longer had a userConnections entry тАФ so the
   entire removal branch was skipped. They kept their room seat and
   active reconnect_timer, and reconnecting before it expired let them
   fully resume playing seconds after being banned.
2. Banning a currently-*online* target never closed their WebSocket
   connection тАФ only sent them a `banned` packet. $connection->userId/
   isAdmin/sessionToken stayed bound; they could keep issuing any action
   not tied to the now-removed room indefinitely, until they happened to
   disconnect on their own.
3. The most severe: AuthHandler::handleReconnect() never checked
   banned_until at all, unlike AuthService::login() which does. A banned
   user could bypass the ban indefinitely simply by sending
   {"action":"reconnect","token":<their existing session_token>} instead
   of logging in fresh тАФ reconnect was a complete, permanent end-run
   around moderation, independent of anything room-related.
- Verified empirically end-to-end (not simulated) before writing any fix,
  same discipline as FIX-10: reproduced all three independently with a
  live server and real WS clients.

Fix:
1. handleBanUser()'s removal logic un-nested from the userConnections
   check тАФ now runs unconditionally based on findPlayerMembership(),
   identical in shape to handleKickUser()'s already-correct pattern. The
   "notify + close" part remains conditional on the target being
   currently online (that part is correctly conditional).
2. If the target is online, after sending `banned`, their connection is
   now explicitly closed ($targetConnection->close()). Order matters:
   room removal happens first, so onClose's own
   ReconnectService::handleDisconnect() correctly no-ops afterward (the
   player is already gone from $room['players'] by the time it runs) тАФ
   no double-removal/double-refund risk (the FIX-3 class of bug).
3. AuthService::getUserById() (added in FIX-10) now also returns
   banned_until (new column in the user_auth_fields_by_id query).
   AuthHandler::handleReconnect() checks it immediately after fetching
   the user and, if currently banned, responds with the exact same
   {"type":"banned","until":...} packet login() already sends тАФ reusing
   the existing contract, not introducing a new one.

Verified non-false-positive (each of the three independently, by
reverting only that piece and re-running the relevant scenario):
- Reverted only the handleBanUser() un-nesting -> disconnected-at-ban-time
  player kept their room seat and successfully reconnected; restored ->
  fixed again.
- Reverted only the connection-close call -> N/A as a standalone revert
  (bundled with #1 in the same nested block); covered together in the
  online-ban scenario, confirmed working as one unit.
- Reverted only the AuthHandler ban check -> banned user's reconnect
  succeeded and subsequent room_list worked (full bypass reproduced
  exactly as before the fix); restored -> fixed again.

Result:
- tests/Manual/test_admin_packet_routing.php (new): 15/15 PASSED тАФ
  real WS client against a live server.php. Covers admin_get_logs/
  admin_ban_user/admin_unban_user/admin_kick_user/admin_close_room
  routing, the assertAdmin guard (both auth_required and not_your_turn
  paths), cannot_moderate_admin, and three FIX-11 scenarios: online-ban
  (banned packet + connection closed), mid-disconnect-ban (reconnect
  correctly blocked, room structurally cleaned up despite the target
  being offline at ban time), and unban-then-relogin.
- tests/Manual/test_admin_ban.php: 9/9 PASSED after adding close() to
  MockConnection (fixture update, not a business-logic change тАФ Rule 22).
- tests/Manual/test_admin_integration.php: 20/20 PASSED after the same
  fixture update to SpyConnection.
- Full regression across every tests/Manual/*.php file (30 files,
  including the new one) тАФ 0 failed.

Also fixed in this Epic (trivial, unrelated to FIX-11's substance): a
pre-existing PHP warning ("Undefined property: ...TcpConnection::$userId")
in onClose when a raw TCP connection closes before ever completing the
WebSocket handshake (so onWebSocketConnected's field initialization never
ran) тАФ direct property access changed to null-coalescing, matching the
adjacent log line's existing style.

No ADR required for the routing wiring (no protocol change). FIX-11 also
requires no ADR: no protocol packet, error code, room/player structure
key, or timer changed тАФ `banned` is the same existing packet login()
already sends, reused from a second call site where it had been missing.

Diff: patches/EPIC-10.6-server.patch, patches/FIX-11-AdminService.patch,
patches/FIX-11-AuthHandler.patch, patches/FIX-11-AuthService.patch,
patches/FIX-11-PreparedStatements.patch,
patches/FIX-11-test-admin-ban.patch, patches/FIX-11-test-admin-integration.patch

## FIX-10 тАФ Permanent session lockout after any disconnect outside room membership
Status: Completed
Date: 2026-07-24

Found during: proactive audit before starting EPIC-10.6, specifically
looking for another FIX-9-class issue (a bug only reachable once real
end-to-end routing exists) before adding more admin-side removal paths
that would have inherited the same defect.

Files:
- src/Infrastructure/PreparedStatements.php (diff тАФ new query
  `user_auth_fields_by_id`: id/username/is_admin by id; neither existing
  `user_by_id` (id, coins) nor `user_admin_by_id` (id, is_admin) return
  username, which AuthHandler::bindConnection() requires)
- src/Auth/AuthService.php (diff тАФ new `getUserById(int $userId): ?array`,
  using the statement above; returns null on missing user, which the
  caller treats as an invalid session rather than throwing)
- src/Auth/AuthHandler.php (diff тАФ `handleReconnect()` now calls
  `getUserById()` and `bindConnection()`, mirroring what register()/
  login() already do; previously only `$worker->userConnections[$userId]`
  was restored and `$connection->userId` was never set)
- server.php (diff тАФ `onClose` now unsets
  `$worker->userConnections[$connection->userId]` when the closing
  connection had one)
- tests/Manual/test_session_lifecycle.php (new file тАФ real WS client
  against a live server.php, 6 assertions, no MockConnection)

Problem:
- `$worker->userConnections[$userId]` (ADR-001 ┬з Single Active Session)
  is written by register/login/reconnect but was **never unset by any
  code path whatsoever** тАФ not in `onClose`, not in
  `removePlayerFromLobby()/removePlayerFromGame()/removePlayerFromApartment()`,
  not on reconnect-timer expiry, not in `admin_close_room`. Once set, an
  account's slot in that map is permanent for the life of the worker
  process.
- `AuthService::login()`'s single-session guard is a plain `isset()`
  check against that map тАФ so once a user disconnects, EVERY subsequent
  `login` attempt with correct credentials fails with the generic
  `error.auth_invalid_credentials` (message text: "User already logged
  in", though the client has no reliable way to distinguish this from a
  wrong password since the error *code* is deliberately generic тАФ see
  `AuthHandler::mapLoginError()`).
- The only theoretical way back in is the `reconnect` action, per
  ADR-001 ┬з5-6 ("reconnect is the only supported method for restoring
  access"). But `AuthHandler::handleReconnect()` only ever restored
  `$worker->userConnections[$userId]` тАФ it never set
  `$connection->userId` itself (a second, related gap: this is the same
  class of omission flagged as a KNOWN GAP in EPIC-10.5, just with a much
  larger blast radius than originally scoped there). For a user with an
  active room, `ReconnectService::handleReconnect()` (wired in EPIC-10.5)
  closes that gap by binding `$connection->userId` when it finds a
  matching disconnected room player. **For a user who was never in a
  room тАФ or whose room session already ended тАФ nothing ever binds
  `$connection->userId`,** so the `error.auth_required` guard in
  `server.php` blocks every subsequent action, including `create_room`/
  `join_room`.
- Net effect: any account that disconnects while not currently seated in
  a room (idling in the lobby, between games, after `leave_room`, after
  a finished game's room was destroyed, or simply a network blip before
  ever joining a room) is **permanently locked out** тАФ neither `login`
  nor `reconnect` can recover it. Only a full server restart clears
  `$worker->userConnections`.
- Why this was undetected until now: unreachable through any real code
  path before EPIC-10.5, since `onClose` was a stub that never called
  `ReconnectService::handleDisconnect()` at all prior to that Epic тАФ no
  disconnect ever triggered any downstream state change. The one test
  that exercises the single-session concept,
  `tests/Manual/test_single_session.php` (Phase 1), manually performs
  `unset($worker->userConnections[$userId])` inside the test itself
  before asserting a second login succeeds тАФ simulating the cleanup step
  that production code never actually implements, rather than exercising
  a real code path. Textbook instance of ANCHOR_RULES.md Part 22 ("Tests
  must not compensate for missing contracts") тАФ except the missing
  contract was on the implementation side, not the test's own logic, and
  had gone unnoticed because nothing forced the two to be compared until
  real routing existed.
- Verified empirically end-to-end (not simulated) before writing any fix:
  registered a user, closed the connection via a raw TCP close without
  ever joining a room, then confirmed both `login` (rejected,
  "already logged in") and `reconnect` (silently "succeeded" at the
  protocol level but left the connection unauthenticated тАФ
  `room_list`/`create_room` afterward returned `error.auth_required`)
  failed to restore access.

Fix:
- `AuthHandler::handleReconnect()`: after validating the token format and
  confirming `$worker->sessionTokens[$token]` exists, now looks up the
  user via the new `AuthService::getUserById()` and, on success, calls
  the same private `bindConnection()` helper register()/login() already
  use тАФ setting `$connection->userId`/`username`/`isAdmin`/`sessionToken`.
  If the user row is somehow gone (defensive тАФ a session token pointing
  at a deleted account), responds `error.auth_invalid_token` rather than
  proceeding with a half-bound connection.
- `server.php`'s `onClose`: unsets
  `$worker->userConnections[$connection->userId]` whenever the closing
  connection had a bound `userId`, after `ReconnectService::handleDisconnect()`
  runs. This does not interfere with the intended reconnect path (ADR-001
  ┬з5-6): `reconnect` never depended on `userConnections` still being
  occupied тАФ it works off `$worker->sessionTokens` plus a session_token
  match against room player state, both independent of this map. The
  only behavioral change is that a user who disconnects can now also
  fall back to a fresh `login` instead of being force-funneled through
  `reconnect` тАФ which was previously not just "not preferred" but
  completely broken for any player outside a room.
- Regression guard preserved on purpose: `onClose` only fires on an
  actual connection close, so ADR-001's core guarantee тАФ rejecting a
  *concurrent* second login while the first connection is still open тАФ
  is untouched. Verified explicitly (TEST 3 below).

Verified non-false-positive (each half of the fix independently):
- Reverted only the `onClose` change тЖТ `tests/Manual/test_session_lifecycle.php`
  TEST 1 failed exactly as predicted (login still permanently blocked);
  TEST 2/3 unaffected. Restored тЖТ 6/6 again.
- Reverted only the `AuthHandler::handleReconnect()` change тЖТ TEST 2
  failed exactly as predicted (create_room after reconnect-only still
  `error.auth_required`); TEST 1/3 unaffected. Restored тЖТ 6/6 again.

Result:
- tests/Manual/test_session_lifecycle.php (new): 6/6 PASSED тАФ real WS
  client against a live server.php subprocess, no MockConnection. Covers:
  disconnect-then-login (no room), disconnect-then-reconnect-only (no
  room, no login fallback), and a regression guard confirming concurrent
  double-login is still rejected while the original connection stays
  open.
- tests/Manual/test_single_session.php: unchanged, still 3/3 scenarios
  PASSED (Phase-1-era unit test against AuthService in isolation; left
  as-is since it tests a real contract, just not the one this FIX closes
  тАФ no false claims to correct here, unlike the EPIC-10.5 test_auth_packet_routing.php
  fix).
- Full regression across every tests/Manual/*.php file (29 files,
  including the new one) тАФ 0 failed.

No ADR required тАФ no protocol packet, error code, room/player structure
key, or timer changed. `error.auth_invalid_token`/`error.auth_invalid_credentials`
are pre-existing codes, used exactly as already documented.

Diff: patches/FIX-10-server.patch, patches/FIX-10-AuthHandler.patch,
patches/FIX-10-AuthService.patch, patches/FIX-10-PreparedStatements.patch

## EPIC-10.5 тАФ Game packet routing (+ FIX-9, found during wiring)
Status: Completed
Date: 2026-07-23

Files:
- src/Game/GameHandler.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ thin wrapper ╨╜╨░╨┤ GameService,
  ╤В╨╛╤В ╨╢╨╡ ╨┐╨░╤В╤В╨╡╤А╨╜ ╤З╤В╨╛ LobbyHandler/AuthHandler)
- server.php (diff тАФ LottoEngine/VictoryService/ApartmentService/
  GameFinishService/GameService/GameHandler dependency wiring in
  onWorkerStart, ╨╕╨┤╨╡╨╜╤В╨╕╤З╨╜╤Л╨╣ ╨┐╨╛╤А╤П╨┤╨╛╨║ ╨║╨╛╨╜╤Б╤В╤А╤Г╨║╤В╨╛╤А╨░ ╤Г╨╢╨╡ ╨┐╤А╨╕╨╜╤П╤В╨╛╨╝╤Г ╨▓
  tests/Manual/test_game_start.php; start_game/draw_barrel/
  apartment_choice wired in onMessage dispatch; ReconnectService ╤В╨╡╨┐╨╡╤А╤М
  ╤В╨╛╨╢╨╡ ╤Б╨╛╨▒╤А╨░╨╜ тАФ ╨╛╨▒╨░ ╨╡╨│╨╛ ╨╖╨░╨▓╨╕╤Б╨╕╨╝╤Л╤Е ╤Б╨╡╤А╨▓╨╕╤Б╨░, LobbyService (EPIC-10.4) ╨╕
  GameService (╤Н╤В╨╛╤В Epic), ╨╜╨░╨║╨╛╨╜╨╡╤Ж ╨│╨╛╤В╨╛╨▓╤Л ╨╛╨┤╨╜╨╛╨▓╤А╨╡╨╝╨╡╨╜╨╜╨╛; onClose ╨┤╨╡╨╗╨╡╨│╨╕╤А╤Г╨╡╤В
  ReconnectService::handleDisconnect(); action 'reconnect' ╨┤╨╛╨┐╨╛╨╗╨╜╨╕╤В╨╡╨╗╤М╨╜╨╛
  ╨▓╤Л╨╖╤Л╨▓╨░╨╡╤В ReconnectService::handleReconnect() ╨┐╨╛╤Б╨╗╨╡ AuthHandler ╨┤╨╗╤П
  ╨▓╨╛╤Б╤Б╤В╨░╨╜╨╛╨▓╨╗╨╡╨╜╨╕╤П ╨╕╨│╤А╨╛╨▓╨╛╨│╨╛ ╤Б╨╛╤Б╤В╨╛╤П╨╜╨╕╤П/reconnect_state)
- src/Game/ReconnectService.php (diff тАФ FIX-9, ╤Б╨╝. ╨╜╨╕╨╢╨╡)
- tests/Manual/test_reconnect.php (diff тАФ GROUP 3 assertions ╨╛╨▒╨╜╨╛╨▓╨╗╨╡╨╜╤Л ╨┐╨╛╨┤
  FIX-9: ╨╖╨░╨┐╨╕╤Б╤М ╨┐╨╡╤А╨╡╨╡╨╖╨╢╨░╨╡╤В ╨╜╨░ ╨╜╨╛╨▓╤Л╨╣ conn_id, ╨░ ╨╜╨╡ ╨╛╤Б╤В╨░╤С╤В╤Б╤П ╨╜╨░ ╤Б╤В╨░╤А╨╛╨╝; +3
  ╨╜╨╛╨▓╤Л╤Е assertion ╨╜╨░ host_conn_id/active_drawer_conn_id/drawer_order)
- tests/Manual/test_game_packet_routing.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ 21 assertions,
  real WS client against live server.php, `e105_` username prefix)

GameService/VictoryService/ApartmentService/GameFinishService already
existed (Phase 4-7) and required no new business logic for the packet-
routing part itself тАФ matching every other EPIC-10.x so far, this is pure
dependency wiring + routing. The one router-level addition is in
GameHandler::handleApartmentChoice(): validates that `choice` is a
non-empty string before delegating (error.invalid_json otherwise) тАФ
GameService/ApartmentService already validate the actual value
('agree'/'refuse') internally.

Reconnect wiring was deliberately bundled into this Epic rather than left
pending further, because ReconnectService's constructor is the literal
reason onClose and 'reconnect' were stubbed out since EPIC-10.0 тАФ both of
its dependencies (LobbyService, GameService) are only both available as of
this Epic. This is not a new/separate feature so much as completing what
EPIC-10.0's own code comments already earmarked for "EPIC-10.4/10.5".

Found and fixed during wiring (FIX-9, see PATCHES-style note below тАФ
kept inline here since it's this Epic's direct blocker, not a standalone
older-code audit finding):
- ReconnectService::handleReconnect() restored player state and sent
  reconnect_state, but left the `$room['players']` array entry keyed
  under the OLD (disconnected) connection id. A new WS connection created
  by the client on reconnect gets a brand-new Workerman connection->id тАФ
  every downstream handler (draw_barrel, leave_room, apartment_choice, ...)
  looks the player up by the CURRENT connection's id, so none of them
  could find the reconnected player. Reconnect looked successful
  (reconnect_state packet received, status flipped to 'active') but was
  functionally dead for anything after it.
- Root cause of why this was never caught: tests/Manual/test_reconnect.php
  (EPIC-8.6) only unit-tests handleReconnect() in isolation with
  MockConnection and asserts state at the OLD key тАФ it never simulates a
  subsequent action arriving from the NEW connection through real routing,
  because until this Epic there was no real routing to go through.
- Fix: handleReconnect() now re-keys the players array entry from the old
  conn_id to the new one, and updates every other room-level field that
  can point at a conn_id: `host_conn_id`, `active_drawer_conn_id`, and
  every matching entry in `drawer_order`. Timer, connection object, and
  session_token handling unchanged.
- Verified non-false-positive: reverted the fix locally, re-ran
  tests/Manual/test_game_packet_routing.php TEST 8 тАФ draw_barrel after
  reconnect failed with error.room_not_found as predicted (new conn_id not
  found in `$room['players']`); restored the fix тАФ 21/21 PASSED again.

No ADR required тАФ no protocol packet, error code, or ANCHOR document
changed. Room/Player structure keys are unchanged (Rule 7 No Hidden
Features) тАФ FIX-9 only changes which array key an existing structure is
stored under, at the moment of reconnect.

Also fixed in this Epic (stale pre-existing test assertion, not a FIX-N тАФ
Rule 22 Test Philosophy: fix the test, not the implementation, since the
implementation was already correct): tests/Manual/test_auth_packet_routing.php
TEST 2 still asserted `error.invalid_json` for create_room after register,
a leftover from before EPIC-10.4 wired lobby routing тАФ despite this
project's own IMPLEMENTATION_STATUS.md EPIC-10.4 entry already claiming
this assertion was updated. It had not been, in the actual committed file.
Corrected to assert `room_joined`.

Housekeeping (found during this Epic's audit, unrelated to game routing
itself): the repository had two case-variant test directories,
`tests/Manual/` and `tests/manual/`, byte-identical except that
`tests/manual/test_lobby_packet_routing.php` (EPIC-10.4) existed only in
the lowercase copy тАФ almost certainly a case-insensitive-filesystem
artifact from a local dev machine, invisible on that machine but tracked
as two separate directories in git. Consequence: `run_ALL_tests.sh` (globs
`tests/Manual/test_*.php` only) was silently never running
test_lobby_packet_routing.php at all. Fixed: file moved into
`tests/Manual/` (confirmed identical before the move, `php -l` clean,
re-run 23/23 PASSED post-move), the stray `tests/manual/` directory
removed entirely.

Result:
- tests/Manual/test_game_packet_routing.php (new): 21/21 PASSED тАФ full
  flow verified end-to-end through a real WS client against a live
  server.php subprocess: non-host start_game guard, game_started
  broadcast (bank/drawer_order), turn-order draw_barrel guard,
  barrels_drawn + your_turn rotation, apartment_choice with no apartment
  active, apartment_choice missing `choice` field, unauth draw_barrel,
  and тАФ critically тАФ a real TCP disconnect mid-game followed by
  reconnect on a brand-new connection, then a successful draw_barrel from
  that new connection (this last step is the FIX-9 regression check).
- tests/Manual/test_reconnect.php: 20/20 PASSED (was 15 тАФ +5 new FIX-9
  assertions in GROUP 3).
- tests/Manual/test_auth_packet_routing.php: 18/18 PASSED (TEST 2 fixed).
- tests/Manual/test_lobby_packet_routing.php: 23/23 PASSED (moved,
  unchanged otherwise).
- Full regression across every tests/Manual/*.php file тАФ 0 failed.

тЬЕ RESOLVED (FIX-10, 2026-07-24): if a client sends `{"action":
"reconnect", "token": ...}` with a token AuthHandler considers valid, but
ReconnectService::handleReconnect() finds no matching disconnected player
in any room (i.e. the user was never in a room-level session, or it was
already cleaned up), `$connection->userId` is never set тАФ AuthHandler::
handleReconnect() itself never sets it, only ReconnectService does, only
on a match. Symmetric in spirit to FIX-8 (EPIC-10.3) but a distinct fix,
deliberately left for a follow-up rather than folded into this Epic.
Turned out to be far more severe than "narrow" once actually audited тАФ
see FIX-10: AuthHandler::handleReconnect() now unconditionally binds the
connection via bindConnection() once the token/user is validated,
regardless of room membership.

Diff: patches/EPIC-10.5-game-routing.patch

## EPIC-10.4 тАФ Lobby packet routing
Status: Completed
Date: 2026-07-23

Files:
- src/Lobby/LobbyHandler.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ thin wrapper ╨╜╨░╨┤ LobbyService)
- server.php (diff тАФ RoomManager/LobbyService/LobbyHandler dependency wiring
  in onWorkerStart; room_list/create_room/join_room/leave_room wired in
  onMessage dispatch; ┬лAlready in a room┬╗ guard for create_room/join_room)
- tests/Manual/test_lobby_packet_routing.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ 22 assertions,
  real WS client against live server.php)
- tests/Manual/test_auth_packet_routing.php (diff тАФ TEST 2 updated: ╨┐╨╛╤Б╨╗╨╡
  register create_room ╤В╨╡╨┐╨╡╤А╤М ╨▓╨╛╨╖╨▓╤А╨░╤Й╨░╨╡╤В room_joined, ╨╜╨╡ error.invalid_json)

LobbyService already existed (EPIC-2.x) and required no new business
logic тАФ EPIC-10.4 itself is pure dependency wiring + routing + one router-
level guard, matching every other EPIC-10.x so far.

┬лAlready in a room┬╗ guard: LobbyService::handleCreateRoom() ╨┤╨╛╨║╤Г╨╝╨╡╨╜╤В╨╕╤А╤Г╨╡╤В,
╤З╤В╨╛ ╨┐╨╛╨╗╤М╨╖╨╛╨▓╨░╤В╨╡╨╗╤М ╨╜╨╡ ╨┤╨╛╨╗╨╢╨╡╨╜ ╤Г╨╢╨╡ ╨╜╨░╤Е╨╛╨┤╨╕╤В╤М╤Б╤П ╨▓ ╨┤╤А╤Г╨│╨╛╨╣ ╨║╨╛╨╝╨╜╨░╤В╨╡ тАФ ╨┐╤А╨╛╨▓╨╡╤А╨║╨░
╨┤╨╡╨╗╨╡╨│╨╕╤А╨╛╨▓╨░╨╜╨░ router'╤Г (server.php), ╨╛╨┤╨╕╨╜ ╤А╨░╨╖ ╨┤╨╗╤П create_room ╨╕ join_room,
╤З╨╡╤А╨╡╨╖ RoomManager::findRoomIdByConnId(). ╨Ъ╨╛╨┤ ╨╛╤И╨╕╨▒╨║╨╕: error.invalid_json
(╨╛╤В╨┤╨╡╨╗╤М╨╜╨╛╨│╨╛ ╨║╨╛╨┤╨░ ╨▓ ANCHOR_PROTOCOL.md ╨╜╨╡╤В).

No ADR required тАФ no protocol packet, error code, or ANCHOR document changed.

Result:
- tests/Manual/test_lobby_packet_routing.php (new): 22/22 PASSED тАФ
  create_room/room_list/join_room/leave_room verified end-to-end through
  a real WS client against a live server.php subprocess (real game.db,
  `e104_` username prefix, cleaned up before/after). Includes router-level
  ┬лAlready in a room┬╗ guard checks (TEST 4, TEST 5).
- tests/Manual/test_auth_packet_routing.php: TEST 2 updated for EPIC-10.4
  (create_room after register тЖТ room_joined).
- tests/Manual/test_lobby_integration.php: 91/91 PASSED (unchanged).
- Full regression across all tests/Manual/*.php files тАФ 0 failed.

Diff: patches/EPIC-10.4-lobby-routing.patch

## EPIC-10.3 тАФ Auth packet routing (+ FIX-8, found during wiring)
Status: Completed
Date: 2026-07-22

Files:
- server.php (diff тАФ AuthHandler dependency wiring in onWorkerStart;
  register/login/reconnect wired to AuthHandler in onMessage dispatch)
- src/Auth/AuthHandler.php (diff тАФ FIX-8: new bindConnection() private
  helper, called from handleRegister()/handleLogin())
- tests/Manual/test_auth_integration.php (diff тАФ 7 new FIX-8 assertions
  via MockConnection)
- tests/Manual/test_auth_packet_routing.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗ тАФ 18 assertions,
  real WS client against live server.php)

AuthHandler already existed (EPIC-1.3) and required no new business
logic тАФ EPIC-10.3 itself is pure dependency wiring + routing, matching
every other EPIC-10.x so far.

FIX-8 found while wiring (not a pre-existing regression тАФ the bug was
latent until this Epic connected AuthHandler to the newly-added
auth_required guard, ADR-006, in the same code path): `AuthService::
login()` only ever set `$worker->userConnections[$userId]` тАФ it never
set `$connection->userId` itself. Confirmed by grep: the ONLY place in
the entire codebase that set `$connection->userId` was
`ReconnectService::attemptReconnect()`, for its own, unrelated scenario.
Without a fix, a client could register/login successfully, receive a
valid `auth_result`, and then have EVERY subsequent action rejected with
`error.auth_required` forever тАФ the auth_required guard checks exactly
`$connection->userId === null`, which never became false.

Fix: new `AuthHandler::bindConnection(object $connection, array $user,
string $token): void` private helper, mirroring the exact field set
`ReconnectService` already uses for its own scenario (`$connection->
userId`, `->username`, `->sessionToken`) plus `->isAdmin` (available in
AuthHandler's login result, unlike in ReconnectService's context). Called
from both `handleRegister()` (after its internal auto-login) and
`handleLogin()`, right before `sendAuthResult()`.

No ADR required тАФ this is a code-correctness fix within the existing,
already-documented `ANCHOR_CORE.md` ┬з Connection Runtime Fields registry
(all four fields were already declared there); no protocol packet, error
code, or ANCHOR document changed.

Result:
- tests/Manual/test_auth_integration.php: 55/55 PASSED (was 48; +7 тАФ
  FIX-8 assertions verifying `$connection->userId/username/isAdmin/
  sessionToken` are correctly bound after both handleRegister() and
  handleLogin() via MockConnection).
- tests/Manual/test_auth_packet_routing.php (new): 18/18 PASSED тАФ
  register/login/reconnect verified end-to-end through a real WS client
  against a live server.php subprocess (real game.db, `e103_` username
  prefix, cleaned up before/after). Critically includes two FIX-8
  end-to-end checks (TEST 2, TEST 6): after a real register/login over
  the real protocol, a subsequent non-exempt action no longer receives
  `error.auth_required` тАФ confirming the fix works through the actual
  router, not only in the MockConnection unit test.
- Full regression across all tests/Manual/*.php files (28 files,
  including the new one) тАФ 0 failed ([FAIL] marker searched explicitly).

Diff: patches/EPIC-10.3-auth-routing.patch

## EPIC-10.2 continuation тАФ Generic auth_required guard
Status: Completed
Date: 2026-07-22

Files:
- server.php (diff тАФ auth_required guard in onMessage, before dispatch)
- docs/ANCHOR_PROTOCOL.md (diff тАФ error.auth_required semantics documented)
- docs/ADR/006.md (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗)
- tests/Manual/test_server_bootstrap.php (diff тАФ TEST 4 tightened to
  assert the specific code; new TEST 8 for the exempt-actions set)

Closes the second, previously-deferred half of EPIC-10.2 (first half тАФ
connection-level MAX_TOTAL_PLAYERS gate тАФ completed separately, ADR-005).
EPIC-10.2 is now fully complete.

Implements prompt.md ╨д╨░╨╖╨░ 1: "╨┐╤А╨╛╨▓╨╡╤А╨║╨░ userId ╨┤╨╗╤П ╨▓╤Б╨╡╤Е ╨║╨╡╨╣╤Б╨╛╨▓ ╨║╤А╨╛╨╝╨╡
register, login, reconnect" тАФ checked once, generically, by the router
in onMessage, before the (still empty) action dispatcher. Exempt set is
exactly {register, login, reconnect}; `ping` isn't listed because it
already short-circuits earlier in onMessage and never reaches this
check.

Side effect verified explicitly (not a defect, documented in ADR-006):
the dispatcher's `default => error.invalid_json` fallback is now
unreachable for an unauthenticated connection sending any non-exempt
action тАФ the guard intercepts first with error.auth_required. Remains
reachable only for the exempt actions themselves (not yet wired to real
handlers until EPIC-10.3).

Result:
- tests/Manual/test_server_bootstrap.php: 18/18 PASSED (was 14; +4 тАФ TEST
  4 tightened to assert code=error.auth_required specifically instead of
  just type=error; new TEST 8 confirms register/login/reconnect are NOT
  blocked by the guard, falling through to the empty dispatcher's
  not-yet-wired response instead).
- Full regression across all tests/Manual/*.php files (25 files) тАФ 0
  failed ([FAIL] marker searched explicitly, not just "failed" text
  appearing in unrelated log messages).

Diff: patches/EPIC-10.2-auth-guard.patch

## EPIC-10.2 тАФ Protocol error handling (partial: connection-level capacity gate)
Status: Partially completed (by user decision тАФ scope explicitly narrowed)
Date: 2026-07-22

Files:
- src/Core/Helpers.php (diff тАФ new closeWithCode() helper)
- server.php (diff тАФ global connection-level MAX_TOTAL_PLAYERS gate in
  onWebSocketConnected, before hello)
- docs/ANCHOR_PROTOCOL.md (diff тАФ new ┬з WebSocket Close Codes, code 4001)
- docs/ADR/005.md (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗)
- tests/Manual/test_server_bootstrap.php (diff тАФ TEST 7: 150 ╤А╨╡╨░╨╗╤М╨╜╤Л╤Е
  TCP+WS ╤Б╨╛╨╡╨┤╨╕╨╜╨╡╨╜╨╕╨╣ + 151-╨╡ ╨╛╤В╨║╨╗╨╛╨╜╤С╨╜╨╜╨╛╨╡, ╨┐╤А╨╛╨▓╨╡╤А╨║╨░ close code 4001)

Scope decision: user chose to implement ONLY the connection-level
`error.server_full` + WS close 4001 gate (prompt.md ╨д╨░╨╖╨░ 1, previously
undocumented in any ANCHOR file) in this round. The generic
`auth_required` router guard (also prompt.md ╨д╨░╨╖╨░ 1, for actions outside
{register, login, reconnect, ping}) was explicitly deferred тАФ not
implemented, tracked as open for a future round.

Problem: `docs/prompt.md` line 41 specified "╨┐╤А╨╕ ╨┐╤А╨╡╨▓╤Л╤И╨╡╨╜╨╕╨╕ 150 тАФ
╨╖╨░╨║╤А╤Л╤В╤М ╤Б╨╛╨╡╨┤╨╕╨╜╨╡╨╜╨╕╨╡ ╤Б ╨║╨╛╨┤╨╛╨╝ 4001 ╨╕ error.server_full", never formalized
in ANCHOR_PROTOCOL.md and never implemented. Distinct from the
room-join-time capacity check in LobbyService (FIX-7/ADR-004) тАФ this one
runs at the connection layer, before authentication, against ALL live
sockets (`count($worker->connections)`), not just players seated in
rooms.

Technical finding: the installed Workerman version has no built-in API
to close a WebSocket connection with an explicit close-frame status
code тАФ `closeWithCode()` builds the RFC 6455 ┬з5.5.1 close frame by hand
(opcode 0x8, 2-byte big-endian status code + reason) and sends it via
`$connection->close($frame, true)`.

Fix:
- `closeWithCode()` helper added to Core/Helpers.php (general-purpose,
  reusable for any future application-specific close code).
- Gate added at the top of `onWebSocketConnected`: if
  `count($worker->connections) > Constants::MAX_TOTAL_PLAYERS`, sends
  `error.server_full` (JSON, normal protocol-encoded) then closes with
  WS code 4001 тАФ before any connection-field init, before `hello`.
- Comparison uses `>` (not `>=`, unlike LobbyService's checks) because
  Workerman registers the connection into `$worker->connections` at
  TCP-accept time, before this callback runs тАФ so the count already
  includes the connection being evaluated. Effective capacity is
  identical either way: exactly MAX_TOTAL_PLAYERS concurrent connections
  allowed, the (N+1)-th rejected. Documented explicitly in ADR-005 to
  avoid the kind of silent inconsistency FIX-7 had to fix.
- New WS close-code registry section added to ANCHOR_PROTOCOL.md so
  future application-specific codes have a documented home.

Result:
- tests/Manual/test_server_bootstrap.php: 14/14 PASSED (was 8; +6 new
  checks in TEST 7 тАФ opened exactly 150 real TCP+WS connections against
  a live server.php subprocess, verified the 151st receives
  error.server_full as a text frame followed by a close frame carrying
  status code 4001, decoded from the raw close-frame payload).
- Full regression across all tests/Manual/*.php files (25 files) тАФ 0
  failed.

Diff: patches/EPIC-10.2-partial.patch

### FIX-7 тАФ `error.server_full` reused for room-full condition + wrong check order
Status: Completed
Date: 2026-07-22

Files:
- src/Lobby/LobbyService.php (diff тАФ reorder checks in handleJoinRoom(),
  new error.room_full code)
- docs/ANCHOR_PROTOCOL.md (diff тАФ error.room_full added to registry,
  note distinguishing it from error.server_full and documenting
  join-order precedence)
- docs/ADR/004.md (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗)
- tests/Manual/test_lobby_integration.php (diff тАФ ╨╛╨▒╨╜╨╛╨▓╨╗╨╡╨╜╨░ ╨░╤Б╤Б╨╡╤А╤Ж╨╕╤П ╨┐╨╛╨┤
  ╨╜╨╛╨▓╤Л╨╣ ╨║╨╛╨┤, ╨┤╨╛╨▒╨░╨▓╨╗╨╡╨╜ regression-╤В╨╡╤Б╤В ╨╜╨░ ╨┐╨╛╤А╤П╨┤╨╛╨║ ╨┐╤А╨╛╨▓╨╡╤А╨╛╨║)

Found during: user-reported review (not an audit round) тАФ user flagged
that a full room and a full server must not share an error code, and
that server capacity must be checked before room capacity.

Problem:
- LobbyService::handleJoinRoom() reused `error.server_full` for two
  distinct conditions: the genuine global MAX_TOTAL_PLAYERS limit, and a
  single room reaching its own max_players. ANCHOR_PROTOCOL.md had no
  dedicated code for the room-full case.
- Check order was room-capacity-first, server-capacity-second тАФ so if
  both conditions were true simultaneously, the client would receive the
  less accurate/less actionable of the two.

Fix:
- New protocol error code `error.room_full`, reserved exclusively for a
  single room being at its own max_players. `error.server_full` now
  reserved exclusively for the global MAX_TOTAL_PLAYERS limit.
- Check order in handleJoinRoom() swapped: global server-capacity check
  now runs BEFORE the per-room capacity check, so error.server_full
  always wins when both apply.
- handleCreateRoom() required no change (only ever had the global check).
- Formalized as ADR-004 (protocol addition, no rename/removal тАФ permitted
  under ANCHOR_PROTOCOL.md's Compatibility Rule without a version bump).

Result:
- tests/Manual/test_lobby_integration.php: 91/91 PASSED (was 90; +1 new
  regression test verifying error.server_full wins when both room and
  server are full simultaneously тАФ verified by manually seeding both
  conditions via direct room-state manipulation and RoomManager::
  getTotalPlayerCount()).
- Full regression across all tests/Manual/*.php files тАФ 0 failed.

Diff: patches/FIX-7.patch

### FIX-6 тАФ Reconnect timer leak on kick/ban removal (Timer Integrity)
Status: Completed
Date: 2026-07-03

Files:
- src/Lobby/LobbyService.php
- src/Game/ApartmentService.php
- tests/Manual/test_timer_integrity.php (╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗)

Found during: post-Phase-9 audit for bugs similar in class to FIX-3
(╨╖╨░╨┐╤А╨╛╤И╨╡╨╜ ╨┐╨╛╨╗╤М╨╖╨╛╨▓╨░╤В╨╡╨╗╨╡╨╝ ╨┐╨╡╤А╨╡╨┤ ╤Б╤В╨░╤А╤В╨╛╨╝ Phase 10).

Problem:
- ANCHOR_CORE.md Part 5 ┬з Timer Integrity Rules: "No reconnect timer
  survives player removal" / "A destroyed owner keeps no timers" тАФ
  ╨▒╨╡╨╖╤Г╤Б╨╗╨╛╨▓╨╜╨╛╨╡ ╨┐╤А╨░╨▓╨╕╨╗╨╛.
- ReconnectService::removePlayerFromGame() ╨║╨╛╤А╤А╨╡╨║╤В╨╜╨╛ ╨╛╤В╨╝╨╡╨╜╤П╨╡╤В
  player['reconnect_timer'] ╨Я╨Х╨а╨Х╨Ф ╤Г╨┤╨░╨╗╨╡╨╜╨╕╨╡╨╝ ╨╕╨│╤А╨╛╨║╨░.
- LobbyService::removePlayerFromLobby() ╨╕ ApartmentService::
  removePlayerFromApartment() тАФ ╨Э╨Х ╨╛╤В╨╝╨╡╨╜╤П╨╗╨╕, ╨░╤Б╨╕╨╝╨╝╨╡╤В╤А╨╕╤П ╨╝╨╡╨╢╨┤╤Г ╤В╤А╨╡╨╝╤П
  "╤Б╤С╤Б╤В╤А╨╕╨╜╤Б╨║╨╕╨╝╨╕" ╨╝╨╡╤В╨╛╨┤╨░╨╝╨╕ ╤Г╨┤╨░╨╗╨╡╨╜╨╕╤П ╨╕╨│╤А╨╛╨║╨░.
- ╨Ф╨╛╤Б╤В╨╕╨╢╨╕╨╝╨╛╤Б╤В╤М (╤А╨╡╨░╨╗╤М╨╜╤Л╨╣ ╤Б╤Ж╨╡╨╜╨░╤А╨╕╨╣, ╨╜╨╡ ╨│╨╕╨┐╨╛╤В╨╡╤В╨╕╤З╨╡╤Б╨║╨╕╨╣): disconnected-╨╕╨│╤А╨╛╨║
  ╨▓ waiting-╨║╨╛╨╝╨╜╨░╤В╨╡ ╨╕╨╝╨╡╨╡╤В ╨░╨║╤В╨╕╨▓╨╜╤Л╨╣ 15s reconnect_timer (ANCHOR_CORE ┬з
  Reconnect Timer). ╨Х╤Б╨╗╨╕ ╨░╨┤╨╝╨╕╨╜╨╕╤Б╤В╤А╨░╤В╨╛╤А ╨║╨╕╨║╨░╨╡╤В/╨▒╨░╨╜╨╕╤В ╨╡╨│╨╛ ╨┤╨╛ ╨╕╤Б╤В╨╡╤З╨╡╨╜╨╕╤П
  ╤В╨░╨╣╨╝╨╡╤А╨░, removePlayerFromLobby() ╤Г╨┤╨░╨╗╤П╨╡╤В ╨╕╨│╤А╨╛╨║╨░, ╨╜╨╛ ╤В╨░╨╣╨╝╨╡╤А ╨╛╤Б╤В╨░╤С╤В╤Б╤П
  ╨╖╨░╤А╨╡╨│╨╕╤Б╤В╤А╨╕╤А╨╛╨▓╨░╨╜╨╜╤Л╨╝ ╨▓ Workerman. RoomManager::generateRoomId()
  ╨┐╨╡╤А╨╡╨╕╤Б╨┐╨╛╨╗╤М╨╖╤Г╨╡╤В ╨Я╨Х╨а╨Т╨л╨Щ ╤Б╨▓╨╛╨▒╨╛╨┤╨╜╤Л╨╣ room_id ╤Б╤А╨░╨╖╤Г ╨┐╨╛╤Б╨╗╨╡ ╤Г╨╜╨╕╤З╤В╨╛╨╢╨╡╨╜╨╕╤П ╨║╨╛╨╝╨╜╨░╤В╤Л
  (MAX_ROOMS=30) тАФ ╤В╨╛ ╨╡╤Б╤В╤М ╤Н╤В╨╛ ╨╜╨╡ ╨┐╤А╨╛╤Б╤В╨╛ ╤Г╤В╨╡╤З╨║╨░ ╨┐╨░╨╝╤П╤В╨╕ ╨╜╨░ 15 ╤Б╨╡╨║╤Г╨╜╨┤, ╨░
  ╨╜╨░╤А╤Г╤И╨╡╨╜╨╕╨╡ ╨╕╨╜╨▓╨░╤А╨╕╨░╨╜╤В╨░ ╨╜╨░ ╨░╨║╤В╨╕╨▓╨╜╨╛ ╨┐╨╡╤А╨╡╨╕╤Б╨┐╨╛╨╗╤М╨╖╤Г╨╡╨╝╨╛╨╝ ╤А╨╡╤Б╤Г╤А╤Б╨╡ (Rule 28 VPS
  Awareness: 1 CPU/500MB RAM).
- removePlayerFromApartment(): ╤В╨╛╤В ╨╢╨╡ ╨┐╤А╨╛╨▒╨╡╨╗, ╨╜╨╛ ╨┐╨╛ state machine
  (ANCHOR_CORE ┬з Reconnect Rules: reconnect ╨╖╨░╨┐╤А╨╡╤Й╤С╨╜ ╨▓ apartment) ╨▓
  ╨╜╨╛╤А╨╝╨╡ ╨╜╨╡╨┤╨╛╤Б╤В╨╕╨╢╨╕╨╝ тАФ ╨╕╤Б╨┐╤А╨░╨▓╨╗╨╡╨╜╨╛ ╨╖╨░╤Й╨╕╤В╨╜╨╛, ╤В.╨║. ╨┐╤А╨░╨▓╨╕╨╗╨╛ ╨▒╨╡╨╖╤Г╤Б╨╗╨╛╨▓╨╜╨╛╨╡.

Fix:
- Timer::del($player['reconnect_timer']) ╨┤╨╛╨▒╨░╨▓╨╗╨╡╨╜ ╨▓ ╨╛╨▒╨░ ╨╝╨╡╤В╨╛╨┤╨░ ╨Ф╨Ю
  ╤Г╨┤╨░╨╗╨╡╨╜╨╕╤П ╨╕╨│╤А╨╛╨║╨░ тАФ ╨╕╨┤╨╡╨╜╤В╨╕╤З╨╜╤Л╨╣ ╤Г╨╢╨╡ ╨║╨╛╤А╤А╨╡╨║╤В╨╜╨╛╨╝╤Г ╨┐╨░╤В╤В╨╡╤А╨╜╤Г ╨▓
  ReconnectService::removePlayerFromGame().

Result:
- tests/Manual/test_timer_integrity.php: 5/5 PASSED.
- Regression ╨┐╤А╨╛╨▓╨╡╤А╨╡╨╜ ╨╜╨░ ╨╗╨╛╨╢╨╜╨╛╨┐╨╛╨╗╨╛╨╢╨╕╤В╨╡╨╗╤М╨╜╨╛╤Б╤В╤М: ╨▓╤А╨╡╨╝╨╡╨╜╨╜╨╛ ╨╛╤В╨║╨░╤В╤Л╨▓╨░╨╗╨╕╤Б╤М ╨╛╨▒╨╡
  ╨┐╤А╨░╨▓╨║╨╕ тЖТ 3/5 ╤З╨╡╤Б╤В╨╜╤Л╤Е FAIL; ╨┐╨╛╤Б╨╗╨╡ ╨▓╨╛╤Б╤Б╤В╨░╨╜╨╛╨▓╨╗╨╡╨╜╨╕╤П тАФ ╤Б╨╜╨╛╨▓╨░ 5/5.
- ╨Я╨╛╨╗╨╜╤Л╨╣ ╤А╨╡╨│╤А╨╡╤Б╤Б ╨┐╨╛ ╨▓╤Б╨╡╨╝ 23 ╤Д╨░╨╣╨╗╨░╨╝ tests/Manual/*.php тАФ 0 failed.

Diff: patches/FIX-6.patch

### FIX-4 тАФ Stale test fixtures after ADR-002 (GameFinishService)
Status: Completed
Date: 2026-07-03

Files:
- src/Infrastructure/Database.php
- tests/Manual/test_game_start.php
- tests/Manual/test_victory.php

Problem:
- ADR-002 (╨▓╤Л╨╜╨╛╤Б GameFinishService, final class ╤Б╨╛ ╤Б╤В╤А╨╛╨│╨╛╨╣ ╤В╨╕╨┐╨╕╨╖╨░╤Ж╨╕╨╡╨╣
  Database/PreparedStatements/Logger) ╨╜╨╡ ╨▒╤Л╨╗ ╨┐╤А╨╛╨▒╤А╨░╤Б╤С╨╜ ╨▓ ╤В╨╡╤Б╤В╨╛╨▓╤Л╨╡ ╤Д╨╕╨║╤Б╤В╤Г╤А╤Л
  test_game_start.php ╨╕ test_victory.php тАФ ╨╛╨▒╨╡ ╨┐╤А╨╛╨┤╨╛╨╗╨╢╨░╨╗╨╕ ╨╕╤Б╨┐╨╛╨╗╤М╨╖╨╛╨▓╨░╤В╤М
  ╨░╨╜╨╛╨╜╨╕╨╝╨╜╤Л╨╡ ╨║╨╗╨░╤Б╤Б╤Л ╨▓╨╝╨╡╤Б╤В╨╛ GameFinishService, ╤З╤В╨╛ ╨╜╨╡╤Б╨╛╨▓╨╝╨╡╤Б╤В╨╕╨╝╨╛ ╨┐╨╛ ╤В╨╕╨┐╤Г ╤Б
  GameService::__construct(). ╨Ю╨▒╨░ ╤Д╨░╨╣╨╗╨░ ╨┐╨░╨┤╨░╨╗╨╕ ╤Б Fatal TypeError.
- ╨Ъ╨╛╤А╨╜╨╡╨▓╨░╤П ╨┐╤А╨╕╤З╨╕╨╜╨░ ╨╜╨╡╨▓╨╛╨╖╨╝╨╛╨╢╨╜╨╛╤Б╤В╨╕ ╤З╨╡╤Б╤В╨╜╨╛╨│╨╛ (╨▒╨╡╨╖ reflection тАФ ╨╖╨░╨┐╤А╨╡╤Й╤С╨╜╨╜╨╛╨│╨╛
  ANCHOR_RULES.md Part 22) ╨╕╤Б╨┐╤А╨░╨▓╨╗╨╡╨╜╨╕╤П: Database ╨╢╤С╤Б╤В╨║╨╛ ╤Е╨░╤А╨┤╨║╨╛╨┤╨╕╨╗╨░ ╨┐╤Г╤В╤М ╨║
  game.db ╨▓ ╨║╨╛╨╜╤Б╤В╤А╤Г╨║╤В╨╛╤А╨╡ ╨▒╨╡╨╖ ╤В╨╛╤З╨║╨╕ ╨▓╨╜╨╡╨┤╤А╨╡╨╜╨╕╤П ╨╖╨░╨▓╨╕╤Б╨╕╨╝╨╛╤Б╤В╨╡╨╣.

Fix:
- Database::__construct() ╤А╨░╤Б╤И╨╕╤А╨╡╨╜ ╨╛╨┐╤Ж╨╕╨╛╨╜╨░╨╗╤М╨╜╤Л╨╝ ╨┐╨░╤А╨░╨╝╨╡╤В╤А╨╛╨╝ `?PDO $pdo = null`
  (╨╛╨▒╤А╨░╤В╨╜╨╛ ╤Б╨╛╨▓╨╝╨╡╤Б╤В╨╕╨╝╨╛ тАФ ╨╜╨░ ╨╝╨╛╨╝╨╡╨╜╤В ╤Д╨╕╨║╤Б╨░ `new Database()` ╨╜╨╕╨│╨┤╨╡ ╨▓ ╨┐╤А╨╛╨╡╨║╤В╨╡ ╨╜╨╡
  ╨▓╤Л╨╖╤Л╨▓╨░╨╡╤В╤Б╤П ╨╜╨░╨┐╤А╤П╨╝╤Г╤О, server.php/init_db.php ╨╡╤Й╤С ╨╜╨╡ ╤А╨╡╨░╨╗╨╕╨╖╨╛╨▓╨░╨╜╤Л; ╨┐╨╛╨▓╨╡╨┤╨╡╨╜╨╕╨╡
  ╨▒╨╡╨╖ ╨░╤А╨│╤Г╨╝╨╡╨╜╤В╨░ ╨╕╨┤╨╡╨╜╤В╨╕╤З╨╜╨╛ ╨┐╤А╨╡╨╢╨╜╨╡╨╝╤Г).
- test_game_start.php: finishGame() ╨╜╨╡ ╨▓╤Л╨╖╤Л╨▓╨░╨╡╤В╤Б╤П ╨╜╨╕ ╨▓ ╨╛╨┤╨╜╨╛╨╝ ╤Б╤Ж╨╡╨╜╨░╤А╨╕╨╕
  EPIC-4.5 тЖТ ╨░╨╜╨╛╨╜╨╕╨╝╨╜╤Л╨╣ ╨║╨╗╨░╤Б╤Б ╨╖╨░╨╝╨╡╨╜╤С╨╜ ╨╜╨░ ╤Г╨╢╨╡ ╨┐╤А╨╕╨╜╤П╤В╤Л╨╣ ╨▓ ╨┐╤А╨╛╨╡╨║╤В╨╡ ╨┐╨░╤В╤В╨╡╤А╨╜
  ReflectionClass::newInstanceWithoutConstructor() (╤Б╨╝. test_apartment.php,
  test_turn_system.php).
- test_victory.php: GROUP 4/5/6 ╤А╨╡╨░╨╗╤М╨╜╨╛ ╨▓╤Л╨╖╤Л╨▓╨░╤О╤В finishGame() тЖТ makeSvc()
  ╤В╨╡╨┐╨╡╤А╤М ╤Б╤В╤А╨╛╨╕╤В ╨╜╨░╤Б╤В╨╛╤П╤Й╨╕╨╣ GameFinishService(Database, PreparedStatements,
  Logger) ╨┐╨╛╨▓╨╡╤А╤Е in-memory SQLite. GROUP 5 (╤Б╨▒╨╛╨╣ ╨С╨Ф тЖТ rollback) ╨┐╨╡╤А╨╡╨┐╨╕╤Б╨░╨╜ ╤Б
  ╨╕╤Б╨║╤Г╤Б╤Б╤В╨▓╨╡╨╜╨╜╨╛╨│╨╛ MockPDO->shouldFail ╤Д╨╗╨░╨│╨░ ╨╜╨░ ╤З╨╡╤Б╤В╨╜╨╛╨╡ ╨╜╨░╤А╤Г╤И╨╡╨╜╨╕╨╡ SQL
  CHECK-╨╛╨│╤А╨░╨╜╨╕╤З╨╡╨╜╨╕╤П (coins<=200) тАФ ╤В╨╡╤Б╤В╨╕╤А╤Г╨╡╤В ╤А╨╡╨░╨╗╤М╨╜╤Л╨╣ ╨┐╤Г╤В╤М ╨╛╤В╨║╨░╤В╨░ ╨▓╨╜╤Г╤В╤А╨╕
  GameFinishService, ╨░ ╨╜╨╡ ╨╕╨╝╨╕╤В╨░╤Ж╨╕╤О.

Result:
- test_game_start.php: 44/44 PASSED
- test_victory.php: 40/40 PASSED (╨▒╤Л╨╗╨╛ 38 ╨╖╨░╤П╨▓╨╗╨╡╨╜╨╛ ╨▓ ╤Б╤В╨░╤В╤Г╤Б╨╡; +2 ╨▒╨╛╨╗╨╡╨╡
  ╤Б╤В╤А╨╛╨│╨╕╨╡ ╨┐╤А╨╛╨▓╨╡╤А╨║╨╕ ╨┤╨╛╨▒╨░╨▓╨╗╨╡╨╜╤Л ╨▓ GROUP 5 тАФ inTransaction()===false,
  room ╨╜╨╡ ╤Г╨╜╨╕╤З╤В╨╛╨╢╨╡╨╜╨░ ╨┐╤А╨╕ ╨╛╤В╨║╨░╤В╨╡)
- ╨Я╨╛╨╗╨╜╤Л╨╣ ╤А╨╡╨│╤А╨╡╤Б╤Б╨╕╨╛╨╜╨╜╤Л╨╣ ╨┐╤А╨╛╨│╨╛╨╜ ╨▓╤Б╨╡╤Е 22 ╤Д╨░╨╣╨╗╨╛╨▓ tests/Manual/*.php тАФ 0 failed.

Diff: patches/FIX-4.patch

---

### FIX-5 тАФ Stale sendError() assertion (pre-FIX-1 contract)
Status: Completed
Date: 2026-07-03

Files:
- tests/Manual/test_helpers_runner.php

Problem:
- Scenario 2 ╨▓╤Л╨╖╤Л╨▓╨░╨╗╨░ sendError($conn, 'Invalid action syntax') ╨┐╨╛ ╤Б╤В╨░╤А╨╛╨╝╤Г
  ╨╛╨┤╨╜╨╛╨┐╨░╤А╨░╨╝╨╡╤В╤А╨╛╨▓╨╛╨╝╤Г ╨║╨╛╨╜╤В╤А╨░╨║╤В╤Г (╨┤╨╛ FIX-1) ╨╕ ╨╛╨╢╨╕╨┤╨░╨╗╨░ ╨┐╨░╨║╨╡╤В ╨▒╨╡╨╖ ╨┐╨╛╨╗╤П code.
  ╨а╨╡╨░╨╗╤М╨╜╤Л╨╣ sendError(object $connection, string $code, string $message = '')
  ╨┐╨╛╤Б╨╗╨╡ FIX-1 ╨║╨╛╤А╤А╨╡╨║╤В╨╜╨╛ ╤В╤А╨╡╨▒╤Г╨╡╤В code тАФ ╤В╨╡╤Б╤В ╨╜╨╡ ╨▒╤Л╨╗ ╨╛╨▒╨╜╨╛╨▓╨╗╤С╨╜ ╨▓╨╝╨╡╤Б╤В╨╡ ╤Б FIX-1.

Fix:
- Scenario 2 ╨┐╨╡╤А╨╡╨┐╨╕╤Б╨░╨╜ ╨┐╨╛╨┤ ╨░╨║╤В╤Г╨░╨╗╤М╨╜╤Л╨╣ ╨▓╤Л╨╖╨╛╨▓
  sendError($conn2, 'error.invalid_json', 'Invalid action syntax') ╨╕
  ╨╛╨╢╨╕╨┤╨░╨╡╨╝╤Л╨╣ ╨┐╨░╨║╨╡╤В {"type":"error","code":"error.invalid_json","message":"..."}
  (ANCHOR_PROTOCOL.md ┬з Error Packet). ╨Я╤А╨░╨▓╨╕╨╗╤Б╤П ╤В╨╡╤Б╤В, ╨╜╨╡ ╤А╨╡╨░╨╗╨╕╨╖╨░╤Ж╨╕╤П тАФ
  ANCHOR_RULES.md Part 22 (Test Philosophy): sendError() ╤Г╨╢╨╡ ╨▓╨╡╤А╨╜╨╛
  ╤А╨╡╨░╨╗╨╕╨╖╤Г╨╡╤В ╨░╨║╤В╤Г╨░╨╗╤М╨╜╤Л╨╣ ╨║╨╛╨╜╤В╤А╨░╨║╤В.

Result:
- test_helpers_runner.php: ╨▓╤Б╨╡ 4 ╤Б╤Ж╨╡╨╜╨░╤А╨╕╤П PASSED.

Diff: patches/FIX-5.patch

### FIX-3 тАФ Double refund on kick + admin_close_room
Status: Completed
Date: 2026-07-03

Files:
- src/Admin/AdminService.php

Problem:
- handleKickUser() ╤А╨╡╤Д╨░╨╜╨┤╨╕╨╗ total_paid ╨╕╨│╤А╨╛╨║╤Г ╨╕ ╤Г╨╝╨╡╨╜╤М╤И╨░╨╗ room bank, ╨╜╨╛ ╨Э╨Х
  ╨╛╨▒╨╜╤Г╨╗╤П╨╗ total_paid ╨╕╨│╤А╨╛╨║╨░ ╨▓ ╨┐╨░╨╝╤П╤В╨╕ room state.
- ╨Ф╨╡╨╗╨╡╨│╨░╤В ╤Г╨┤╨░╨╗╨╡╨╜╨╕╤П (removePlayerFromLobby/removePlayerFromGame/
  removePlayerFromApartment) ╨╖╨░╨┐╨╕╤Б╤Л╨▓╨░╨╗ ╨▓ all_players_history ╤Б╤В╨░╤А╨╛╨╡
  (╨┤╨╛╤А╨╡╤Д╨░╨╜╨┤╨╜╨╛╨╡) ╨╖╨╜╨░╤З╨╡╨╜╨╕╨╡ total_paid.
- handleCloseRoom() ╨▒╨╡╨╖╤Г╤Б╨╗╨╛╨▓╨╜╨╛ ╤А╨╡╤Д╨░╨╜╨┤╨╕╤В total_paid ╨╕╨╖ all_players_history
  ╨║╨░╨╢╨┤╨╛╨╝╤Г ╤Г╤З╨░╤Б╤В╨╜╨╕╨║╤Г тАФ ╨┐╤А╨╕ ╨┐╨╛╤Б╨╗╨╡╨┤╤Г╤О╤Й╨╡╨╝ admin_close_room() ╤А╨░╨╜╨╡╨╡ ╨║╨╕╨║╨╜╤Г╤В╤Л╨╣
  ╨╕╨│╤А╨╛╨║ ╨┐╨╛╨╗╤Г╤З╨░╨╗ ╤Б╤В╨░╨▓╨║╤Г ╨╡╤Й╤С ╤А╨░╨╖. ╨Э╨░╤А╤Г╤И╨╡╨╜╨╕╨╡ ANCHOR_CORE.md Part 2 ┬з
  Economic Integrity Rule.

Fix:
- ╨Я╨╛╤Б╨╗╨╡ ╤Г╤Б╨┐╨╡╤И╨╜╨╛╨╣ refund-╤В╤А╨░╨╜╨╖╨░╨║╤Ж╨╕╨╕ ╨▓ handleKickUser() ╨┤╨╛╨▒╨░╨▓╨╗╨╡╨╜╨░ ╤Б╤В╤А╨╛╨║╨░
  `$room['players'][$connId]['total_paid'] = 0;` тАФ ╨╛╨▒╨╜╤Г╨╗╨╡╨╜╨╕╨╡ ╨Ф╨Ю ╨▓╤Л╨╖╨╛╨▓╨░
  ╨┤╨╡╨╗╨╡╨│╨░╤В╨░ ╤Г╨┤╨░╨╗╨╡╨╜╨╕╤П, ╤З╤В╨╛╨▒╤Л all_players_history ╤Д╨╕╨║╤Б╨╕╤А╨╛╨▓╨░╨╗ 0 (╨╜╨╡╤З╨╡╨│╨╛ ╨▒╨╛╨╗╤М╤И╨╡
  ╨▓╨╛╨╖╨▓╤А╨░╤Й╨░╤В╤М ╤Н╤В╨╛╨╝╤Г ╨╕╨│╤А╨╛╨║╤Г).

Result:
- ╨Ю╨▒╨╜╨░╤А╤Г╨╢╨╡╨╜╨╛ ╨╕ ╨╖╨░╤Д╨╕╨║╤Б╨╕╤А╨╛╨▓╨░╨╜╨╛ regression-╤В╨╡╤Б╤В╨░╨╝╨╕ ╨▓
  tests/Manual/test_admin_integration.php (TEST 1, TEST 3).
- ╨Я╤А╨╛╨▓╨╡╤А╨╡╨╜╨╛ ╨╜╨░ ╨╗╨╛╨╢╨╜╨╛╨┐╨╛╨╗╨╛╨╢╨╕╤В╨╡╨╗╤М╨╜╨╛╤Б╤В╤М: ╨▒╨╡╨╖ ╤Д╨╕╨║╤Б╨░ ╤В╨╡╤Б╤В ╨┤╨░╤С╤В 5 ╤З╨╡╤Б╤В╨╜╤Л╤Е FAIL,
  ╤Б ╤Д╨╕╨║╤Б╨╛╨╝ тАФ 20/20 PASSED.
- ╨Т╤Б╤П ╤Б╤Г╤Й╨╡╤Б╤В╨▓╤Г╤О╤Й╨░╤П ╤А╨╡╨│╤А╨╡╤Б╤Б╨╕╤П (test_admin_kick.php, test_admin_close_room.php
  ╨╕ ╨┤╤А.) ╨╛╤Б╤В╨░╤С╤В╤Б╤П ╨╖╨╡╨╗╤С╨╜╨╛╨╣.

Diff: patches/FIX-3.patch

### FIX-1 тАФ sendError() protocol contract
Status: Completed
Date: 2026-06-21

Files:
- src/Core/Helpers.php

Problem:
- error packet ╨╜╨╡ ╤Б╨╛╨┤╨╡╤А╨╢╨░╨╗ ╨╛╨▒╤П╨╖╨░╤В╨╡╨╗╤М╨╜╨╛╨╡ ╨┐╨╛╨╗╨╡ `code`

Fix:
- ╤Б╨╕╨│╨╜╨░╤В╤Г╤А╨░ ╨╕╨╖╨╝╨╡╨╜╨╡╨╜╨░ ╨╜╨░:

`php
sendError(object $connection, string $code, string $message = ''): void
`

- ╨┐╨╛╨╗╨╡ `code` ╨┤╨╛╨▒╨░╨▓╨╗╨╡╨╜╨╛ ╨▓ JSON ╨┐╨░╨║╨╡╤В.

---

### FIX-2 тАФ Registration Daily Bonus Contract
Status: Completed
Date: 2026-06-22

Files:
- src/Infrastructure/PreparedStatements.php

Problem:
- ╨Э╨╛╨▓╤Л╨╣ ╨┐╨╛╨╗╤М╨╖╨╛╨▓╨░╤В╨╡╨╗╤М ╤Б╨╛╨╖╨┤╨░╨▓╨░╨╗╤Б╤П ╤Б `last_daily_bonus = 0`
- ╨Р╨▓╤В╨╛╨╗╨╛╨│╨╕╨╜ ╨┐╨╛╤Б╨╗╨╡ ╤А╨╡╨│╨╕╤Б╤В╤А╨░╤Ж╨╕╨╕ ╨╜╨░╤З╨╕╤Б╨╗╤П╨╗ +100 ╨╝╨╛╨╜╨╡╤В
- ╨Э╨░╤А╤Г╤И╨░╨╗╤Б╤П ╨║╨╛╨╜╤В╤А╨░╨║╤В EPIC-1.4 (`coins = 500` ╨┐╨╛╤Б╨╗╨╡ ╤А╨╡╨│╨╕╤Б╤В╤А╨░╤Ж╨╕╨╕)

Fix:

`sql
strftime('%s','now')
`

╨╕╤Б╨┐╨╛╨╗╤М╨╖╤Г╨╡╤В╤Б╤П ╨┐╤А╨╕ ╤Б╨╛╨╖╨┤╨░╨╜╨╕╨╕ ╨┐╨╛╨╗╤М╨╖╨╛╨▓╨░╤В╨╡╨╗╤П.

Result:
- ╨С╨░╨╗╨░╨╜╤Б ╨┐╨╛╤Б╨╗╨╡ ╤А╨╡╨│╨╕╤Б╤В╤А╨░╤Ж╨╕╨╕ = 500
- ╨Т╤Б╨╡ ╨╕╨╜╤В╨╡╨│╤А╨░╤Ж╨╕╨╛╨╜╨╜╤Л╨╡ ╤В╨╡╤Б╤В╤Л ╨┐╤А╨╛╤Е╨╛╨┤╤П╤В.

---

## DECISION LOG

- 2026-07-28 тАФ FIX-16 Accepted: found during VPS `./run_ALL_tests.sh` at
  EPIC-13.4 sign-off (not a proactive audit) тАФ `b203493` (EPIC-13.1) called
  `lottoBootstrapPhpExtensions()` in `server.php` but the function existed
  only in an uncommitted local `src/Core/Helpers.php` diff (FIX-15 Windows
  bootstrap work). Eight live-WS-subprocess tests failed on Ubuntu with a
  fatal error before port bind; local Windows runs did not catch it because
  the committed `run_ALL_tests.php` still skips those tests via
  `$skipOnWindows`. Fixed in `0de46d0`. Process takeaway mirrors FIX-12:
  VPS-authoritative runs expose gaps that dev-host shortcuts hide; never
  commit `server.php` calls to symbols not yet in the repository.
- 2026-07-28 тАФ Phase 13 git checkpoint deviation Accepted (process note, no
  code impact): implementation followed Rule 16 intent (each Epic independently
  verifiable) but commit boundaries did not map 1:1 to Epic numbers. EPIC-13.3
  label duplicated across commits `8cd1434` and `f4cf0f4`; EPIC-13.2 bundled
  into `b203493` (EPIC-13.1) due to shared `GameService.php` edits. Documented
  in Phase 13 block above. Future phases: split file edits per Epic before
  committing, or use explicit `EPIC-13.2+13.3` combined messages when files
  cannot be separated without partial commits.
- 2026-07-26 тАФ ROADMAP.md Phase 11/12/13/14 reorder Accepted (user
  decision, following up on a concern raised after EPIC-10.7): Frontend
  depends entirely on the server implementation, so auditing the server
  first avoids redoing client work if an audit surfaces a protocol
  change. New Phase 11 (was Phase 12.0-12.6): Full integration testing,
  memory/timer/economy/state-machine/protocol audits, load testing. New
  Phase 12 (was Phase 11): Frontend. Phase 13 intentionally skipped
  entirely, by explicit project decision (not a numbering error). New
  Phase 14 (was EPIC-12.7/12.8): Release Candidate -> v1.0 Release, given
  its own phase number since Phase 12 was reassigned to Frontend.
  docs/ROADMAP.md updated with the new structure plus an explanatory
  note mapping old->new numbering. No code, protocol, or test changes тАФ
  none of the affected epics were implemented yet, so this is pure
  documentation with zero migration risk.
- 2026-07-25 тАФ FIX-12 Accepted: found during a live operational incident
  (not a proactive audit) тАФ test runs executed as root against the live
  VPS left game.db/workerman.log/logs/server.log root-owned while the
  production systemd service runs as www-data, causing a real crash-loop
  (Permission denied on every log write, worker respawning repeatedly).
  Fixed operationally via chown (see incident thread). While diagnosing
  it, a confusing [ERROR] "CHECK constraint failed: coins <= 200" line
  was found in the production log тАФ traced to tests/Manual/
  test_victory.php's makeSvc(), which correctly isolates its DATABASE via
  an in-memory PDO (FIX-4) but paired it with a real, default-path
  Logger, so a deliberately-rigged rollback test's error message still
  landed in the real log, indistinguishable from an actual incident. The
  existing code comment had already flagged this exact side effect
  without fixing it. Root cause: Logger::__construct() had no way to
  redirect its output at all. Fixed with an optional constructor
  parameter (mirroring the FIX-4 Database DI-seam precedent), then swept
  every real-Logger call site project-wide: 6 test files fixed, 2 more
  (test_admin_logs.php, test_admin_integration.php) found bleeding the
  same way via the same sweep, one stale already-superseded test file
  (test_logger.php) deleted, and тАФ as a side benefit тАФ confirmed that
  test_lobby_integration.php/test_auth_integration.php's existing (but
  silently non-functional, since PHP doesn't error on extra constructor
  arguments) attempt to redirect to '/dev/null' now actually works.
  Explicitly left out of scope: real-WS-subprocess tests (EPIC-10.3-10.7)
  spawn genuine server.php instances whose Logger is correct production
  code by definition тАФ a different, lower-severity category of test
  noise than the false-ERROR incident this fix targets; making
  server.php's log path itself configurable is a separate, larger
  decision left for later. Verified via MD5 hash of logs/server.log
  before/after each affected test, individually and across the full
  suite. Full regression 0 failed (29 files, one deleted).
- 2026-07-24 тАФ EPIC-10.7 Accepted: per explicit user scoping, this Epic is
  a completeness/coverage audit (does the server side have everything
  ANCHOR_CORE.md/ANCHOR_PROTOCOL.md declare?), not a re-test of business
  logic already covered by the per-module routing tests and Phase-
  specific unit tests. New tests/Manual/test_protocol_completeness.php
  parses the actual declared registries out of the ANCHOR docs at run
  time (not a hardcoded copy) and cross-references against server.php/
  src/ тАФ 50/50 PASSED, 3 warnings, all matching already-documented KNOWN
  GAPS (admin_stats_data unimplemented, afk_warning undeclared) plus one
  new low-priority finding (error.banned declared but unused тАФ superseded
  by the dedicated `banned` packet type, not a functional gap). No code
  defects found тАФ confirms EPIC-10.0-10.6's wiring is genuinely complete
  against the full declared protocol surface. PHASE 10 тАФ WEBSOCKET
  PROTOCOL: COMPLETE.
- 2026-07-24 тАФ EPIC-10.6 Accepted + FIX-11: admin_ban_user/admin_unban_user/
  admin_kick_user/admin_close_room/admin_get_logs wired to new
  AdminHandler (AdminService Phase 9 already existed тАФ dependency wiring
  + routing, all 7 of its nullable dependencies wired this time, unlike a
  partial wiring which would have silently degraded kick/ban removal or
  admin_close_room's timer cleanup). Proactive audit (again requested by
  user, same pattern as FIX-9/FIX-10) found FIX-11: banned users could
  fully bypass their ban. Three compounding gaps in the ban path only
  (kick was already correct) тАФ handleBanUser()'s room-removal was
  incorrectly gated behind isset($worker->userConnections[...]), which
  FIX-10 (same day) had just made behave correctly, exposing that a
  disconnected-but-reconnect-pending banned player was never removed;
  banning an online player never closed their connection, leaving a
  stale-but-authenticated session able to keep acting; and тАФ the most
  severe тАФ AuthHandler::handleReconnect() never checked banned_until at
  all, unlike login(), so reconnect was a total, permanent bypass of any
  ban regardless of room state. All three fixed and independently
  verified non-false-positive. Two existing unit tests' mock connection
  classes needed a close() method added (fixture update, not a logic
  change) now that handleBanUser() actually calls it. New
  tests/Manual/test_admin_packet_routing.php: 15/15 PASSED, real WS
  client, covering both the Epic's routing and FIX-11 together. Full
  regression 0 failed (30 files).
- 2026-07-24 тАФ FIX-10 Accepted: proactive audit before EPIC-10.6 (requested
  by user, same spirit as the FIX-6 audit before Phase 10 and the FIX-9
  discovery during EPIC-10.5) found that $worker->userConnections is never
  unset by ANY code path тАФ permanent single-session lockout for any
  account that disconnects without being seated in a room, since neither
  login (blocked by the stale isset() check) nor reconnect (never bound
  $connection->userId for room-less sessions) could recover access.
  Undetected until now because onClose never called
  ReconnectService::handleDisconnect() before EPIC-10.5 тАФ no disconnect
  ever reached this code at all тАФ and the one relevant test
  (test_single_session.php, Phase 1) manually fakes the missing cleanup
  step rather than exercising it. Fixed in AuthHandler::handleReconnect()
  (now binds the connection via the same bindConnection() login/register
  use, backed by a new AuthService::getUserById()) and server.php's
  onClose (releases the userConnections slot on genuine disconnect,
  verified not to weaken ADR-001's concurrent-session rejection). Both
  halves independently verified non-false-positive. New
  tests/Manual/test_session_lifecycle.php: 6/6 PASSED, real WS client,
  no MockConnection. Full regression 0 failed. EPIC-10.6 not yet started.
- 2026-06-21 тАФ ROADMAP.md ╨┐╤А╨╕╨╖╨╜╨░╨╜ ╨╕╤Б╤В╨╛╤З╨╜╨╕╨║╨╛╨╝ ╨╕╤Б╤В╨╕╨╜╤Л ╨┐╨╛ ╨╜╤Г╨╝╨╡╤А╨░╤Ж╨╕╨╕ Epic.
- 2026-06-21 тАФ Reconnect Token Infrastructure ╨▓╤Л╨╜╨╡╤Б╨╡╨╜ ╨▓ PRE-BUILT COMPONENTS.
- 2026-06-22 тАФ PHASE 1 ╨╛╤Д╨╕╤Ж╨╕╨░╨╗╤М╨╜╨╛ ╨╖╨░╨▓╨╡╤А╤И╨╡╨╜╨░ ╨┐╨╛╤Б╨╗╨╡ ╨┐╤А╨╛╤Е╨╛╨╢╨┤╨╡╨╜╨╕╤П ╨╕╨╜╤В╨╡╨│╤А╨░╤Ж╨╕╨╛╨╜╨╜╤Л╤Е ╤В╨╡╤Б╤В╨╛╨▓.
- 2026-06-23 тАФ EPIC-2.0 RoomManager ╤А╨╡╨░╨╗╨╕╨╖╨╛╨▓╨░╨╜ (src/Core/RoomManager.php, 245 ╤Б╤В╤А╨╛╨║).
- 2026-06-25 тАФ EPIC-2.3 Leave room ╨╖╨░╨▓╨╡╤А╤И╤С╨╜, FIX: all_players_history ╨▓ removePlayerFromLobby.
- 2026-06-28 тАФ EPIC-2.4 Room list ╨╖╨░╨▓╨╡╤А╤И╤С╨╜.
- 2026-07-02 тАФ ADR-002 Accepted: GameFinishService extracted; Phase 7 anchor-compliance fixes applied; Phase 7 tests green.
- 2026-07-02 тАФ EPIC-9.3 Kick player ╨╖╨░╨▓╨╡╤А╤И╤С╨╜. KNOWN GAP: host transfer ╨┐╤А╨╕ kick/ban ╨▓ apartment-╤Б╨╛╤Б╤В╨╛╤П╨╜╨╕╨╕ ╨╖╨░╤Д╨╕╨║╤Б╨╕╤А╨╛╨▓╨░╨╜ ╨┤╨╗╤П ╨▒╤Г╨┤╤Г╤Й╨╡╨│╨╛ Epic.
- 2026-07-03 тАФ EPIC-9.5 Logs access ╤Д╨░╨║╤В╨╕╤З╨╡╤Б╨║╨╕ ╤А╨╡╨░╨╗╨╕╨╖╨╛╨▓╨░╨╜ (handleGetLogs()/getLastLines()), ╨╖╨░╨║╤А╤Л╤В╨╛ ╤А╨░╤Б╤Е╨╛╨╢╨┤╨╡╨╜╨╕╨╡ ╨╝╨╡╨╢╨┤╤Г ╤Б╤В╨░╤В╤Г╤Б╨╛╨╝ ╨╕ ╨║╨╛╨┤╨╛╨╝, ╨╛╨▒╨╜╨░╤А╤Г╨╢╨╡╨╜╨╜╨╛╨╡ ╨┐╤А╨╕ ╨┐╨╛╨┤╨│╨╛╤В╨╛╨▓╨║╨╡ EPIC-9.6.
- 2026-07-03 тАФ FIX-3 Accepted: ╤Г╤Б╤В╤А╨░╨╜╤С╨╜ ╨┤╨▓╨╛╨╣╨╜╨╛╨╣ ╤А╨╡╤Д╨░╨╜╨┤ kick+admin_close_room (Economic Integrity Rule). EPIC-9.6 Admin integration tests ╨╖╨░╨▓╨╡╤А╤И╤С╨╜, PHASE 9 COMPLETE.
- 2026-07-03 тАФ ╨Ю╨▒╨╜╨░╤А╤Г╨╢╨╡╨╜╤Л pre-existing ╨┐╨░╨┤╨╡╨╜╨╕╤П test_game_start.php/test_victory.php (GameFinishService type mismatch) ╨╕ test_helpers_runner.php (╤Г╤Б╤В╨░╤А╨╡╨▓╤И╨╕╨╣ assert sendError()) тАФ ╨╜╨╡ ╤Б╨▓╤П╨╖╨░╨╜╤Л ╤Б EPIC-9.6, ╨╖╨░╤Д╨╕╨║╤Б╨╕╤А╨╛╨▓╨░╨╜╤Л ╨▓ KNOWN GAPS ╨┤╨╗╤П ╨╛╤В╨┤╨╡╨╗╤М╨╜╨╛╨│╨╛ FIX ╨┐╨╡╤А╨╡╨┤ Phase 10.
- 2026-07-03 тАФ FIX-4 Accepted: Database ╨┐╨╛╨╗╤Г╤З╨╕╨╗ DI-seam (╨╛╨┐╤Ж╨╕╨╛╨╜╨░╨╗╤М╨╜╤Л╨╣ PDO), test_game_start.php/test_victory.php ╨┐╨╡╤А╨╡╨▓╨╡╨┤╨╡╨╜╤Л ╨╜╨░ ╤А╨╡╨░╨╗╤М╨╜╤Л╨╣ GameFinishService ╨▓╨╝╨╡╤Б╤В╨╛ type-╨╜╨╡╤Б╨╛╨▓╨╝╨╡╤Б╤В╨╕╨╝╤Л╤Е ╨░╨╜╨╛╨╜╨╕╨╝╨╜╤Л╤Е ╨║╨╗╨░╤Б╤Б╨╛╨▓. FIX-5 Accepted: test_helpers_runner.php ╨┐╤А╨╕╨▓╨╡╨┤╤С╨╜ ╨║ ╨░╨║╤В╤Г╨░╨╗╤М╨╜╨╛╨╝╤Г ╨║╨╛╨╜╤В╤А╨░╨║╤В╤Г sendError(). ╨Я╨╛╨╗╨╜╤Л╨╣ ╤А╨╡╨│╤А╨╡╤Б╤Б ╨┐╨╛ ╨▓╤Б╨╡╨╝ 22 ╤Д╨░╨╣╨╗╨░╨╝ tests/Manual/*.php тАФ 0 failed. PHASE 9 ╤Б╤В╨░╨▒╨╕╨╗╤М╨╜╨░, ╨┐╤Г╤В╤М ╨║ Phase 10 ╨╛╤В╨║╤А╤Л╤В ╨▒╨╡╨╖ ╨╕╨╖╨▓╨╡╤Б╤В╨╜╤Л╤Е ╨┤╨╡╤Д╨╡╨║╤В╨╛╨▓.
- 2026-07-03 тАФ ╨Р╤Г╨┤╨╕╤В ╨╜╨░ ╨▒╨░╨│╨╕, ╨░╨╜╨░╨╗╨╛╨│╨╕╤З╨╜╤Л╨╡ FIX-3 (╨┐╨╛ ╨╖╨░╨┐╤А╨╛╤Б╤Г ╨┐╨╡╤А╨╡╨┤ Phase 10): ╨╜╨░╨╣╨┤╨╡╨╜ ╨╕ ╨╕╤Б╨┐╤А╨░╨▓╨╗╨╡╨╜ FIX-6 (╤Г╤В╨╡╤З╨║╨░ reconnect_timer ╨┐╤А╨╕ kick/ban ╤Г╨┤╨░╨╗╨╡╨╜╨╕╨╕ ╨▓ Lobby/Apartment тАФ Timer Integrity Rule). ╨Я╤А╨╛╨▓╨╡╤А╨╡╨╜╤Л: ╤Н╨║╨╛╨╜╨╛╨╝╨╕╤З╨╡╤Б╨║╨╕╨╡ ╨╝╤Г╤В╨░╤Ж╨╕╨╕ (bank/total_paid/coins тАФ ╤З╨╕╤Б╤В╨╛), reconnect/disconnect ╨╕╤Б╤В╨╛╤А╨╕╤П (╤З╨╕╤Б╤В╨╛), timer cleanup ╨┐╤А╨╕ destroyRoom (╤З╨╕╤Б╤В╨╛, ╨┤╨╡╨╗╨╡╨│╨╕╤А╨╛╨▓╨░╨╜╨╕╨╡ ╨║╨╛╤А╤А╨╡╨║╤В╨╜╨╛), state machine ╨╖╨░╨┐╨╕╤Б╨╕ ╤Б╤В╨░╤В╤Г╤Б╨╛╨▓ (╤З╨╕╤Б╤В╨╛), Module Boundaries AdminтЖТGame (╤З╨╕╤Б╤В╨╛, ╤В╨╛╨╗╤М╨║╨╛ ╨┐╤Г╨▒╨╗╨╕╤З╨╜╤Л╨╡ ╨╝╨╡╤В╨╛╨┤╤Л), host-transfer ╨║╨╛╨╝╨╝╨╡╨╜╤В╨░╤А╨╕╨╣ ╨▓ handleKickUser (╤Б╨╛╨╛╤В╨▓╨╡╤В╤Б╤В╨▓╤Г╨╡╤В ╤Г╨╢╨╡ ╨╖╨░╨┤╨╛╨║╤Г╨╝╨╡╨╜╤В╨╕╤А╨╛╨▓╨░╨╜╨╜╨╛╨╝╤Г KNOWN GAP EPIC-9.3, ╨╜╨╛╨▓╤Л╤Е ╤А╨░╤Б╤Е╨╛╨╢╨┤╨╡╨╜╨╕╨╣ ╨╜╨╡╤В). ╨Я╨╛╨╗╨╜╤Л╨╣ ╤А╨╡╨│╤А╨╡╤Б╤Б ╨┐╨╛ 23 ╤Д╨░╨╣╨╗╨░╨╝ tests/Manual/*.php (╨┤╨╛╨▒╨░╨▓╨╗╨╡╨╜ test_timer_integrity.php) тАФ 0 failed.
- 2026-07-03 тАФ ╨Т╤В╨╛╤А╨╛╨╣ ╤А╨░╤Г╨╜╨┤ ╨░╤Г╨┤╨╕╤В╨░ (╨┐╤А╨╛╤В╨╛╨║╨╛╨╗/edge cases): ╨╛╨▒╨╜╨░╤А╤Г╨╢╨╡╨╜╤Л ╨╕ ╤Г╨┤╨░╨╗╨╡╨╜╤Л docs/ANCHOR_PROJECT_STATUS.md (╤Г╤Б╤В╨░╤А╨╡╨╗ ╤Б ╨╜╨░╤З╨░╨╗╨░ ╨┐╤А╨╛╨╡╨║╤В╨░, ╨▓╨▓╨╛╨┤╨╕╨╗ ╨▓ ╨╖╨░╨▒╨╗╤Г╨╢╨┤╨╡╨╜╨╕╨╡ ╨▒╤Г╨┤╤Г╤Й╨╕╨╡ ╤Б╨╡╤Б╤Б╨╕╨╕). ╨Ю╨▒╨╜╨░╤А╤Г╨╢╨╡╨╜╤Л docs/prompt.md (╨╕╤Б╤Е╨╛╨┤╨╜╨╛╨╡ ╨в╨Ч v4.0) ╨╕ docs/GAME_RULES.md тАФ ╨╛╨▒╨░ ╤В╨╛╨╢╨╡ ╨╜╨╡ ╨╛╨▒╨╜╨╛╨▓╨╗╤П╨╗╨╕╤Б╤М ╤Б ╨╜╨░╤З╨░╨╗╨░ ╨┐╤А╨╛╨╡╨║╤В╨░; ╨╕╨╖ prompt.md ╨╕╨╖╨▓╨╗╨╡╤З╨╡╨╜╤Л ╨┤╨▓╨░ ╨╜╨╡╨╖╨░╨┤╨╛╨║╤Г╨╝╨╡╨╜╤В╨╕╤А╨╛╨▓╨░╨╜╨╜╤Л╤Е ╤В╤А╨╡╨▒╨╛╨▓╨░╨╜╨╕╤П (rate limiting, invalid-JSON policy) тАФ ╤Б╨╝. KNOWN GAPS, ╤А╨╡╤И╨╡╨╜╨╕╨╡ ╨╛╤В╨╗╨╛╨╢╨╡╨╜╨╛ ╨┤╨╛ EPIC-10.1 ╨┐╨╛ ╤А╨╡╤И╨╡╨╜╨╕╤О ╨┐╨╛╨╗╤М╨╖╨╛╨▓╨░╤В╨╡╨╗╤П. ╨в╨░╨║╨╢╨╡ ╨╛╨▒╨╜╨░╤А╤Г╨╢╨╡╨╜╤Л ╨┤╨▓╨░ ╨┐╤А╨╛╤В╨╛╨║╨╛╨╗╤М╨╜╤Л╤Е ╨┤╨╛╨╗╨│╨░ ╨╜╨╕╨╖╨║╨╛╨│╨╛ ╨┐╤А╨╕╨╛╤А╨╕╤В╨╡╤В╨░: afk_warning (╨╜╨╡ ╨╖╨░╨┤╨╡╨║╨╗╨░╤А╨╕╤А╨╛╨▓╨░╨╜) ╨╕ admin_stats_data (╨╖╨░╨┤╨╡╨║╨╗╨░╤А╨╕╤А╨╛╨▓╨░╨╜, ╨╜╨╡ ╤А╨╡╨░╨╗╨╕╨╖╨╛╨▓╨░╨╜, ╨▒╨╡╨╖ Epic). ╨Ъ╨╛╨┤╨╛╨▓╤Л╤Е ╨▒╨░╨│╨╛╨▓ ╨▓ ╤Н╤В╨╛╨╝ ╤А╨░╤Г╨╜╨┤╨╡ ╨╜╨╡ ╨╜╨░╨╣╨┤╨╡╨╜╨╛ тАФ ╨▓╤Б╨╡ ╨╜╨░╤Е╨╛╨┤╨║╨╕ ╨┤╨╛╨║╤Г╨╝╨╡╨╜╤В╨░╤Ж╨╕╨╛╨╜╨╜╤Л╨╡/╨┐╤А╨╛╤Ж╨╡╤Б╤Б╨╜╤Л╨╡.
- 2026-07-03 тАФ EPIC-10.0 Protocol router ╨╖╨░╨▓╨╡╤А╤И╤С╨╜: server.php (Workerman bootstrap, onWorkerStart/onWebSocketConnected/onMessage/onClose) ╨▒╨╡╨╖ auth/lobby/game/admin-╨╗╨╛╨│╨╕╨║╨╕ (Rule 11 Epic Isolation тАФ ReconnectService ╤В╤А╨╡╨▒╤Г╨╡╤В LobbyService+GameService ╨╛╨┤╨╜╨╛╨▓╤А╨╡╨╝╨╡╨╜╨╜╨╛, ╨┐╨╛╨┤╨║╨╗╤О╤З╨╡╨╜╨╕╨╡ onClose ╨║ ╤А╨╡╨░╨╗╤М╨╜╨╛╨╣ ╨▒╨╕╨╖╨╜╨╡╤Б-╨╗╨╛╨│╨╕╨║╨╡ ╨╛╤В╨╗╨╛╨╢╨╡╨╜╨╛ ╨┤╨╛ EPIC-10.4/10.5). ╨Т╨╡╤А╨╕╤Д╨╕╤Ж╨╕╤А╨╛╨▓╨░╨╜ ╨┐╨╛╨╗╨╜╨╛╤Б╤В╤М╤О ╨░╨▓╤В╨╛╨╝╨░╤В╨╕╤З╨╡╤Б╨║╨╕ ╤З╨╡╤А╨╡╨╖ ╤А╨╡╨░╨╗╤М╨╜╤Л╨╣ WebSocket-╨║╨╗╨╕╨╡╨╜╤В (╨▒╨╡╨╖ ╨▓╨╜╨╡╤И╨╜╨╕╤Е ╨▒╨╕╨▒╨╗╨╕╨╛╤В╨╡╨║) ╨┐╨╛╨▓╨╡╤А╤Е ╨╜╨░╤Б╤В╨╛╤П╤Й╨╡╨│╨╛ TCP-╤Б╨╛╨║╨╡╤В╨░ тАФ 8/8 PASSED. Rate limiting ╨╕ invalid-JSON policy ╨┐╨╛╨┤╤В╨▓╨╡╤А╨╢╨┤╨╡╨╜╤Л ╨║╨░╨║ ╨╛╤В╨║╤А╤Л╤В╤Л╨╡ ╨▓╨╛╨┐╤А╨╛╤Б╤Л EPIC-10.1 (╨╜╨╡ ╤А╨╡╨░╨╗╨╕╨╖╨╛╨▓╨░╨╜╤Л ╨╜╨░╨╝╨╡╤А╨╡╨╜╨╜╨╛).
- 2026-07-23 тАФ EPIC-10.5 Accepted + FIX-9: start_game/draw_barrel/
  apartment_choice ╨┐╨╛╨┤╨║╨╗╤О╤З╨╡╨╜╤Л ╨║ ╨╜╨╛╨▓╨╛╨╝╤Г GameHandler (GameService Phase 4-7
  ╤Г╨╢╨╡ ╤Б╤Г╤Й╨╡╤Б╤В╨▓╨╛╨▓╨░╨╗ тАФ dependency wiring + routing). ReconnectService ╤В╨░╨║╨╢╨╡
  ╨┐╨╛╨┤╨║╨╗╤О╤З╤С╨╜ (onClose -> handleDisconnect(), 'reconnect' action ->
  handleReconnect() ╨┐╨╛╨▓╨╡╤А╤Е AuthHandler) тАФ ╨╛╨▒╨░ ╨╡╨│╨╛ ╨╖╨░╨▓╨╕╤Б╨╕╨╝╤Л╤Е ╤Б╨╡╤А╨▓╨╕╤Б╨░,
  LobbyService (EPIC-10.4) ╨╕ GameService (╤Н╤В╨╛╤В Epic), ╨╜╨░╨║╨╛╨╜╨╡╤Ж ╤Б╨╛╨▒╤А╨░╨╜╤Л
  ╨╛╨┤╨╜╨╛╨▓╤А╨╡╨╝╨╡╨╜╨╜╨╛. ╨Э╨░╨╣╨┤╨╡╨╜ ╨╕ ╨╕╤Б╨┐╤А╨░╨▓╨╗╨╡╨╜ FIX-9 ╨▓ ╨┐╤А╨╛╤Ж╨╡╤Б╤Б╨╡: handleReconnect() ╨╜╨╡
  ╨┐╨╡╤А╨╡╨╕╨╜╨┤╨╡╨║╤Б╨╕╤А╨╛╨▓╨░╨╗ $room['players'] ╨╜╨░ ╨╜╨╛╨▓╤Л╨╣ conn_id ╨╜╨╛╨▓╨╛╨│╨╛ WS-╤Б╨╛╨╡╨┤╨╕╨╜╨╡╨╜╨╕╤П
  тАФ reconnect_state ╨╛╤В╨┐╤А╨░╨▓╨╗╤П╨╗╤Б╤П, ╨╜╨╛ ╨╗╤О╨▒╨╛╨╡ ╨┤╨░╨╗╤М╨╜╨╡╨╣╤И╨╡╨╡ ╨┤╨╡╨╣╤Б╤В╨▓╨╕╨╡ ╤Б ╨╜╨╛╨▓╨╛╨│╨╛
  ╤Б╨╛╨╡╨┤╨╕╨╜╨╡╨╜╨╕╤П ╨╜╨╡ ╨╜╨░╤Е╨╛╨┤╨╕╨╗╨╛ ╨╕╨│╤А╨╛╨║╨░ (room_not_found). ╨Ш╤Б╨┐╤А╨░╨▓╨╗╨╡╨╜╨╛: re-key
  players + host_conn_id/active_drawer_conn_id/drawer_order. ╨Я╨╛╨┐╤Г╤В╨╜╨╛
  ╨╕╤Б╨┐╤А╨░╨▓╨╗╨╡╨╜╨░ ╤Б╤В╤Г╤Е╤И╨░╤П assertion ╨▓ test_auth_packet_routing.php TEST 2
  (╨╛╨╢╨╕╨┤╨░╨╗╨░ error.invalid_json ╤В╨░╨╝, ╨│╨┤╨╡ EPIC-10.4 ╤Г╨╢╨╡ ╨┤╨░╨▓╨╜╨╛ ╨▓╨╛╨╖╨▓╤А╨░╤Й╨░╨╡╤В
  room_joined тАФ ╤А╨░╤Б╤Е╨╛╨╢╨┤╨╡╨╜╨╕╨╡ ╨╝╨╡╨╢╨┤╤Г ╤Н╤В╨╕╨╝ ╤Д╨░╨╣╨╗╨╛╨╝ ╨╕ ╤Д╨░╨║╤В╨╕╤З╨╡╤Б╨║╨╕ ╨╖╨░╨║╨╛╨╝╨╝╨╕╤З╨╡╨╜╨╜╤Л╨╝
  ╤В╨╡╤Б╤В╨╛╨╝). Housekeeping: ╤Г╨┤╨░╨╗╤С╨╜ ╨┐╨░╤А╨░╨╖╨╕╤В╨╜╤Л╨╣ `tests/manual/` (╨╜╨╕╨╢╨╜╨╕╨╣
  ╤А╨╡╨│╨╕╤Б╤В╤А) ╨║╨░╤В╨░╨╗╨╛╨│-╨┤╤Г╨▒╨╗╨╕╨║╨░╤В тАФ `test_lobby_packet_routing.php` ╤Б╤Г╤Й╨╡╤Б╤В╨▓╨╛╨▓╨░╨╗
  ╤В╨╛╨╗╤М╨║╨╛ ╨▓ ╨╜╤С╨╝ ╨╕ ╨╜╨╕╨║╨╛╨│╨┤╨░ ╨╜╨╡ ╨╖╨░╨┐╤Г╤Б╨║╨░╨╗╤Б╤П run_ALL_tests.sh; ╨┐╨╡╤А╨╡╨╜╨╡╤Б╤С╨╜ ╨▓
  `tests/Manual/`. ╨Э╨╛╨▓╤Л╨╣ test_game_packet_routing.php 21/21 (╤А╨╡╨░╨╗╤М╨╜╤Л╨╣ WS
  ╨┐╤А╨╛╤В╨╕╨▓ ╨╢╨╕╨▓╨╛╨│╨╛ server.php, ╨▓╨║╨╗╤О╤З╨░╤П ╤Б╨║╨▓╨╛╨╖╨╜╤Г╤О ╨┐╤А╨╛╨▓╨╡╤А╨║╤Г FIX-9: disconnect тЖТ
  reconnect ╤Б ╨╜╨╛╨▓╨╛╨│╨╛ ╤Б╨╛╨╡╨┤╨╕╨╜╨╡╨╜╨╕╤П тЖТ ╤Г╤Б╨┐╨╡╤И╨╜╤Л╨╣ draw_barrel). test_reconnect.php
  20/20 (╨▒╤Л╨╗╨╛ 15, +5 assertions ╨┐╨╛╨┤ FIX-9). ╨Я╨╛╨╗╨╜╤Л╨╣ ╤А╨╡╨│╤А╨╡╤Б╤Б 0 failed.
- 2026-07-23 тАФ EPIC-10.4 Accepted: room_list/create_room/join_room/
  leave_room ╨┐╨╛╨┤╨║╨╗╤О╤З╨╡╨╜╤Л ╨║ LobbyHandler (LobbyService EPIC-2.x ╤Г╨╢╨╡
  ╤Б╤Г╤Й╨╡╤Б╤В╨▓╨╛╨▓╨░╨╗ тАФ dependency wiring + routing). ╨Э╨╛╨▓╤Л╨╣ LobbyHandler.php
  (thin wrapper). Router-level guard ┬лAlready in a room┬╗ ╨┤╨╗╤П
  create_room/join_room ╤З╨╡╤А╨╡╨╖ RoomManager::findRoomIdByConnId().
  ╨Э╨╛╨▓╤Л╨╣ test_lobby_packet_routing.php 22/22 (╤А╨╡╨░╨╗╤М╨╜╤Л╨╣ WS ╨┐╤А╨╛╤В╨╕╨▓ ╨╢╨╕╨▓╨╛╨│╨╛
  server.php). test_auth_packet_routing.php TEST 2 ╨╛╨▒╨╜╨╛╨▓╨╗╤С╨╜ ╨┐╨╛╨┤
  room_joined. ╨Я╨╛╨╗╨╜╤Л╨╣ ╤А╨╡╨│╤А╨╡╤Б╤Б 0 failed.
- 2026-07-22 тАФ EPIC-10.3 Accepted + FIX-8: register/login/reconnect
  ╨┐╨╛╨┤╨║╨╗╤О╤З╨╡╨╜╤Л ╨║ AuthHandler (dependency wiring ╨▓ onWorkerStart, routing ╨▓
  onMessage). ╨Э╨░╨╣╨┤╨╡╨╜ ╨╕ ╨╕╤Б╨┐╤А╨░╨▓╨╗╨╡╨╜ FIX-8 ╨▓ ╨┐╤А╨╛╤Ж╨╡╤Б╤Б╨╡: AuthService::login()
  ╨╜╨╕╨║╨╛╨│╨┤╨░ ╨╜╨╡ ╤Г╤Б╤В╨░╨╜╨░╨▓╨╗╨╕╨▓╨░╨╗ $connection->userId (╤В╨╛╨╗╤М╨║╨╛ $worker->
  userConnections) тАФ ╨▒╨╡╨╖ ╤Д╨╕╨║╤Б╨░ auth_required guard (ADR-006) ╨╜╨░╨▓╤Б╨╡╨│╨┤╨░
  ╨▒╨╗╨╛╨║╨╕╤А╨╛╨▓╨░╨╗ ╨▒╤Л ╨╗╤О╨▒╨╛╨╡ ╨┤╨╡╨╣╤Б╤В╨▓╨╕╨╡ ╨┐╨╛╤Б╨╗╨╡ ╤Г╤Б╨┐╨╡╤И╨╜╨╛╨│╨╛ ╨╗╨╛╨│╨╕╨╜╨░. ╨Э╨╛╨▓╤Л╨╣
  AuthHandler::bindConnection() helper, ╨▓╤Л╨╖╤Л╨▓╨░╨╡╤В╤Б╤П ╨╕╨╖ handleRegister()/
  handleLogin(). 55/55 test_auth_integration.php (╨▒╤Л╨╗╨╛ 48, +7), ╨╜╨╛╨▓╤Л╨╣
  test_auth_packet_routing.php 18/18 (╤А╨╡╨░╨╗╤М╨╜╤Л╨╣ WS ╨┐╤А╨╛╤В╨╕╨▓ ╨╢╨╕╨▓╨╛╨│╨╛
  server.php, ╨▓╨║╨╗╤О╤З╨░╤П ╤Б╨║╨▓╨╛╨╖╨╜╤Г╤О ╨┐╤А╨╛╨▓╨╡╤А╨║╤Г FIX-8 ╤З╨╡╤А╨╡╨╖ ╨╜╨░╤Б╤В╨╛╤П╤Й╨╕╨╣ router).
  ╨Я╨╛╨╗╨╜╤Л╨╣ ╤А╨╡╨│╤А╨╡╤Б╤Б 0 failed.
- 2026-07-22 тАФ EPIC-10.2 continuation: generic auth_required guard ╨▓
  onMessage (ADR-006) тАФ prompt.md "╨┐╤А╨╛╨▓╨╡╤А╨║╨░ userId ╨┤╨╗╤П ╨▓╤Б╨╡╤Е ╨║╨╡╨╣╤Б╨╛╨▓ ╨║╤А╨╛╨╝╨╡
  register, login, reconnect", ╤А╨╡╨░╨╗╨╕╨╖╨╛╨▓╨░╨╜╨╛ ╨╛╨┤╨╕╨╜ ╤А╨░╨╖ ╨▓ router'╨╡, ╨╜╨╡
  ╨┤╤Г╨▒╨╗╨╕╤А╤Г╨╡╤В╤Б╤П ╨┐╨╛ ╤Е╨╡╨╜╨┤╨╗╨╡╤А╨░╨╝. EPIC-10.2 ╤В╨╡╨┐╨╡╤А╤М ╨┐╨╛╨╗╨╜╨╛╤Б╤В╤М╤О ╨╖╨░╨▓╨╡╤А╤И╤С╨╜.
  18/18 test_server_bootstrap.php (╨▒╤Л╨╗╨╛ 14, +4 тАФ TEST 4 ╤Г╨╢╨╡╤Б╤В╨╛╤З╤С╨╜,
  ╨╜╨╛╨▓╤Л╨╣ TEST 8 ╨╜╨░ exempt-╤Б╨┐╨╕╤Б╨╛╨║), ╨┐╨╛╨╗╨╜╤Л╨╣ ╤А╨╡╨│╤А╨╡╤Б╤Б 0 failed.
- 2026-07-22 тАФ EPIC-10.2 (╤З╨░╤Б╤В╨╕╤З╨╜╨╛, ╨┐╨╛ ╤А╨╡╤И╨╡╨╜╨╕╤О ╨┐╨╛╨╗╤М╨╖╨╛╨▓╨░╤В╨╡╨╗╤П): ╤А╨╡╨░╨╗╨╕╨╖╨╛╨▓╨░╨╜
  ╤В╨╛╨╗╤М╨║╨╛ connection-level MAX_TOTAL_PLAYERS gate тАФ error.server_full + WS
  close code 4001 ╨▓ onWebSocketConnected (ADR-005, closeWithCode() helper,
  ╤А╤Г╤З╨╜╨░╤П ╤Б╨▒╨╛╤А╨║╨░ close-╤Д╤А╨╡╨╣╨╝╨░ тАФ ╨│╨╛╤В╨╛╨▓╨╛╨│╨╛ API ╨▓ ╨╕╤Б╨┐╨╛╨╗╤М╨╖╤Г╨╡╨╝╨╛╨╣ ╨▓╨╡╤А╤Б╨╕╨╕ Workerman
  ╨╜╨╡╤В). Generic auth_required guard ╨▓ router'╨╡ ╤Б╨╛╨╖╨╜╨░╤В╨╡╨╗╤М╨╜╨╛ ╨╛╤В╨╗╨╛╨╢╨╡╨╜.
  14/14 test_server_bootstrap.php (╨▒╤Л╨╗╨╛ 8, +6 тАФ TEST 7 ╤З╨╡╤А╨╡╨╖ 150 ╤А╨╡╨░╨╗╤М╨╜╤Л╤Е
  TCP+WS ╤Б╨╛╨╡╨┤╨╕╨╜╨╡╨╜╨╕╨╣), ╨┐╨╛╨╗╨╜╤Л╨╣ ╤А╨╡╨│╤А╨╡╤Б╤Б 0 failed.
- 2026-07-22 тАФ FIX-7 Accepted: ╤Г╤Б╤В╤А╨░╨╜╨╡╨╜╨╛ ╤Б╨╝╨╡╤И╨╡╨╜╨╕╨╡ error.server_full (╨│╨╗╨╛╨▒╨░╨╗╤М╨╜╤Л╨╣
  ╨╗╨╕╨╝╨╕╤В) ╨╕ ╨╖╨░╨┐╨╛╨╗╨╜╨╡╨╜╨╜╨╛╤Б╤В╨╕ ╨╛╤В╨┤╨╡╨╗╤М╨╜╨╛╨╣ ╨║╨╛╨╝╨╜╨░╤В╤Л тАФ ╨▓╨▓╨╡╨┤╤С╨╜ ╨╛╤В╨┤╨╡╨╗╤М╨╜╤Л╨╣ ╨║╨╛╨┤
  error.room_full (ADR-004), ╨┐╨╛╤А╤П╨┤╨╛╨║ ╨┐╤А╨╛╨▓╨╡╤А╨╛╨║ ╨▓ handleJoinRoom() ╨╕╨╖╨╝╨╡╨╜╤С╨╜ ╨╜╨░
  server-capacity-first. 91/91 lobby ╤В╨╡╤Б╤В╨╛╨▓ (╨▒╤Л╨╗╨╛ 90, +1 regression-╤В╨╡╤Б╤В ╨╜╨░
  ╨┐╨╛╤А╤П╨┤╨╛╨║), ╨┐╨╛╨╗╨╜╤Л╨╣ ╤А╨╡╨│╤А╨╡╤Б╤Б ╨┐╨╛ ╨▓╤Б╨╡╨╝ tests/Manual/*.php тАФ 0 failed.
- 2026-07-21 тАФ EPIC-10.1 Packet validation ╨╖╨░╨▓╨╡╤А╤И╤С╨╜: ADR-003 ╤Д╨╛╤А╨╝╨░╨╗╨╕╨╖╤Г╨╡╤В rate limiting (>15 ╨┐╨░╨║╨╡╤В╨╛╨▓/╤Б╨╡╨║/╤Б╨╛╨╡╨┤╨╕╨╜╨╡╨╜╨╕╨╡ тЖТ ╨╖╨░╨║╤А╤Л╤В╨╕╨╡ ╨▒╨╡╨╖ error-╨┐╨░╨║╨╡╤В╨░, ╤Б╤З╨╕╤В╨░╨╡╤В ╨Т╨б╨Х ╨▓╤Е╨╛╨┤╤П╤Й╨╕╨╡ ╤Б╨╛╨╛╨▒╤Й╨╡╨╜╨╕╤П) ╨╕ invalid-JSON policy (error.invalid_json, ╨▒╨╡╨╖ ╤А╨░╨╖╤А╤Л╨▓╨░ тАФ ╤А╨╡╤И╨╡╨╜╨╛ ╨▓ ╨┐╨╛╨╗╤М╨╖╤Г ANCHOR_PROTOCOL.md, ╨┐╨╛╨┤╨║╤А╨╡╨┐╨╗╨╡╨╜╨╛ ╨┐╤А╨╡╤Ж╨╡╨┤╨╡╨╜╤В╨╛╨╝ error.server_full). ANCHOR_CORE.md/ANCHOR_PROTOCOL.md ╨╛╨▒╨╜╨╛╨▓╨╗╨╡╨╜╤Л (Connection Runtime Fields, Global Constants, ╤Б╨╡╨╝╨░╨╜╤В╨╕╨║╨░ error.invalid_json). ╨Ю╨▒╨░ KNOWN GAP ╨╕╨╖ ╨░╤Г╨┤╨╕╤В╨░ ╨┐╤А╨╛╤В╨╛╨║╨╛╨╗╨░ (2026-07-03) ╨╖╨░╨║╤А╤Л╤В╤Л ╨║╨░╨║ RESOLVED. ╨Я╨╛╨┐╤Г╤В╨╜╨╛ ╨╛╨▒╨╜╨░╤А╤Г╨╢╨╡╨╜╤Л ╨╕ ╨╕╤Б╨┐╤А╨░╨▓╨╗╨╡╨╜╤Л ╤Б╨╗╤Г╤З╨░╨╣╨╜╨╛ ╨╖╨░╨║╨╛╨╝╨╝╨╕╤З╨╡╨╜╨╜╤Л╨╡ ╤А╨░╨╜╤В╨░╨╣╨╝-╨░╤А╤В╨╡╤Д╨░╨║╤В╤Л (game.db-shm/game.db-wal/workerman.*.pid) тАФ ╨┤╨╛╨▒╨░╨▓╨╗╨╡╨╜ .gitignore. ╨Т╨╡╤А╨╕╤Д╨╕╤Ж╨╕╤А╨╛╨▓╨░╨╜╨╛ 11/11 PASSED ╤З╨╡╤А╨╡╨╖ ╤А╨╡╨░╨╗╤М╨╜╤Л╨╣ WebSocket-╨║╨╗╨╕╨╡╨╜╤В, 5 ╨│╤А╨░╨╜╨╕╤З╨╜╤Л╤Е ╤Б╤Ж╨╡╨╜╨░╤А╨╕╨╡╨▓ (╤А╨╛╨▓╨╜╨╛ ╨╜╨░ ╨╗╨╕╨╝╨╕╤В╨╡, ╨┐╤А╨╡╨▓╤Л╤И╨╡╨╜╨╕╨╡ ╨╜╨░ 1, ping ╤Б╤З╨╕╤В╨░╨╡╤В╤Б╤П ╨╜╨░╤А╨░╨▓╨╜╨╡, ╤Б╨▒╤А╨╛╤Б ╨╛╨║╨╜╨░, ╨╡╨┤╨╕╨╜╨╕╤З╨╜╤Л╨╣ ╨╜╨╡╨▓╨░╨╗╨╕╨┤╨╜╤Л╨╣ ╨┐╨░╨║╨╡╤В). ╨Я╨╛╨╗╨╜╤Л╨╣ ╤А╨╡╨│╤А╨╡╤Б╤Б тАФ 25/25 tests/Manual/*.php.
---

## KNOWN GAPS / NOT VERIFIED

- тЪая╕П OPEN (ADR-033 / EPIC-033C, 2026-08-23): Some load/stress scripts historically
  register via the real registration path against the environment `game.db`
  instead of isolating via `LOTTO_DB_PATH` / `LOTTO_TEST_CONFIG`, leaving junk
  usernames (`steady*`, `ramp_*`, `login_banned`, тАж) in production SQLite.
  Admin bulk delete is the operational cleanup path; root-cause isolation of
  load-test DB writes is a separate follow-up (not fixed in Epic C).

- тЪая╕П OPEN (2026-08-23): ╨а╨░╨╖╨╛╨▓╤Л╨╣ `SQLITE_MISUSE` (SQLSTATE[HY000]: General
  error: 21 bad parameter or other API misuse) ╨▓ `AuthService::login()` тАФ
  ╤Б╤Л╤А╨╛╨╣ ╤В╨╡╨║╤Б╤В PDO-╨╕╤Б╨║╨╗╤О╤З╨╡╨╜╨╕╤П ╤Г╤В╤С╨║ ╨▓ ╨║╨╗╨╕╨╡╨╜╤В╤Б╨║╨╛╨╡ ╨┐╨╛╨╗╨╡ `message` ╨┐╨░╨║╨╡╤В╨░
  `error` (╨║╨╛╨┤ ╨┐╤А╨╕ ╤Н╤В╨╛╨╝ ╨╛╤Б╤В╨░╨╗╤Б╤П `error.auth_invalid_credentials`,
  ╨╖╨░╨╝╨░╤Б╨║╨╕╤А╨╛╨▓╨░╨▓ ╤А╨╡╨░╨╗╤М╨╜╤Г╤О ╨┐╤А╨╕╤З╨╕╨╜╤Г ╨┐╨╛╨┤ "╨╜╨╡╨▓╨╡╤А╨╜╤Л╨╣ ╨╗╨╛╨│╨╕╨╜ ╨╕╨╗╨╕ ╨┐╨░╤А╨╛╨╗╤М").
  ╨Ю╨▒╨╜╨░╤А╤Г╨╢╨╡╨╜╨╛ ╨┐╤А╨╕ ╨╢╨╕╨▓╨╛╨╝ ╨╗╨╛╨│╨╕╨╜╨╡ ╤З╨╡╤А╨╡╨╖ `wss://rusbingo.ju-87.club/ws`
  (╨┐╨╛╨╗╤М╨╖╨╛╨▓╨░╤В╨╡╨╗╤М `test4`, ╨┐╨░╤А╨╛╨╗╤М ╨┐╨╛╨┤╤В╨▓╨╡╤А╨╢╨┤╤С╨╜ ╨║╨╛╤А╤А╨╡╨║╤В╨╜╤Л╨╝ ╨╜╨╡╨╖╨░╨▓╨╕╤Б╨╕╨╝╨╛╨╣
  ╨┐╤А╨╛╨▓╨╡╤А╨║╨╛╨╣ `password_verify()` ╤З╨╡╤А╨╡╨╖ ╨╛╤В╨┤╨╡╨╗╤М╨╜╤Л╨╣ CLI-╤Б╨║╤А╨╕╨┐╤В тАФ ╤В╨╛ ╨╡╤Б╤В╤М
  ╤Г╤З╤С╤В╨╜╤Л╨╡ ╨┤╨░╨╜╨╜╤Л╨╡ ╨▒╤Л╨╗╨╕ ╨╖╨░╨▓╨╡╨┤╨╛╨╝╨╛ ╨▓╨╡╤А╨╜╤Л).
  ╨г╤Б╤В╤А╨░╨╜╨╡╨╜╨╛ ╨┐╨╡╤А╨╡╨╖╨░╨┐╤Г╤Б╨║╨╛╨╝ `lotto-server.service` тАФ ╨░╨▓╤В╨╛╤А╨╕╨╖╨░╤Ж╨╕╤П
  ╨▓╨╛╤Б╤Б╤В╨░╨╜╨╛╨▓╨╕╨╗╨░╤Б╤М ╨┤╨╗╤П ╨▓╤Б╨╡╤Е ╨┐╨╛╨╗╤М╨╖╨╛╨▓╨░╤В╨╡╨╗╨╡╨╣. ╨в╨╛╤З╨╜╨░╤П ╨┐╤А╨╕╤З╨╕╨╜╨░ ╨Э╨Х ╨┐╨╛╨┤╤В╨▓╨╡╤А╨╢╨┤╨╡╨╜╨░:
  ╨╕╨╜╤Ж╨╕╨┤╨╡╨╜╤В ╤Б╨╛╨▓╨┐╨░╨╗ ╨┐╨╛ ╨▓╤А╨╡╨╝╨╡╨╜╨╕ ╤Б ╨┐╨░╤А╨░╨╗╨╗╨╡╨╗╤М╨╜╤Л╨╝ ╨╖╨░╨┐╤Г╤Б╨║╨╛╨╝ ╤Б╤В╨╛╤А╨╛╨╜╨╜╨╡╨│╨╛
  CLI-╤Б╨║╤А╨╕╨┐╤В╨░ (`change_admin_password.php`) ╨╕ ╨╛╤В╨┤╨╡╨╗╤М╨╜╨╛╨│╨╛ ╨┤╨╕╨░╨│╨╜╨╛╤Б╤В╨╕╤З╨╡╤Б╨║╨╛╨│╨╛
  `php -r` (╨╜╨╡╨╖╨░╨▓╨╕╤Б╨╕╨╝╨╛╨╡ PDO-╨┐╨╛╨┤╨║╨╗╤О╤З╨╡╨╜╨╕╨╡ ╨║ ╤В╨╛╨╝╤Г ╨╢╨╡ `game.db`), ╤З╤В╨╛
  ╤П╨▓╨╗╤П╨╡╤В╤Б╤П ╨╜╨░╨╕╨▒╨╛╨╗╨╡╨╡ ╨▓╨╡╤А╨╛╤П╤В╨╜╨╛╨╣ ╨┐╤А╨╕╤З╨╕╨╜╨╛╨╣ (╨║╨╛╨╗╨╗╨╕╨╖╨╕╤П ╨▒╨╗╨╛╨║╨╕╤А╨╛╨▓╨╛╨║/╤Б╨╛╤Б╤В╨╛╤П╨╜╨╕╤П
  ╨║╤Н╤И╨░ `PDOStatement` ╨▓ `PreparedStatements::get()`), ╨╜╨╛ ╨╜╨╡ ╨▒╤Л╨╗╨░
  ╨╖╨░╤Д╨╕╨║╤Б╨╕╤А╨╛╨▓╨░╨╜╨░ ╨╗╨╛╨│╨░╨╝╨╕/╨▓╨╡╤А╤Б╨╕╤П╨╝╨╕ ╨Ф╨Ю ╤А╨╡╤Б╤В╨░╤А╤В╨░ тАФ ╤Н╤В╨░ ╤Г╨╗╨╕╨║╨░ ╨┐╨╛╤В╨╡╤А╤П╨╜╨░.
  ╨Р╤А╤Е╨╕╤В╨╡╨║╤В╤Г╤А╨╜╨╛ ╤А╨╡╤Б╤В╨░╤А╤В ╤Б╨╡╤А╨▓╨╕╤Б╨░ ╨Э╨Х ╨┤╨╛╨╗╨╢╨╡╨╜ ╤В╤А╨╡╨▒╨╛╨▓╨░╤В╤М╤Б╤П ╨┐╨╛╤Б╨╗╨╡
  `change_admin_password.php` (╤Б╨║╤А╨╕╨┐╤В ╨┤╨╡╨╗╨░╨╡╤В `BEGIN IMMEDIATE
  TRANSACTION` тЖТ `UPDATE` тЖТ `COMMIT` ╤Б╤В╤А╨╛╨│╨╛ in-place, ╨▒╨╡╨╖ ╨┐╨╡╤А╨╡╤Б╨╛╨╖╨┤╨░╨╜╨╕╤П
  ╤Д╨░╨╣╨╗╨░ тАФ ╤И╤В╨░╤В╨╜╤Л╨╣ ╤Б╤Ж╨╡╨╜╨░╤А╨╕╨╣ ╨┤╨╗╤П `PRAGMA journal_mode=WAL`, ╤З╨╕╤В╨░╤В╨╡╨╗╤М ╨╕
  ╨┐╨╕╤Б╨░╤В╨╡╨╗╤М ╨┤╨╛╨╗╨╢╨╜╤Л ╤Б╨╛╤Б╤Г╤Й╨╡╤Б╤В╨▓╨╛╨▓╨░╤В╤М ╨▒╨╡╨╖ ╤А╨╡╤Б╤В╨░╤А╤В╨░).
  ╨в╤А╨╡╨▒╤Г╨╡╤В╤Б╤П ╨┐╤А╨╕ ╨┐╨╛╨▓╤В╨╛╤А╨╡╨╜╨╕╨╕: ╨Э╨Х ╨┐╨╡╤А╨╡╨╖╨░╨┐╤Г╤Б╨║╨░╤В╤М ╤Б╨╡╤А╨▓╨╕╤Б ╤Б╤А╨░╨╖╤Г тАФ ╤Б╨╜╨░╤З╨░╨╗╨░
  ╤Б╨╜╤П╤В╤М `grep -B2 -A2 "SQLSTATE\|bad parameter" logs/server.log`,
  ╨▓╨╡╤А╤Б╨╕╨╕ `php --ri pdo_sqlite`/`php --ri sqlite3`, ╨╕ ╨┐╤А╨╛╨▓╨╡╤А╨╕╤В╤М, ╨╜╨╡ ╨▒╤Л╨╗╨╛
  ╨╗╨╕ ╨▓ ╤Н╤В╨╛╤В ╨╝╨╛╨╝╨╡╨╜╤В ╨┐╨░╤А╨░╨╗╨╗╨╡╨╗╤М╨╜╨╛╨│╨╛ ╤Б╤В╨╛╤А╨╛╨╜╨╜╨╡╨│╨╛ ╨┐╤А╨╛╤Ж╨╡╤Б╤Б╨░ ╤Б ╨╛╤В╨║╤А╤Л╤В╤Л╨╝
  ╤Б╨╛╨╡╨┤╨╕╨╜╨╡╨╜╨╕╨╡╨╝ ╨║ `game.db`. ╨Ю╤В╨┤╨╡╨╗╤М╨╜╨╛: `AuthHandler::handleLogin()`
  (╤Б╤В╤А╨╛╨║╨░ `$clientMsg = $msg === 'Auth rate limited' ? ... : $msg;`)
  ╨┐╤А╨╛╨▒╤А╨░╤Б╤Л╨▓╨░╨╡╤В `$e->getMessage()` ╨╗╤О╨▒╨╛╨│╨╛ ╨╜╨╡-`Auth rate limited`
  ╨╕╤Б╨║╨╗╤О╤З╨╡╨╜╨╕╤П ╨║╨╗╨╕╨╡╨╜╤В╤Г ╨┤╨╛╤Б╨╗╨╛╨▓╨╜╨╛ тАФ ╨▓╨║╨╗╤О╤З╨░╤П ╤Б╤Л╤А╤Л╨╡ PDO-╨╛╤И╨╕╨▒╨║╨╕ ╨┐╤А╨╕ ╨╕╤Е
  ╨▓╨╛╨╖╨╜╨╕╨║╨╜╨╛╨▓╨╡╨╜╨╕╨╕; ╤Б╤В╨╛╨╕╤В ╤А╨░╤Б╤Б╨╝╨╛╤В╤А╨╡╤В╤М ╨╛╤В╨┤╨╡╨╗╤М╨╜╤Л╨╣ ADR ╨╜╨░ ╨╝╨░╤Б╨║╨╕╤А╨╛╨▓╨║╤Г ╨Ы╨о╨С╨Ю╨У╨Ю
  ╨╜╨╡╨┐╤А╨╡╨┤╨▓╨╕╨┤╨╡╨╜╨╜╨╛╨│╨╛ ╨╕╤Б╨║╨╗╤О╤З╨╡╨╜╨╕╤П ╨▓ `login()` ╨┐╨╛╨┤ `error.auth_invalid_credentials`
  ╤Б ╨╛╨▒╤Й╨╕╨╝ ╤В╨╡╨║╤Б╤В╨╛╨╝, ╨░ ╨╜╨╡ ╤В╨╛╨╗╤М╨║╨╛ `Auth rate limited` (ADR-028).

- тЪая╕П OPEN (EPIC-13.6, 2026-07-28): Reconnect mid-turn тАФ reconnecting active
  drawer does not receive `your_turn`; frontend `onReconnectState` explicitly
  disables draw button (`setDrawButton(false, false)`) and `reconnect_state`
  carries no active-drawer field. Requires follow-up Epic (protocol change or
  `your_turn` resend) before implementation тАФ not reproduced live yet.

- тЪая╕П OPEN (╨╜╨╕╨╖╨║╨╕╨╣ ╨┐╤А╨╕╨╛╤А╨╕╤В╨╡╤В, ╨╜╨░╨╣╨┤╨╡╨╜╨╛ ╨┐╤А╨╕ FIX-12): real-WS-client
  subprocess-╤В╨╡╤Б╤В╤Л (test_auth_packet_routing.php, test_lobby_packet_routing.php,
  test_game_packet_routing.php, test_admin_packet_routing.php,
  test_session_lifecycle.php, test_packet_validation.php,
  test_server_bootstrap.php) ╨╖╨░╨┐╤Г╤Б╨║╨░╤О╤В ╨╜╨░╤Б╤В╨╛╤П╤Й╨╕╨╣ `php server.php start` тАФ
  ╨╡╨│╨╛ Logger ╨║╨╛╤А╤А╨╡╨║╤В╨╜╨╛ ╨┐╨╕╤И╨╡╤В ╨▓ ╤А╨╡╨░╨╗╤М╨╜╤Л╨╣ logs/server.log, ╤В.╨║. ╤Н╤В╨╛ ╨╕ ╨╡╤Б╤В╤М
  ╨╜╨░╤Б╤В╨╛╤П╤Й╨╕╨╣ ╤Б╨╡╤А╨▓╨╡╤А. ╨н╤В╨╛ ╨╛╤Б╤В╨░╨▓╨╗╤П╨╡╤В ╨▓ ╨┐╤А╨╛╨┤╨░╨║╤И╨╜-╨╗╨╛╨│╨╡ ╤В╨╡╤Б╤В╨╛╨▓╤Л╨╡ INFO/WARNING
  ╤Б╤В╤А╨╛╨║╨╕ ╤Б ╤В╨╡╤Б╤В╨╛╨▓╤Л╨╝╨╕ ╨╕╨╝╨╡╨╜╨░╨╝╨╕ ╨┐╨╛╨╗╤М╨╖╨╛╨▓╨░╤В╨╡╨╗╨╡╨╣ (fix10_user1, e106_admin ╨╕
  ╤В.╨┐.) тАФ ╨▒╨╡╨╖╨▓╤А╨╡╨┤╨╜╤Л╨╣ ╤И╤Г╨╝, ╨╛╤В╨╗╨╕╤З╨╕╨╝╤Л╨╣ ╨╜╨░ ╨│╨╗╨░╨╖ ╨╛╤В ╤А╨╡╨░╨╗╤М╨╜╤Л╤Е ╤Б╨╛╨▒╤Л╤В╨╕╨╣, ╨╜╨╡ ╤В╨░
  ╨║╨░╤В╨╡╨│╨╛╤А╨╕╤П ╨┐╤А╨╛╨▒╨╗╨╡╨╝╤Л, ╤З╤В╨╛ ╨▓╤Л╨╖╨▓╨░╨╗╨░ ╨╕╨╜╤Ж╨╕╨┤╨╡╨╜╤В FIX-12 (╨╗╨╛╨╢╨╜╤Л╨╣ ERROR). ╨Я╨╛╨╗╨╜╨░╤П
  ╨╕╨╖╨╛╨╗╤П╤Ж╨╕╤П ╨┐╨╛╤В╤А╨╡╨▒╨╛╨▓╨░╨╗╨░ ╨▒╤Л ╤Б╨┤╨╡╨╗╨░╤В╤М ╨┐╤Г╤В╤М ╨╗╨╛╨│╨╕╤А╨╛╨▓╨░╨╜╨╕╤П server.php
  ╨║╨╛╨╜╤Д╨╕╨│╤Г╤А╨╕╤А╤Г╨╡╨╝╤Л╨╝ (╨┐╨╡╤А╨╡╨╝╨╡╨╜╨╜╨░╤П ╨╛╨║╤А╤Г╨╢╨╡╨╜╨╕╤П, ╨┐╨╛ ╤Г╨╝╨╛╨╗╤З╨░╨╜╨╕╤О тАФ ╤В╨╡╨║╤Г╤Й╨╕╨╣ ╨┐╤Г╤В╤М) ╨╕
  ╨╛╨▒╨╜╨╛╨▓╨╕╤В╤М ╨▓╤Б╨╡ ╤Б╨╡╨╝╤М ╤В╨╡╤Б╤В╨╛╨▓-╤А╨░╨╜╨╜╨╡╤А╨╛╨▓ тАФ ╨▒╨╛╨╗╨╡╨╡ ╨║╤А╤Г╨┐╨╜╨╛╨╡ ╨╕╨╖╨╝╨╡╨╜╨╡╨╜╨╕╨╡,
  ╨╖╨░╤В╤А╨░╨│╨╕╨▓╨░╤О╤Й╨╡╨╡ ╨┐╤А╨╛╨┤╨░╨║╤И╨╜-╨║╨╛╨┤ ╤Б╨╡╤А╨▓╨╡╤А╨░, ╨╛╤Б╤В╨░╨▓╨╗╨╡╨╜╨╛ ╨╜╨░ ╤П╨▓╨╜╨╛╨╡ ╤А╨╡╤И╨╡╨╜╨╕╨╡
  ╨┐╨╛╨╗╤М╨╖╨╛╨▓╨░╤В╨╡╨╗╤П.

- тЬЕ RESOLVED (2026-07-03): docs/ANCHOR_PROJECT_STATUS.md ╤Г╨┤╨░╨╗╤С╨╜ тАФ ╤Д╨░╨╣╨╗ ╨╜╨╡
  ╨╛╨▒╨╜╨╛╨▓╨╗╤П╨╗╤Б╤П ╤Б ╤Б╨░╨╝╨╛╨│╨╛ ╨╜╨░╤З╨░╨╗╨░ ╨┐╤А╨╛╨╡╨║╤В╨░ (╨╖╨░╨╝╨╛╤А╨╛╨╢╨╡╨╜ ╨╜╨░ ╤Б╨╛╤Б╤В╨╛╤П╨╜╨╕╨╕ "EPIC-1.1,
  Lobby/WebSocket/Economy: Not implemented"), ╨┐╤А╨╕ ╤Н╤В╨╛╨╝ ╤Б╨░╨╝ ╤Д╨░╨╣╨╗ ╨┐╤А╨╡╨┤╨┐╨╕╤Б╤Л╨▓╨░╨╗
  ╨▒╤Г╨┤╤Г╤Й╨╕╨╝ ╨╝╨╛╨┤╨╡╨╗╤П╨╝ ╤З╨╕╤В╨░╤В╤М ╨╡╨│╨╛ ╨║╨░╨║ ╨╛╨▒╤П╨╖╨░╤В╨╡╨╗╤М╨╜╤Л╨╣ ╨║╨╛╨╜╤В╨╡╨║╤Б╤В. ╨а╨╕╤Б╨║ ╨║╨░╤В╨░╤Б╤В╤А╨╛╤Д╨╕╤З╨╡╤Б╨║╨╛╨╣
  ╨┐╤Г╤В╨░╨╜╨╕╤Ж╤Л ╨┤╨╗╤П ╨╜╨╛╨▓╨╛╨╣ ╤Б╨╡╤Б╤Б╨╕╨╕. ANCHOR_RULES.md Part 19 (Context Recovery Rule)
  ╤Г╨╢╨╡ ╨║╨╛╤А╤А╨╡╨║╤В╨╜╨╛ ╨╛╨┐╤А╨╡╨┤╨╡╨╗╤П╨╡╤В 5 ╨░╨▓╤В╨╛╤А╨╕╤В╨╡╤В╨╜╤Л╤Е ╨┤╨╛╨║╤Г╨╝╨╡╨╜╤В╨╛╨▓ ╨▒╨╡╨╖ ╨╜╨╡╨│╨╛.
- тЬЕ RESOLVED (ADR-003, EPIC-10.1, 2026-07-21): docs/prompt.md ╤Б╨╛╨┤╨╡╤А╨╢╨░╨╗ ╨┤╨▓╨░
  ╤В╤А╨╡╨▒╨╛╨▓╨░╨╜╨╕╤П, ╨╛╤В╤Б╤Г╤В╤Б╤В╨▓╤Г╤О╤Й╨╕╨╡ ╨▓╨╛ ╨▓╤Б╨╡╤Е ANCHOR-╨┤╨╛╨║╤Г╨╝╨╡╨╜╤В╨░╤Е тАФ (a) rate limiting
  ">15 ╨┐╨░╨║╨╡╤В╨╛╨▓/╤Б╨╡╨║ тАФ ╤А╨░╨╖╤А╤Л╨▓" ╨╕ (b) ╨┐╤А╨╛╤В╨╕╨▓╨╛╤А╨╡╤З╨╕╨╡ ╨┐╨╛ ╨╛╨▒╤А╨░╨▒╨╛╤В╨║╨╡ ╨╜╨╡╨▓╨░╨╗╨╕╨┤╨╜╨╛╨│╨╛
  JSON (prompt.md "╨╖╨░╨║╤А╤Л╤В╤М ╤Б╨╛╨╡╨┤╨╕╨╜╨╡╨╜╨╕╨╡" vs ANCHOR_PROTOCOL.md error.invalid_json).
  ╨д╨╛╤А╨╝╨░╨╗╨╕╨╖╨╛╨▓╨░╨╜╨╛ ╨▓ docs/ADR/003-rate-limiting-and-invalid-json-policy.md:
  rate limiting ╤А╨╡╨░╨╗╨╕╨╖╨╛╨▓╨░╨╜ ╨║╨░╨║ ╨╡╤Б╤В╤М (server.php, Constants::
  RATE_LIMIT_PACKETS_PER_WINDOW/RATE_LIMIT_WINDOW_SECONDS); invalid-JSON
  policy ╤А╨╡╤И╨╡╨╜╨░ ╨▓ ╨┐╨╛╨╗╤М╨╖╤Г ANCHOR_PROTOCOL.md (error-╨┐╨░╨║╨╡╤В, ╨▒╨╡╨╖ ╤А╨░╨╖╤А╤Л╨▓╨░) тАФ
  ╨┐╨╛╨┤╨║╤А╨╡╨┐╨╗╨╡╨╜╨╛ ╤Г╨╢╨╡ ╤А╨╡╨░╨╗╨╕╨╖╨╛╨▓╨░╨╜╨╜╤Л╨╝ ╨┐╤А╨╡╤Ж╨╡╨┤╨╡╨╜╤В╨╛╨╝ error.server_full. ╨Ф╨╡╤В╨░╨╗╨╕ тАФ
  ╤Б╨╝. ╨╖╨░╨┐╨╕╤Б╤М [DONE] EPIC-10.1 ╨▓ ╨╜╨░╤З╨░╨╗╨╡ ╤Д╨░╨╣╨╗╨░.
- тЬЕ RESOLVED (ADR-007, EPIC-11.5, 2026-07-27): ╨┐╨░╨║╨╡╤В afk_warning ╨┤╨╛╨▒╨░╨▓╨╗╨╡╨╜
  ╨▓ ANCHOR_CORE.md ┬з Protocol Packet Types ╨╕ ANCHOR_PROTOCOL.md ┬з Turn System.
  ╨Я╨╛╨▓╨╡╨┤╨╡╨╜╨╕╨╡ ╨▒╤Л╨╗╨╛ ╨║╨╛╤А╤А╨╡╨║╤В╨╜╤Л╨╝ ╤Б EPIC-8.3; ╨╖╨░╨║╤А╤Л╤В ╨┤╨╛╨║╤Г╨╝╨╡╨╜╤В╨░╤Ж╨╕╨╛╨╜╨╜╤Л╨╣ ╨┤╨╛╨╗╨│ W1.
- тЪая╕П OPEN (╨╜╨╕╨╖╨║╨╕╨╣ ╨┐╤А╨╕╨╛╤А╨╕╤В╨╡╤В, roadmap-╨┤╨╛╨╗╨│): ╨┐╨░╨║╨╡╤В admin_stats_data ╨╛╨▒╤К╤П╨▓╨╗╨╡╨╜
  ╨▓ ANCHOR_PROTOCOL.md ╨╕ ╨▓ ╤А╨╡╨╡╤Б╤В╤А╨╡ ANCHOR_CORE.md, ╨╜╨╛ ╨╜╨╕ ╤А╨░╨╖╤Г ╨╜╨╡ ╤А╨╡╨░╨╗╨╕╨╖╨╛╨▓╨░╨╜
  ╨╕ ╨╜╨╡ ╨╜╨░╨╖╨╜╨░╤З╨╡╨╜ ╨╜╨╕ ╨╛╨┤╨╜╨╛╨╝╤Г Epic ╨▓ ROADMAP.md (EPIC-9.x ╨┐╨╛╨║╤А╤Л╨╗ ╤В╨╛╨╗╤М╨║╨╛
  admin_logs_data). ╨Э╤Г╨╢╨╜╨╛ ╨╗╨╕╨▒╨╛ ╨╖╨░╨▓╨╡╤Б╤В╨╕ Epic, ╨╗╨╕╨▒╨╛ ╤Д╨╛╤А╨╝╨░╨╗╤М╨╜╨╛ ╨╕╤Б╨║╨╗╤О╤З╨╕╤В╤М ╨╕╨╖
  ╨┐╤А╨╛╤В╨╛╨║╨╛╨╗╨░.
- тЪая╕П OPEN (╨╜╨╕╨╖╨║╨╕╨╣ ╨┐╤А╨╕╨╛╤А╨╕╤В╨╡╤В, ╨┤╨╛╨║╤Г╨╝╨╡╨╜╤В╨░╤Ж╨╕╨╛╨╜╨╜╤Л╨╣ ╨┤╨╛╨╗╨│, ╨╜╨░╨╣╨┤╨╡╨╜╨╛ EPIC-10.7):
  ╨║╨╛╨┤ ╨╛╤И╨╕╨▒╨║╨╕ `error.banned` ╨╛╨▒╤К╤П╨▓╨╗╨╡╨╜ ╨▓ ╤А╨╡╨╡╤Б╤В╤А╨╡ Error Packet Codes
  (ANCHOR_PROTOCOL.md) ╨╜╨╛ ╨╜╨╕╨│╨┤╨╡ ╨╜╨╡ ╨╕╤Б╨┐╨╛╨╗╤М╨╖╤Г╨╡╤В╤Б╤П тАФ ╨╜╨╛╨╗╤М usage sites ╨┐╨╛
  ╨▓╤Б╨╡╨╝╤Г src/ ╨╕ server.php. ╨Э╨╡ ╤Д╤Г╨╜╨║╤Ж╨╕╨╛╨╜╨░╨╗╤М╨╜╤Л╨╣ ╨┐╤А╨╛╨▒╨╡╨╗: ╨▓╤Л╨┤╨╡╨╗╨╡╨╜╨╜╤Л╨╣ ╨┐╨░╨║╨╡╤В
  `banned` (`{"type":"banned","until":...}`) ╤Г╨╢╨╡ ╨┐╨╛╨║╤А╤Л╨▓╨░╨╡╤В ╨║╨░╨╢╨┤╤Л╨╣ ╨┐╤Г╤В╤М
  ╨╛╤В╨║╨░╨╖╨░ ╨┐╨╛ ╨▒╨░╨╜╤Г (login, reconnect тАФ ╤Б FIX-11, admin-╤Г╨▓╨╡╨┤╨╛╨╝╨╗╨╡╨╜╨╕╨╡).
  ╨Ф╨╛╨║╤Г╨╝╨╡╨╜╤В╨╕╤А╨╛╨▓╨░╨╜ ╨║╨░╨║ reserved/unused ╨▓ ADR-007 (EPIC-11.5). ╨в╤А╨╡╨▒╤Г╨╡╤В
  ╨╗╨╕╨▒╨╛ ╤П╨▓╨╜╨╛╨│╨╛ ╨╜╨░╨╖╨╜╨░╤З╨╡╨╜╨╕╤П ╨╕╤Б╨┐╨╛╨╗╤М╨╖╨╛╨▓╨░╨╜╨╕╤П, ╨╗╨╕╨▒╨╛ ╤Д╨╛╤А╨╝╨░╨╗╤М╨╜╨╛╨│╨╛ ╨╕╤Б╨║╨╗╤О╤З╨╡╨╜╨╕╤П ╨╕╨╖
  ╤А╨╡╨╡╤Б╤В╤А╨░ (╤В╨╛╤В ╨╢╨╡ ╨▓╤Л╨▒╨╛╤А, ╤З╤В╨╛ ╤Г╨╢╨╡ ╤Б╤В╨╛╨╕╤В ╨┐╨╡╤А╨╡╨┤ admin_stats_data).

- тЬЕ RESOLVED (FIX-4, 2026-07-03): test_game_start.php/test_victory.php ╨┐╨░╨┤╨░╨╗╨╕ ╨╕╨╖-╨╖╨░
  ╤Г╤Б╤В╨░╤А╨╡╨▓╤И╨╕╤Е ╤Д╨╕╨║╤Б╤В╤Г╤А ╨┐╨╛╤Б╨╗╨╡ ADR-002. ╨г╤Б╤В╤А╨░╨╜╨╡╨╜╨╛ тАФ ╤Б╨╝. ╤Б╨╡╨║╤Ж╨╕╤О PATCHES ┬з FIX-4.
- тЬЕ RESOLVED (FIX-5, 2026-07-03): test_helpers_runner.php Scenario 2 ╨░╤Б╤Б╨╡╤А╤В╨╕╨╗ ╨║╨╛╨╜╤В╤А╨░╨║╤В
  ╨┤╨╛ FIX-1. ╨г╤Б╤В╤А╨░╨╜╨╡╨╜╨╛ тАФ ╤Б╨╝. ╤Б╨╡╨║╤Ж╨╕╤О PATCHES ┬з FIX-5.

- composer.json ╨╜╨╡ ╨┐╨╡╤А╨╡╨┐╤А╨╛╨▓╨╡╤А╤П╨╗╤Б╤П ╨▓ ╤В╨╡╨║╤Г╤Й╨╡╨╣ ╤Б╨╡╤Б╤Б╨╕╨╕.
- ReconnectTokenService ╤Б╤Г╤Й╨╡╤Б╤В╨▓╤Г╨╡╤В, ╨╜╨╛ ╨┐╨╛╨║╨░ ╨╜╨╡ ╨╕╤Б╨┐╨╛╨╗╤М╨╖╤Г╨╡╤В╤Б╤П.
- SessionService ╤В╤А╨╡╨▒╤Г╨╡╤В ╨║╨╛╤Б╨╝╨╡╤В╨╕╤З╨╡╤Б╨║╨╛╨╣ ╨╛╤З╨╕╤Б╤В╨║╨╕ ╤Д╨╛╤А╨╝╨░╤В╨╕╤А╨╛╨▓╨░╨╜╨╕╤П (╨▒╨╡╨╖ ╨╕╨╖╨╝╨╡╨╜╨╡╨╜╨╕╤П ╨╗╨╛╨│╨╕╨║╨╕).
- lobby_afk_timer_id ╨┐╤А╨╕ count<2 ╨╜╨╡ ╨╛╤В╨╝╨╡╨╜╤П╨╡╤В╤Б╤П ╨▓ removePlayerFromLobby тАФ ╤Г╤Б╤В╤А╨░╨╜╤П╨╡╤В╤Б╤П ╨▓ EPIC-2.6.

---

## CURRENT PROJECT STATUS

PHASE 0 тАФ FOUNDATION: COMPLETE
PHASE 1 тАФ AUTHENTICATION: COMPLETE
PHASE 2 тАФ ROOM LOBBY: COMPLETE
PHASE 3 тАФ LOTTO ENGINE: COMPLETE
PHASE 4 тАФ GAME START: COMPLETE
PHASE 5 тАФ TURN SYSTEM: COMPLETE
PHASE 6 тАФ VICTORY SYSTEM: COMPLETE
PHASE 7 тАФ APARTMENT: COMPLETE
PHASE 8 тАФ RECONNECT & AFK: COMPLETE
PHASE 9 тАФ ADMIN: COMPLETE
PHASE 10 тАФ WEBSOCKET PROTOCOL: COMPLETE (10.0-10.7 all done)

Integration tests:

`text
55 / 55 PASSED (auth)                    [+7 vs ╨╖╨░╤П╨▓╨╗╨╡╨╜╨╜╤Л╤Е 48 тАФ FIX-8 regression-╤В╨╡╤Б╤В╤Л]
91 / 91 PASSED (lobby)                   [+1 vs ╨╖╨░╤П╨▓╨╗╨╡╨╜╨╜╤Л╤Е 90 тАФ FIX-7 regression-╤В╨╡╤Б╤В]
164 / 164 PASSED (lotto engine)
44 / 44 PASSED (game start)
37 / 37 PASSED (turn system)
40 / 40 PASSED (victory system)          [+2 vs ╨╖╨░╤П╨▓╨╗╨╡╨╜╨╜╤Л╤Е 38 тАФ ╤Г╤Б╨╕╨╗╨╡╨╜╤Л ╨┐╤А╨╛╨▓╨╡╤А╨║╨╕ FIX-4]
32 / 32 PASSED (apartment)
8 / 8 PASSED (admin auth)
9 / 9 PASSED (admin ban)                 [close() ╨┤╨╛╨▒╨░╨▓╨╗╨╡╨╜ ╨▓ MockConnection, FIX-11]
8 / 8 PASSED (admin unban)
37 / 37 PASSED (admin kick)
28 / 28 PASSED (admin close room)
16 / 16 PASSED (admin logs)               [isolated log path, FIX-12]
20 / 20 PASSED (admin integration)       [close() ╨┤╨╛╨▒╨░╨▓╨╗╨╡╨╜ ╨▓ SpyConnection, FIX-11; isolated log path, FIX-12]
5 / 5 PASSED (timer integrity)
18 / 18 PASSED (server bootstrap тАФ real WS client, EPIC-10.0/10.2) [+10 vs ╨╖╨░╤П╨▓╨╗╨╡╨╜╨╜╤Л╤Е 8 тАФ TEST 7 (connection gate), TEST 8 (auth_required exemptions), TEST 4 ╤Г╨╢╨╡╤Б╤В╨╛╤З╤С╨╜]
11 / 11 PASSED (packet validation тАФ real WS client, EPIC-10.1)
18 / 18 PASSED (auth packet routing тАФ real WS client, EPIC-10.3, TEST 2 ╨╛╨▒╨╜╨╛╨▓╨╗╤С╨╜ ╨▓ EPIC-10.5)
23 / 23 PASSED (lobby packet routing тАФ real WS client, EPIC-10.4, ╨┐╨╡╤А╨╡╨╜╨╡╤Б╤С╨╜ ╨╕╨╖ ╨┐╨░╤А╨░╨╖╨╕╤В╨╜╨╛╨│╨╛ tests/manual/ ╨▓ EPIC-10.5)
20 / 20 PASSED (reconnect тАФ ╨▒╤Л╨╗╨╛ 15, +5 assertions FIX-9, EPIC-10.5)
21 / 21 PASSED (game packet routing тАФ real WS client, EPIC-10.5, ╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗)
6 / 6 PASSED (session lifecycle тАФ real WS client, FIX-10, ╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗)
15 / 15 PASSED (admin packet routing тАФ real WS client, EPIC-10.6 + FIX-11, ╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗)
50 / 50 PASSED, 3 warnings (protocol completeness тАФ static doc-cross-reference, EPIC-10.7, ╨╜╨╛╨▓╤Л╨╣ ╤Д╨░╨╣╨╗)
`

FIX-12 also touched (counts unchanged, only log destination isolated):
victory system (40/40, above), lobby integration (91/91, above), auth
integration (55/55, above), plus admin logs/admin integration (both
annotated above).

tests/Manual/test_logger.php REMOVED (FIX-12) тАФ stale duplicate of an
already-superseded print_r() smoke script (root-level copy already
deleted 2026-07-03), zero assertions, was writing raw noise into
production logs/server.log on every full-suite run. File count: 29
(was 30).

Current branch:

`text
main
`

Current stable commit (pending push тАФ see Git Checkpoint below):

`text
FIX-12-logger-isolation (Logger DI-seam + 6 test files redirected +
2 more found via full sweep + stale test_logger.php removed; incident
root-caused and resolved; full regression 0 failed)
`

Next planned Epic:

`text
EPIC-11.4 State machine audit (Phase 11 тАФ see docs/PHASE_11_REPORT.md;
EPIC-11.1/11.2/11.3 instrumentation complete, VPS runs pending)
`
PHASE 10 тАФ WEBSOCKET PROTOCOL: COMPLETE (10.0-10.7 all done). Server-side
protocol surface confirmed complete against ANCHOR_CORE.md/
ANCHOR_PROTOCOL.md's own declared registries (EPIC-10.7). Four low-
priority documentation-debt items remain open (admin_stats_data,
afk_warning, error.banned, real-WS-subprocess test log noise тАФ see
KNOWN GAPS) but none block the next phase.
Known open items: none blocking. The EPIC-10.5 KNOWN GAP
(AuthHandler::handleReconnect() not binding $connection->userId when no
matching disconnected room player is found) is RESOLVED as of FIX-10 тАФ
handleReconnect() now unconditionally binds the connection via
bindConnection() once the token/user is validated, regardless of room
membership.
