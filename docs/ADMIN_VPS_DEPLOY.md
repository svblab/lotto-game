# Развёртывание на VPS — руководство администратора

Документ для системного администратора: установка, HTTPS, ежедневная эксплуатация и обновление **Русского лото** на одном VPS.

Аудитория: человек с SSH-доступом root/sudo. Не для разработки протокола и не для игровых правил.

Канонический путь на сервере: `/opt/lotto-game`. Сервис: `lotto-server.service`. Пользователь процесса: `www-data`.

Связанные документы (не обязательны для первого запуска):

- `README.md` — краткий обзор и примеры конфигов
- `docs/LOCAL_ENVIRONMENT.md` — тесты и переменные окружения
- `docs/ADR/027-reverse-proxy-tls-termination.md` — почему TLS снаружи PHP
- `docs/GAME_RULES.md` — правила игры для игроков

---

## 1. Как устроена production-схема

```
Браузер  --HTTPS:443-->  nginx (статика public/ + TLS)
                              |
                              +-- /ws --proxy-->  Workerman  127.0.0.1:8080
                                                     |
                                                     +-- PHP 8, один worker
                                                     +-- SQLite  game.db
```

- Клиент — статическая SPA в `public/` (HTML/JS/CSS). PHP-FPM **не нужен**.
- Игровой сервер — один процесс Workerman. Комнаты, ходы и чат живут **в оперативной памяти**. Перезапуск сервиса разрывает все партии. Учётки и монеты — в `game.db`.
- TLS **не** включается в PHP. Сертификат обслуживает nginx (или Caddy). Обновление сертификата **не** должно перезапускать `lotto-server`.
- Порт `8080` слушает Workerman на `0.0.0.0`. Снаружи его открывать нельзя: публично только `22`, `80`, `443`.

Минимум железа: 1 CPU, 512 МБ RAM, SSD. ОС: Ubuntu 22.04 или 24.04 LTS.

---

## 2. Чек-лист перед установкой

- [ ] VPS с публичным IPv4 и SSH
- [ ] Домен (A-запись) указывает на этот IP; DNS уже разошёлся
- [ ] Есть git-доступ к репозиторию `https://github.com/svblab/lotto-game`
- [ ] Запланировано окно: первый `systemctl restart` и каждое обновление **сбрасывают живые комнаты**

---

## 3. Первичная установка

Все команды ниже — с машины администратора по SSH. Подставьте свой домен вместо `your-domain.com`.

### 3.1. Пакеты

```bash
sudo apt update
sudo apt install -y git unzip curl sqlite3 \
  php-cli php-sqlite3 php-mbstring php-xml php-curl php-zip \
  nginx certbot python3-certbot-nginx
```

Composer:

```bash
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer
php -r "unlink('composer-setup.php');"
composer --version
```

Проверка SQLite в PHP:

```bash
php -m | grep -i pdo_sqlite
```

Должна быть строка `pdo_sqlite`. Без неё сервис не стартует.

### 3.2. Код и зависимости

```bash
sudo git clone https://github.com/svblab/lotto-game.git /opt/lotto-game
cd /opt/lotto-game
sudo git switch main
sudo composer install --no-dev --optimize-autoloader --no-interaction
sudo mkdir -p logs backups
sudo chown -R www-data:www-data /opt/lotto-game
```

`game.db` в git **не** хранится. Файл появится на следующем шаге.

### 3.3. База и пароль администратора

**Обязательно от `www-data`**, не от root. Иначе `game.db` станет root-owned, и systemd-юнит уйдёт в crash-loop.

```bash
sudo -u www-data php /opt/lotto-game/init_db.php
```

Скрипт один раз печатает:

```text
ADMIN PASSWORD:
<случайная строка>
```

Сохраните пароль в менеджере паролей. Повторный запуск `init_db.php` **не** печатает пароль снова и не перезаписывает существующего `admin`.

Логин веб-админки: пользователь `admin`, этот пароль. Смена пароля — в панели (минимум 10 символов, буква и цифра) или см. §8.3.

### 3.4. Meta-теги клиента (WSS)

В `public/index.html` для HTTPS должно быть:

```html
<meta name="lotto-ws-port" content="">
<meta name="lotto-ws-path" content="/ws">
```

Так браузер открывает `wss://your-domain.com/ws` (порт 443). Сейчас в `main` эти значения уже стоят. После `git pull` проверьте, что их не сбросило на `8080` / пустой path.

### 3.5. systemd

Создайте `/etc/systemd/system/lotto-server.service`:

```ini
[Unit]
Description=Lotto WebSocket Server
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/opt/lotto-game
ExecStart=/usr/bin/php /opt/lotto-game/server.php start
Restart=always
RestartSec=5
MemoryMax=400M
MemoryHigh=350M
Environment=LOTTO_ALLOWED_ORIGINS=https://your-domain.com
Environment=LOTTO_TRUSTED_PROXY_IPS=127.0.0.1,::1

[Install]
WantedBy=multi-user.target
```

Замените `your-domain.com` на реальный Origin сайта (схема + хост, без хвоста `/ws`). Несколько Origin — через запятую, без пробелов.

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now lotto-server
sudo systemctl status lotto-server --no-pager
```

Ожидание: `active (running)`, в `ss -ltnp | grep 8080` слушает PHP.

Если статус `failed` — сразу §9 (часто виноваты права на `game.db` / `logs/`).

### 3.6. nginx + Let's Encrypt

**Порядок:** сначала HTTP-виртуальный хост, потом сертификат, потом полный HTTPS-конфиг с `/ws`. Certbot не должен затереть `location /ws`.

Временный HTTP-сайт `/etc/nginx/sites-available/lotto-game`:

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /opt/lotto-game/public;
    index index.html;
    location / {
        try_files $uri $uri/ /index.html;
    }
}
```

```bash
sudo ln -sf /etc/nginx/sites-available/lotto-game /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

Сертификат:

```bash
sudo certbot --nginx -d your-domain.com
```

Затем **замените** файл сайта на полный (TLS + WebSocket). Не удаляйте заголовки `X-Real-IP` / `X-Forwarded-For`: по ним считается лимит аккаунтов на IP.

```nginx
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}

server {
    listen 443 ssl http2;
    server_name your-domain.com;

    ssl_certificate     /etc/letsencrypt/live/your-domain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/your-domain.com/privkey.pem;

    root /opt/lotto-game/public;
    index index.html;

    location / {
        try_files $uri $uri/ /index.html;
    }

    location /ws {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }
}

server {
    listen 80;
    server_name your-domain.com;
    return 301 https://$host$request_uri;
}
```

```bash
sudo nginx -t && sudo systemctl reload nginx
```

Продление сертификата перезагружает **только nginx**, не игровой процесс:

```bash
sudo crontab -e
```

Строка:

```cron
0 3 * * * certbot renew --quiet && systemctl reload nginx
```

Альтернатива nginx — Caddy (`README.md` §3.3). Схема та же: статика + `reverse_proxy /ws 127.0.0.1:8080`.

### 3.7. Firewall

Не закрывайте SSH.

```bash
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw deny 8080/tcp
sudo ufw enable
sudo ufw status
```

### 3.8. Приёмка после установки

| Проверка | Команда / действие | Ожидание |
|----------|--------------------|----------|
| Юнит | `systemctl is-active lotto-server` | `active` |
| Порт WS | `ss -ltnp \| grep 8080` | процесс php, не с публичной сети |
| Сайт | `curl -sI https://your-domain.com` | `200` |
| Клиент | браузер, регистрация/вход | лобби без ошибки сокета |
| Админ | вход `admin`, кнопка админки в лобби | панель открывается |
| WSS | DevTools → Network → WS | `wss://your-domain.com/ws`, статус 101 |

---

## 4. Обновление кода (git pull)

Комнаты в RAM. Планируйте рестарт, когда игроков мало.

```bash
cd /opt/lotto-game
sudo systemctl stop lotto-server

# если git pull ругается на локальные файлы — см. ниже
sudo git fetch origin
sudo git switch main
sudo git pull --ff-only origin main

sudo composer install --no-dev --optimize-autoloader --no-interaction
sudo chown -R www-data:www-data /opt/lotto-game
sudo systemctl start lotto-server
sudo systemctl status lotto-server --no-pager
```

`--ff-only` не создаёт merge-коммит, если `main` разъехался. Если pull отклонён — не делайте `reset --hard` на живом сервере, пока не поняли, какие локальные правки нужны (обычно их быть не должно).

**Не коммитьте и не удаляйте** живой `game.db`. Он в `.gitignore`.

Типичные блокеры `git pull` (неотслеживаемые файлы на сервере):

| Что часто лежит локально | Что делать |
|--------------------------|------------|
| `public/img/*.png` (логотип, фон — правили руками) | скопировать в `/root/lotto-img-backup/`, убрать из дерева, pull, вернуть файлы |
| `package-lock.json` | оставить неотслеживаемым; **не** добавлять в git на VPS |
| изменённый `public/index.html` | сравнить `git diff`; meta `lotto-ws-port` / `lotto-ws-path` должны остаться production-значениями из §3.4 |

После pull снова проверьте meta-теги и `systemctl is-active lotto-server`.

---

## 5. Что трогать нельзя на живом сервере

- Не запускайте `lotto-server` и тесты **от root**. Файлы `logs/*.log`, `game.db`, `workerman*.pid` станут недоступны `www-data` → crash-loop.
- Не правьте `game.db` «на глаз» во время игры, кроме операций из §8.
- Не гоняйте нагрузочные скрипты и `run_ALL_tests.php` против **боевого** `game.db`. Живые WS-тесты сами поднимают временную БД и порт **18080**; `lotto-server` на 8080 останавливать не нужно. Запуск:

  ```bash
  sudo -u www-data php /opt/lotto-game/run_ALL_tests.php
  ```

- Не увеличивайте `Worker->count` и не ставьте несколько инстансов на один `game.db` / один порт.
- Не открывайте `8080` в firewall «чтобы проверить сокет». Проверка — через `https://…/ws`.
- Не включайте на постоянной основе `LOTTO_MEMORY_AUDIT=1` / `LOTTO_ECONOMY_AUDIT=1` без отдельного диска под логи: это отладочные флаги.

---

## 6. Переменные окружения (systemd)

Задаются в юните `Environment=…`, затем `daemon-reload` и `systemctl restart lotto-server`.

| Переменная | Зачем в production |
|------------|--------------------|
| `LOTTO_ALLOWED_ORIGINS` | Разрешённые Origin браузера. Пусто = любой Origin. Для боя задайте `https://your-domain.com`. Чужой Origin → закрытие до `hello`. |
| `LOTTO_TRUSTED_PROXY_IPS` | С каких peer-IP верить `X-Forwarded-For` / `X-Real-IP`. По умолчанию `127.0.0.1,::1`. Нужно, чтобы лимит аккаунтов на IP видел клиента, а не nginx. |
| `LOTTO_MAX_ACCOUNTS_PER_IP` | Максимум одновременных живых учёток с одного IP (по умолчанию 3). |
| `LOTTO_WS_PORT` | Порт Workerman. В этой схеме не меняйте: nginx смотрит на `127.0.0.1:8080`. |

Остальные `LOTTO_*` (таймеры, audit-логи, `LOTTO_DB_PATH`) — для тестов и отладки, не для штатного боя.

---

## 7. Резервное копирование

SQLite в WAL. Копировать только файл `game.db` во время работы **нельзя** — можно получить обрезанный снимок. Нужен online backup:

```bash
sudo mkdir -p /opt/lotto-game/backups
sudo crontab -e
```

От root (sqlite3 читает БД, путь записи — backups):

```cron
0 4 * * * sqlite3 /opt/lotto-game/game.db ".backup '/opt/lotto-game/backups/game_$(date +\%Y\%m\%d).db'"
```

Права на каталог backups — чтобы cron мог писать; после бэкапа можно `chown www-data`.

Восстановление (сервис остановлен):

```bash
sudo systemctl stop lotto-server
sudo -u www-data cp /opt/lotto-game/backups/game_YYYYMMDD.db /opt/lotto-game/game.db
sudo rm -f /opt/lotto-game/game.db-wal /opt/lotto-game/game.db-shm
sudo chown www-data:www-data /opt/lotto-game/game.db
sudo systemctl start lotto-server
```

Храните копии **вне** этого VPS (скачивание, отдельный диск). В git базу не кладите.

Логи: `logs/server.log`, действия аварийного скрипта — `logs/admin_control.log`. Ротацию `server.log` лучше повесить на `logrotate` (в коде автоматической ротации нет). Пример `/etc/logrotate.d/lotto-game`:

```text
/opt/lotto-game/logs/server.log {
    daily
    rotate 14
    missingok
    notifempty
    copytruncate
    su www-data www-data
}
```

`copytruncate` — чтобы не нужно было сигналить PHP.

---

## 8. Ежедневные операции

### 8.1. Статус и логи

```bash
sudo systemctl status lotto-server --no-pager
sudo journalctl -u lotto-server -n 80 --no-pager
sudo tail -n 80 /opt/lotto-game/logs/server.log
```

### 8.2. Перезапуск

Штатный (SSH, надёжный путь):

```bash
sudo systemctl restart lotto-server
```

Аварийный (зависший worker, «порт занят», битые pid-файлы):

```bash
cd /opt/lotto-game
sudo bash admin_emergency_control.sh status
sudo bash admin_emergency_control.sh restart
# если процесс не отпускает порт:
sudo bash admin_emergency_control.sh force-restart
```

Без аргументов скрипт делает `force-restart`. Нужен **root**.

Кнопка Restart в веб-админке вызывает тот же скрипт **от имени `www-data`**. Скрипт требует root, поэтому кнопка на типичном VPS **не заменяет** SSH/`systemctl`. Для перезапуска используйте команды выше.

Веб-админка (бан, кик, закрытие комнаты, смена пароля админа, настройки ставки) — это игровой admin по WebSocket, не замена systemd.

### 8.3. Пароль администратора

**Native production** (`/opt/lotto-game`, этот документ §3.3): `init_db.php` печатает пароль один раз в терминал.

**Docker / generic systemd** (новые VPS, не production): пароль выдаётся через AHPC
(`admin-bootstrap.sh read` → сохранить → `acknowledge`). См.
[deploy/docker/README.md](../deploy/docker/README.md),
`docs/ADR/038-admin-bootstrap-credential-delivery.md` и `docs/LOCAL_ENVIRONMENT.md`.

Предпочтительно: войти как `admin` → смена пароля в панели (нужен текущий пароль).

Аварийно, если пароль потерян (сервис можно не останавливать; лучше — в окне без игроков):

```bash
php -r "echo password_hash('НовыйПароль1', PASSWORD_BCRYPT), PHP_EOL;"
sudo -u www-data sqlite3 /opt/lotto-game/game.db \
  "UPDATE users SET password_hash='ВСТАВЬТЕ_ХЕШ' WHERE username='admin';"
```

Политика нового пароля в панели: ≥10 символов, буква и цифра, не совпадает со старым.

### 8.4. Ручной разбан

```bash
sudo -u www-data sqlite3 /opt/lotto-game/game.db \
  "UPDATE users SET banned_until = 0 WHERE username = 'имя';"
```

Обычный разбан — из веб-админки.

### 8.5. Права после случайного root

Симптом: `lotto-server` рестартится, в логе ошибка записи в `logs/` или открытия БД.

```bash
sudo chown -R www-data:www-data /opt/lotto-game
sudo systemctl restart lotto-server
```

---

## 9. Диагностика

| Симптом | Что проверить |
|---------|----------------|
| Сайт открывается, сокет не коннектится | `lotto-server` active? nginx `location /ws`? meta `lotto-ws-path=/ws` и пустой port? Origin в `LOTTO_ALLOWED_ORIGINS` совпадает с `https://your-domain.com`? |
| `502` / сразу обрыв WS | Workerman не слушает 8080; `journalctl -u lotto-server` |
| Crash-loop юнита | `ls -l game.db logs/`; владелец не `www-data` |
| `could not find driver` | пакет `php-sqlite3`, `php -m \| grep pdo_sqlite` |
| Игрок за NAT, лимит IP срабатывает на всех | нет `X-Real-IP` / `X-Forwarded-For` в nginx или `LOTTO_TRUSTED_PROXY_IPS` не содержит `127.0.0.1` |
| `git pull` не идёт | неотслеживаемые png / lock-файл — §4 |
| После обновления клиент на старом JS | кэш браузера; у ассетов в HTML есть `?v=` — при необходимости увеличьте после правки CSS/JS |

---

## 10. Модель угроз (кратко для админа)

- Один worker в RAM: DoS по CPU/RAM упирается в `MemoryMax` и лимиты протокола, горизонтального масштаба нет.
- Парольные комнаты — чат и файлы между людьми; игра с ботом в них выключена.
- Файлы в чате не пишутся на диск сервера; лимит пакета WS ~2 МиБ.
- Регистрация: не больше N живых учёток с одного клиентского IP (по умолчанию 3).

---

## 11. Краткий runbook «сервер лёг, поднять»

```bash
sudo bash /opt/lotto-game/admin_emergency_control.sh status
sudo bash /opt/lotto-game/admin_emergency_control.sh force-restart
sudo systemctl status lotto-server --no-pager
sudo nginx -t && sudo systemctl reload nginx
ss -ltnp | grep -E '443|8080'
```

Если юнит снова падает — §8.5 (права) и `journalctl -u lotto-server -e`.
