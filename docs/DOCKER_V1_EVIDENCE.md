# Docker V1 — Evidence Index

**Status:** Template — gates **not yet executed**  
**Roadmap:** [`docs/ROADMAP_DOCKER_V1.md`](ROADMAP_DOCKER_V1.md)  
**Application baseline:** tag `v1.0` → `508cc280704ed72cc3e85df03e57bd6fb42d24ee`

This document defines the **structure** of evidence for Docker V1 validation.
Results are filled in as gates are executed. **Do not** mark PASS without
executed procedure and recorded artifacts.

---

## Release identity (to be confirmed at D12 / H-D1)

| Field | Value |
|-------|-------|
| Application release tag | `v1.0` |
| Application release SHA | `508cc280704ed72cc3e85df03e57bd6fb42d24ee` |
| Application artifact hash (D0) | `sha256:780bb0ea9157a326908afee593f3f7acbbf1c043903094c2bbd7072e4eb166a8` |
| Docker image name | *TBD (Human — HD-D4)* |
| Docker image digest | *TBD* |
| Docker release tag | *TBD (Human — HD-D3)* |
| NLD `v1.0` tag | **Unchanged** |

---

## Gate summary

| Gate | Title | Result | Date (UTC) | Tested SHA | Image identity | Notes |
|------|-------|--------|------------|------------|----------------|-------|
| **D0** | V1.0 baseline / immutable artifact | PENDING | | | | |
| **D1** | Docker project architecture | PENDING | | | | |
| **D1.1** | Artifact resolution | PENDING | | | | |
| **D2** | Docker implementation audit | PENDING | | | | |
| **D2.1** | Installation contract freeze | PENDING | | | | |
| **D3** | Clean installation | PENDING | | | | |
| **D4** | Functional / browser E2E | PENDING | | | | |
| **D5** | Persistence & container lifecycle | PENDING | | | | |
| **D5.1** | Multi-instance / SQLite boundary | PENDING | | | | |
| **D6** | Backup / restore | PENDING | | | | |
| **D7** | Recovery | PENDING | | | | |
| **D8** | Security audit | PENDING | | | | |
| **D8.1** | Image vulnerability scan | PENDING | | | | |
| **D9** | Observability | PENDING | | | | |
| **D10** | Zero residue / uninstall | PENDING | | | | |
| **D11** | Clean reinstall | PENDING | | | | |
| **D12** | Final regression | PENDING | | | | |
| **H-D1** | Human approval | PENDING | | | | |

---

## D0 — V1.0 baseline / immutable artifact

**Expected evidence:**

- Recorded application release tag and SHA
- Artifact integrity hash (`git archive` or equivalent)
- Confirmation Docker validation does not use floating `main`

| Field | Value |
|-------|-------|
| **Result** | PENDING |
| **Date (UTC)** | |
| **Tested SHA** | |
| **Artifact hash** | |
| **Procedure** | |
| **Notes / findings** | |

---

## D1 — Docker project architecture

**Expected evidence:**

- Documented separation: application vs Docker distribution layer
- Confirmation no application logic fork
- List of Docker project responsibilities vs application repo

| Field | Value |
|-------|-------|
| **Result** | PENDING |
| **Date (UTC)** | |
| **Tested SHA** | |
| **Notes / findings** | |

---

## D1.1 — Artifact resolution

### Human Decision HD-D1 — **ACCEPTED** (2026-09-06)

**Model:** Immutable Release (variant B).

> Каждый Docker release жёстко соответствует конкретному immutable application release.

| Principle | Status |
|-----------|--------|
| Immutable application release artifact | **ACCEPTED** |
| Artifact tied to specific application tag/release | **ACCEPTED** |
| SHA256 verification required | **ACCEPTED** |
| No dependency on mutable `main` | **ACCEPTED** |
| Docker release identity maps to application release | **ACCEPTED** |
| Existing installation does not auto-update on new app release | **ACCEPTED** |
| New application release → separate Docker release | **ACCEPTED** |
| Upgrade of existing installation | **Out of scope** (future decision) |

**Not decided by HD-D1:** artifact storage location, registry, image naming, release tag naming, upgrade command/procedure, backup-before-upgrade, SQLite migration.

**Expected evidence (implementation — still pending):**

- Artifact delivery mechanism documented (storage — separate decision)
- SHA256 verification demonstrated at build/install
- Reproducible build without mutable `main`
- Release identity mapping recorded

| Field | Value |
|-------|-------|
| **HD-D1 decision** | **ACCEPTED** |
| **Decision date (UTC)** | 2026-09-06 |
| **Model** | Immutable Release (variant B) |
| **Implementation result** | PENDING |
| **Tested SHA** | |
| **Artifact source** | *TBD — not HD-D1* |
| **Verification command** | |
| **Notes / findings** | |

---

## D2 — Docker implementation audit

**Expected evidence:**

- Storage audit (container-local DB; no host volume)
- Logs audit (stdout/stderr preferred)
- Network audit (ports, proxy, WSS, `network_mode: host` disposition)
- Workerman audit (1 worker, shutdown, restart)
- Configuration audit (domain, origins, no hardcoded production domain, no secrets in Git)
- Gap list vs current `deploy/docker/` (incl. ADR-036 named-volume conflict)

| Field | Value |
|-------|-------|
| **Result** | PENDING |
| **Date (UTC)** | |
| **Tested SHA** | |
| **Image identity** | |
| **Findings** | |
| **Notes** | |

---

## D2.1 — Installation contract freeze

**Expected evidence:**

- Domain resolution precedence documented
- Prerequisites, OS, Docker/Compose versions
- Install / uninstall commands
- Upgrade model
- Persistence statement: no host application state
- Topology: 1 container / 1 worker / 1 SQLite

| Field | Value |
|-------|-------|
| **Result** | PENDING |
| **Date (UTC)** | |
| **Tested SHA** | |
| **Contract document link** | |
| **Notes / findings** | |

---

## D3 — Clean installation

**Expected evidence:**

- Clean VPS install log
- Image build/pull
- Container start
- Domain / HTTPS / WSS
- SQLite inside container
- Workerman start
- Cold start time
- Repeatability note

| Field | Value |
|-------|-------|
| **Result** | PENDING |
| **Date (UTC)** | |
| **Tested SHA** | |
| **Image identity** | |
| **OS / VPS** | |
| **Domain** | |
| **Artifacts** | |
| **Notes / findings** | |

---

## D4 — Functional / browser E2E

**Expected evidence:**

- HTTPS, WSS, auth, rooms, game lifecycle
- Reload, reconnect, wrong password
- Smoke test references

| Field | Value |
|-------|-------|
| **Result** | PENDING |
| **Date (UTC)** | |
| **Tested SHA** | |
| **Image identity** | |
| **Domain** | |
| **Artifacts** | |
| **Notes / findings** | |

---

## D5 — Persistence & container lifecycle

**Expected evidence:**

- State survives **same container** restart
- State **absent** after container removal + new container without restore
- No host volume for game data

| Field | Value |
|-------|-------|
| **Result** | PENDING |
| **Date (UTC)** | |
| **Tested SHA** | |
| **Image identity** | |
| **Procedure steps** | |
| **Notes / findings** | |

---

## D5.1 — Unsupported multi-instance / SQLite boundary

**Expected evidence:**

- Documented unsupported: shared SQLite across containers
- Optional negative test results

| Field | Value |
|-------|-------|
| **Result** | PENDING |
| **Date (UTC)** | |
| **Tested SHA** | |
| **Notes / findings** | |

---

## D6 — Backup / restore

**Expected evidence:**

- Known state → export → destroy container → clean container → import → verify
- Backup integrity / hash
- Backup not implemented as persistent volume

| Field | Value |
|-------|-------|
| **Result** | PENDING |
| **Date (UTC)** | |
| **Tested SHA** | |
| **Image identity** | |
| **Backup artifact id** | |
| **Notes / findings** | |

---

## D7 — Recovery

**Expected evidence:**

- Per-scenario expected vs actual (restart, stop/start, proxy, host reboot, failure, recreate, restore)

| Field | Value |
|-------|-------|
| **Result** | PENDING |
| **Date (UTC)** | |
| **Tested SHA** | |
| **Image identity** | |
| **Scenario table** | |
| **Notes / findings** | |

---

## D8 — Security audit

**Expected evidence:**

- Finding table: severity, evidence, impact, disposition, PASS/FAIL
- No unresolved P0/P1

| Field | Value |
|-------|-------|
| **Result** | PENDING |
| **Date (UTC)** | |
| **Tested SHA** | |
| **Image identity** | |
| **Findings count (P0/P1/P2/P3)** | |
| **Notes / findings** | |

---

## D8.1 — Image vulnerability scan

**Expected evidence:**

- Scanner name + version
- Image digest scanned
- CRITICAL = 0
- HIGH policy + result

| Field | Value |
|-------|-------|
| **Result** | PENDING |
| **Date (UTC)** | |
| **Image digest** | |
| **Scanner** | |
| **CRITICAL count** | |
| **HIGH policy** | |
| **HIGH count** | |
| **Notes / findings** | |

---

## D9 — Observability

**Expected evidence:**

- stdout/stderr visibility
- Startup/shutdown in logs
- Health/smoke mechanism used

| Field | Value |
|-------|-------|
| **Result** | PENDING |
| **Date (UTC)** | |
| **Tested SHA** | |
| **Image identity** | |
| **Notes / findings** | |

---

## D10 — Zero residue / uninstall

**Expected evidence:**

- Uninstall command output
- Verification: no containers, volumes, networks, host game data, install dirs
- Docker CLI + known paths (not only `/var/lib/docker` inspection)

| Field | Value |
|-------|-------|
| **Result** | PENDING |
| **Date (UTC)** | |
| **Tested SHA** | |
| **Verification commands** | |
| **Notes / findings** | |

---

## D11 — Clean reinstall

**Expected evidence:**

- Post-D10 reinstall
- HTTPS/WSS + smoke PASS
- No dependency on prior install artifacts

| Field | Value |
|-------|-------|
| **Result** | PENDING |
| **Date (UTC)** | |
| **Tested SHA** | |
| **Image identity** | |
| **Notes / findings** | |

---

## D12 — Final regression

**Expected evidence:**

- All D3–D11 areas re-verified on same release candidate
- Single identity bundle: app SHA + image digest

| Field | Value |
|-------|-------|
| **Result** | PENDING |
| **Date (UTC)** | |
| **Application SHA** | |
| **Image digest** | |
| **Regression report link** | |
| **Notes / findings** | |

---

## H-D1 — Human approval

**Expected evidence:**

- Written Human confirmation
- Accepted P2/P3 list
- No unresolved P0/P1
- Final Docker release naming

| Field | Value |
|-------|-------|
| **Result** | PENDING |
| **Date (UTC)** | |
| **Approver** | |
| **Final application SHA** | |
| **Final image digest** | |
| **Docker release tag** | |
| **Notes** | |

---

## Finding log (cross-gate)

Use for P0–P3 findings discovered during any gate:

| ID | Gate | Severity | Summary | Disposition | Status |
|----|------|----------|---------|-------------|--------|
| | | | | | |

---

## References

- [`docs/ROADMAP_DOCKER_V1.md`](ROADMAP_DOCKER_V1.md)
- [`docs/RELEASE_CONTRACT_V1.md`](RELEASE_CONTRACT_V1.md) — NLD V1.0 (separate track)
