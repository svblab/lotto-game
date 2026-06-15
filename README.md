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
sudo apt update
sudo apt install -y php8.1-cli php8.1-sqlite3 php8.1-mbstring php8.1-xml php8.1-curl php8.1-zip

Установка Composer:
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php --install-dir=/usr/local/bin --filename=composer
php -r "unlink('composer-setup.php');"

Клонирование проекта:
cd /opt
git clone <репозиторий> lotto-game
cd lotto-game
composer install

Инициализация БД:
php init_db.php
(выведет пароль администратора)

## 2. Демонизация (systemd)
Создайте /etc/systemd/system/lotto-server.service:

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

sudo systemctl daemon-reload
sudo systemctl enable lotto-server
sudo systemctl start lotto-server

## 3. SSL (wss)
sudo apt install -y certbot
sudo certbot certonly --standalone -d your-domain.com

config/ssl.php:
<?php
return [
    'ssl' => [
        'local_cert'  => '/etc/letsencrypt/live/your-domain.com/fullchain.pem',
        'local_pk'    => '/etc/letsencrypt/live/your-domain.com/privkey.pem',
        'verify_peer' => false,
    ],
    'port' => 8443,
];

Автообновление: crontab -e
0 3 * * * certbot renew --quiet && systemctl restart lotto-server

## 4. Логи и бэкап
Логи: logs/server.log, ротация автоматическая.
Бэкап БД: 0 4 * * * cp /opt/lotto-game/game.db /opt/lotto-game/backups/game_$(date +\%Y\%m\%d).db

## 5. Ручной разбан
sqlite3 game.db
UPDATE users SET banned_until = 0 WHERE username = '...';

## 6. Графические заглушки
img/logo.png (300x120), img/barrel.png (64x64), img/chip.png (48x48), img/card_bg.png (300x200), img/table_bg.png (1920x1080), img/btn_draw.png (32x32), img/icon_admin.png (24x24), img/icon_lang.png (24x24)

## 7. Восстановление пароля администратора
php -r "echo password_hash('новый_пароль', PASSWORD_BCRYPT);"
sqlite3 game.db "UPDATE users SET password_hash='хеш' WHERE username='admin';"

