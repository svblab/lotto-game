# Generic systemd deployment

Multi-instance native/systemd deployment tooling lives here (ADR-037).

## Status

| Epic | Scope | Status |
|------|-------|--------|
| **B1** | Identity, layout, metadata, production guards | **DONE** — `lib/common.sh`, `tests/run_tests.sh` |
| **B2** | Installation | **DONE** — `install.sh`, `healthcheck.sh`, `service.template` |
| **B3** | Removal | **DONE** — `remove.sh` |
| **C** | Update / operational lifecycle | **DONE** — `update.sh` |
| **D** | Documentation / deployment tests | **NOT STARTED** |

Epic B2 installs, Epic C updates, and Epic B3 removes generic systemd instances under `/opt/lotto-game-<name>/`. Full VPS documentation and integration tests are deferred to D.

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

## Update (C)

Run on the same Linux host as root:

```bash
sudo ./deploy/systemd/update.sh [INSTANCE]
```

Examples:

```bash
sudo ./deploy/systemd/update.sh demo
sudo ./deploy/systemd/update.sh lotto-01
```

Update refreshes a managed instance safely:

- Requires valid metadata and `config/environment`
- Stops service → rsync `app/` from repo → `composer install` (no `composer update`)
- Preserves `data/`, `config/environment`, `config/deployment.json` contents, existing port, and service user
- Re-renders systemd unit only when the template output changed; `daemon-reload` only then
- Restarts service, runs WebSocket healthcheck, sets metadata `updated_at` only after success
- Instance lock at `/var/lock/lotto-game-<name>.lock` prevents concurrent updates of the same instance
- On failure: service left stopped, database and configuration preserved, metadata not finalized

Port changes are not supported in C; updates preserve the existing metadata port.

Production and Docker deployments are never modified.

## Remove (B3)

Run on the same Linux host as root:

```bash
sudo ./deploy/systemd/remove.sh [INSTANCE]
```

Examples:

```bash
sudo ./deploy/systemd/remove.sh demo
sudo ./deploy/systemd/remove.sh lotto-01
```

Removal is instance-scoped and ownership-scoped:

- Requires valid metadata (`config/deployment.json`) to establish ownership before destructive steps
- Validates metadata paths against the deterministic B1 layout (tampered paths fail closed)
- Canonicalizes paths and rejects symlink escapes before deleting files
- Stops/disables/removes only the expected `lotto-game-<name>.service` unit
- Removes `/opt/lotto-game-<name>/`, instance backup dir, and metadata
- Removes `lotto-<name>` only when `created_user=true` and no other instance claims the user
- Idempotent: re-running on an already-absent instance succeeds
- Missing metadata with residual resources fails safely (no blind deletion)

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

`config/deployment.json` — schema version 1, no secrets. Tracks paths, unit, user, port, `created_user`, `created_at`, and `updated_at` (after successful updates).

## Not the same as existing production

| | Production | Generic systemd |
|---|------------|-----------------|
| Path | `/opt/lotto-game` | `/opt/lotto-game-<name>/` |
| Unit | `lotto-server.service` | `lotto-game-<name>.service` |
| User | `www-data` | `lotto-<name>` |
| Runbook | `docs/ADMIN_VPS_DEPLOY.md` | This README + lifecycle scripts |

## Entry points

| Script | Epic | Status |
|--------|------|--------|
| `install.sh` | B2 | **Available** |
| `update.sh` | C | **Available** |
| `remove.sh` | B3 | **Available** |
| `healthcheck.sh` | B2/C (verification) | **Available** |

## Tests

```bash
bash deploy/systemd/tests/run_tests.sh
```

Helper/unit tests run on Git Bash or Linux. Full install/update/remove integration requires a Linux VPS with root and systemd.
