# Документация «Русское лото»

Навигация для администраторов и специалистов среднего уровня. Документы разделены
по роли — не нужно читать всё подряд.

## Эксплуатация сервера

| Документ | Когда использовать |
|----------|-------------------|
| [ADMIN_VPS_DEPLOY.md](ADMIN_VPS_DEPLOY.md) | **Production** на одном VPS: `/opt/lotto-game`, `lotto-server.service`, nginx, HTTPS |
| [../deploy/docker/README.md](../deploy/docker/README.md) | **Docker** на новом VPS (контейнеры, AHPC, `configure-proxy.sh`) |
| [../deploy/systemd/README.md](../deploy/systemd/README.md) | **Generic systemd**: несколько native-инстансов `/opt/lotto-game-<name>/` |
| [LOCAL_ENVIRONMENT.md](LOCAL_ENVIRONMENT.md) | Сводка всех моделей развёртывания, тесты, переменные окружения |
| [SYSTEMD_VPS_VERIFICATION.md](SYSTEMD_VPS_VERIFICATION.md) | Чек-лист проверки systemd на реальном VPS |

### Важно: три разные модели

1. **Production (существующий)** — `docs/ADMIN_VPS_DEPLOY.md`, пароль admin через `init_db.php` в терминал.
2. **Docker Compose** — `deploy/docker/`, пароль через **AHPC** (`admin-bootstrap.sh`).
3. **Generic systemd** — `deploy/systemd/`, пароль через **AHPC**.

Нет общего `deploy/install.sh` и нет `--mode docker|systemd`.

## Игроки и модераторы

| Документ | Содержание |
|----------|------------|
| [GAME_RULES.md](GAME_RULES.md) | Правила игры, AFK, «Квартира», бот, чат, переподключение |
| [../README.md](../README.md) | Краткий обзор проекта, примеры nginx/Caddy |

## Разработка и протокол

| Документ | Содержание |
|----------|------------|
| [ANCHOR_PROTOCOL.md](ANCHOR_PROTOCOL.md) | Форматы WebSocket-пакетов (канон) |
| [ANCHOR_CORE.md](ANCHOR_CORE.md) | Архитектура сервера, лимиты, реестры |
| [ANCHOR_RULES.md](ANCHOR_RULES.md) | Правила разработки |
| [IMPLEMENTATION_STATUS.md](IMPLEMENTATION_STATUS.md) | Журнал эпиков и статус тестов (для разработчиков) |
| [ADR/README.md](ADR/README.md) | Архитектурные решения (ADR) |

## AHPC (пароль admin для Docker / systemd)

Спецификация: [ADR/038-admin-bootstrap-credential-delivery.md](ADR/038-admin-bootstrap-credential-delivery.md)

Кратко: после первой установки пароль лежит в pending-файле на хосте (`0600`).
Получить: `admin-bootstrap.sh read` → сохранить → `acknowledge`. Пароль **не**
попадает в логи установки.

## Устаревшие / внутренние отчёты

Файлы `PHASE_*_REPORT.md`, `EPIC_*_VERIFICATION.md`, `AUDIT_*` — отчёты о
конкретных этапах разработки. Для ежедневной эксплуатации используйте таблицы выше.
