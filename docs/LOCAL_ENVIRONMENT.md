# Local Environment

Host: Ubuntu 24.04
PHP: 8.4.21
Composer: 2.9.8
SQLite: 3.45.1
Workerman: installed via Composer

Repository: https://github.com/svblab/lotto-game
Deployment path: /opt/lotto-game
Service: lotto-server.service
WebSocket: ws://localhost:8080

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
This runner enables SQLite extensions automatically on Windows.
Live WebSocket subprocess tests run on Windows too (FIX-15); VPS remains
authoritative for production sign-off.

**Start server (dev / frontend manual testing):**
```bash
php init_db.php          # first time — creates game.db + admin user
php scripts/start_server.php start
# Windows: same commands; start_server.php loads SQLite extensions automatically
```
Plain `php server.php start` also works after the FIX-15 bootstrap (auto-loads
SQLite on Windows). Stop with `php scripts/start_server.php stop`.

See `docs/PHASE_11_REPORT.md` for audit status.

**Memory audit (EPIC-11.1):** enable with `LOTTO_MEMORY_AUDIT=1`.
Optional log path: `LOTTO_MEMORY_AUDIT_LOG=/path/to/file.log`.
Long-duration VPS test: `php scripts/memory_stability_runner.php`.
Analyze: `php scripts/analyze_memory_log.php`.

**Economy audit (EPIC-11.3):** enable with `LOTTO_ECONOMY_AUDIT=1`.
Optional log path: `LOTTO_ECONOMY_AUDIT_LOG=/path/to/file.log`.
Multi-scenario integrity test: `php scripts/economy_integrity_runner.php`.
Analyze/replay: `php scripts/analyze_economy_log.php [--initial=1:500,2:500]`.

**Important:** Do not run tests as `root` on the VPS — root-owned
`logs/*.log` files will block the `www-data` service user (see FIX-12/FIX-13).
Run tests as the same user as `lotto-server.service`.
