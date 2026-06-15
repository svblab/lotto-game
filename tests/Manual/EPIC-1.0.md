# Мануальное тестирование: EPIC-1.0 AuthService — User Registration

## Сценарии верификации регистрационной логики

### Сценарий №1: Новый пользователь
* **Входные данные:** `username` = "test_user_1", `password` = "password123" (пользователя нет в БД).
* **Ожидаемый результат:** Возвращается массив `['success' => true, 'username' => 'test_user_1']`. В логах появляется запись уровня `INFO`: `User registered: test_user_1`.

### Сценарий №2: Повторная регистрация
* **Входные данные:** `username` = "test_user_1", `password` = "password123" (пользователь уже создан).
* **Ожидаемый результат:** Выбрасывается `Exception` с текстом `Username already exists`. В логах фиксируется запись уровня `WARNING`: `Registration failed: Username already exists`.

### Сценарий №3: Невалидный username (короткий)
* **Входные данные:** `username` = "ab", `password` = "password123".
* **Ожидаемый результат:** Выбрасывается `Exception` (ошибка валидации формата). В логах — запись `WARNING`: `Registration failed: Invalid username format`.

### Сценарий №4: Невалидный пароль (короткий)
* **Входные данные:** `username` = "test_user_2", `password` = "123".
* **Ожидаемый результат:** Выбрасывается `Exception` (ошибка длины пароля). В логах — запись `WARNING`: `Registration failed: Password must be between 6 and 64 characters`.

### Сценарий №5: Проверка баланса в БД
* **Действие:** Выполнить SQL-запрос `SELECT username, coins FROM users WHERE username = 'test_user_1';`.
* **Ожидаемый результат:** Стартовое количество монет у нового пользователя строго равно `500`.
