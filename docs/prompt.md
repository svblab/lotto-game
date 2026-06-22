ФИНАЛЬНЫЙ ПЛАН РАЗРАБОТКИ
(ПОД ПРОМПТ v4.0)
Фаза 0 — Подготовка окружения

Архитектура:

    Подготовка серверного окружения (VPS: 1 CPU, 500 МБ ОЗУ, Ubuntu 22.04).

    Установка PHP 8.x, Composer, SQLite3, настройка firewall (порт 8080).
    Документация (README.md):

    Пошаговая инструкция по установке Composer и развёртыванию зависимостей.

    Конфигурационный файл systemd для демонизации server.php с автоперезапуском при сбоях.

    Инструкция по генерации SSL-сертификатов Let's Encrypt и переводу WebSocket в режим wss://.

Фаза 1 — Скелет сервера и авторизация
База данных (init_db.php):

    Создание game.db, включение PRAGMA journal_mode=WAL; и PRAGMA foreign_keys=ON;.

    Создание таблицы users (CREATE TABLE IF NOT EXISTS): поля id, username (с индексом), password_hash, coins (500), is_admin, banned_until, last_daily_bonus.

    Автосоздание администратора admin со случайным паролем через bin2hex(random_bytes(12)), вывод в консоль единожды, хранение через PASSWORD_BCRYPT. Повторный запуск пароль не меняет.

Серверное ядро (server.php):

    Инициализация Workerman, WebSocket на порту 8080, count = 1.

    onWorkerStart: открытие PDO, инициализация worker−>rooms=[],worker−>rooms=[],worker->userConnections = [].

    Глобальный сторожевой таймер (замыкание с use ($worker), интервал 60 сек): закрытие авторизованных соединений без пинга > 120 сек и неавторизованных > 60 сек.

    onConnect: инициализация свойств соединения — userId = null, username = null, isAdmin = false, sessionToken = null, lastPing = time(). Немедленная отправка пакета hello с версией протокола.

    onMessage: безопасный парсинг JSON (невалидный — закрыть соединение), switch/case по action, проверка userId для всех кейсов кроме register, login, reconnect.

    Rate Limiting: более 15 пакетов/сек от одного соединения — немедленный разрыв.

    Глобальный лимит игроков: при превышении 150 — закрыть соединение с кодом 4001 и error.server_full.

Обработчики:

    register: проверка уникальности username, хеширование PASSWORD_BCRYPT.

    login: password_verify(), проверка бана (banned_until > time() → error.banned с until), защита от двойного входа (кик старого соединения), ежедневный бонус (+100 монет, интервал ≥ 86400 сек, только не-админам), генерация sessionToken = bin2hex(random_bytes(16)).
    Протокол: пакеты hello, auth_result, error.
    Требования к коду: все запросы через PDO с try-catch, комментарии на русском языке.

Фаза 2 — Комнаты и лобби
Математика (LottoEngine.php):

    generateCard(): матрица 3×9, ровно 15 чисел (по 5 в строке), диапазоны колонок по десяткам, строгое возрастание в колонках сверху вниз, уникальность всех чисел.

    generateBag(): массив 1–90, перемешивание алгоритмом Фишера–Йетса через random_int().

Структуры данных в RAM:

Структура комнаты worker−>rooms[worker−>rooms[room_id]:

host_conn_id, bet_per_card (=10), max_players, password_hash,
status ('waiting'|'playing'|'apartment'|'finished'),
bank, apartment_fired, pause_for_apartment, apartment_responses,
game_afk_timer_id, apartment_timer_id, lobby_afk_timer_id,
active_drawer_conn_id, drawer_order,
bag, drawn_numbers,
players, all_players_history
Структура players[conn_id]:

user_id, username,
cards (1 или 2 матрицы 3×9),
cards_count (1|2),
total_paid,
last_action,
afk_start (int|null),
strikes (0..3),
auto_draws (0..3),
status ('active'|'disconnected'),
session_token,
reconnect_timer (id|null),
connection
Карточки и ставки:

    При create_room / join_room игрок выбирает cards_count (1 или 2). Стоимость: bet_per_card = 10 монет за карту. Изменение внутри лобби запрещено.

    При добавлении в players: players[players[conn_id]['session_token'] = $connection->sessionToken.

    Лимит комнат: максимум 30 (MAX_ROOMS = 30), при превышении — error.room_limit.
    Логика лобби ('waiting'):

    Автостарт при заполнении до max_players.

    Ручной старт хостом при ≥ 2 игроках.

    AFK-таймер хоста: при ≥ 2 игроках запускается на 120 сек. Бездействие → передача прав следующему игроку.

    Пустая комната (0 игроков) уничтожается мгновенно с очисткой всех таймеров.
    Обработчики:

    ping: в комнате — обновить last_action; в лобби — обновить $connection->lastPing. Ответ не отправлять.

    room_list, create_room, join_room, leave_room → removePlayer(..., 'leave').
    Протокол: пакеты room_list, room_joined, player_joined, player_left.

Якорный документ перед фазой 3. Для моделей с ограниченным контекстом: вставить туда актуальный server.php + LottoEngine.php + финальный промпт Фазы 3.

Проект: multiplayer браузерное «Русское Лото»
Стек: PHP 8.x, Workerman WebSocket (порт 8080), SQLite3 (PDO), Vanilla JS
Деплой: VPS /opt/lotto-game, systemd-сервис lotto-server
Фазы 0-2 закрыты и задеплоены.

Константы: MAX_ROOMS=30, MAX_TOTAL_PLAYERS=150, bet_per_card=10
Язык комментариев: русский. Язык логов (serverLog): английский.

Соглашения по коду:

    все DB-запросы через PDO + try/catch

    таймеры через Workerman\Timer

    sendJson() / sendError() / broadcastToRoom() — готовые хелперы в server.php

    removePlayer() из Фазы 2 переименовать в removePlayerFromLobby()

Открытые решения зафиксированные в Фазе 3:

    drawer_order: хост первый, далее FIFO

    AFK-таймер: один Timer::add(1, repeat), проверяем пороги 15/25/30 сек

    barrels_drawn: числа + остаток мешка + next_drawer + is_final

    game_started: своя карточка полностью, чужие — username + пустая маска

    три отдельные функции: removePlayerFromLobby / removePlayerFromGame / removePlayerFromApartment

Финальный промпт Фазы 3 (зафиксированный):
Фаза 3 — Игровой цикл и механика ходов

Запуск игры (startGame()):

    Короткая транзакция SQLite: проверка coins >= total_paid у всех, списание монет, наполнение bank. При неудаче — rollBack(), ошибка хосту.

    Генерация карточек (по cards_count) и мешка. Рассылка game_started.

    game_started содержит: своя карточка полностью (числа + пустая маска), чужие игроки — только username + пустая маска без чисел.

    Формирование drawer_order (первый — хост, далее FIFO). Установка active_drawer_conn_id = drawer_order[0]. Отправка your_turn. Запуск AFK-таймера.

Основной тик (draw_barrel):

    Проверить что отправитель — active_drawer_conn_id. Иначе error.not_your_turn.

    Сбросить счётчики: strikes = 0, afk_start = null, last_action = time(), auto_draws = 0.

    Если status == 'apartment' — пропустить шаги 3–5, AFK-страховка выполняется всегда.

    Извлечь 3 числа из мешка, обрабатывать строго по одному. После каждого числа: обновить маски карточек всех игроков, проверить победу, затем «Квартиру». При срабатывании — остаток чисел хода аннулируется.

    Проверка победы: закрыты все 15 ячеек хотя бы одной карточки → победа. Приоритет абсолютный. Формула двойной победы (обе карты закрылись на одном ходу): floor(bank/(bank/(N + $M)), победитель с двойной победой получает 2 доли, остаток сгорает. Установить is_final: true. Зачислить выигрыш в БД. Разослать barrels_drawn, затем game_over. Уничтожить комнату.

    Если игра продолжается — передать очередь следующему живому игроку в drawer_order. Разослать barrels_drawn с next_drawer. Запустить AFK-таймер нового активного игрока.
    barrels_drawn содержит: вытянутые числа, остаток мешка, next_drawer, is_final.
    game_over содержит: победитель, выигрыш, полная статистика (кто сколько получил, итоговый банк).

AFK-страховка (один таймер 1 сек, проверяем time() - afk_start):

    15 сек → strikes = 1, предупреждение клиенту.

    10 сек → strikes = 2, предупреждение клиенту.

    5 сек → автоход, auto_draws++, strikes = 0, очередь дальше.

    auto_draws == 3 → removePlayerFromGame(..., 'afk').

    ping обновляет last_action, на AFK-страховку не влияет.

Три отдельные функции удаления:

    removePlayerFromLobby() — существующая removePlayer() из Фазы 2, переименовать.

    removePlayerFromGame() — новая для status = 'playing'.

    removePlayerFromApartment() — новая для status = 'apartment'.

Логика removePlayerFromGame() / removePlayerFromApartment():

    Перенести в выбывшие, total_paid остаётся в банке.

    kicked: вернуть total_paid игроку в БД, уменьшить bank.

    banned: деньги остаются в банке.

    afk, refuse, disconnect, leave: деньги остаются в банке.

    Если удалённый был active_drawer_conn_id — передать очередь следующему, отправить your_turn.

    Остался 1 активный → last_survivor, забирает банк.

    Осталось 0 активных → вернуть всем total_paid из all_players_history, уничтожить комнату.

Очистка при уничтожении комнаты:
php
if (!empty(
room['lobby_afk_timer_id']);
}

if (!empty(
room['game_afk_timer_id']);
}

if (!empty(
room['apartment_timer_id']);
}

foreach (room[′players′]asroom[′players′]asplayer) {
if (!empty(
player['reconnect_timer']);
}
}

unset(worker−>rooms[worker−>rooms[roomId]);
Протокол: game_started, your_turn, barrels_drawn, game_over.
Фаза 4 — «Квартира» и Reconnect

Механика «Квартиры» (Шаг 5 основного тика):

    Если apartment_fired == true — пропустить.

    Если у любого игрока впервые закрылась строка (5 ячеек true) в любой карточке:
    o apartment_fired = true. Ход прерывается. status = 'apartment', pause_for_apartment = true.
    o Игроки с закрытой строкой → immune = true. Остальные → immune = false, обязаны доплатить 5 монет.
    o Запустить apartment_timer_id на 10 сек. Разослать apartment_alert.

Обработка apartment_choice:

    'agree': проверить coins >= 5 в БД. Если нет — принудительный 'refuse'. Если да — списать 5 монет, добавить в bank и total_paid.

    'refuse' или таймаут → removePlayerFromApartment(..., 'refuse'). Ставка сгорает в банке.

    immune = true во время паузы: при дисконнекте — немедленный removePlayerFromApartment(..., 'disconnect') без Reconnect-окна.

    Досрочное снятие паузы: все обязанные ответили или удалены → отменить apartment_timer_id, status = 'playing', сбросить pause_for_apartment. Передать очередь следующему по drawer_order, отправить your_turn.

    При удалении обязанного игрока (immune = false) — проверить досрочное снятие паузы.

Механизм Reconnect:

    Работает только в фазах 'waiting' и 'playing'. В 'apartment' — немедленный removePlayer(..., 'disconnect').

    onClose (фазы 'waiting'/'playing'): статус игрока → 'disconnected', запустить таймер 15 сек. Истёк →
    removePlayerFromLobby(..., 'disconnect') для waiting
    removePlayerFromGame(..., 'disconnect') для playing

    reconnect (от клиента: { token }): перебор всех комнат и игроков, поиск p[′sessiontoken′]===p[′sessiont​oken′]===token && p[′status′]===′disconnected′.Отменитьтаймер,восстановитьсоединение,обновитьp[′status′]===′disconnected′.Отменитьтаймер,восстановитьсоединение,обновитьworker->userConnections.

    Отправить reconnect_state:
    o 'waiting' → my_cards: null, drawn_all: [], bank: 0. Клиент восстанавливает экран лобби.
    o 'playing' → актуальные матрицы с true, полная история drawn_all, текущий bank. Клиент восстанавливает игровой экран.
    o 'apartment' → пакет не генерируется (Reconnect запрещён).
    Протокол: пакеты apartment_alert, reconnect_state.

Фаза 5 — Модерация и логирование

Административные обработчики:

    admin_ban_user: расчёт banned_until (1д / 3д / 4102444800), запись в БД. Запрет банить администратора (error.cannot_moderate_admin). Если онлайн — отправить banned, вызвать:
    removePlayerFromLobby(..., 'banned') для waiting
    removePlayerFromGame(..., 'banned') для playing
    removePlayerFromApartment(..., 'banned') для apartment
    (деньги остаются в банке).

    admin_unban_user: banned_until = 0 в БД.

    admin_kick_user:
    removePlayerFromLobby(..., 'kicked') для waiting
    removePlayerFromGame(..., 'kicked') для playing
    removePlayerFromApartment(..., 'kicked') для apartment
    возврат total_paid игроку.

    admin_close_room: остановить игру, вернуть 100% средств всем из all_players_history, очистить таймеры, уничтожить комнату.
    Логирование (logs/server.log):

    Строго на английском. Формат: [YYYY-MM-DD HH:MM:SS] [LEVEL] message.

    События: запуск/стоп сервера, логины, старты игр, победы, баны, кики, ошибки транзакций.

    Авторотация: первая запись старше 30 дней → переименовать в server_YYYYMMDD.log, создать новый. При старте демона — удалять файлы старше 30 дней.

    admin_get_logs: фильтрация строк по timestamp за последние 24 часа.
    Протокол: пакеты banned, admin_stats_data, admin_logs_data.
    Документация (README.md): разделы по бэкапу game.db, ротации логов, ручному разбану через CLI SQLite3, сбросу пароля администратора.

Фаза 6 — Клиент: авторизация и лобби

Общие требования (app.js):

    Vanilla JS, SPA-архитектура. const WS_URL = "ws://localhost:8080";.

    После авторизации — фоновый пинг каждые 2.5 сек.

    При разрыве соединения — автоматическая отправка { action: "reconnect", token: ... }.

    Обработка banned на любом экране: очистка сессии, уведомление со сроком бана, редирект на #auth-screen.
    Интерфейс и адаптивность (index.html, style.css):

    Дизайн ретро-казино (дерево / зелёное сукно). Логотип-заглушка .logo-placeholder (высота 120px, макс. ширина 80%).

    Mobile-First, три брейкпоинта: смартфон (320–480px), планшет (768–1024px), десктоп (≥1280px).

    Сетка карточки лото всегда LTR для всех языков.
    Экраны:

    #auth-screen: формы входа/регистрации, кнопка «?» (правила), переключатель языка.

    #lobby-screen: баланс, таблица комнат, кнопки «Создать комнату», «Быстрый старт», кнопка «Управление сервером» для админов.

    Быстрый старт: поиск свободной открытой комнаты с bet_per_card = 10. Если нет — автосоздание на 10 мест с cards_count = 1.
    Локализация (i18n):

    6 языков: en (по умолчанию), ru, es, fr, zh, tr. Файлы locales/{lang}.json.

    Определение по navigator.language, сохранение в localStorage. Переключатель-глобус на #auth-screen и #lobby-screen.

    Серверные коды ошибок переводятся по ключам, резерв — поле message.

    Сетка карточки всегда LTR независимо от языка.
    Документация (README.md): таблица заглушек img/ с размерами для дизайнера.

Фаза 7 — Клиент: игровой экран и анимации

Игровой экран (#game-screen):

    Список игроков и их статусы (онлайн / дисконнект).

    Прогресс-бар «Шанс на победу» (% закрытых ячеек от 15).

    Карточки лото: CSS Grid или <table>, шрифт до 12px на смартфонах, горизонтальный скролл только при ≤280px.

    Две карточки: отображение стопкой с каскадным сдвигом (CSS Transform), переключение свайпом или стрелками.

    Кнопка «Начать игру» — только хосту при status == 'waiting' и ≥2 игроков.
    Кнопка «Тянуть бочонок»:

    Видна всем, активна только у игрока с активным ходом (по next_drawer или пакету your_turn).

    При your_turn — пульсация и подсветка кнопки.

    Блокируется сразу после нажатия, разблокируется только после получения barrels_drawn и полного завершения анимации.
    Очередь анимаций (animationQueue):

    Максимум 3 пакета в очереди. Порядок: barrels_drawn → apartment_alert → game_over.

    game_over обрабатывается только после завершения анимации последнего хода.

    Анимация бочонков: 3 окошка «слот-машины», размытый барабан, остановка слева направо с шагом 0.5 сек. Совпавшие числа — золотой импульс, закрытие фишкой.

    apartment_alert: модальное окно после завершения анимации бочонков. Таймер 10 сек. immune = false — кнопки «Согласен» / «Отказаться». immune = true — текст ожидания. Таймаут → автоотправка refuse.

    Если is_final: true в barrels_drawn — после завершения анимации немедленно извлечь game_over из очереди.

    #rules-modal: пожелтевший фон, правила (сгорание остатка банка, двойная победа, «Квартира» и доплата 10 монет).
    Обработка reconnect_state на клиенте:

    status == 'waiting' → восстановить экран лобби комнаты.

    status == 'playing' → восстановить игровой экран с актуальными карточками (my_cards), историей бочонков (drawn_all), текущим банком.

Фаза 8 — Админ-панель и финальная полировка

Интерфейс администратора (#admin-panel):

    Доступен только при is_admin == 1.

    Живая статистика: онлайн, использование памяти PHP, список активных комнат.

    Таблица пользователей: id, username, coins, is_admin, banned_until — без паролей. Кнопки «Кик», «Бан», «Разбан», выпадающий список сроков бана.

    Текстовое поле логов сервера за 24 часа.

Чек-лист финального тестирования:
Сценарий	Ожидаемый результат
10 одновременных подключений	Все получают пакеты без задержек
AFK → 3 автохода подряд	removePlayer('afk')
«Квартира» срабатывает ровно один раз	Второе закрытие строки паузу не вызывает
Победа на том же бочонке что и «Квартира»	Победа, «Квартира» игнорируется
Двойная победа (2 карты)	Формула N + M долей, корректный расчёт
Остаток банка при делении	Сгорает, не начисляется
Reconnect в 'playing' (до 15 сек)	Восстановление с актуальным состоянием
Reconnect в 'apartment'	Немедленный кик
last_survivor	Забирает весь банк
0 активных игроков	Возврат всех ставок, комната уничтожена
Rate limiting (flood)	Соединение разрывается
Бан онлайн-игрока	Кик без возврата денег, banned пакет
Кик онлайн-игрока	Возврат total_paid, kicked пакет
admin_close_room	100% возврат всем, комната уничтожена
Таймеры при уничтожении комнаты	Все три + reconnect_timer отменены
Зависший клиент в лобби > 120 сек	Принудительное закрытие соединения
Неавторизованное соединение > 60 сек	Принудительное закрытие соединения

Финальное ревью кода:

    Чистота кода, русскоязычные комментарии.

    PDO с try-catch и rollBack() везде где есть транзакции.

    Подготовленные выражения кешируются, не пересоздаются при каждом вызове.

    Проверка экономии памяти на VPS.

ARCHITECTURAL OVERRIDE

Если инструкция любой фазы противоречит ANCHOR_CORE.md,
приоритет имеет ANCHOR_CORE.md.

server.php допускается только как bootstrap-файл Workerman.
Бизнес-логика должна располагаться исключительно в:

src/Core
src/Auth
src/Lobby
src/Game
src/Admin
src/Infrastructure

согласно структуре ANCHOR_CORE.md.