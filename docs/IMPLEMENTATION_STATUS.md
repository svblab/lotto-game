# Implementation Status — Lotto Game Project

- [DONE] EPIC-10.1 Packet validation
Files:
- docs/ADR/003-rate-limiting-and-invalid-json-policy.md (новый файл)
- docs/ANCHOR_CORE.md (diff — Connection Runtime Fields + Global Constants,
  Part 1 и Part 6, согласно ADR-003)
- docs/ANCHOR_PROTOCOL.md (diff — уточнение семантики error.invalid_json)
- src/Core/Constants.php (diff — RATE_LIMIT_PACKETS_PER_WINDOW=15,
  RATE_LIMIT_WINDOW_SECONDS=1)
- server.php (diff — реализация rate limiting в onMessage, инициализация
  packetCount/packetWindowStart в onWebSocketConnected)
- tests/Manual/test_packet_validation.php (новый файл)
- .gitignore (новый файл — попутно обнаружены случайно закоммиченные
  рантайм-артефакты game.db-shm/game.db-wal/workerman.*.pid)

Implemented:
- ADR-003 закрывает оба KNOWN GAP, зафиксированных при пре-Phase-10 аудите:
  1. Rate limiting (docs/prompt.md): > RATE_LIMIT_PACKETS_PER_WINDOW (15)
     пакетов за RATE_LIMIT_WINDOW_SECONDS (1) секунду от одного соединения
     → немедленное закрытие БЕЗ error-пакета. Считает ЛЮБЫЕ входящие
     сообщения (валидные/невалидные/ping) — инкремент до json_decode.
  2. Invalid-JSON policy (противоречие prompt.md "закрыть соединение" vs
     ANCHOR_PROTOCOL.md error.invalid_json): решено в пользу
     ANCHOR_PROTOCOL.md — код ошибки предполагает, что клиент его получит
     и разберёт, значит соединение НЕ закрывается. Подкреплено прецедентом
     error.server_full (уже реализован в LobbyService через sendError(),
     не через разрыв). Защиту от флуда малформед-JSON обеспечивает rate
     limiting, а не разрыв на первом невалидном пакете.
- Оба решения формализованы как ADR-003 и отражены в ANCHOR_CORE.md
  (новые Connection Runtime Fields: packetCount, packetWindowStart) и
  ANCHOR_PROTOCOL.md (явное уточнение про error.invalid_json).

Verification (полностью автоматическая, реальный WebSocket-клиент):
- tests/Manual/test_packet_validation.php — 11/11 PASSED, 5 сценариев:
  1. Ровно 15 невалидных пакетов — все получают error.invalid_json,
     соединение живо.
  2. 16-й пакет в том же окне — закрытие БЕЗ error-пакета (отличается от
     таймаута через feof()-проверку, не только по отсутствию ответа).
  3. Rate limit считает ping наравне с прочими (не делает исключения для
     валидных action) — 15 ping ок, 16-й закрывает соединение.
  4. Окно реально сбрасывается — burst 15+пауза>1s+burst 15 не суммируется
     в закрытие.
  5. Единичный невалидный JSON не закрывает соединение (базовый ADR-003
     сценарий вне контекста rate limit).
- Прогнано 3 раза подряд — стабильно, ~4s каждый, без зомби-процессов.
- Полный регресс по всем 25 файлам tests/Manual/*.php (было 24, добавлен
  test_packet_validation.php) — 0 failed.

PHASE 10 — WEBSOCKET PROTOCOL: IN PROGRESS (10.0, 10.1 done). Следующий:
EPIC-10.2 Protocol error handling.

- [DONE] EPIC-10.0 Protocol router
Files:
- server.php (новый файл, 175 строк)
- tests/Manual/test_server_bootstrap.php (новый файл, 227 строк)

Implemented:
- Workerman bootstrap: websocket://0.0.0.0:8080, single worker (count=1),
  согласно LOCAL_ENVIRONMENT.md и ANCHOR_CORE.md Part 1.
- onWorkerStart: инициализация Database/Logger/RoomManager-совместимой
  runtime-памяти ($worker->rooms/userConnections/sessionTokens = []),
  Global Watchdog Timer (60s, закрытие мёртвых соединений по порогам
  AUTHORIZED_TIMEOUT/UNAUTHORIZED_TIMEOUT — ANCHOR_CORE.md Part 5).
- onWebSocketConnected (не onConnect — handshake на этот момент уже
  завершён, что подтверждено докблоком Workerman "Emitted after websocket
  handshake"): инициализация Connection Runtime Fields (userId/username/
  isAdmin/sessionToken/lastPing), немедленная отправка hello
  {"type":"hello","protocol_version":1}.
- onMessage: безопасный json_decode (не-объект → error.invalid_json),
  ping без ответа (ANCHOR_PROTOCOL.md § Heartbeat), пустой action-диспетчер
  (match/default → error.invalid_json для любого ещё не подключённого action).
- onClose: диагностическое логирование + явный TODO — полная реконнект-
  логика невозможна в этом Epic (см. ниже).

Сознательно НЕ реализовано (Rule 11 Epic Isolation):
- Маршрутизация auth/lobby/game/admin-пакетов — EPIC-10.3/10.4/10.5/10.6.
  AuthHandler уже существует (Phase 1), но не подключён.
  LobbyHandler/GameHandler/AdminHandler ещё предстоит создать.
- Rate limiting (>15 пакетов/сек) и точная policy невалидного JSON —
  EPIC-10.1 (решено с пользователем явно, см. KNOWN GAPS).
- onClose → ReconnectService::handleDisconnect() не подключён: сам
  конструктор ReconnectService требует ОДНОВРЕМЕННО LobbyService И
  GameService — подключить его в server.php раньше EPIC-10.4/10.5
  означало бы нарушить Rule 11 (Auth+Lobby+Game в одном Epic).

Verification (автоматическая, полностью самодостаточная):
- tests/Manual/test_server_bootstrap.php поднимает server.php как
  реальный подпроцесс (proc_open), общается с ним через собственноручно
  написанный RFC6455 WebSocket-клиент (без внешних библиотек) по
  настоящему TCP-сокету на 127.0.0.1:8080, затем корректно останавливает
  процесс (SIGTERM → graceful shutdown, SIGKILL как fallback).
- Результат: 8/8 PASSED. Прогнан дважды подряд — порт корректно
  освобождается между запусками.
- Ручная проверка `php server.php start` — Workerman поднимается,
  таблица воркеров показывает [OK], graceful stop по SIGTERM.
- ⚠️→✅ ИСПРАВЛЕНО (2026-07-21): первая версия test_server_bootstrap.php
  зависала на VPS (требовался Ctrl+C). Причина — классический proc_open
  deadlock: stdout/stderr дочернего процесса шли в pipe, который никогда
  не вычитывался; ОС-буфер пайпа заполнялся выводом Workerman, дочерний
  процесс блокировался на write() до реального биндинга порта. В песочнице
  не воспроизводилось из-за небольшого объёма вывода, помещавшегося в буфер.
  Исправлено: вывод дочернего процесса теперь идёт в файлы (['file', ...],
  не ['pipe', ...] — запись в файл не блокируется по объёму), опрос порта
  вместо фиксированного sleep, диагностика stdout/stderr при сбое биндинга,
  жёсткий watchdog по SIGALRM (HARD_TIMEOUT_SECONDS=20) как последний
  рубеж — скрипт физически не может зависнуть навсегда. Проверено 5
  прогонов подряд (~3-4s каждый) + отдельно путь диагностики при заведомо
  нерабочем порте (5s, чистое сообщение об ошибке, без зависания).
- ⚠️→✅ ИСПРАВЛЕНО (второй раунд, тот же день): после первого фикса тест
  всё ещё падал на VPS — "WS handshake failed" с пустым ответом.
  Причина: осиротевший процесс server.php с ПЕРВОЙ (зависшей) попытки
  остался жить и держать порт 8080 (Workerman stdout честно писал
  "already running"), а тест по ошибке подключался к ЭТОМУ старому
  процессу вместо своего свежесозданного. Исправлено: перед стартом
  тест теперь сам вызывает `php server.php stop` (idempotent, безопасно
  при отсутствии запущенного процесса) для гарантированно чистого
  состояния, плюс явная диагностика "already running" с подсказкой
  ручной команды на случай, если self-healing не сработает. Проверено:
  вручную создан осиротевший процесс → тест сам его погасил и стартовал
  заново — 8/8 PASSED, без зомби-процессов после. 3 дополнительных
  прогона с чистого состояния — стабильно 8/8, ~3-4s каждый.
- Полный регресс по всем 24 файлам tests/Manual/*.php (был 23, добавлен
  test_server_bootstrap.php) — 0 failed.

PHASE 10 — WEBSOCKET PROTOCOL: IN PROGRESS (10.0 done, 10.1 Packet
validation next — включает решение по rate limiting и invalid-JSON policy)

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

Next planned Epic: EPIC-10.0 Protocol router (историческая запись на момент завершения EPIC-9.6 — выполнено, см. запись выше)

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

## EPIC-10.5 — Game packet routing (+ FIX-9, found during wiring)
Status: Completed
Date: 2026-07-23

Files:
- src/Game/GameHandler.php (новый файл — thin wrapper над GameService,
  тот же паттерн что LobbyHandler/AuthHandler)
- server.php (diff — LottoEngine/VictoryService/ApartmentService/
  GameFinishService/GameService/GameHandler dependency wiring in
  onWorkerStart, идентичный порядок конструктора уже принятому в
  tests/Manual/test_game_start.php; start_game/draw_barrel/
  apartment_choice wired in onMessage dispatch; ReconnectService теперь
  тоже собран — оба его зависимых сервиса, LobbyService (EPIC-10.4) и
  GameService (этот Epic), наконец готовы одновременно; onClose делегирует
  ReconnectService::handleDisconnect(); action 'reconnect' дополнительно
  вызывает ReconnectService::handleReconnect() после AuthHandler для
  восстановления игрового состояния/reconnect_state)
- src/Game/ReconnectService.php (diff — FIX-9, см. ниже)
- tests/Manual/test_reconnect.php (diff — GROUP 3 assertions обновлены под
  FIX-9: запись переезжает на новый conn_id, а не остаётся на старом; +3
  новых assertion на host_conn_id/active_drawer_conn_id/drawer_order)
- tests/Manual/test_game_packet_routing.php (новый файл — 21 assertions,
  real WS client against live server.php, `e105_` username prefix)

GameService/VictoryService/ApartmentService/GameFinishService already
existed (Phase 4-7) and required no new business logic for the packet-
routing part itself — matching every other EPIC-10.x so far, this is pure
dependency wiring + routing. The one router-level addition is in
GameHandler::handleApartmentChoice(): validates that `choice` is a
non-empty string before delegating (error.invalid_json otherwise) —
GameService/ApartmentService already validate the actual value
('agree'/'refuse') internally.

Reconnect wiring was deliberately bundled into this Epic rather than left
pending further, because ReconnectService's constructor is the literal
reason onClose and 'reconnect' were stubbed out since EPIC-10.0 — both of
its dependencies (LobbyService, GameService) are only both available as of
this Epic. This is not a new/separate feature so much as completing what
EPIC-10.0's own code comments already earmarked for "EPIC-10.4/10.5".

Found and fixed during wiring (FIX-9, see PATCHES-style note below —
kept inline here since it's this Epic's direct blocker, not a standalone
older-code audit finding):
- ReconnectService::handleReconnect() restored player state and sent
  reconnect_state, but left the `$room['players']` array entry keyed
  under the OLD (disconnected) connection id. A new WS connection created
  by the client on reconnect gets a brand-new Workerman connection->id —
  every downstream handler (draw_barrel, leave_room, apartment_choice, ...)
  looks the player up by the CURRENT connection's id, so none of them
  could find the reconnected player. Reconnect looked successful
  (reconnect_state packet received, status flipped to 'active') but was
  functionally dead for anything after it.
- Root cause of why this was never caught: tests/Manual/test_reconnect.php
  (EPIC-8.6) only unit-tests handleReconnect() in isolation with
  MockConnection and asserts state at the OLD key — it never simulates a
  subsequent action arriving from the NEW connection through real routing,
  because until this Epic there was no real routing to go through.
- Fix: handleReconnect() now re-keys the players array entry from the old
  conn_id to the new one, and updates every other room-level field that
  can point at a conn_id: `host_conn_id`, `active_drawer_conn_id`, and
  every matching entry in `drawer_order`. Timer, connection object, and
  session_token handling unchanged.
- Verified non-false-positive: reverted the fix locally, re-ran
  tests/Manual/test_game_packet_routing.php TEST 8 — draw_barrel after
  reconnect failed with error.room_not_found as predicted (new conn_id not
  found in `$room['players']`); restored the fix — 21/21 PASSED again.

No ADR required — no protocol packet, error code, or ANCHOR document
changed. Room/Player structure keys are unchanged (Rule 7 No Hidden
Features) — FIX-9 only changes which array key an existing structure is
stored under, at the moment of reconnect.

Also fixed in this Epic (stale pre-existing test assertion, not a FIX-N —
Rule 22 Test Philosophy: fix the test, not the implementation, since the
implementation was already correct): tests/Manual/test_auth_packet_routing.php
TEST 2 still asserted `error.invalid_json` for create_room after register,
a leftover from before EPIC-10.4 wired lobby routing — despite this
project's own IMPLEMENTATION_STATUS.md EPIC-10.4 entry already claiming
this assertion was updated. It had not been, in the actual committed file.
Corrected to assert `room_joined`.

Housekeeping (found during this Epic's audit, unrelated to game routing
itself): the repository had two case-variant test directories,
`tests/Manual/` and `tests/manual/`, byte-identical except that
`tests/manual/test_lobby_packet_routing.php` (EPIC-10.4) existed only in
the lowercase copy — almost certainly a case-insensitive-filesystem
artifact from a local dev machine, invisible on that machine but tracked
as two separate directories in git. Consequence: `run_ALL_tests.sh` (globs
`tests/Manual/test_*.php` only) was silently never running
test_lobby_packet_routing.php at all. Fixed: file moved into
`tests/Manual/` (confirmed identical before the move, `php -l` clean,
re-run 23/23 PASSED post-move), the stray `tests/manual/` directory
removed entirely.

Result:
- tests/Manual/test_game_packet_routing.php (new): 21/21 PASSED — full
  flow verified end-to-end through a real WS client against a live
  server.php subprocess: non-host start_game guard, game_started
  broadcast (bank/drawer_order), turn-order draw_barrel guard,
  barrels_drawn + your_turn rotation, apartment_choice with no apartment
  active, apartment_choice missing `choice` field, unauth draw_barrel,
  and — critically — a real TCP disconnect mid-game followed by
  reconnect on a brand-new connection, then a successful draw_barrel from
  that new connection (this last step is the FIX-9 regression check).
- tests/Manual/test_reconnect.php: 20/20 PASSED (was 15 — +5 new FIX-9
  assertions in GROUP 3).
- tests/Manual/test_auth_packet_routing.php: 18/18 PASSED (TEST 2 fixed).
- tests/Manual/test_lobby_packet_routing.php: 23/23 PASSED (moved,
  unchanged otherwise).
- Full regression across every tests/Manual/*.php file — 0 failed.

⚠️ KNOWN GAP (found during this Epic, not fixed — narrow edge case, out
of scope per Rule 11 Epic Isolation): if a client sends `{"action":
"reconnect", "token": ...}` with a token AuthHandler considers valid, but
ReconnectService::handleReconnect() finds no matching disconnected player
in any room (i.e. the user was never in a room-level session, or it was
already cleaned up), `$connection->userId` is never set — AuthHandler::
handleReconnect() itself never sets it, only ReconnectService does, only
on a match. Symmetric in spirit to FIX-8 (EPIC-10.3) but a distinct fix,
deliberately left for a follow-up rather than folded into this Epic.

Diff: patches/EPIC-10.5-game-routing.patch

## EPIC-10.4 — Lobby packet routing
Status: Completed
Date: 2026-07-23

Files:
- src/Lobby/LobbyHandler.php (новый файл — thin wrapper над LobbyService)
- server.php (diff — RoomManager/LobbyService/LobbyHandler dependency wiring
  in onWorkerStart; room_list/create_room/join_room/leave_room wired in
  onMessage dispatch; «Already in a room» guard for create_room/join_room)
- tests/Manual/test_lobby_packet_routing.php (новый файл — 22 assertions,
  real WS client against live server.php)
- tests/Manual/test_auth_packet_routing.php (diff — TEST 2 updated: после
  register create_room теперь возвращает room_joined, не error.invalid_json)

LobbyService already existed (EPIC-2.x) and required no new business
logic — EPIC-10.4 itself is pure dependency wiring + routing + one router-
level guard, matching every other EPIC-10.x so far.

«Already in a room» guard: LobbyService::handleCreateRoom() документирует,
что пользователь не должен уже находиться в другой комнате — проверка
делегирована router'у (server.php), один раз для create_room и join_room,
через RoomManager::findRoomIdByConnId(). Код ошибки: error.invalid_json
(отдельного кода в ANCHOR_PROTOCOL.md нет).

No ADR required — no protocol packet, error code, or ANCHOR document changed.

Result:
- tests/Manual/test_lobby_packet_routing.php (new): 22/22 PASSED —
  create_room/room_list/join_room/leave_room verified end-to-end through
  a real WS client against a live server.php subprocess (real game.db,
  `e104_` username prefix, cleaned up before/after). Includes router-level
  «Already in a room» guard checks (TEST 4, TEST 5).
- tests/Manual/test_auth_packet_routing.php: TEST 2 updated for EPIC-10.4
  (create_room after register → room_joined).
- tests/Manual/test_lobby_integration.php: 91/91 PASSED (unchanged).
- Full regression across all tests/Manual/*.php files — 0 failed.

Diff: patches/EPIC-10.4-lobby-routing.patch

## EPIC-10.3 — Auth packet routing (+ FIX-8, found during wiring)
Status: Completed
Date: 2026-07-22

Files:
- server.php (diff — AuthHandler dependency wiring in onWorkerStart;
  register/login/reconnect wired to AuthHandler in onMessage dispatch)
- src/Auth/AuthHandler.php (diff — FIX-8: new bindConnection() private
  helper, called from handleRegister()/handleLogin())
- tests/Manual/test_auth_integration.php (diff — 7 new FIX-8 assertions
  via MockConnection)
- tests/Manual/test_auth_packet_routing.php (новый файл — 18 assertions,
  real WS client against live server.php)

AuthHandler already existed (EPIC-1.3) and required no new business
logic — EPIC-10.3 itself is pure dependency wiring + routing, matching
every other EPIC-10.x so far.

FIX-8 found while wiring (not a pre-existing regression — the bug was
latent until this Epic connected AuthHandler to the newly-added
auth_required guard, ADR-006, in the same code path): `AuthService::
login()` only ever set `$worker->userConnections[$userId]` — it never
set `$connection->userId` itself. Confirmed by grep: the ONLY place in
the entire codebase that set `$connection->userId` was
`ReconnectService::attemptReconnect()`, for its own, unrelated scenario.
Without a fix, a client could register/login successfully, receive a
valid `auth_result`, and then have EVERY subsequent action rejected with
`error.auth_required` forever — the auth_required guard checks exactly
`$connection->userId === null`, which never became false.

Fix: new `AuthHandler::bindConnection(object $connection, array $user,
string $token): void` private helper, mirroring the exact field set
`ReconnectService` already uses for its own scenario (`$connection->
userId`, `->username`, `->sessionToken`) plus `->isAdmin` (available in
AuthHandler's login result, unlike in ReconnectService's context). Called
from both `handleRegister()` (after its internal auto-login) and
`handleLogin()`, right before `sendAuthResult()`.

No ADR required — this is a code-correctness fix within the existing,
already-documented `ANCHOR_CORE.md` § Connection Runtime Fields registry
(all four fields were already declared there); no protocol packet, error
code, or ANCHOR document changed.

Result:
- tests/Manual/test_auth_integration.php: 55/55 PASSED (was 48; +7 —
  FIX-8 assertions verifying `$connection->userId/username/isAdmin/
  sessionToken` are correctly bound after both handleRegister() and
  handleLogin() via MockConnection).
- tests/Manual/test_auth_packet_routing.php (new): 18/18 PASSED —
  register/login/reconnect verified end-to-end through a real WS client
  against a live server.php subprocess (real game.db, `e103_` username
  prefix, cleaned up before/after). Critically includes two FIX-8
  end-to-end checks (TEST 2, TEST 6): after a real register/login over
  the real protocol, a subsequent non-exempt action no longer receives
  `error.auth_required` — confirming the fix works through the actual
  router, not only in the MockConnection unit test.
- Full regression across all tests/Manual/*.php files (28 files,
  including the new one) — 0 failed ([FAIL] marker searched explicitly).

Diff: patches/EPIC-10.3-auth-routing.patch

## EPIC-10.2 continuation — Generic auth_required guard
Status: Completed
Date: 2026-07-22

Files:
- server.php (diff — auth_required guard in onMessage, before dispatch)
- docs/ANCHOR_PROTOCOL.md (diff — error.auth_required semantics documented)
- docs/ADR/006.md (новый файл)
- tests/Manual/test_server_bootstrap.php (diff — TEST 4 tightened to
  assert the specific code; new TEST 8 for the exempt-actions set)

Closes the second, previously-deferred half of EPIC-10.2 (first half —
connection-level MAX_TOTAL_PLAYERS gate — completed separately, ADR-005).
EPIC-10.2 is now fully complete.

Implements prompt.md Фаза 1: "проверка userId для всех кейсов кроме
register, login, reconnect" — checked once, generically, by the router
in onMessage, before the (still empty) action dispatcher. Exempt set is
exactly {register, login, reconnect}; `ping` isn't listed because it
already short-circuits earlier in onMessage and never reaches this
check.

Side effect verified explicitly (not a defect, documented in ADR-006):
the dispatcher's `default => error.invalid_json` fallback is now
unreachable for an unauthenticated connection sending any non-exempt
action — the guard intercepts first with error.auth_required. Remains
reachable only for the exempt actions themselves (not yet wired to real
handlers until EPIC-10.3).

Result:
- tests/Manual/test_server_bootstrap.php: 18/18 PASSED (was 14; +4 — TEST
  4 tightened to assert code=error.auth_required specifically instead of
  just type=error; new TEST 8 confirms register/login/reconnect are NOT
  blocked by the guard, falling through to the empty dispatcher's
  not-yet-wired response instead).
- Full regression across all tests/Manual/*.php files (25 files) — 0
  failed ([FAIL] marker searched explicitly, not just "failed" text
  appearing in unrelated log messages).

Diff: patches/EPIC-10.2-auth-guard.patch

## EPIC-10.2 — Protocol error handling (partial: connection-level capacity gate)
Status: Partially completed (by user decision — scope explicitly narrowed)
Date: 2026-07-22

Files:
- src/Core/Helpers.php (diff — new closeWithCode() helper)
- server.php (diff — global connection-level MAX_TOTAL_PLAYERS gate in
  onWebSocketConnected, before hello)
- docs/ANCHOR_PROTOCOL.md (diff — new § WebSocket Close Codes, code 4001)
- docs/ADR/005.md (новый файл)
- tests/Manual/test_server_bootstrap.php (diff — TEST 7: 150 реальных
  TCP+WS соединений + 151-е отклонённое, проверка close code 4001)

Scope decision: user chose to implement ONLY the connection-level
`error.server_full` + WS close 4001 gate (prompt.md Фаза 1, previously
undocumented in any ANCHOR file) in this round. The generic
`auth_required` router guard (also prompt.md Фаза 1, for actions outside
{register, login, reconnect, ping}) was explicitly deferred — not
implemented, tracked as open for a future round.

Problem: `docs/prompt.md` line 41 specified "при превышении 150 —
закрыть соединение с кодом 4001 и error.server_full", never formalized
in ANCHOR_PROTOCOL.md and never implemented. Distinct from the
room-join-time capacity check in LobbyService (FIX-7/ADR-004) — this one
runs at the connection layer, before authentication, against ALL live
sockets (`count($worker->connections)`), not just players seated in
rooms.

Technical finding: the installed Workerman version has no built-in API
to close a WebSocket connection with an explicit close-frame status
code — `closeWithCode()` builds the RFC 6455 §5.5.1 close frame by hand
(opcode 0x8, 2-byte big-endian status code + reason) and sends it via
`$connection->close($frame, true)`.

Fix:
- `closeWithCode()` helper added to Core/Helpers.php (general-purpose,
  reusable for any future application-specific close code).
- Gate added at the top of `onWebSocketConnected`: if
  `count($worker->connections) > Constants::MAX_TOTAL_PLAYERS`, sends
  `error.server_full` (JSON, normal protocol-encoded) then closes with
  WS code 4001 — before any connection-field init, before `hello`.
- Comparison uses `>` (not `>=`, unlike LobbyService's checks) because
  Workerman registers the connection into `$worker->connections` at
  TCP-accept time, before this callback runs — so the count already
  includes the connection being evaluated. Effective capacity is
  identical either way: exactly MAX_TOTAL_PLAYERS concurrent connections
  allowed, the (N+1)-th rejected. Documented explicitly in ADR-005 to
  avoid the kind of silent inconsistency FIX-7 had to fix.
- New WS close-code registry section added to ANCHOR_PROTOCOL.md so
  future application-specific codes have a documented home.

Result:
- tests/Manual/test_server_bootstrap.php: 14/14 PASSED (was 8; +6 new
  checks in TEST 7 — opened exactly 150 real TCP+WS connections against
  a live server.php subprocess, verified the 151st receives
  error.server_full as a text frame followed by a close frame carrying
  status code 4001, decoded from the raw close-frame payload).
- Full regression across all tests/Manual/*.php files (25 files) — 0
  failed.

Diff: patches/EPIC-10.2-partial.patch

### FIX-7 — `error.server_full` reused for room-full condition + wrong check order
Status: Completed
Date: 2026-07-22

Files:
- src/Lobby/LobbyService.php (diff — reorder checks in handleJoinRoom(),
  new error.room_full code)
- docs/ANCHOR_PROTOCOL.md (diff — error.room_full added to registry,
  note distinguishing it from error.server_full and documenting
  join-order precedence)
- docs/ADR/004.md (новый файл)
- tests/Manual/test_lobby_integration.php (diff — обновлена ассерция под
  новый код, добавлен regression-тест на порядок проверок)

Found during: user-reported review (not an audit round) — user flagged
that a full room and a full server must not share an error code, and
that server capacity must be checked before room capacity.

Problem:
- LobbyService::handleJoinRoom() reused `error.server_full` for two
  distinct conditions: the genuine global MAX_TOTAL_PLAYERS limit, and a
  single room reaching its own max_players. ANCHOR_PROTOCOL.md had no
  dedicated code for the room-full case.
- Check order was room-capacity-first, server-capacity-second — so if
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
- Formalized as ADR-004 (protocol addition, no rename/removal — permitted
  under ANCHOR_PROTOCOL.md's Compatibility Rule without a version bump).

Result:
- tests/Manual/test_lobby_integration.php: 91/91 PASSED (was 90; +1 new
  regression test verifying error.server_full wins when both room and
  server are full simultaneously — verified by manually seeding both
  conditions via direct room-state manipulation and RoomManager::
  getTotalPlayerCount()).
- Full regression across all tests/Manual/*.php files — 0 failed.

Diff: patches/FIX-7.patch

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
- 2026-07-03 — EPIC-10.0 Protocol router завершён: server.php (Workerman bootstrap, onWorkerStart/onWebSocketConnected/onMessage/onClose) без auth/lobby/game/admin-логики (Rule 11 Epic Isolation — ReconnectService требует LobbyService+GameService одновременно, подключение onClose к реальной бизнес-логике отложено до EPIC-10.4/10.5). Верифицирован полностью автоматически через реальный WebSocket-клиент (без внешних библиотек) поверх настоящего TCP-сокета — 8/8 PASSED. Rate limiting и invalid-JSON policy подтверждены как открытые вопросы EPIC-10.1 (не реализованы намеренно).
- 2026-07-23 — EPIC-10.5 Accepted + FIX-9: start_game/draw_barrel/
  apartment_choice подключены к новому GameHandler (GameService Phase 4-7
  уже существовал — dependency wiring + routing). ReconnectService также
  подключён (onClose -> handleDisconnect(), 'reconnect' action ->
  handleReconnect() поверх AuthHandler) — оба его зависимых сервиса,
  LobbyService (EPIC-10.4) и GameService (этот Epic), наконец собраны
  одновременно. Найден и исправлен FIX-9 в процессе: handleReconnect() не
  переиндексировал $room['players'] на новый conn_id нового WS-соединения
  — reconnect_state отправлялся, но любое дальнейшее действие с нового
  соединения не находило игрока (room_not_found). Исправлено: re-key
  players + host_conn_id/active_drawer_conn_id/drawer_order. Попутно
  исправлена стухшая assertion в test_auth_packet_routing.php TEST 2
  (ожидала error.invalid_json там, где EPIC-10.4 уже давно возвращает
  room_joined — расхождение между этим файлом и фактически закоммиченным
  тестом). Housekeeping: удалён паразитный `tests/manual/` (нижний
  регистр) каталог-дубликат — `test_lobby_packet_routing.php` существовал
  только в нём и никогда не запускался run_ALL_tests.sh; перенесён в
  `tests/Manual/`. Новый test_game_packet_routing.php 21/21 (реальный WS
  против живого server.php, включая сквозную проверку FIX-9: disconnect →
  reconnect с нового соединения → успешный draw_barrel). test_reconnect.php
  20/20 (было 15, +5 assertions под FIX-9). Полный регресс 0 failed.
- 2026-07-23 — EPIC-10.4 Accepted: room_list/create_room/join_room/
  leave_room подключены к LobbyHandler (LobbyService EPIC-2.x уже
  существовал — dependency wiring + routing). Новый LobbyHandler.php
  (thin wrapper). Router-level guard «Already in a room» для
  create_room/join_room через RoomManager::findRoomIdByConnId().
  Новый test_lobby_packet_routing.php 22/22 (реальный WS против живого
  server.php). test_auth_packet_routing.php TEST 2 обновлён под
  room_joined. Полный регресс 0 failed.
- 2026-07-22 — EPIC-10.3 Accepted + FIX-8: register/login/reconnect
  подключены к AuthHandler (dependency wiring в onWorkerStart, routing в
  onMessage). Найден и исправлен FIX-8 в процессе: AuthService::login()
  никогда не устанавливал $connection->userId (только $worker->
  userConnections) — без фикса auth_required guard (ADR-006) навсегда
  блокировал бы любое действие после успешного логина. Новый
  AuthHandler::bindConnection() helper, вызывается из handleRegister()/
  handleLogin(). 55/55 test_auth_integration.php (было 48, +7), новый
  test_auth_packet_routing.php 18/18 (реальный WS против живого
  server.php, включая сквозную проверку FIX-8 через настоящий router).
  Полный регресс 0 failed.
- 2026-07-22 — EPIC-10.2 continuation: generic auth_required guard в
  onMessage (ADR-006) — prompt.md "проверка userId для всех кейсов кроме
  register, login, reconnect", реализовано один раз в router'е, не
  дублируется по хендлерам. EPIC-10.2 теперь полностью завершён.
  18/18 test_server_bootstrap.php (было 14, +4 — TEST 4 ужесточён,
  новый TEST 8 на exempt-список), полный регресс 0 failed.
- 2026-07-22 — EPIC-10.2 (частично, по решению пользователя): реализован
  только connection-level MAX_TOTAL_PLAYERS gate — error.server_full + WS
  close code 4001 в onWebSocketConnected (ADR-005, closeWithCode() helper,
  ручная сборка close-фрейма — готового API в используемой версии Workerman
  нет). Generic auth_required guard в router'е сознательно отложен.
  14/14 test_server_bootstrap.php (было 8, +6 — TEST 7 через 150 реальных
  TCP+WS соединений), полный регресс 0 failed.
- 2026-07-22 — FIX-7 Accepted: устранено смешение error.server_full (глобальный
  лимит) и заполненности отдельной комнаты — введён отдельный код
  error.room_full (ADR-004), порядок проверок в handleJoinRoom() изменён на
  server-capacity-first. 91/91 lobby тестов (было 90, +1 regression-тест на
  порядок), полный регресс по всем tests/Manual/*.php — 0 failed.
- 2026-07-21 — EPIC-10.1 Packet validation завершён: ADR-003 формализует rate limiting (>15 пакетов/сек/соединение → закрытие без error-пакета, считает ВСЕ входящие сообщения) и invalid-JSON policy (error.invalid_json, без разрыва — решено в пользу ANCHOR_PROTOCOL.md, подкреплено прецедентом error.server_full). ANCHOR_CORE.md/ANCHOR_PROTOCOL.md обновлены (Connection Runtime Fields, Global Constants, семантика error.invalid_json). Оба KNOWN GAP из аудита протокола (2026-07-03) закрыты как RESOLVED. Попутно обнаружены и исправлены случайно закоммиченные рантайм-артефакты (game.db-shm/game.db-wal/workerman.*.pid) — добавлен .gitignore. Верифицировано 11/11 PASSED через реальный WebSocket-клиент, 5 граничных сценариев (ровно на лимите, превышение на 1, ping считается наравне, сброс окна, единичный невалидный пакет). Полный регресс — 25/25 tests/Manual/*.php.
---

## KNOWN GAPS / NOT VERIFIED

- ✅ RESOLVED (2026-07-03): docs/ANCHOR_PROJECT_STATUS.md удалён — файл не
  обновлялся с самого начала проекта (заморожен на состоянии "EPIC-1.1,
  Lobby/WebSocket/Economy: Not implemented"), при этом сам файл предписывал
  будущим моделям читать его как обязательный контекст. Риск катастрофической
  путаницы для новой сессии. ANCHOR_RULES.md Part 19 (Context Recovery Rule)
  уже корректно определяет 5 авторитетных документов без него.
- ✅ RESOLVED (ADR-003, EPIC-10.1, 2026-07-21): docs/prompt.md содержал два
  требования, отсутствующие во всех ANCHOR-документах — (a) rate limiting
  ">15 пакетов/сек — разрыв" и (b) противоречие по обработке невалидного
  JSON (prompt.md "закрыть соединение" vs ANCHOR_PROTOCOL.md error.invalid_json).
  Формализовано в docs/ADR/003-rate-limiting-and-invalid-json-policy.md:
  rate limiting реализован как есть (server.php, Constants::
  RATE_LIMIT_PACKETS_PER_WINDOW/RATE_LIMIT_WINDOW_SECONDS); invalid-JSON
  policy решена в пользу ANCHOR_PROTOCOL.md (error-пакет, без разрыва) —
  подкреплено уже реализованным прецедентом error.server_full. Детали —
  см. запись [DONE] EPIC-10.1 в начале файла.
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
55 / 55 PASSED (auth)                    [+7 vs заявленных 48 — FIX-8 regression-тесты]
91 / 91 PASSED (lobby)                   [+1 vs заявленных 90 — FIX-7 regression-тест]
164 / 164 PASSED (lotto engine)
44 / 44 PASSED (game start)
37 / 37 PASSED (turn system)
40 / 40 PASSED (victory system)          [+2 vs заявленных 38 — усилены проверки FIX-4]
32 / 32 PASSED (apartment)
8 / 8 PASSED (admin auth)
9 / 9 PASSED (admin ban)
8 / 8 PASSED (admin unban)
37 / 37 PASSED (admin kick)
28 / 28 PASSED (admin close room)
16 / 16 PASSED (admin logs)
20 / 20 PASSED (admin integration)
5 / 5 PASSED (timer integrity)
18 / 18 PASSED (server bootstrap — real WS client, EPIC-10.0/10.2) [+10 vs заявленных 8 — TEST 7 (connection gate), TEST 8 (auth_required exemptions), TEST 4 ужесточён]
11 / 11 PASSED (packet validation — real WS client, EPIC-10.1)
18 / 18 PASSED (auth packet routing — real WS client, EPIC-10.3, TEST 2 обновлён в EPIC-10.5)
23 / 23 PASSED (lobby packet routing — real WS client, EPIC-10.4, перенесён из паразитного tests/manual/ в EPIC-10.5)
20 / 20 PASSED (reconnect — было 15, +5 assertions FIX-9, EPIC-10.5)
21 / 21 PASSED (game packet routing — real WS client, EPIC-10.5, новый файл)
`

Current branch:

`text
main
`

Current stable commit:

`text
EPIC-10.5-game-routing (GameHandler wiring + ReconnectService wiring +
FIX-9 conn_id re-key on reconnect; full regression 0 failed)
`

Next planned Epic:

`text
EPIC-10.6 Admin packet integration
`
PHASE 10 — WEBSOCKET PROTOCOL: IN PROGRESS (10.0, 10.1, 10.2, 10.3, 10.4,
10.5 done).
Known open items (not defects blocking progress, see EPIC-10.5 KNOWN GAP
above): AuthHandler::handleReconnect() doesn't bind $connection->userId
when the token is valid but no matching disconnected room player is
found — narrow edge case outside ANCHOR_CORE.md § Reconnect Rules.