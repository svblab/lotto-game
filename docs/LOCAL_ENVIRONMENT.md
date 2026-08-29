# Local Environment

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

This section describes an **optional, independent** container deployment for a
**fresh** Debian/Ubuntu VPS. It does **not** replace, migrate, or modify the
existing native/systemd production deployment documented above and in
`docs/ADMIN_VPS_DEPLOY.md`. ADR-036 records the architecture decision.

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
- `remove.sh` touches only resources owned by the named Lotto instance — never unrelated containers, volumes, networks, or host services.
- Host-level and Docker Engine security remain the operator's responsibility.

### Install

```bash
git clone https://github.com/svblab/lotto-game.git
cd lotto-game
sudo ./deploy/install.sh --name lotto-01
```

Default instance name (when `--name` omitted): `default`.

The installer builds a local image from the checkout, creates an instance-specific
named volume for SQLite (`lotto-<name>-data`), binds
`127.0.0.1:<port>:8080` by default, runs a WebSocket hello healthcheck, and prints
connection details. On a **new** database it prints the one-time admin bootstrap
password to the **installer's terminal only** (not via `docker logs`).

Re-running install for the same `--name` is idempotent: existing data is preserved;
the container/image may be rebuilt safely.

### Multiple instances

```bash
sudo ./deploy/install.sh --name lotto-01
sudo ./deploy/install.sh --name lotto-02
```

Each instance has its own Compose project, container, network, volume, host port,
and metadata under `/var/lib/lotto-game/<name>/`.

Resource limits are configurable: `--mem-limit`, `--cpu-limit`, `--pids-limit`.

### Status / troubleshooting

```bash
# Logs (stdout — no file rotation in this mode)
sudo docker compose -f deploy/docker/compose.yaml \
  --env-file /var/lib/lotto-game/lotto-01/instance.env \
  -p lotto-lotto-01 logs -f app

# Healthcheck (real WS hello handshake)
sudo ./deploy/healthcheck.sh --name lotto-01

# Container state
sudo docker compose -f deploy/docker/compose.yaml \
  --env-file /var/lib/lotto-game/lotto-01/instance.env \
  -p lotto-lotto-01 ps
```

Native/systemd deployments use file-based logs under `logs/server.log` with
logrotate (see `docs/ADMIN_VPS_DEPLOY.md`). **Docker deployment logs to stdout
only**; the 30-day file rotation described for native deployment does not apply here.

### Remove

```bash
sudo ./deploy/remove.sh --name lotto-01
sudo ./deploy/remove.sh --name lotto-01 --yes   # non-interactive
```

Removes only that instance's container, volume, network, image tag, and metadata.
Does not remove Docker Engine or unrelated resources. Does not run global
`docker system prune`.

### Reverse proxy

Expose each instance on loopback and point your existing nginx/Caddy/Traefik upstream
at the instance port — same TLS termination pattern as ADR-027, one upstream per
instance:

```nginx
# Example: lotto-01 published on 127.0.0.1:8080, lotto-02 on 127.0.0.1:8081
location /ws {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_read_timeout 86400;
}
```

Set client meta in `public/index.html` (`lotto-ws-port`, `lotto-ws-path`) to match
your proxy. Optional env vars (`LOTTO_ALLOWED_ORIGINS`, `LOTTO_TRUSTED_PROXY_IPS`,
`LOTTO_MAX_ACCOUNTS_PER_IP`) can be passed via install flags — see
`deploy/install.sh --help`.

### Deployment tests

```bash
bash deploy/tests/run_tests.sh
```

Static checks always run; live Docker install/remove tests run only when Docker and
passwordless `sudo` are available.
