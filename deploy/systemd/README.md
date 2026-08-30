# Generic systemd deployment

Multi-instance native/systemd deployment tooling lives here (ADR-037).

## Status

| Epic | Scope | Status |
|------|-------|--------|
| **B1** | Identity, layout, metadata, production guards | **DONE** — `lib/common.sh`, `tests/run_tests.sh` |
| **B2** | Installation | **NOT STARTED** |
| **B3** | Removal | **NOT STARTED** |
| **C** | Update / healthcheck / resource limits | **NOT STARTED** |
| **D** | Documentation / deployment tests | **NOT STARTED** |

Epic B1 provides validation and identity helpers only. **Generic systemd deployment is not installable yet.**

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

| | Production | Generic systemd (future) |
|---|------------|--------------------------|
| Path | `/opt/lotto-game` | `/opt/lotto-game-<name>/` |
| Unit | `lotto-server.service` | `lotto-game-<name>.service` |
| User | `www-data` | `lotto-<name>` |
| Runbook | `docs/ADMIN_VPS_DEPLOY.md` | B2+ (not available) |

## Planned entry points (future — B2/C)

```bash
sudo ./deploy/systemd/install.sh      # B2
sudo ./deploy/systemd/remove.sh       # B3
sudo ./deploy/systemd/update.sh       # C
sudo ./deploy/systemd/healthcheck.sh # C
```

## Tests

```bash
bash deploy/systemd/tests/run_tests.sh
```
