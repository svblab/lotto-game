# Implementation Status — Lotto Game Project
- [DONE] EPIC-9.2 Unban user
Files:
- src/Admin/AdminService.php (diff)
- tests/manual/test_admin_unban.php (новый файл)
Notes:
- Реализован handleUnbanUser() для admin_unban_user
- Guard: только admin (assertAdmin)
- Валидация: user_id > 0
- DB: PreparedStatements key unban_user (banned_until=0)
- Manual tests: 8/8 PASSED

- [DONE] EPIC-9.1 Ban user
Files:
- src/Admin/AdminService.php (diff)
- src/Infrastructure/PreparedStatements.php (добавлен user_admin_by_id)
- tests/manual/test_admin_ban.php (новый файл)
Notes:
- Реализован handleBanUser() с duration: 1d / 3d / permanent
- Запрет бана администратора: error.cannot_moderate_admin
- Для онлайн-цели отправляется пакет banned {until}
- Удаление из комнаты по статусу:
  waiting -> removePlayerFromLobby(..., 'banned')
  playing -> removePlayerFromGame(..., 'banned')
  apartment -> removePlayerFromApartment(..., 'banned')
- Manual tests: 9/9 PASSED

- [DONE] EPIC-9.0 Admin authentication
Files:
- src/Admin/AdminService.php (реализован)
- tests/manual/test_admin_auth.php (новый файл)
Notes:
- Добавлен единый admin guard: AdminService::assertAdmin(object $connection): bool
- Контракт: unauthenticated -> error.auth_required, non-admin -> error.not_your_turn
- Manual tests: 8/8 PASSED

- [DONE] EPIC-8.6 Reconnect tests
Files:
- tests/manual/test_reconnect.php (новый файл)
Notes:
- 15/15 тестов пройдено
- Покрыто: disconnect->disconnected+timer, waiting-timeout removal, reconnect restore,
  reconnect_state payload, game AFK warning, auto-draw, afk removal

- [DONE] EPIC-8.5 AFK removal — ReconnectService::removePlayerFromGame(..., 'afk')
- [DONE] EPIC-8.4 Auto draw — ReconnectService::performAutoDraw()
- [DONE] EPIC-8.3 Game AFK protection — ReconnectService::ensureGameAfkTimer()/tickGameAfk()
- [DONE] EPIC-8.2 Reconnect restoration — ReconnectService::handleReconnect()
- [DONE] EPIC-8.1 Disconnect processing — ReconnectService::handleDisconnect()
- [DONE] EPIC-8.0 ReconnectService — src/Game/ReconnectService.php (реализация)
Files (8.0–8.5):
- src/Game/ReconnectService.php (новый файл, реализован)
Notes:
- Реализованы reconnect timers (15s, single-shot) и восстановление игрока по session_token
- Реализована game AFK защита с порогами 15/25/30с, auto draw и удалением по afk при 3 автоходах

PHASE 8 — RECONNECT & AFK: COMPLETE (service + manual tests)

- [DONE] EPIC-7.6 Apartment integration tests
Files:
- tests/Manual/test_apartment.php (новый файл)
Notes:
- 32/32 тестов пройдено
- Покрыто: hasLine, shouldTrigger, prepareApartment, allRequiredAnswered,
  alert broadcast, agree→payment, refuse→removal, re-trigger blocked

- [DONE] EPIC-7.5 Apartment timeout — ApartmentService::onApartmentTimeout()
- [DONE] EPIC-7.4 Apartment payment transaction — ApartmentService::finishApartment()
- [DONE] EPIC-7.3 Apartment voting — ApartmentService::handleApartmentChoice()
- [DONE] EPIC-7.2 Apartment state — ApartmentService::triggerApartment()
- [DONE] EPIC-7.1 Apartment trigger — ApartmentService::shouldTrigger() / prepareApartment()
- [DONE] EPIC-7.0 Line detection — ApartmentService::hasLine()
Files (7.0–7.5):
- src/Game/ApartmentService.php (470 строк — полный оркестратор)
- src/Game/GameService.php (735 строк — тонкие делегаторы)
Notes:
- ApartmentService расширен до оркестратора (db, stmts, logger в конструкторе)
- GameService сокращён с 985 до 735 строк
- GameService::handleApartmentChoice() / triggerApartment() — публичные делегаторы

PHASE 7 — APARTMENT: COMPLETE
PHASE 8 — RECONNECT & AFK: COMPLETE

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
- tests/Manual/test_apartment.php (новый файл)
Notes:
- 32/32 тестов пройдено
- Покрыто: hasLine (empty/full/partial), shouldTrigger (line/fired/disconnected),
  prepareApartment (status, flags, required), allRequiredAnswered,
  alert broadcast (required/immune), agree→payment (bank, immune, commit),
  refuse→removal (player_left, drawer_order), re-trigger blocked

- [DONE] EPIC-7.5 Apartment timeout — GameService::onApartmentTimeout()
- [DONE] EPIC-7.4 Apartment payment transaction — finishApartment() PDO
- [DONE] EPIC-7.3 Apartment voting — GameService::handleApartmentChoice()
- [DONE] EPIC-7.2 Apartment state — GameService::triggerApartment()
- [DONE] EPIC-7.1 Apartment trigger — ApartmentService::shouldTrigger() / prepareApartment()
- [DONE] EPIC-7.0 Line detection — ApartmentService::hasLine()
Files (7.0–7.5):
- src/Game/ApartmentService.php (новый файл, 222 строки)
- src/Game/GameService.php (diff, 985 строк)
Notes:
- Victory > Apartment: проверка победы идёт до shouldTrigger() в handleDrawBarrel()
- immune=true после agree — повторный апартамент не требует платы
- apartment_fired — at most once per game

PHASE 7 — APARTMENT: COMPLETE

⚠️ KNOWN GAP — ADR REQUIRED:
GameService 985 строк — вплотную к mandatory refactor (1000).
Кандидаты на декомпозицию: finishGame(), handleNoSurvivors() → отдельный GameFinishService.
Необходимо до начала Phase 8.

Integration tests:
48 / 48 PASSED (auth)
90 / 90 PASSED (lobby)
164 / 164 PASSED (lotto engine)
44 / 44 PASSED (game start)
37 / 37 PASSED (turn system)
38 / 38 PASSED (victory system)
32 / 32 PASSED (apartment)

Next planned Epic: EPIC-8.0 ReconnectService
⚠️ Before Phase 8: ADR for GameService decomposition required.

- [DONE] EPIC-6.5 Victory system tests
Files:
- tests/Manual/test_victory.php (новый файл)
Notes:
- 38/38 тестов пройдено
- Покрыто: checkCardVictory (0/1/2 wins), checkAllVictories (disconnected skip),
  calculatePrize (floor division, remainder burn, double+normal),
  finishGame (payout, room destruction, game_over broadcast, DB rollback),
  full draw-until-victory integration test

- [DONE] EPIC-6.4 Game finish flow — GameService::finishGame()
- [DONE] EPIC-6.3 Winner payout transaction — all-or-nothing PDO
- [DONE] EPIC-6.2 Prize calculation — VictoryService::calculatePrize()
- [DONE] EPIC-6.1 Double victory detection — встроена в checkCardVictory()
- [DONE] EPIC-6.0 Victory detection — VictoryService::checkCardVictory() / checkAllVictories()
Files (6.0–6.4):
- src/Game/VictoryService.php (новый файл, 146 строк)
- src/Game/GameService.php (diff, 703 строки)
Notes:
- markNumber() в handleDrawBarrel() применяется ко всем активным игрокам
- GameService 703 строки — зона warning; finishGame() кандидат на ADR-декомпозицию

PHASE 6 — VICTORY SYSTEM: COMPLETE

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
- tests/Manual/test_turn_system.php (новый файл)
Notes:
- 37/37 тестов пройдено
- Покрыто: sendYourTurn, nextDrawer (cyclic, skip disconnected, skip removed, null),
  handleDrawBarrel (guards, bag, drawn_numbers, AFK reset, broadcast, rotation),
  markNumber (column mapping, multi-cell, unknown number),
  full 2-player 3-turn cycle

- [DONE] EPIC-5.4 Player card marking — GameService::markNumber()
- [DONE] EPIC-5.3 Broadcast drawn barrel — barrels_drawn packet
- [DONE] EPIC-5.2 Draw barrel — GameService::handleDrawBarrel()
- [DONE] EPIC-5.1 Drawer rotation — GameService::nextDrawer()
- [DONE] EPIC-5.0 Drawer queue — GameService::sendYourTurn()
Files (5.0–5.4):
- src/Game/GameService.php (diff, 564 строки)
Notes:
- masks инициализируются в handleStartGame (bool[cardsCount][3][9], все false)
- markNumber() публичный — используется VictoryService в Phase 6
- peekNextDrawer() приватный — только для next_drawer в пакете barrels_drawn

PHASE 5 — TURN SYSTEM: COMPLETE

Integration tests:
48 / 48 PASSED (auth)
90 / 90 PASSED (lobby)
164 / 164 PASSED (lotto engine)
44 / 44 PASSED (game start)
37 / 37 PASSED (turn system)

Next planned Epic: EPIC-6.0 Victory detection
- [DONE] EPIC-4.5 Game initialization tests
Files:
- tests/Manual/test_game_start.php (новый файл)
Notes:
- 44/44 тестов пройдено
- Покрыто: auth guard, room guard, host guard, status guard, min players,
  insufficient coins, bank calculation, bag generation, card assignment,
  transaction commit, game_started packet (is_self, cards, masks, drawer_order),
  AFK fields reset

- [DONE] EPIC-4.4 Game start protocol — GameService::handleStartGame()
- [DONE] EPIC-4.3 StartGame transaction — all-or-nothing PDO transaction
- [DONE] EPIC-4.2 Bank creation — bank = sum(total_paid)
- [DONE] EPIC-4.1 Game initialization — status=playing, bag, cards, drawer
- [DONE] EPIC-4.0 Player card purchase logic — total_paid = cards_count × BET_PER_CARD
Files (4.0–4.4):
- src/Game/GameService.php (новый файл, 301 строка)
- src/Infrastructure/PreparedStatements.php (добавлен user_by_id)

PHASE 4 — GAME START: COMPLETE

Integration tests:
48 / 48 PASSED (auth)
90 / 90 PASSED (lobby)
164 / 164 PASSED (lotto engine)
44 / 44 PASSED (game start)

Next planned Epic: EPIC-5.0 Drawer queue
- [DONE] EPIC-3.4 Engine test suite
Files:
- tests/Manual/test_lotto_engine.php (новый файл)
Notes:
- 164/164 тестов пройдено
- Покрыты: generateCard, generateBag, validateCard, validateBag
- 100 итераций generateCard, 20 итераций generateBag
- Колоночные инварианты: >=1 число на столбец, сортировка top-to-bottom
- CSPRNG: Fisher-Yates + random_int() во всех shuffle-операциях

- [DONE] EPIC-3.3 Bag validator — LottoEngine::validateBag()
- [DONE] EPIC-3.2 Card validator — LottoEngine::validateCard()
- [DONE] EPIC-3.1 Bag generator — LottoEngine::generateBag()
- [DONE] EPIC-3.0 Card generator — LottoEngine::generateCard() (mask-based алгоритм)
Files (3.0–3.3):
- src/Game/LottoEngine.php (новый файл, заменена заглушка)

PHASE 3 — LOTTO ENGINE: COMPLETE

- [DONE] EPIC-2.7 Lobby integration tests
Files:
- tests/Manual/test_lobby_integration.php (новый файл)
- tests/Manual/mock_timer.php (новый файл)
Notes:
- 90/90 тестов пройдено
- Покрыто: RoomManager, handleCreateRoom, handleJoinRoom, handleLeaveRoom,
  removePlayerFromLobby, all_players_history, transferHost, handleRoomList,
  Lobby AFK Timer (MockTimer stub без event loop)
- Workerman\Timer подменён через mock_timer.php (namespace stub)
- Функциональный WebSocket тест отложен до EPIC-10.x (server.php не создан)

Commit: EPIC-2.7 lobby-integration-tests

- [DONE] EPIC-2.6 Lobby AFK system
Files:
- src/Lobby/LobbyService.php (diff)
Notes:
- startLobbyAfkTimer(): отменяет предыдущий → Timer::add(1s repeat) → проверяет time()-host.last_action >= 120s → transferHost()
- stopLobbyAfkTimer(): Timer::del + lobby_afk_timer_id = null
- handleJoinRoom(): вызов startLobbyAfkTimer() когда count(players) >= 2
- handleLeaveRoom(): вызов stopLobbyAfkTimer() когда count(players) < 2 после удаления
- destroyRoom() уже отменяет таймер — дублирования нет
- Добавлен use Workerman\Timer
- Known gap закрыт (зафиксирован в EPIC-2.3)
- Функциональный тест (WebSocket) отложен до EPIC-10.x (server.php не создан)

Commit: EPIC-2.6 lobby-afk-system

- [DONE] EPIC-2.5 Host transfer
Files:
- src/Lobby/LobbyService.php (реализовано в рамках EPIC-2.3)
Notes:
- transferHost(): FIFO по drawer_order среди active → новый host_conn_id
- Вызывается из handleLeaveRoom() при $wasHost === true
- Если нет активных игроков → destroyRoom()
- Отдельного кода не потребовалось: логика покрыта EPIC-2.3

Commit: (входит в EPIC-2.3 leave-room)

- [DONE] EPIC-2.4 Room list
Files:
- src/Lobby/LobbyService.php (diff)
Notes:
- handleRoomList(): auth guard → итерация $worker->rooms → buildRoomListEntry() → room_list пакет
- Возвращаются все комнаты в любом статусе (waiting / playing / apartment)
- Формирование entry делегировано RoomManager::buildRoomListEntry() (EPIC-2.0)
- Протокол: {"type":"room_list","rooms":[...]} — ANCHOR_PROTOCOL.md § Lobby

Commit: EPIC-2.4 room-list

- [DONE] EPIC-2.3 Leave room
Files:
- src/Lobby/LobbyService.php (diff)
Notes:
- handleLeaveRoom(): auth → findRoomIdByConnId → guard status=waiting → removePlayerFromLobby → transferHost если ушёл хост
- removePlayerFromLobby(): запись в all_players_history → unset players → очистка drawer_order → destroyRoom если пусто → broadcast player_left активным
- transferHost(): FIFO по drawer_order среди active → destroyRoom если нет активных
- Протокол: player_left {type, username, reason} — только активным, не уходящему
- Экономика: монеты не затронуты (total_paid=0 в waiting)
- Known gap: lobby_afk_timer_id при count<2 не отменяется — устраняется в EPIC-2.6

Commit: pending (git commit -m "EPIC-2.3 leave-room")

Commit: pending (git commit -m "EPIC-2.0 room-manager")

- [DONE] EPIC-2.1 Create room
Files:
- src/Lobby/LobbyService.php

Notes:
- handleCreateRoom(): валидация лимитов → bcrypt пароль → RoomManager::createRoom() → player entry → room_joined
- Проверки: MAX_ROOMS, MAX_TOTAL_PLAYERS, cards_count ∈ {1,2}, max_players ∈ [2..10]
- Монеты не списываются (Reservation Rule, ANCHOR_CORE Part 2)
- drawer_order инициализируется хостом (ANCHOR_CORE § Drawer Order Rules)
- Карты не назначаются — делегировано start_game() (EPIC-4.1)

Commit: pending (git commit -m "EPIC-2.1 create-room")

- [DONE] EPIC-2.2 Join room
Files:
- src/Lobby/LobbyService.php (diff)
Notes:
- handleJoinRoom(): auth → room exists → status=waiting → not full → MAX_TOTAL_PLAYERS → password → cards_count → player entry → room_joined → broadcast player_joined
- Пароль: password_verify(bcrypt)
- drawer_order: FIFO append (ANCHOR_CORE § Drawer Order Rules)
- room_joined → входящему; player_joined → остальным активным
Commit: pending

---

## PRE-BUILT COMPONENTS

### PRE-BUILT-1 — Reconnect Token Infrastructure
Status: Completed (изолирован, пока не используется)

Files:
- src/Auth/ReconnectTokenService.php

Notes:
- Генерация и валидация 64-символьных HEX токенов переподключения.
- Не интегрирован в текущий протокол.
- Планируемый потребитель: EPIC-8.0 ReconnectService.

---

## PATCHES

### FIX-1 — sendError() protocol contract
Status: Completed
Date: 2026-06-21

Files:
- src/Core/Helpers.php

Problem:
- error packet не содержал обязательное поле `code`

Fix:
- сигнатура изменена на:

`php
sendError(object $connection, string $code, string $message = ''): void
`

- поле `code` добавлено в JSON пакет.

---

### FIX-2 — Registration Daily Bonus Contract
Status: Completed
Date: 2026-06-22

Files:
- src/Infrastructure/PreparedStatements.php

Problem:
- Новый пользователь создавался с `last_daily_bonus = 0`
- Автологин после регистрации начислял +100 монет
- Нарушался контракт EPIC-1.4 (`coins = 500` после регистрации)

Fix:

`sql
strftime('%s','now')
`

используется при создании пользователя.

Result:
- Баланс после регистрации = 500
- Все интеграционные тесты проходят.

---

## DECISION LOG

- 2026-06-21 — ROADMAP.md признан источником истины по нумерации Epic.
- 2026-06-21 — Reconnect Token Infrastructure вынесен в PRE-BUILT COMPONENTS.
- 2026-06-22 — PHASE 1 официально завершена после прохождения интеграционных тестов.
- 2026-06-23 — EPIC-2.0 RoomManager реализован (src/Core/RoomManager.php, 245 строк).
- 2026-06-25 — EPIC-2.3 Leave room завершён, FIX: all_players_history в removePlayerFromLobby.
- 2026-06-28 — EPIC-2.4 Room list завершён.
- 2026-07-02 — ADR-002 Accepted: GameFinishService extracted; Phase 7 anchor-compliance fixes applied; Phase 7 tests green.

---

## KNOWN GAPS / NOT VERIFIED

- composer.json не перепроверялся в текущей сессии.
- ReconnectTokenService существует, но пока не используется.
- SessionService требует косметической очистки форматирования (без изменения логики).
- lobby_afk_timer_id при count<2 не отменяется в removePlayerFromLobby — устраняется в EPIC-2.6.

---

## CURRENT PROJECT STATUS

PHASE 0 — FOUNDATION: COMPLETE
PHASE 1 — AUTHENTICATION: COMPLETE
PHASE 2 — ROOM LOBBY: COMPLETE
PHASE 3 — LOTTO ENGINE: COMPLETE
PHASE 4 — GAME START: COMPLETE
PHASE 5 — TURN SYSTEM: COMPLETE
PHASE 6 — VICTORY SYSTEM: COMPLETE
PHASE 7 — APARTMENT: COMPLETE

Integration tests:

`text
48 / 48 PASSED (auth)
90 / 90 PASSED (lobby)
164 / 164 PASSED (lotto engine)
44 / 44 PASSED (game start)
37 / 37 PASSED (turn system)
38 / 38 PASSED (victory system)
32 / 32 PASSED (apartment)
15 / 15 PASSED (reconnect)
8 / 8 PASSED (admin auth)
9 / 9 PASSED (admin ban)
8 / 8 PASSED (admin unban)
`

Current branch:

`text
main
`

Current stable commit:

`text
PHASE-7 apartment-complete (manual tests green)
`

Next planned Epic:

`text
EPIC-9.3 Kick player
`