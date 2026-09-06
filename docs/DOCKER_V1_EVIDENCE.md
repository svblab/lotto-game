# Docker V1 — Evidence Index

**Status:** Template — gates **not yet executed**  
**Roadmap:** [`docs/ROADMAP_DOCKER_V1.md`](ROADMAP_DOCKER_V1.md)  
**Application baseline:** tag `v1.0` → `508cc280704ed72cc3e85df03e57bd6fb42d24ee`

This document defines the **structure** of evidence for Docker V1 validation.
Results are filled in as gates are executed. **Do not** mark PASS without
executed procedure and recorded artifacts.

**Policy vs implementation:** Human Decisions marked **DECIDED** below are
approved **policy/contract** only. They do **not** imply D2/D3/D4… implementation
gates are PASS.

---

## Human decision register (approved policy)

| ID | Decision | Status | Date | Implementation evidence |
|----|----------|--------|------|-------------------------|
| **HD-D1** | Immutable Release model | **DECIDED** | 2026-09-06 | PENDING (D1.1) |
| **HD-D2** | HIGH vulnerability disposition policy | **DECIDED** | 2026-09-06 | PENDING (D8.1 scan) |
| **HD-D3** | Docker release versioning / provenance | **DECIDED** | 2026-09-06 | PENDING (release packaging) |
| **HD-D4** | Registry-independent contract | **DECIDED** | 2026-09-06 | PENDING (registry selection) |
| **HD-D7** | Supported OS targets | **DECIDED** | 2026-09-06 | PENDING (D3 validation matrix) |
| **HD-D8** | Installer-first / automated installation | **DECIDED** | 2026-09-06 | PENDING (installer implementation) |
| **HD-D9** | Canonical input = single immutable application release archive | **DECIDED** | 2026-09-06 | PENDING (D1.1 build evidence) |

---

## Release identity (to be confirmed at D12 / H-D1)

| Field | Value |
|-------|-------|
| Application release tag | `v1.0` |
| Application release SHA | `508cc280704ed72cc3e85df03e57bd6fb42d24ee` |
| Application artifact hash (D0) | `sha256:780bb0ea9157a326908afee593f3f7acbbf1c043903094c2bbd7072e4eb166a8` |
| Docker release version | *TBD — HD-D3 allows independent numbering; must link to app version* |
| Docker image digest | *TBD (HD-D4 — digest-based identity when image used)* |
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

### Human Decision HD-D1 — **DECIDED** (2026-09-06)

**Model:** Immutable Release (variant B).

> Каждый Docker release жёстко связан с конкретным immutable application release.

| Principle | Status |
|-----------|--------|
| Immutable application release artifact | **DECIDED** |
| Artifact tied to specific application tag/release | **DECIDED** |
| SHA256 verification required | **DECIDED** |
| No dependency on mutable `main` / latest / floating version | **DECIDED** |
| Release metadata: app version + full 40-char SHA + artifact + SHA256 | **DECIDED** |
| Docker release identity maps to application release | **DECIDED** |
| Existing installation does not auto-update on new app release | **DECIDED** |
| New application release → separate Docker release | **DECIDED** |
| Upgrade of existing installation | **Out of scope** (future decision) |

**Not decided by HD-D1:** artifact hosting/download, registry, image naming, release tag naming, upgrade.

### Human Decision HD-D9 — **DECIDED** (2026-09-06)

> Docker V1 uses the single immutable application release artifact as the canonical
> input for Docker installation.

| Principle | Status |
|-----------|--------|
| One release archive per application release | **DECIDED** |
| SHA256 verification before `docker build` | **DECIDED** |
| Installer obtains archive → verify → build → start container | **DECIDED** (future implementation) |
| Pre-built OCI image distribution as V1 prerequisite | **Rejected as prerequisite** (may be evaluated later) |

**Not decided by HD-D9:** hosting provider, download mechanism, archive filename/format policy, registry, remote pull, installer code.

**Implementation evidence (still PENDING — gate D1.1 remains PENDING):**

- Artifact delivery mechanism documented (storage — separate decision)
- SHA256 verification demonstrated at build/install
- Reproducible build without mutable `main`
- Release identity mapping recorded

| Field | Value |
|-------|-------|
| **HD-D1 decision** | **DECIDED** |
| **Decision date (UTC)** | 2026-09-06 |
| **Model** | Immutable Release (variant B) |
| **Implementation result** | PENDING |
| **Baseline application SHA** | `508cc280704ed72cc3e85df03e57bd6fb42d24ee` |
| **HD-D9 decision** | **DECIDED** |
| **Canonical input** | Single immutable application release archive |
| **Build model** | Verified archive → `docker build` (not OCI pull prerequisite) |
| **Artifact hosting/download** | *TBD — not HD-D9* |
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

### Human Decision HD-D7 — **DECIDED** (2026-09-06)

**Certified minimum:** Ubuntu 22.04 LTS, Ubuntu 24.04 LTS, Debian 12.

**Compatibility:** other Linux if Docker Engine + Compose + system requirements met — not certified until validation matrix PASS.

**Not decided:** exact Docker Engine / Compose versions, minimum VPS resources (after D2/D3 audit).

### Human Decision HD-D8 — **DECIDED** (2026-09-06)

**Model:** Installer-first; Docker Engine installed by installer if absent; idempotency required (future implementation).

**Domain resolution:** `RUSBINGO_DOMAIN` → hostname → interactive prompt (host installer only).

**Implementation evidence (still PENDING):**

- Domain resolution precedence documented
- Install / uninstall commands
- Upgrade model (future)
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

### Human Decision HD-D2 — **DECIDED** (2026-09-06)

| Severity | Policy |
|----------|--------|
| CRITICAL | Release blocker (must be 0) |
| HIGH | May be ACCEPTED only with individual documented disposition |

Per-accepted-HIGH evidence required: CVE, severity, affected component, runtime
affected (Y/N), exploitability, justification, disposition, mitigation if any.

**Not decided:** numeric HIGH count threshold.

**Scan implementation evidence (still PENDING):**

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

## HD-D3 — Docker release versioning (policy)

**Status:** **DECIDED** (2026-09-06)

| Rule | Status |
|------|--------|
| Docker version may differ from application version | **DECIDED** |
| Metadata must state application version | **DECIDED** |
| Provenance: Docker Release → Application Version → Full Git SHA | **DECIDED** |
| Exact Docker release tag string | **Not decided** |

Implementation evidence: PENDING.

---

## HD-D4 — Registry strategy (policy)

**Status:** **DECIDED** (2026-09-06)

| Rule | Status |
|------|--------|
| OCI/container-image portable | **DECIDED** |
| No dependency on one registry's proprietary features | **DECIDED** |
| Production evidence uses immutable digest | **DECIDED** |
| Must not block future Docker Hub publication | **DECIDED** |
| Specific registry / repository / tag / credentials | **Not decided** |

Implementation evidence: PENDING.

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
