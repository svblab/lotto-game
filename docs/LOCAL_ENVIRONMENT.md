# Local Environment

## Какой документ читать

| Задача | Документ |
|--------|----------|
| Production VPS (`/opt/lotto-game`, nginx, `lotto-server.service`) | `docs/ADMIN_VPS_DEPLOY.md` |
| Docker на новом VPS | `deploy/docker/README.md` + § Docker ниже |
| Generic systemd (несколько инстансов) | `deploy/systemd/README.md` + § Generic systemd ниже |
| Правила игры для игроков | `docs/GAME_RULES.md` |
| Протокол WebSocket (разработка) | `docs/ANCHOR_PROTOCOL.md` |
| Статус реализации (разработчики) | `docs/IMPLEMENTATION_STATUS.md` |

Нет единого `deploy/install.sh` и нет флага `--mode docker|systemd`.

---

Host: Ubuntu 24.04
PHP: 8.4.21
Composer: 2.9.8
SQLite: 3.45.1
Workerman: installed via Composer

Repository: https://github.com/svblab/lotto-game
Deployment path: /opt/lotto-game
Service: lotto-server.service
WebSocket: ws://localhost:8080 (plain; production WSS via reverse proxy — ADR-027)

Production TLS: terminate at nginx/Caddy on 443, proxy `/ws` → `127.0.0.1:8080`.
Administrator runbook (install, HTTPS, pull, backup, restart): `docs/ADMIN_VPS_DEPLOY.md`.
See also `README.md` §3 and `docs/ADR/027-reverse-proxy-tls-termination.md`.
Client deploy meta: `lotto-ws-port=""`, `lotto-ws-path="/ws"` in `public/index.html`.

## Composer Convention
Current PSR-4 mapping:
```json
{"autoload": {"psr-4": {"Lotto\\": "src/"}}}
```
All generated code must follow this mapping (root namespace `Lotto\`, see ANCHOR_RULES.md Part 21 and ANCHOR_CORE.md Naming Registry).

## Running tests

**Ubuntu VPS (authoritative):**
```bash
# Run as the service user — never as root (avoids root-owned log files)
sudo -u www-data php run_ALL_tests.php
```

Live WebSocket tests use port **18080** by default (not production 8080) and
write all logs to `/tmp` — they do not require stopping `lotto-server.service`.
Each live WS test also uses an isolated temp SQLite database (`LOTTO_DB_PATH`)
so tests do not lock or pollute production `game.db`.

**Windows dev (partial):** PHP may ship without `pdo_sqlite` enabled. Use:
```bash
php run_ALL_tests.php
```
This runner enables SQLite extensions automatically on Windows via
`lottoPhpIniArgs()` (`-d extension=php_pdo_sqlite.dll`, …). Direct
`php tests/Manual/test_login.php` does **not** inject those flags and
fails with `could not find driver` unless sqlite is enabled in `php.ini`.
Live WebSocket subprocess tests run on Windows too (FIX-15); VPS remains
authoritative for production sign-off. See README.md §9.

**Start server (dev / frontend manual testing):**
```bash
php init_db.php          # first time — creates game.db + admin user
php scripts/start_server.php start
# Windows: same commands; start_server.php loads SQLite extensions automatically
```
Plain `php server.php start` also works after the FIX-15 bootstrap (auto-loads
SQLite on Windows). Stop with `php scripts/start_server.php stop`.

**Manual reconnect QA (browser):**
- **F1 (lobby):** join a waiting room, close the tab → player is removed from
  `room_list` immediately; reopening shows lobby without stale membership.
- **F2 (in-game):** during `playing`, press **F2** to simulate a transport
  drop (WebSocket closes without logout). Within 15s the client auto-reconnects
  and `reconnect_state` restores the game screen. F2 does nothing in lobby —
  reconnect grace applies only after `start_game`.

See `docs/IMPLEMENTATION_STATUS.md` FIX-24 (F1) and FIX-26 (F2).

**Memory audit (EPIC-11.1):** enable with `LOTTO_MEMORY_AUDIT=1`.
Optional log path: `LOTTO_MEMORY_AUDIT_LOG=/path/to/file.log`.
Long-duration VPS test: `php scripts/memory_stability_runner.php`.
Analyze: `php scripts/analyze_memory_log.php`.

**Economy audit (EPIC-11.3):** enable with `LOTTO_ECONOMY_AUDIT=1`.
Optional log path: `LOTTO_ECONOMY_AUDIT_LOG=/path/to/file.log`.
Multi-scenario integrity test: `php scripts/economy_integrity_runner.php`.
Analyze/replay: `php scripts/analyze_economy_log.php [--initial=1:500,2:500]`.

**WebSocket Origin allow-list (ADR-029):** optional hardening for browser clients.
- `LOTTO_ALLOWED_ORIGINS` — comma-separated allowed `Origin` values
  (e.g. `https://your-domain.com,http://localhost:8080`).
- Unset or empty = allow all (default; preserves dev/tests).
- When set, mismatched or missing `Origin` is rejected before `hello`.

**IP account limit / trusted proxy (ADR-031):** when TLS terminates at nginx/Caddy
(ADR-027), set reverse-proxy headers (`X-Forwarded-For`, `X-Real-IP` — see
`README.md` §3.8). Optional env:
- `LOTTO_TRUSTED_PROXY_IPS` — comma-separated TCP peer IPs from which
  `X-Forwarded-For` / `X-Real-IP` are trusted (default `127.0.0.1,::1`).
  Direct WS connections (not from these peers) never trust forwarded headers.
- `LOTTO_MAX_ACCOUNTS_PER_IP` — max distinct live authenticated accounts per
  resolved client IP (default `3`, same as `Constants::MAX_ACCOUNTS_PER_IP`).
  Positive integer only; unset/empty/invalid keeps the default. Raise
  (e.g. `9999`) to effectively disable for tests, local dev, or staging.

**Important:** Do not run tests as `root` on the VPS — root-owned
`logs/*.log` files will block the `www-data` service user (see FIX-12/FIX-13).
Run tests as the same user as `lotto-server.service`.

---

## Docker deployment (alternative, new VPS)

Entry point: **`deploy/docker/`** (ADR-036). Not the same as existing production
(`lotto-server.service` / `/opt/lotto-game`) or generic systemd (`deploy/systemd/`).

This section describes an **optional, independent** container deployment for a
**fresh** Debian/Ubuntu VPS. It does **not** replace, migrate, or modify the
existing native/systemd production deployment documented above and in
`docs/ADMIN_VPS_DEPLOY.md`.

### Prerequisites

- Debian or Ubuntu VPS (operator-controlled)
- Docker Engine + Docker Compose plugin (`docker compose`)
- Git
- **Not required on the host:** PHP, Composer, SQLite, Workerman

Docker is **not** installed automatically by this repository.

### Security boundary

- Containers isolate the Lotto application from normal host filesystem/process access.
- Docker containers share the host kernel — containerization is **not** equivalent to a VM boundary.
- Privileged containers and Docker socket mounts are prohibited by the Compose template.
- `deploy/docker/remove.sh` touches only resources owned by the named Lotto instance.
- Host-level and Docker Engine security remain the operator's responsibility.

### Install

```bash
git clone https://github.com/svblab/lotto-game.git
cd lotto-game
sudo ./deploy/docker/install.sh --name lotto-01
```

Default instance name (when `--name` omitted): `default`.

Re-running install for the same `--name` is idempotent: existing data is preserved;
the container/image may be rebuilt safely.

### Admin bootstrap (AHPC, ADR-038)

On a **fresh** database, `install.sh` creates the `admin` user and promotes the
one-time password to a root-only pending file:

`/var/lib/lotto-game/<instance>/admin-bootstrap.pending` (`root:root`, `0600`).

**Пароль никогда не попадает** в stdout установки, Docker logs, `instance.env` или
Compose-конфиг. Только явная команда `read` (TTY или `--format=json`).

#### Типовой сценарий (первый запуск)

```bash
sudo ./deploy/docker/install.sh --name lotto-01
# Сообщение: credential pending — см. admin-bootstrap.sh

sudo ./deploy/docker/admin-bootstrap.sh --name lotto-01 status
sudo ./deploy/docker/admin-bootstrap.sh --name lotto-01 read          # TTY
# или: read --format=json  для CI/ansible

# Сохраните пароль во внешнем хранилище секретов, затем:
sudo ./deploy/docker/admin-bootstrap.sh --name lotto-01 acknowledge
```

После `acknowledge` файл pending удаляется; повторный `read` → exit `2`.

#### Все команды AHPC

```bash
sudo ./deploy/docker/admin-bootstrap.sh --name lotto-01 status [--format=json]
sudo ./deploy/docker/admin-bootstrap.sh --name lotto-01 read [--format=json]
sudo ./deploy/docker/admin-bootstrap.sh --name lotto-01 acknowledge
sudo ./deploy/docker/admin-bootstrap.sh --name lotto-01 reset
```

`reset` требует отсутствия pending (сначала `acknowledge`). Генерирует новый
пароль в БД и новый pending-файл; старый пароль не восстанавливается.

Non-interactive provisioning (`install.sh --non-interactive`) exits **42** with JSON
metadata (path/state only — no password). The **provisioning layer** must store the
credential in an external secret manager, then call `acknowledge`.

Re-install on an existing volume does **not** regenerate credentials or pending files.

### Multiple instances

```bash
sudo ./deploy/docker/install.sh --name lotto-01
sudo ./deploy/docker/install.sh --name lotto-02
```

Each instance has its own Compose project, container, network, volume, host port,
and metadata under `/var/lib/lotto-game/<name>/`.

### Status / troubleshooting

```bash
sudo ./deploy/docker/healthcheck.sh --name lotto-01
sudo docker compose -f deploy/docker/compose.yaml \
  --env-file /var/lib/lotto-game/lotto-01/instance.env \
  -p lotto-lotto-01 logs -f app
```

Compose project name: `lotto-<instance>` (для `--name lotto-01` → `lotto-lotto-01`).

**Docker deployment logs to stdout only**; file-based log rotation applies to native
deployments, not containers.

### Remove

```bash
sudo ./deploy/docker/remove.sh --name lotto-01
sudo ./deploy/docker/remove.sh --name lotto-01 --yes
```

### Reverse proxy

Point your reverse proxy at each instance's published loopback port (ADR-027 pattern).
See `deploy/docker/install.sh --help` for optional env flags.

**Provisioning contract (FQDN):** before install, set the VPS static hostname to your
public domain (not the provider machine name):

```bash
sudo hostnamectl set-hostname rusbingo.online   # example; use your domain
```

`install.sh` reads `hostnamectl` static hostname (not `hostname -f` short name), validates
DNS resolves, and sets `LOTTO_ALLOWED_ORIGINS=https://<fqdn>` when `--allowed-origins`
is omitted.

**Host TLS (nginx + Let's Encrypt):** after `install.sh`, run:

```bash
sudo ./deploy/docker/configure-proxy.sh --name default
```

This applies the ADR-027 nginx upstream pattern to `127.0.0.1:<host-port>` and serves
static files from the Git checkout `public/`. TLS is terminated on the host, not inside
the container.

### Docker tests

```bash
bash deploy/docker/tests/run_tests.sh
```

---

## Generic systemd deployment (multi-instance, new VPS)

Entry point: **`deploy/systemd/`** (ADR-037). **Not** the existing production deployment
(`lotto-server.service` / `/opt/lotto-game` / `www-data`) and **not** Docker
(`deploy/docker/`).

Use this path when you want one or more **isolated native systemd instances** on a
Linux VPS, each under `/opt/lotto-game-<name>/` with its own unit, user, port, and
SQLite database.

### What it is / what it is NOT

| | Generic systemd (`deploy/systemd/`) | Existing production | Docker (`deploy/docker/`) |
|---|-------------------------------------|---------------------|---------------------------|
| Path | `/opt/lotto-game-<name>/` | `/opt/lotto-game` | Container + `/var/lib/lotto-game/<name>/` |
| Unit | `lotto-game-<name>.service` | `lotto-server.service` | Compose project |
| User | `lotto-<name>` | `www-data` | container user |
| Runbook | This section + `deploy/systemd/README.md` | `docs/ADMIN_VPS_DEPLOY.md` | § Docker deployment above |

There is **no** top-level `deploy/install.sh` and **no** `--mode systemd|docker` switch.

### Prerequisites (host)

- Debian or Ubuntu VPS with **systemd**
- **root/sudo**
- **PHP 8.x** CLI with extensions: `sqlite3`, `pdo_sqlite`, `mbstring`, `json`, `pcntl` (Workerman)
- **Composer** on the host
- **rsync**
- **ss** (or `netstat`) for port checks
- Git clone of this repository (source for `install.sh` / `update.sh` rsync)

Verify before install:

```bash
cat /etc/os-release
systemctl --version
php --version
php -m | grep -E 'sqlite|pcntl|mbstring'
composer --version
rsync --version
ss --version || netstat --version
```

### New VPS quick-start

1. Clone the repository on the VPS (or copy a checkout reachable by the scripts).
2. Install OS packages: PHP CLI + sqlite extensions, Composer, rsync.
3. Choose a valid instance name (`^[a-z0-9][a-z0-9_-]{0,31}$`, not reserved).
4. Install one instance (example name `demo`):

```bash
cd /path/to/lotto-game
sudo ./deploy/systemd/install.sh demo
```

5. Verify health:

```bash
sudo ./deploy/systemd/healthcheck.sh demo
```

6. Configure firewall and/or reverse proxy if the instance port must not be public.
7. For a second instance, pick another name; ports auto-allocate from `8081–8999`
   (8080 is reserved for production).

### One-command lifecycle

| Step | Command |
|------|---------|
| Install | `sudo ./deploy/systemd/install.sh [options] [INSTANCE]` |
| Health | `sudo ./deploy/systemd/healthcheck.sh [INSTANCE]` |
| Update | `sudo ./deploy/systemd/update.sh [INSTANCE]` |
| Remove | `sudo ./deploy/systemd/remove.sh [INSTANCE]` |

Supported install options (see `./deploy/systemd/install.sh --help`):

- `--name`, `--port`, `--bind`, `--allowed-origins`, `--trusted-proxy-ips`, `--max-accounts-per-ip`, `--non-interactive`

Update and remove take the instance name as the first positional argument only.

### Admin bootstrap (AHPC, ADR-038)

Fresh install promotes the one-time admin password to:

`/opt/lotto-game-<name>/config/admin-bootstrap.pending` (`root:root`, `0600`).

```bash
sudo ./deploy/systemd/admin-bootstrap.sh --name demo status [--format=json]
sudo ./deploy/systemd/admin-bootstrap.sh --name demo read [--format=json]
sudo ./deploy/systemd/admin-bootstrap.sh --name demo acknowledge
sudo ./deploy/systemd/admin-bootstrap.sh --name demo reset
```

`install.sh --non-interactive` на новой БД завершается с exit **42** (без пароля в
выводе) — та же семантика, что у Docker.

Same semantics as Docker AHPC (`docs/ADR/038-admin-bootstrap-credential-delivery.md`).
Existing `game.db` on re-install is never modified.

### Persistent layout (per instance)

```text
/opt/lotto-game-<name>/
  app/                 application source (refreshed on install/update)
  data/game.db         SQLite database (preserved on update; deleted on remove)
  logs/                server and Workerman logs
  config/environment   instance env (preserved on update)
  config/deployment.json  metadata v1 (no secrets)
  config/admin-bootstrap.pending  one-time admin password (ADR-038; removed after acknowledge)
/var/backups/lotto-game/<name>/   instance backup dir (optional/empty until used)
/etc/systemd/system/lotto-game-<name>.service
```

Service user: `lotto-<name>` (created by installer when absent; removed on remove only if `created_user=true` in metadata).

### Port behavior

- Production port **8080** is reserved and rejected for generic instances.
- Fresh install without `--port`: first free port in **8081–8999**.
- **Update preserves** the existing port (port change not supported in Epic C).
- Conflicts with listening sockets, Docker-published ports, or other instances fail closed.

### Bind address / networking (important)

`server.php` binds Workerman to **`0.0.0.0:$LOTTO_WS_PORT`** (all interfaces). Metadata
`bind_address` (default `127.0.0.1`) documents upstream/reverse-proxy targeting only —
it does **not** currently restrict the PHP listener. Plan firewall rules or a reverse
proxy accordingly. Do not assume loopback-only exposure without verifying `server.php`.

### Operational lifecycle

```text
install   → create instance, user, unit, DB (if new), start, healthcheck
health    → unit active + WebSocket hello health (deploy/docker/healthcheck.php)
update    → stop, refresh app/, composer install, restart, healthcheck (DB/config preserved)
remove    → stop, delete instance tree, metadata, owned user when safe
```

**Update does not provide transactional rollback.** On failure the service may remain
stopped while `data/` and `config/environment` are preserved.

**Remove deletes** the instance's persistent data per B3 semantics.

### Security notes

- Production paths (`/opt/lotto-game`, `lotto-server.service`, `www-data`) are hard-protected.
- Instance names are validated; reserved names are rejected.
- Secrets are **not** stored in `deployment.json`.
- `config/environment` is mode `640`; treat it as sensitive configuration.
- Generic systemd instances are independent from Docker deployments on the same host when ports do not conflict.

### Multi-instance example

```bash
sudo ./deploy/systemd/install.sh staging-a
sudo ./deploy/systemd/install.sh staging-b
sudo ./deploy/systemd/healthcheck.sh staging-a
sudo ./deploy/systemd/healthcheck.sh staging-b
```

Each instance gets a distinct root, user, port, unit, and database.

### Tests (helper scripts)

```bash
bash deploy/systemd/tests/run_tests.sh
bash deploy/docker/tests/run_tests.sh
```

Helper tests run on Git Bash or Linux. **Full lifecycle verification on a real Linux VPS**
is documented in `docs/SYSTEMD_VPS_VERIFICATION.md` (Epic D).

For production on a single VPS today, use `docs/ADMIN_VPS_DEPLOY.md`. For containerised
fresh VPS installs, use `deploy/docker/` above.

---
