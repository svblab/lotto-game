# Docker Compose deployment

Контейнерное развёртывание для **нового** VPS (Debian/Ubuntu). Не заменяет
существующий production (`/opt/lotto-game`, `lotto-server.service`).

**Полное руководство:** `docs/LOCAL_ENVIRONMENT.md` § Docker deployment.  
**Архитектура:** `docs/ADR/036-docker-compose-deployment.md`, AHPC — `docs/ADR/038-admin-bootstrap-credential-delivery.md`.

## Быстрый старт

```bash
git clone https://github.com/svblab/lotto-game.git
cd lotto-game
sudo ./deploy/docker/install.sh --name lotto-01
```

После **первой** установки (новая база) пароль администратора **не** выводится в
терминал. Используйте AHPC:

```bash
sudo ./deploy/docker/admin-bootstrap.sh --name lotto-01 status
sudo ./deploy/docker/admin-bootstrap.sh --name lotto-01 read          # только с TTY
# или для автоматизации:
sudo ./deploy/docker/admin-bootstrap.sh --name lotto-01 read --format=json
sudo ./deploy/docker/admin-bootstrap.sh --name lotto-01 acknowledge
```

Сохраните пароль в менеджере секретов **до** `acknowledge`. После подтверждения
повторное чтение невозможно (exit `2`); для нового пароля — `reset` (см. ниже).

## Команды

| Действие | Команда |
|----------|---------|
| Установка / обновление | `sudo ./deploy/docker/install.sh --name <instance> [options]` |
| Проверка здоровья | `sudo ./deploy/docker/healthcheck.sh --name <instance>` |
| Статус учётки admin | `sudo ./deploy/docker/admin-bootstrap.sh --name <instance> status` |
| Получить пароль (один раз) | `sudo ./deploy/docker/admin-bootstrap.sh --name <instance> read` |
| Подтвердить получение | `sudo ./deploy/docker/admin-bootstrap.sh --name <instance> acknowledge` |
| Сброс пароля admin | `sudo ./deploy/docker/admin-bootstrap.sh --name <instance> reset` |
| nginx + Let's Encrypt | `sudo ./deploy/docker/configure-proxy.sh --name <instance>` |
| Удаление | `sudo ./deploy/docker/remove.sh --name <instance> --yes` |

Опции установки: `./deploy/docker/install.sh --help`  
(`--port`, `--mem-limit`, `--allowed-origins`, `--non-interactive`, …)

## Имена ресурсов Docker

Для инстанса `<name>` (например `lotto-01`):

| Ресурс | Имя |
|--------|-----|
| Compose project | `lotto-<name>` |
| Контейнер | `lotto-<name>-app` |
| Volume (БД) | `lotto-<name>-data` |
| Сеть | `lotto-<name>-net` |
| Образ | `lotto-game:<name>` |
| Метаданные на хосте | `/var/lib/lotto-game/<name>/` |
| Pending-пароль (AHPC) | `/var/lib/lotto-game/<name>/admin-bootstrap.pending` |

Логи приложения — **только stdout** (`docker compose logs`). Файловая ротация
как у native systemd не применяется.

## Автоматическая установка (CI / provisioning)

```bash
sudo ./deploy/docker/install.sh --name lotto-01 --non-interactive
# exit 42 = нужен ручной/автоматический handoff пароля admin
```

Скрипт **не** печатает пароль. Дальше: `read --format=json` → внешний vault →
`acknowledge`. Подробности — ADR-038.

## Сброс пароля (`reset`)

1. Сначала `acknowledge`, если ещё есть pending-файл (иначе reset вернёт `10`).
2. `sudo ./deploy/docker/admin-bootstrap.sh --name <instance> reset`
3. `read` → сохранить новый пароль → `acknowledge`

Старый пароль **никогда** не показывается.

## Тесты

```bash
bash deploy/docker/tests/run_tests.sh
bash deploy/docker/tests/test_admin_bootstrap.sh
```

## Что это НЕ делает

- Не трогает production `default` / `lotto-server.service` без явного `--name`.
- Не устанавливает Docker на хост (нужен заранее).
- Не хранит пароль в `instance.env`, Compose или логах контейнера.
