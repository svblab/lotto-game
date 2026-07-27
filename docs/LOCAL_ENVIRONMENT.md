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
This runner enables SQLite extensions automatically and skips 8 live-WebSocket
subprocess tests (Workerman requires Linux). Full sign-off requires VPS run.

See `docs/PHASE_11_REPORT.md` for audit status.

**Memory audit (EPIC-11.1):** enable with `LOTTO_MEMORY_AUDIT=1`.
Optional log path: `LOTTO_MEMORY_AUDIT_LOG=/path/to/file.log`.
Long-duration VPS test: `php scripts/memory_stability_runner.php`.
Analyze: `php scripts/analyze_memory_log.php`.

**Important:** Do not run tests as `root` on the VPS — root-owned
`logs/*.log` files will block the `www-data` service user (see FIX-12/FIX-13).
Run tests as the same user as `lotto-server.service`.
