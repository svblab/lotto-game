# Implementation Status вЂ” Lotto Game Project

## EPIC-035 вЂ” Room game-speed mode (ADR-035) (2026-08-24)

Status: Completed

- [DONE] ADR-035 accepted; `speed_mode` on Room Structure + registries
- [DONE] Server: `create_room` optional `speed_mode` (default `slow`); wire into
  `room_list`, `room_joined`, `reconnect_state` (waiting + playing)
- [DONE] Client: create-room select, room list column, room panel label
- [DONE] Fast animation profile (~3s total, Lв†’R stops); slow unchanged
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
   "Draw barrel", stopwatch the full drawв†’all-three-revealed sequence.
   Expected: в‰€3s total; reels stop left-to-right; cards mark without gold pulse.
2. Create a **slow** room (default). Confirm existing ~3s-per-number reveal
   spacing and gold pulse still present.
3. Mid-game in a fast room: reload / reconnect. Confirm UI still uses fast
   animation (from `reconnect_state.speed_mode`) without recreating the room.
4. Lobby `room_list`: confirm Speed column shows Slow/Fast for each room.
5. Omit `speed_mode` in a raw `create_room` packet в†’ room behaves as slow.

---

## EPIC-033C вЂ” Admin players delete + dropdown (ADR-033) (2026-08-23)

Status: Completed

- [DONE] `admin_delete_user` / `admin_bulk_delete_users` with busy guards (online / players / history / roster)
- [DONE] All-or-nothing bulk PDO transaction; no auto-kick
- [DONE] Players admin UI: table в†’ `<select>` + detail; Ban/Unban/Kick/Delete + Delete matching preview
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
- MANUAL вЂ” admin Players dropdown select в†’ Ban/Unban/Kick/Delete
- MANUAL вЂ” filter junk usernames в†’ Delete matching в†’ confirm list в†’ purge
- MANUAL вЂ” delete rejected while target online or still in room RAM

CHANGED:
- Hard delete + bulk delete WS paths; players list UI; delete i18n; tests; KNOWN GAP note
NOT CHANGED:
- Ban/kick/unban semantics; auto-kick on delete; load-test DB isolation (KNOWN GAP)

## EPIC-033B вЂ” Admin rooms dropdown (ADR-033) (2026-08-23)

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
- MANUAL вЂ” open admin panel в†’ Active Rooms shows dropdown; empty state when no rooms
- MANUAL вЂ” select a room в†’ detail shows id / players / status / lock; Close enabled
- MANUAL вЂ” Close room в†’ room removed on next stats/list refresh; selection clears if gone
- MANUAL вЂ” Refresh rooms preserves selection when room still present

CHANGED:
- Admin rooms list presentation (table в†’ select + detail)
NOT CHANGED:
- Protocol / server / `admin_close_room`; players moderation UI; password rotation

## EPIC-033A вЂ” Admin password rotation (ADR-033) (2026-08-23)

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
- MANUAL вЂ” admin panel в†’ Change admin password; wrong current / weak new / success login with new password

CHANGED:
- Admin password rotation WS path + ADR-033 + registries + UI modal + tests
NOT CHANGED:
- Registration password rules; CLI change_admin_password.php; ban/kick/delete; rooms/players list UI

## EPIC-032a вЂ” Nudge voice i18n (client) (2026-08-23)

Status: Completed

- [DONE] `nudge_received` voice line resolves `audio/nudge_<lang>.mp3` from recipient's current `LottoI18n.getLang()` on every play.
- [DONE] Migrated `nudge.mp3` в†’ `nudge_en.mp3`; missing per-language files fall back to English without breaking other sounds.
- [DONE] Per-language cache keys (`nudge_en`, `nudge_ru`, вЂ¦) so language switches within an open tab apply on the next nudge.

Files:
- public/js/sound.js
- public/audio/nudge_en.mp3 (renamed from nudge.mp3)
- public/audio/README.md
- docs/IMPLEMENTATION_STATUS.md

Commit: 2a42674
Notes: Client-only; no ADR/protocol/server changes (ADR-032 server rules unchanged). Voice recordings for `ru/es/fr/zh/tr` are out of scope вЂ” fallback to `nudge_en.mp3` until added.

VERIFICATION:
- MANUAL вЂ” with `getLang() === 'en'`, `nudge_received` plays `nudge_en.mp3`.
- MANUAL вЂ” with `getLang()` set to `ru` (no `nudge_ru.mp3` on disk), `nudge_en.mp3` plays instead.
- MANUAL вЂ” switch language via selector without reload; next `nudge_received` uses new language.
- MANUAL вЂ” other sounds (`spin`, `apartment`, `reveal`, `match`, `victory`, `defeat`) unchanged.

## Frontend sound вЂ” volume slider + loop cues (2026-08-17)

Status: Completed

- [DONE] `LottoSound.setVolume()` / `getVolume()` with `localStorage` persistence (`lotto_sound_volume`, default 0.7); volume slider in game top-bar.
- [DONE] `startLoop()` / `stopLoop()` for spin (loops until last drum reveals) and apartment (required players only).
- [DONE] Spin: `startSlotsWaiting()` в†’ `startLoop('spin')`. Normal stop is inside `revealSlot()` at the tick that commits the last drumвЂ™s number (`!isSlotsSpinning()` after removing that drumвЂ™s `spinning` class) вЂ” not after `await revealSlot()` returns (that Promise waits an extra 450ms) and not in `app.js` after the for-loop. Safety-net / interruption stops: `stopSlotsWaiting()`, `resetSlots()`, `idleSlot()` (only when no drum still spinning).
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
MANUAL VERIFICATION REQUIRED вЂ” browser audio timing cannot be verified by the PHP test suite; see Epic completion QA checklist in chat / manual steps below.
- Volume slider changes audible level in real time; mute silences regardless of volume.
- Spin loop starts when drums spin; stops when last drum reveals or on reset/reconnect mid-spin (F1/F2 hooks).
- Apartment sound only for `required` players; stops on timeout, hideApartment paths; no leak into next turn.

## Lobby projected bank (pre-game) (2026-08-17)

Status: Completed

- [DONE] `bet_per_card` on `room_joined` (`LobbyService::buildRoomJoinedPacket`).
- [DONE] `bet_per_card` on waiting `reconnect_state`
  (`ReconnectService::buildReconnectState`) вЂ” fixes hard-refresh reconnect path.
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

## EPIC-032 вЂ” Turn nudge (ADR-032) (2026-08-17)

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

Commit: (implementation вЂ” pending user commit; docs `8eeb649`)
Notes: `GameHandler` 89 / 300; `GameTurnService` 500 / 500; `GameService` 496 / 500.

VERIFICATION:
- `php tests/Manual/test_turn_nudge.php` вЂ” 32/32 passed
- `php tests/Manual/test_turn_system.php` вЂ” 59/59 passed
- `php tests/Manual/test_game_start_turn_integration.php` вЂ” 11/11 passed

## EPIC-031c-b вЂ” IP account limit server guard (ADR-031) (2026-08-16)

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

## EPIC-031c-a вЂ” Trusted proxy IP docs + registry (ADR-031) (2026-08-16)

Status: Completed

- [DONE] ADR-031 addendum: trust boundary, sentinel fallback, updated limitations.
- [DONE] `clientRemoteIp` in ANCHOR_CORE Connection Runtime Fields.
- [DONE] README В§3.8 + LOCAL_ENVIRONMENT `LOTTO_TRUSTED_PROXY_IPS`.

Files:
- docs/ADR/031-per-account-tab-ownership-and-ip-account-limit.md
- docs/ANCHOR_CORE.md
- README.md
- docs/LOCAL_ENVIRONMENT.md

## EPIC-031b вЂ” Per-account tab ownership client fix (ADR-031) (2026-08-16)

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

## EPIC-031a вЂ” Per-account tab ownership + IP limit ADR (ADR-031) (2026-08-16)

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

Notes: Documentation only вЂ” no PHP/JS implementation in this epic.
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

- [DONE] `public/js/sound.js` вЂ” HTML5 Audio preload/play for 5 optional clips in
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
- [DONE] Replaced `#drawn-history` chip list with a responsive 1вЂ“90 horizontal
  segment strip (`renderDrawnHistory` in `ui.js`); drawn segments use `--gold`,
  undrawn stay neutral; number tooltip on `:hover`/`:focus` via CSS `::after`.

Files:
- public/index.html
- public/css/style.css
- public/js/ui.js

CHANGED:
- Auth: removed duplicate title heading; lobby: logo в†’ `span.top-bar-title`
  with existing `app.title` i18n key (no new locale keys)
- `#drawn-history`: 90 flex segments, `aria-label` per segment, full-array
  correctness on all three `renderDrawnHistory` call sites in `app.js`

NOT CHANGED:
- `public/js/app.js`, protocol, server, locale JSON files
- `.logo-img` / `.logo-placeholder` base rules (auth still uses them)
- `.drawn-chip` CSS left in place (unused)

VERIFICATION:
- `renderDrawnHistory` receives full `state.drawnAll` on draw, reset, and
  `reconnect_state` вЂ” each call rebuilds all 90 segment states via `Set`
- Responsive width: `flex: 1 1 0` on 90 segments; ~3px/segment at 320px viewport
- Tooltip: CSS-only `::after` on `:hover` and `:focus` (keyboard + touch tap)

## Optional image asset wiring (2026-08-13)

- [DONE] Wired optional `public/img/` assets into the frontend with graceful
  degradation when files are absent (fresh clone = unchanged appearance).

Files:
- public/index.html (`<img>` logos + chip probe script)
- public/css/style.css (logo-img, felt-bg layer, chip icon)

Assets:
- **logo.png** вЂ” `<img src="img/logo.png">`; `onerror` calls `replaceWith()` a
  `<div class="logo-placeholder">` (or `.small` variant), restoring the exact
  pre-integration gradient box with no broken-image icon.
- **felt-bg.png** вЂ” comma-separated `background-image` on `body` (texture +
  existing radial gradient); `background-color: var(--felt-dark)` base.
- **chip.png** вЂ” inline probe adds `html.has-chip-icon`; CSS replaces рџЄ™ emoji.
- **barrel.png** вЂ” not wired (see notes).

Notes: No binary assets committed. `barrel.png` skipped: `.drawn-chip` history
entries are compact number pills (~0.8rem); a 64Г—64 icon would clutter layout.

CHANGED:
- Auth/lobby logo placeholders в†’ optional `<img>` with onerror fallback
- `body` background layers for optional felt texture
- Balance `.coins` icon when `chip.png` exists

NOT CHANGED:
- `public/js/ui.js`, `public/js/app.js` (barrel history rendering)
- Protocol, server, locale files
- Binary files in `public/img/`

VERIFICATION:
- Fresh clone (no PNGs): gradient logos, felt gradient, рџЄ™ emoji unchanged
- With PNGs deployed: logos, texture, chip icon appear

## Rules modal copy rewrite (2026-08-13)

- [DONE] Expanded `rules.*Body` strings in all 6 locale files (en, ru, es, fr,
  zh, tr) with player-facing explanations of core loop, economy/bank, cards,
  Apartment, victory split, and reconnect. Fixed incorrect apartment fee (was
  10 in copy; actual is 5 coins). Removed English "reconnect" leak in ru.

Files:
- public/locales/en.json, ru.json, es.json, fr.json, zh.json, tr.json

Notes: Content-only вЂ” no i18n key changes, no .php/.js edits. Numbers verified
against `Constants.php`, `ApartmentService::APARTMENT_PAYMENT`, `PreparedStatements`
(create_user 500 coins), `docs/GAME_RULES.md`, `docs/ANCHOR_CORE.md` Part 2.

CHANGED:
- `rules.introBody`, `rules.economyBody`, `rules.cardsBody`,
  `rules.apartmentBody`, `rules.victoryBody`, `rules.reconnectBody` (Г—6 locales)

NOT CHANGED:
- `renderRules()` / `public/js/ui.js`, any `.php` file, i18n key structure

VERIFICATION:
- `php tests/Manual/test_frontend_i18n.php`

## Rules modal close-button contrast fix (2026-08-13)

- [DONE] Scoped `.rules-panel .icon-btn { color: var(--wood); }` so the "вњ•"
  glyph is visible on the pale-gold panel background (`#f5e6a8`). Base
  `.icon-btn` keeps `color: var(--cream)` for dark wood/felt contexts.

Files:
- public/css/style.css

Notes: Presentational only вЂ” no protocol/ADR/JS changes. Old contrast
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
- tests/Manual/test_server_bootstrap.php (TEST 9вЂ“11)
- README.md В§3.7, docs/LOCAL_ENVIRONMENT.md

Notes: Token-based auth blocks classic CSWSH cookie riding; control addresses
residual resource/spam risk and defense-in-depth. Reject path:
`error.origin_forbidden` + WS close 4002 (ADR-005 pattern).

CHANGED:
- `onWebSocketConnected` Origin inspection when env list is non-empty

NOT CHANGED:
- Auth/Lobby/Game/Admin Handlers or Services

VERIFICATION:
- `php tests/Manual/test_server_bootstrap.php`
- VPS: `sudo -u www-data php run_ALL_tests.php` (manual вЂ” agent has no SSH)

## Admin assertAdmin SQLite freshness (2026-08-12)

- [DONE] `AdminService::assertAdmin()` re-reads `users.is_admin` and
  `banned_until` from SQLite via `user_auth_fields_by_id` on each admin action.
Files:
- src/Admin/AdminService.php (`fetchUserAuthFields()`, demotion/ban sync)
- tests/Manual/test_admin_auth.php (groups 4вЂ“6: demotion, still-admin, banned)

Notes: Stale `$connection->isAdmin` cleared on demotion/ban; subsequent calls
fail fast without DB. Client sees `error.not_your_turn` on demotion (no hint);
active ban uses existing `banned` packet. One SQLite read per admin action when
`isAdmin` is true вЂ” acceptable (admin panel is low-frequency, not polled in a
tight loop).

CHANGED:
- Admin guard authoritative against SQLite

NOT CHANGED:
- AdminHandler, register/login flows, game/lobby handlers

VERIFICATION:
- `php tests/Manual/test_admin_auth.php`
- `php run_ALL_tests.php`

## EPIC-5a вЂ” Per-username login lockout (ADR-028) (2026-08-12)

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

Notes: Defaults вЂ” 5 failures / 300s window / 900s lockout. Register throttling
deferred to EPIC-5b (placeholder section in ADR-028 only). Client receives
`Invalid username or password` for rate-limited logins вЂ” no attempt count or
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
- src/Auth/AuthService.php (diff вЂ” dummy bcrypt hash on missing user row)
- tests/Manual/test_auth_integration.php (diff вЂ” identical error-message assertion)

Notes: When username is not found, `password_verify()` still runs against a
precomputed dummy bcrypt hash before throwing вЂ” reduces username enumeration via
response-time analysis. External login() contract unchanged.

CHANGED:
- AuthService::login() internal timing path only

NOT CHANGED:
- AuthHandler.php, SessionGuardService.php, exception messages, return shape

VERIFICATION:
- `php tests/Manual/test_auth_integration.php` вЂ” PASS
- Full suite: `php run_ALL_tests.php` (see run below)

## ANCHOR_CORE Part 6 registry back-fill (2026-08-12)

- [DONE] Back-fill missing protocol registry entries in ANCHOR_CORE.md Part 6
Files:
- docs/ANCHOR_CORE.md (diff вЂ” `turn_ready` action; `player_status_changed`,
  `admin_users_data` packet types)

Notes: Pure documentation/registry sync вЂ” no code changes. `turn_ready` was
implemented (server.php, GameHandler, ANCHOR_PROTOCOL.md ADR-017) but omitted
from the Part 6 action list. Packet sweep found two additional omissions already
documented in ANCHOR_PROTOCOL.md and implemented in production code.

CHANGED:
- ANCHOR_CORE.md Part 6 В§ Protocol Actions вЂ” added `turn_ready`
- ANCHOR_CORE.md Part 6 В§ Protocol Packet Types вЂ” added `player_status_changed`,
  `admin_users_data`

NOT CHANGED:
- Any `.php` source files
- ANCHOR_PROTOCOL.md packet contracts

VERIFICATION:
- `git diff --stat` shows only `.md` files modified

## EPIC-028.3 вЂ” Asymmetric cross-engine session closure + economy invariant net (2026-08-12)

- [DONE] Close ADR-026 reproduction gap; add EconomyAudit structural safety net
Files:
- tests/Manual/test_asymmetric_engine_stress.php (new вЂ” asymmetric teardown create+join stress)
- src/Core/EconomyAudit.php (diff вЂ” `checkWorkerInvariants()` duplicate-seat/dual-auth checks)
- src/Core/Helpers.php (diff вЂ” `lottoEconomyCheckInvariants()` helper)
- src/Core/RoomManager.php (diff вЂ” invariant scan on `destroyRoom()`)
- src/Game/GameService.php (diff вЂ” invariant scan on `finishGame()` teardown)
- docs/ADR/026-fix-concurrent-session-bug.md (append вЂ” EPIC-028.3 addendum)
- tests/Manual/test_economy_audit.php (diff вЂ” invariant check regression group)

Notes: Part A stress test models delayed onClose after fresh login with both
sockets attempting create_room/join_room вЂ” **no gap reproduced**; existing
SessionGuardService sweeps close the window. Part B adds detect-and-log-only
invariant monitoring (no balance mutation).

CHANGED:
- ADR-026 "Honest limit" superseded by EPIC-028.3 closure verification addendum.
- EconomyAudit structural checks on room destroy and game finish.

NOT CHANGED:
- SessionGuardService logic (no fix required вЂ” test proves current sweeps sufficient).
- Protocol packets, Handler/Service business rules.

VERIFICATION:
- `php tests/Manual/test_asymmetric_engine_stress.php` вЂ” PASS
- `php tests/Manual/test_economy_audit.php` вЂ” PASS
- Full suite: `php run_ALL_tests.php` (see run below).

## TLS/WSS documentation-vs-code fix (2026-08-12)

- [DONE] Close TLS/WSS documentation-vs-code mismatch (ADR-027)
Files:
- docs/ADR/027-reverse-proxy-tls-termination.md (new вЂ” reverse-proxy TLS decision)
- README.md (diff вЂ” В§3 rewritten: nginx/Caddy WSS via proxy; `config/ssl.php` removed)
- public/index.html (diff вЂ” `lotto-ws-port` / `lotto-ws-path` deploy meta tags)
- public/js/ws.js (diff вЂ” `resolveWsUrl()` reads meta; no hardcoded `:8080` on HTTPS)
- tests/Manual/test_ws_url_resolution.php (new вЂ” URL resolution + README/ADR checks)

Notes: Chose **option (b) reverse-proxy TLS termination** over native Workerman TLS
(lower RAM/CPU risk on 1 CPU / 500 MB VPS; avoids growing `server.php`; cert renew
reloads proxy only). `server.php` unchanged вЂ” still plain `websocket://0.0.0.0:8080`.
Production clients use `wss://host/ws` (443) when meta tags set per README В§3.

CHANGED:
- README SSL section meaning: **was** native Workerman `config/ssl.php` on port 8443;
  **now** external nginx/Caddy terminates TLS, worker stays plain WS on 8080.
- Client transport URL derivation from deploy meta + page protocol.

NOT CHANGED:
- `server.php` bootstrap, protocol packets, Handlers/Services business logic.

VERIFICATION:
- `php tests/Manual/test_ws_url_resolution.php` вЂ” PASS
- Full suite: `php run_ALL_tests.php` (see run below).

## Phase 18 вЂ” FIX-30 Multi-session auth hardening (2026-08-06)

- [DONE] FIX-30 Concurrent multi-session auth bug (single account, multiple browsers)
Files:
- src/Auth/AuthHandler.php (diff вЂ” `claimUserSession()`, evict superseded connections)
- src/Auth/AuthService.php (diff вЂ” session registry moved out of `login()`)
- server.php (diff вЂ” ownership-safe `userConnections` cleanup on `onClose`)
- src/Lobby/LobbyService.php (diff вЂ” one seat per `user_id`, `user_id` on `player_left`)
- src/Game/ReconnectService.php (diff вЂ” `rebindSeat()`, `user_id` on `player_left`)
- public/js/app.js (diff вЂ” `player_left` by `user_id`; superseded session message)
- public/locales/en.json, ru.json (diff вЂ” `auth_session_superseded`)
- tests/Manual/test_single_session.php, test_session_lifecycle.php, test_multi_session.php
- docs/ADR/001.md, docs/ANCHOR_PROTOCOL.md

Notes: ADR-001 amended вЂ” newest login/reconnect wins (evict prior live session) instead
of reject-only second login. Prevents dual authenticated sockets and duplicate room
seats; `player_left` no longer resets unrelated clients with the same username.

VERIFICATION:
- `php tests/Manual/test_single_session.php` вЂ” PASS
- `php tests/Manual/test_session_lifecycle.php` вЂ” PASS
- `php tests/Manual/test_multi_session.php` вЂ” PASS
- MANUAL: Browser A login в†’ close в†’ Browser B login в†’ reopen A в†’ only one session;
  leave from one client does not spuriously reset the other.

## Phase 18 вЂ” Client Balance Persistence (2026-08-02)

- [DONE] FIX-29 Browser-reopen reconnect + SVG line chart (game-over)
Files:
- public/js/app.js (diff вЂ” `hasPersistedSession()`; no token wipe on init; reconnect on WS open)
- public/js/ui.js (diff вЂ” SVG `polyline` line chart in `renderWinChanceChart`)
- public/index.html (diff вЂ” chart container div instead of canvas)
- public/css/style.css (diff вЂ” `.win-chance-line-chart` styles)
- public/locales/*.json (diff вЂ” `game.chartTurn`)
- src/Game/ReconnectService.php (diff вЂ” `adoptSessionTokenForUser()`)
- server.php (diff вЂ” login в†’ adopt token + `handleReconnect()`)
- tests/Manual/test_reconnect.php (diff вЂ” GROUP 3c adopt token)
- tests/Manual/test_frontend_structure.php (diff вЂ” SVG chart + reconnect checks)

Notes: Closing the browser clears sessionStorage but kept localStorage token was
wiped on next visit (`init()` + sessionStorage gate). Reconnect now attempts on
any persisted token. Login after manual re-auth adopts the new `session_token` onto
the disconnected room player so `handleReconnect()` can match. Game-over chart
rendered as SVG line chart (grid, axes, polylines, point markers, legend).

CHANGED:
- Client: auto-reconnect on page load when `lotto_session_token` exists
- Server: `login` action tries room restore after token adoption
- Game-over modal: canvas chart в†’ SVG line chart

NOT CHANGED:
- 15s playing disconnect grace, lobby immediate removal, F2 QA hotkey

VERIFICATION:
- `php tests/Manual/test_reconnect.php` вЂ” PASS (GROUP 3c).
- `php tests/Manual/test_frontend_structure.php` вЂ” PASS.
- MANUAL: start game в†’ close browser tab в†’ reopen within 15s в†’ auto-rejoin room;
  finish game в†’ game-over modal shows multi-line SVG chart.

- [DONE] FIX-28 Exponential win-chance formula (LottoEngine)
Files:
- src/Game/LottoEngine.php (diff вЂ” `calculateWinChances()` static, exponential weights)
- src/Game/VictoryService.php (diff вЂ” delegate to LottoEngine; username wire map)
- src/Game/GameService.php (diff вЂ” pass `room.status` for apartment immune Г—1.1)
- src/Game/ReconnectService.php (diff вЂ” same)
- public/js/ui.js (diff вЂ” bar shows 1-decimal server percent)
- tests/Manual/test_lotto_engine.php, test_victory.php, test_turn_system.php
- docs/ADR/014.md (amendment), docs/ANCHOR_PROTOCOL.md, docs/GAME_RULES.md

Notes: Replaces ADR-014 inverse-moves formula with `turnsToWin = 15 в€’ bestCardClosed`,
`weight = exp(в€’0.25 Г— turnsToWin)`, normalize to 1 decimal summing 100%. Active only;
disconnected excluded; complete card в†’ 100% for winner(s). Wire keys remain `username`.

CHANGED:
- `LottoEngine::calculateWinChances()` вЂ” core math
- `VictoryService::calculateWinChances()` вЂ” conn_id в†’ username for packets

NOT CHANGED:
- Victory/payout logic, client progress-bar placement (FIX-27), game-over chart

VERIFICATION:
- `php tests/Manual/test_lotto_engine.php` вЂ” PASS (+winChance engine group).
- `php tests/Manual/test_victory.php` вЂ” PASS (GROUP 3b updated).
- `php tests/Manual/test_turn_system.php` вЂ” PASS (win_chances numeric, sum 100%).

- [DONE] FIX-27 Win-chance progress bar + game-over probability chart
Files:
- public/index.html (diff вЂ” win-chance track/fill; game-over chart canvas + legend)
- public/css/style.css (diff вЂ” bar gradient styles, chart panel)
- public/js/ui.js (diff вЂ” `updateWinChanceBar`, `renderWinChanceChart`; players list without %)
- public/js/app.js (diff вЂ” track `winChanceHistory` from server `win_chances` snapshots)
- public/locales/*.json (diff вЂ” `game.winChanceHistory`)
- docs/GAME_RULES.md (diff вЂ” single bar location, sidebar fields, end-game graph)

Notes: Win chance shown only above slot machine as redв†’blue progress bar (server
comparative % from `barrels_drawn.win_chances`). Player sidebar: nickname, cards,
status only. Client records per-turn snapshots for game-over line chart (no protocol
change). History lost on mid-game refresh/reconnect вЂ” acceptable client-only scope.

CHANGED:
- Personal win-chance UI: progress bar with `winChanceBarColor()` gradient
- `renderGamePlayers()` / sidebar: no win-chance column
- `showGameOver()` renders turn-indexed probability chart after statistics table

NOT CHANGED:
- `win_chances` server calculation (ADR-014), protocol packets, payout logic

VERIFICATION:
- `php tests/Manual/test_frontend_structure.php` вЂ” PASS.
- MANUAL: 2-player game в†’ bar updates after each draw; sidebar has no %; game-over
  modal shows multi-line chart when в‰Ґ2 turns recorded.

- [DONE] FIX-26 F2 in-game reconnect QA hotkey + guard fix on page refresh
Files:
- public/js/ws.js (diff вЂ” `simulateTransportDrop()` closes WS without `intentionalClose`)
- public/js/app.js (diff вЂ” F2 key during `playing`; reconnect guard loads persisted user)
- public/locales/*.json (diff вЂ” `dev.f2Disconnect`, `dev.f2PlayingOnly`)
- docs/LOCAL_ENVIRONMENT.md (diff вЂ” F1/F2 manual reconnect steps)
- tests/Manual/test_frontend_structure.php (diff вЂ” F2 + simulateTransportDrop checks)

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
- `php tests/Manual/test_frontend_structure.php` вЂ” PASS (+2 assertions).
- MANUAL (F2): 2-player game в†’ press F2 в†’ reconnect overlay в†’ game restored with toast
  В«Session restoredВ»; if your turn, draw button enabled (FIX-17). F2 in lobby shows hint only.

- [DONE] FIX-25 Quick Start pseudo-random room pick when multiple eligible
Files:
- public/js/ui.js (diff вЂ” `pickQuickStartRoom()` filters + random choice among eligible)
- public/js/app.js (diff вЂ” `doQuickStart()` uses `pickQuickStartRoom` instead of `.find()`)
- docs/GAME_RULES.md (diff вЂ” В§2 quick start multi-room behavior)

Notes: Previously Quick Start always joined the first eligible room in `room_list` order.
Now: 0 eligible в†’ error; 1 в†’ that room; 2+ в†’ `Math.floor(Math.random() * n)`.

CHANGED:
- `pickQuickStartRoom()` helper exported from UI module
- `doQuickStart()` uses pseudo-random selection

NOT CHANGED:
- Eligibility rules (`waiting`, no password, not full), join_room flow, server room_list

VERIFICATION:
- MANUAL: create 2+ open waiting rooms without password в†’ Quick Start joins a random one
  across repeated clicks (before joining); single room в†’ always that room.

- [DONE] FIX-24 No lobby reconnect grace вЂ” waiting disconnect removes player immediately
Files:
- src/Game/ReconnectService.php (diff вЂ” `handleDisconnect()` waiting в†’ `removePlayerFromLobby`; timer callback playing-only)
- public/js/app.js (diff вЂ” `auth_result` clears stale room; `player_left` reason `disconnect` resets lobby)
- tests/Manual/test_reconnect.php (diff вЂ” GROUP 1b waiting immediate removal; GROUP 2 playing timeout)
- tests/Manual/test_timer_audit.php (diff вЂ” GROUP 4 split waiting vs playing)

Notes: Closes reconnect F1: disconnected lobby player stayed in `room['players']` as
`disconnected` with 15s timer, inflating room_list counts and allowing stale reconnect.
User rule: no reconnect tracking in lobby; only after `start_game` (`playing`). Page-refresh
re-key in waiting (active before onClose) unchanged via `handleReconnect()` GROUP 3b.
ANCHOR_CORE.md still lists reconnect in `waiting` вЂ” behavior intentionally overridden per
user instruction (Rule 1); doc sync deferred.

CHANGED:
- Lobby disconnect: immediate `removePlayerFromLobby(..., 'disconnect')` + `broadcastRoomList`
- Client: clear `state.room` on `auth_result` when not restored to room; handle self `disconnect` `player_left`

NOT CHANGED:
- Playing-state 15s reconnect timer, apartment immediate removal, in-game `reconnect_state`

VERIFICATION:
- `php tests/Manual/test_reconnect.php` вЂ” **111/111 PASS** (+GROUP 1b, GROUP 2 retargeted).
- `php tests/Manual/test_timer_audit.php` вЂ” **24/24 PASS**.
- MANUAL (F1): join room in lobby, disconnect tab в†’ player gone from room_list immediately;
  reconnect shows lobby without stale membership; can join same room again.

- [DONE] FIX-23 Game-over modal lists all winners on shared victory
Files:
- public/js/ui.js (diff вЂ” `showGameOver()` derives winners from `statistics[].received > 0`)
- public/locales/*.json (diff вЂ” `game.winnersLine` plural headline, 6 locales)

Notes: `game_over.winner` is a single string (first winner, backward-compat field). Bank/payout
table was already correct via `statistics`. Modal headline now joins all usernames with
`received > 0` and shows `final_bank` for multi-winner shared prize total.

CHANGED:
- `showGameOver()`: multi-winner headline from statistics; `winnersLine` i18n key

NOT CHANGED:
- `game_over` protocol, `GameFinishService` payout math, statistics table rendering

VERIFICATION:
- MANUAL: trigger double victory (2+ winners same barrel) вЂ” modal headline names all
  winners and total shared prize matches table sum; single-winner unchanged.

- [DONE] FIX-22 Apartment alert shown immediately (not behind barrel animation)
Files:
- public/js/app.js (diff вЂ” `onApartmentAlert()` no longer uses `enqueueAnimation`)

Notes: Non-immune players were kicked to lobby before seeing agree/refuse: server
`apartment_timer` (10s per ANCHOR_CORE) starts when `apartment_alert` is sent, but
the client queued the modal behind `barrels_drawn` slot animation (~8s+). Server timed
out в†’ `player_left` reason=refuse в†’ `resetToLobby()` before modal appeared.

CHANGED:
- `onApartmentAlert()`: call `UI().showApartment()` synchronously on packet receipt

NOT CHANGED:
- Server apartment timer duration (10s), `ApartmentService` logic, protocol, animation queue for barrels

VERIFICATION:
- MANUAL: 2вЂ“3 player game, trigger apartment вЂ” required (non-immune) player sees
  agree/refuse modal immediately with full ~10s countdown; not thrown to lobby until
  timeout/refuse. Immune player sees wait screen immediately.
- No automated test (client UI timing).

- [DONE] FIX-21 GAME_RULES.md В§5 В«РљРІР°СЂС‚РёСЂР°В» direction and payment amount corrected
Files:
- docs/GAME_RULES.md (diff вЂ” swap immune/required categories; 10 в†’ 5 coins)

Notes: Documentation-only correction paired with FIX-20. Prior GAME_RULES.md В§5 had
immunity/payment backwards (source of FIX-19's wrong direction). Payment now matches
`ApartmentService::APARTMENT_PAYMENT` (5) and ANCHOR_CORE.md.

VERIFICATION:
- Manual review вЂ” immune = closed-row players; required = all others; 5 coins; 10s timer unchanged.

- [DONE] FIX-20 Apartment immunity direction corrected (reversal of FIX-19)
Files:
- src/Game/ApartmentService.php (diff вЂ” `prepareApartment()`: closed row в†’ immune; no line в†’ required)
- tests/Manual/test_apartment.php (diff вЂ” GROUP 3/5 assertions flipped to match)

Notes: **Direction correction.** FIX-19 correctly wired `hasLine()` but used inverted
semantics copied from GAME_RULES.md В§5 (which was itself backwards). User-confirmed
correct design (Rule 1 authority): players WITH a closed row earned immunity (triggered
the event); players WITHOUT must pay APARTMENT_PAYMENT (5). Do NOT revert to FIX-19
direction even if an old GAME_RULES snapshot suggests otherwise вЂ” see FIX-21.

CHANGED:
- `prepareApartment()`: `immune = hasLine`, `required = !hasLine`

NOT CHANGED:
- `hasLine()`, `shouldTrigger()`, `finishApartment()` post-agree `immune=true`, payment amount

VERIFICATION:
- `php tests/Manual/test_apartment.php` вЂ” **51/51 PASS** (GROUP 3/5 assertions flipped).
- MANUAL: closed-row player sees immune wait screen; others see agree/refuse.

- [SUPERSEDED вЂ” direction wrong] FIX-19 Apartment immunity computed from hasLine() at trigger time
Files:
- src/Game/ApartmentService.php (diff вЂ” `prepareApartment()` derives required/immune from `hasLine()`, persists `player['immune']`)
- tests/Manual/test_apartment.php (diff вЂ” GROUP 3 real cards/masks; 3-player regression)

Notes: Introduced `hasLine()`-based immunity (good) but inverted who pays vs who is immune
(wrong вЂ” copied from backwards GAME_RULES.md В§5). Corrected by FIX-20/FIX-21.

- [DONE] FIX-18 Persist post-game_over balance to localStorage (client-only)
Files:
- public/js/app.js (diff вЂ” `onGameOver()` calls `persistUser()` after updating `state.user.coins`)

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
- MANUAL VERIFICATION REQUIRED: play 2вЂ“3 player game to victory; confirm winner
  balance on screen; hard-refresh в†’ balance matches post-win (not pre-game).
- Repeat for loser (paid > 0, received = 0) вЂ” deduction survives refresh.

- [PROPOSED] ADR-016 Server-authoritative client balance (`coins` field)
Files:
- docs/ADR/016.md (new, Status: Proposed)

Notes: Proposes additive `coins` on `game_over.statistics[]`, `reconnect_state`,
and optional `balance_updated` for admin/apartment paths. Implementation blocked
until ADR accepted (Rule 7). FIX-18 is interim mitigation only.

VERIFICATION:
- N/A вЂ” ADR draft for user review. Post-approval verification matrix in ADR В§Implementation Epic.

## Phase 17 вЂ” Compliance Audit Fixes (2026-08-02)

- [DONE] FIX-17 Reconnecting active drawer restores draw-button UI (client-only)
Files:
- public/js/app.js (diff вЂ” `onReconnectState()` playing branch sets `state.isMyTurn` from `current_drawer`)

Notes: `reconnect_state.current_drawer` was already correct; `syncTurnUi()` branches
on `state.isMyTurn`, which was only set by `your_turn`. No protocol change. Server
AFK re-arm in `ReconnectService::restorePlayerConnection()` unchanged; no in-flight
auto-draw race вЂ” `current_drawer` reflects `active_drawer_conn_id` at reconnect time.

CHANGED:
- `onReconnectState()` playing: derive `isMyTurn`, then `syncTurnUi()`

NOT CHANGED:
- `reconnect_state` payload, server reconnect/AFK logic, `your_turn` handler

VERIFICATION:
- MANUAL VERIFICATION REQUIRED: 2-player game, player A's turn, disconnect tab,
  reconnect within 15s в†’ draw button visible/enabled; non-drawer sees waiting state.
- No automated test (client UI).

- [DONE] EPIC-9.3b Host transfer on player removal during apartment state
Files:
- src/Game/ApartmentService.php (diff вЂ” `removePlayerFromApartment()` host FIFO reassignment + `host_changed` broadcast)
- tests/Manual/test_apartment.php (diff вЂ” GROUP 10 host-refuse scenario)

Notes: Closes KNOWN GAP from EPIC-9.3 (`removePlayerFromApartment` stale `host_conn_id`).
Mirrors `ReconnectService::removePlayerFromGame()` FIFO-over-`drawer_order` logic.
`host_changed` broadcast matches `LobbyService` pattern (ADR-009); lobby timeout fields
omitted вЂ” not applicable in apartment phase.

CHANGED:
- Host reassignment when removed conn was `host_conn_id`
- `broadcastHostChanged()` / `resolveHostUsername()` private helpers

NOT CHANGED:
- `removePlayerFromGame()` host path, lobby host transfer, Room/Player structure

VERIFICATION:
- `php tests/Manual/test_apartment.php` вЂ” **45/45 PASS** (was 40; +GROUP 10).

- [DONE] EPIC-17.1 GAME_RULES.md win-chance description aligned with ADR-014
Files:
- docs/GAME_RULES.md (diff вЂ” В§2 comparative win-chance wording)

Notes: Documentation-only. Player-facing language; no formula reproduction.

VERIFICATION:
- Manual review against ADR-014 В§ Formula вЂ” concept accurate, no new claims.

- [PENDING USER DECISION] EPIC-17.2 Protocol registry cleanup (`admin_stats_data`, `error.banned`)
Status: Blocked вЂ” requires explicit path (implement vs deprecate via ADR). See ISSUE 4 in audit prompt.

- [PROPOSED] ADR-015 GameTurnService extraction draft (GameService file-size policy)
Files:
- docs/ADR/015.md (new, Status: Proposed)

Notes: No code extraction in this pass (Epic Isolation). Decomposition proposal only.

VERIFICATION:
- N/A вЂ” written ADR for user review.

## Phase 16 вЂ” Comparative Win-Chance (Server-Side)

- [DONE] EPIC-16.1 Comparative win-chance calculation and protocol wiring (ADR-014)
Files:
- docs/ADR/014.md (new)
- docs/ANCHOR_PROTOCOL.md (diff вЂ” `win_chances` on `barrels_drawn` / `reconnect_state`)
- src/Game/VictoryService.php (diff вЂ” `calculateWinChances()`)
- src/Game/GameService.php (diff вЂ” wire into `broadcastBarrelsDrawn()`; passthrough; skip on victory draw)
- src/Game/ReconnectService.php (diff вЂ” `reconnect_state` playing branch)
- public/js/app.js (diff вЂ” opponents use server `win_chances`; self indicator unchanged)
- tests/Manual/test_victory.php (diff вЂ” GROUP 7 unit tests)
- tests/Manual/test_turn_system.php (diff вЂ” GROUP 7 integration)
- tests/Manual/test_reconnect.php (diff вЂ” `MockGameService::calculateWinChances`; reconnect_state assert)

Notes: Fixes silently broken opponent win-chance (~0% always) by moving comparative
move-distance formula server-side. Informational only вЂ” zero changes to victory
detection, prize calculation, apartment, AFK, economy, or state machine.
Opponent card numbers remain hidden; only coarse percentage exposed.

VERIFICATION:
- `php tests/Manual/test_victory.php` вЂ” **48/48 PASS** (was 40; +GROUP 3b unit tests).
- `php tests/Manual/test_turn_system.php` вЂ” **47/47 PASS** (was 42; +GROUP 7).
- `php tests/Manual/test_reconnect.php` вЂ” **107/107 PASS** (+2 reconnect_state asserts).
- `php run_ALL_tests.php` вЂ” baseline unchanged; no victory/prize regressions.

## Phase 15 вЂ” AFK Audit Fixes (Fresh Findings)

- [DONE] EPIC-15.4 AFK-cascade last survivor excludes equally idle player (ADR-013)
Files:
- docs/ADR/013.md (new)
- docs/ANCHOR_CORE.md (diff вЂ” В§ Last Survivor qualifying condition for AFK removal)
- docs/GAME_RULES.md (diff вЂ” Last Survivor vs mutual-AFK refund wording)
- src/Game/ReconnectService.php (diff вЂ” `removePlayerFromGame()` AFK + survivor `auto_draws>0` в†’ `handleNoSurvivors()`)
- tests/Manual/test_reconnect.php (diff вЂ” GROUP 5 engaged survivor; 5b/5c both-idle refund; 5d non-afk unchanged)
- tests/Manual/test_timer_integrity.php (diff вЂ” noop `handleNoSurvivors` mock for TEST 6b)

Notes: Closes economy loophole where second-to-last player removed for `afk` paid entire bank to a
survivor who had themselves accumulated `auto_draws > 0`. Option A (ADR-013): reuse existing
`handleNoSurvivors()` refund path; no new Player Structure field. Removal reasons `disconnect`,
`leave`, `refuse`, `kicked`, `banned` unchanged. `ApartmentService::removePlayerFromApartment()` has
no `count(active)===1` last-survivor branch вЂ” out of scope.

VERIFICATION:
- `php tests/Manual/test_reconnect.php` вЂ” **105/105 PASS** (was 77; +GROUP 5b/5c/5d, GROUP 5 split).
- `php tests/Manual/test_timer_integrity.php` вЂ” **14/14 PASS**.
- `php tests/Manual/test_admin_kick.php` вЂ” **39/39 PASS** (no double-refund regression).
- `php run_ALL_tests.php` вЂ” **32/41** files pass (baseline unchanged; `test_timer_integrity` fixed).

- [DONE] EPIC-15.1 Zero-active no-survivors refund during playing (economic integrity)
Files:
- src/Game/GameFinishService.php (diff вЂ” `handleNoSurvivors()`, `cancelRoomTimers()`, `snapshotRemainingPlayersToHistory()`; constructor `object` deps for testability)
- src/Game/GameService.php (diff вЂ” `handleNoSurvivors()` passthrough)
- src/Game/ReconnectService.php (diff вЂ” `count(active)===0` в†’ refund path; unified active-player dispatch; removed dead `destroyRoom()`)
- src/Game/ApartmentService.php (diff вЂ” delegate no-survivors to `GameService`; fix `removePlayerFromApartment` empty path; `sendJson` import)
- tests/Manual/test_reconnect.php (diff вЂ” GROUP 8/8b no-survivors + refund assertions)
- tests/Manual/test_apartment.php (diff вЂ” GROUP 9 apartment empty-path refund; `makeSvc()` wires real `GameFinishService`)

Notes: Closes ANCHOR_CORE Part 2 В§ No Survivors / В§ Economic Integrity Rule gap where
`removePlayerFromGame()` called bare `destroyRoom()` when `count(active)===0` or
`empty(players)` вЂ” coins lost, zombie rooms with disconnected stragglers. Chose option (a):
refund logic centralized in `GameFinishService` (ADR-002 payout owner). Disconnected
stragglers snapshotted into `all_players_history` before refund; reconnect timers cancelled;
`bank` explicitly zeroed.

VERIFICATION:
- `php tests/Manual/test_reconnect.php` вЂ” **65/65 PASS** (was 52; +GROUP 8/8b).
- `php tests/Manual/test_apartment.php` вЂ” **38/38 PASS** (was 36; +GROUP 9).
- `php run_ALL_tests.php` вЂ” **32/41** files pass (baseline 31/41 pre-epic; `test_apartment.php` fixed).

- [DONE] EPIC-15.2 Progressive game AFK strike windows 30s / 15s / 5s (ADR-012)
Files:
- docs/ADR/012.md (new)
- docs/ANCHOR_CORE.md (diff вЂ” В§ Game AFK Timer thresholds table)
- docs/ANCHOR_PROTOCOL.md (diff вЂ” `turn_seconds` semantics for `your_turn` / `afk_warning`)
- src/Core/Constants.php (diff вЂ” `gameAfkStrikeWindowSeconds()`; removed dead flat-30 helpers)
- src/Game/ReconnectService.php (diff вЂ” `tickGameAfk()` per-strike window lookup)
- src/Game/GameService.php (diff вЂ” `sendYourTurn()` / packet `turn_seconds` per `auto_draws`)
- tests/Manual/test_reconnect.php (diff вЂ” GROUP 4/4b/4c/5/6 boundary + `turn_seconds` assertions)
- tests/Manual/test_timer_audit.php (diff вЂ” `LOTTO_GAME_AFK_STRIKE1/2/3` env override tests)

Notes: `auto_draws` semantics unchanged (ADR-008). Client (`public/js/ui.js`) already uses
server `turn_seconds` вЂ” no hardcoded 30s dependency beyond falsy fallback.

VERIFICATION:
- `php tests/Manual/test_reconnect.php` вЂ” **71/71 PASS** (strike 1в‰Ґ30s, strike 2в‰Ґ15s, strike 3в‰Ґ5s boundaries; `turn_seconds` 30/15 in packets).
- `php tests/Manual/test_timer_audit.php` вЂ” **22/22 PASS**.
- `php run_ALL_tests.php` вЂ” **32/41** files pass (no new failures vs EPIC-15.1 sign-off).

## Phase 14 вЂ” AFK Timer Audit Fixes

- [DONE] EPIC-14.9 GAME_RULES.md: align lobby AFK activity examples with allowlist
Files:
- docs/GAME_RULES.md (diff вЂ” В§4 В«Р’ Р»РѕР±Р±РёВ»: drop misleading В«РќР°С‡Р°С‚СЊ РёРіСЂСѓВ» example;
  list `room_list` / create / join / leave; note start_game ends waiting phase)

Notes: Documentation-only polish. Matches EPIC-14.5 `$lobbyHostActivityActions` in
server.php. No code or test changes.

VERIFICATION:
- Manual review against ANCHOR_CORE.md В§ Lobby AFK Timer and ADR-010 вЂ” consistent.

- [DONE] EPIC-14.8 Fix stale ADR-007 citations in lobby integration test comments
Files:
- tests/Manual/test_lobby_integration.php (diff вЂ” SUITE 5 comments: ADR-007 в†’ ADR-011)

Notes: Comment-only traceability cleanup (ADR-011 retroactive doc). No logic change.
Grep confirmed no remaining incorrect В«ADR-007В» / В«A7 specВ» citations outside
legitimate ADR-007 subjects (`error.banned`, `afk_warning` protocol audit).

VERIFICATION:
- `php tests/Manual/test_lobby_integration.php` вЂ” 133/133 PASS (unchanged logic).

- [DONE] EPIC-14.6 Clear stale lobby joined message on leave room
Files:
- public/js/app.js (diff вЂ” `resetToLobby()` clears `#lobby-message`)

Notes: Cosmetic UI fix only; unrelated to AFK timing logic. Stale В«Р’С‹ РІ РєРѕРјРЅР°С‚Рµ
#NВ» text persisted after `leave_room` because `onRoomJoined` set the message but
`resetToLobby()` did not clear it.

VERIFICATION:
- Manual UI: leave room в†’ `#lobby-message` empty; lobby timers unaffected.
- `php tests/Manual/test_lobby_integration.php` вЂ” 133/133 PASS (no test change).
- `php run_ALL_tests.php` вЂ” 30/41 test files PASS (11 pre-existing failures
  unrelated to this one-line client fix; same baseline as EPIC-14.1 sign-off).

- [DONE] EPIC-14.5 Fix lobby AFK 120s display and turn passing after start_game
Files:
- server.php (diff вЂ” `hello` packet gains `server_time`; `touchLobbyHostActivity`
  restricted to waiting-room lobby-action allowlist: `room_list`, `create_room`,
  `join_room`, `leave_room` вЂ” excludes `start_game` and all in-game/admin actions)
- public/js/app.js (diff вЂ” server clock skew from `hello`; `onHostChanged` ignored
  while `state.inGame`)
- public/js/ui.js (diff вЂ” `setServerClockSkew` / `serverNowSec()` for lobby and
  game AFK countdown displays)
- src/Game/ReconnectService.php (diff вЂ” `reconnect_state` `host_timeout_start`
  sourced from `host_activity_at`, not stale `last_action`)
- src/Lobby/LobbyService.php (diff вЂ” `startLobbyAfkTimer()` refreshes
  `host_activity_at` + broadcasts on arm; `touchLobbyHostActivity` broadcasts via
  `broadcastHostChanged` only)
- tests/Manual/test_lobby_integration.php (diff вЂ” SUITE 7: timer arm sets full
  120s window assertion)

Notes: Closes residual EPIC-14.1 gap where `touchLobbyHostActivity` was wired
unconditionally for every action (including `start_game`), which re-broadcast
`host_changed` during game start and broke turn passing. Client clock skew caused
lobby countdown to open at ~105s instead of 120s when client clock led server.

VERIFICATION:
- `php tests/Manual/test_lobby_integration.php` вЂ” 133/133 PASS (includes SUITE 7
  В«timer arm sets full 120s windowВ» + SUITE 8 ping-immunity from EPIC-14.1).
- `php run_ALL_tests.php` вЂ” 30/41 test files PASS (11 pre-existing failures on
  Windows dev host: live WS subprocess tests, `sendJson` bootstrap gaps in some
  apartment/admin manual tests вЂ” unchanged from EPIC-14.1 baseline).

- [DONE] EPIC-14.4 Update GAME_RULES.md AFK section to match per-turn model (ADR-008)
Files:
- docs/GAME_RULES.md (diff вЂ” В§4 AFK: per-turn 30s threshold, cross-turn strike counting)

Notes: Documentation-only. Aligned with ANCHOR_CORE.md В§ Game AFK Timer and ADR-008.

VERIFICATION:
- Manual review against ANCHOR_CORE.md В§ Game AFK Timer вЂ” wording consistent.

- [DONE] EPIC-14.3 Cancel game_afk_timer immediately on apartment transition
Files:
- src/Game/ApartmentService.php (diff вЂ” explicit game_afk_timer_id cancel in triggerApartment)
- tests/Manual/test_apartment.php (diff вЂ” GROUP 5b assertion; mock_timer bootstrap)

Notes: Defensive self-stop in ReconnectService::tickGameAfk() retained.

VERIFICATION:
- `php tests/Manual/test_apartment.php` вЂ” all PASS (including GROUP 5b)

- [DONE] EPIC-14.2 Lobby AFK: document forward-only rotation + queue exhaustion (ADR-011)
Files:
- docs/ADR/011.md (РЅРѕРІС‹Р№ вЂ” retroactive ADR for host rotation + room destruction)
- docs/ANCHOR_CORE.md (diff вЂ” Room Destruction Rules 4th bullet; ADR-011 citation)
- src/Lobby/LobbyService.php (diff вЂ” comment/citation corrections only)

Notes: No runtime behavior change. Replaces incorrect ADR-007 / "A7 spec" citations.

VERIFICATION:
- `php tests/Manual/test_lobby_integration.php` вЂ” 132/132 PASS (unmodified logic)

- [DONE] EPIC-14.1 Lobby AFK timer: separate host_activity_at from ping keepalive
Files:
- docs/ADR/010.md (РЅРѕРІС‹Р№ вЂ” host_activity_at Player Structure key)
- docs/ANCHOR_CORE.md (diff вЂ” Player Structure, Lobby AFK Timer, Naming Registry)
- src/Lobby/LobbyService.php (diff вЂ” host_activity_at, touchLobbyHostActivity, timer check)
- server.php (diff вЂ” ping no longer syncs lobby AFK; touchLobbyHostActivity on real actions)
- tests/Manual/test_lobby_integration.php (diff вЂ” SUITE 8 ping-immunity regression)

Notes: `ping` still updates `last_action` for connection liveness; lobby AFK reads
`host_activity_at` only (ADR-010). Game AFK unchanged.

VERIFICATION:
- `php tests/Manual/test_lobby_integration.php` вЂ” all PASS (including SUITE 8)
- `php run_ALL_tests.php` вЂ” 0 failures

---

## Phase 13 вЂ” Game AFK Wiring & Orphaned-Method Fixes

- [DONE] EPIC-13.0 ADR: Game AFK timer wiring decision
Files:
- docs/AUDIT_ORPHANED_METHODS_2026-07-28.md (РЅРѕРІС‹Р№ вЂ” archived audit report)
- docs/ADR/008.md (РЅРѕРІС‹Р№ вЂ” startTurn + setter wiring decision)
- docs/ROADMAP.md (diff вЂ” Phase 13 added, skip note updated)

Decision: ADR-008 option (c) вЂ” `GameService::startTurn()` atomically sends
`your_turn` and arms AFK timer via post-construction `setReconnectService()`.

- [DONE] EPIC-13.1 Wire first-turn your_turn + AFK arm into handleStartGame()
Files:
- src/Game/GameService.php (diff вЂ” startTurn, setReconnectService, handleStartGame)
- server.php (diff вЂ” setReconnectService wiring)

Verification: `php tests/Manual/test_game_start.php` вЂ” 46/46 PASS (Group 7 lines
401вЂ“402 updated; Group 10 afk_start assertion updated for drawer).

- [DONE] EPIC-13.2 Wire AFK arm into handleDrawBarrel() turn rotation
Files:
- src/Game/GameService.php (diff вЂ” handleDrawBarrel uses startTurn)

Verification: `php tests/Manual/test_turn_system.php` вЂ” 38/38 PASS. Group 4
flagged for EPIC-13.4: added afk_start assertion on next drawer.

- [DONE] EPIC-13.3 Wire AFK arm into drawer-replacement paths
Files:
- src/Game/ReconnectService.php (diff вЂ” removePlayerFromGame uses startTurn)
- src/Game/ApartmentService.php (diff вЂ” finishApartment uses startTurn)

Verification: `php tests/Manual/test_reconnect.php` 20/20, `test_apartment.php` 32/32.

- [DONE] EPIC-13.4 Test corrections + turn-start integration test
Files:
- tests/Manual/test_game_start.php (diff вЂ” Group 7/10 assertions)
- tests/Manual/test_turn_system.php (diff вЂ” Group 4 afk_start)
- tests/Manual/test_game_packet_routing.php (diff вЂ” TEST 2 your_turn)
- tests/Manual/test_phase11_core_flows.php (diff вЂ” your_turn assertion)
- tests/Manual/test_game_start_turn_integration.php (РЅРѕРІС‹Р№ вЂ” 7/7 PASS)
- tests/Manual/test_admin_ban.php (diff вЂ” MockApartmentService stub)

Verification: `php run_ALL_tests.php` вЂ” 41/41 test files PASS (local Windows
dev host, 2026-07-28). VPS `./run_ALL_tests.sh` initially failed with FIX-16
(8 live WS subprocess tests вЂ” server.php fatal on missing bootstrap helper);
re-verify on VPS after `0de46d0` вЂ” see FIX-16.

- [DONE] EPIC-13.5 Apartment early-finish check on kick/ban removal
Files:
- src/Game/ApartmentService.php (diff вЂ” bindGameService, maybeFinishApartmentEarly)
- src/Admin/AdminService.php (diff вЂ” kick/ban apartment paths)
- server.php (diff вЂ” bindGameService)
- tests/Manual/test_admin_kick.php (diff вЂ” TEST 9 early-finish scenario)

Verification: test_admin_kick TEST 9 PASS; test_apartment 32/32.

- [DONE] EPIC-13.6 Investigation: reconnect mid-turn drawer turn-signal
Finding: **Frontend does NOT self-activate draw button from reconnect_state.**
`onReconnectState` (playing) calls `UI().setDrawButton(false, false)` and
`reconnect_state` carries no active-drawer field. Reconnecting drawer needs
separate `your_turn` resend or protocol extension вЂ” deferred to follow-up Epic.

- [DONE] EPIC-13.7 Cleanup: RoomManager::findRoomIdByUserId()
Decision: **(b) intentionally-retained utility** вЂ” docblock updated; no
production consumer planned; test coverage in test_lobby_integration.php kept.

**Process deviation (Rule 16 вЂ” Git Checkpoint Rule):** Phase 13 commits on
branch `cursor/epic-11-1-vps-ws-test-isolation` did not strictly follow the
one-Epic-one-commit convention. EPIC-13.3 appears in **two** commit messages:
`8cd1434` (`EPIC-13.3 wire-afk-drawer-replacement` вЂ” ReconnectService only)
and `f4cf0f4` (`EPIC-13.2-13.3 wire-afk-turn-rotation-and-apartment-resume`
вЂ” ApartmentService `finishApartment`). EPIC-13.2 landed inside `b203493`
(`EPIC-13.1 start-game-first-turn`) because `handleDrawBarrel()` and
`handleStartGame()` share `GameService.php` in a single diff. All epics are
implemented and verified; numbering in commit messages is authoritative for
audit only вЂ” see DECISION LOG 2026-07-28.

---

- [IN PROGRESS] EPIC-11.6 Load testing (Phase 11 вЂ” instrumentation complete 2026-07-27; VPS load runs pending)
Files:
- src/Core/LoadAudit.php (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” opt-in handler latency + snapshots в†’ logs/load_audit.log)
- server.php (diff вЂ” LoadAudit wiring, onMessage latency recording, periodic snapshots)
- scripts/load_test_runner.php (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” ramp/steady/storm/long VPS scenarios)
- scripts/analyze_load_log.php (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” p95/CPU/memory acceptance validator)
- tests/Manual/test_load_audit.php (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” 30 mock regression tests)
- docs/PHASE_11_REPORT.md (diff вЂ” EPIC-11.6 section updated)

Implemented:
- LoadAudit utility: LOTTO_LOAD_AUDIT=1 logs per-action handler latency_ms and
  periodic snapshots (mem, connections, rooms) for EPIC-11.6 targets.
- load_test_runner.php: four scenarios (ramp, steady, storm, long) with
  realistic register/room/game flows; client RTT в†’ logs/load_client.log;
  CPU/memory sampling в†’ logs/load_resource.log.
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

Next in Phase 11: Complete EPIC-11.1вЂ“11.6 VPS sign-off runs per docs/PHASE_11_REPORT.md.

- [IN PROGRESS] EPIC-11.5 Protocol audit (Phase 11 вЂ” instrumentation complete 2026-07-27; VPS live replay pending)
Files:
- docs/ANCHOR_CORE.md (diff вЂ” afk_warning added to packet registry)
- docs/ANCHOR_PROTOCOL.md (diff вЂ” afk_warning packet spec, error.banned note)
- docs/ADR/007.md (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” documentation alignment decisions)
- tests/Manual/test_protocol_audit.php (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” 7 live WS acceptance tests)
- scripts/ws_emulator.php (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” CLI client emulator + replay)
- tests/Manual/test_protocol_completeness.php (diff вЂ” afk_warning gap closed)
- docs/PHASE_11_REPORT.md (diff вЂ” EPIC-11.5 section updated)

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
  error.banned reserved вЂ” both documented KNOWN GAPS).

Verification (Windows dev host):
- test_protocol_completeness.php: 50/50 PASS, 2 warnings (expected)
- test_protocol_audit.php: requires Linux/VPS (live Workerman subprocess)
- Full suite: php run_ALL_tests.php вЂ” 29/29 test files passed (Windows;
  9 live-server tests skipped)

Remaining: Run test_protocol_audit.php on Ubuntu VPS; use ws_emulator.php
for session replay during live-game protocol sign-off.

- [IN PROGRESS] EPIC-11.4 State machine audit (Phase 11 вЂ” instrumentation complete 2026-07-27; VPS live-game run pending)
Files:
- src/Core/StateMachineAudit.php (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” opt-in state transition logging в†’ logs/state_machine_audit.log)
- src/Core/Helpers.php (diff вЂ” lottoStateTransition/lottoStateReject/lottoPlayerStateTransition)
- server.php (diff вЂ” StateMachineAudit wiring)
- src/Core/RoomManager.php, src/Game/GameService.php, src/Game/GameFinishService.php,
  src/Game/ApartmentService.php, src/Game/ReconnectService.php, src/Lobby/LobbyService.php,
  src/Admin/AdminService.php (diff вЂ” transition/rejection hooks)
- tests/Manual/test_state_machine_audit.php (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” 29 mock regression tests)
- scripts/analyze_state_machine_log.php (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” log replay + transition validation)
- docs/PHASE_11_REPORT.md (diff вЂ” EPIC-11.4 section updated)

Implemented:
- StateMachineAudit utility: LOTTO_STATE_AUDIT=1 logs room transitions, player
  transitions, and rejected actions per ANCHOR_CORE.md Part 4.
- Transition graph encoded: waitingв†’playingв†’apartmentв†’playingв†’finishedв†’destroyed.
- Instrumentation at all status mutation sites + key rejection guards.
- test_state_machine_audit.php: utility, valid/invalid transitions, apartment
  cycle, apartment timeout, host disconnect/reconnect, join_room guard.
- analyze_state_machine_log.php: parse log, verify sequence against spec.

Verification (Windows dev host):
- test_state_machine_audit.php: 29/29 PASS
- Full suite: php run_ALL_tests.php вЂ” 28/28 test files passed
- Existing state tests unchanged: test_phase11_core_flows.php (17/17),
  test_apartment.php (32/32), test_reconnect.php (20/20)

Remaining: Enable LOTTO_STATE_AUDIT=1 on VPS during live multi-game sessions;
run analyze_state_machine_log.php after sessions for full sign-off.

Next in Phase 11: EPIC-11.5 Protocol audit, then 11.6 per
docs/prompt phase 11 detail.md and docs/PHASE_11_REPORT.md.

- [IN PROGRESS] EPIC-11.3 Economy audit (Phase 11 вЂ” instrumentation complete 2026-07-27; VPS live-game run pending)
Files:
- src/Core/EconomyAudit.php (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” opt-in financial event logging в†’ logs/economy_audit.log)
- src/Core/Helpers.php (diff вЂ” lottoEconomyRecord() helper)
- server.php (diff вЂ” EconomyAudit wiring)
- src/Game/GameService.php, src/Game/GameFinishService.php, src/Game/ApartmentService.php,
  src/Admin/AdminService.php (diff вЂ” audit hooks on stake/prize/burn/apartment/refund)
- tests/Manual/test_economy_audit.php (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” 32 mock regression tests)
- scripts/economy_integrity_runner.php (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” multi-scenario conservation check)
- scripts/analyze_economy_log.php (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” log replay + duplicate tx_id check)
- docs/PHASE_11_REPORT.md (diff вЂ” EPIC-11.3 section updated)

Implemented:
- EconomyAudit utility: LOTTO_ECONOMY_AUDIT=1 logs stake/prize/apartment/refund/burn
  with tx_id, user_id, room_id, signed amount, microsecond timestamp.
- Transaction sites instrumented: startGame stakes, finishGame prizes+burn,
  apartment payments, admin kick/close refunds, no-survivors refunds.
- Conservation invariant: sum(user coins) + room banks + burned = initial total.
- test_economy_audit.php: utility, replay, VictoryService math, GameFinishService integration.
- economy_integrity_runner.php: 4-scenario chain (stake в†’ prize/burn в†’ apartment в†’ refund).
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

- [IN PROGRESS] EPIC-11.2 Timer audit (Phase 11 вЂ” instrumentation complete 2026-07-27; VPS accelerated run pending)
Files:
- src/Core/TimerAudit.php (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” opt-in timer lifecycle logging в†’ logs/timer_audit.log)
- src/Core/Constants.php (diff вЂ” env-resolved timeout accessors + AFK/APARTMENT constants)
- src/Core/Helpers.php (diff вЂ” lottoTimerAdd/lottoTimerDel wrappers with audit hooks)
- server.php (diff вЂ” TimerAudit wiring, watchdog uses env-resolved timeouts)
- src/Lobby/LobbyService.php, src/Game/ReconnectService.php, src/Game/ApartmentService.php,
  src/Game/GameService.php, src/Game/GameFinishService.php, src/Core/RoomManager.php
  (diff вЂ” all Timer::add/del migrated to lottoTimer* wrappers)
- tests/Manual/test_timer_audit.php (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” 20 mock regression tests)
- tests/Manual/mock_timer.php (diff вЂ” fire()/fireAll() for accelerated mock tests)
- scripts/timer_accelerated_runner.php (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” VPS accelerated timer scenarios)
- scripts/analyze_timer_log.php (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” drift В±200ms + orphan check)
- tests/Manual/ws_test_harness.php (diff вЂ” LOTTO_TIMER_AUDIT_LOG isolation)
- docs/PHASE_11_REPORT.md (diff вЂ” EPIC-11.2 section updated)

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
  analyze_timer_log.php (acceptance: no orphans, drift в‰¤200ms).

Verification (Windows dev host):
- test_timer_audit.php: 20/20 PASS
- test_timer_integrity.php: 5/5 PASS (FIX-6 regression, unchanged)
- Full suite: php run_ALL_tests.php вЂ” 26/26 test files passed

Remaining: Run timer_accelerated_runner.php on Ubuntu VPS for live drift
acceptance sign-off per EPIC-11.2 acceptance criteria.

Next in Phase 11: EPIC-11.5 Protocol audit, then 11.6 per
docs/prompt phase 11 detail.md and docs/PHASE_11_REPORT.md.

- [IN PROGRESS] EPIC-11.1 Memory audit (Phase 11 вЂ” instrumentation complete 2026-07-27; VPS 6h run pending)
Files:
- src/Core/MemoryAudit.php (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” opt-in memory snapshots в†’ logs/memory_audit.log)
- server.php (diff вЂ” worker_start/connection/packet/periodic snapshots)
- src/Core/RoomManager.php (diff вЂ” room_created/room_destroyed snapshots)
- tests/Manual/test_memory_audit.php (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” mock regression: map cleanup, bounded growth)
- scripts/memory_stability_runner.php (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” 6-hour VPS load test, Linux only)
- scripts/analyze_memory_log.php (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” validates в‰¤120% baseline threshold)
- docs/PHASE_11_REPORT.md (diff вЂ” EPIC-11.1 section updated)

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
  analyze_memory_log.php (acceptance: memory в‰¤120% baseline).

Verification (Windows dev host):
- test_memory_audit.php: all groups PASS
- Full suite: php run_ALL_tests.php (includes new test file)

Remaining: Run memory_stability_runner.php on Ubuntu VPS for 6-hour
acceptance sign-off per EPIC-11.1 acceptance criteria.

FIX-14 (VPS test isolation, 2026-07-27): Live WS tests now use port 18080
and temp-dir logs via tests/Manual/ws_test_harness.php вЂ” no collision with
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
- [DONE] EPIC-11.0 Full integration testing (Phase 11 audit вЂ” 2026-07-27)
>>>>>>> cursor/epic-11-1-vps-ws-test-isolation
Files:
- tests/Manual/test_admin_ban.php (diff вЂ” FIX-11 MockConnection::close())
- tests/Manual/test_admin_integration.php (diff вЂ” FIX-11 SpyConnection::close())
- tests/Manual/test_phase11_core_flows.php (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” chained authв†’lobbyв†’game flows)
- run_ALL_tests.php (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” cross-platform runner, SQLite on Windows)
- docs/PHASE_11_REPORT.md (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” consolidated Phase 11 audit report)

вљ пёЏ CORRECTION (2026-07-27, post-VPS regression): РїСЂРµРґС‹РґСѓС‰Р°СЏ РІРµСЂСЃРёСЏ СЌС‚РѕР№
Р·Р°РїРёСЃРё РѕС€РёР±РѕС‡РЅРѕ СѓС‚РІРµСЂР¶РґР°Р»Р°, С‡С‚Рѕ `server.php` Р±С‹Р» РёР·РјРµРЅС‘РЅ РІ СЂР°РјРєР°С… РґР°РЅРЅРѕРіРѕ
Epic РґР»СЏ СѓСЃС‚СЂР°РЅРµРЅРёСЏ "РєСЂРёС‚РёС‡РµСЃРєРѕРіРѕ РїСЂРѕР±РµР»Р° P11-001" (admin_* wiring
СЏРєРѕР±С‹ РѕС‚СЃСѓС‚СЃС‚РІРѕРІР°Р»). Р­С‚Рѕ Р±С‹Р»Рѕ Р»РѕР¶РЅС‹Рј СЃСЂР°Р±Р°С‚С‹РІР°РЅРёРµРј, РїРѕР»СѓС‡РµРЅРЅС‹Рј РЅР°
Windows-РѕРєСЂСѓР¶РµРЅРёРё СЃ РЅРµСЃРёРЅС…СЂРѕРЅРёР·РёСЂРѕРІР°РЅРЅРѕР№ Р»РѕРєР°Р»СЊРЅРѕР№ РєРѕРїРёРµР№: СЂРµР°Р»СЊРЅС‹Р№
`server.php` СѓР¶Рµ СЃРѕРґРµСЂР¶Р°Р» РїРѕР»РЅС‹Р№ admin-СЂРѕСѓС‚РёРЅРі СЃ 2026-07-25
(commit 5ad67d5, EPIC-10.6). Р”РёС„ РєРѕРјРјРёС‚Р° 6efede1 (git show --stat)
РїРѕРґС‚РІРµСЂР¶РґР°РµС‚, С‡С‚Рѕ `server.php` РІ РЅС‘Рј РќР• РјРµРЅСЏР»СЃСЏ. Rule 22 (Test
Philosophy) С‚СЂРµР±СѓРµС‚, С‡С‚РѕР±С‹ РєР°Р¶РґС‹Р№ С„РёРєСЃ Р±С‹Р» РїРѕРґС‚РІРµСЂР¶РґС‘РЅ РєР°Рє
non-false-positive РґРѕ Р·Р°РЅРµСЃРµРЅРёСЏ РІ СЃС‚Р°С‚СѓСЃ; РґР»СЏ P11-001 СЌС‚Рѕ РїСЂР°РІРёР»Рѕ Р±С‹Р»Рѕ
РЅР°СЂСѓС€РµРЅРѕ. Р—Р°РїРёСЃСЊ РёСЃРїСЂР°РІР»РµРЅР° Р·Р°РґРЅРёРј С‡РёСЃР»РѕРј; СЃР°Рј РєРѕРґ admin-СЂРѕСѓС‚РёРЅРіР°
РїРѕРґС‚РІРµСЂР¶РґС‘РЅ СЂР°Р±РѕС‡РёРј (СЃРј. Verification РЅРёР¶Рµ) вЂ” СЂРµРіСЂРµСЃСЃРёРё РїРѕ СЃСѓС‰РµСЃС‚РІСѓ РЅРµС‚,
РѕС€РёР±РѕС‡РЅРѕР№ Р±С‹Р»Р° С‚РѕР»СЊРєРѕ Р°С‚СЂРёР±СѓС†РёСЏ РёР·РјРµРЅРµРЅРёСЏ.

Implemented:
- FIX-11 mock close() РІРѕСЃСЃС‚Р°РЅРѕРІР»РµРЅ РІ test_admin_ban.php Рё
  test_admin_integration.php (AdminService::handleBanUser() Р·Р°РєСЂС‹РІР°РµС‚
  РѕРЅР»Р°Р№РЅ-С†РµР»СЊ вЂ” Р±РµР· close() С‚РµСЃС‚С‹ РїР°РґР°Р»Рё Fatal error).
- РќРѕРІС‹Р№ test_phase11_core_flows.php: registerв†’loginв†’create_roomв†’join_roomв†’
  start_game, invalid state transitions, rate-limit constants вЂ” 17/17 PASSED.
- docs/PHASE_11_REPORT.md: РїРµСЂРІС‹Р№ consolidated РѕС‚С‡С‘С‚ Phase 11 (С‚СЂРµР±СѓРµС‚
  СЃРІРµСЂРєРё СЃ CORRECTION РІС‹С€Рµ РІ С‡Р°СЃС‚Рё P11-001).

Verification:
- РџСЂРµРґРІР°СЂРёС‚РµР»СЊРЅРѕ (Windows dev host, php run_ALL_tests.php): 25/25
  runnable test files PASSED, 8 live-WS subprocess tests SKIP
  (Workerman С‚СЂРµР±СѓРµС‚ Linux).
- РћРљРћРќР§РђРўР•Р›Р¬РќРћ (Ubuntu VPS, root@box-918838:/opt/lotto-game,
  php run_ALL_tests.php, 2026-07-27): РїРѕР»РЅС‹Р№ СЂРµРіСЂРµСЃСЃ РІСЃРµС… 31 С„Р°Р№Р»РѕРІ вЂ”
  **31/31 test files PASSED**, РІРєР»СЋС‡Р°СЏ РІСЃРµ 8 СЂР°РЅРµРµ РїСЂРѕРїСѓС‰РµРЅРЅС‹С… live-WS
  subprocess С‚РµСЃС‚РѕРІ (test_server_bootstrap 18/18, test_packet_validation
  11/11, test_auth_packet_routing 18/18, test_lobby_packet_routing
  23/23, test_game_packet_routing 21/21, test_admin_packet_routing
  15/15, test_session_lifecycle 6/6, test_protocol_completeness
  50/50 + 3 known warnings). Р­С‚Рѕ РїРµСЂРІРѕРµ РїРѕРґС‚РІРµСЂР¶РґРµРЅРёРµ РІСЃРµР№ Phase 10/11
  С†РµРїРѕС‡РєРё РЅР° СЂРµР°Р»СЊРЅРѕРј Workerman-РїСЂРѕС†РµСЃСЃРµ СЃ РјРѕРјРµРЅС‚Р° EPIC-10.7 вЂ”
  admin-СЂРѕСѓС‚РёРЅРі (EPIC-10.6) Рё РІСЃСЏ РѕСЃС‚Р°Р»СЊРЅР°СЏ РїСЂРѕС‚РѕРєРѕР»СЊРЅР°СЏ РјР°СЂС€СЂСѓС‚РёР·Р°С†РёСЏ
  РїРѕРґС‚РІРµСЂР¶РґРµРЅС‹ СЂР°Р±РѕС‡РёРјРё end-to-end РЅР° С†РµР»РµРІРѕР№ РїР»Р°С‚С„РѕСЂРјРµ, РЅРµ С‚РѕР»СЊРєРѕ
  СЃС‚Р°С‚РёС‡РµСЃРєРё/РЅР° РјРѕРєР°С….

- [DONE] EPIC-10.1 Packet validation
Files:
- docs/ADR/003-rate-limiting-and-invalid-json-policy.md (РЅРѕРІС‹Р№ С„Р°Р№Р»)
- docs/ANCHOR_CORE.md (diff вЂ” Connection Runtime Fields + Global Constants,
  Part 1 Рё Part 6, СЃРѕРіР»Р°СЃРЅРѕ ADR-003)
- docs/ANCHOR_PROTOCOL.md (diff вЂ” СѓС‚РѕС‡РЅРµРЅРёРµ СЃРµРјР°РЅС‚РёРєРё error.invalid_json)
- src/Core/Constants.php (diff вЂ” RATE_LIMIT_PACKETS_PER_WINDOW=15,
  RATE_LIMIT_WINDOW_SECONDS=1)
- server.php (diff вЂ” СЂРµР°Р»РёР·Р°С†РёСЏ rate limiting РІ onMessage, РёРЅРёС†РёР°Р»РёР·Р°С†РёСЏ
  packetCount/packetWindowStart РІ onWebSocketConnected)
- tests/Manual/test_packet_validation.php (РЅРѕРІС‹Р№ С„Р°Р№Р»)
- .gitignore (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” РїРѕРїСѓС‚РЅРѕ РѕР±РЅР°СЂСѓР¶РµРЅС‹ СЃР»СѓС‡Р°Р№РЅРѕ Р·Р°РєРѕРјРјРёС‡РµРЅРЅС‹Рµ
  СЂР°РЅС‚Р°Р№Рј-Р°СЂС‚РµС„Р°РєС‚С‹ game.db-shm/game.db-wal/workerman.*.pid)

Implemented:
- ADR-003 Р·Р°РєСЂС‹РІР°РµС‚ РѕР±Р° KNOWN GAP, Р·Р°С„РёРєСЃРёСЂРѕРІР°РЅРЅС‹С… РїСЂРё РїСЂРµ-Phase-10 Р°СѓРґРёС‚Рµ:
  1. Rate limiting (docs/prompt.md): > RATE_LIMIT_PACKETS_PER_WINDOW (15)
     РїР°РєРµС‚РѕРІ Р·Р° RATE_LIMIT_WINDOW_SECONDS (1) СЃРµРєСѓРЅРґСѓ РѕС‚ РѕРґРЅРѕРіРѕ СЃРѕРµРґРёРЅРµРЅРёСЏ
     в†’ РЅРµРјРµРґР»РµРЅРЅРѕРµ Р·Р°РєСЂС‹С‚РёРµ Р‘Р•Р— error-РїР°РєРµС‚Р°. РЎС‡РёС‚Р°РµС‚ Р›Р®Р‘Р«Р• РІС…РѕРґСЏС‰РёРµ
     СЃРѕРѕР±С‰РµРЅРёСЏ (РІР°Р»РёРґРЅС‹Рµ/РЅРµРІР°Р»РёРґРЅС‹Рµ/ping) вЂ” РёРЅРєСЂРµРјРµРЅС‚ РґРѕ json_decode.
  2. Invalid-JSON policy (РїСЂРѕС‚РёРІРѕСЂРµС‡РёРµ prompt.md "Р·Р°РєСЂС‹С‚СЊ СЃРѕРµРґРёРЅРµРЅРёРµ" vs
     ANCHOR_PROTOCOL.md error.invalid_json): СЂРµС€РµРЅРѕ РІ РїРѕР»СЊР·Сѓ
     ANCHOR_PROTOCOL.md вЂ” РєРѕРґ РѕС€РёР±РєРё РїСЂРµРґРїРѕР»Р°РіР°РµС‚, С‡С‚Рѕ РєР»РёРµРЅС‚ РµРіРѕ РїРѕР»СѓС‡РёС‚
     Рё СЂР°Р·Р±РµСЂС‘С‚, Р·РЅР°С‡РёС‚ СЃРѕРµРґРёРЅРµРЅРёРµ РќР• Р·Р°РєСЂС‹РІР°РµС‚СЃСЏ. РџРѕРґРєСЂРµРїР»РµРЅРѕ РїСЂРµС†РµРґРµРЅС‚РѕРј
     error.server_full (СѓР¶Рµ СЂРµР°Р»РёР·РѕРІР°РЅ РІ LobbyService С‡РµСЂРµР· sendError(),
     РЅРµ С‡РµСЂРµР· СЂР°Р·СЂС‹РІ). Р—Р°С‰РёС‚Сѓ РѕС‚ С„Р»СѓРґР° РјР°Р»С„РѕСЂРјРµРґ-JSON РѕР±РµСЃРїРµС‡РёРІР°РµС‚ rate
     limiting, Р° РЅРµ СЂР°Р·СЂС‹РІ РЅР° РїРµСЂРІРѕРј РЅРµРІР°Р»РёРґРЅРѕРј РїР°РєРµС‚Рµ.
- РћР±Р° СЂРµС€РµРЅРёСЏ С„РѕСЂРјР°Р»РёР·РѕРІР°РЅС‹ РєР°Рє ADR-003 Рё РѕС‚СЂР°Р¶РµРЅС‹ РІ ANCHOR_CORE.md
  (РЅРѕРІС‹Рµ Connection Runtime Fields: packetCount, packetWindowStart) Рё
  ANCHOR_PROTOCOL.md (СЏРІРЅРѕРµ СѓС‚РѕС‡РЅРµРЅРёРµ РїСЂРѕ error.invalid_json).

Verification (РїРѕР»РЅРѕСЃС‚СЊСЋ Р°РІС‚РѕРјР°С‚РёС‡РµСЃРєР°СЏ, СЂРµР°Р»СЊРЅС‹Р№ WebSocket-РєР»РёРµРЅС‚):
- tests/Manual/test_packet_validation.php вЂ” 11/11 PASSED, 5 СЃС†РµРЅР°СЂРёРµРІ:
  1. Р РѕРІРЅРѕ 15 РЅРµРІР°Р»РёРґРЅС‹С… РїР°РєРµС‚РѕРІ вЂ” РІСЃРµ РїРѕР»СѓС‡Р°СЋС‚ error.invalid_json,
     СЃРѕРµРґРёРЅРµРЅРёРµ Р¶РёРІРѕ.
  2. 16-Р№ РїР°РєРµС‚ РІ С‚РѕРј Р¶Рµ РѕРєРЅРµ вЂ” Р·Р°РєСЂС‹С‚РёРµ Р‘Р•Р— error-РїР°РєРµС‚Р° (РѕС‚Р»РёС‡Р°РµС‚СЃСЏ РѕС‚
     С‚Р°Р№РјР°СѓС‚Р° С‡РµСЂРµР· feof()-РїСЂРѕРІРµСЂРєСѓ, РЅРµ С‚РѕР»СЊРєРѕ РїРѕ РѕС‚СЃСѓС‚СЃС‚РІРёСЋ РѕС‚РІРµС‚Р°).
  3. Rate limit СЃС‡РёС‚Р°РµС‚ ping РЅР°СЂР°РІРЅРµ СЃ РїСЂРѕС‡РёРјРё (РЅРµ РґРµР»Р°РµС‚ РёСЃРєР»СЋС‡РµРЅРёСЏ РґР»СЏ
     РІР°Р»РёРґРЅС‹С… action) вЂ” 15 ping РѕРє, 16-Р№ Р·Р°РєСЂС‹РІР°РµС‚ СЃРѕРµРґРёРЅРµРЅРёРµ.
  4. РћРєРЅРѕ СЂРµР°Р»СЊРЅРѕ СЃР±СЂР°СЃС‹РІР°РµС‚СЃСЏ вЂ” burst 15+РїР°СѓР·Р°>1s+burst 15 РЅРµ СЃСѓРјРјРёСЂСѓРµС‚СЃСЏ
     РІ Р·Р°РєСЂС‹С‚РёРµ.
  5. Р•РґРёРЅРёС‡РЅС‹Р№ РЅРµРІР°Р»РёРґРЅС‹Р№ JSON РЅРµ Р·Р°РєСЂС‹РІР°РµС‚ СЃРѕРµРґРёРЅРµРЅРёРµ (Р±Р°Р·РѕРІС‹Р№ ADR-003
     СЃС†РµРЅР°СЂРёР№ РІРЅРµ РєРѕРЅС‚РµРєСЃС‚Р° rate limit).
- РџСЂРѕРіРЅР°РЅРѕ 3 СЂР°Р·Р° РїРѕРґСЂСЏРґ вЂ” СЃС‚Р°Р±РёР»СЊРЅРѕ, ~4s РєР°Р¶РґС‹Р№, Р±РµР· Р·РѕРјР±Рё-РїСЂРѕС†РµСЃСЃРѕРІ.
- РџРѕР»РЅС‹Р№ СЂРµРіСЂРµСЃСЃ РїРѕ РІСЃРµРј 25 С„Р°Р№Р»Р°Рј tests/Manual/*.php (Р±С‹Р»Рѕ 24, РґРѕР±Р°РІР»РµРЅ
  test_packet_validation.php) вЂ” 0 failed.

PHASE 10 вЂ” WEBSOCKET PROTOCOL: IN PROGRESS (10.0, 10.1 done). РЎР»РµРґСѓСЋС‰РёР№:
EPIC-10.2 Protocol error handling.

- [DONE] EPIC-10.0 Protocol router
Files:
- server.php (РЅРѕРІС‹Р№ С„Р°Р№Р», 175 СЃС‚СЂРѕРє)
- tests/Manual/test_server_bootstrap.php (РЅРѕРІС‹Р№ С„Р°Р№Р», 227 СЃС‚СЂРѕРє)

Implemented:
- Workerman bootstrap: websocket://0.0.0.0:8080, single worker (count=1),
  СЃРѕРіР»Р°СЃРЅРѕ LOCAL_ENVIRONMENT.md Рё ANCHOR_CORE.md Part 1.
- onWorkerStart: РёРЅРёС†РёР°Р»РёР·Р°С†РёСЏ Database/Logger/RoomManager-СЃРѕРІРјРµСЃС‚РёРјРѕР№
  runtime-РїР°РјСЏС‚Рё ($worker->rooms/userConnections/sessionTokens = []),
  Global Watchdog Timer (60s, Р·Р°РєСЂС‹С‚РёРµ РјС‘СЂС‚РІС‹С… СЃРѕРµРґРёРЅРµРЅРёР№ РїРѕ РїРѕСЂРѕРіР°Рј
  AUTHORIZED_TIMEOUT/UNAUTHORIZED_TIMEOUT вЂ” ANCHOR_CORE.md Part 5).
- onWebSocketConnected (РЅРµ onConnect вЂ” handshake РЅР° СЌС‚РѕС‚ РјРѕРјРµРЅС‚ СѓР¶Рµ
  Р·Р°РІРµСЂС€С‘РЅ, С‡С‚Рѕ РїРѕРґС‚РІРµСЂР¶РґРµРЅРѕ РґРѕРєР±Р»РѕРєРѕРј Workerman "Emitted after websocket
  handshake"): РёРЅРёС†РёР°Р»РёР·Р°С†РёСЏ Connection Runtime Fields (userId/username/
  isAdmin/sessionToken/lastPing), РЅРµРјРµРґР»РµРЅРЅР°СЏ РѕС‚РїСЂР°РІРєР° hello
  {"type":"hello","protocol_version":1}.
- onMessage: Р±РµР·РѕРїР°СЃРЅС‹Р№ json_decode (РЅРµ-РѕР±СЉРµРєС‚ в†’ error.invalid_json),
  ping Р±РµР· РѕС‚РІРµС‚Р° (ANCHOR_PROTOCOL.md В§ Heartbeat), РїСѓСЃС‚РѕР№ action-РґРёСЃРїРµС‚С‡РµСЂ
  (match/default в†’ error.invalid_json РґР»СЏ Р»СЋР±РѕРіРѕ РµС‰С‘ РЅРµ РїРѕРґРєР»СЋС‡С‘РЅРЅРѕРіРѕ action).
- onClose: РґРёР°РіРЅРѕСЃС‚РёС‡РµСЃРєРѕРµ Р»РѕРіРёСЂРѕРІР°РЅРёРµ + СЏРІРЅС‹Р№ TODO вЂ” РїРѕР»РЅР°СЏ СЂРµРєРѕРЅРЅРµРєС‚-
  Р»РѕРіРёРєР° РЅРµРІРѕР·РјРѕР¶РЅР° РІ СЌС‚РѕРј Epic (СЃРј. РЅРёР¶Рµ).

РЎРѕР·РЅР°С‚РµР»СЊРЅРѕ РќР• СЂРµР°Р»РёР·РѕРІР°РЅРѕ (Rule 11 Epic Isolation):
- РњР°СЂС€СЂСѓС‚РёР·Р°С†РёСЏ auth/lobby/game/admin-РїР°РєРµС‚РѕРІ вЂ” EPIC-10.3/10.4/10.5/10.6.
  AuthHandler СѓР¶Рµ СЃСѓС‰РµСЃС‚РІСѓРµС‚ (Phase 1), РЅРѕ РЅРµ РїРѕРґРєР»СЋС‡С‘РЅ.
  LobbyHandler/GameHandler/AdminHandler РµС‰С‘ РїСЂРµРґСЃС‚РѕРёС‚ СЃРѕР·РґР°С‚СЊ.
- Rate limiting (>15 РїР°РєРµС‚РѕРІ/СЃРµРє) Рё С‚РѕС‡РЅР°СЏ policy РЅРµРІР°Р»РёРґРЅРѕРіРѕ JSON вЂ”
  EPIC-10.1 (СЂРµС€РµРЅРѕ СЃ РїРѕР»СЊР·РѕРІР°С‚РµР»РµРј СЏРІРЅРѕ, СЃРј. KNOWN GAPS).
- onClose в†’ ReconnectService::handleDisconnect() РЅРµ РїРѕРґРєР»СЋС‡С‘РЅ: СЃР°Рј
  РєРѕРЅСЃС‚СЂСѓРєС‚РѕСЂ ReconnectService С‚СЂРµР±СѓРµС‚ РћР”РќРћР’Р Р•РњР•РќРќРћ LobbyService Р
  GameService вЂ” РїРѕРґРєР»СЋС‡РёС‚СЊ РµРіРѕ РІ server.php СЂР°РЅСЊС€Рµ EPIC-10.4/10.5
  РѕР·РЅР°С‡Р°Р»Рѕ Р±С‹ РЅР°СЂСѓС€РёС‚СЊ Rule 11 (Auth+Lobby+Game РІ РѕРґРЅРѕРј Epic).

Verification (Р°РІС‚РѕРјР°С‚РёС‡РµСЃРєР°СЏ, РїРѕР»РЅРѕСЃС‚СЊСЋ СЃР°РјРѕРґРѕСЃС‚Р°С‚РѕС‡РЅР°СЏ):
- tests/Manual/test_server_bootstrap.php РїРѕРґРЅРёРјР°РµС‚ server.php РєР°Рє
  СЂРµР°Р»СЊРЅС‹Р№ РїРѕРґРїСЂРѕС†РµСЃСЃ (proc_open), РѕР±С‰Р°РµС‚СЃСЏ СЃ РЅРёРј С‡РµСЂРµР· СЃРѕР±СЃС‚РІРµРЅРЅРѕСЂСѓС‡РЅРѕ
  РЅР°РїРёСЃР°РЅРЅС‹Р№ RFC6455 WebSocket-РєР»РёРµРЅС‚ (Р±РµР· РІРЅРµС€РЅРёС… Р±РёР±Р»РёРѕС‚РµРє) РїРѕ
  РЅР°СЃС‚РѕСЏС‰РµРјСѓ TCP-СЃРѕРєРµС‚Сѓ РЅР° 127.0.0.1:8080, Р·Р°С‚РµРј РєРѕСЂСЂРµРєС‚РЅРѕ РѕСЃС‚Р°РЅР°РІР»РёРІР°РµС‚
  РїСЂРѕС†РµСЃСЃ (SIGTERM в†’ graceful shutdown, SIGKILL РєР°Рє fallback).
- Р РµР·СѓР»СЊС‚Р°С‚: 8/8 PASSED. РџСЂРѕРіРЅР°РЅ РґРІР°Р¶РґС‹ РїРѕРґСЂСЏРґ вЂ” РїРѕСЂС‚ РєРѕСЂСЂРµРєС‚РЅРѕ
  РѕСЃРІРѕР±РѕР¶РґР°РµС‚СЃСЏ РјРµР¶РґСѓ Р·Р°РїСѓСЃРєР°РјРё.
- Р СѓС‡РЅР°СЏ РїСЂРѕРІРµСЂРєР° `php server.php start` вЂ” Workerman РїРѕРґРЅРёРјР°РµС‚СЃСЏ,
  С‚Р°Р±Р»РёС†Р° РІРѕСЂРєРµСЂРѕРІ РїРѕРєР°Р·С‹РІР°РµС‚ [OK], graceful stop РїРѕ SIGTERM.
- вљ пёЏв†’вњ… РРЎРџР РђР’Р›Р•РќРћ (2026-07-21): РїРµСЂРІР°СЏ РІРµСЂСЃРёСЏ test_server_bootstrap.php
  Р·Р°РІРёСЃР°Р»Р° РЅР° VPS (С‚СЂРµР±РѕРІР°Р»СЃСЏ Ctrl+C). РџСЂРёС‡РёРЅР° вЂ” РєР»Р°СЃСЃРёС‡РµСЃРєРёР№ proc_open
  deadlock: stdout/stderr РґРѕС‡РµСЂРЅРµРіРѕ РїСЂРѕС†РµСЃСЃР° С€Р»Рё РІ pipe, РєРѕС‚РѕСЂС‹Р№ РЅРёРєРѕРіРґР°
  РЅРµ РІС‹С‡РёС‚С‹РІР°Р»СЃСЏ; РћРЎ-Р±СѓС„РµСЂ РїР°Р№РїР° Р·Р°РїРѕР»РЅСЏР»СЃСЏ РІС‹РІРѕРґРѕРј Workerman, РґРѕС‡РµСЂРЅРёР№
  РїСЂРѕС†РµСЃСЃ Р±Р»РѕРєРёСЂРѕРІР°Р»СЃСЏ РЅР° write() РґРѕ СЂРµР°Р»СЊРЅРѕРіРѕ Р±РёРЅРґРёРЅРіР° РїРѕСЂС‚Р°. Р’ РїРµСЃРѕС‡РЅРёС†Рµ
  РЅРµ РІРѕСЃРїСЂРѕРёР·РІРѕРґРёР»РѕСЃСЊ РёР·-Р·Р° РЅРµР±РѕР»СЊС€РѕРіРѕ РѕР±СЉС‘РјР° РІС‹РІРѕРґР°, РїРѕРјРµС‰Р°РІС€РµРіРѕСЃСЏ РІ Р±СѓС„РµСЂ.
  РСЃРїСЂР°РІР»РµРЅРѕ: РІС‹РІРѕРґ РґРѕС‡РµСЂРЅРµРіРѕ РїСЂРѕС†РµСЃСЃР° С‚РµРїРµСЂСЊ РёРґС‘С‚ РІ С„Р°Р№Р»С‹ (['file', ...],
  РЅРµ ['pipe', ...] вЂ” Р·Р°РїРёСЃСЊ РІ С„Р°Р№Р» РЅРµ Р±Р»РѕРєРёСЂСѓРµС‚СЃСЏ РїРѕ РѕР±СЉС‘РјСѓ), РѕРїСЂРѕСЃ РїРѕСЂС‚Р°
  РІРјРµСЃС‚Рѕ С„РёРєСЃРёСЂРѕРІР°РЅРЅРѕРіРѕ sleep, РґРёР°РіРЅРѕСЃС‚РёРєР° stdout/stderr РїСЂРё СЃР±РѕРµ Р±РёРЅРґРёРЅРіР°,
  Р¶С‘СЃС‚РєРёР№ watchdog РїРѕ SIGALRM (HARD_TIMEOUT_SECONDS=20) РєР°Рє РїРѕСЃР»РµРґРЅРёР№
  СЂСѓР±РµР¶ вЂ” СЃРєСЂРёРїС‚ С„РёР·РёС‡РµСЃРєРё РЅРµ РјРѕР¶РµС‚ Р·Р°РІРёСЃРЅСѓС‚СЊ РЅР°РІСЃРµРіРґР°. РџСЂРѕРІРµСЂРµРЅРѕ 5
  РїСЂРѕРіРѕРЅРѕРІ РїРѕРґСЂСЏРґ (~3-4s РєР°Р¶РґС‹Р№) + РѕС‚РґРµР»СЊРЅРѕ РїСѓС‚СЊ РґРёР°РіРЅРѕСЃС‚РёРєРё РїСЂРё Р·Р°РІРµРґРѕРјРѕ
  РЅРµСЂР°Р±РѕС‡РµРј РїРѕСЂС‚Рµ (5s, С‡РёСЃС‚РѕРµ СЃРѕРѕР±С‰РµРЅРёРµ РѕР± РѕС€РёР±РєРµ, Р±РµР· Р·Р°РІРёСЃР°РЅРёСЏ).
- вљ пёЏв†’вњ… РРЎРџР РђР’Р›Р•РќРћ (РІС‚РѕСЂРѕР№ СЂР°СѓРЅРґ, С‚РѕС‚ Р¶Рµ РґРµРЅСЊ): РїРѕСЃР»Рµ РїРµСЂРІРѕРіРѕ С„РёРєСЃР° С‚РµСЃС‚
  РІСЃС‘ РµС‰С‘ РїР°РґР°Р» РЅР° VPS вЂ” "WS handshake failed" СЃ РїСѓСЃС‚С‹Рј РѕС‚РІРµС‚РѕРј.
  РџСЂРёС‡РёРЅР°: РѕСЃРёСЂРѕС‚РµРІС€РёР№ РїСЂРѕС†РµСЃСЃ server.php СЃ РџР•Р Р’РћР™ (Р·Р°РІРёСЃС€РµР№) РїРѕРїС‹С‚РєРё
  РѕСЃС‚Р°Р»СЃСЏ Р¶РёС‚СЊ Рё РґРµСЂР¶Р°С‚СЊ РїРѕСЂС‚ 8080 (Workerman stdout С‡РµСЃС‚РЅРѕ РїРёСЃР°Р»
  "already running"), Р° С‚РµСЃС‚ РїРѕ РѕС€РёР±РєРµ РїРѕРґРєР»СЋС‡Р°Р»СЃСЏ Рє Р­РўРћРњРЈ СЃС‚Р°СЂРѕРјСѓ
  РїСЂРѕС†РµСЃСЃСѓ РІРјРµСЃС‚Рѕ СЃРІРѕРµРіРѕ СЃРІРµР¶РµСЃРѕР·РґР°РЅРЅРѕРіРѕ. РСЃРїСЂР°РІР»РµРЅРѕ: РїРµСЂРµРґ СЃС‚Р°СЂС‚РѕРј
  С‚РµСЃС‚ С‚РµРїРµСЂСЊ СЃР°Рј РІС‹Р·С‹РІР°РµС‚ `php server.php stop` (idempotent, Р±РµР·РѕРїР°СЃРЅРѕ
  РїСЂРё РѕС‚СЃСѓС‚СЃС‚РІРёРё Р·Р°РїСѓС‰РµРЅРЅРѕРіРѕ РїСЂРѕС†РµСЃСЃР°) РґР»СЏ РіР°СЂР°РЅС‚РёСЂРѕРІР°РЅРЅРѕ С‡РёСЃС‚РѕРіРѕ
  СЃРѕСЃС‚РѕСЏРЅРёСЏ, РїР»СЋСЃ СЏРІРЅР°СЏ РґРёР°РіРЅРѕСЃС‚РёРєР° "already running" СЃ РїРѕРґСЃРєР°Р·РєРѕР№
  СЂСѓС‡РЅРѕР№ РєРѕРјР°РЅРґС‹ РЅР° СЃР»СѓС‡Р°Р№, РµСЃР»Рё self-healing РЅРµ СЃСЂР°Р±РѕС‚Р°РµС‚. РџСЂРѕРІРµСЂРµРЅРѕ:
  РІСЂСѓС‡РЅСѓСЋ СЃРѕР·РґР°РЅ РѕСЃРёСЂРѕС‚РµРІС€РёР№ РїСЂРѕС†РµСЃСЃ в†’ С‚РµСЃС‚ СЃР°Рј РµРіРѕ РїРѕРіР°СЃРёР» Рё СЃС‚Р°СЂС‚РѕРІР°Р»
  Р·Р°РЅРѕРІРѕ вЂ” 8/8 PASSED, Р±РµР· Р·РѕРјР±Рё-РїСЂРѕС†РµСЃСЃРѕРІ РїРѕСЃР»Рµ. 3 РґРѕРїРѕР»РЅРёС‚РµР»СЊРЅС‹С…
  РїСЂРѕРіРѕРЅР° СЃ С‡РёСЃС‚РѕРіРѕ СЃРѕСЃС‚РѕСЏРЅРёСЏ вЂ” СЃС‚Р°Р±РёР»СЊРЅРѕ 8/8, ~3-4s РєР°Р¶РґС‹Р№.
- РџРѕР»РЅС‹Р№ СЂРµРіСЂРµСЃСЃ РїРѕ РІСЃРµРј 24 С„Р°Р№Р»Р°Рј tests/Manual/*.php (Р±С‹Р» 23, РґРѕР±Р°РІР»РµРЅ
  test_server_bootstrap.php) вЂ” 0 failed.

PHASE 10 вЂ” WEBSOCKET PROTOCOL: IN PROGRESS (10.0 done, 10.1 Packet
validation next вЂ” РІРєР»СЋС‡Р°РµС‚ СЂРµС€РµРЅРёРµ РїРѕ rate limiting Рё invalid-JSON policy)

- [DONE] EPIC-9.6 Admin integration tests
Files:
- src/Admin/AdminService.php (diff вЂ” FIX-3, СЃРј. РЅРёР¶Рµ)
- tests/Manual/test_admin_logs.php (РЅРѕРІС‹Р№ С„Р°Р№Р»)
- tests/Manual/test_admin_integration.php (РЅРѕРІС‹Р№ С„Р°Р№Р»)
- test_logger.php (СѓРґР°Р»С‘РЅ РёР· РєРѕСЂРЅСЏ РїСЂРѕРµРєС‚Р°)

Implemented:
- tests/Manual/test_admin_logs.php: assert-based РІРµСЂРёС„РёРєР°С†РёСЏ AdminService::handleGetLogs()
  (guard auth_required/not_your_turn, РїР°РєРµС‚ admin_logs_data, РѕС‚СЃСѓС‚СЃС‚РІРёРµ logger, СЃСЂРµР·
  limit=100 С‡РµСЂРµР· Logger::getLastLines(), СЂРµР°Р»СЊРЅС‹Р№ Logger РїСЂРѕС‚РёРІ С„Р°Р№Р»Р°). Р—Р°РєСЂС‹РІР°РµС‚
  РїСЂРѕР±РµР» РІРµСЂРёС„РёРєР°С†РёРё EPIC-9.5 вЂ” РїСЂРµР¶РЅРёР№ tests/Manual/test_logger.php Р±С‹Р» print_r()
  СЃРјРѕСѓРє-СЃРєСЂРёРїС‚РѕРј Р±РµР· assert'РѕРІ Рё РЅРµ РїСЂРѕРІРµСЂСЏР» AdminService РІРѕРѕР±С‰Рµ.
- tests/Manual/test_admin_integration.php: РєСЂРѕСЃСЃ-СЃС†РµРЅР°СЂРёРё РјРµР¶РґСѓ admin-РґРµР№СЃС‚РІРёСЏРјРё
  (test_admin_ban/unban/kick/close_room.php РїРѕРєСЂС‹РІР°СЋС‚ РєРѕРЅС‚СЂР°РєС‚С‹ РєР°Р¶РґРѕРіРѕ РґРµР№СЃС‚РІРёСЏ
  РР—РћР›РР РћР’РђРќРќРћ; СЌС‚РѕС‚ С„Р°Р№Р» РїСЂРѕРІРµСЂСЏРµС‚ РїРѕСЃР»РµРґРѕРІР°С‚РµР»СЊРЅРѕСЃС‚Рё РёР· РЅРµСЃРєРѕР»СЊРєРёС… РґРµР№СЃС‚РІРёР№ РІ
  РѕРґРЅРѕР№ РєРѕРјРЅР°С‚Рµ, РіРґРµ РёРЅРІР°СЂРёР°РЅС‚ СЌРєРѕРЅРѕРјРёРєРё РјРѕР¶РµС‚ РЅР°СЂСѓС€Р°С‚СЊСЃСЏ РЅР° СЃС‚С‹РєРµ РєРѕРЅС‚СЂР°РєС‚РѕРІ).

РћР±РЅР°СЂСѓР¶РµРЅ Рё РёСЃРїСЂР°РІР»РµРЅ Р±Р°Рі (FIX-3, СЃРј. СЃРµРєС†РёСЋ PATCHES):
- handleKickUser() СЂРµС„Р°РЅРґРёР» total_paid Рё СѓРјРµРЅСЊС€Р°Р» bank, РЅРѕ РЅРµ РѕР±РЅСѓР»СЏР» total_paid
  РёРіСЂРѕРєР° РІ РїР°РјСЏС‚Рё. Р”РµР»РµРіР°С‚ СѓРґР°Р»РµРЅРёСЏ (removePlayerFromLobby/Game/Apartment) РїРёСЃР°Р»
  РІ all_players_history РЎРўРђР РћР• (РґРѕСЂРµС„Р°РЅРґРЅРѕРµ) Р·РЅР°С‡РµРЅРёРµ total_paid. РџРѕСЃР»РµРґСѓСЋС‰РёР№
  admin_close_room() Р±РµР·СѓСЃР»РѕРІРЅРѕ СЂРµС„Р°РЅРґРёР» total_paid РёР· РёСЃС‚РѕСЂРёРё РєР°Р¶РґРѕРјСѓ СѓС‡Р°СЃС‚РЅРёРєСѓ вЂ”
  РєРёРєРЅСѓС‚С‹Р№ РёРіСЂРѕРє РїРѕР»СѓС‡Р°Р» РґРµРЅСЊРіРё РґРІР°Р¶РґС‹. РќР°СЂСѓС€РµРЅРёРµ Economic Integrity Rule
  (ANCHOR_CORE.md Part 2).
- Regression-С‚РµСЃС‚ (TEST 1 Рё TEST 3 РІ test_admin_integration.php) РїСЂРѕРІРµСЂРµРЅ РЅР°
  Р»РѕР¶РЅРѕРїРѕР»РѕР¶РёС‚РµР»СЊРЅРѕСЃС‚СЊ: РІСЂРµРјРµРЅРЅРѕ РѕС‚РєР°С‚С‹РІР°Р»СЃСЏ FIX-3 в†’ С‚РµСЃС‚ РґР°Р» 5 С‡РµСЃС‚РЅС‹С… FAIL
  (520 РїСЂРѕС‚РёРІ С„Р°РєС‚РёС‡РµСЃРєРёС… 540, 40 РїСЂРѕС‚РёРІ 60); РїРѕСЃР»Рµ РІРѕСЃСЃС‚Р°РЅРѕРІР»РµРЅРёСЏ С„РёРєСЃР° вЂ” СЃРЅРѕРІР°
  20/20 PASSED.

Manual verification:
- test_admin_logs.php: 16/16 PASSED
- test_admin_integration.php: 20/20 PASSED
- Р РµРіСЂРµСЃСЃРёСЏ РїСЂРѕС‚РёРІ РІСЃРµС… СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёС… admin-С‚РµСЃС‚РѕРІ РїРѕСЃР»Рµ FIX-3:
  test_admin_auth.php 8/8, test_admin_ban.php 9/9, test_admin_unban.php 8/8,
  test_admin_kick.php 37/37, test_admin_close_room.php 28/28 вЂ” РІСЃРµ С‡РёСЃС‚С‹.

PHASE 9 вЂ” ADMIN: COMPLETE (9.0вЂ“9.6 done)

Integration tests:
48 / 48 PASSED (auth)
90 / 90 PASSED (lobby)
164 / 164 PASSED (lotto engine) вЂ” СЃРј. KNOWN GAPS: С‚РµСЃС‚РѕРІС‹Р№ С„Р°Р№Р» РїР°РґР°РµС‚ РїРѕ РЅРµР·Р°РІРёСЃРёРјРѕР№ РїСЂРёС‡РёРЅРµ
44 / 44 PASSED (game start) вЂ” СЃРј. KNOWN GAPS: С‚РµСЃС‚РѕРІС‹Р№ С„Р°Р№Р» РїР°РґР°РµС‚ РїРѕ РЅРµР·Р°РІРёСЃРёРјРѕР№ РїСЂРёС‡РёРЅРµ
37 / 37 PASSED (turn system)
38 / 38 PASSED (victory system) вЂ” СЃРј. KNOWN GAPS: С‚РµСЃС‚РѕРІС‹Р№ С„Р°Р№Р» РїР°РґР°РµС‚ РїРѕ РЅРµР·Р°РІРёСЃРёРјРѕР№ РїСЂРёС‡РёРЅРµ
32 / 32 PASSED (apartment)
15 / 15 PASSED (reconnect)
8 / 8 PASSED (admin auth)
9 / 9 PASSED (admin ban)
8 / 8 PASSED (admin unban)
37 / 37 PASSED (admin kick)
28 / 28 PASSED (admin close room)
16 / 16 PASSED (admin logs)
20 / 20 PASSED (admin integration)

Next planned Epic: EPIC-10.0 Protocol router (РёСЃС‚РѕСЂРёС‡РµСЃРєР°СЏ Р·Р°РїРёСЃСЊ РЅР° РјРѕРјРµРЅС‚ Р·Р°РІРµСЂС€РµРЅРёСЏ EPIC-9.6 вЂ” РІС‹РїРѕР»РЅРµРЅРѕ, СЃРј. Р·Р°РїРёСЃСЊ РІС‹С€Рµ)

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

- [DONE] EPIC-9.4 Close room вЂ” AdminService::handleCloseRoom()
Files:
- src/Admin/AdminService.php (diff вЂ” РґРѕР±Р°РІР»РµРЅ handleCloseRoom())
- tests/Manual/test_admin_close_room.php (РЅРѕРІС‹Р№ С„Р°Р№Р»)
Notes:
- 28/28 С‚РµСЃС‚РѕРІ РїСЂРѕР№РґРµРЅРѕ (php test_admin_close_room.php)
- РџРѕРєСЂС‹С‚Рѕ: Р·Р°РєСЂС‹С‚РёРµ waiting-РєРѕРјРЅР°С‚С‹ Р±РµР· СЂРµС„Р°РЅРґРѕРІ РїСЂРё total_paid=0,
  Р·Р°РєСЂС‹С‚РёРµ playing-РєРѕРјРЅР°С‚С‹ СЃ РїРѕР»РЅС‹Рј РІРѕР·РІСЂР°С‚РѕРј СЃСЂРµРґСЃС‚РІ,
  РІРѕР·РІСЂР°С‚ СЂР°РЅРµРµ СѓРґР°Р»С‘РЅРЅС‹Рј РёРіСЂРѕРєР°Рј С‡РµСЂРµР· all_players_history,
  СѓРІРµРґРѕРјР»РµРЅРёРµ С‚РѕР»СЊРєРѕ active-РёРіСЂРѕРєРѕРІ (disconnected РЅРµ РїРѕР»СѓС‡Р°СЋС‚ packet, РЅРѕ РїРѕР»СѓС‡Р°СЋС‚ refund),
  room_not_found, guard РґР»СЏ РЅРµ-Р°РґРјРёРЅРёСЃС‚СЂР°С‚РѕСЂР°,
  rollback РїСЂРё РѕС€РёР±РєРµ refund-С‚СЂР°РЅР·Р°РєС†РёРё (coins/bank РЅРµ РёР·РјРµРЅСЏСЋС‚СЃСЏ, destroyRoom РЅРµ РІС‹Р·С‹РІР°РµС‚СЃСЏ,
  РєРѕРјРЅР°С‚Р° СЃРѕС…СЂР°РЅСЏРµС‚СЃСЏ, PDO transaction РєРѕСЂСЂРµРєС‚РЅРѕ РѕС‚РєР°С‚С‹РІР°РµС‚СЃСЏ)
- Р­РєРѕРЅРѕРјРёРєР°: ANCHOR_CORE.md Part 2 В§ Admin Close Room вЂ”
  РІСЃРµРј СѓС‡Р°СЃС‚РЅРёРєР°Рј РІРѕР·РІСЂР°С‰Р°РµС‚СЃСЏ 100% total_paid (РІРєР»СЋС‡Р°СЏ apartment payments),
  РёСЃС‚РѕС‡РЅРёРє РґР°РЅРЅС‹С… вЂ” all_players_history, РѕРїРµСЂР°С†РёСЏ РІС‹РїРѕР»РЅСЏРµС‚СЃСЏ РІ РѕРґРЅРѕР№ PDO-С‚СЂР°РЅР·Р°РєС†РёРё

PHASE 9 вЂ” ADMIN: IN PROGRESS (9.0/9.1/9.2/9.3/9.4 done, 9.5 Logs access next)

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

- [DONE] EPIC-9.3 Kick player вЂ” AdminService::handleKickUser()
Files:
- src/Admin/AdminService.php (diff вЂ” РґРѕР±Р°РІР»РµРЅ РїР°СЂР°РјРµС‚СЂ $db РІ РєРѕРЅСЃС‚СЂСѓРєС‚РѕСЂ + handleKickUser())
- tests/Manual/test_admin_kick.php (РЅРѕРІС‹Р№ С„Р°Р№Р»)
Notes:
- 37/37 С‚РµСЃС‚РѕРІ РїСЂРѕР№РґРµРЅРѕ (php test_admin_kick.php)
- РџРѕРєСЂС‹С‚Рѕ: waiting Р±РµР· total_paid (РЅРµС‚ СЂРµС„Р°РЅРґР°), kick С…РѕСЃС‚Р° РІ waiting в†’ transferHost(),
  playing СЃ СЂРµС„Р°РЅРґРѕРј (users.coins += total_paid, bank -= total_paid, removePlayerFromGame
  СЃ reason='kicked'), apartment СЃ СЂРµС„Р°РЅРґРѕРј (removePlayerFromApartment СЃ reason='kicked'),
  cannot_moderate_admin (РЅРµР»СЊР·СЏ РєРёРєРЅСѓС‚СЊ Р°РґРјРёРЅР°), room_not_found (С†РµР»СЊ РЅРµ РІ РєРѕРјРЅР°С‚Рµ),
  not_your_turn guard (РЅРµ-Р°РґРјРёРЅ), rollback РїСЂРё СЃР±РѕРµ refund-С‚СЂР°РЅР·Р°РєС†РёРё (bank/room РЅРµ С‚СЂРѕРЅСѓС‚С‹,
  delegation РЅРµ РІС‹Р·РІР°РЅ, no dangling PDO transaction)
- Р­РєРѕРЅРѕРјРёРєР°: ANCHOR_CORE.md Part 2 В§ Kick вЂ” bank -= total_paid; coins += total_paid,
  С‚СЂР°РЅР·Р°РєС†РёСЏ РѕР±СЏР·Р°С‚РµР»СЊРЅР°, СЂРµР°Р»РёР·РѕРІР°РЅРѕ С‡РµСЂРµР· СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёР№ stmt 'add_user_coins'
- РљРѕРЅСЃС‚СЂСѓРєС‚РѕСЂ AdminService СЂР°СЃС€РёСЂРµРЅ nullable-РїР°СЂР°РјРµС‚СЂРѕРј $db (РѕР±СЂР°С‚РЅР°СЏ СЃРѕРІРјРµСЃС‚РёРјРѕСЃС‚СЊ
  СЃРѕС…СЂР°РЅРµРЅР° вЂ” СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРµ РІС‹Р·РѕРІС‹ СЃ 5 Р°СЂРіСѓРјРµРЅС‚Р°РјРё РЅРµ Р»РѕРјР°СЋС‚СЃСЏ)

вљ пёЏ KNOWN GAP (RESOLVED EPIC-9.3b, 2026-08-02):
~~removePlayerFromApartment() (ApartmentService) РЅРµ РІС‹РїРѕР»РЅСЏРµС‚ host transfer РїСЂРё
kick/ban С…РѕСЃС‚Р° РІ apartment-СЃРѕСЃС‚РѕСЏРЅРёРё~~ вЂ” fixed in EPIC-9.3b.

вљ пёЏ KNOWN GAP (historical, EPIC-9.3):
removePlayerFromApartment() (ApartmentService) РЅРµ РІС‹РїРѕР»РЅСЏР» host transfer РїСЂРё
kick/ban С…РѕСЃС‚Р° РІ apartment-СЃРѕСЃС‚РѕСЏРЅРёРё, С…РѕС‚СЏ ANCHOR_CORE.md Host Rules РЅР°Р·С‹РІР°РµС‚
'kicked'/'banned' РІР°Р»РёРґРЅС‹РјРё РїСЂРёС‡РёРЅР°РјРё СЃРјРµРЅС‹ С…РѕСЃС‚Р°. РўРѕС‚ Р¶Рµ РїСЂРѕР±РµР» РїСЂРёСЃСѓС‚СЃС‚РІСѓРµС‚
Рё РІ СЃСѓС‰РµСЃС‚РІСѓСЋС‰РµРј handleBanUser() РґР»СЏ 'waiting' (РЅРµ РёСЃРїСЂР°РІР»СЏР»СЃСЏ вЂ” РІРЅРµ scope
EPIC-9.3, Epic Isolation). **Apartment path closed EPIC-9.3b; waiting ban path
still open.**

PHASE 9 вЂ” ADMIN: IN PROGRESS (9.0/9.1/9.2/9.3 done, 9.4 Close room next)

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
- tests/manual/test_admin_unban.php (РЅРѕРІС‹Р№ С„Р°Р№Р»)
Notes:
- Р РµР°Р»РёР·РѕРІР°РЅ handleUnbanUser() РґР»СЏ admin_unban_user
- Guard: С‚РѕР»СЊРєРѕ admin (assertAdmin)
- Р’Р°Р»РёРґР°С†РёСЏ: user_id > 0
- DB: PreparedStatements key unban_user (banned_until=0)
- Manual tests: 8/8 PASSED

- [DONE] EPIC-9.1 Ban user
Files:
- src/Admin/AdminService.php (diff)
- src/Infrastructure/PreparedStatements.php (РґРѕР±Р°РІР»РµРЅ user_admin_by_id)
- tests/manual/test_admin_ban.php (РЅРѕРІС‹Р№ С„Р°Р№Р»)
Notes:
- Р РµР°Р»РёР·РѕРІР°РЅ handleBanUser() СЃ duration: 1d / 3d / permanent
- Р—Р°РїСЂРµС‚ Р±Р°РЅР° Р°РґРјРёРЅРёСЃС‚СЂР°С‚РѕСЂР°: error.cannot_moderate_admin
- Р”Р»СЏ РѕРЅР»Р°Р№РЅ-С†РµР»Рё РѕС‚РїСЂР°РІР»СЏРµС‚СЃСЏ РїР°РєРµС‚ banned {until}
- РЈРґР°Р»РµРЅРёРµ РёР· РєРѕРјРЅР°С‚С‹ РїРѕ СЃС‚Р°С‚СѓСЃСѓ:
  waiting -> removePlayerFromLobby(..., 'banned')
  playing -> removePlayerFromGame(..., 'banned')
  apartment -> removePlayerFromApartment(..., 'banned')
- Manual tests: 9/9 PASSED

- [DONE] EPIC-9.0 Admin authentication
Files:
- src/Admin/AdminService.php (СЂРµР°Р»РёР·РѕРІР°РЅ)
- tests/manual/test_admin_auth.php (РЅРѕРІС‹Р№ С„Р°Р№Р»)
Notes:
- Р”РѕР±Р°РІР»РµРЅ РµРґРёРЅС‹Р№ admin guard: AdminService::assertAdmin(object $connection): bool
- РљРѕРЅС‚СЂР°РєС‚: unauthenticated -> error.auth_required, non-admin -> error.not_your_turn
- Manual tests: 8/8 PASSED

- [DONE] EPIC-8.6 Reconnect tests
Files:
- tests/manual/test_reconnect.php (РЅРѕРІС‹Р№ С„Р°Р№Р»)
Notes:
- 15/15 С‚РµСЃС‚РѕРІ РїСЂРѕР№РґРµРЅРѕ
- РџРѕРєСЂС‹С‚Рѕ: disconnect->disconnected+timer, waiting-timeout removal, reconnect restore,
  reconnect_state payload, game AFK warning, auto-draw, afk removal

- [DONE] EPIC-8.5 AFK removal вЂ” ReconnectService::removePlayerFromGame(..., 'afk')
- [DONE] EPIC-8.4 Auto draw вЂ” ReconnectService::performAutoDraw()
- [DONE] EPIC-8.3 Game AFK protection вЂ” ReconnectService::ensureGameAfkTimer()/tickGameAfk()
- [DONE] EPIC-8.2 Reconnect restoration вЂ” ReconnectService::handleReconnect()
- [DONE] EPIC-8.1 Disconnect processing вЂ” ReconnectService::handleDisconnect()
- [DONE] EPIC-8.0 ReconnectService вЂ” src/Game/ReconnectService.php (СЂРµР°Р»РёР·Р°С†РёСЏ)
Files (8.0вЂ“8.5):
- src/Game/ReconnectService.php (РЅРѕРІС‹Р№ С„Р°Р№Р», СЂРµР°Р»РёР·РѕРІР°РЅ)
Notes:
- Р РµР°Р»РёР·РѕРІР°РЅС‹ reconnect timers (15s, single-shot) Рё РІРѕСЃСЃС‚Р°РЅРѕРІР»РµРЅРёРµ РёРіСЂРѕРєР° РїРѕ session_token
- Р РµР°Р»РёР·РѕРІР°РЅР° game AFK Р·Р°С‰РёС‚Р° СЃ РїРѕСЂРѕРіР°РјРё 15/25/30СЃ, auto draw Рё СѓРґР°Р»РµРЅРёРµРј РїРѕ afk РїСЂРё 3 Р°РІС‚РѕС…РѕРґР°С…

PHASE 8 вЂ” RECONNECT & AFK: COMPLETE (service + manual tests)

- [DONE] EPIC-7.6 Apartment integration tests
Files:
- tests/Manual/test_apartment.php (РЅРѕРІС‹Р№ С„Р°Р№Р»)
Notes:
- 32/32 С‚РµСЃС‚РѕРІ РїСЂРѕР№РґРµРЅРѕ
- РџРѕРєСЂС‹С‚Рѕ: hasLine, shouldTrigger, prepareApartment, allRequiredAnswered,
  alert broadcast, agreeв†’payment, refuseв†’removal, re-trigger blocked

- [DONE] EPIC-7.5 Apartment timeout вЂ” ApartmentService::onApartmentTimeout()
- [DONE] EPIC-7.4 Apartment payment transaction вЂ” ApartmentService::finishApartment()
- [DONE] EPIC-7.3 Apartment voting вЂ” ApartmentService::handleApartmentChoice()
- [DONE] EPIC-7.2 Apartment state вЂ” ApartmentService::triggerApartment()
- [DONE] EPIC-7.1 Apartment trigger вЂ” ApartmentService::shouldTrigger() / prepareApartment()
- [DONE] EPIC-7.0 Line detection вЂ” ApartmentService::hasLine()
Files (7.0вЂ“7.5):
- src/Game/ApartmentService.php (470 СЃС‚СЂРѕРє вЂ” РїРѕР»РЅС‹Р№ РѕСЂРєРµСЃС‚СЂР°С‚РѕСЂ)
- src/Game/GameService.php (735 СЃС‚СЂРѕРє вЂ” С‚РѕРЅРєРёРµ РґРµР»РµРіР°С‚РѕСЂС‹)
Notes:
- ApartmentService СЂР°СЃС€РёСЂРµРЅ РґРѕ РѕСЂРєРµСЃС‚СЂР°С‚РѕСЂР° (db, stmts, logger РІ РєРѕРЅСЃС‚СЂСѓРєС‚РѕСЂРµ)
- GameService СЃРѕРєСЂР°С‰С‘РЅ СЃ 985 РґРѕ 735 СЃС‚СЂРѕРє
- GameService::handleApartmentChoice() / triggerApartment() вЂ” РїСѓР±Р»РёС‡РЅС‹Рµ РґРµР»РµРіР°С‚РѕСЂС‹

PHASE 7 вЂ” APARTMENT: COMPLETE
PHASE 8 вЂ” RECONNECT & AFK: COMPLETE

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
- tests/Manual/test_apartment.php (РЅРѕРІС‹Р№ С„Р°Р№Р»)
Notes:
- 32/32 С‚РµСЃС‚РѕРІ РїСЂРѕР№РґРµРЅРѕ
- РџРѕРєСЂС‹С‚Рѕ: hasLine (empty/full/partial), shouldTrigger (line/fired/disconnected),
  prepareApartment (status, flags, required), allRequiredAnswered,
  alert broadcast (required/immune), agreeв†’payment (bank, immune, commit),
  refuseв†’removal (player_left, drawer_order), re-trigger blocked

- [DONE] EPIC-7.5 Apartment timeout вЂ” GameService::onApartmentTimeout()
- [DONE] EPIC-7.4 Apartment payment transaction вЂ” finishApartment() PDO
- [DONE] EPIC-7.3 Apartment voting вЂ” GameService::handleApartmentChoice()
- [DONE] EPIC-7.2 Apartment state вЂ” GameService::triggerApartment()
- [DONE] EPIC-7.1 Apartment trigger вЂ” ApartmentService::shouldTrigger() / prepareApartment()
- [DONE] EPIC-7.0 Line detection вЂ” ApartmentService::hasLine()
Files (7.0вЂ“7.5):
- src/Game/ApartmentService.php (РЅРѕРІС‹Р№ С„Р°Р№Р», 222 СЃС‚СЂРѕРєРё)
- src/Game/GameService.php (diff, 985 СЃС‚СЂРѕРє)
Notes:
- Victory > Apartment: РїСЂРѕРІРµСЂРєР° РїРѕР±РµРґС‹ РёРґС‘С‚ РґРѕ shouldTrigger() РІ handleDrawBarrel()
- immune=true РїРѕСЃР»Рµ agree вЂ” РїРѕРІС‚РѕСЂРЅС‹Р№ Р°РїР°СЂС‚Р°РјРµРЅС‚ РЅРµ С‚СЂРµР±СѓРµС‚ РїР»Р°С‚С‹
- apartment_fired вЂ” at most once per game

PHASE 7 вЂ” APARTMENT: COMPLETE

вљ пёЏ KNOWN GAP вЂ” ADR REQUIRED:
GameService 985 СЃС‚СЂРѕРє вЂ” РІРїР»РѕС‚РЅСѓСЋ Рє mandatory refactor (1000).
РљР°РЅРґРёРґР°С‚С‹ РЅР° РґРµРєРѕРјРїРѕР·РёС†РёСЋ: finishGame(), handleNoSurvivors() в†’ РѕС‚РґРµР»СЊРЅС‹Р№ GameFinishService.
РќРµРѕР±С…РѕРґРёРјРѕ РґРѕ РЅР°С‡Р°Р»Р° Phase 8.

Integration tests:
48 / 48 PASSED (auth)
90 / 90 PASSED (lobby)
164 / 164 PASSED (lotto engine)
44 / 44 PASSED (game start)
37 / 37 PASSED (turn system)
38 / 38 PASSED (victory system)
32 / 32 PASSED (apartment)

Next planned Epic: EPIC-8.0 ReconnectService
вљ пёЏ Before Phase 8: ADR for GameService decomposition required.

- [DONE] EPIC-6.5 Victory system tests
Files:
- tests/Manual/test_victory.php (РЅРѕРІС‹Р№ С„Р°Р№Р»)
Notes:
- 38/38 С‚РµСЃС‚РѕРІ РїСЂРѕР№РґРµРЅРѕ
- РџРѕРєСЂС‹С‚Рѕ: checkCardVictory (0/1/2 wins), checkAllVictories (disconnected skip),
  calculatePrize (floor division, remainder burn, double+normal),
  finishGame (payout, room destruction, game_over broadcast, DB rollback),
  full draw-until-victory integration test

- [DONE] EPIC-6.4 Game finish flow вЂ” GameService::finishGame()
- [DONE] EPIC-6.3 Winner payout transaction вЂ” all-or-nothing PDO
- [DONE] EPIC-6.2 Prize calculation вЂ” VictoryService::calculatePrize()
- [DONE] EPIC-6.1 Double victory detection вЂ” РІСЃС‚СЂРѕРµРЅР° РІ checkCardVictory()
- [DONE] EPIC-6.0 Victory detection вЂ” VictoryService::checkCardVictory() / checkAllVictories()
Files (6.0вЂ“6.4):
- src/Game/VictoryService.php (РЅРѕРІС‹Р№ С„Р°Р№Р», 146 СЃС‚СЂРѕРє)
- src/Game/GameService.php (diff, 703 СЃС‚СЂРѕРєРё)
Notes:
- markNumber() РІ handleDrawBarrel() РїСЂРёРјРµРЅСЏРµС‚СЃСЏ РєРѕ РІСЃРµРј Р°РєС‚РёРІРЅС‹Рј РёРіСЂРѕРєР°Рј
- GameService 703 СЃС‚СЂРѕРєРё вЂ” Р·РѕРЅР° warning; finishGame() РєР°РЅРґРёРґР°С‚ РЅР° ADR-РґРµРєРѕРјРїРѕР·РёС†РёСЋ

PHASE 6 вЂ” VICTORY SYSTEM: COMPLETE

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
- tests/Manual/test_turn_system.php (РЅРѕРІС‹Р№ С„Р°Р№Р»)
Notes:
- 37/37 С‚РµСЃС‚РѕРІ РїСЂРѕР№РґРµРЅРѕ
- РџРѕРєСЂС‹С‚Рѕ: sendYourTurn, nextDrawer (cyclic, skip disconnected, skip removed, null),
  handleDrawBarrel (guards, bag, drawn_numbers, AFK reset, broadcast, rotation),
  markNumber (column mapping, multi-cell, unknown number),
  full 2-player 3-turn cycle

- [DONE] EPIC-5.4 Player card marking вЂ” GameService::markNumber()
- [DONE] EPIC-5.3 Broadcast drawn barrel вЂ” barrels_drawn packet
- [DONE] EPIC-5.2 Draw barrel вЂ” GameService::handleDrawBarrel()
- [DONE] EPIC-5.1 Drawer rotation вЂ” GameService::nextDrawer()
- [DONE] EPIC-5.0 Drawer queue вЂ” GameService::sendYourTurn()
Files (5.0вЂ“5.4):
- src/Game/GameService.php (diff, 564 СЃС‚СЂРѕРєРё)
Notes:
- masks РёРЅРёС†РёР°Р»РёР·РёСЂСѓСЋС‚СЃСЏ РІ handleStartGame (bool[cardsCount][3][9], РІСЃРµ false)
- markNumber() РїСѓР±Р»РёС‡РЅС‹Р№ вЂ” РёСЃРїРѕР»СЊР·СѓРµС‚СЃСЏ VictoryService РІ Phase 6
- peekNextDrawer() РїСЂРёРІР°С‚РЅС‹Р№ вЂ” С‚РѕР»СЊРєРѕ РґР»СЏ next_drawer РІ РїР°РєРµС‚Рµ barrels_drawn

PHASE 5 вЂ” TURN SYSTEM: COMPLETE

Integration tests:
48 / 48 PASSED (auth)
90 / 90 PASSED (lobby)
164 / 164 PASSED (lotto engine)
44 / 44 PASSED (game start)
37 / 37 PASSED (turn system)

Next planned Epic: EPIC-6.0 Victory detection
- [DONE] EPIC-4.5 Game initialization tests
Files:
- tests/Manual/test_game_start.php (РЅРѕРІС‹Р№ С„Р°Р№Р»)
Notes:
- 44/44 С‚РµСЃС‚РѕРІ РїСЂРѕР№РґРµРЅРѕ
- РџРѕРєСЂС‹С‚Рѕ: auth guard, room guard, host guard, status guard, min players,
  insufficient coins, bank calculation, bag generation, card assignment,
  transaction commit, game_started packet (is_self, cards, masks, drawer_order),
  AFK fields reset

- [DONE] EPIC-4.4 Game start protocol вЂ” GameService::handleStartGame()
- [DONE] EPIC-4.3 StartGame transaction вЂ” all-or-nothing PDO transaction
- [DONE] EPIC-4.2 Bank creation вЂ” bank = sum(total_paid)
- [DONE] EPIC-4.1 Game initialization вЂ” status=playing, bag, cards, drawer
- [DONE] EPIC-4.0 Player card purchase logic вЂ” total_paid = cards_count Г— BET_PER_CARD
Files (4.0вЂ“4.4):
- src/Game/GameService.php (РЅРѕРІС‹Р№ С„Р°Р№Р», 301 СЃС‚СЂРѕРєР°)
- src/Infrastructure/PreparedStatements.php (РґРѕР±Р°РІР»РµРЅ user_by_id)

PHASE 4 вЂ” GAME START: COMPLETE

Integration tests:
48 / 48 PASSED (auth)
90 / 90 PASSED (lobby)
164 / 164 PASSED (lotto engine)
44 / 44 PASSED (game start)

Next planned Epic: EPIC-5.0 Drawer queue
- [DONE] EPIC-3.4 Engine test suite
Files:
- tests/Manual/test_lotto_engine.php (РЅРѕРІС‹Р№ С„Р°Р№Р»)
Notes:
- 164/164 С‚РµСЃС‚РѕРІ РїСЂРѕР№РґРµРЅРѕ
- РџРѕРєСЂС‹С‚С‹: generateCard, generateBag, validateCard, validateBag
- 100 РёС‚РµСЂР°С†РёР№ generateCard, 20 РёС‚РµСЂР°С†РёР№ generateBag
- РљРѕР»РѕРЅРѕС‡РЅС‹Рµ РёРЅРІР°СЂРёР°РЅС‚С‹: >=1 С‡РёСЃР»Рѕ РЅР° СЃС‚РѕР»Р±РµС†, СЃРѕСЂС‚РёСЂРѕРІРєР° top-to-bottom
- CSPRNG: Fisher-Yates + random_int() РІРѕ РІСЃРµС… shuffle-РѕРїРµСЂР°С†РёСЏС…

- [DONE] EPIC-3.3 Bag validator вЂ” LottoEngine::validateBag()
- [DONE] EPIC-3.2 Card validator вЂ” LottoEngine::validateCard()
- [DONE] EPIC-3.1 Bag generator вЂ” LottoEngine::generateBag()
- [DONE] EPIC-3.0 Card generator вЂ” LottoEngine::generateCard() (mask-based Р°Р»РіРѕСЂРёС‚Рј)
Files (3.0вЂ“3.3):
- src/Game/LottoEngine.php (РЅРѕРІС‹Р№ С„Р°Р№Р», Р·Р°РјРµРЅРµРЅР° Р·Р°РіР»СѓС€РєР°)

PHASE 3 вЂ” LOTTO ENGINE: COMPLETE

- [DONE] EPIC-2.7 Lobby integration tests
Files:
- tests/Manual/test_lobby_integration.php (РЅРѕРІС‹Р№ С„Р°Р№Р»)
- tests/Manual/mock_timer.php (РЅРѕРІС‹Р№ С„Р°Р№Р»)
Notes:
- 90/90 С‚РµСЃС‚РѕРІ РїСЂРѕР№РґРµРЅРѕ
- РџРѕРєСЂС‹С‚Рѕ: RoomManager, handleCreateRoom, handleJoinRoom, handleLeaveRoom,
  removePlayerFromLobby, all_players_history, transferHost, handleRoomList,
  Lobby AFK Timer (MockTimer stub Р±РµР· event loop)
- Workerman\Timer РїРѕРґРјРµРЅС‘РЅ С‡РµСЂРµР· mock_timer.php (namespace stub)
- Р¤СѓРЅРєС†РёРѕРЅР°Р»СЊРЅС‹Р№ WebSocket С‚РµСЃС‚ РѕС‚Р»РѕР¶РµРЅ РґРѕ EPIC-10.x (server.php РЅРµ СЃРѕР·РґР°РЅ)

Commit: EPIC-2.7 lobby-integration-tests

- [DONE] EPIC-2.6 Lobby AFK system
Files:
- src/Lobby/LobbyService.php (diff)
Notes:
- startLobbyAfkTimer(): РѕС‚РјРµРЅСЏРµС‚ РїСЂРµРґС‹РґСѓС‰РёР№ в†’ Timer::add(1s repeat) в†’ РїСЂРѕРІРµСЂСЏРµС‚ time()-host.last_action >= 120s в†’ transferHost()
- stopLobbyAfkTimer(): Timer::del + lobby_afk_timer_id = null
- handleJoinRoom(): РІС‹Р·РѕРІ startLobbyAfkTimer() РєРѕРіРґР° count(players) >= 2
- handleLeaveRoom(): РІС‹Р·РѕРІ stopLobbyAfkTimer() РєРѕРіРґР° count(players) < 2 РїРѕСЃР»Рµ СѓРґР°Р»РµРЅРёСЏ
- destroyRoom() СѓР¶Рµ РѕС‚РјРµРЅСЏРµС‚ С‚Р°Р№РјРµСЂ вЂ” РґСѓР±Р»РёСЂРѕРІР°РЅРёСЏ РЅРµС‚
- Р”РѕР±Р°РІР»РµРЅ use Workerman\Timer
- Known gap Р·Р°РєСЂС‹С‚ (Р·Р°С„РёРєСЃРёСЂРѕРІР°РЅ РІ EPIC-2.3)
- Р¤СѓРЅРєС†РёРѕРЅР°Р»СЊРЅС‹Р№ С‚РµСЃС‚ (WebSocket) РѕС‚Р»РѕР¶РµРЅ РґРѕ EPIC-10.x (server.php РЅРµ СЃРѕР·РґР°РЅ)

Commit: EPIC-2.6 lobby-afk-system

- [DONE] EPIC-2.5 Host transfer
Files:
- src/Lobby/LobbyService.php (СЂРµР°Р»РёР·РѕРІР°РЅРѕ РІ СЂР°РјРєР°С… EPIC-2.3)
Notes:
- transferHost(): FIFO РїРѕ drawer_order СЃСЂРµРґРё active в†’ РЅРѕРІС‹Р№ host_conn_id
- Р’С‹Р·С‹РІР°РµС‚СЃСЏ РёР· handleLeaveRoom() РїСЂРё $wasHost === true
- Р•СЃР»Рё РЅРµС‚ Р°РєС‚РёРІРЅС‹С… РёРіСЂРѕРєРѕРІ в†’ destroyRoom()
- РћС‚РґРµР»СЊРЅРѕРіРѕ РєРѕРґР° РЅРµ РїРѕС‚СЂРµР±РѕРІР°Р»РѕСЃСЊ: Р»РѕРіРёРєР° РїРѕРєСЂС‹С‚Р° EPIC-2.3

Commit: (РІС…РѕРґРёС‚ РІ EPIC-2.3 leave-room)

- [DONE] EPIC-2.4 Room list
Files:
- src/Lobby/LobbyService.php (diff)
Notes:
- handleRoomList(): auth guard в†’ РёС‚РµСЂР°С†РёСЏ $worker->rooms в†’ buildRoomListEntry() в†’ room_list РїР°РєРµС‚
- Р’РѕР·РІСЂР°С‰Р°СЋС‚СЃСЏ РІСЃРµ РєРѕРјРЅР°С‚С‹ РІ Р»СЋР±РѕРј СЃС‚Р°С‚СѓСЃРµ (waiting / playing / apartment)
- Р¤РѕСЂРјРёСЂРѕРІР°РЅРёРµ entry РґРµР»РµРіРёСЂРѕРІР°РЅРѕ RoomManager::buildRoomListEntry() (EPIC-2.0)
- РџСЂРѕС‚РѕРєРѕР»: {"type":"room_list","rooms":[...]} вЂ” ANCHOR_PROTOCOL.md В§ Lobby

Commit: EPIC-2.4 room-list

- [DONE] EPIC-2.3 Leave room
Files:
- src/Lobby/LobbyService.php (diff)
Notes:
- handleLeaveRoom(): auth в†’ findRoomIdByConnId в†’ guard status=waiting в†’ removePlayerFromLobby в†’ transferHost РµСЃР»Рё СѓС€С‘Р» С…РѕСЃС‚
- removePlayerFromLobby(): Р·Р°РїРёСЃСЊ РІ all_players_history в†’ unset players в†’ РѕС‡РёСЃС‚РєР° drawer_order в†’ destroyRoom РµСЃР»Рё РїСѓСЃС‚Рѕ в†’ broadcast player_left Р°РєС‚РёРІРЅС‹Рј
- transferHost(): FIFO РїРѕ drawer_order СЃСЂРµРґРё active в†’ destroyRoom РµСЃР»Рё РЅРµС‚ Р°РєС‚РёРІРЅС‹С…
- РџСЂРѕС‚РѕРєРѕР»: player_left {type, username, reason} вЂ” С‚РѕР»СЊРєРѕ Р°РєС‚РёРІРЅС‹Рј, РЅРµ СѓС…РѕРґСЏС‰РµРјСѓ
- Р­РєРѕРЅРѕРјРёРєР°: РјРѕРЅРµС‚С‹ РЅРµ Р·Р°С‚СЂРѕРЅСѓС‚С‹ (total_paid=0 РІ waiting)
- Known gap: lobby_afk_timer_id РїСЂРё count<2 РЅРµ РѕС‚РјРµРЅСЏРµС‚СЃСЏ вЂ” СѓСЃС‚СЂР°РЅСЏРµС‚СЃСЏ РІ EPIC-2.6

Commit: 5974582 (git commit -m "EPIC-2.3 leave-room")

Commit: 5974582 (git commit -m "EPIC-2.0 room-manager")

- [DONE] EPIC-2.1 Create room
Files:
- src/Lobby/LobbyService.php

Notes:
- handleCreateRoom(): РІР°Р»РёРґР°С†РёСЏ Р»РёРјРёС‚РѕРІ в†’ bcrypt РїР°СЂРѕР»СЊ в†’ RoomManager::createRoom() в†’ player entry в†’ room_joined
- РџСЂРѕРІРµСЂРєРё: MAX_ROOMS, MAX_TOTAL_PLAYERS, cards_count в€€ {1,2}, max_players в€€ [2..10]
- РњРѕРЅРµС‚С‹ РЅРµ СЃРїРёСЃС‹РІР°СЋС‚СЃСЏ (Reservation Rule, ANCHOR_CORE Part 2)
- drawer_order РёРЅРёС†РёР°Р»РёР·РёСЂСѓРµС‚СЃСЏ С…РѕСЃС‚РѕРј (ANCHOR_CORE В§ Drawer Order Rules)
- РљР°СЂС‚С‹ РЅРµ РЅР°Р·РЅР°С‡Р°СЋС‚СЃСЏ вЂ” РґРµР»РµРіРёСЂРѕРІР°РЅРѕ start_game() (EPIC-4.1)

Commit: 5974582 (git commit -m "EPIC-2.1 create-room")

- [DONE] EPIC-2.2 Join room
Files:
- src/Lobby/LobbyService.php (diff)
Notes:
- handleJoinRoom(): auth в†’ room exists в†’ status=waiting в†’ not full в†’ MAX_TOTAL_PLAYERS в†’ password в†’ cards_count в†’ player entry в†’ room_joined в†’ broadcast player_joined
- РџР°СЂРѕР»СЊ: password_verify(bcrypt)
- drawer_order: FIFO append (ANCHOR_CORE В§ Drawer Order Rules)
- room_joined в†’ РІС…РѕРґСЏС‰РµРјСѓ; player_joined в†’ РѕСЃС‚Р°Р»СЊРЅС‹Рј Р°РєС‚РёРІРЅС‹Рј
Commit: 5974582

---

## PRE-BUILT COMPONENTS

### PRE-BUILT-1 вЂ” Reconnect Token Infrastructure
Status: Completed (РёР·РѕР»РёСЂРѕРІР°РЅ, РїРѕРєР° РЅРµ РёСЃРїРѕР»СЊР·СѓРµС‚СЃСЏ)

Files:
- src/Auth/ReconnectTokenService.php

Notes:
- Р“РµРЅРµСЂР°С†РёСЏ Рё РІР°Р»РёРґР°С†РёСЏ 64-СЃРёРјРІРѕР»СЊРЅС‹С… HEX С‚РѕРєРµРЅРѕРІ РїРµСЂРµРїРѕРґРєР»СЋС‡РµРЅРёСЏ.
- РќРµ РёРЅС‚РµРіСЂРёСЂРѕРІР°РЅ РІ С‚РµРєСѓС‰РёР№ РїСЂРѕС‚РѕРєРѕР».
- РџР»Р°РЅРёСЂСѓРµРјС‹Р№ РїРѕС‚СЂРµР±РёС‚РµР»СЊ: EPIC-8.0 ReconnectService.

---

## PATCHES

## FIX-12 вЂ” Test loggers writing into the production log file
Status: Completed
Date: 2026-07-25

Found during: a live operational incident, not a proactive audit this
time. A permission-ownership mismatch (`game.db`/`workerman.log`/
`logs/server.log` left root-owned after test runs executed as root
against the live VPS, while the production `lotto-server.service` runs
as `www-data`) caused a real crash-loop on the production service.
While diagnosing that, a confusing `[ERROR] ... CHECK constraint failed:
coins <= 200` line was found in `logs/server.log` вЂ” alarming at first
glance, since no such constraint exists in the real schema
(`docs/... .schema users` confirmed no CHECK clause). Traced to its
actual source rather than assumed.

Files:
- src/Core/Logger.php (diff вЂ” optional `?string $logFilePath = null`
  constructor parameter, mirroring the FIX-4 precedent for
  `Database::__construct(?PDO $pdo = null)`. Default (no argument)
  preserves exact prior behavior вЂ” server.php's own `new Logger()` needs
  no change at all.)
- tests/Manual/test_login.php (diff)
- tests/Manual/test_register.php (diff)
- tests/Manual/test_session_service.php (diff)
- tests/Manual/test_single_session.php (diff)
- tests/Manual/test_victory.php (diff вЂ” the actual source of the
  incident's confusing ERROR line)
- tests/Manual/test_admin_logs.php (diff)
- tests/Manual/test_admin_integration.php (diff)
- tests/Manual/test_logger.php (DELETED вЂ” see below)

Problem:
- `Logger::__construct()` hardcoded the log path to `logs/server.log`
  with no way to inject a different one. Any test constructing a real
  `Logger` (not a `MockLogger`) вЂ” which several do purely incidentally,
  as a required dependency of `AuthService`/`GameFinishService`/
  `AdminService`, with no interest in testing logging itself вЂ” wrote
  straight into the shared production log on every run.
- `tests/Manual/test_victory.php`'s `makeSvc()` (added in FIX-4) builds a
  real `GameFinishService` over an isolated **in-memory** SQLite database
  specifically to test transaction rollback via a deliberately-rigged
  `CHECK(coins <= 200)` constraint вЂ” genuinely correct DB isolation. But
  it paired that with a real, default-path `Logger`, so the rigged
  failure's error message still landed in the real `logs/server.log`,
  indistinguishable from an actual production incident. The existing
  code comment at that exact line already said "РїРѕР±РѕС‡РЅС‹Р№ СЌС„С„РµРєС‚ вЂ” Р·Р°РїРёСЃСЊ
  РІ logs/server.log" (side effect вЂ” writes to logs/server.log) вЂ”
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
  `test_auth_integration.php` already called `new Logger('/dev/null')` вЂ”
  an earlier session's own attempt to solve exactly this problem. It
  never worked: PHP does not error when extra arguments are passed to a
  zero-parameter constructor (unlike stricter-arity languages), so the
  `'/dev/null'` argument was silently discarded and the call was
  equivalent to `new Logger()` the whole time. This fix makes that
  existing, previously-non-functional intent actually work, at zero
  additional cost вЂ” no code change needed in either file.
- `tests/Manual/test_logger.php` (distinct from the project-root copy
  already deleted in a much earlier session, per the 2026-07-03 decision
  log entry) was a leftover duplicate: a print_r()-based smoke script
  with zero assertions, explicitly documented by this project's own
  EPIC-9.6 entry and by `test_admin_logs.php`'s own header comment as
  superseded. It ran on every `run_ALL_tests.sh` pass, wrote generic
  "test 1"/"test 2"/"test 3" lines into the real log, and contributed
  nothing (no pass/fail signal at all) вЂ” deleted.

Deliberately NOT changed: `tests/Manual/test_helpers_runner.php`
Scenario 4 and `server.php` itself. Scenario 4's entire purpose is
verifying that the *default* `Logger` path is genuinely
`logs/server.log` вЂ” redirecting it would break the one thing it's
testing. `server.php`'s own `new Logger()` is correct production code by
definition.

Known, explicitly out-of-scope for this fix (lower severity, different
category вЂ” see decision log): the real-WS-client subprocess tests
(`test_auth_packet_routing.php`, `test_lobby_packet_routing.php`,
`test_game_packet_routing.php`, `test_admin_packet_routing.php`,
`test_session_lifecycle.php`, `test_packet_validation.php`,
`test_server_bootstrap.php`) each spawn a genuine `php server.php start`
subprocess to exercise real end-to-end routing вЂ” that subprocess's
`Logger` is unmodified production code, correctly writing to the real
log path, because it *is* the real server. This still leaves
test-generated `INFO`/`WARNING` lines with test-like usernames
(`fix10_user1`, `e106_admin`, etc.) in the production log вЂ” clearly
identifiable as test noise, not the confusing false-`ERROR` class of
problem this fix targets. Properly isolating it would require making
`server.php`'s own log path configurable (an env var, defaulting to the
current path) and updating all seven harnesses to set it вЂ” a larger,
separate change touching production code, left for an explicit future
decision rather than folded in here silently.

Verified:
- All 5 originally-affected tests re-run individually with an MD5 hash
  of `logs/server.log` captured before and after each вЂ” byte-identical
  in every case (confirmed no write occurred).
- Full `run_ALL_tests.sh` re-run with the same before/after hash check
  across the *entire* suite вЂ” the only lines that appear afterward
  originate from the real-WS-subprocess tests described above (expected,
  out of scope), not from any of the fixed files.
- `test_helpers_runner.php` re-run in isolation вЂ” still correctly writes
  to and reads back from the real default path, confirming `Logger`'s
  default behavior is byte-for-byte unchanged.
- Every affected test's pass count matches its previously-documented
  count exactly (40/40 victory, 91/91 lobby integration, 55/55 auth
  integration, 20/20 admin integration, etc.) вЂ” no behavior change, only
  the log destination.
- Full regression across all 29 remaining `tests/Manual/*.php` files (30
  minus the deleted `test_logger.php`) вЂ” 0 failed.

No ADR required вЂ” no protocol, economy, timer, or room/player structure
touched. Purely a test-isolation and logging-infrastructure fix.

Diff: patches/FIX-12-Logger.patch, patches/FIX-12-test-login.patch,
patches/FIX-12-test-register.patch, patches/FIX-12-test-session-service.patch,
patches/FIX-12-test-single-session.patch, patches/FIX-12-test-victory.patch,
patches/FIX-12-test-admin-logs.patch, patches/FIX-12-test-admin-integration.patch

## FIX-16 вЂ” server.php bootstrap helper missing from committed Helpers.php
Status: Completed
Date: 2026-07-28

Found during: full `./run_ALL_tests.sh` on the Ubuntu VPS at the end of
EPIC-13.4 sign-off вЂ” not during local Windows dev, where the committed
`run_ALL_tests.php` still skips the eight live-WS-subprocess tests via
`$skipOnWindows` (FIX-15 intent documented in `docs/LOCAL_ENVIRONMENT.md`
but the bootstrap helpers themselves were never committed).

Background (FIX-15): `lottoBootstrapPhpExtensions()` and `lottoPhpIniArgs()`
were developed locally for Windows SQLite bootstrap and child-process
`proc_open` spawning. They lived only in an **uncommitted** diff to
`src/Core/Helpers.php` alongside local edits to `run_ALL_tests.php`.

Breaking commit: `b203493` (EPIC-13.1) added
`lottoBootstrapPhpExtensions()` to `server.php:109` (and the corresponding
`use function` import) вЂ” copied from the local uncommitted state вЂ” without
the function definition being present in the repository. On Linux/VPS the
call is a no-op when defined, but **fatal when undefined**.

Symptom on VPS (`/opt/lotto-game`, `./run_ALL_tests.sh` after `git pull`
to Phase 13 HEAD before this fix):
- Eight live WS subprocess tests failed with
  `server.php did not bind port вЂ¦ in time (running=no)`.
- stderr on every spawned `server.php`:
  `PHP Fatal error: Call to undefined function
  Lotto\Core\lottoBootstrapPhpExtensions() in server.php:109`.

Affected tests (all subprocess-spawned `server.php`):
`test_admin_packet_routing.php`, `test_auth_packet_routing.php`,
`test_game_packet_routing.php`, `test_lobby_packet_routing.php`,
`test_packet_validation.php`, `test_server_bootstrap.php`,
`test_session_lifecycle.php`, `test_protocol_audit.php`.

Files:
- src/Core/Helpers.php (diff вЂ” add `lottoBootstrapPhpExtensions()` and
  `lottoPhpIniArgs()`; both no-op / empty-array on Linux)

Fix commit: `0de46d0` вЂ” `Fix missing lottoBootstrapPhpExtensions in committed
Helpers.php.`

Verified:
- Fresh `git clone` from GitHub at `0de46d0` (branch
  `cursor/epic-11-1-vps-ws-test-isolation`, no workspace-local files):
  `php server.php start` with isolated `LOTTO_WS_PORT` reaches Workerman
  `[ok]` вЂ” no fatal error (Windows dev host, 2026-07-28; `composer install`
  not available in agent environment вЂ” vendor copied from lockfile-matched
  tree for bind test only).
- Local workspace `php run_ALL_tests.php` at `0de46d0`+: **41/41** test
  files PASS (Windows dev host, 2026-07-28; uses uncommitted runner with
  FIX-15 Windows WS enablement).
- VPS `./run_ALL_tests.sh` after `git pull` to `0de46d0`:
  **MANUAL VERIFICATION REQUIRED** вЂ” agent has no SSH access to
  `/opt/lotto-game`. Expected: all `tests/Manual/test_*.php` pass (41 files
  at HEAD); the eight subprocess tests above must reach port bind.

Process lesson (same class as FIX-12): local-only or root-owned artifacts
masked a production-breaking gap until the VPS-authoritative test run.
Any symbol `server.php` calls must be committed **in the same commit or an
earlier one** before the call lands. Uncommitted helper functions
referenced by committed entrypoints are a release blocker вЂ” Windows skips
are not a substitute for Ubuntu sign-off per `LOCAL_ENVIRONMENT.md`.

No ADR required вЂ” no protocol, economy, timer, or room/player structure
touched. Purely a missing-dependency / process-discipline fix.

Diff: commit `0de46d0` (src/Core/Helpers.php only)

## EPIC-10.7 вЂ” Protocol integration tests
Status: Completed
Date: 2026-07-24

Files:
- tests/Manual/test_protocol_completeness.php (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” 50 assertions)

Scope, per explicit user direction: this Epic checks that everything
ANCHOR_CORE.md/ANCHOR_PROTOCOL.md *declare* is actually *present* on the
server side вЂ” a completeness/coverage audit, not a re-test of business
logic. Business logic is already exhaustively covered: every module has
its own real-WS-client routing test (test_auth_packet_routing.php,
test_lobby_packet_routing.php, test_game_packet_routing.php,
test_admin_packet_routing.php) plus dozens of Phase-specific unit tests.
Re-testing that logic here would be redundant, not thorough.

Deliberately a static source-cross-reference test, not a live-server one
вЂ” it parses the actual registries out of docs/ANCHOR_CORE.md and
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

Result: 50/50 PASSED, 0 failed, 3 warnings вЂ” all three warnings match
already-documented KNOWN GAPS, no new surprises:
- `admin_stats_data` (packet type): declared, zero emission sites вЂ”
  already flagged (2026-07-03 audit) as unimplemented/no Epic assigned.
- `afk_warning` (packet type): emitted (ReconnectService, EPIC-8.3), not
  declared in the registry вЂ” already flagged as documentation debt.
- `error.banned` (error code): declared, zero usage sites вЂ” **new
  finding this Epic**. Not a functional gap: the dedicated `banned`
  packet type (`{"type":"banned","until":...}`) already covers every
  ban-rejection path (login, reconnect since FIX-11, admin notification)
  вЂ” `error.banned` appears to be a redundant/unused declaration in the
  Error Packet Codes registry, never actually needed once the dedicated
  packet type existed. Logged as a new KNOWN GAP (low priority,
  documentation-only) rather than touched: ANCHOR_PROTOCOL.md states it
  "Never changes," and removing a declared code вЂ” even an unused one вЂ”
  is arguably a change to that document; left for an explicit user
  decision (same treatment as the admin_stats_data gap: either assign it
  a purpose or formally deprecate it).

No code defects found by this Epic вЂ” confirms the wiring built across
EPIC-10.0-10.6 is genuinely complete against the declared protocol
surface, not just working for the specific scenarios the routing tests
happened to cover.

PHASE 10 вЂ” WEBSOCKET PROTOCOL: COMPLETE (10.0-10.7 all done).

Diff: patches/EPIC-10.7-test-protocol-completeness.patch (new file, full
content вЂ” see also tests/Manual/test_protocol_completeness.php directly)


Status: Completed
Date: 2026-07-24

Files:
- src/Admin/AdminHandler.php (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” thin wrapper РЅР°Рґ AdminService,
  С‚РѕС‚ Р¶Рµ РїР°С‚С‚РµСЂРЅ С‡С‚Рѕ GameHandler/LobbyHandler)
- server.php (diff вЂ” AdminService/AdminHandler dependency wiring in
  onWorkerStart, РІСЃРµ 5 admin_* actions РґРѕР±Р°РІР»РµРЅС‹ РІ dispatcher; СЃРј. FIX-11
  РЅРёР¶Рµ вЂ” С‡Р°СЃС‚СЊ СЌС‚РѕРіРѕ Р¶Рµ diff)
- src/Admin/AdminService.php (diff вЂ” FIX-11, СЃРј. РЅРёР¶Рµ)
- src/Auth/AuthHandler.php (diff вЂ” FIX-11, ban check in handleReconnect())
- src/Auth/AuthService.php (diff вЂ” FIX-11, getUserById() returns
  banned_until now too)
- src/Infrastructure/PreparedStatements.php (diff вЂ” FIX-11, extended
  user_auth_fields_by_id to include banned_until)
- tests/Manual/test_admin_ban.php (diff вЂ” FIX-11, MockConnection needs a
  close() method now that handleBanUser() actually calls it)
- tests/Manual/test_admin_integration.php (diff вЂ” FIX-11, same fix for
  SpyConnection)
- tests/Manual/test_admin_packet_routing.php (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” 15 assertions,
  real WS client, covers both EPIC-10.6 routing and FIX-11 regression
  scenarios together since FIX-11 was found while probing this Epic's own
  wiring вЂ” same pattern as EPIC-10.5/FIX-9)

AdminService already existed and was fully tested (Phase 9) вЂ” the routing
part of this Epic is, like every other EPIC-10.x, pure dependency wiring.
The one thing that made this wiring non-trivial: AdminService's
constructor takes 7 nullable dependencies (stmts, logger, lobbyService,
reconnectService, apartmentService, db, roomManager), and several of them
degrade silently rather than erroring if omitted вЂ” missing
lobbyService/reconnectService/apartmentService means a banned/kicked
online player is never actually removed from their room (money still
moves correctly, but a "ghost" player entry lingers); missing roomManager
means admin_close_room falls back to a raw unset() that skips ALL timer
cleanup, the exact class of bug FIX-6 fixed elsewhere. All seven are now
wired. $apartmentService is deliberately the same local variable already
in scope from the EPIC-10.5 block (never stored as a $worker property,
since only GameService needed it there) rather than retroactively
touching completed EPIC-10.5 code вЂ” captured by closure scope instead.

Found and fixed during this Epic's audit (FIX-11) вЂ” proactively looking
for another FIX-9/FIX-10-class interaction bug before shipping, per user
request:

Problem (three compounding gaps, all in the ban path specifically вЂ”
handleKickUser() was already correct):
1. AdminService::handleBanUser()'s structural room-removal
   (findPlayerMembership() + removePlayerFromLobby/Game/Apartment) was
   nested INSIDE `if (isset($worker->userConnections[$targetUserId]))`.
   Before FIX-10, that map entry was never cleared on disconnect, so this
   accidentally always ran for anyone who'd ever been online. After
   FIX-10 correctly started clearing it on genuine disconnect, a banned
   player who happened to be mid-reconnect-window (disconnected, not yet
   timed out) at ban time no longer had a userConnections entry вЂ” so the
   entire removal branch was skipped. They kept their room seat and
   active reconnect_timer, and reconnecting before it expired let them
   fully resume playing seconds after being banned.
2. Banning a currently-*online* target never closed their WebSocket
   connection вЂ” only sent them a `banned` packet. $connection->userId/
   isAdmin/sessionToken stayed bound; they could keep issuing any action
   not tied to the now-removed room indefinitely, until they happened to
   disconnect on their own.
3. The most severe: AuthHandler::handleReconnect() never checked
   banned_until at all, unlike AuthService::login() which does. A banned
   user could bypass the ban indefinitely simply by sending
   {"action":"reconnect","token":<their existing session_token>} instead
   of logging in fresh вЂ” reconnect was a complete, permanent end-run
   around moderation, independent of anything room-related.
- Verified empirically end-to-end (not simulated) before writing any fix,
  same discipline as FIX-10: reproduced all three independently with a
  live server and real WS clients.

Fix:
1. handleBanUser()'s removal logic un-nested from the userConnections
   check вЂ” now runs unconditionally based on findPlayerMembership(),
   identical in shape to handleKickUser()'s already-correct pattern. The
   "notify + close" part remains conditional on the target being
   currently online (that part is correctly conditional).
2. If the target is online, after sending `banned`, their connection is
   now explicitly closed ($targetConnection->close()). Order matters:
   room removal happens first, so onClose's own
   ReconnectService::handleDisconnect() correctly no-ops afterward (the
   player is already gone from $room['players'] by the time it runs) вЂ”
   no double-removal/double-refund risk (the FIX-3 class of bug).
3. AuthService::getUserById() (added in FIX-10) now also returns
   banned_until (new column in the user_auth_fields_by_id query).
   AuthHandler::handleReconnect() checks it immediately after fetching
   the user and, if currently banned, responds with the exact same
   {"type":"banned","until":...} packet login() already sends вЂ” reusing
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
- tests/Manual/test_admin_packet_routing.php (new): 15/15 PASSED вЂ”
  real WS client against a live server.php. Covers admin_get_logs/
  admin_ban_user/admin_unban_user/admin_kick_user/admin_close_room
  routing, the assertAdmin guard (both auth_required and not_your_turn
  paths), cannot_moderate_admin, and three FIX-11 scenarios: online-ban
  (banned packet + connection closed), mid-disconnect-ban (reconnect
  correctly blocked, room structurally cleaned up despite the target
  being offline at ban time), and unban-then-relogin.
- tests/Manual/test_admin_ban.php: 9/9 PASSED after adding close() to
  MockConnection (fixture update, not a business-logic change вЂ” Rule 22).
- tests/Manual/test_admin_integration.php: 20/20 PASSED after the same
  fixture update to SpyConnection.
- Full regression across every tests/Manual/*.php file (30 files,
  including the new one) вЂ” 0 failed.

Also fixed in this Epic (trivial, unrelated to FIX-11's substance): a
pre-existing PHP warning ("Undefined property: ...TcpConnection::$userId")
in onClose when a raw TCP connection closes before ever completing the
WebSocket handshake (so onWebSocketConnected's field initialization never
ran) вЂ” direct property access changed to null-coalescing, matching the
adjacent log line's existing style.

No ADR required for the routing wiring (no protocol change). FIX-11 also
requires no ADR: no protocol packet, error code, room/player structure
key, or timer changed вЂ” `banned` is the same existing packet login()
already sends, reused from a second call site where it had been missing.

Diff: patches/EPIC-10.6-server.patch, patches/FIX-11-AdminService.patch,
patches/FIX-11-AuthHandler.patch, patches/FIX-11-AuthService.patch,
patches/FIX-11-PreparedStatements.patch,
patches/FIX-11-test-admin-ban.patch, patches/FIX-11-test-admin-integration.patch

## FIX-10 вЂ” Permanent session lockout after any disconnect outside room membership
Status: Completed
Date: 2026-07-24

Found during: proactive audit before starting EPIC-10.6, specifically
looking for another FIX-9-class issue (a bug only reachable once real
end-to-end routing exists) before adding more admin-side removal paths
that would have inherited the same defect.

Files:
- src/Infrastructure/PreparedStatements.php (diff вЂ” new query
  `user_auth_fields_by_id`: id/username/is_admin by id; neither existing
  `user_by_id` (id, coins) nor `user_admin_by_id` (id, is_admin) return
  username, which AuthHandler::bindConnection() requires)
- src/Auth/AuthService.php (diff вЂ” new `getUserById(int $userId): ?array`,
  using the statement above; returns null on missing user, which the
  caller treats as an invalid session rather than throwing)
- src/Auth/AuthHandler.php (diff вЂ” `handleReconnect()` now calls
  `getUserById()` and `bindConnection()`, mirroring what register()/
  login() already do; previously only `$worker->userConnections[$userId]`
  was restored and `$connection->userId` was never set)
- server.php (diff вЂ” `onClose` now unsets
  `$worker->userConnections[$connection->userId]` when the closing
  connection had one)
- tests/Manual/test_session_lifecycle.php (new file вЂ” real WS client
  against a live server.php, 6 assertions, no MockConnection)

Problem:
- `$worker->userConnections[$userId]` (ADR-001 В§ Single Active Session)
  is written by register/login/reconnect but was **never unset by any
  code path whatsoever** вЂ” not in `onClose`, not in
  `removePlayerFromLobby()/removePlayerFromGame()/removePlayerFromApartment()`,
  not on reconnect-timer expiry, not in `admin_close_room`. Once set, an
  account's slot in that map is permanent for the life of the worker
  process.
- `AuthService::login()`'s single-session guard is a plain `isset()`
  check against that map вЂ” so once a user disconnects, EVERY subsequent
  `login` attempt with correct credentials fails with the generic
  `error.auth_invalid_credentials` (message text: "User already logged
  in", though the client has no reliable way to distinguish this from a
  wrong password since the error *code* is deliberately generic вЂ” see
  `AuthHandler::mapLoginError()`).
- The only theoretical way back in is the `reconnect` action, per
  ADR-001 В§5-6 ("reconnect is the only supported method for restoring
  access"). But `AuthHandler::handleReconnect()` only ever restored
  `$worker->userConnections[$userId]` вЂ” it never set
  `$connection->userId` itself (a second, related gap: this is the same
  class of omission flagged as a KNOWN GAP in EPIC-10.5, just with a much
  larger blast radius than originally scoped there). For a user with an
  active room, `ReconnectService::handleReconnect()` (wired in EPIC-10.5)
  closes that gap by binding `$connection->userId` when it finds a
  matching disconnected room player. **For a user who was never in a
  room вЂ” or whose room session already ended вЂ” nothing ever binds
  `$connection->userId`,** so the `error.auth_required` guard in
  `server.php` blocks every subsequent action, including `create_room`/
  `join_room`.
- Net effect: any account that disconnects while not currently seated in
  a room (idling in the lobby, between games, after `leave_room`, after
  a finished game's room was destroyed, or simply a network blip before
  ever joining a room) is **permanently locked out** вЂ” neither `login`
  nor `reconnect` can recover it. Only a full server restart clears
  `$worker->userConnections`.
- Why this was undetected until now: unreachable through any real code
  path before EPIC-10.5, since `onClose` was a stub that never called
  `ReconnectService::handleDisconnect()` at all prior to that Epic вЂ” no
  disconnect ever triggered any downstream state change. The one test
  that exercises the single-session concept,
  `tests/Manual/test_single_session.php` (Phase 1), manually performs
  `unset($worker->userConnections[$userId])` inside the test itself
  before asserting a second login succeeds вЂ” simulating the cleanup step
  that production code never actually implements, rather than exercising
  a real code path. Textbook instance of ANCHOR_RULES.md Part 22 ("Tests
  must not compensate for missing contracts") вЂ” except the missing
  contract was on the implementation side, not the test's own logic, and
  had gone unnoticed because nothing forced the two to be compared until
  real routing existed.
- Verified empirically end-to-end (not simulated) before writing any fix:
  registered a user, closed the connection via a raw TCP close without
  ever joining a room, then confirmed both `login` (rejected,
  "already logged in") and `reconnect` (silently "succeeded" at the
  protocol level but left the connection unauthenticated вЂ”
  `room_list`/`create_room` afterward returned `error.auth_required`)
  failed to restore access.

Fix:
- `AuthHandler::handleReconnect()`: after validating the token format and
  confirming `$worker->sessionTokens[$token]` exists, now looks up the
  user via the new `AuthService::getUserById()` and, on success, calls
  the same private `bindConnection()` helper register()/login() already
  use вЂ” setting `$connection->userId`/`username`/`isAdmin`/`sessionToken`.
  If the user row is somehow gone (defensive вЂ” a session token pointing
  at a deleted account), responds `error.auth_invalid_token` rather than
  proceeding with a half-bound connection.
- `server.php`'s `onClose`: unsets
  `$worker->userConnections[$connection->userId]` whenever the closing
  connection had a bound `userId`, after `ReconnectService::handleDisconnect()`
  runs. This does not interfere with the intended reconnect path (ADR-001
  В§5-6): `reconnect` never depended on `userConnections` still being
  occupied вЂ” it works off `$worker->sessionTokens` plus a session_token
  match against room player state, both independent of this map. The
  only behavioral change is that a user who disconnects can now also
  fall back to a fresh `login` instead of being force-funneled through
  `reconnect` вЂ” which was previously not just "not preferred" but
  completely broken for any player outside a room.
- Regression guard preserved on purpose: `onClose` only fires on an
  actual connection close, so ADR-001's core guarantee вЂ” rejecting a
  *concurrent* second login while the first connection is still open вЂ”
  is untouched. Verified explicitly (TEST 3 below).

Verified non-false-positive (each half of the fix independently):
- Reverted only the `onClose` change в†’ `tests/Manual/test_session_lifecycle.php`
  TEST 1 failed exactly as predicted (login still permanently blocked);
  TEST 2/3 unaffected. Restored в†’ 6/6 again.
- Reverted only the `AuthHandler::handleReconnect()` change в†’ TEST 2
  failed exactly as predicted (create_room after reconnect-only still
  `error.auth_required`); TEST 1/3 unaffected. Restored в†’ 6/6 again.

Result:
- tests/Manual/test_session_lifecycle.php (new): 6/6 PASSED вЂ” real WS
  client against a live server.php subprocess, no MockConnection. Covers:
  disconnect-then-login (no room), disconnect-then-reconnect-only (no
  room, no login fallback), and a regression guard confirming concurrent
  double-login is still rejected while the original connection stays
  open.
- tests/Manual/test_single_session.php: unchanged, still 3/3 scenarios
  PASSED (Phase-1-era unit test against AuthService in isolation; left
  as-is since it tests a real contract, just not the one this FIX closes
  вЂ” no false claims to correct here, unlike the EPIC-10.5 test_auth_packet_routing.php
  fix).
- Full regression across every tests/Manual/*.php file (29 files,
  including the new one) вЂ” 0 failed.

No ADR required вЂ” no protocol packet, error code, room/player structure
key, or timer changed. `error.auth_invalid_token`/`error.auth_invalid_credentials`
are pre-existing codes, used exactly as already documented.

Diff: patches/FIX-10-server.patch, patches/FIX-10-AuthHandler.patch,
patches/FIX-10-AuthService.patch, patches/FIX-10-PreparedStatements.patch

## EPIC-10.5 вЂ” Game packet routing (+ FIX-9, found during wiring)
Status: Completed
Date: 2026-07-23

Files:
- src/Game/GameHandler.php (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” thin wrapper РЅР°Рґ GameService,
  С‚РѕС‚ Р¶Рµ РїР°С‚С‚РµСЂРЅ С‡С‚Рѕ LobbyHandler/AuthHandler)
- server.php (diff вЂ” LottoEngine/VictoryService/ApartmentService/
  GameFinishService/GameService/GameHandler dependency wiring in
  onWorkerStart, РёРґРµРЅС‚РёС‡РЅС‹Р№ РїРѕСЂСЏРґРѕРє РєРѕРЅСЃС‚СЂСѓРєС‚РѕСЂР° СѓР¶Рµ РїСЂРёРЅСЏС‚РѕРјСѓ РІ
  tests/Manual/test_game_start.php; start_game/draw_barrel/
  apartment_choice wired in onMessage dispatch; ReconnectService С‚РµРїРµСЂСЊ
  С‚РѕР¶Рµ СЃРѕР±СЂР°РЅ вЂ” РѕР±Р° РµРіРѕ Р·Р°РІРёСЃРёРјС‹С… СЃРµСЂРІРёСЃР°, LobbyService (EPIC-10.4) Рё
  GameService (СЌС‚РѕС‚ Epic), РЅР°РєРѕРЅРµС† РіРѕС‚РѕРІС‹ РѕРґРЅРѕРІСЂРµРјРµРЅРЅРѕ; onClose РґРµР»РµРіРёСЂСѓРµС‚
  ReconnectService::handleDisconnect(); action 'reconnect' РґРѕРїРѕР»РЅРёС‚РµР»СЊРЅРѕ
  РІС‹Р·С‹РІР°РµС‚ ReconnectService::handleReconnect() РїРѕСЃР»Рµ AuthHandler РґР»СЏ
  РІРѕСЃСЃС‚Р°РЅРѕРІР»РµРЅРёСЏ РёРіСЂРѕРІРѕРіРѕ СЃРѕСЃС‚РѕСЏРЅРёСЏ/reconnect_state)
- src/Game/ReconnectService.php (diff вЂ” FIX-9, СЃРј. РЅРёР¶Рµ)
- tests/Manual/test_reconnect.php (diff вЂ” GROUP 3 assertions РѕР±РЅРѕРІР»РµРЅС‹ РїРѕРґ
  FIX-9: Р·Р°РїРёСЃСЊ РїРµСЂРµРµР·Р¶Р°РµС‚ РЅР° РЅРѕРІС‹Р№ conn_id, Р° РЅРµ РѕСЃС‚Р°С‘С‚СЃСЏ РЅР° СЃС‚Р°СЂРѕРј; +3
  РЅРѕРІС‹С… assertion РЅР° host_conn_id/active_drawer_conn_id/drawer_order)
- tests/Manual/test_game_packet_routing.php (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” 21 assertions,
  real WS client against live server.php, `e105_` username prefix)

GameService/VictoryService/ApartmentService/GameFinishService already
existed (Phase 4-7) and required no new business logic for the packet-
routing part itself вЂ” matching every other EPIC-10.x so far, this is pure
dependency wiring + routing. The one router-level addition is in
GameHandler::handleApartmentChoice(): validates that `choice` is a
non-empty string before delegating (error.invalid_json otherwise) вЂ”
GameService/ApartmentService already validate the actual value
('agree'/'refuse') internally.

Reconnect wiring was deliberately bundled into this Epic rather than left
pending further, because ReconnectService's constructor is the literal
reason onClose and 'reconnect' were stubbed out since EPIC-10.0 вЂ” both of
its dependencies (LobbyService, GameService) are only both available as of
this Epic. This is not a new/separate feature so much as completing what
EPIC-10.0's own code comments already earmarked for "EPIC-10.4/10.5".

Found and fixed during wiring (FIX-9, see PATCHES-style note below вЂ”
kept inline here since it's this Epic's direct blocker, not a standalone
older-code audit finding):
- ReconnectService::handleReconnect() restored player state and sent
  reconnect_state, but left the `$room['players']` array entry keyed
  under the OLD (disconnected) connection id. A new WS connection created
  by the client on reconnect gets a brand-new Workerman connection->id вЂ”
  every downstream handler (draw_barrel, leave_room, apartment_choice, ...)
  looks the player up by the CURRENT connection's id, so none of them
  could find the reconnected player. Reconnect looked successful
  (reconnect_state packet received, status flipped to 'active') but was
  functionally dead for anything after it.
- Root cause of why this was never caught: tests/Manual/test_reconnect.php
  (EPIC-8.6) only unit-tests handleReconnect() in isolation with
  MockConnection and asserts state at the OLD key вЂ” it never simulates a
  subsequent action arriving from the NEW connection through real routing,
  because until this Epic there was no real routing to go through.
- Fix: handleReconnect() now re-keys the players array entry from the old
  conn_id to the new one, and updates every other room-level field that
  can point at a conn_id: `host_conn_id`, `active_drawer_conn_id`, and
  every matching entry in `drawer_order`. Timer, connection object, and
  session_token handling unchanged.
- Verified non-false-positive: reverted the fix locally, re-ran
  tests/Manual/test_game_packet_routing.php TEST 8 вЂ” draw_barrel after
  reconnect failed with error.room_not_found as predicted (new conn_id not
  found in `$room['players']`); restored the fix вЂ” 21/21 PASSED again.

No ADR required вЂ” no protocol packet, error code, or ANCHOR document
changed. Room/Player structure keys are unchanged (Rule 7 No Hidden
Features) вЂ” FIX-9 only changes which array key an existing structure is
stored under, at the moment of reconnect.

Also fixed in this Epic (stale pre-existing test assertion, not a FIX-N вЂ”
Rule 22 Test Philosophy: fix the test, not the implementation, since the
implementation was already correct): tests/Manual/test_auth_packet_routing.php
TEST 2 still asserted `error.invalid_json` for create_room after register,
a leftover from before EPIC-10.4 wired lobby routing вЂ” despite this
project's own IMPLEMENTATION_STATUS.md EPIC-10.4 entry already claiming
this assertion was updated. It had not been, in the actual committed file.
Corrected to assert `room_joined`.

Housekeeping (found during this Epic's audit, unrelated to game routing
itself): the repository had two case-variant test directories,
`tests/Manual/` and `tests/manual/`, byte-identical except that
`tests/manual/test_lobby_packet_routing.php` (EPIC-10.4) existed only in
the lowercase copy вЂ” almost certainly a case-insensitive-filesystem
artifact from a local dev machine, invisible on that machine but tracked
as two separate directories in git. Consequence: `run_ALL_tests.sh` (globs
`tests/Manual/test_*.php` only) was silently never running
test_lobby_packet_routing.php at all. Fixed: file moved into
`tests/Manual/` (confirmed identical before the move, `php -l` clean,
re-run 23/23 PASSED post-move), the stray `tests/manual/` directory
removed entirely.

Result:
- tests/Manual/test_game_packet_routing.php (new): 21/21 PASSED вЂ” full
  flow verified end-to-end through a real WS client against a live
  server.php subprocess: non-host start_game guard, game_started
  broadcast (bank/drawer_order), turn-order draw_barrel guard,
  barrels_drawn + your_turn rotation, apartment_choice with no apartment
  active, apartment_choice missing `choice` field, unauth draw_barrel,
  and вЂ” critically вЂ” a real TCP disconnect mid-game followed by
  reconnect on a brand-new connection, then a successful draw_barrel from
  that new connection (this last step is the FIX-9 regression check).
- tests/Manual/test_reconnect.php: 20/20 PASSED (was 15 вЂ” +5 new FIX-9
  assertions in GROUP 3).
- tests/Manual/test_auth_packet_routing.php: 18/18 PASSED (TEST 2 fixed).
- tests/Manual/test_lobby_packet_routing.php: 23/23 PASSED (moved,
  unchanged otherwise).
- Full regression across every tests/Manual/*.php file вЂ” 0 failed.

вњ… RESOLVED (FIX-10, 2026-07-24): if a client sends `{"action":
"reconnect", "token": ...}` with a token AuthHandler considers valid, but
ReconnectService::handleReconnect() finds no matching disconnected player
in any room (i.e. the user was never in a room-level session, or it was
already cleaned up), `$connection->userId` is never set вЂ” AuthHandler::
handleReconnect() itself never sets it, only ReconnectService does, only
on a match. Symmetric in spirit to FIX-8 (EPIC-10.3) but a distinct fix,
deliberately left for a follow-up rather than folded into this Epic.
Turned out to be far more severe than "narrow" once actually audited вЂ”
see FIX-10: AuthHandler::handleReconnect() now unconditionally binds the
connection via bindConnection() once the token/user is validated,
regardless of room membership.

Diff: patches/EPIC-10.5-game-routing.patch

## EPIC-10.4 вЂ” Lobby packet routing
Status: Completed
Date: 2026-07-23

Files:
- src/Lobby/LobbyHandler.php (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” thin wrapper РЅР°Рґ LobbyService)
- server.php (diff вЂ” RoomManager/LobbyService/LobbyHandler dependency wiring
  in onWorkerStart; room_list/create_room/join_room/leave_room wired in
  onMessage dispatch; В«Already in a roomВ» guard for create_room/join_room)
- tests/Manual/test_lobby_packet_routing.php (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” 22 assertions,
  real WS client against live server.php)
- tests/Manual/test_auth_packet_routing.php (diff вЂ” TEST 2 updated: РїРѕСЃР»Рµ
  register create_room С‚РµРїРµСЂСЊ РІРѕР·РІСЂР°С‰Р°РµС‚ room_joined, РЅРµ error.invalid_json)

LobbyService already existed (EPIC-2.x) and required no new business
logic вЂ” EPIC-10.4 itself is pure dependency wiring + routing + one router-
level guard, matching every other EPIC-10.x so far.

В«Already in a roomВ» guard: LobbyService::handleCreateRoom() РґРѕРєСѓРјРµРЅС‚РёСЂСѓРµС‚,
С‡С‚Рѕ РїРѕР»СЊР·РѕРІР°С‚РµР»СЊ РЅРµ РґРѕР»Р¶РµРЅ СѓР¶Рµ РЅР°С…РѕРґРёС‚СЊСЃСЏ РІ РґСЂСѓРіРѕР№ РєРѕРјРЅР°С‚Рµ вЂ” РїСЂРѕРІРµСЂРєР°
РґРµР»РµРіРёСЂРѕРІР°РЅР° router'Сѓ (server.php), РѕРґРёРЅ СЂР°Р· РґР»СЏ create_room Рё join_room,
С‡РµСЂРµР· RoomManager::findRoomIdByConnId(). РљРѕРґ РѕС€РёР±РєРё: error.invalid_json
(РѕС‚РґРµР»СЊРЅРѕРіРѕ РєРѕРґР° РІ ANCHOR_PROTOCOL.md РЅРµС‚).

No ADR required вЂ” no protocol packet, error code, or ANCHOR document changed.

Result:
- tests/Manual/test_lobby_packet_routing.php (new): 22/22 PASSED вЂ”
  create_room/room_list/join_room/leave_room verified end-to-end through
  a real WS client against a live server.php subprocess (real game.db,
  `e104_` username prefix, cleaned up before/after). Includes router-level
  В«Already in a roomВ» guard checks (TEST 4, TEST 5).
- tests/Manual/test_auth_packet_routing.php: TEST 2 updated for EPIC-10.4
  (create_room after register в†’ room_joined).
- tests/Manual/test_lobby_integration.php: 91/91 PASSED (unchanged).
- Full regression across all tests/Manual/*.php files вЂ” 0 failed.

Diff: patches/EPIC-10.4-lobby-routing.patch

## EPIC-10.3 вЂ” Auth packet routing (+ FIX-8, found during wiring)
Status: Completed
Date: 2026-07-22

Files:
- server.php (diff вЂ” AuthHandler dependency wiring in onWorkerStart;
  register/login/reconnect wired to AuthHandler in onMessage dispatch)
- src/Auth/AuthHandler.php (diff вЂ” FIX-8: new bindConnection() private
  helper, called from handleRegister()/handleLogin())
- tests/Manual/test_auth_integration.php (diff вЂ” 7 new FIX-8 assertions
  via MockConnection)
- tests/Manual/test_auth_packet_routing.php (РЅРѕРІС‹Р№ С„Р°Р№Р» вЂ” 18 assertions,
  real WS client against live server.php)

AuthHandler already existed (EPIC-1.3) and required no new business
logic вЂ” EPIC-10.3 itself is pure dependency wiring + routing, matching
every other EPIC-10.x so far.

FIX-8 found while wiring (not a pre-existing regression вЂ” the bug was
latent until this Epic connected AuthHandler to the newly-added
auth_required guard, ADR-006, in the same code path): `AuthService::
login()` only ever set `$worker->userConnections[$userId]` вЂ” it never
set `$connection->userId` itself. Confirmed by grep: the ONLY place in
the entire codebase that set `$connection->userId` was
`ReconnectService::attemptReconnect()`, for its own, unrelated scenario.
Without a fix, a client could register/login successfully, receive a
valid `auth_result`, and then have EVERY subsequent action rejected with
`error.auth_required` forever вЂ” the auth_required guard checks exactly
`$connection->userId === null`, which never became false.

Fix: new `AuthHandler::bindConnection(object $connection, array $user,
string $token): void` private helper, mirroring the exact field set
`ReconnectService` already uses for its own scenario (`$connection->
userId`, `->username`, `->sessionToken`) plus `->isAdmin` (available in
AuthHandler's login result, unlike in ReconnectService's context). Called
from both `handleRegister()` (after its internal auto-login) and
`handleLogin()`, right before `sendAuthResult()`.

No ADR required вЂ” this is a code-correctness fix within the existing,
already-documented `ANCHOR_CORE.md` В§ Connection Runtime Fields registry
(all four fields were already declared there); no protocol packet, error
code, or ANCHOR document changed.

Result:
- tests/Manual/test_auth_integration.php: 55/55 PASSED (was 48; +7 вЂ”
  FIX-8 assertions verifying `$connection->userId/username/isAdmin/
  sessionToken` are correctly bound after both handleRegister() and
  handleLogin() via MockConnection).
- tests/Manual/test_auth_packet_routing.php (new): 18/18 PASSED вЂ”
  register/login/reconnect verified end-to-end through a real WS client
  against a live server.php subprocess (real game.db, `e103_` username
  prefix, cleaned up before/after). Critically includes two FIX-8
  end-to-end checks (TEST 2, TEST 6): after a real register/login over
  the real protocol, a subsequent non-exempt action no longer receives
  `error.auth_required` вЂ” confirming the fix works through the actual
  router, not only in the MockConnection unit test.
- Full regression across all tests/Manual/*.php files (28 files,
  including the new one) вЂ” 0 failed ([FAIL] marker searched explicitly).

Diff: patches/EPIC-10.3-auth-routing.patch

## EPIC-10.2 continuation вЂ” Generic auth_required guard
Status: Completed
Date: 2026-07-22

Files:
- server.php (diff вЂ” auth_required guard in onMessage, before dispatch)
- docs/ANCHOR_PROTOCOL.md (diff вЂ” error.auth_required semantics documented)
- docs/ADR/006.md (РЅРѕРІС‹Р№ С„Р°Р№Р»)
- tests/Manual/test_server_bootstrap.php (diff вЂ” TEST 4 tightened to
  assert the specific code; new TEST 8 for the exempt-actions set)

Closes the second, previously-deferred half of EPIC-10.2 (first half вЂ”
connection-level MAX_TOTAL_PLAYERS gate вЂ” completed separately, ADR-005).
EPIC-10.2 is now fully complete.

Implements prompt.md Р¤Р°Р·Р° 1: "РїСЂРѕРІРµСЂРєР° userId РґР»СЏ РІСЃРµС… РєРµР№СЃРѕРІ РєСЂРѕРјРµ
register, login, reconnect" вЂ” checked once, generically, by the router
in onMessage, before the (still empty) action dispatcher. Exempt set is
exactly {register, login, reconnect}; `ping` isn't listed because it
already short-circuits earlier in onMessage and never reaches this
check.

Side effect verified explicitly (not a defect, documented in ADR-006):
the dispatcher's `default => error.invalid_json` fallback is now
unreachable for an unauthenticated connection sending any non-exempt
action вЂ” the guard intercepts first with error.auth_required. Remains
reachable only for the exempt actions themselves (not yet wired to real
handlers until EPIC-10.3).

Result:
- tests/Manual/test_server_bootstrap.php: 18/18 PASSED (was 14; +4 вЂ” TEST
  4 tightened to assert code=error.auth_required specifically instead of
  just type=error; new TEST 8 confirms register/login/reconnect are NOT
  blocked by the guard, falling through to the empty dispatcher's
  not-yet-wired response instead).
- Full regression across all tests/Manual/*.php files (25 files) вЂ” 0
  failed ([FAIL] marker searched explicitly, not just "failed" text
  appearing in unrelated log messages).

Diff: patches/EPIC-10.2-auth-guard.patch

## EPIC-10.2 вЂ” Protocol error handling (partial: connection-level capacity gate)
Status: Partially completed (by user decision вЂ” scope explicitly narrowed)
Date: 2026-07-22

Files:
- src/Core/Helpers.php (diff вЂ” new closeWithCode() helper)
- server.php (diff вЂ” global connection-level MAX_TOTAL_PLAYERS gate in
  onWebSocketConnected, before hello)
- docs/ANCHOR_PROTOCOL.md (diff вЂ” new В§ WebSocket Close Codes, code 4001)
- docs/ADR/005.md (РЅРѕРІС‹Р№ С„Р°Р№Р»)
- tests/Manual/test_server_bootstrap.php (diff вЂ” TEST 7: 150 СЂРµР°Р»СЊРЅС‹С…
  TCP+WS СЃРѕРµРґРёРЅРµРЅРёР№ + 151-Рµ РѕС‚РєР»РѕРЅС‘РЅРЅРѕРµ, РїСЂРѕРІРµСЂРєР° close code 4001)

Scope decision: user chose to implement ONLY the connection-level
`error.server_full` + WS close 4001 gate (prompt.md Р¤Р°Р·Р° 1, previously
undocumented in any ANCHOR file) in this round. The generic
`auth_required` router guard (also prompt.md Р¤Р°Р·Р° 1, for actions outside
{register, login, reconnect, ping}) was explicitly deferred вЂ” not
implemented, tracked as open for a future round.

Problem: `docs/prompt.md` line 41 specified "РїСЂРё РїСЂРµРІС‹С€РµРЅРёРё 150 вЂ”
Р·Р°РєСЂС‹С‚СЊ СЃРѕРµРґРёРЅРµРЅРёРµ СЃ РєРѕРґРѕРј 4001 Рё error.server_full", never formalized
in ANCHOR_PROTOCOL.md and never implemented. Distinct from the
room-join-time capacity check in LobbyService (FIX-7/ADR-004) вЂ” this one
runs at the connection layer, before authentication, against ALL live
sockets (`count($worker->connections)`), not just players seated in
rooms.

Technical finding: the installed Workerman version has no built-in API
to close a WebSocket connection with an explicit close-frame status
code вЂ” `closeWithCode()` builds the RFC 6455 В§5.5.1 close frame by hand
(opcode 0x8, 2-byte big-endian status code + reason) and sends it via
`$connection->close($frame, true)`.

Fix:
- `closeWithCode()` helper added to Core/Helpers.php (general-purpose,
  reusable for any future application-specific close code).
- Gate added at the top of `onWebSocketConnected`: if
  `count($worker->connections) > Constants::MAX_TOTAL_PLAYERS`, sends
  `error.server_full` (JSON, normal protocol-encoded) then closes with
  WS code 4001 вЂ” before any connection-field init, before `hello`.
- Comparison uses `>` (not `>=`, unlike LobbyService's checks) because
  Workerman registers the connection into `$worker->connections` at
  TCP-accept time, before this callback runs вЂ” so the count already
  includes the connection being evaluated. Effective capacity is
  identical either way: exactly MAX_TOTAL_PLAYERS concurrent connections
  allowed, the (N+1)-th rejected. Documented explicitly in ADR-005 to
  avoid the kind of silent inconsistency FIX-7 had to fix.
- New WS close-code registry section added to ANCHOR_PROTOCOL.md so
  future application-specific codes have a documented home.

Result:
- tests/Manual/test_server_bootstrap.php: 14/14 PASSED (was 8; +6 new
  checks in TEST 7 вЂ” opened exactly 150 real TCP+WS connections against
  a live server.php subprocess, verified the 151st receives
  error.server_full as a text frame followed by a close frame carrying
  status code 4001, decoded from the raw close-frame payload).
- Full regression across all tests/Manual/*.php files (25 files) вЂ” 0
  failed.

Diff: patches/EPIC-10.2-partial.patch

### FIX-7 вЂ” `error.server_full` reused for room-full condition + wrong check order
Status: Completed
Date: 2026-07-22

Files:
- src/Lobby/LobbyService.php (diff вЂ” reorder checks in handleJoinRoom(),
  new error.room_full code)
- docs/ANCHOR_PROTOCOL.md (diff вЂ” error.room_full added to registry,
  note distinguishing it from error.server_full and documenting
  join-order precedence)
- docs/ADR/004.md (РЅРѕРІС‹Р№ С„Р°Р№Р»)
- tests/Manual/test_lobby_integration.php (diff вЂ” РѕР±РЅРѕРІР»РµРЅР° Р°СЃСЃРµСЂС†РёСЏ РїРѕРґ
  РЅРѕРІС‹Р№ РєРѕРґ, РґРѕР±Р°РІР»РµРЅ regression-С‚РµСЃС‚ РЅР° РїРѕСЂСЏРґРѕРє РїСЂРѕРІРµСЂРѕРє)

Found during: user-reported review (not an audit round) вЂ” user flagged
that a full room and a full server must not share an error code, and
that server capacity must be checked before room capacity.

Problem:
- LobbyService::handleJoinRoom() reused `error.server_full` for two
  distinct conditions: the genuine global MAX_TOTAL_PLAYERS limit, and a
  single room reaching its own max_players. ANCHOR_PROTOCOL.md had no
  dedicated code for the room-full case.
- Check order was room-capacity-first, server-capacity-second вЂ” so if
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
- Formalized as ADR-004 (protocol addition, no rename/removal вЂ” permitted
  under ANCHOR_PROTOCOL.md's Compatibility Rule without a version bump).

Result:
- tests/Manual/test_lobby_integration.php: 91/91 PASSED (was 90; +1 new
  regression test verifying error.server_full wins when both room and
  server are full simultaneously вЂ” verified by manually seeding both
  conditions via direct room-state manipulation and RoomManager::
  getTotalPlayerCount()).
- Full regression across all tests/Manual/*.php files вЂ” 0 failed.

Diff: patches/FIX-7.patch

### FIX-6 вЂ” Reconnect timer leak on kick/ban removal (Timer Integrity)
Status: Completed
Date: 2026-07-03

Files:
- src/Lobby/LobbyService.php
- src/Game/ApartmentService.php
- tests/Manual/test_timer_integrity.php (РЅРѕРІС‹Р№ С„Р°Р№Р»)

Found during: post-Phase-9 audit for bugs similar in class to FIX-3
(Р·Р°РїСЂРѕС€РµРЅ РїРѕР»СЊР·РѕРІР°С‚РµР»РµРј РїРµСЂРµРґ СЃС‚Р°СЂС‚РѕРј Phase 10).

Problem:
- ANCHOR_CORE.md Part 5 В§ Timer Integrity Rules: "No reconnect timer
  survives player removal" / "A destroyed owner keeps no timers" вЂ”
  Р±РµР·СѓСЃР»РѕРІРЅРѕРµ РїСЂР°РІРёР»Рѕ.
- ReconnectService::removePlayerFromGame() РєРѕСЂСЂРµРєС‚РЅРѕ РѕС‚РјРµРЅСЏРµС‚
  player['reconnect_timer'] РџР•Р Р•Р” СѓРґР°Р»РµРЅРёРµРј РёРіСЂРѕРєР°.
- LobbyService::removePlayerFromLobby() Рё ApartmentService::
  removePlayerFromApartment() вЂ” РќР• РѕС‚РјРµРЅСЏР»Рё, Р°СЃРёРјРјРµС‚СЂРёСЏ РјРµР¶РґСѓ С‚СЂРµРјСЏ
  "СЃС‘СЃС‚СЂРёРЅСЃРєРёРјРё" РјРµС‚РѕРґР°РјРё СѓРґР°Р»РµРЅРёСЏ РёРіСЂРѕРєР°.
- Р”РѕСЃС‚РёР¶РёРјРѕСЃС‚СЊ (СЂРµР°Р»СЊРЅС‹Р№ СЃС†РµРЅР°СЂРёР№, РЅРµ РіРёРїРѕС‚РµС‚РёС‡РµСЃРєРёР№): disconnected-РёРіСЂРѕРє
  РІ waiting-РєРѕРјРЅР°С‚Рµ РёРјРµРµС‚ Р°РєС‚РёРІРЅС‹Р№ 15s reconnect_timer (ANCHOR_CORE В§
  Reconnect Timer). Р•СЃР»Рё Р°РґРјРёРЅРёСЃС‚СЂР°С‚РѕСЂ РєРёРєР°РµС‚/Р±Р°РЅРёС‚ РµРіРѕ РґРѕ РёСЃС‚РµС‡РµРЅРёСЏ
  С‚Р°Р№РјРµСЂР°, removePlayerFromLobby() СѓРґР°Р»СЏРµС‚ РёРіСЂРѕРєР°, РЅРѕ С‚Р°Р№РјРµСЂ РѕСЃС‚Р°С‘С‚СЃСЏ
  Р·Р°СЂРµРіРёСЃС‚СЂРёСЂРѕРІР°РЅРЅС‹Рј РІ Workerman. RoomManager::generateRoomId()
  РїРµСЂРµРёСЃРїРѕР»СЊР·СѓРµС‚ РџР•Р Р’Р«Р™ СЃРІРѕР±РѕРґРЅС‹Р№ room_id СЃСЂР°Р·Сѓ РїРѕСЃР»Рµ СѓРЅРёС‡С‚РѕР¶РµРЅРёСЏ РєРѕРјРЅР°С‚С‹
  (MAX_ROOMS=30) вЂ” С‚Рѕ РµСЃС‚СЊ СЌС‚Рѕ РЅРµ РїСЂРѕСЃС‚Рѕ СѓС‚РµС‡РєР° РїР°РјСЏС‚Рё РЅР° 15 СЃРµРєСѓРЅРґ, Р°
  РЅР°СЂСѓС€РµРЅРёРµ РёРЅРІР°СЂРёР°РЅС‚Р° РЅР° Р°РєС‚РёРІРЅРѕ РїРµСЂРµРёСЃРїРѕР»СЊР·СѓРµРјРѕРј СЂРµСЃСѓСЂСЃРµ (Rule 28 VPS
  Awareness: 1 CPU/500MB RAM).
- removePlayerFromApartment(): С‚РѕС‚ Р¶Рµ РїСЂРѕР±РµР», РЅРѕ РїРѕ state machine
  (ANCHOR_CORE В§ Reconnect Rules: reconnect Р·Р°РїСЂРµС‰С‘РЅ РІ apartment) РІ
  РЅРѕСЂРјРµ РЅРµРґРѕСЃС‚РёР¶РёРј вЂ” РёСЃРїСЂР°РІР»РµРЅРѕ Р·Р°С‰РёС‚РЅРѕ, С‚.Рє. РїСЂР°РІРёР»Рѕ Р±РµР·СѓСЃР»РѕРІРЅРѕРµ.

Fix:
- Timer::del($player['reconnect_timer']) РґРѕР±Р°РІР»РµРЅ РІ РѕР±Р° РјРµС‚РѕРґР° Р”Рћ
  СѓРґР°Р»РµРЅРёСЏ РёРіСЂРѕРєР° вЂ” РёРґРµРЅС‚РёС‡РЅС‹Р№ СѓР¶Рµ РєРѕСЂСЂРµРєС‚РЅРѕРјСѓ РїР°С‚С‚РµСЂРЅСѓ РІ
  ReconnectService::removePlayerFromGame().

Result:
- tests/Manual/test_timer_integrity.php: 5/5 PASSED.
- Regression РїСЂРѕРІРµСЂРµРЅ РЅР° Р»РѕР¶РЅРѕРїРѕР»РѕР¶РёС‚РµР»СЊРЅРѕСЃС‚СЊ: РІСЂРµРјРµРЅРЅРѕ РѕС‚РєР°С‚С‹РІР°Р»РёСЃСЊ РѕР±Рµ
  РїСЂР°РІРєРё в†’ 3/5 С‡РµСЃС‚РЅС‹С… FAIL; РїРѕСЃР»Рµ РІРѕСЃСЃС‚Р°РЅРѕРІР»РµРЅРёСЏ вЂ” СЃРЅРѕРІР° 5/5.
- РџРѕР»РЅС‹Р№ СЂРµРіСЂРµСЃСЃ РїРѕ РІСЃРµРј 23 С„Р°Р№Р»Р°Рј tests/Manual/*.php вЂ” 0 failed.

Diff: patches/FIX-6.patch

### FIX-4 вЂ” Stale test fixtures after ADR-002 (GameFinishService)
Status: Completed
Date: 2026-07-03

Files:
- src/Infrastructure/Database.php
- tests/Manual/test_game_start.php
- tests/Manual/test_victory.php

Problem:
- ADR-002 (РІС‹РЅРѕСЃ GameFinishService, final class СЃРѕ СЃС‚СЂРѕРіРѕР№ С‚РёРїРёР·Р°С†РёРµР№
  Database/PreparedStatements/Logger) РЅРµ Р±С‹Р» РїСЂРѕР±СЂР°СЃС‘РЅ РІ С‚РµСЃС‚РѕРІС‹Рµ С„РёРєСЃС‚СѓСЂС‹
  test_game_start.php Рё test_victory.php вЂ” РѕР±Рµ РїСЂРѕРґРѕР»Р¶Р°Р»Рё РёСЃРїРѕР»СЊР·РѕРІР°С‚СЊ
  Р°РЅРѕРЅРёРјРЅС‹Рµ РєР»Р°СЃСЃС‹ РІРјРµСЃС‚Рѕ GameFinishService, С‡С‚Рѕ РЅРµСЃРѕРІРјРµСЃС‚РёРјРѕ РїРѕ С‚РёРїСѓ СЃ
  GameService::__construct(). РћР±Р° С„Р°Р№Р»Р° РїР°РґР°Р»Рё СЃ Fatal TypeError.
- РљРѕСЂРЅРµРІР°СЏ РїСЂРёС‡РёРЅР° РЅРµРІРѕР·РјРѕР¶РЅРѕСЃС‚Рё С‡РµСЃС‚РЅРѕРіРѕ (Р±РµР· reflection вЂ” Р·Р°РїСЂРµС‰С‘РЅРЅРѕРіРѕ
  ANCHOR_RULES.md Part 22) РёСЃРїСЂР°РІР»РµРЅРёСЏ: Database Р¶С‘СЃС‚РєРѕ С…Р°СЂРґРєРѕРґРёР»Р° РїСѓС‚СЊ Рє
  game.db РІ РєРѕРЅСЃС‚СЂСѓРєС‚РѕСЂРµ Р±РµР· С‚РѕС‡РєРё РІРЅРµРґСЂРµРЅРёСЏ Р·Р°РІРёСЃРёРјРѕСЃС‚РµР№.

Fix:
- Database::__construct() СЂР°СЃС€РёСЂРµРЅ РѕРїС†РёРѕРЅР°Р»СЊРЅС‹Рј РїР°СЂР°РјРµС‚СЂРѕРј `?PDO $pdo = null`
  (РѕР±СЂР°С‚РЅРѕ СЃРѕРІРјРµСЃС‚РёРјРѕ вЂ” РЅР° РјРѕРјРµРЅС‚ С„РёРєСЃР° `new Database()` РЅРёРіРґРµ РІ РїСЂРѕРµРєС‚Рµ РЅРµ
  РІС‹Р·С‹РІР°РµС‚СЃСЏ РЅР°РїСЂСЏРјСѓСЋ, server.php/init_db.php РµС‰С‘ РЅРµ СЂРµР°Р»РёР·РѕРІР°РЅС‹; РїРѕРІРµРґРµРЅРёРµ
  Р±РµР· Р°СЂРіСѓРјРµРЅС‚Р° РёРґРµРЅС‚РёС‡РЅРѕ РїСЂРµР¶РЅРµРјСѓ).
- test_game_start.php: finishGame() РЅРµ РІС‹Р·С‹РІР°РµС‚СЃСЏ РЅРё РІ РѕРґРЅРѕРј СЃС†РµРЅР°СЂРёРё
  EPIC-4.5 в†’ Р°РЅРѕРЅРёРјРЅС‹Р№ РєР»Р°СЃСЃ Р·Р°РјРµРЅС‘РЅ РЅР° СѓР¶Рµ РїСЂРёРЅСЏС‚С‹Р№ РІ РїСЂРѕРµРєС‚Рµ РїР°С‚С‚РµСЂРЅ
  ReflectionClass::newInstanceWithoutConstructor() (СЃРј. test_apartment.php,
  test_turn_system.php).
- test_victory.php: GROUP 4/5/6 СЂРµР°Р»СЊРЅРѕ РІС‹Р·С‹РІР°СЋС‚ finishGame() в†’ makeSvc()
  С‚РµРїРµСЂСЊ СЃС‚СЂРѕРёС‚ РЅР°СЃС‚РѕСЏС‰РёР№ GameFinishService(Database, PreparedStatements,
  Logger) РїРѕРІРµСЂС… in-memory SQLite. GROUP 5 (СЃР±РѕР№ Р‘Р” в†’ rollback) РїРµСЂРµРїРёСЃР°РЅ СЃ
  РёСЃРєСѓСЃСЃС‚РІРµРЅРЅРѕРіРѕ MockPDO->shouldFail С„Р»Р°РіР° РЅР° С‡РµСЃС‚РЅРѕРµ РЅР°СЂСѓС€РµРЅРёРµ SQL
  CHECK-РѕРіСЂР°РЅРёС‡РµРЅРёСЏ (coins<=200) вЂ” С‚РµСЃС‚РёСЂСѓРµС‚ СЂРµР°Р»СЊРЅС‹Р№ РїСѓС‚СЊ РѕС‚РєР°С‚Р° РІРЅСѓС‚СЂРё
  GameFinishService, Р° РЅРµ РёРјРёС‚Р°С†РёСЋ.

Result:
- test_game_start.php: 44/44 PASSED
- test_victory.php: 40/40 PASSED (Р±С‹Р»Рѕ 38 Р·Р°СЏРІР»РµРЅРѕ РІ СЃС‚Р°С‚СѓСЃРµ; +2 Р±РѕР»РµРµ
  СЃС‚СЂРѕРіРёРµ РїСЂРѕРІРµСЂРєРё РґРѕР±Р°РІР»РµРЅС‹ РІ GROUP 5 вЂ” inTransaction()===false,
  room РЅРµ СѓРЅРёС‡С‚РѕР¶РµРЅР° РїСЂРё РѕС‚РєР°С‚Рµ)
- РџРѕР»РЅС‹Р№ СЂРµРіСЂРµСЃСЃРёРѕРЅРЅС‹Р№ РїСЂРѕРіРѕРЅ РІСЃРµС… 22 С„Р°Р№Р»РѕРІ tests/Manual/*.php вЂ” 0 failed.

Diff: patches/FIX-4.patch

---

### FIX-5 вЂ” Stale sendError() assertion (pre-FIX-1 contract)
Status: Completed
Date: 2026-07-03

Files:
- tests/Manual/test_helpers_runner.php

Problem:
- Scenario 2 РІС‹Р·С‹РІР°Р»Р° sendError($conn, 'Invalid action syntax') РїРѕ СЃС‚Р°СЂРѕРјСѓ
  РѕРґРЅРѕРїР°СЂР°РјРµС‚СЂРѕРІРѕРјСѓ РєРѕРЅС‚СЂР°РєС‚Сѓ (РґРѕ FIX-1) Рё РѕР¶РёРґР°Р»Р° РїР°РєРµС‚ Р±РµР· РїРѕР»СЏ code.
  Р РµР°Р»СЊРЅС‹Р№ sendError(object $connection, string $code, string $message = '')
  РїРѕСЃР»Рµ FIX-1 РєРѕСЂСЂРµРєС‚РЅРѕ С‚СЂРµР±СѓРµС‚ code вЂ” С‚РµСЃС‚ РЅРµ Р±С‹Р» РѕР±РЅРѕРІР»С‘РЅ РІРјРµСЃС‚Рµ СЃ FIX-1.

Fix:
- Scenario 2 РїРµСЂРµРїРёСЃР°РЅ РїРѕРґ Р°РєС‚СѓР°Р»СЊРЅС‹Р№ РІС‹Р·РѕРІ
  sendError($conn2, 'error.invalid_json', 'Invalid action syntax') Рё
  РѕР¶РёРґР°РµРјС‹Р№ РїР°РєРµС‚ {"type":"error","code":"error.invalid_json","message":"..."}
  (ANCHOR_PROTOCOL.md В§ Error Packet). РџСЂР°РІРёР»СЃСЏ С‚РµСЃС‚, РЅРµ СЂРµР°Р»РёР·Р°С†РёСЏ вЂ”
  ANCHOR_RULES.md Part 22 (Test Philosophy): sendError() СѓР¶Рµ РІРµСЂРЅРѕ
  СЂРµР°Р»РёР·СѓРµС‚ Р°РєС‚СѓР°Р»СЊРЅС‹Р№ РєРѕРЅС‚СЂР°РєС‚.

Result:
- test_helpers_runner.php: РІСЃРµ 4 СЃС†РµРЅР°СЂРёСЏ PASSED.

Diff: patches/FIX-5.patch

### FIX-3 вЂ” Double refund on kick + admin_close_room
Status: Completed
Date: 2026-07-03

Files:
- src/Admin/AdminService.php

Problem:
- handleKickUser() СЂРµС„Р°РЅРґРёР» total_paid РёРіСЂРѕРєСѓ Рё СѓРјРµРЅСЊС€Р°Р» room bank, РЅРѕ РќР•
  РѕР±РЅСѓР»СЏР» total_paid РёРіСЂРѕРєР° РІ РїР°РјСЏС‚Рё room state.
- Р”РµР»РµРіР°С‚ СѓРґР°Р»РµРЅРёСЏ (removePlayerFromLobby/removePlayerFromGame/
  removePlayerFromApartment) Р·Р°РїРёСЃС‹РІР°Р» РІ all_players_history СЃС‚Р°СЂРѕРµ
  (РґРѕСЂРµС„Р°РЅРґРЅРѕРµ) Р·РЅР°С‡РµРЅРёРµ total_paid.
- handleCloseRoom() Р±РµР·СѓСЃР»РѕРІРЅРѕ СЂРµС„Р°РЅРґРёС‚ total_paid РёР· all_players_history
  РєР°Р¶РґРѕРјСѓ СѓС‡Р°СЃС‚РЅРёРєСѓ вЂ” РїСЂРё РїРѕСЃР»РµРґСѓСЋС‰РµРј admin_close_room() СЂР°РЅРµРµ РєРёРєРЅСѓС‚С‹Р№
  РёРіСЂРѕРє РїРѕР»СѓС‡Р°Р» СЃС‚Р°РІРєСѓ РµС‰С‘ СЂР°Р·. РќР°СЂСѓС€РµРЅРёРµ ANCHOR_CORE.md Part 2 В§
  Economic Integrity Rule.

Fix:
- РџРѕСЃР»Рµ СѓСЃРїРµС€РЅРѕР№ refund-С‚СЂР°РЅР·Р°РєС†РёРё РІ handleKickUser() РґРѕР±Р°РІР»РµРЅР° СЃС‚СЂРѕРєР°
  `$room['players'][$connId]['total_paid'] = 0;` вЂ” РѕР±РЅСѓР»РµРЅРёРµ Р”Рћ РІС‹Р·РѕРІР°
  РґРµР»РµРіР°С‚Р° СѓРґР°Р»РµРЅРёСЏ, С‡С‚РѕР±С‹ all_players_history С„РёРєСЃРёСЂРѕРІР°Р» 0 (РЅРµС‡РµРіРѕ Р±РѕР»СЊС€Рµ
  РІРѕР·РІСЂР°С‰Р°С‚СЊ СЌС‚РѕРјСѓ РёРіСЂРѕРєСѓ).

Result:
- РћР±РЅР°СЂСѓР¶РµРЅРѕ Рё Р·Р°С„РёРєСЃРёСЂРѕРІР°РЅРѕ regression-С‚РµСЃС‚Р°РјРё РІ
  tests/Manual/test_admin_integration.php (TEST 1, TEST 3).
- РџСЂРѕРІРµСЂРµРЅРѕ РЅР° Р»РѕР¶РЅРѕРїРѕР»РѕР¶РёС‚РµР»СЊРЅРѕСЃС‚СЊ: Р±РµР· С„РёРєСЃР° С‚РµСЃС‚ РґР°С‘С‚ 5 С‡РµСЃС‚РЅС‹С… FAIL,
  СЃ С„РёРєСЃРѕРј вЂ” 20/20 PASSED.
- Р’СЃСЏ СЃСѓС‰РµСЃС‚РІСѓСЋС‰Р°СЏ СЂРµРіСЂРµСЃСЃРёСЏ (test_admin_kick.php, test_admin_close_room.php
  Рё РґСЂ.) РѕСЃС‚Р°С‘С‚СЃСЏ Р·РµР»С‘РЅРѕР№.

Diff: patches/FIX-3.patch

### FIX-1 вЂ” sendError() protocol contract
Status: Completed
Date: 2026-06-21

Files:
- src/Core/Helpers.php

Problem:
- error packet РЅРµ СЃРѕРґРµСЂР¶Р°Р» РѕР±СЏР·Р°С‚РµР»СЊРЅРѕРµ РїРѕР»Рµ `code`

Fix:
- СЃРёРіРЅР°С‚СѓСЂР° РёР·РјРµРЅРµРЅР° РЅР°:

`php
sendError(object $connection, string $code, string $message = ''): void
`

- РїРѕР»Рµ `code` РґРѕР±Р°РІР»РµРЅРѕ РІ JSON РїР°РєРµС‚.

---

### FIX-2 вЂ” Registration Daily Bonus Contract
Status: Completed
Date: 2026-06-22

Files:
- src/Infrastructure/PreparedStatements.php

Problem:
- РќРѕРІС‹Р№ РїРѕР»СЊР·РѕРІР°С‚РµР»СЊ СЃРѕР·РґР°РІР°Р»СЃСЏ СЃ `last_daily_bonus = 0`
- РђРІС‚РѕР»РѕРіРёРЅ РїРѕСЃР»Рµ СЂРµРіРёСЃС‚СЂР°С†РёРё РЅР°С‡РёСЃР»СЏР» +100 РјРѕРЅРµС‚
- РќР°СЂСѓС€Р°Р»СЃСЏ РєРѕРЅС‚СЂР°РєС‚ EPIC-1.4 (`coins = 500` РїРѕСЃР»Рµ СЂРµРіРёСЃС‚СЂР°С†РёРё)

Fix:

`sql
strftime('%s','now')
`

РёСЃРїРѕР»СЊР·СѓРµС‚СЃСЏ РїСЂРё СЃРѕР·РґР°РЅРёРё РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ.

Result:
- Р‘Р°Р»Р°РЅСЃ РїРѕСЃР»Рµ СЂРµРіРёСЃС‚СЂР°С†РёРё = 500
- Р’СЃРµ РёРЅС‚РµРіСЂР°С†РёРѕРЅРЅС‹Рµ С‚РµСЃС‚С‹ РїСЂРѕС…РѕРґСЏС‚.

---

## DECISION LOG

- 2026-07-28 вЂ” FIX-16 Accepted: found during VPS `./run_ALL_tests.sh` at
  EPIC-13.4 sign-off (not a proactive audit) вЂ” `b203493` (EPIC-13.1) called
  `lottoBootstrapPhpExtensions()` in `server.php` but the function existed
  only in an uncommitted local `src/Core/Helpers.php` diff (FIX-15 Windows
  bootstrap work). Eight live-WS-subprocess tests failed on Ubuntu with a
  fatal error before port bind; local Windows runs did not catch it because
  the committed `run_ALL_tests.php` still skips those tests via
  `$skipOnWindows`. Fixed in `0de46d0`. Process takeaway mirrors FIX-12:
  VPS-authoritative runs expose gaps that dev-host shortcuts hide; never
  commit `server.php` calls to symbols not yet in the repository.
- 2026-07-28 вЂ” Phase 13 git checkpoint deviation Accepted (process note, no
  code impact): implementation followed Rule 16 intent (each Epic independently
  verifiable) but commit boundaries did not map 1:1 to Epic numbers. EPIC-13.3
  label duplicated across commits `8cd1434` and `f4cf0f4`; EPIC-13.2 bundled
  into `b203493` (EPIC-13.1) due to shared `GameService.php` edits. Documented
  in Phase 13 block above. Future phases: split file edits per Epic before
  committing, or use explicit `EPIC-13.2+13.3` combined messages when files
  cannot be separated without partial commits.
- 2026-07-26 вЂ” ROADMAP.md Phase 11/12/13/14 reorder Accepted (user
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
  note mapping old->new numbering. No code, protocol, or test changes вЂ”
  none of the affected epics were implemented yet, so this is pure
  documentation with zero migration risk.
- 2026-07-25 вЂ” FIX-12 Accepted: found during a live operational incident
  (not a proactive audit) вЂ” test runs executed as root against the live
  VPS left game.db/workerman.log/logs/server.log root-owned while the
  production systemd service runs as www-data, causing a real crash-loop
  (Permission denied on every log write, worker respawning repeatedly).
  Fixed operationally via chown (see incident thread). While diagnosing
  it, a confusing [ERROR] "CHECK constraint failed: coins <= 200" line
  was found in the production log вЂ” traced to tests/Manual/
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
  (test_logger.php) deleted, and вЂ” as a side benefit вЂ” confirmed that
  test_lobby_integration.php/test_auth_integration.php's existing (but
  silently non-functional, since PHP doesn't error on extra constructor
  arguments) attempt to redirect to '/dev/null' now actually works.
  Explicitly left out of scope: real-WS-subprocess tests (EPIC-10.3-10.7)
  spawn genuine server.php instances whose Logger is correct production
  code by definition вЂ” a different, lower-severity category of test
  noise than the false-ERROR incident this fix targets; making
  server.php's log path itself configurable is a separate, larger
  decision left for later. Verified via MD5 hash of logs/server.log
  before/after each affected test, individually and across the full
  suite. Full regression 0 failed (29 files, one deleted).
- 2026-07-24 вЂ” EPIC-10.7 Accepted: per explicit user scoping, this Epic is
  a completeness/coverage audit (does the server side have everything
  ANCHOR_CORE.md/ANCHOR_PROTOCOL.md declare?), not a re-test of business
  logic already covered by the per-module routing tests and Phase-
  specific unit tests. New tests/Manual/test_protocol_completeness.php
  parses the actual declared registries out of the ANCHOR docs at run
  time (not a hardcoded copy) and cross-references against server.php/
  src/ вЂ” 50/50 PASSED, 3 warnings, all matching already-documented KNOWN
  GAPS (admin_stats_data unimplemented, afk_warning undeclared) plus one
  new low-priority finding (error.banned declared but unused вЂ” superseded
  by the dedicated `banned` packet type, not a functional gap). No code
  defects found вЂ” confirms EPIC-10.0-10.6's wiring is genuinely complete
  against the full declared protocol surface. PHASE 10 вЂ” WEBSOCKET
  PROTOCOL: COMPLETE.
- 2026-07-24 вЂ” EPIC-10.6 Accepted + FIX-11: admin_ban_user/admin_unban_user/
  admin_kick_user/admin_close_room/admin_get_logs wired to new
  AdminHandler (AdminService Phase 9 already existed вЂ” dependency wiring
  + routing, all 7 of its nullable dependencies wired this time, unlike a
  partial wiring which would have silently degraded kick/ban removal or
  admin_close_room's timer cleanup). Proactive audit (again requested by
  user, same pattern as FIX-9/FIX-10) found FIX-11: banned users could
  fully bypass their ban. Three compounding gaps in the ban path only
  (kick was already correct) вЂ” handleBanUser()'s room-removal was
  incorrectly gated behind isset($worker->userConnections[...]), which
  FIX-10 (same day) had just made behave correctly, exposing that a
  disconnected-but-reconnect-pending banned player was never removed;
  banning an online player never closed their connection, leaving a
  stale-but-authenticated session able to keep acting; and вЂ” the most
  severe вЂ” AuthHandler::handleReconnect() never checked banned_until at
  all, unlike login(), so reconnect was a total, permanent bypass of any
  ban regardless of room state. All three fixed and independently
  verified non-false-positive. Two existing unit tests' mock connection
  classes needed a close() method added (fixture update, not a logic
  change) now that handleBanUser() actually calls it. New
  tests/Manual/test_admin_packet_routing.php: 15/15 PASSED, real WS
  client, covering both the Epic's routing and FIX-11 together. Full
  regression 0 failed (30 files).
- 2026-07-24 вЂ” FIX-10 Accepted: proactive audit before EPIC-10.6 (requested
  by user, same spirit as the FIX-6 audit before Phase 10 and the FIX-9
  discovery during EPIC-10.5) found that $worker->userConnections is never
  unset by ANY code path вЂ” permanent single-session lockout for any
  account that disconnects without being seated in a room, since neither
  login (blocked by the stale isset() check) nor reconnect (never bound
  $connection->userId for room-less sessions) could recover access.
  Undetected until now because onClose never called
  ReconnectService::handleDisconnect() before EPIC-10.5 вЂ” no disconnect
  ever reached this code at all вЂ” and the one relevant test
  (test_single_session.php, Phase 1) manually fakes the missing cleanup
  step rather than exercising it. Fixed in AuthHandler::handleReconnect()
  (now binds the connection via the same bindConnection() login/register
  use, backed by a new AuthService::getUserById()) and server.php's
  onClose (releases the userConnections slot on genuine disconnect,
  verified not to weaken ADR-001's concurrent-session rejection). Both
  halves independently verified non-false-positive. New
  tests/Manual/test_session_lifecycle.php: 6/6 PASSED, real WS client,
  no MockConnection. Full regression 0 failed. EPIC-10.6 not yet started.
- 2026-06-21 вЂ” ROADMAP.md РїСЂРёР·РЅР°РЅ РёСЃС‚РѕС‡РЅРёРєРѕРј РёСЃС‚РёРЅС‹ РїРѕ РЅСѓРјРµСЂР°С†РёРё Epic.
- 2026-06-21 вЂ” Reconnect Token Infrastructure РІС‹РЅРµСЃРµРЅ РІ PRE-BUILT COMPONENTS.
- 2026-06-22 вЂ” PHASE 1 РѕС„РёС†РёР°Р»СЊРЅРѕ Р·Р°РІРµСЂС€РµРЅР° РїРѕСЃР»Рµ РїСЂРѕС…РѕР¶РґРµРЅРёСЏ РёРЅС‚РµРіСЂР°С†РёРѕРЅРЅС‹С… С‚РµСЃС‚РѕРІ.
- 2026-06-23 вЂ” EPIC-2.0 RoomManager СЂРµР°Р»РёР·РѕРІР°РЅ (src/Core/RoomManager.php, 245 СЃС‚СЂРѕРє).
- 2026-06-25 вЂ” EPIC-2.3 Leave room Р·Р°РІРµСЂС€С‘РЅ, FIX: all_players_history РІ removePlayerFromLobby.
- 2026-06-28 вЂ” EPIC-2.4 Room list Р·Р°РІРµСЂС€С‘РЅ.
- 2026-07-02 вЂ” ADR-002 Accepted: GameFinishService extracted; Phase 7 anchor-compliance fixes applied; Phase 7 tests green.
- 2026-07-02 вЂ” EPIC-9.3 Kick player Р·Р°РІРµСЂС€С‘РЅ. KNOWN GAP: host transfer РїСЂРё kick/ban РІ apartment-СЃРѕСЃС‚РѕСЏРЅРёРё Р·Р°С„РёРєСЃРёСЂРѕРІР°РЅ РґР»СЏ Р±СѓРґСѓС‰РµРіРѕ Epic.
- 2026-07-03 вЂ” EPIC-9.5 Logs access С„Р°РєС‚РёС‡РµСЃРєРё СЂРµР°Р»РёР·РѕРІР°РЅ (handleGetLogs()/getLastLines()), Р·Р°РєСЂС‹С‚Рѕ СЂР°СЃС…РѕР¶РґРµРЅРёРµ РјРµР¶РґСѓ СЃС‚Р°С‚СѓСЃРѕРј Рё РєРѕРґРѕРј, РѕР±РЅР°СЂСѓР¶РµРЅРЅРѕРµ РїСЂРё РїРѕРґРіРѕС‚РѕРІРєРµ EPIC-9.6.
- 2026-07-03 вЂ” FIX-3 Accepted: СѓСЃС‚СЂР°РЅС‘РЅ РґРІРѕР№РЅРѕР№ СЂРµС„Р°РЅРґ kick+admin_close_room (Economic Integrity Rule). EPIC-9.6 Admin integration tests Р·Р°РІРµСЂС€С‘РЅ, PHASE 9 COMPLETE.
- 2026-07-03 вЂ” РћР±РЅР°СЂСѓР¶РµРЅС‹ pre-existing РїР°РґРµРЅРёСЏ test_game_start.php/test_victory.php (GameFinishService type mismatch) Рё test_helpers_runner.php (СѓСЃС‚Р°СЂРµРІС€РёР№ assert sendError()) вЂ” РЅРµ СЃРІСЏР·Р°РЅС‹ СЃ EPIC-9.6, Р·Р°С„РёРєСЃРёСЂРѕРІР°РЅС‹ РІ KNOWN GAPS РґР»СЏ РѕС‚РґРµР»СЊРЅРѕРіРѕ FIX РїРµСЂРµРґ Phase 10.
- 2026-07-03 вЂ” FIX-4 Accepted: Database РїРѕР»СѓС‡РёР» DI-seam (РѕРїС†РёРѕРЅР°Р»СЊРЅС‹Р№ PDO), test_game_start.php/test_victory.php РїРµСЂРµРІРµРґРµРЅС‹ РЅР° СЂРµР°Р»СЊРЅС‹Р№ GameFinishService РІРјРµСЃС‚Рѕ type-РЅРµСЃРѕРІРјРµСЃС‚РёРјС‹С… Р°РЅРѕРЅРёРјРЅС‹С… РєР»Р°СЃСЃРѕРІ. FIX-5 Accepted: test_helpers_runner.php РїСЂРёРІРµРґС‘РЅ Рє Р°РєС‚СѓР°Р»СЊРЅРѕРјСѓ РєРѕРЅС‚СЂР°РєС‚Сѓ sendError(). РџРѕР»РЅС‹Р№ СЂРµРіСЂРµСЃСЃ РїРѕ РІСЃРµРј 22 С„Р°Р№Р»Р°Рј tests/Manual/*.php вЂ” 0 failed. PHASE 9 СЃС‚Р°Р±РёР»СЊРЅР°, РїСѓС‚СЊ Рє Phase 10 РѕС‚РєСЂС‹С‚ Р±РµР· РёР·РІРµСЃС‚РЅС‹С… РґРµС„РµРєС‚РѕРІ.
- 2026-07-03 вЂ” РђСѓРґРёС‚ РЅР° Р±Р°РіРё, Р°РЅР°Р»РѕРіРёС‡РЅС‹Рµ FIX-3 (РїРѕ Р·Р°РїСЂРѕСЃСѓ РїРµСЂРµРґ Phase 10): РЅР°Р№РґРµРЅ Рё РёСЃРїСЂР°РІР»РµРЅ FIX-6 (СѓС‚РµС‡РєР° reconnect_timer РїСЂРё kick/ban СѓРґР°Р»РµРЅРёРё РІ Lobby/Apartment вЂ” Timer Integrity Rule). РџСЂРѕРІРµСЂРµРЅС‹: СЌРєРѕРЅРѕРјРёС‡РµСЃРєРёРµ РјСѓС‚Р°С†РёРё (bank/total_paid/coins вЂ” С‡РёСЃС‚Рѕ), reconnect/disconnect РёСЃС‚РѕСЂРёСЏ (С‡РёСЃС‚Рѕ), timer cleanup РїСЂРё destroyRoom (С‡РёСЃС‚Рѕ, РґРµР»РµРіРёСЂРѕРІР°РЅРёРµ РєРѕСЂСЂРµРєС‚РЅРѕ), state machine Р·Р°РїРёСЃРё СЃС‚Р°С‚СѓСЃРѕРІ (С‡РёСЃС‚Рѕ), Module Boundaries Adminв†’Game (С‡РёСЃС‚Рѕ, С‚РѕР»СЊРєРѕ РїСѓР±Р»РёС‡РЅС‹Рµ РјРµС‚РѕРґС‹), host-transfer РєРѕРјРјРµРЅС‚Р°СЂРёР№ РІ handleKickUser (СЃРѕРѕС‚РІРµС‚СЃС‚РІСѓРµС‚ СѓР¶Рµ Р·Р°РґРѕРєСѓРјРµРЅС‚РёСЂРѕРІР°РЅРЅРѕРјСѓ KNOWN GAP EPIC-9.3, РЅРѕРІС‹С… СЂР°СЃС…РѕР¶РґРµРЅРёР№ РЅРµС‚). РџРѕР»РЅС‹Р№ СЂРµРіСЂРµСЃСЃ РїРѕ 23 С„Р°Р№Р»Р°Рј tests/Manual/*.php (РґРѕР±Р°РІР»РµРЅ test_timer_integrity.php) вЂ” 0 failed.
- 2026-07-03 вЂ” Р’С‚РѕСЂРѕР№ СЂР°СѓРЅРґ Р°СѓРґРёС‚Р° (РїСЂРѕС‚РѕРєРѕР»/edge cases): РѕР±РЅР°СЂСѓР¶РµРЅС‹ Рё СѓРґР°Р»РµРЅС‹ docs/ANCHOR_PROJECT_STATUS.md (СѓСЃС‚Р°СЂРµР» СЃ РЅР°С‡Р°Р»Р° РїСЂРѕРµРєС‚Р°, РІРІРѕРґРёР» РІ Р·Р°Р±Р»СѓР¶РґРµРЅРёРµ Р±СѓРґСѓС‰РёРµ СЃРµСЃСЃРёРё). РћР±РЅР°СЂСѓР¶РµРЅС‹ docs/prompt.md (РёСЃС…РѕРґРЅРѕРµ РўР— v4.0) Рё docs/GAME_RULES.md вЂ” РѕР±Р° С‚РѕР¶Рµ РЅРµ РѕР±РЅРѕРІР»СЏР»РёСЃСЊ СЃ РЅР°С‡Р°Р»Р° РїСЂРѕРµРєС‚Р°; РёР· prompt.md РёР·РІР»РµС‡РµРЅС‹ РґРІР° РЅРµР·Р°РґРѕРєСѓРјРµРЅС‚РёСЂРѕРІР°РЅРЅС‹С… С‚СЂРµР±РѕРІР°РЅРёСЏ (rate limiting, invalid-JSON policy) вЂ” СЃРј. KNOWN GAPS, СЂРµС€РµРЅРёРµ РѕС‚Р»РѕР¶РµРЅРѕ РґРѕ EPIC-10.1 РїРѕ СЂРµС€РµРЅРёСЋ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ. РўР°РєР¶Рµ РѕР±РЅР°СЂСѓР¶РµРЅС‹ РґРІР° РїСЂРѕС‚РѕРєРѕР»СЊРЅС‹С… РґРѕР»РіР° РЅРёР·РєРѕРіРѕ РїСЂРёРѕСЂРёС‚РµС‚Р°: afk_warning (РЅРµ Р·Р°РґРµРєР»Р°СЂРёСЂРѕРІР°РЅ) Рё admin_stats_data (Р·Р°РґРµРєР»Р°СЂРёСЂРѕРІР°РЅ, РЅРµ СЂРµР°Р»РёР·РѕРІР°РЅ, Р±РµР· Epic). РљРѕРґРѕРІС‹С… Р±Р°РіРѕРІ РІ СЌС‚РѕРј СЂР°СѓРЅРґРµ РЅРµ РЅР°Р№РґРµРЅРѕ вЂ” РІСЃРµ РЅР°С…РѕРґРєРё РґРѕРєСѓРјРµРЅС‚Р°С†РёРѕРЅРЅС‹Рµ/РїСЂРѕС†РµСЃСЃРЅС‹Рµ.
- 2026-07-03 вЂ” EPIC-10.0 Protocol router Р·Р°РІРµСЂС€С‘РЅ: server.php (Workerman bootstrap, onWorkerStart/onWebSocketConnected/onMessage/onClose) Р±РµР· auth/lobby/game/admin-Р»РѕРіРёРєРё (Rule 11 Epic Isolation вЂ” ReconnectService С‚СЂРµР±СѓРµС‚ LobbyService+GameService РѕРґРЅРѕРІСЂРµРјРµРЅРЅРѕ, РїРѕРґРєР»СЋС‡РµРЅРёРµ onClose Рє СЂРµР°Р»СЊРЅРѕР№ Р±РёР·РЅРµСЃ-Р»РѕРіРёРєРµ РѕС‚Р»РѕР¶РµРЅРѕ РґРѕ EPIC-10.4/10.5). Р’РµСЂРёС„РёС†РёСЂРѕРІР°РЅ РїРѕР»РЅРѕСЃС‚СЊСЋ Р°РІС‚РѕРјР°С‚РёС‡РµСЃРєРё С‡РµСЂРµР· СЂРµР°Р»СЊРЅС‹Р№ WebSocket-РєР»РёРµРЅС‚ (Р±РµР· РІРЅРµС€РЅРёС… Р±РёР±Р»РёРѕС‚РµРє) РїРѕРІРµСЂС… РЅР°СЃС‚РѕСЏС‰РµРіРѕ TCP-СЃРѕРєРµС‚Р° вЂ” 8/8 PASSED. Rate limiting Рё invalid-JSON policy РїРѕРґС‚РІРµСЂР¶РґРµРЅС‹ РєР°Рє РѕС‚РєСЂС‹С‚С‹Рµ РІРѕРїСЂРѕСЃС‹ EPIC-10.1 (РЅРµ СЂРµР°Р»РёР·РѕРІР°РЅС‹ РЅР°РјРµСЂРµРЅРЅРѕ).
- 2026-07-23 вЂ” EPIC-10.5 Accepted + FIX-9: start_game/draw_barrel/
  apartment_choice РїРѕРґРєР»СЋС‡РµРЅС‹ Рє РЅРѕРІРѕРјСѓ GameHandler (GameService Phase 4-7
  СѓР¶Рµ СЃСѓС‰РµСЃС‚РІРѕРІР°Р» вЂ” dependency wiring + routing). ReconnectService С‚Р°РєР¶Рµ
  РїРѕРґРєР»СЋС‡С‘РЅ (onClose -> handleDisconnect(), 'reconnect' action ->
  handleReconnect() РїРѕРІРµСЂС… AuthHandler) вЂ” РѕР±Р° РµРіРѕ Р·Р°РІРёСЃРёРјС‹С… СЃРµСЂРІРёСЃР°,
  LobbyService (EPIC-10.4) Рё GameService (СЌС‚РѕС‚ Epic), РЅР°РєРѕРЅРµС† СЃРѕР±СЂР°РЅС‹
  РѕРґРЅРѕРІСЂРµРјРµРЅРЅРѕ. РќР°Р№РґРµРЅ Рё РёСЃРїСЂР°РІР»РµРЅ FIX-9 РІ РїСЂРѕС†РµСЃСЃРµ: handleReconnect() РЅРµ
  РїРµСЂРµРёРЅРґРµРєСЃРёСЂРѕРІР°Р» $room['players'] РЅР° РЅРѕРІС‹Р№ conn_id РЅРѕРІРѕРіРѕ WS-СЃРѕРµРґРёРЅРµРЅРёСЏ
  вЂ” reconnect_state РѕС‚РїСЂР°РІР»СЏР»СЃСЏ, РЅРѕ Р»СЋР±РѕРµ РґР°Р»СЊРЅРµР№С€РµРµ РґРµР№СЃС‚РІРёРµ СЃ РЅРѕРІРѕРіРѕ
  СЃРѕРµРґРёРЅРµРЅРёСЏ РЅРµ РЅР°С…РѕРґРёР»Рѕ РёРіСЂРѕРєР° (room_not_found). РСЃРїСЂР°РІР»РµРЅРѕ: re-key
  players + host_conn_id/active_drawer_conn_id/drawer_order. РџРѕРїСѓС‚РЅРѕ
  РёСЃРїСЂР°РІР»РµРЅР° СЃС‚СѓС…С€Р°СЏ assertion РІ test_auth_packet_routing.php TEST 2
  (РѕР¶РёРґР°Р»Р° error.invalid_json С‚Р°Рј, РіРґРµ EPIC-10.4 СѓР¶Рµ РґР°РІРЅРѕ РІРѕР·РІСЂР°С‰Р°РµС‚
  room_joined вЂ” СЂР°СЃС…РѕР¶РґРµРЅРёРµ РјРµР¶РґСѓ СЌС‚РёРј С„Р°Р№Р»РѕРј Рё С„Р°РєС‚РёС‡РµСЃРєРё Р·Р°РєРѕРјРјРёС‡РµРЅРЅС‹Рј
  С‚РµСЃС‚РѕРј). Housekeeping: СѓРґР°Р»С‘РЅ РїР°СЂР°Р·РёС‚РЅС‹Р№ `tests/manual/` (РЅРёР¶РЅРёР№
  СЂРµРіРёСЃС‚СЂ) РєР°С‚Р°Р»РѕРі-РґСѓР±Р»РёРєР°С‚ вЂ” `test_lobby_packet_routing.php` СЃСѓС‰РµСЃС‚РІРѕРІР°Р»
  С‚РѕР»СЊРєРѕ РІ РЅС‘Рј Рё РЅРёРєРѕРіРґР° РЅРµ Р·Р°РїСѓСЃРєР°Р»СЃСЏ run_ALL_tests.sh; РїРµСЂРµРЅРµСЃС‘РЅ РІ
  `tests/Manual/`. РќРѕРІС‹Р№ test_game_packet_routing.php 21/21 (СЂРµР°Р»СЊРЅС‹Р№ WS
  РїСЂРѕС‚РёРІ Р¶РёРІРѕРіРѕ server.php, РІРєР»СЋС‡Р°СЏ СЃРєРІРѕР·РЅСѓСЋ РїСЂРѕРІРµСЂРєСѓ FIX-9: disconnect в†’
  reconnect СЃ РЅРѕРІРѕРіРѕ СЃРѕРµРґРёРЅРµРЅРёСЏ в†’ СѓСЃРїРµС€РЅС‹Р№ draw_barrel). test_reconnect.php
  20/20 (Р±С‹Р»Рѕ 15, +5 assertions РїРѕРґ FIX-9). РџРѕР»РЅС‹Р№ СЂРµРіСЂРµСЃСЃ 0 failed.
- 2026-07-23 вЂ” EPIC-10.4 Accepted: room_list/create_room/join_room/
  leave_room РїРѕРґРєР»СЋС‡РµРЅС‹ Рє LobbyHandler (LobbyService EPIC-2.x СѓР¶Рµ
  СЃСѓС‰РµСЃС‚РІРѕРІР°Р» вЂ” dependency wiring + routing). РќРѕРІС‹Р№ LobbyHandler.php
  (thin wrapper). Router-level guard В«Already in a roomВ» РґР»СЏ
  create_room/join_room С‡РµСЂРµР· RoomManager::findRoomIdByConnId().
  РќРѕРІС‹Р№ test_lobby_packet_routing.php 22/22 (СЂРµР°Р»СЊРЅС‹Р№ WS РїСЂРѕС‚РёРІ Р¶РёРІРѕРіРѕ
  server.php). test_auth_packet_routing.php TEST 2 РѕР±РЅРѕРІР»С‘РЅ РїРѕРґ
  room_joined. РџРѕР»РЅС‹Р№ СЂРµРіСЂРµСЃСЃ 0 failed.
- 2026-07-22 вЂ” EPIC-10.3 Accepted + FIX-8: register/login/reconnect
  РїРѕРґРєР»СЋС‡РµРЅС‹ Рє AuthHandler (dependency wiring РІ onWorkerStart, routing РІ
  onMessage). РќР°Р№РґРµРЅ Рё РёСЃРїСЂР°РІР»РµРЅ FIX-8 РІ РїСЂРѕС†РµСЃСЃРµ: AuthService::login()
  РЅРёРєРѕРіРґР° РЅРµ СѓСЃС‚Р°РЅР°РІР»РёРІР°Р» $connection->userId (С‚РѕР»СЊРєРѕ $worker->
  userConnections) вЂ” Р±РµР· С„РёРєСЃР° auth_required guard (ADR-006) РЅР°РІСЃРµРіРґР°
  Р±Р»РѕРєРёСЂРѕРІР°Р» Р±С‹ Р»СЋР±РѕРµ РґРµР№СЃС‚РІРёРµ РїРѕСЃР»Рµ СѓСЃРїРµС€РЅРѕРіРѕ Р»РѕРіРёРЅР°. РќРѕРІС‹Р№
  AuthHandler::bindConnection() helper, РІС‹Р·С‹РІР°РµС‚СЃСЏ РёР· handleRegister()/
  handleLogin(). 55/55 test_auth_integration.php (Р±С‹Р»Рѕ 48, +7), РЅРѕРІС‹Р№
  test_auth_packet_routing.php 18/18 (СЂРµР°Р»СЊРЅС‹Р№ WS РїСЂРѕС‚РёРІ Р¶РёРІРѕРіРѕ
  server.php, РІРєР»СЋС‡Р°СЏ СЃРєРІРѕР·РЅСѓСЋ РїСЂРѕРІРµСЂРєСѓ FIX-8 С‡РµСЂРµР· РЅР°СЃС‚РѕСЏС‰РёР№ router).
  РџРѕР»РЅС‹Р№ СЂРµРіСЂРµСЃСЃ 0 failed.
- 2026-07-22 вЂ” EPIC-10.2 continuation: generic auth_required guard РІ
  onMessage (ADR-006) вЂ” prompt.md "РїСЂРѕРІРµСЂРєР° userId РґР»СЏ РІСЃРµС… РєРµР№СЃРѕРІ РєСЂРѕРјРµ
  register, login, reconnect", СЂРµР°Р»РёР·РѕРІР°РЅРѕ РѕРґРёРЅ СЂР°Р· РІ router'Рµ, РЅРµ
  РґСѓР±Р»РёСЂСѓРµС‚СЃСЏ РїРѕ С…РµРЅРґР»РµСЂР°Рј. EPIC-10.2 С‚РµРїРµСЂСЊ РїРѕР»РЅРѕСЃС‚СЊСЋ Р·Р°РІРµСЂС€С‘РЅ.
  18/18 test_server_bootstrap.php (Р±С‹Р»Рѕ 14, +4 вЂ” TEST 4 СѓР¶РµСЃС‚РѕС‡С‘РЅ,
  РЅРѕРІС‹Р№ TEST 8 РЅР° exempt-СЃРїРёСЃРѕРє), РїРѕР»РЅС‹Р№ СЂРµРіСЂРµСЃСЃ 0 failed.
- 2026-07-22 вЂ” EPIC-10.2 (С‡Р°СЃС‚РёС‡РЅРѕ, РїРѕ СЂРµС€РµРЅРёСЋ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ): СЂРµР°Р»РёР·РѕРІР°РЅ
  С‚РѕР»СЊРєРѕ connection-level MAX_TOTAL_PLAYERS gate вЂ” error.server_full + WS
  close code 4001 РІ onWebSocketConnected (ADR-005, closeWithCode() helper,
  СЂСѓС‡РЅР°СЏ СЃР±РѕСЂРєР° close-С„СЂРµР№РјР° вЂ” РіРѕС‚РѕРІРѕРіРѕ API РІ РёСЃРїРѕР»СЊР·СѓРµРјРѕР№ РІРµСЂСЃРёРё Workerman
  РЅРµС‚). Generic auth_required guard РІ router'Рµ СЃРѕР·РЅР°С‚РµР»СЊРЅРѕ РѕС‚Р»РѕР¶РµРЅ.
  14/14 test_server_bootstrap.php (Р±С‹Р»Рѕ 8, +6 вЂ” TEST 7 С‡РµСЂРµР· 150 СЂРµР°Р»СЊРЅС‹С…
  TCP+WS СЃРѕРµРґРёРЅРµРЅРёР№), РїРѕР»РЅС‹Р№ СЂРµРіСЂРµСЃСЃ 0 failed.
- 2026-07-22 вЂ” FIX-7 Accepted: СѓСЃС‚СЂР°РЅРµРЅРѕ СЃРјРµС€РµРЅРёРµ error.server_full (РіР»РѕР±Р°Р»СЊРЅС‹Р№
  Р»РёРјРёС‚) Рё Р·Р°РїРѕР»РЅРµРЅРЅРѕСЃС‚Рё РѕС‚РґРµР»СЊРЅРѕР№ РєРѕРјРЅР°С‚С‹ вЂ” РІРІРµРґС‘РЅ РѕС‚РґРµР»СЊРЅС‹Р№ РєРѕРґ
  error.room_full (ADR-004), РїРѕСЂСЏРґРѕРє РїСЂРѕРІРµСЂРѕРє РІ handleJoinRoom() РёР·РјРµРЅС‘РЅ РЅР°
  server-capacity-first. 91/91 lobby С‚РµСЃС‚РѕРІ (Р±С‹Р»Рѕ 90, +1 regression-С‚РµСЃС‚ РЅР°
  РїРѕСЂСЏРґРѕРє), РїРѕР»РЅС‹Р№ СЂРµРіСЂРµСЃСЃ РїРѕ РІСЃРµРј tests/Manual/*.php вЂ” 0 failed.
- 2026-07-21 вЂ” EPIC-10.1 Packet validation Р·Р°РІРµСЂС€С‘РЅ: ADR-003 С„РѕСЂРјР°Р»РёР·СѓРµС‚ rate limiting (>15 РїР°РєРµС‚РѕРІ/СЃРµРє/СЃРѕРµРґРёРЅРµРЅРёРµ в†’ Р·Р°РєСЂС‹С‚РёРµ Р±РµР· error-РїР°РєРµС‚Р°, СЃС‡РёС‚Р°РµС‚ Р’РЎР• РІС…РѕРґСЏС‰РёРµ СЃРѕРѕР±С‰РµРЅРёСЏ) Рё invalid-JSON policy (error.invalid_json, Р±РµР· СЂР°Р·СЂС‹РІР° вЂ” СЂРµС€РµРЅРѕ РІ РїРѕР»СЊР·Сѓ ANCHOR_PROTOCOL.md, РїРѕРґРєСЂРµРїР»РµРЅРѕ РїСЂРµС†РµРґРµРЅС‚РѕРј error.server_full). ANCHOR_CORE.md/ANCHOR_PROTOCOL.md РѕР±РЅРѕРІР»РµРЅС‹ (Connection Runtime Fields, Global Constants, СЃРµРјР°РЅС‚РёРєР° error.invalid_json). РћР±Р° KNOWN GAP РёР· Р°СѓРґРёС‚Р° РїСЂРѕС‚РѕРєРѕР»Р° (2026-07-03) Р·Р°РєСЂС‹С‚С‹ РєР°Рє RESOLVED. РџРѕРїСѓС‚РЅРѕ РѕР±РЅР°СЂСѓР¶РµРЅС‹ Рё РёСЃРїСЂР°РІР»РµРЅС‹ СЃР»СѓС‡Р°Р№РЅРѕ Р·Р°РєРѕРјРјРёС‡РµРЅРЅС‹Рµ СЂР°РЅС‚Р°Р№Рј-Р°СЂС‚РµС„Р°РєС‚С‹ (game.db-shm/game.db-wal/workerman.*.pid) вЂ” РґРѕР±Р°РІР»РµРЅ .gitignore. Р’РµСЂРёС„РёС†РёСЂРѕРІР°РЅРѕ 11/11 PASSED С‡РµСЂРµР· СЂРµР°Р»СЊРЅС‹Р№ WebSocket-РєР»РёРµРЅС‚, 5 РіСЂР°РЅРёС‡РЅС‹С… СЃС†РµРЅР°СЂРёРµРІ (СЂРѕРІРЅРѕ РЅР° Р»РёРјРёС‚Рµ, РїСЂРµРІС‹С€РµРЅРёРµ РЅР° 1, ping СЃС‡РёС‚Р°РµС‚СЃСЏ РЅР°СЂР°РІРЅРµ, СЃР±СЂРѕСЃ РѕРєРЅР°, РµРґРёРЅРёС‡РЅС‹Р№ РЅРµРІР°Р»РёРґРЅС‹Р№ РїР°РєРµС‚). РџРѕР»РЅС‹Р№ СЂРµРіСЂРµСЃСЃ вЂ” 25/25 tests/Manual/*.php.
---

## KNOWN GAPS / NOT VERIFIED

- вљ пёЏ OPEN (ADR-033 / EPIC-033C, 2026-08-23): Some load/stress scripts historically
  register via the real registration path against the environment `game.db`
  instead of isolating via `LOTTO_DB_PATH` / `LOTTO_TEST_CONFIG`, leaving junk
  usernames (`steady*`, `ramp_*`, `login_banned`, вЂ¦) in production SQLite.
  Admin bulk delete is the operational cleanup path; root-cause isolation of
  load-test DB writes is a separate follow-up (not fixed in Epic C).

- вљ пёЏ OPEN (2026-08-23): Р Р°Р·РѕРІС‹Р№ `SQLITE_MISUSE` (SQLSTATE[HY000]: General
  error: 21 bad parameter or other API misuse) РІ `AuthService::login()` вЂ”
  СЃС‹СЂРѕР№ С‚РµРєСЃС‚ PDO-РёСЃРєР»СЋС‡РµРЅРёСЏ СѓС‚С‘Рє РІ РєР»РёРµРЅС‚СЃРєРѕРµ РїРѕР»Рµ `message` РїР°РєРµС‚Р°
  `error` (РєРѕРґ РїСЂРё СЌС‚РѕРј РѕСЃС‚Р°Р»СЃСЏ `error.auth_invalid_credentials`,
  Р·Р°РјР°СЃРєРёСЂРѕРІР°РІ СЂРµР°Р»СЊРЅСѓСЋ РїСЂРёС‡РёРЅСѓ РїРѕРґ "РЅРµРІРµСЂРЅС‹Р№ Р»РѕРіРёРЅ РёР»Рё РїР°СЂРѕР»СЊ").
  РћР±РЅР°СЂСѓР¶РµРЅРѕ РїСЂРё Р¶РёРІРѕРј Р»РѕРіРёРЅРµ С‡РµСЂРµР· `wss://rusbingo.ju-87.club/ws`
  (РїРѕР»СЊР·РѕРІР°С‚РµР»СЊ `test4`, РїР°СЂРѕР»СЊ РїРѕРґС‚РІРµСЂР¶РґС‘РЅ РєРѕСЂСЂРµРєС‚РЅС‹Рј РЅРµР·Р°РІРёСЃРёРјРѕР№
  РїСЂРѕРІРµСЂРєРѕР№ `password_verify()` С‡РµСЂРµР· РѕС‚РґРµР»СЊРЅС‹Р№ CLI-СЃРєСЂРёРїС‚ вЂ” С‚Рѕ РµСЃС‚СЊ
  СѓС‡С‘С‚РЅС‹Рµ РґР°РЅРЅС‹Рµ Р±С‹Р»Рё Р·Р°РІРµРґРѕРјРѕ РІРµСЂРЅС‹).
  РЈСЃС‚СЂР°РЅРµРЅРѕ РїРµСЂРµР·Р°РїСѓСЃРєРѕРј `lotto-server.service` вЂ” Р°РІС‚РѕСЂРёР·Р°С†РёСЏ
  РІРѕСЃСЃС‚Р°РЅРѕРІРёР»Р°СЃСЊ РґР»СЏ РІСЃРµС… РїРѕР»СЊР·РѕРІР°С‚РµР»РµР№. РўРѕС‡РЅР°СЏ РїСЂРёС‡РёРЅР° РќР• РїРѕРґС‚РІРµСЂР¶РґРµРЅР°:
  РёРЅС†РёРґРµРЅС‚ СЃРѕРІРїР°Р» РїРѕ РІСЂРµРјРµРЅРё СЃ РїР°СЂР°Р»Р»РµР»СЊРЅС‹Рј Р·Р°РїСѓСЃРєРѕРј СЃС‚РѕСЂРѕРЅРЅРµРіРѕ
  CLI-СЃРєСЂРёРїС‚Р° (`change_admin_password.php`) Рё РѕС‚РґРµР»СЊРЅРѕРіРѕ РґРёР°РіРЅРѕСЃС‚РёС‡РµСЃРєРѕРіРѕ
  `php -r` (РЅРµР·Р°РІРёСЃРёРјРѕРµ PDO-РїРѕРґРєР»СЋС‡РµРЅРёРµ Рє С‚РѕРјСѓ Р¶Рµ `game.db`), С‡С‚Рѕ
  СЏРІР»СЏРµС‚СЃСЏ РЅР°РёР±РѕР»РµРµ РІРµСЂРѕСЏС‚РЅРѕР№ РїСЂРёС‡РёРЅРѕР№ (РєРѕР»Р»РёР·РёСЏ Р±Р»РѕРєРёСЂРѕРІРѕРє/СЃРѕСЃС‚РѕСЏРЅРёСЏ
  РєСЌС€Р° `PDOStatement` РІ `PreparedStatements::get()`), РЅРѕ РЅРµ Р±С‹Р»Р°
  Р·Р°С„РёРєСЃРёСЂРѕРІР°РЅР° Р»РѕРіР°РјРё/РІРµСЂСЃРёСЏРјРё Р”Рћ СЂРµСЃС‚Р°СЂС‚Р° вЂ” СЌС‚Р° СѓР»РёРєР° РїРѕС‚РµСЂСЏРЅР°.
  РђСЂС…РёС‚РµРєС‚СѓСЂРЅРѕ СЂРµСЃС‚Р°СЂС‚ СЃРµСЂРІРёСЃР° РќР• РґРѕР»Р¶РµРЅ С‚СЂРµР±РѕРІР°С‚СЊСЃСЏ РїРѕСЃР»Рµ
  `change_admin_password.php` (СЃРєСЂРёРїС‚ РґРµР»Р°РµС‚ `BEGIN IMMEDIATE
  TRANSACTION` в†’ `UPDATE` в†’ `COMMIT` СЃС‚СЂРѕРіРѕ in-place, Р±РµР· РїРµСЂРµСЃРѕР·РґР°РЅРёСЏ
  С„Р°Р№Р»Р° вЂ” С€С‚Р°С‚РЅС‹Р№ СЃС†РµРЅР°СЂРёР№ РґР»СЏ `PRAGMA journal_mode=WAL`, С‡РёС‚Р°С‚РµР»СЊ Рё
  РїРёСЃР°С‚РµР»СЊ РґРѕР»Р¶РЅС‹ СЃРѕСЃСѓС‰РµСЃС‚РІРѕРІР°С‚СЊ Р±РµР· СЂРµСЃС‚Р°СЂС‚Р°).
  РўСЂРµР±СѓРµС‚СЃСЏ РїСЂРё РїРѕРІС‚РѕСЂРµРЅРёРё: РќР• РїРµСЂРµР·Р°РїСѓСЃРєР°С‚СЊ СЃРµСЂРІРёСЃ СЃСЂР°Р·Сѓ вЂ” СЃРЅР°С‡Р°Р»Р°
  СЃРЅСЏС‚СЊ `grep -B2 -A2 "SQLSTATE\|bad parameter" logs/server.log`,
  РІРµСЂСЃРёРё `php --ri pdo_sqlite`/`php --ri sqlite3`, Рё РїСЂРѕРІРµСЂРёС‚СЊ, РЅРµ Р±С‹Р»Рѕ
  Р»Рё РІ СЌС‚РѕС‚ РјРѕРјРµРЅС‚ РїР°СЂР°Р»Р»РµР»СЊРЅРѕРіРѕ СЃС‚РѕСЂРѕРЅРЅРµРіРѕ РїСЂРѕС†РµСЃСЃР° СЃ РѕС‚РєСЂС‹С‚С‹Рј
  СЃРѕРµРґРёРЅРµРЅРёРµРј Рє `game.db`. РћС‚РґРµР»СЊРЅРѕ: `AuthHandler::handleLogin()`
  (СЃС‚СЂРѕРєР° `$clientMsg = $msg === 'Auth rate limited' ? ... : $msg;`)
  РїСЂРѕР±СЂР°СЃС‹РІР°РµС‚ `$e->getMessage()` Р»СЋР±РѕРіРѕ РЅРµ-`Auth rate limited`
  РёСЃРєР»СЋС‡РµРЅРёСЏ РєР»РёРµРЅС‚Сѓ РґРѕСЃР»РѕРІРЅРѕ вЂ” РІРєР»СЋС‡Р°СЏ СЃС‹СЂС‹Рµ PDO-РѕС€РёР±РєРё РїСЂРё РёС…
  РІРѕР·РЅРёРєРЅРѕРІРµРЅРёРё; СЃС‚РѕРёС‚ СЂР°СЃСЃРјРѕС‚СЂРµС‚СЊ РѕС‚РґРµР»СЊРЅС‹Р№ ADR РЅР° РјР°СЃРєРёСЂРѕРІРєСѓ Р›Р®Р‘РћР“Рћ
  РЅРµРїСЂРµРґРІРёРґРµРЅРЅРѕРіРѕ РёСЃРєР»СЋС‡РµРЅРёСЏ РІ `login()` РїРѕРґ `error.auth_invalid_credentials`
  СЃ РѕР±С‰РёРј С‚РµРєСЃС‚РѕРј, Р° РЅРµ С‚РѕР»СЊРєРѕ `Auth rate limited` (ADR-028).

- вљ пёЏ OPEN (EPIC-13.6, 2026-07-28): Reconnect mid-turn вЂ” reconnecting active
  drawer does not receive `your_turn`; frontend `onReconnectState` explicitly
  disables draw button (`setDrawButton(false, false)`) and `reconnect_state`
  carries no active-drawer field. Requires follow-up Epic (protocol change or
  `your_turn` resend) before implementation вЂ” not reproduced live yet.

- вљ пёЏ OPEN (РЅРёР·РєРёР№ РїСЂРёРѕСЂРёС‚РµС‚, РЅР°Р№РґРµРЅРѕ РїСЂРё FIX-12): real-WS-client
  subprocess-С‚РµСЃС‚С‹ (test_auth_packet_routing.php, test_lobby_packet_routing.php,
  test_game_packet_routing.php, test_admin_packet_routing.php,
  test_session_lifecycle.php, test_packet_validation.php,
  test_server_bootstrap.php) Р·Р°РїСѓСЃРєР°СЋС‚ РЅР°СЃС‚РѕСЏС‰РёР№ `php server.php start` вЂ”
  РµРіРѕ Logger РєРѕСЂСЂРµРєС‚РЅРѕ РїРёС€РµС‚ РІ СЂРµР°Р»СЊРЅС‹Р№ logs/server.log, С‚.Рє. СЌС‚Рѕ Рё РµСЃС‚СЊ
  РЅР°СЃС‚РѕСЏС‰РёР№ СЃРµСЂРІРµСЂ. Р­С‚Рѕ РѕСЃС‚Р°РІР»СЏРµС‚ РІ РїСЂРѕРґР°РєС€РЅ-Р»РѕРіРµ С‚РµСЃС‚РѕРІС‹Рµ INFO/WARNING
  СЃС‚СЂРѕРєРё СЃ С‚РµСЃС‚РѕРІС‹РјРё РёРјРµРЅР°РјРё РїРѕР»СЊР·РѕРІР°С‚РµР»РµР№ (fix10_user1, e106_admin Рё
  С‚.Рї.) вЂ” Р±РµР·РІСЂРµРґРЅС‹Р№ С€СѓРј, РѕС‚Р»РёС‡РёРјС‹Р№ РЅР° РіР»Р°Р· РѕС‚ СЂРµР°Р»СЊРЅС‹С… СЃРѕР±С‹С‚РёР№, РЅРµ С‚Р°
  РєР°С‚РµРіРѕСЂРёСЏ РїСЂРѕР±Р»РµРјС‹, С‡С‚Рѕ РІС‹Р·РІР°Р»Р° РёРЅС†РёРґРµРЅС‚ FIX-12 (Р»РѕР¶РЅС‹Р№ ERROR). РџРѕР»РЅР°СЏ
  РёР·РѕР»СЏС†РёСЏ РїРѕС‚СЂРµР±РѕРІР°Р»Р° Р±С‹ СЃРґРµР»Р°С‚СЊ РїСѓС‚СЊ Р»РѕРіРёСЂРѕРІР°РЅРёСЏ server.php
  РєРѕРЅС„РёРіСѓСЂРёСЂСѓРµРјС‹Рј (РїРµСЂРµРјРµРЅРЅР°СЏ РѕРєСЂСѓР¶РµРЅРёСЏ, РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ вЂ” С‚РµРєСѓС‰РёР№ РїСѓС‚СЊ) Рё
  РѕР±РЅРѕРІРёС‚СЊ РІСЃРµ СЃРµРјСЊ С‚РµСЃС‚РѕРІ-СЂР°РЅРЅРµСЂРѕРІ вЂ” Р±РѕР»РµРµ РєСЂСѓРїРЅРѕРµ РёР·РјРµРЅРµРЅРёРµ,
  Р·Р°С‚СЂР°РіРёРІР°СЋС‰РµРµ РїСЂРѕРґР°РєС€РЅ-РєРѕРґ СЃРµСЂРІРµСЂР°, РѕСЃС‚Р°РІР»РµРЅРѕ РЅР° СЏРІРЅРѕРµ СЂРµС€РµРЅРёРµ
  РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ.

- вњ… RESOLVED (2026-07-03): docs/ANCHOR_PROJECT_STATUS.md СѓРґР°Р»С‘РЅ вЂ” С„Р°Р№Р» РЅРµ
  РѕР±РЅРѕРІР»СЏР»СЃСЏ СЃ СЃР°РјРѕРіРѕ РЅР°С‡Р°Р»Р° РїСЂРѕРµРєС‚Р° (Р·Р°РјРѕСЂРѕР¶РµРЅ РЅР° СЃРѕСЃС‚РѕСЏРЅРёРё "EPIC-1.1,
  Lobby/WebSocket/Economy: Not implemented"), РїСЂРё СЌС‚РѕРј СЃР°Рј С„Р°Р№Р» РїСЂРµРґРїРёСЃС‹РІР°Р»
  Р±СѓРґСѓС‰РёРј РјРѕРґРµР»СЏРј С‡РёС‚Р°С‚СЊ РµРіРѕ РєР°Рє РѕР±СЏР·Р°С‚РµР»СЊРЅС‹Р№ РєРѕРЅС‚РµРєСЃС‚. Р РёСЃРє РєР°С‚Р°СЃС‚СЂРѕС„РёС‡РµСЃРєРѕР№
  РїСѓС‚Р°РЅРёС†С‹ РґР»СЏ РЅРѕРІРѕР№ СЃРµСЃСЃРёРё. ANCHOR_RULES.md Part 19 (Context Recovery Rule)
  СѓР¶Рµ РєРѕСЂСЂРµРєС‚РЅРѕ РѕРїСЂРµРґРµР»СЏРµС‚ 5 Р°РІС‚РѕСЂРёС‚РµС‚РЅС‹С… РґРѕРєСѓРјРµРЅС‚РѕРІ Р±РµР· РЅРµРіРѕ.
- вњ… RESOLVED (ADR-003, EPIC-10.1, 2026-07-21): docs/prompt.md СЃРѕРґРµСЂР¶Р°Р» РґРІР°
  С‚СЂРµР±РѕРІР°РЅРёСЏ, РѕС‚СЃСѓС‚СЃС‚РІСѓСЋС‰РёРµ РІРѕ РІСЃРµС… ANCHOR-РґРѕРєСѓРјРµРЅС‚Р°С… вЂ” (a) rate limiting
  ">15 РїР°РєРµС‚РѕРІ/СЃРµРє вЂ” СЂР°Р·СЂС‹РІ" Рё (b) РїСЂРѕС‚РёРІРѕСЂРµС‡РёРµ РїРѕ РѕР±СЂР°Р±РѕС‚РєРµ РЅРµРІР°Р»РёРґРЅРѕРіРѕ
  JSON (prompt.md "Р·Р°РєСЂС‹С‚СЊ СЃРѕРµРґРёРЅРµРЅРёРµ" vs ANCHOR_PROTOCOL.md error.invalid_json).
  Р¤РѕСЂРјР°Р»РёР·РѕРІР°РЅРѕ РІ docs/ADR/003-rate-limiting-and-invalid-json-policy.md:
  rate limiting СЂРµР°Р»РёР·РѕРІР°РЅ РєР°Рє РµСЃС‚СЊ (server.php, Constants::
  RATE_LIMIT_PACKETS_PER_WINDOW/RATE_LIMIT_WINDOW_SECONDS); invalid-JSON
  policy СЂРµС€РµРЅР° РІ РїРѕР»СЊР·Сѓ ANCHOR_PROTOCOL.md (error-РїР°РєРµС‚, Р±РµР· СЂР°Р·СЂС‹РІР°) вЂ”
  РїРѕРґРєСЂРµРїР»РµРЅРѕ СѓР¶Рµ СЂРµР°Р»РёР·РѕРІР°РЅРЅС‹Рј РїСЂРµС†РµРґРµРЅС‚РѕРј error.server_full. Р”РµС‚Р°Р»Рё вЂ”
  СЃРј. Р·Р°РїРёСЃСЊ [DONE] EPIC-10.1 РІ РЅР°С‡Р°Р»Рµ С„Р°Р№Р»Р°.
- вњ… RESOLVED (ADR-007, EPIC-11.5, 2026-07-27): РїР°РєРµС‚ afk_warning РґРѕР±Р°РІР»РµРЅ
  РІ ANCHOR_CORE.md В§ Protocol Packet Types Рё ANCHOR_PROTOCOL.md В§ Turn System.
  РџРѕРІРµРґРµРЅРёРµ Р±С‹Р»Рѕ РєРѕСЂСЂРµРєС‚РЅС‹Рј СЃ EPIC-8.3; Р·Р°РєСЂС‹С‚ РґРѕРєСѓРјРµРЅС‚Р°С†РёРѕРЅРЅС‹Р№ РґРѕР»Рі W1.
- вљ пёЏ OPEN (РЅРёР·РєРёР№ РїСЂРёРѕСЂРёС‚РµС‚, roadmap-РґРѕР»Рі): РїР°РєРµС‚ admin_stats_data РѕР±СЉСЏРІР»РµРЅ
  РІ ANCHOR_PROTOCOL.md Рё РІ СЂРµРµСЃС‚СЂРµ ANCHOR_CORE.md, РЅРѕ РЅРё СЂР°Р·Сѓ РЅРµ СЂРµР°Р»РёР·РѕРІР°РЅ
  Рё РЅРµ РЅР°Р·РЅР°С‡РµРЅ РЅРё РѕРґРЅРѕРјСѓ Epic РІ ROADMAP.md (EPIC-9.x РїРѕРєСЂС‹Р» С‚РѕР»СЊРєРѕ
  admin_logs_data). РќСѓР¶РЅРѕ Р»РёР±Рѕ Р·Р°РІРµСЃС‚Рё Epic, Р»РёР±Рѕ С„РѕСЂРјР°Р»СЊРЅРѕ РёСЃРєР»СЋС‡РёС‚СЊ РёР·
  РїСЂРѕС‚РѕРєРѕР»Р°.
- вљ пёЏ OPEN (РЅРёР·РєРёР№ РїСЂРёРѕСЂРёС‚РµС‚, РґРѕРєСѓРјРµРЅС‚Р°С†РёРѕРЅРЅС‹Р№ РґРѕР»Рі, РЅР°Р№РґРµРЅРѕ EPIC-10.7):
  РєРѕРґ РѕС€РёР±РєРё `error.banned` РѕР±СЉСЏРІР»РµРЅ РІ СЂРµРµСЃС‚СЂРµ Error Packet Codes
  (ANCHOR_PROTOCOL.md) РЅРѕ РЅРёРіРґРµ РЅРµ РёСЃРїРѕР»СЊР·СѓРµС‚СЃСЏ вЂ” РЅРѕР»СЊ usage sites РїРѕ
  РІСЃРµРјСѓ src/ Рё server.php. РќРµ С„СѓРЅРєС†РёРѕРЅР°Р»СЊРЅС‹Р№ РїСЂРѕР±РµР»: РІС‹РґРµР»РµРЅРЅС‹Р№ РїР°РєРµС‚
  `banned` (`{"type":"banned","until":...}`) СѓР¶Рµ РїРѕРєСЂС‹РІР°РµС‚ РєР°Р¶РґС‹Р№ РїСѓС‚СЊ
  РѕС‚РєР°Р·Р° РїРѕ Р±Р°РЅСѓ (login, reconnect вЂ” СЃ FIX-11, admin-СѓРІРµРґРѕРјР»РµРЅРёРµ).
  Р”РѕРєСѓРјРµРЅС‚РёСЂРѕРІР°РЅ РєР°Рє reserved/unused РІ ADR-007 (EPIC-11.5). РўСЂРµР±СѓРµС‚
  Р»РёР±Рѕ СЏРІРЅРѕРіРѕ РЅР°Р·РЅР°С‡РµРЅРёСЏ РёСЃРїРѕР»СЊР·РѕРІР°РЅРёСЏ, Р»РёР±Рѕ С„РѕСЂРјР°Р»СЊРЅРѕРіРѕ РёСЃРєР»СЋС‡РµРЅРёСЏ РёР·
  СЂРµРµСЃС‚СЂР° (С‚РѕС‚ Р¶Рµ РІС‹Р±РѕСЂ, С‡С‚Рѕ СѓР¶Рµ СЃС‚РѕРёС‚ РїРµСЂРµРґ admin_stats_data).

- вњ… RESOLVED (FIX-4, 2026-07-03): test_game_start.php/test_victory.php РїР°РґР°Р»Рё РёР·-Р·Р°
  СѓСЃС‚Р°СЂРµРІС€РёС… С„РёРєСЃС‚СѓСЂ РїРѕСЃР»Рµ ADR-002. РЈСЃС‚СЂР°РЅРµРЅРѕ вЂ” СЃРј. СЃРµРєС†РёСЋ PATCHES В§ FIX-4.
- вњ… RESOLVED (FIX-5, 2026-07-03): test_helpers_runner.php Scenario 2 Р°СЃСЃРµСЂС‚РёР» РєРѕРЅС‚СЂР°РєС‚
  РґРѕ FIX-1. РЈСЃС‚СЂР°РЅРµРЅРѕ вЂ” СЃРј. СЃРµРєС†РёСЋ PATCHES В§ FIX-5.

- composer.json РЅРµ РїРµСЂРµРїСЂРѕРІРµСЂСЏР»СЃСЏ РІ С‚РµРєСѓС‰РµР№ СЃРµСЃСЃРёРё.
- ReconnectTokenService СЃСѓС‰РµСЃС‚РІСѓРµС‚, РЅРѕ РїРѕРєР° РЅРµ РёСЃРїРѕР»СЊР·СѓРµС‚СЃСЏ.
- SessionService С‚СЂРµР±СѓРµС‚ РєРѕСЃРјРµС‚РёС‡РµСЃРєРѕР№ РѕС‡РёСЃС‚РєРё С„РѕСЂРјР°С‚РёСЂРѕРІР°РЅРёСЏ (Р±РµР· РёР·РјРµРЅРµРЅРёСЏ Р»РѕРіРёРєРё).
- lobby_afk_timer_id РїСЂРё count<2 РЅРµ РѕС‚РјРµРЅСЏРµС‚СЃСЏ РІ removePlayerFromLobby вЂ” СѓСЃС‚СЂР°РЅСЏРµС‚СЃСЏ РІ EPIC-2.6.

---

## CURRENT PROJECT STATUS

PHASE 0 вЂ” FOUNDATION: COMPLETE
PHASE 1 вЂ” AUTHENTICATION: COMPLETE
PHASE 2 вЂ” ROOM LOBBY: COMPLETE
PHASE 3 вЂ” LOTTO ENGINE: COMPLETE
PHASE 4 вЂ” GAME START: COMPLETE
PHASE 5 вЂ” TURN SYSTEM: COMPLETE
PHASE 6 вЂ” VICTORY SYSTEM: COMPLETE
PHASE 7 вЂ” APARTMENT: COMPLETE
PHASE 8 вЂ” RECONNECT & AFK: COMPLETE
PHASE 9 вЂ” ADMIN: COMPLETE
PHASE 10 вЂ” WEBSOCKET PROTOCOL: COMPLETE (10.0-10.7 all done)

Integration tests:

`text
55 / 55 PASSED (auth)                    [+7 vs Р·Р°СЏРІР»РµРЅРЅС‹С… 48 вЂ” FIX-8 regression-С‚РµСЃС‚С‹]
91 / 91 PASSED (lobby)                   [+1 vs Р·Р°СЏРІР»РµРЅРЅС‹С… 90 вЂ” FIX-7 regression-С‚РµСЃС‚]
164 / 164 PASSED (lotto engine)
44 / 44 PASSED (game start)
37 / 37 PASSED (turn system)
40 / 40 PASSED (victory system)          [+2 vs Р·Р°СЏРІР»РµРЅРЅС‹С… 38 вЂ” СѓСЃРёР»РµРЅС‹ РїСЂРѕРІРµСЂРєРё FIX-4]
32 / 32 PASSED (apartment)
8 / 8 PASSED (admin auth)
9 / 9 PASSED (admin ban)                 [close() РґРѕР±Р°РІР»РµРЅ РІ MockConnection, FIX-11]
8 / 8 PASSED (admin unban)
37 / 37 PASSED (admin kick)
28 / 28 PASSED (admin close room)
16 / 16 PASSED (admin logs)               [isolated log path, FIX-12]
20 / 20 PASSED (admin integration)       [close() РґРѕР±Р°РІР»РµРЅ РІ SpyConnection, FIX-11; isolated log path, FIX-12]
5 / 5 PASSED (timer integrity)
18 / 18 PASSED (server bootstrap вЂ” real WS client, EPIC-10.0/10.2) [+10 vs Р·Р°СЏРІР»РµРЅРЅС‹С… 8 вЂ” TEST 7 (connection gate), TEST 8 (auth_required exemptions), TEST 4 СѓР¶РµСЃС‚РѕС‡С‘РЅ]
11 / 11 PASSED (packet validation вЂ” real WS client, EPIC-10.1)
18 / 18 PASSED (auth packet routing вЂ” real WS client, EPIC-10.3, TEST 2 РѕР±РЅРѕРІР»С‘РЅ РІ EPIC-10.5)
23 / 23 PASSED (lobby packet routing вЂ” real WS client, EPIC-10.4, РїРµСЂРµРЅРµСЃС‘РЅ РёР· РїР°СЂР°Р·РёС‚РЅРѕРіРѕ tests/manual/ РІ EPIC-10.5)
20 / 20 PASSED (reconnect вЂ” Р±С‹Р»Рѕ 15, +5 assertions FIX-9, EPIC-10.5)
21 / 21 PASSED (game packet routing вЂ” real WS client, EPIC-10.5, РЅРѕРІС‹Р№ С„Р°Р№Р»)
6 / 6 PASSED (session lifecycle вЂ” real WS client, FIX-10, РЅРѕРІС‹Р№ С„Р°Р№Р»)
15 / 15 PASSED (admin packet routing вЂ” real WS client, EPIC-10.6 + FIX-11, РЅРѕРІС‹Р№ С„Р°Р№Р»)
50 / 50 PASSED, 3 warnings (protocol completeness вЂ” static doc-cross-reference, EPIC-10.7, РЅРѕРІС‹Р№ С„Р°Р№Р»)
`

FIX-12 also touched (counts unchanged, only log destination isolated):
victory system (40/40, above), lobby integration (91/91, above), auth
integration (55/55, above), plus admin logs/admin integration (both
annotated above).

tests/Manual/test_logger.php REMOVED (FIX-12) вЂ” stale duplicate of an
already-superseded print_r() smoke script (root-level copy already
deleted 2026-07-03), zero assertions, was writing raw noise into
production logs/server.log on every full-suite run. File count: 29
(was 30).

Current branch:

`text
main
`

Current stable commit (pending push вЂ” see Git Checkpoint below):

`text
FIX-12-logger-isolation (Logger DI-seam + 6 test files redirected +
2 more found via full sweep + stale test_logger.php removed; incident
root-caused and resolved; full regression 0 failed)
`

Next planned Epic:

`text
EPIC-11.4 State machine audit (Phase 11 вЂ” see docs/PHASE_11_REPORT.md;
EPIC-11.1/11.2/11.3 instrumentation complete, VPS runs pending)
`
PHASE 10 вЂ” WEBSOCKET PROTOCOL: COMPLETE (10.0-10.7 all done). Server-side
protocol surface confirmed complete against ANCHOR_CORE.md/
ANCHOR_PROTOCOL.md's own declared registries (EPIC-10.7). Four low-
priority documentation-debt items remain open (admin_stats_data,
afk_warning, error.banned, real-WS-subprocess test log noise вЂ” see
KNOWN GAPS) but none block the next phase.
Known open items: none blocking. The EPIC-10.5 KNOWN GAP
(AuthHandler::handleReconnect() not binding $connection->userId when no
matching disconnected room player is found) is RESOLVED as of FIX-10 вЂ”
handleReconnect() now unconditionally binds the connection via
bindConnection() once the token/user is validated, regardless of room
membership.
