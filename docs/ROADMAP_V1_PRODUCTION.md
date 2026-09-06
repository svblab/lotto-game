# ROADMAP V1.0 — Production Release (SSOT)

**Status:** Accepted roadmap (documentation only — **not** a release approval)  
**Repository:** `svblab/lotto-game`  
**Last reviewed against `main`:** `ae59b92` (2026-09-06)  
**Related:** `docs/ROADMAP.md` (feature epics), `docs/ADMIN_VPS_DEPLOY.md` (production runbook)

## Purpose

Единый источник правды (SSOT) для выхода **V1.0 в production**. Документ описывает
фазы, release gates, evidence, роли Human/Cursor и будущую декомпозицию в Epic
**без** реализации новых игровых функций, **без** production deployment и **без**
release tag в рамках фиксации roadmap.

Проект **не считается production-ready**, пока все P0 gates не пройдены с
документированным evidence на целевом VPS и привязкой к конкретному Git SHA.

## Non-goals (этот roadmap)

- Не добавлять игровые features.
- Не менять протокол, схему БД или игровую логику.
- Не выполнять go-live автоматически.
- Не заменять Human approval предположениями Cursor.
- Не объявлять Docker равноправным production deployment.

---

## Release flow

```text
Release Contract (G0)
        ↓
Canonical Deployment (G1)
        ↓
Domain / Configuration
        ↓
VPS Bootstrap
        ↓
DNS (G2)
        ↓
Production Installation
        ↓
HTTPS / WSS (G3)
        ↓
Production E2E (G4)
        ↓
Persistence (G5)
        ↓
Backup / Restore (G6)
        ↓
Recovery (G7)
        ↓
Security (G8)
        ↓
Observability (G9)
        ↓
Final regression (G10)
        ↓
Release Candidate
        ↓
Human release approval (G11)
        ↓
V1.0 Release (tag — отдельное действие после G11)
        ↓
Post-release Observation
        ↓
V1.0 Stable
```

---

## Canonical deployment (решение V1.0)

### Production (единственный canonical)

```text
Linux VPS
+ native application deployment
+ systemd (lotto-server.service)
+ nginx (TLS termination, static + /ws proxy)
+ Workerman (plain WS on loopback)
+ SQLite (game.db)
```

Операторский runbook: **`docs/ADMIN_VPS_DEPLOY.md`**.  
Текущая production-схема: `/opt/lotto-game`, пользователь `www-data`, порт Workerman
`8080` на loopback, публично `443`/`80` через nginx.

### Docker / Compose (не production)

Docker **не deprecated**. Роль для V1.0:

```text
reproducible test / staging deployment
```

Документация: `deploy/docker/README.md`, `docs/LOCAL_ENVIRONMENT.md` § Docker,
ADR-036, ADR-038 (AHPC).

### Generic systemd multi-instance

`deploy/systemd/` (`/opt/lotto-game-<name>/`) — **не** canonical V1.0 production.
Допустим как staging / второй контур / эксперимент, но не заменяет единственный
production path выше.

---

## Domain / configuration contract

Production domain — **configuration**, не часть application source code.

### Целевой контракт (V1.0)

```text
APP_ENV=production
APP_DOMAIN=<production-domain>
```

Публичный origin и WebSocket endpoint должны быть согласованы:

```text
https://<APP_DOMAIN>/
wss://<APP_DOMAIN>/ws
```

Предпочтительная схема: **один hostname**, без отдельного WS-subdomain, если нет
архитектурной необходимости:

```text
example.com
    |
    +-- HTTPS (static SPA)
    |
    +-- WSS /ws → upstream 127.0.0.1:8080
```

### Запрещено зашивать production domain в

- PHP source (`src/`);
- JavaScript source (`public/js/`);
- WebSocket client hardcode;
- deployment scripts (кроме шаблонов/примеров);
- automated tests (использовать test/staging domains).

### Текущее состояние репозитория (см. Open Decisions)

Сегодня WS URL задаётся meta-тегами в `public/index.html` (`lotto-ws-port`,
`lotto-ws-path`), а Origin — через `LOTTO_ALLOWED_ORIGINS` в systemd unit.
Переход к `APP_DOMAIN` / единому env-файлу — **цель EPIC-16**, не завершён на
момент фиксации roadmap.

---

## Environment / secrets policy

### Repository (Git)

Разрешено:

```text
.env.example   # имена переменных + описания, без production values
```

Запрещено в Git:

- production secrets и реальные production values;
- TLS private keys;
- SSH private keys;
- API tokens;
- plaintext admin passwords;
- snapshots `game.db` с production data.

### VPS (вне Git working tree)

Предпочтительная целевая раскладка для V1.0 (может быть уточнена в EPIC-17 без
изменения canonical deployment model):

| Класс | Назначение | Предпочтительный path (target) | Текущий production (as-is) |
|-------|------------|--------------------------------|----------------------------|
| Configuration | non-secret env | `/etc/lotto-game/lotto-game.env` | `Environment=` в unit + `public/index.html` meta |
| Secrets | passwords, keys | `/etc/lotto-game/` (`0600`, root) | AHPC pending (Docker/systemd only); native: `init_db` one-shot |
| Database | SQLite | `/var/lib/lotto-game/game.db` | `/opt/lotto-game/game.db` |
| Uploads | chat file attachments (persistent) | `/var/lib/lotto-game/uploads/` | under app tree (if used) |
| Logs | application logs | `/var/log/lotto-game/` | `/opt/lotto-game/logs/` |
| Backups | offline copies | `/var/backups/lotto-game/` или `/opt/lotto-game/backups/` | `/opt/lotto-game/backups/` (documented) |
| Application code | git checkout | `/opt/lotto-game/` | `/opt/lotto-game/` |

SSH keys, passwords, tokens **не передаются** Cursor через prompt, Git или обычные
текстовые файлы проекта.

---

## VPS / responsibility model

| Роль | Ответственность |
|------|-----------------|
| **Human** | VPS, SSH, domain, DNS (или возможность внести записи), production approvals (H1–H9), backup/restore execution на бою, go-live decision |
| **Cursor** | deployment automation, templates, verification scripts, test procedures, documentation, evidence templates — **не** выбор production architecture без Human |

Cursor **не** считает production готовым по локальным тестам alone. Production PASS
привязан к **конкретному Git SHA** на **предоставленном VPS**.

---

## Release gates (summary)

| Gate | Description | Priority | Executor | Decision | V1.0 blocker | Evidence |
|------|-------------|----------|----------|----------|--------------|----------|
| **G0** | Release Contract — этот документ утверждён | P0 | Human + Cursor | Human (H1) | **YES** | Signed/approved roadmap revision |
| **G1** | Canonical deployment на VPS | P0 | Cursor + Human | Human (H5) | **YES** | Install + health on target VPS @ SHA |
| **G2** | Domain / DNS | P0 | Human | Human (H3, H4) | **YES** | DNS + curl/openssl checks |
| **G3** | HTTPS / WSS live | P0 | Cursor + Human | Human (H5) | **YES** | `https://` + `wss://…/ws` handshake @ domain |
| **G4** | Production browser E2E | P0 | Cursor + Human | Human (H6) | **YES** | Recorded E2E checklist @ domain |
| **G5** | Persistence after restart | P0 | Cursor + Human | Human (H6) | **YES** | Restart + login + state match |
| **G6** | Backup / restore | P0 | Human + Cursor | Human (H7) | **YES** | Executed restore test, not script-only |
| **G7** | Recovery (systemd/nginx/WS) | P0 | Cursor + Human | Human (H5) | **YES** | Controlled failure/recovery log |
| **G8** | Security checklist | P0 | Cursor + Human | Human (H5) | **YES** | Completed security gate form @ SHA |
| **G9** | Observability / operator runbook | P1 (operational minimum **P0** for go-live) | Cursor | Human (H5) | **YES** for go-live | Operator runbook + smoke commands |
| **G10** | Final regression | P0 | Cursor | Human (H8) | **YES** | `run_ALL_tests.php` + deploy tests @ SHA |
| **G11** | Release approval / go-live | P0 | Human | Human (H9) | **YES** | Explicit written approval |

**Правило:** G0–G11 не помечаются PASS без evidence. Старые результаты (Phase 11
VPS, Docker AHPC, local Windows) **не заменяют** production gates для нового SHA.

---

## Evidence template (обязателен для каждого P0 gate)

```text
gate_id:
git_sha:
environment:          # local | docker-staging | generic-systemd-staging | production-vps
os:
php_version:
deployment_mode:    # native-production | docker | generic-systemd
domain:             # or N/A
date_time_utc:
procedure:          # command or checklist section
expected:
actual:
result:             # PASS | FAIL
artifacts:          # log paths, screenshots, report file links (no secrets)
```

**SHA rule:**

```text
tested_sha = release_candidate_sha = released_sha
```

---

## Human approval gates

| ID | Decision | Owner |
|----|----------|-------|
| **H1** | Approve Release Contract (this document) | Human |
| **H2** | Provide VPS (SSH, resources) | Human |
| **H3** | Provide / confirm production domain | Human |
| **H4** | Perform / approve DNS changes | Human |
| **H5** | Approve production deployment configuration | Human |
| **H6** | Confirm production E2E + persistence | Human |
| **H7** | Confirm backup/restore executed | Human |
| **H8** | Approve release candidate @ SHA | Human |
| **H9** | Approve v1.0 tag / go-live | Human |

Cursor не подменяет H1–H9.

---

## Phases (detailed)

### Phase 1 — Release Contract

| | |
|---|---|
| **Goal** | Зафиксировать scope, gates, roles, canonical deployment |
| **Inputs** | Approved feature set on `main`; `docs/PHASE_14_REPORT.md` as historical input only |
| **Deliverables** | `docs/ROADMAP_V1_PRODUCTION.md` (this file); Human sign-off record |
| **Checks** | No contradiction with `docs/ANCHOR_CORE.md` deployment boundaries; Open Decisions documented |
| **Acceptance** | H1 complete; G0 evidence filed |
| **Executor** | Cursor (draft) + Human (approve) |
| **P0 blocker** | YES (G0) |

### Phase 2 — Canonical Deployment

| | |
|---|---|
| **Goal** | One production model: native systemd + nginx + Workerman + SQLite |
| **Inputs** | G0 PASS; VPS available (H2) |
| **Deliverables** | Production install per `docs/ADMIN_VPS_DEPLOY.md`; optional path migration plan if `/etc`/`/var` split adopted |
| **Checks** | `systemctl is-active lotto-server`; `ss` shows 8080 on loopback; nginx serves `public/` |
| **Acceptance** | G1 evidence on VPS @ SHA |
| **Executor** | Cursor (automation/docs) + Human (VPS access) |
| **P0 blocker** | YES (G1) |

### Phase 3 — Domain / Configuration

| | |
|---|---|
| **Goal** | Domain as config; WS URL derivable from public origin |
| **Inputs** | H3 domain; template env file design |
| **Deliverables** | `.env.example` entries; nginx `server_name`; `LOTTO_ALLOWED_ORIGINS` / future `APP_DOMAIN`; production meta tags documented |
| **Checks** | No production domain string in `src/` or `public/js/` |
| **Acceptance** | Configuration review checklist PASS |
| **Executor** | Cursor + Human |
| **P0 blocker** | Part of G2/G3 |

### Phase 4 — VPS Bootstrap

| | |
|---|---|
| **Goal** | OS, packages, firewall baseline, non-root service user |
| **Inputs** | H2 VPS; Ubuntu 22.04/24.04 |
| **Deliverables** | PHP 8.2+ CLI + extensions, Composer, nginx, certbot, ufw rules |
| **Checks** | `php -m` includes `pdo_sqlite`; ports 22/80/443; 8080 not public |
| **Acceptance** | Bootstrap checklist PASS |
| **Executor** | Human (hosting) + Cursor (checklist) |
| **P0 blocker** | YES (part of G1) |

### Phase 5 — DNS

| | |
|---|---|
| **Goal** | Domain resolves to production VPS |
| **Inputs** | H3, H4 |
| **Deliverables** | `A`/`AAAA` records; documented TTL |
| **Checks** | `dig`/`nslookup`; IPv4/IPv6 if used |
| **Acceptance** | G2 evidence |
| **Executor** | Human |
| **P0 blocker** | YES (G2) |

### Phase 6 — Production Installation

| | |
|---|---|
| **Goal** | Repeatable install sequence on VPS |
| **Inputs** | G2; git SHA selected for RC |
| **Sequence** | OS → packages → PHP → Composer → app clone → `composer install --no-dev` → `init_db.php` as `www-data` → permissions → systemd → nginx → TLS placeholder |
| **Deliverables** | Running `lotto-server.service`; `game.db` owned by `www-data` |
| **Checks** | `docs/ADMIN_VPS_DEPLOY.md` §3.8 acceptance table |
| **Acceptance** | HTTP 200 on `/`; WS not yet required |
| **Executor** | Cursor + Human |
| **P0 blocker** | YES (G1) |

### Phase 7 — HTTPS / WSS

| | |
|---|---|
| **Goal** | Production-grade TLS and browser WSS |
| **Inputs** | DNS (G2); nginx vhost |
| **Deliverables** | Let's Encrypt cert; `location /ws`; meta `lotto-ws-port=""`, `lotto-ws-path="/ws"` |
| **Checks** | HTTP→HTTPS redirect; valid chain; `wss://<domain>/ws` RFC6455 handshake; `LOTTO_ALLOWED_ORIGINS` matches `https://<domain>` |
| **Acceptance** | G3 evidence — **not** HTTP-only |
| **Executor** | Cursor + Human |
| **P0 blocker** | YES (G3) |

### Phase 8 — Production E2E

| | |
|---|---|
| **Goal** | Observable gameplay on production domain |
| **Minimum path** | register → login → create room → second player joins → cards → start → draw → mark → turns → finish → payout |
| **Additional** | disconnect → reconnect; AFK (escalating windows per ADR-012); admin kick; admin close; human vs bot; bot win / bank burn |
| **Checks** | Browser on real domain; two clients/sessions where needed |
| **Acceptance** | G4 evidence with screenshots or structured log (no passwords) |
| **Executor** | Human (browser) + Cursor (scripted WS checks optional) |
| **P0 blocker** | YES (G4) |

### Phase 9 — Persistence

| | |
|---|---|
| **Goal** | SQLite survives service restart |
| **Procedure** | create account → balance change → play → payout → `systemctl restart lotto-server` → login → verify state |
| **Checks** | `sqlite3 game.db "PRAGMA integrity_check;"` |
| **Acceptance** | G5 evidence on production filesystem |
| **Executor** | Cursor + Human |
| **P0 blocker** | YES (G5) |

### Phase 10 — Backup / Restore

| | |
|---|---|
| **Goal** | Documented procedure **executed**, not theoretical |
| **Procedure** | backup → verify artifact → simulate loss → restore → integrity check → start → functional smoke |
| **Scope** | SQLite; config references; **no secrets in public backup artifacts** |
| **Acceptance** | G6 — restore actually performed (Human H7) |
| **Executor** | Human (execute) + Cursor (procedure doc) |
| **P0 blocker** | YES (G6) |

### Phase 11 — Recovery

| | |
|---|---|
| **Goal** | Controlled recovery from failure |
| **Checks** | `systemctl restart`; `admin_emergency_control.sh`; stale PID; nginx reload; WSS after recovery |
| **Acceptance** | G7 evidence |
| **Executor** | Cursor + Human |
| **P0 blocker** | YES (G7) |

### Phase 12 — Security

| | |
|---|---|
| **Goal** | Production hardening before go-live |
| **Checklist** | SSH; firewall; exposed ports; root login; password auth policy; nginx/TLS; `LOTTO_ALLOWED_ORIGINS`; rate limits; upload limits (chat files); file permissions; `www-data` service user; no debug leakage; no secrets in Git |
| **Acceptance** | G8 completed checklist @ SHA |
| **Executor** | Cursor (review/automation) + Human |
| **P0 blocker** | YES (G8) |

### Phase 13 — Observability

| | |
|---|---|
| **Goal** | Operator can answer "is it down?" without APM platform |
| **Minimum** | `systemctl status`; `journalctl`; `logs/server.log`; disk/mem; backup age; WSS probe; sqlite integrity |
| **Deliverables** | Short runbook: "Application is down" → checks → restart → logs → WSS → DB → rollback criteria |
| **Acceptance** | G9; linked from `docs/ADMIN_VPS_DEPLOY.md` §8–9 |
| **Executor** | Cursor (doc) + Human (validate) |
| **P0 blocker** | YES for go-live (G9) |

### Phase 14 — Release Candidate

| | |
|---|---|
| **Goal** | Single SHA with all G1–G10 PASS |
| **Inputs** | G10 regression @ same SHA |
| **Deliverables** | RC report (evidence bundle); no tag yet |
| **Acceptance** | Human H8 |
| **Executor** | Cursor + Human |
| **P0 blocker** | YES (G10 + H8) |

### Phase 15 — V1.0 Release

| | |
|---|---|
| **Goal** | Tagged release after G11 |
| **Inputs** | H9 approval; `tested_sha = released_sha` |
| **Deliverables** | Git tag `v1.0.0` (or project convention); release notes |
| **Acceptance** | Tag points to approved SHA; post-deploy smoke |
| **Executor** | Human |
| **P0 blocker** | YES (G11) — **отдельное действие после gates** |

### Phase 16 — Post-release Observation

| | |
|---|---|
| **Goal** | Stabilization window (e.g. 48–72h) |
| **Checks** | logs, disk, error rates, player reports |
| **Acceptance** | No P0 incidents; observation log |
| **Executor** | Human |
| **P0 blocker** | NO (operational) |

### Phase 17 — V1.0 Stable

| | |
|---|---|
| **Goal** | Declare stable operations baseline |
| **Inputs** | Observation complete; backup cadence running |
| **Acceptance** | Human sign-off on stable operations |
| **Executor** | Human |
| **P0 blocker** | NO |

---

## Operator runbook stub ("Application is down")

1. `systemctl status lotto-server` — active?
2. `journalctl -u lotto-server -n 80` — errors?
3. `tail -n 80 /opt/lotto-game/logs/server.log`
4. `ss -ltnp | grep 8080` — Workerman listening?
5. `sudo nginx -t && systemctl status nginx`
6. Browser DevTools → `wss://<domain>/ws` status 101
7. `sqlite3 /opt/lotto-game/game.db "PRAGMA integrity_check;"`
8. Restart: `sudo systemctl restart lotto-server` (or `admin_emergency_control.sh`)
9. If unresolved: restore from backup (G6 procedure) or rollback to previous SHA (Human decision)

Полная версия — расширение `docs/ADMIN_VPS_DEPLOY.md` в EPIC-20.

---

## Epic decomposition (future — not created in this task)

Roadmap синтезируется в следующие Epic (номера **не** пересекаются с `docs/ROADMAP.md` feature epics):

| Epic | Scope |
|------|-------|
| **EPIC-15** | V1.0 Release Contract (G0, H1, evidence process) |
| **EPIC-16** | Production Configuration & Domain (`APP_DOMAIN`, `.env.example`, meta/Origin alignment) |
| **EPIC-17** | Canonical Production Deployment (install automation, path layout, G1) |
| **EPIC-18** | Production Security & TLS (G3, G8, nginx hardening) |
| **EPIC-19** | Production E2E & Recovery (G4, G5, G7) |
| **EPIC-20** | Backup / Restore / Operations (G6, G9, operator runbook) |
| **EPIC-21** | V1.0 Release & Go-Live (RC, G10–G11, tag, observation) |

Это **roadmap decomposition only** — задачи Epic не создаются автоматически при фиксации этого документа.

---

## Definition of Done — V1.0 Release (product)

V1.0 считается достигнутым только когда:

1. G0–G11 — **PASS** с evidence на production VPS @ одном SHA.
2. H1–H9 — явные Human approvals задокументированы.
3. Production E2E (G4) выполнен в браузере на production domain.
4. Backup/restore (G6) **выполнен**, не только описан.
5. Нет открытых P0 security findings.
6. Release tag создан **после** G11 (отдельный шаг).

До этого момента статус проекта: **NOT production-ready for V1.0**.

---

## Open Decisions / Conflicts

| ID | Topic | Current repo state | Roadmap target | Resolution owner |
|----|-------|-------------------|----------------|------------------|
| **OD-1** | Env file layout | `Environment=` inline in `lotto-server.service`; DB/logs under `/opt/lotto-game/` | `/etc/lotto-game/lotto-game.env` + `/var/lib`, `/var/log` split | EPIC-17; must not break canonical deployment model |
| **OD-2** | `APP_ENV` / `APP_DOMAIN` | Not implemented; uses `LOTTO_*` + HTML meta for WS | Unified domain contract | EPIC-16 |
| **OD-3** | Admin bootstrap | Native production: `init_db.php` stdout (ADR-038 exempt); Docker/systemd: AHPC | Documented in ADR-038; no change required for V1.0 native path | Accept as-is for V1.0 |
| **OD-4** | Prior RC evidence | `docs/PHASE_14_REPORT.md` — RC with unverified browser/VPS gates | Historical only; must re-run G1–G11 @ release SHA | Human + Cursor at RC time |
| **OD-5** | Epic numbering | `docs/ROADMAP.md` uses EPIC-0.x–14.x for features | EPIC-15–21 reserved for V1.0 production stream | This document; update `ROADMAP.md` index when Epic start |

### Consistency notes (no hidden conflict)

- **ANCHOR_CORE.md:** deployment/bootstrap only; V1.0 roadmap does not alter protocol or game rules. ✓
- **ADR-037:** discourages shared `deploy/lib/` except AHPC (ADR-038) — unchanged. ✓
- **ADR-027:** TLS at nginx — matches canonical production. ✓
- **Docker AHPC** verified on disposable Linux (see `IMPLEMENTATION_STATUS.md` EPIC-14.1) — counts as **docker-staging** evidence only, not G1–G4 production PASS.

---

## References

- `docs/ADMIN_VPS_DEPLOY.md` — production install/runbook
- `docs/LOCAL_ENVIRONMENT.md` — deployment models, tests, AHPC
- `docs/README.md` — documentation index
- `docs/ROADMAP.md` — feature implementation epics
- `docs/PHASE_14_REPORT.md` — prior RC report (superseded for gates by this roadmap)
- `docs/ADR/027-reverse-proxy-tls-termination.md`
- `docs/ADR/036-docker-compose-deployment.md`
- `docs/ADR/038-admin-bootstrap-credential-delivery.md`
