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

## 3. SSL (wss)

```bash
sudo apt install -y certbot
sudo certbot certonly --standalone -d your-domain.com
```

Создайте `config/ssl.php`:

```php
<?php
return [
    'ssl' => [
        'local_cert'  => '/etc/letsencrypt/live/your-domain.com/fullchain.pem',
        'local_pk'    => '/etc/letsencrypt/live/your-domain.com/privkey.pem',
        'verify_peer' => false,
    ],
    'port' => 8443,
];
```

Автообновление (crontab):

```bash
0 3 * * * certbot renew --quiet && systemctl restart lotto-server
```

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