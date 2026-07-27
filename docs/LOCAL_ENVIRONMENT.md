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
bash run_ALL_tests.sh
# or
php run_ALL_tests.php
```

**Windows dev (partial):** PHP may ship without `pdo_sqlite` enabled. Use:
```bash
php run_ALL_tests.php
```
This runner enables SQLite extensions automatically and skips 8 live-WebSocket
subprocess tests (Workerman requires Linux). Full sign-off requires VPS run.

See `docs/PHASE_11_REPORT.md` for audit status.
