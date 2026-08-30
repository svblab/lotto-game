# Generic systemd deployment

Multi-instance native/systemd deployment tooling lives here (ADR-037).

Authoritative operator guide: **`docs/LOCAL_ENVIRONMENT.md`** § Generic systemd deployment.  
Live VPS verification checklist: **`docs/SYSTEMD_VPS_VERIFICATION.md`**.

## Status

| Epic | Scope | Status |
|------|-------|--------|
| **B1** | Identity, layout, metadata, production guards | **DONE** |
| **B2** | Installation | **DONE** |
| **B3** | Removal | **DONE** |
| **C** | Update / operational lifecycle | **DONE** |
| **D** | VPS verification + final docs + operational UX | **PARTIAL** — docs/UX done; live VPS checklist **NOT RUN** |

## Deployment models (do not confuse)

| | Generic systemd | Existing production | Docker |
|---|-----------------|---------------------|--------|
| Entry | `deploy/systemd/` | `docs/ADMIN_VPS_DEPLOY.md` | `deploy/docker/` |
| App root | `/opt/lotto-game-<name>/` | `/opt/lotto-game` | container volume |
| Unit | `lotto-game-<name>.service` | `lotto-server.service` | Compose project |
| User | `lotto-<name>` | `www-data` | container user |

There is **no** `deploy/install.sh` and **no** `--mode systemd|docker`.

## One-command lifecycle (D3)

Each operation is a single namespaced script under `deploy/systemd/`:

```bash
sudo ./deploy/systemd/install.sh demo      # create instance
sudo ./deploy/systemd/healthcheck.sh demo    # verify unit + WebSocket health
sudo ./deploy/systemd/update.sh demo         # refresh app; preserve data/config
sudo ./deploy/systemd/remove.sh demo        # stop and remove instance
```

Install options: `--name`, `--port`, `--bind`, `--allowed-origins`, `--trusted-proxy-ips`, `--max-accounts-per-ip` (see `install.sh --help`).

Commands print instance, unit, user, paths, and port before mutating state so operators can confirm they are **not** targeting production or Docker.

## Install (B2)

```bash
sudo ./deploy/systemd/install.sh [options] [INSTANCE]
```

Requires Linux, root, systemd, PHP + Composer + rsync on the host. Port defaults to `8081–8999` (8080 reserved). Idempotent reinstall preserves `data/game.db`.

## Update (C)

```bash
sudo ./deploy/systemd/update.sh [INSTANCE]
```

Preserves `data/`, `config/environment`, port, and user. No transactional rollback. Instance lock: `/var/lock/lotto-game-<name>.lock`.

## Remove (B3)

```bash
sudo ./deploy/systemd/remove.sh [INSTANCE]
```

Metadata-validated, fail-closed removal with zero-artifact verification. Idempotent when already absent.

## Healthcheck

```bash
sudo ./deploy/systemd/healthcheck.sh [INSTANCE]
```

Requires unit active + WebSocket health via `deploy/docker/healthcheck.php`.

## Tests

```bash
bash deploy/systemd/tests/run_tests.sh
```

Full VPS lifecycle: see **`docs/SYSTEMD_VPS_VERIFICATION.md`**.
