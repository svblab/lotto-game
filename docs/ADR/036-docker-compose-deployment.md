# 036 — Docker Compose deployment (alternative VPS path)

## Status

Accepted

## Context

`ANCHOR_CORE.md` Part 1 documents a single native deployment model: Ubuntu 22.04,
systemd (`lotto-server.service`), one Workerman worker, SQLite on the host, reverse
proxy TLS per ADR-027. That production path remains authoritative for the existing
live VPS and is documented in `docs/LOCAL_ENVIRONMENT.md` and `docs/ADMIN_VPS_DEPLOY.md`.

Operators may also want to run `svblab/lotto-game` on a **fresh** Debian/Ubuntu VPS
using containers only — without installing PHP, Composer, or Workerman on the host.
Multiple isolated instances on one shared VPS must coexist without cross-instance
cleanup. Removal of one instance must leave zero Lotto-owned Docker artifacts for
that instance while never touching unrelated host or Docker resources.

This ADR records the **additional** deployment boundary. It does not migrate,
replace, or modify the existing native/systemd production instance.

## Decision

1. **Docker Compose is a supported, optional deployment model** alongside the native
   systemd path. Application code stays the same Workerman + SQLite architecture;
   only packaging and lifecycle tooling differ.

2. **Instance lifecycle**
   - Primary commands: `sudo ./deploy/install.sh` and `sudo ./deploy/remove.sh`.
   - Instance name: `--name` flag; default fixed name `default` (deterministic, not
     auto-generated).
   - Compose project: `lotto-<instance>`; container: `lotto-<instance>-app`; network:
     `lotto-<instance>-net`; volume: `lotto-<instance>-data`; image tag:
     `lotto-game:<instance>` (instance-specific tag so removing one instance can
     delete its image without breaking others).
   - Idempotency: existing instance detected by deployment metadata under
     `/var/lib/lotto-game/<instance>/` and/or its named volume. Re-install rebuilds
     the container/image but never re-runs `init_db.php` against an existing volume.

3. **SQLite persistence** lives on a **named Docker volume** mounted at
   `/app/data/game.db` (+ WAL/SHM siblings). `LOTTO_DB_PATH` (already honoured by
   `Database.php`) is set in the container. `init_db.php` was extended to honour
   `LOTTO_DB_PATH` for parity with runtime.

4. **Host port allocation**: default bind `127.0.0.1:<host-port>:8080` for reverse
   proxy upstream per ADR-027 (multi-instance = one upstream port per instance).
   If `--port` is omitted on first install, the installer scans for a free TCP port
   (8080–8999). Limits (`--mem-limit`, `--cpu-limit`, `--pids-limit`) are overridable.

5. **Zero-artifact removal**: `remove.sh` stops the Compose project, deletes the
   instance volume, network, instance-specific image (unless another instance still
   references it), and `/var/lib/lotto-game/<instance>/` metadata. No
   `docker system prune` or global prune commands.

6. **Container logging (stdout-only)**: in Docker mode,
   `LOTTO_SERVER_LOG=php://stdout` and `LOTTO_WORKERMAN_LOG_FILE=php://stdout`.
   `Logger` accepts stream targets; file-based 30-day rotation described for native
   systemd deployment does not apply (nothing on disk to rotate). Admin bootstrap
   credentials from `init_db.php` are written once to a `0600` file on the data
   volume (`LOTTO_ADMIN_BOOTSTRAP_FILE`), read by `install.sh` on the host terminal,
   then deleted — never emitted through the container stdout stream captured by
   `docker logs`.

7. **Healthcheck**: minimal standalone `deploy/docker/healthcheck.php` copied into
   the runtime image performs a real RFC6455 WebSocket handshake against
   `127.0.0.1:<LOTTO_WS_PORT>` and verifies `{"type":"hello","protocol_version":1}`.

8. **Docker is a documented host prerequisite** — not auto-installed by the
   repository (auto-install would be a separate security/architecture decision).

9. **Container hardening baseline**: non-root UID 1000, `read_only: true`, writable
   data volume only (+ PID file on data volume), `cap_drop: [ALL]`,
   `security_opt: [no-new-privileges:true]`, `tmpfs: /tmp`, configurable resource
   limits, no host networking, no privileged mode, no Docker socket mounts.

## Consequences

**Positive**

- One-command install/remove on any Debian/Ubuntu VPS with Docker.
- Multiple isolated instances on one host; scoped cleanup.
- Native production deployment docs and behaviour unchanged.

**Negative**

- Admin log viewer (`admin_get_logs`) returns empty in stdout-only container mode.
- Operators must provide reverse proxy/TLS externally (same as ADR-027 pattern).
- Slightly larger operational surface (Docker Engine security is operator responsibility).

**Compatibility:** Deployment/bootstrap only — no `ANCHOR_PROTOCOL.md`, economy, or
state-machine changes.
