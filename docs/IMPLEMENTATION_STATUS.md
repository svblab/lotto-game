# Implementation Status — Lotto Game Project

## PHASE 0 — FOUNDATION
- [DONE] EPIC-0.1 Project skeleton
  Files:
  - src/Admin/AdminService.php (заглушка, `final class AdminService {}`)
  - src/Game/GameService.php (заглушка)
  - src/Game/LottoEngine.php (заглушка)
  - src/Game/ApartmentService.php (заглушка)
  - src/Game/ReconnectService.php (заглушка)
  - src/Game/VictoryService.php (заглушка)
  - src/Lobby/LobbyService.php (заглушка)
  Notes: Пустые классы без бизнес-логики, namespace соответствует структуре каталогов (`Lotto\Admin`, `Lotto\Game`, `Lotto\Lobby`). Реализация — в соответствующих будущих Epic.

- [DONE] EPIC-0.2 Composer configuration
  Notes: composer.json не передавался в проверяемом архиве (src.zip содержал только папку src/) — PSR-4 маппинг `Lotto\\ => src/` не перепроверен в этой сессии, статус оставлен по предыдущему подтверждению.

- [DONE] EPIC-0.3 Database layer
  Files:
  - src/Infrastructure/Database.php
  Notes: PDO/SQLite, `PRAGMA journal_mode=WAL`, `PRAGMA foreign_keys=ON`, `ATTR_EMULATE_PREPARES=false`. Метод `getPdo()` помечен как критический контракт (запрещено переименовывать/удалять).

- [DONE] EPIC-0.4 Prepared statement registry
  Files:
  - src/Infrastructure/PreparedStatements.php
  Notes: Кэш PDOStatement по строковому ключу. Зарегистрированные запросы: `user_by_username, create_user, update_daily_bonus, update_user_coins, ban_user, unban_user`. `create_user` выставляет стартовый баланс `coins=500` согласно ANCHOR_CORE Part 2.

- [DONE] EPIC-0.5 Logger
  Files:
  - src/Core/Logger.php
  Notes: Формат строки соответствует ANCHOR_CORE (`[YYYY-MM-DD HH:MM:SS] [LEVEL] message`), уровни `INFO|WARNING|ERROR` валидируются. Поверх `write()` добавлены удобные методы `info()/warning()/error()` — не входят в Naming Registry явным списком; оставлены как есть, не запрещены, но не задокументированы отдельно.

- [DONE] EPIC-0.6 Constants
  Files:
  - src/Core/Constants.php
  Notes: 1:1 соответствие ANCHOR_CORE Part 1 (все константы присутствуют, значения совпадают).

- [DONE] EPIC-0.7 SessionService
  Files:
  - src/Auth/SessionService.php
  Notes: `generateToken()` (32 hex-символа), `isValidToken()`, `tokensEqual()` (через `hash_equals`). Известная проблема: в файле артефакты форматирования — переносы строк ломают докблоки и местами тело методов; на работу кода не влияет, но снижает читаемость. Кандидат на отдельный косметический Epic (без изменения логики, см. Rule 6).

- [DONE] EPIC-0.8 Infrastructure validation
  Notes: Ручная верификация (`php -l`, проверка соединения с БД) не выполнялась в текущей сессии — нет доступа к VPS. Статус сохранён по предыдущему подтверждению.

- [DONE] EPIC-0.9 Helpers
  Files:
  - src/Core/Helpers.php
  Notes: `sendJson(), sendError(), broadcastToRoom(), serverLog()`. См. FIX-1 ниже — `sendError()` был исправлен после ревизии этой сессии.

## PHASE 1 — AUTHENTICATION
- [DONE] EPIC-1.0 AuthService registration
- [DONE] EPIC-1.1 AuthService login
- [DONE] EPIC-1.2 Session validation flow
- [DONE] EPIC-1.3 Reconnect Token Infrastructure

Created/Updated Files:
- src/Auth/AuthService.php
- src/Auth/SessionService.php
- src/Auth/ReconnectTokenService.php
- tests/Manual/test_register.php (не входил в проверяемый архив src.zip — не переподтверждено)
- tests/Manual/test_login.php (не входил в проверяемый архив src.zip — не переподтверждено)
- tests/Manual/test_session_service.php (не входил в проверяемый архив src.zip — не переподтверждено)
- tests/Manual/test_reconnect_token_service.php (не входил в проверяемый архив src.zip — не переподтверждено)

Notes:
- Внедрен компонент SessionService через DI в конструктор класса AuthService.
- Поле `session_token` добавлено на верхний уровень возвращаемого ассоциативного массива метода `login()`, полностью сохранив при этом обратную совместимость с вложенной структурой `user` и флагом `daily_bonus_received`.
- Создан ReconnectTokenService для безопасной генерации и валидации 64-символьных HEX-токенов переподключения игроков.
- Компонент полностью изолирован: изменения в БД, AuthService или сетевом протоколе не производились, обеспечивая нулевую регрессию.

---

## PATCHES (вне нумерации Epic, по Rule 8/10 ANCHOR_RULES)

### FIX-1 — sendError() не содержал обязательное поле `code`
Status: Completed
Date: 2026-06-21
Files:
- src/Core/Helpers.php

Problem: `sendError()` отправлял пакет `{"type": "error", "message": "..."}`, без поля `code`. ANCHOR_PROTOCOL.md фиксирует контракт error-пакета как `{"type": "error", "code": "error_code", "message": "optional text"}` — отсутствие `code` нарушало Rule 26 ANCHOR_RULES (протокол — контракт, поле нельзя терять без версии протокола).

Fix: сигнатура изменена на `sendError(object $connection, string $code, string $message = ''): void`, поле `code` добавлено в JSON-полезную нагрузку.

CHANGED:
- src/Core/Helpers.php: `sendError()` — добавлен обязательный параметр `$code` (вторым аргументом), добавлено поле `code` в отправляемый JSON.

NOT CHANGED:
- `sendJson()`, `broadcastToRoom()`, `serverLog()` — без изменений.
- Остальные файлы проекта не затронуты.

Verification:
- `grep -rn "sendError(" src/` по всему проверяемому архиву показал, что функция нигде не вызывается — изменение сигнатуры безопасно, регрессий по существующим вызывающим местам нет (вызывающих мест не существует).

MANUAL VERIFICATION REQUIRED (на VPS, не выполнено в данной сессии — нет доступа к среде исполнения):
```
php -l src/Core/Helpers.php
```
Expected: `No syntax errors detected`
```
php -r "require 'src/Core/Helpers.php'; var_dump(function_exists('Lotto\Core\sendError'));"
```
Expected: `bool(true)`

---

## KNOWN GAPS / NOT VERIFIED IN THIS SESSION
- `composer.json` — не входил в проверяемый архив (`src.zip` содержал только `src/`), PSR-4 маппинг `Lotto\\ => src/` не переподтверждён.
- `tests/Manual/*.php` — не входили в проверяемый архив, существование и содержание 4 тестов из EPIC-1.3 не переподтверждено.
- История git/коммиты — нет доступа к VPS/репозиторию из текущей сессии; ранее заявленные коммиты независимо не проверялись.
- `src/Auth/SessionService.php` — форматирование файла повреждено (переносы строк внутри докблоков/тела методов); не блокирует работу, но рекомендуется косметический Epic.