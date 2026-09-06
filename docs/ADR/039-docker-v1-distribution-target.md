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
   release artifact tied to `v1.0` /
   `508cc280704ed72cc3e85df03e57bd6fb42d24ee`, not a silently moving `main`
   branch, latest source, mutable source archive, or floating application version.

7. **Immutable Release model (HD-D1, decided 2026-09-06).** Each Docker release
   corresponds **exactly** to one immutable application release:

   ```text
   Application release (tag + full SHA)
       → immutable release artifact
       → SHA256 verification
       → Docker release (separate identity)
   ```

   - Release metadata must preserve provenance: application version, **full
     40-character Git SHA**, immutable artifact reference, artifact SHA256 when
     applicable.
   - Docker version numbering may differ from application version (HD-D3).
   - An existing Docker installation **must not** automatically receive changes
     from `main`, latest source, mutable source archive, or floating application
     version when a new application release appears.
   - A new application release produces a **separate** Docker release.
   - Upgrade of an existing installation is a **future operation** — not
     defined by HD-D1.

   HD-D1 does **not** decide: artifact hosting/download, registry, image naming, release
   tag naming, upgrade implementation.

8. **Docker release versioning (HD-D3, decided 2026-09-06).**
   - Docker release version and application version **may differ**.
   - Docker release metadata **must** explicitly state application version.
   - Minimum traceability: `Docker Release → Application Version → Full Git SHA`.
   - Versioning schemes that hide the underlying application version are
     **forbidden**.

9. **Registry-independent contract (HD-D4-A, decided 2026-09-06).**
   - Docker images must remain OCI/container-image **portable**.
   - Docker V1 contract must **not** depend on one registry's proprietary features.
   - Production evidence must use **immutable image digest** when an image is used.
   - Specific OCI registry is **not chosen** — Docker Hub **not selected**.
   - Architecture must **not** block future publication via Docker Hub.
   - Pre-built **OCI image distribution is not a Docker V1 prerequisite**.
   - HD-D4-A does **not** decide: OCI registry name, repository name, image tag, credentials.

10. **GitHub Releases distribution channel (HD-D4 V1, decided 2026-09-06).**
    - **GitHub Releases** is the **official distribution channel** for Docker V1
      immutable application release archives.
    - Target flow: Application Release → GitHub Release → immutable release archive
      + trusted release metadata/SHA256 → Docker installer → SHA256 verification →
      `docker build` → RUSBINGO container.
    - Each Docker-installable archive is bound to exactly one application release.
    - Distribution identity preserves: application version, full Git SHA, artifact
      filename, artifact SHA256.
    - SHA256 verification is a **mandatory security boundary**. A GitHub Release
      asset does **not** replace the trusted expected SHA256 (e.g.
      `deploy/docker/release-manifests/v1.0.env`).
    - Future installer must: acquire archive → verify expected SHA256 → extract →
      build (**policy only — download not implemented**).
    - Installer must **not** obtain mutable `main` / Git checkout / floating
      `latest` source. Docker installation must **not** depend on Git checkout.
    - Docker **runtime** does **not** depend on GitHub Releases after installation.
    - The distribution channel is **not** part of RUSBINGO runtime architecture.
    - **Replaceability:** GitHub Releases is the V1 distribution **implementation**,
      not a permanent runtime dependency. A future HTTPS/CDN/object-storage channel
      may replace it if the contract holds: immutable artifact + trusted metadata
      + SHA256 verification + application provenance. No multi-provider abstraction
      in V1.
    - Baseline `v1.0` reference: version `v1.0`, Git SHA
      `508cc280704ed72cc3e85df03e57bd6fb42d24ee`, archive SHA256
      `780bb0ea9157a326908afee593f3f7acbbf1c043903094c2bbd7072e4eb166a8`.
    - HD-D4 V1 does **not** implement: GitHub Actions pipeline, Release publication,
      installer HTTP download, OCI registry infrastructure.

11. **Supported OS targets (HD-D7, approved 2026-09-06).**
    - **Officially certified minimum:** Ubuntu 22.04 LTS, Ubuntu 24.04 LTS,
      Debian 12.
    - Other Linux distributions may be **compatible** if they support required
      Docker Engine, Compose mechanism, and system requirements — but are **not
      officially certified** until validation matrix PASS.
    - Do **not** claim «any Linux is supported».
    - **Certification/release floors (approved 2026-09-06):** Docker Engine
      **≥ 24.0**; Docker Compose **V2 plugin** (`docker compose`); minimum VPS
      **1 vCPU**, **1 GiB RAM**, **5 GiB** free disk. Installer enforcement/pinning
      is separate implementation work (D2.1 / D3).

12. **Installer-first installation model (HD-D8, decided 2026-09-06).**
    - Goal: ordinary user installs game server on clean supported Linux VPS with
      maximum automation via RUSBINGO installer.
    - Docker Engine is **not** a user prerequisite — installer installs it if
      absent.
    - Installer must perform OS check, prerequisites, Docker/Compose setup,
      domain resolution, verified release archive acquisition from **GitHub Releases**
      (**HD-D4 V1**), provenance verification (HD-D9), `docker build`, container
      start, post-install verification, and present server address.
    - Domain resolution (host installer): `RUSBINGO_DOMAIN` → hostname →
      interactive prompt; no in-container interactive config.
    - **Idempotency** (future): re-run must not accidentally create second server
      or independent application state.
    - HD-D8 is **policy only** — not implemented in this ADR.

13. **HIGH vulnerability policy (HD-D2, approved 2026-09-06).**
    - CRITICAL vulnerabilities: **0 allowed** — any CRITICAL blocks release.
    - HIGH: **no numeric count cap**; every HIGH requires **individual documented
      technical disposition** proving non-exploitability / non-applicability to
      supported runtime.
    - Any **exploitable or unresolved** HIGH blocks release.
    - Generic «HIGH not exploitable» without per-CVE evidence is **insufficient**.
    - Numeric HIGH count threshold: **waived / not applicable**.

14. **Canonical release archive input (HD-D9, decided 2026-09-06; remediation approved 2026-09-06).**
    - Docker V1 uses the **single immutable application release artifact** (release
      archive — e.g. `.tar` / `.tar.gz` / `.rar` style) as the canonical input for
      Docker installation.
    - Target flow: Application Release → GitHub Release (**HD-D4 V1**) → immutable
      release archive → SHA256 verification → Docker installer → `docker build` →
      RUSBINGO container.
    - Future installer: obtain artifact from distribution channel → verify SHA256 →
      use as build input → build image → start container (**policy only — not
      implemented**).
    - A separate pre-built **OCI image distribution pipeline is not a prerequisite**
      for Docker V1. OCI distribution may be evaluated later based on evidence from
      the first Docker installation/release cycle — **not permanently rejected**.
    - HD-D9 does **not** decide: exact archive filename/format policy, installer
      implementation, OCI registry remote pull.
    - **Implementation note (2026-09-06):** the verified release archive must
      contain the immutable Docker build recipe at `deploy/docker/Dockerfile`
      inside the extracted build context. Installers must not source an unpinned
      working-tree Dockerfile after SHA256 verification.
    - **HD-D4-A** OCI registry-independent contract remains in force; OCI registry
      selection still open. **HD-D4 V1** decides artifact hosting channel (GitHub
      Releases).
    - **Remediation approval (2026-09-06):** commit `fd84a48`; Application **v1.1**
      (`ed42d7a2d278a7f27260fc06249b14bc1d638b6d`, archive SHA256
      `568f528bd32c854f637fb2c31afaeeefeb57aceb8a50331d0dd0daeae60e944a`) is the
      canonical Docker V1 application release input. NLD `v1.0` unchanged.

15. **Application boundary inside container (HD-D10, decided 2026-09-06).**
    - Docker V1 places the **entire RUSBINGO application stack inside the
      container**: application code, `public/` and browser SPA, Workerman,
      HTTP/WebSocket serving, SQLite and `game.db`, application runtime state,
      application logs, healthcheck, and required runtime dependencies.
    - **Host nginx is not part of Docker V1 runtime.** Host PHP, host SQLite, and
      host application files are **not required**. Host-mounted `public/` is **not**
      canonical Docker V1 application delivery. Docker V1 must **not** depend on
      specific PHP/SQLite/nginx versions installed on the VPS.
    - **Storage boundary (reaffirms §2–4):** `game.db` lives inside the container;
      no named volume or bind mount for application state; no persistent host
      storage for game activity/state; **container deletion removes game state**
      with the container (exportable backup per D6 excepted).
    - Host may retain Docker Engine, Compose, installer, temporary release
      artifacts, backup/export artifacts, and ordinary Docker metadata — these are
      **not** RUSBINGO application runtime/state.
    - **D10 Zero Residue** must verify absence of: running RUSBINGO container,
      application state on host, `game.db`, persistent application volumes,
      application bind mounts, host-served `public/` copy, and obsolete
      RUSBINGO-specific installer deployment artifacts.
    - Historical `deploy/docker/configure-proxy.sh` (host nginx + host `public/`)
      is **ADR-036 staging** — superseded for Docker V1 by this decision (see
      ADR-036 supersession note).
    - HD-D10 does **not** decide: OCI registry, installer implementation,
      archive-based build, exact in-container TLS layout, or remediation of
      `deploy/docker/`.

   Because Docker V1 stores application state inside the container filesystem,
   any future upgrade between immutable Docker releases must account for game
   state preservation/restoration (D6); upgrade is **not** designed here.

16. **Docker networking (HD-D6, approved 2026-09-06).**
    - Docker V1 **excludes** `network_mode: host`.
    - Canonical networking: **Docker bridge network** + **published host:container
      ports**.
    - Re-adoption of host networking requires a future explicit Human Decision.

## Consequences

**Positive**

- Clear separation between NLD production release and Docker distribution release.
- Reproducible Docker validation against fixed application SHA.
- Explicit Zero Residue / uninstall contract for container deployments.
- No changes to NLD V1.0 gates, tag, or native production architecture.

**Negative**

- Existing `deploy/docker/configure-proxy.sh` (host nginx + host `public/`) is
  **legacy ADR-036 staging** — superseded for canonical Docker V1 (HD-D10 remediated).
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
