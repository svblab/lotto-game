# Русское лото — Многопользовательская браузерная игра

WebSocket-сервер на чистом PHP 8.x + Workerman + SQLite3.  
Клиент — Vanilla JS SPA.

## Требования к серверу
- VPS с 1 CPU, 512 МБ ОЗУ (SSD)
- Ubuntu 22.04 LTS
- PHP 8.0+
- Composer
- SQLite3

## 1. Установка

```bash
sudo apt update
sudo apt install -y php8.1-cli php8.1-sqlite3 php8.1-mbstring php8.1-xml php8.1-curl php8.1-zip

# Установка Composer
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php --install-dir=/usr/local/bin --filename=composer
php -r "unlink('composer-setup.php');"

# Клонирование проекта
cd /opt
git clone <репозиторий> lotto-game
cd lotto-game
composer install

# Инициализация БД
php init_db.php
# (выведет пароль администратора)
```

## 2. Демонизация (systemd)

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

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable lotto-server
sudo systemctl start lotto-server
```

## 3. SSL (wss) — TLS через reverse proxy (ADR-027)

Workerman слушает **plain** WebSocket на `127.0.0.1:8080` (или `LOTTO_WS_PORT`).
TLS/WSS завершается **внешним** reverse proxy (nginx или Caddy) — не в PHP-воркере.
Это снижает нагрузку на VPS 1 CPU / 512 МБ и не требует перезапуска игрового
процесса при обновлении сертификата.

### 3.1. Сертификат Let's Encrypt

```bash
sudo apt install -y certbot
# Если nginx ещё не слушает 80 — standalone; иначе используйте certbot --nginx
sudo certbot certonly --standalone -d your-domain.com
```

### 3.2. nginx (рекомендуется)

Создайте `/etc/nginx/sites-available/lotto-game`:

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
sudo ln -s /etc/nginx/sites-available/lotto-game /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

**Клиент (`public/index.html`)** — для production за HTTPS:

```html
<meta name="lotto-ws-port" content="">
<meta name="lotto-ws-path" content="/ws">
```

Браузер подключится к `wss://your-domain.com/ws` (порт 443 по умолчанию).

### 3.3. Caddy (альтернатива)

`/etc/caddy/Caddyfile`:

```caddy
your-domain.com {
    root * /opt/lotto-game/public
    file_server
    try_files {path} /index.html

    reverse_proxy /ws 127.0.0.1:8080
}
```

```bash
sudo systemctl reload caddy
```

Те же meta-теги `lotto-ws-port=""` и `lotto-ws-path="/ws"` в `index.html`.

### 3.4. Firewall

```bash
# Публично: 80/443 (proxy). Workerman 8080 — только localhost.
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw deny 8080/tcp
```

Убедитесь, что `lotto-server.service` (раздел 2) запущен и слушает `8080`.

### 3.5. Локальная разработка (без TLS)

По умолчанию в `index.html`: `lotto-ws-port="8080"`, `lotto-ws-path=""`.
Клиент подключается к `ws://localhost:8080`. Reverse proxy не нужен.

### 3.6. Автообновление сертификата

```bash
# crontab root — перезагрузка proxy, НЕ lotto-server
0 3 * * * certbot renew --quiet && systemctl reload nginx
```

### 3.7. Origin allow-list (ADR-029, optional)

По умолчанию сервер принимает WebSocket с любого `Origin`. В production задайте
список разрешённых источников в `lotto-server.service`:

```ini
Environment=LOTTO_ALLOWED_ORIGINS=https://your-domain.com
```

Несколько значений — через запятую (точное совпадение строки `Origin`, включая
схему и порт):

```ini
Environment=LOTTO_ALLOWED_ORIGINS=https://your-domain.com,http://localhost:8080
```

Пустое или отсутствующее значение = разрешить все. При включённом списке
соединения с чужим или отсутствующим `Origin` отклоняются до `hello`
(`error.origin_forbidden`, WS close 4002).

### 3.8. IP account limit / reverse proxy (ADR-031)

При TLS через reverse proxy (§3.2 / §3.3) Workerman видит peer IP прокси
(обычно `127.0.0.1`), а не клиента. Лимит `MAX_ACCOUNTS_PER_IP` использует
реальный IP из заголовков handshake:

- nginx: в примере ниже уже есть
  `proxy_set_header X-Real-IP $remote_addr;` и
  `proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;` — **не удаляйте**.
- Caddy: `reverse_proxy` по умолчанию прокидывает `X-Forwarded-For`.

Список trusted proxy peer IP (с которых заголовки **доверяются**):

```ini
Environment=LOTTO_TRUSTED_PROXY_IPS=127.0.0.1,::1
```

По умолчанию `127.0.0.1,::1`. Прямое подключение к WS **без** прокси не
читает `X-Forwarded-For` / `X-Real-IP` (защита от подделки).

## 4. Логи и бэкап

- Логи: `logs/server.log`, ротация автоматическая.
- Бэкап БД:

```bash
0 4 * * * cp /opt/lotto-game/game.db /opt/lotto-game/backups/game_$(date +\%Y\%m\%d).db
```

## 5. Ручной разбан

```bash
sqlite3 game.db
UPDATE users SET banned_until = 0 WHERE username = '...';
```

## 6. Графические заглушки

Разместите в `img/`:
- `logo.png` (300x120)
- `barrel.png` (64x64)
- `chip.png` (48x48)
- `card_bg.png` (300x200)
- `table_bg.png` (1920x1080)
- `btn_draw.png` (32x32)
- `icon_admin.png` (24x24)
- `icon_lang.png` (24x24)

## 7. Восстановление пароля администратора

```bash
php -r "echo password_hash('новый_пароль', PASSWORD_BCRYPT);"
sqlite3 game.db "UPDATE users SET password_hash='хеш' WHERE username='admin';"
```

## 8. Аварийная остановка/перезапуск (`admin_emergency_control.sh`)

Однокомандный инструмент для админа на случай зависшего/неотвечающего сервера. Специально защищён от уже встречавшихся на практике проблем: `php server.php stop` иногда отчитывается об успехе, пока осиротевший дочерний worker-процесс Workerman всё ещё держит порт (Workerman переименовывает cmdline воркера в `WorkerMan: worker process ...`, из-за чего наивный `pgrep -f server.php` его не находит — учтено); а также устаревшие `workerman.*.pid`/`server.php.pid`, мешающие следующему старту.

**Использование (требует root/sudo):**

```bash
sudo bash admin_emergency_control.sh status          # что сейчас происходит
sudo bash admin_emergency_control.sh stop            # штатная остановка, с ожиданием и проверкой
sudo bash admin_emergency_control.sh force-stop      # немедленный SIGKILL по всем найденным процессам
sudo bash admin_emergency_control.sh start
sudo bash admin_emergency_control.sh restart         # stop -> start
sudo bash admin_emergency_control.sh force-restart   # ЭКСТРЕННЫЙ вариант: force-stop -> start
```

Без аргументов по умолчанию выполняется `force-restart` — единственная команда на случай *"сервер завис, разбираться некогда, просто подними заново чисто"*:

```bash
sudo bash admin_emergency_control.sh
```

Автоматически предпочитает `systemctl` (юнит `lotto-server.service`), если он установлен на сервере (штатный путь по разделу 2 выше); иначе управляет процессом напрямую через `php server.php`. Все действия пишутся в `logs/admin_control.log` с таймстампами.

⚠️ **Это операционный (CLI) инструмент для системного администратора сервера** — не игровая admin-функция. Модерация игроков (бан/кик/закрытие комнаты) выполняется через существующие WebSocket-пакеты `admin_ban_user`/`admin_kick_user`/`admin_close_room` (см. [ANCHOR_PROTOCOL.md](ANCHOR_PROTOCOL.md)), это отдельный, уже реализованный механизм (Phase 9 + EPIC-10.6), и данный скрипт его не заменяет и не дублирует.

Кнопка Restart в веб-админке вызывает этот скрипт на **Linux**. На Windows-хосте кнопка отключена, пакет `admin_restart_server` возвращает явную ошибку — используйте `php scripts/start_server.php restart`.

## 9. Windows (dev host)

Сборка PHP для Windows часто без `pdo_sqlite` в `php.ini`. Проект подключает SQLite через `lottoBootstrapPhpExtensions()` / `lottoPhpIniArgs()` (`src/Core/Helpers.php`):

```bash
# Полный прогон — подставляет php -d extension=php_pdo_sqlite.dll … каждому тесту
php run_ALL_tests.php
```

Прямой запуск отдельного файла **не** добавляет эти флаги:

```bash
php tests/Manual/test_login.php          # часто: "could not find driver"
php scripts/start_server.php start       # OK: сам вызывает lottoPhpIniArgs()
```

Для одного теста либо используйте `run_ALL_tests.php`, либо включите sqlite в `php.ini`, либо повторите флаги из `lottoPhpIniArgs()`:

```bash
php -d extension_dir=…\ext -d extension=php_sqlite3.dll -d extension=php_pdo_sqlite.dll tests/Manual/test_login.php
```

`php server.php start` после FIX-15 сам делает `dl()` в процессе. Подробнее: `docs/LOCAL_ENVIRONMENT.md`.