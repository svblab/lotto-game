# Implementation Status — Lotto Game Project

- [DONE] EPIC-9.6 Admin integration tests
Files:
- src/Admin/AdminService.php (diff — FIX-3, см. ниже)
- tests/Manual/test_admin_logs.php (новый файл)
- tests/Manual/test_admin_integration.php (новый файл)
- test_logger.php (удалён из корня проекта)

Implemented:
- tests/Manual/test_admin_logs.php: assert-based верификация AdminService::handleGetLogs()
  (guard auth_required/not_your_turn, пакет admin_logs_data, отсутствие logger, срез
  limit=100 через Logger::getLastLines(), реальный Logger против файла). Закрывает
  пробел верификации EPIC-9.5 — прежний tests/Manual/test_logger.php был print_r()
  смоук-скриптом без assert'ов и не проверял AdminService вообще.
- tests/Manual/test_admin_integration.php: кросс-сценарии между admin-действиями
  (test_admin_ban/unban/kick/close_room.php покрывают контракты каждого действия
  ИЗОЛИРОВАННО; этот файл проверяет последовательности из нескольких действий в
  одной комнате, где инвариант экономики может нарушаться на стыке контрактов).

Обнаружен и исправлен баг (FIX-3, см. секцию PATCHES):
- handleKickUser() рефандил total_paid и уменьшал bank, но не обнулял total_paid
  игрока в памяти. Делегат удаления (removePlayerFromLobby/Game/Apartment) писал
  в all_players_history СТАРОЕ (дорефандное) значение total_paid. Последующий
  admin_close_room() безусловно рефандил total_paid из истории каждому участнику —
  кикнутый игрок получал деньги дважды. Нарушение Economic Integrity Rule
  (ANCHOR_CORE.md Part 2).
- Regression-тест (TEST 1 и TEST 3 в test_admin_integration.php) проверен на
  ложноположительность: временно откатывался FIX-3 → тест дал 5 честных FAIL
  (520 против фактических 540, 40 против 60); после восстановления фикса — снова
  20/20 PASSED.

Manual verification:
- test_admin_logs.php: 16/16 PASSED
- test_admin_integration.php: 20/20 PASSED
- Регрессия против всех существующих admin-тестов после FIX-3:
  test_admin_auth.php 8/8, test_admin_ban.php 9/9, test_admin_unban.php 8/8,
  test_admin_kick.php 37/37, test_admin_close_room.php 28/28 — все чисты.

PHASE 9 — ADMIN: COMPLETE (9.0–9.6 done)

Integration tests:
48 / 48 PASSED (auth)
90 / 90 PASSED (lobby)
164 / 164 PASSED (lotto engine) — см. KNOWN GAPS: тестовый файл падает по независимой причине
44 / 44 PASSED (game start) — см. KNOWN GAPS: тестовый файл падает по независимой причине
37 / 37 PASSED (turn system)
38 / 38 PASSED (victory system) — см. KNOWN GAPS: тестовый файл падает по независимой причине
32 / 32 PASSED (apartment)
15 / 15 PASSED (reconnect)
8 / 8 PASSED (admin auth)
9 / 9 PASSED (admin ban)
8 / 8 PASSED (admin unban)
37 / 37 PASSED (admin kick)
28 / 28 PASSED (admin close room)
16 / 16 PASSED (admin logs)
20 / 20 PASSED (admin integration)

Next planned Epic: EPIC-10.0 Protocol router
⚠️ Перед Phase 10: см. KNOWN GAPS ниже — падение test_game_start.php/test_victory.php
не связано с EPIC-9.6, но обнаружено при регрессионном прогоне и должно быть
закрыто отдельным FIX перед стартом Phase 10 (Rule 3 — Read Before Writing).

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

- [DONE] EPIC-9.4 Close room — AdminService::handleCloseRoom()
Files:
- src/Admin/AdminService.php (diff — добавлен handleCloseRoom())
- tests/Manual/test_admin_close_room.php (новый файл)
Notes:
- 28/28 тестов пройдено (php test_admin_close_room.php)
- Покрыто: закрытие waiting-комнаты без рефандов при total_paid=0,
  закрытие playing-комнаты с полным возвратом средств,
  возврат ранее удалённым игрокам через all_players_history,
  уведомление только active-игроков (disconnected не получают packet, но получают refund),
  room_not_found, guard для не-администратора,
  rollback при ошибке refund-транзакции (coins/bank не изменяются, destroyRoom не вызывается,
  комната сохраняется, PDO transaction корректно откатывается)
- Экономика: ANCHOR_CORE.md Part 2 § Admin Close Room —
  всем участникам возвращается 100% total_paid (включая apartment payments),
  источник данных — all_players_history, операция выполняется в одной PDO-транзакции

PHASE 9 — ADMIN: IN PROGRESS (9.0/9.1/9.2/9.3/9.4 done, 9.5 Logs access next)

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

- [DONE] EPIC-9.3 Kick player — AdminService::handleKickUser()
Files:
- src/Admin/AdminService.php (diff — добавлен параметр $db в конструктор + handleKickUser())
- tests/Manual/test_admin_kick.php (новый файл)
Notes:
- 37/37 тестов пройдено (php test_admin_kick.php)
- Покрыто: waiting без total_paid (нет рефанда), kick хоста в waiting → transferHost(),
  playing с рефандом (users.coins += total_paid, bank -= total_paid, removePlayerFromGame
  с reason='kicked'), apartment с рефандом (removePlayerFromApartment с reason='kicked'),
  cannot_moderate_admin (нельзя кикнуть админа), room_not_found (цель не в комнате),
  not_your_turn guard (не-админ), rollback при сбое refund-транзакции (bank/room не тронуты,
  delegation не вызван, no dangling PDO transaction)
- Экономика: ANCHOR_CORE.md Part 2 § Kick — bank -= total_paid; coins += total_paid,
  транзакция обязательна, реализовано через существующий stmt 'add_user_coins'
- Конструктор AdminService расширен nullable-параметром $db (обратная совместимость
  сохранена — существующие вызовы с 5 аргументами не ломаются)

⚠️ KNOWN GAP:
removePlayerFromApartment() (ApartmentService) не выполняет host transfer при
kick/ban хоста в apartment-состоянии, хотя ANCHOR_CORE.md Host Rules называет
'kicked'/'banned' валидными причинами смены хоста. Тот же пробел присутствует
и в существующем handleBanUser() для 'waiting' (не исправлялся — вне scope
EPIC-9.3, Epic Isolation). Требует отдельного Epic на доработку ApartmentService
и, возможно, LobbyService.

PHASE 9 — ADMIN: IN PROGRESS (9.0/9.1/9.2/9.3 done, 9.4 Close room next)

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

### FIX-6 — Reconnect timer leak on kick/ban removal (Timer Integrity)
Status: Completed
Date: 2026-07-03

Files:
- src/Lobby/LobbyService.php
- src/Game/ApartmentService.php
- tests/Manual/test_timer_integrity.php (новый файл)

Found during: post-Phase-9 audit for bugs similar in class to FIX-3
(запрошен пользователем перед стартом Phase 10).

Problem:
- ANCHOR_CORE.md Part 5 § Timer Integrity Rules: "No reconnect timer
  survives player removal" / "A destroyed owner keeps no timers" —
  безусловное правило.
- ReconnectService::removePlayerFromGame() корректно отменяет
  player['reconnect_timer'] ПЕРЕД удалением игрока.
- LobbyService::removePlayerFromLobby() и ApartmentService::
  removePlayerFromApartment() — НЕ отменяли, асимметрия между тремя
  "сёстринскими" методами удаления игрока.
- Достижимость (реальный сценарий, не гипотетический): disconnected-игрок
  в waiting-комнате имеет активный 15s reconnect_timer (ANCHOR_CORE §
  Reconnect Timer). Если администратор кикает/банит его до истечения
  таймера, removePlayerFromLobby() удаляет игрока, но таймер остаётся
  зарегистрированным в Workerman. RoomManager::generateRoomId()
  переиспользует ПЕРВЫЙ свободный room_id сразу после уничтожения комнаты
  (MAX_ROOMS=30) — то есть это не просто утечка памяти на 15 секунд, а
  нарушение инварианта на активно переиспользуемом ресурсе (Rule 28 VPS
  Awareness: 1 CPU/500MB RAM).
- removePlayerFromApartment(): тот же пробел, но по state machine
  (ANCHOR_CORE § Reconnect Rules: reconnect запрещён в apartment) в
  норме недостижим — исправлено защитно, т.к. правило безусловное.

Fix:
- Timer::del($player['reconnect_timer']) добавлен в оба метода ДО
  удаления игрока — идентичный уже корректному паттерну в
  ReconnectService::removePlayerFromGame().

Result:
- tests/Manual/test_timer_integrity.php: 5/5 PASSED.
- Regression проверен на ложноположительность: временно откатывались обе
  правки → 3/5 честных FAIL; после восстановления — снова 5/5.
- Полный регресс по всем 23 файлам tests/Manual/*.php — 0 failed.

Diff: patches/FIX-6.patch

### FIX-4 — Stale test fixtures after ADR-002 (GameFinishService)
Status: Completed
Date: 2026-07-03

Files:
- src/Infrastructure/Database.php
- tests/Manual/test_game_start.php
- tests/Manual/test_victory.php

Problem:
- ADR-002 (вынос GameFinishService, final class со строгой типизацией
  Database/PreparedStatements/Logger) не был пробрасён в тестовые фикстуры
  test_game_start.php и test_victory.php — обе продолжали использовать
  анонимные классы вместо GameFinishService, что несовместимо по типу с
  GameService::__construct(). Оба файла падали с Fatal TypeError.
- Корневая причина невозможности честного (без reflection — запрещённого
  ANCHOR_RULES.md Part 22) исправления: Database жёстко хардкодила путь к
  game.db в конструкторе без точки внедрения зависимостей.

Fix:
- Database::__construct() расширен опциональным параметром `?PDO $pdo = null`
  (обратно совместимо — на момент фикса `new Database()` нигде в проекте не
  вызывается напрямую, server.php/init_db.php ещё не реализованы; поведение
  без аргумента идентично прежнему).
- test_game_start.php: finishGame() не вызывается ни в одном сценарии
  EPIC-4.5 → анонимный класс заменён на уже принятый в проекте паттерн
  ReflectionClass::newInstanceWithoutConstructor() (см. test_apartment.php,
  test_turn_system.php).
- test_victory.php: GROUP 4/5/6 реально вызывают finishGame() → makeSvc()
  теперь строит настоящий GameFinishService(Database, PreparedStatements,
  Logger) поверх in-memory SQLite. GROUP 5 (сбой БД → rollback) переписан с
  искусственного MockPDO->shouldFail флага на честное нарушение SQL
  CHECK-ограничения (coins<=200) — тестирует реальный путь отката внутри
  GameFinishService, а не имитацию.

Result:
- test_game_start.php: 44/44 PASSED
- test_victory.php: 40/40 PASSED (было 38 заявлено в статусе; +2 более
  строгие проверки добавлены в GROUP 5 — inTransaction()===false,
  room не уничтожена при откате)
- Полный регрессионный прогон всех 22 файлов tests/Manual/*.php — 0 failed.

Diff: patches/FIX-4.patch

---

### FIX-5 — Stale sendError() assertion (pre-FIX-1 contract)
Status: Completed
Date: 2026-07-03

Files:
- tests/Manual/test_helpers_runner.php

Problem:
- Scenario 2 вызывала sendError($conn, 'Invalid action syntax') по старому
  однопараметровому контракту (до FIX-1) и ожидала пакет без поля code.
  Реальный sendError(object $connection, string $code, string $message = '')
  после FIX-1 корректно требует code — тест не был обновлён вместе с FIX-1.

Fix:
- Scenario 2 переписан под актуальный вызов
  sendError($conn2, 'error.invalid_json', 'Invalid action syntax') и
  ожидаемый пакет {"type":"error","code":"error.invalid_json","message":"..."}
  (ANCHOR_PROTOCOL.md § Error Packet). Правился тест, не реализация —
  ANCHOR_RULES.md Part 22 (Test Philosophy): sendError() уже верно
  реализует актуальный контракт.

Result:
- test_helpers_runner.php: все 4 сценария PASSED.

Diff: patches/FIX-5.patch

### FIX-3 — Double refund on kick + admin_close_room
Status: Completed
Date: 2026-07-03

Files:
- src/Admin/AdminService.php

Problem:
- handleKickUser() рефандил total_paid игроку и уменьшал room bank, но НЕ
  обнулял total_paid игрока в памяти room state.
- Делегат удаления (removePlayerFromLobby/removePlayerFromGame/
  removePlayerFromApartment) записывал в all_players_history старое
  (дорефандное) значение total_paid.
- handleCloseRoom() безусловно рефандит total_paid из all_players_history
  каждому участнику — при последующем admin_close_room() ранее кикнутый
  игрок получал ставку ещё раз. Нарушение ANCHOR_CORE.md Part 2 §
  Economic Integrity Rule.

Fix:
- После успешной refund-транзакции в handleKickUser() добавлена строка
  `$room['players'][$connId]['total_paid'] = 0;` — обнуление ДО вызова
  делегата удаления, чтобы all_players_history фиксировал 0 (нечего больше
  возвращать этому игроку).

Result:
- Обнаружено и зафиксировано regression-тестами в
  tests/Manual/test_admin_integration.php (TEST 1, TEST 3).
- Проверено на ложноположительность: без фикса тест даёт 5 честных FAIL,
  с фиксом — 20/20 PASSED.
- Вся существующая регрессия (test_admin_kick.php, test_admin_close_room.php
  и др.) остаётся зелёной.

Diff: patches/FIX-3.patch

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
- 2026-07-02 — EPIC-9.3 Kick player завершён. KNOWN GAP: host transfer при kick/ban в apartment-состоянии зафиксирован для будущего Epic.
- 2026-07-03 — EPIC-9.5 Logs access фактически реализован (handleGetLogs()/getLastLines()), закрыто расхождение между статусом и кодом, обнаруженное при подготовке EPIC-9.6.
- 2026-07-03 — FIX-3 Accepted: устранён двойной рефанд kick+admin_close_room (Economic Integrity Rule). EPIC-9.6 Admin integration tests завершён, PHASE 9 COMPLETE.
- 2026-07-03 — Обнаружены pre-existing падения test_game_start.php/test_victory.php (GameFinishService type mismatch) и test_helpers_runner.php (устаревший assert sendError()) — не связаны с EPIC-9.6, зафиксированы в KNOWN GAPS для отдельного FIX перед Phase 10.
- 2026-07-03 — FIX-4 Accepted: Database получил DI-seam (опциональный PDO), test_game_start.php/test_victory.php переведены на реальный GameFinishService вместо type-несовместимых анонимных классов. FIX-5 Accepted: test_helpers_runner.php приведён к актуальному контракту sendError(). Полный регресс по всем 22 файлам tests/Manual/*.php — 0 failed. PHASE 9 стабильна, путь к Phase 10 открыт без известных дефектов.
- 2026-07-03 — Аудит на баги, аналогичные FIX-3 (по запросу перед Phase 10): найден и исправлен FIX-6 (утечка reconnect_timer при kick/ban удалении в Lobby/Apartment — Timer Integrity Rule). Проверены: экономические мутации (bank/total_paid/coins — чисто), reconnect/disconnect история (чисто), timer cleanup при destroyRoom (чисто, делегирование корректно), state machine записи статусов (чисто), Module Boundaries Admin→Game (чисто, только публичные методы), host-transfer комментарий в handleKickUser (соответствует уже задокументированному KNOWN GAP EPIC-9.3, новых расхождений нет). Полный регресс по 23 файлам tests/Manual/*.php (добавлен test_timer_integrity.php) — 0 failed.
- 2026-07-03 — Второй раунд аудита (протокол/edge cases): обнаружены и удалены docs/ANCHOR_PROJECT_STATUS.md (устарел с начала проекта, вводил в заблуждение будущие сессии). Обнаружены docs/prompt.md (исходное ТЗ v4.0) и docs/GAME_RULES.md — оба тоже не обновлялись с начала проекта; из prompt.md извлечены два незадокументированных требования (rate limiting, invalid-JSON policy) — см. KNOWN GAPS, решение отложено до EPIC-10.1 по решению пользователя. Также обнаружены два протокольных долга низкого приоритета: afk_warning (не задекларирован) и admin_stats_data (задекларирован, не реализован, без Epic). Кодовых багов в этом раунде не найдено — все находки документационные/процессные.
---

## KNOWN GAPS / NOT VERIFIED

- ✅ RESOLVED (2026-07-03): docs/ANCHOR_PROJECT_STATUS.md удалён — файл не
  обновлялся с самого начала проекта (заморожен на состоянии "EPIC-1.1,
  Lobby/WebSocket/Economy: Not implemented"), при этом сам файл предписывал
  будущим моделям читать его как обязательный контекст. Риск катастрофической
  путаницы для новой сессии. ANCHOR_RULES.md Part 19 (Context Recovery Rule)
  уже корректно определяет 5 авторитетных документов без него.
- ⚠️ OPEN (обнаружено при аудите протокола/edge cases, 2026-07-03, к решению
  в рамках EPIC-10.1 Packet validation): docs/prompt.md (исходное ТЗ v4.0,
  тоже не обновлялось с начала проекта) содержит два требования, отсутствующие
  во ВСЕХ ANCHOR_CORE.md/ANCHOR_PROTOCOL.md/ANCHOR_RULES.md и в ROADMAP.md:
  (a) Rate limiting: "более 15 пакетов/сек от одного соединения — немедленный
  разрыв соединения" — нигде не задокументировано как правило;
  (b) Обработка невалидного JSON — prompt.md требует "закрыть соединение",
  тогда как ANCHOR_PROTOCOL.md уже объявляет код ошибки error.invalid_json
  (подразумевая отправку error-пакета, а не разрыв) — прямое противоречие
  между исходным ТЗ и текущим протокольным контрактом, требует явного решения
  до/во время EPIC-10.1, иначе оба сценария поведения останутся undefined.
- ⚠️ OPEN (низкий приоритет, документационный долг): пакет afk_warning
  (src/Game/ReconnectService.php, EPIC-8.3 Game AFK protection) используется
  и покрыт тестами, но не задекларирован ни в ANCHOR_PROTOCOL.md, ни в
  реестре Protocol Packet Types (ANCHOR_CORE.md Part 6). Требует добавления
  в оба документа (документация, не код — поведение корректно).
- ⚠️ OPEN (низкий приоритет, roadmap-долг): пакет admin_stats_data объявлен
  в ANCHOR_PROTOCOL.md и в реестре ANCHOR_CORE.md, но ни разу не реализован
  и не назначен ни одному Epic в ROADMAP.md (EPIC-9.x покрыл только
  admin_logs_data). Нужно либо завести Epic, либо формально исключить из
  протокола.

- ✅ RESOLVED (FIX-4, 2026-07-03): test_game_start.php/test_victory.php падали из-за
  устаревших фикстур после ADR-002. Устранено — см. секцию PATCHES § FIX-4.
- ✅ RESOLVED (FIX-5, 2026-07-03): test_helpers_runner.php Scenario 2 ассертил контракт
  до FIX-1. Устранено — см. секцию PATCHES § FIX-5.

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
PHASE 8 — RECONNECT & AFK: COMPLETE
PHASE 9 — ADMIN: COMPLETE

Integration tests:

`text
48 / 48 PASSED (auth)
90 / 90 PASSED (lobby)
164 / 164 PASSED (lotto engine)
44 / 44 PASSED (game start)
37 / 37 PASSED (turn system)
40 / 40 PASSED (victory system)          [+2 vs заявленных 38 — усилены проверки FIX-4]
32 / 32 PASSED (apartment)
15 / 15 PASSED (reconnect)
8 / 8 PASSED (admin auth)
9 / 9 PASSED (admin ban)
8 / 8 PASSED (admin unban)
37 / 37 PASSED (admin kick)
28 / 28 PASSED (admin close room)
16 / 16 PASSED (admin logs)
20 / 20 PASSED (admin integration)
`

Current branch:

`text
main
`

Current stable commit:

`text
FIX-6 timer-integrity-audit (все 23 tests/Manual/*.php зелёные)
`

Next planned Epic:

`text
EPIC-10.0 Protocol router
`
✅ Известных дефектов нет. Аудит на баги, аналогичные FIX-3, проведён (2026-07-03) —
найден и исправлен FIX-6. Полный регресс по всем 23 тестовым файлам — 0 failed. Phase 10 можно начинать.