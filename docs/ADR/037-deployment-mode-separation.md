# 037 — Deployment mode separation (Docker vs systemd)

## Status

Accepted (Epic A — repository layout only)

## Context

ADR-036 added Docker Compose deployment, but lifecycle scripts initially lived at
ambiguous top-level paths under `deploy/` (`install.sh`, `remove.sh`,
`healthcheck.sh`, `tests/`). Operators could not tell from the path alone whether
a script targeted Docker or native/systemd.

The repository also has:

- **Existing production native deployment** — `/opt/lotto-game`,
  `lotto-server.service`, `www-data` (`docs/ADMIN_VPS_DEPLOY.md`).
- **Planned generic multi-instance systemd deployment** — future work under
  `deploy/systemd/` (epics B1–D).

This ADR records the **architectural directory boundary** for Epic A. It does
**not** implement the generic systemd lifecycle.

## Decision

1. **Physical separation under `deploy/`**
   - Docker-specific code: `deploy/docker/` (`install.sh`, `remove.sh`,
     `update.sh`, `healthcheck.sh`, `lib/`, `tests/`, `compose.yaml`, etc.).
   - Systemd-specific code: `deploy/systemd/` — **reserved** for future generic
     multi-instance tooling (Epic A adds `README.md` placeholder only).
   - **No** shared top-level `deploy/install.sh`, `deploy/remove.sh`,
     `deploy/update.sh`, or `deploy/healthcheck.sh`.
   - **No** primary UX such as `./deploy/install.sh --mode docker|systemd`.

2. **Three deployment concepts (documented distinctly)**
   - **Existing production native** — `/opt/lotto-game`, `lotto-server.service`,
     `www-data` — unchanged; documented in `docs/ADMIN_VPS_DEPLOY.md`.
   - **Future generic systemd multi-instance** — lifecycle to be implemented under
     `deploy/systemd/` in epics B1–D.
   - **Docker Compose multi-instance** — ADR-036 behaviour preserved after
     relocation to `deploy/docker/`.

3. **Epic A scope limit**
   - Relocate existing Docker deployment unchanged in behaviour.
   - Create `deploy/systemd/` boundary with explicit deferral notice.
   - Do **not** add generic systemd installers, removers, updaters, healthchecks,
     service templates, or production guards in Epic A.

4. **No shared deployment abstraction**
   - Do not introduce `deploy/lib/` shared between Docker and systemd lifecycles.
   - Docker helpers live under `deploy/docker/lib/` only.

## Consequences

**Positive:** Self-explanatory repo layout; no runtime-ambiguous entry points;
Docker and systemd lifecycles can evolve independently.

**Negative:** Some duplication may appear when systemd tooling is implemented
later — accepted in favour of explicit separation.

**Compatibility:** Epic A is relocation/documentation only — no WebSocket
protocol, business-logic, or existing production deployment changes.

**Follow-up epics:** B1 (identity/safety), B2 (install), B3 (remove), C
(update/health/limits), D (docs/tests) under `deploy/systemd/`.
