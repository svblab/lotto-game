# Generic systemd deployment (reserved)

This directory is reserved for **future generic multi-instance native/systemd**
deployment tooling (ADR-037).

## Status

**Implementation intentionally deferred** to later roadmap epics:

- **B1** — instance identity and safety foundation
- **B2** — installation
- **B3** — removal and zero-artifact cleanup
- **C** — update, healthcheck, resource limits
- **D** — documentation and deployment tests

Epic A (ADR-037) established the repository boundary only. No lifecycle scripts
exist here yet.

## Not the same as existing production

The **existing production** deployment is unchanged and documented separately:

- Path: `/opt/lotto-game`
- Unit: `lotto-server.service`
- User: `www-data`
- Runbook: `docs/ADMIN_VPS_DEPLOY.md`

Do not use this directory for production operations until B1+ is implemented.

## Planned entry points (future)

```bash
sudo ./deploy/systemd/install.sh
sudo ./deploy/systemd/remove.sh
sudo ./deploy/systemd/update.sh
sudo ./deploy/systemd/healthcheck.sh
```

These commands are **not available** until the corresponding epics land.
