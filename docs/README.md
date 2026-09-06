# Документация «Русское лото»

Навигация для администраторов и специалистов среднего уровня. Документы разделены
по роли — не нужно читать всё подряд.

## Эксплуатация сервера

### NLD V1.0 — released

Native Linux Deployment (systemd + nginx + `/opt/lotto-game`) — **released** (tag `v1.0`).

| Документ | Когда использовать |
|----------|-------------------|
| [ROADMAP_V1_PRODUCTION.md](ROADMAP_V1_PRODUCTION.md) | **SSOT** — путь к V1.0 в production: gates G0–G11, фазы, Human approvals |
| [RELEASE_CONTRACT_V1.md](RELEASE_CONTRACT_V1.md) | **G0** — V1.0 release contract (**PASS**, H1 approved) |

### Docker V1 — separate validation / release track

Docker как **отдельный** installation/distribution target — **не released**; собственный
цикл валидации D0–D12 и Human gate H-D1. Не заменяет и не изменяет NLD `v1.0`.

| Документ | Когда использовать |
|----------|-------------------|
| [ROADMAP_DOCKER_V1.md](ROADMAP_DOCKER_V1.md) | **SSOT** — Docker V1 validation roadmap (D0–D12, H-D1) |
| [DOCKER_V1_EVIDENCE.md](DOCKER_V1_EVIDENCE.md) | Индекс evidence для Docker gates (шаблон, заполняется при валидации) |
| [ADR/039-docker-v1-distribution-target.md](ADR/039-docker-v1-distribution-target.md) | Архитектурное решение: Docker V1, ephemeral container state |

### Общая эксплуатация

| Документ | Когда использовать |
|----------|-------------------|
| [G1_PRODUCTION_CONFIGURATION.md](G1_PRODUCTION_CONFIGURATION.md) | **G1 / EPIC-16** — production config verification (READY FOR HUMAN APPROVAL) |
| [EPIC_17_PRODUCTION_DEPLOYMENT.md](EPIC_17_PRODUCTION_DEPLOYMENT.md) | **EPIC-17** — canonical production deploy evidence (`b3531d1` @ `rusbingo.online`) |
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
