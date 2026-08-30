# Generic systemd deployment

Multi-instance native/systemd deployment tooling lives here (ADR-037).

## Status

| Epic | Scope | Status |
|------|-------|--------|
| **B1** | Identity, layout, metadata, production guards | **DONE** — `lib/common.sh`, `tests/run_tests.sh` |
| **B2** | Installation | **DONE** — `install.sh`, `healthcheck.sh`, `service.template` |
| **B3** | Removal | **NOT STARTED** |
| **C** | Update / healthcheck / resource limits | **NOT STARTED** |
| **D** | Documentation / deployment tests | **NOT STARTED** |

Epic B2 installs a generic systemd instance under `/opt/lotto-game-<name>/`. Removal, update, and full operational lifecycle are deferred to B3/C.

## Install (B2)

Run on a Linux host with root, systemd, PHP, Composer, and rsync:

```bash
sudo ./deploy/systemd/install.sh [options] [INSTANCE]

# Options: --name, --port, --bind, --allowed-origins, --trusted-proxy-ips, --max-accounts-per-ip
```

Examples:

```bash
sudo ./deploy/systemd/install.sh demo
sudo ./deploy/systemd/install.sh --name lotto-01 --port 8099
```

Requirements:

- Valid instance name: `^[a-z0-9][a-z0-9_-]{0,31}$` (not reserved)
- Source repo path defaults to the directory containing `deploy/systemd/`
- Port defaults to the first free port in `8081–8999` (8080 is reserved for production)
- Creates dedicated user `lotto-<name>`, syncs app source, runs Composer, writes env + unit, initializes DB if new, enables and starts the unit, runs health verification

Idempotent reinstall: re-running install for the same instance refreshes app source, env, and unit; existing `data/game.db` is preserved.

## B1 foundation (`lib/common.sh`)

### Instance naming

- Pattern: `^[a-z0-9][a-z0-9_-]{0,31}$` (1–32 chars, lowercase)
- Reserved: `production`, `www-data`, `lotto-server`, `lotto-server.service`, `server`

### Identity mapping (deterministic)

```text
instance "demo"
  → root:  /opt/lotto-game-demo/
  → unit:  lotto-game-demo.service
  → user:  lotto-demo
  → paths: app/, data/, logs/, config/
  → backup: /var/backups/lotto-game/demo/
```

### Production protection

Hard-fail before privileged operations on:

- `/opt/lotto-game` (exact production app root)
- `lotto-server.service`
- `www-data`
- Port `8080` for generic instances (production default)

### Metadata

`config/deployment.json` — schema version 1, no secrets. Tracks paths, unit, user, port, and whether the service user was created by the installer (for B3 ownership).

## Not the same as existing production

| | Production | Generic systemd |
|---|------------|-----------------|
| Path | `/opt/lotto-game` | `/opt/lotto-game-<name>/` |
| Unit | `lotto-server.service` | `lotto-game-<name>.service` |
| User | `www-data` | `lotto-<name>` |
| Runbook | `docs/ADMIN_VPS_DEPLOY.md` | This README + `install.sh` |

## Entry points

| Script | Epic | Status |
|--------|------|--------|
| `install.sh` | B2 | **Available** |
| `healthcheck.sh` | B2 (install verification) | **Available** |
| `remove.sh` | B3 | Not implemented |
| `update.sh` | C | Not implemented |

## Tests

```bash
bash deploy/systemd/tests/run_tests.sh
```

Helper/unit tests run on Git Bash or Linux. Full install integration requires a Linux VPS with root and systemd.
