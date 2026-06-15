# Ручное тестирование: EPIC-0.9 Core Helpers

## Цель
Проверить независимую работу глобальных хелперов (`sendJson`, `sendError`, `broadcastToRoom`, `serverLog`) на корректность сериализации, фильтрацию состояний игроков и интеграцию с логгером.

---

## Сценарий 1: Функция sendJson()
### Шаги:
1. Создать мок-объект `$connection`, имеющий метод `send($data)`.
2. Вызвать `Lotto\Core\sendJson($connection, ['status' => 'ok', 'message' => 'Тест'])`.

### Ожидаемый результат:
* Метод `send()` мок-объекта получает валидную JSON-строку: `{"status":"ok","message":"Тест"}`.
* Кириллические символы не экранируются в `\uXXXX` благодаря флагу `JSON_UNESCAPED_UNICODE`.

---

## Сценарий 2: Функция sendError()
### Шаги:
1. Использовать тот же мок-объект `$connection`.
2. Вызвать `Lotto\Core\sendError($connection, 'Access Denied')`.

### Ожидаемый результат:
* Метод `send()` принимает структуру строго по протоколу:
  ```json
  {
    "type": "error",
    "message": "Access Denied"
  }
