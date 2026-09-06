# 039 — Docker V1 as separate distribution target (ephemeral container state)

## Status

Accepted (documentation — validation roadmap only; **no implementation** in this ADR)

## Context

NLD V1.0 (Native Linux Deployment) is **released** with tag `v1.0` pointing to
application SHA `508cc280704ed72cc3e85df03e57bd6fb42d24ee`. Its release contract
is [`docs/RELEASE_CONTRACT_V1.md`](../RELEASE_CONTRACT_V1.md); canonical
production is native systemd + nginx + `/opt/lotto-game`.

ADR-036 introduced Docker Compose deployment with **named Docker volumes** for
SQLite persistence across container recreation. That model served test/staging
and multi-instance coexistence goals under the NLD V1.0 roadmap (where Docker was
explicitly **not** canonical production).

Operators now require Docker as a **separate, first-class installation /
distribution target** with its own validation cycle and release identity — without
forking application logic and without revising NLD V1.0.

## Decision

1. **Docker V1 is a separate distribution target** for the same application
   release (`v1.0`). It has its own validation roadmap
   ([`docs/ROADMAP_DOCKER_V1.md`](../ROADMAP_DOCKER_V1.md)), evidence index
   ([`docs/DOCKER_V1_EVIDENCE.md`](../DOCKER_V1_EVIDENCE.md)), and Human release
   gate (**H-D1**). It does **not** modify NLD `v1.0` or
   `RELEASE_CONTRACT_V1.md`.

2. **One application → multiple distribution targets.** The Docker project layer
   (`deploy/docker/` and related packaging) handles installation, orchestration,
   configuration, lifecycle, backup/restore procedures, and Docker-specific
   documentation. Application business logic remains in the main repository
   release artifact resolved per roadmap **D1.1**.

3. **Ephemeral container-local application state (Docker V1 contract).**
   - SQLite `game.db` lives **inside the container filesystem**.
   - **No** host-mounted `game.db`, persistent host game-data directory, named
     Docker volume for application state, or bind mount for persistent game data.
   - Persistence within Docker V1 means: state survives **restart of the same
     container**; state is **lost** when the container is removed unless restored
     from an explicit **exportable backup artifact** (not a persistent volume).
   - After uninstall (Zero Residue), the host must not retain game-server data.

4. **Supported topology:** `1 container → 1 Workerman worker → 1 SQLite database`.
   Shared SQLite across containers and multi-worker Docker production topology are
   **unsupported** in Docker V1.

5. **ADR-036 scope clarification.** ADR-036 remains historical documentation of the
   prior Docker implementation (named volumes, multi-instance metadata under
   `/var/lib/lotto-game/<instance>/`). For the **Docker V1 validation track**,
   ADR-036 §3 (named volume SQLite persistence) is **superseded** by this ADR and
   the roadmap storage contract. Remediation of `deploy/docker/` to match Docker
   V1 is **out of scope** for this ADR — tracked in roadmap **D2** audit and
   subsequent implementation epics.

6. **No application fork.** Docker build must consume an **immutable** application
   release artifact tied to `v1.0` / `508cc28`, not a silently moving `main` branch.

7. **Immutable Release model (HD-D1, accepted 2026-09-06).** Each Docker release
   corresponds **exactly** to one immutable application release:

   ```text
   Application release (tag + SHA)
       → immutable release artifact
       → SHA256 verification
       → Docker release (separate identity)
   ```

   - Docker release identity must unambiguously identify the application release
     it was built from.
   - An existing Docker installation **must not** automatically receive changes
     from `main`, latest source, or any other mutable source when a new application
     release appears.
   - A new application release produces a **separate** Docker release; coexistence
     of Docker Release N on Application vN while Application vN+1 exists is
     expected and correct.
   - Upgrade of an existing installation to a newer Docker release is a **future
     operation** — not defined by HD-D1.

   HD-D1 does **not** decide: artifact storage location, registry, image naming,
   Docker release tag naming, upgrade command/procedure, backup-before-upgrade, or
   SQLite migration mechanism (see roadmap Human decision register).

   Because Docker V1 stores application state inside the container filesystem,
   any future upgrade between immutable Docker releases must account for game
   state preservation/restoration (D6 Backup/Restore scope); upgrade is **not**
   designed or implemented as part of HD-D1.

## Consequences

**Positive**

- Clear separation between NLD production release and Docker distribution release.
- Reproducible Docker validation against fixed application SHA.
- Explicit Zero Residue / uninstall contract for container deployments.
- No changes to NLD V1.0 gates, tag, or native production architecture.

**Negative**

- Existing `deploy/docker/compose.yaml` (named volume `data:/app/data`) **conflicts**
  with Docker V1 contract until remediated — must be caught in D2 audit.
- Container removal without backup **destroys** game data — operators must use
  documented export/import (D6), not rely on host volumes.
- Docker V1 does not inherit NLD G1–G11 PASS; full D0–D12 cycle required.

**Compatibility:** Deployment/distribution only — no `ANCHOR_PROTOCOL.md`,
economy, timers, state machine, or naming changes. Application runtime behaviour
unchanged; only packaging and persistence **model** for Docker track differ from
ADR-036.

**Follow-up:** Execute roadmap D0–D12; implement Docker project changes only after
D2.1 installation contract freeze.

## References

- [`docs/ROADMAP_DOCKER_V1.md`](../ROADMAP_DOCKER_V1.md)
- [`docs/DOCKER_V1_EVIDENCE.md`](../DOCKER_V1_EVIDENCE.md)
- [`docs/RELEASE_CONTRACT_V1.md`](../RELEASE_CONTRACT_V1.md)
- [`docs/ADR/036-docker-compose-deployment.md`](036-docker-compose-deployment.md)
