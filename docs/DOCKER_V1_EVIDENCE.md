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
| **HD-D10** | All-in-container application boundary (no host nginx/host `public/` runtime) | **DECIDED** | 2026-09-06 | PENDING (remediation per D2 findings) |

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
| **D2** | Docker implementation audit | **AUDIT COMPLETE** | 2026-09-06 | `4f14b14` | n/a | See § D2 below — remediation required before D3 |
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

### Human Decision HD-D10 — **DECIDED** (2026-09-06)

> Docker V1: весь RUSBINGO размещается внутри контейнера.

| Principle | Status |
|-----------|--------|
| Application code, `public/`, SPA, Workerman inside container | **DECIDED** |
| HTTP/WebSocket serving inside container | **DECIDED** |
| SQLite + `game.db` inside container writable layer | **DECIDED** |
| Host nginx as RUSBINGO runtime | **Excluded** |
| Host PHP / host SQLite / host application files | **Not required** |
| Host-mounted `public/` as canonical delivery | **Forbidden** |
| Named volume / bind mount for application state | **Not used** |
| Container deletion removes game state | **DECIDED** |
| D10 Zero Residue includes host `public/` copy, nginx/certs, installer artifacts | **DECIDED** |

**Resolves:** D2 audit OPEN (F-D2-05, networking row) — host-split vs all-in-container.

**Not decided by HD-D10:** registry, artifact hosting, installer implementation,
archive-based build, exact in-container TLS layout, remediation of `deploy/docker/`.

**Implementation evidence (still PENDING — remediation not started):**

- `public/` embedded in container image
- No host `configure-proxy.sh` dependency for Docker V1 install path
- D10 uninstall verifies absence of host-split artifacts

| Field | Value |
|-------|-------|
| **HD-D10 decision** | **DECIDED** |
| **Decision date (UTC)** | 2026-09-06 |
| **ADR reference** | ADR-039 §14; ADR-036 supersession note |
| **Implementation result** | PENDING (post-D2 remediation) |
| **Notes / findings** | Current `deploy/docker/` still ADR-036-era; conflicts documented in D2 |

---

**Audit date (UTC):** 2026-09-06
**Auditor:** Cursor (documentation-only audit)
**Repository HEAD:** `4f14b1418b575fb9e9a181596e9a5add34204d7b`
**Application baseline:** `v1.0` → `508cc280704ed72cc3e85df03e57bd6fb42d24ee`
**Verdict:** **Audit complete.** Current `deploy/docker/` is ADR-036-era staging tooling.
**Multiple mandatory Docker V1 constraints are not satisfied.** Remediation required
before D3. **No remediation performed in this audit.**

### Executive summary

| Area | Verdict |
|------|---------|
| One container / one worker / one SQLite (topology) | **Partial PASS** — single service, `Worker->count=1`; SQLite on named volume breaks storage contract |
| HD-D1 / HD-D9 release provenance | **FAIL** — build uses mutable git checkout, not immutable release archive |
| Ephemeral container-local state (no host app volume) | **FAIL** — `data:/app/data` named volume persists `game.db` on host |
| Zero residue after container deletion | **FAIL** — container removal alone leaves volume + host metadata |
| HD-D8 installer-first automation | **GAP** — scripts exist; Docker Engine not auto-installed; requires git clone |
| Security hardening (static) | **PASS** — non-root, `cap_drop: [ALL]`, `read_only`, no socket/privileged |
| Observability | **PASS** — stdout logging + RFC6455 healthcheck |
| `network_mode: host` | **N/A** — not used; bridge + published `127.0.0.1:port` |

### Requirements matrix

| Area | Requirement | Current state | Status | Evidence | Next action |
|------|-------------|---------------|--------|----------|-------------|
| Release provenance | HD-D1 / HD-D9 | `docker compose build` from `LOTTO_BUILD_CONTEXT=${LOTTO_REPO_ROOT}` (mutable git checkout); no release archive SHA256 | **FAIL** | `deploy/docker/lib/common.sh` L225; `install.sh` L146–147; `Dockerfile` L7–11 | Implement archive-based build input per HD-D9 |
| Dockerfile | one-container WS runtime | Multi-stage PHP 8.4-cli; non-root uid 1000; `pcntl`+`pdo_sqlite`; **no `public/`** in image | **GAP** | `deploy/docker/Dockerfile` L1–45 | Remediation per **HD-D10** — embed `public/` in container |
| Workerman | one worker / lifecycle | `$worker->count = 1`; `CMD php server.php start`; PID file on `/app/data` | **PASS** / **OBSERVATION** | `server.php` L169–170, L686; `compose.yaml` L28 | Runtime-verify SIGTERM/`docker stop` at D3 |
| SQLite | inside container writable layer | `LOTTO_DB_PATH=/app/data/game.db` but mounted via **named volume** | **FAIL** | `compose.yaml` L25, L32–33; `Dockerfile` L35 | Remove named volume; DB in container layer (HD-D5) |
| Volumes | no persistent app state | `volumes: data:/app/data` + `lotto-<instance>-data` | **FAIL** | `compose.yaml` L32–33, L49–51; ADR-036 | Remediation per HD-D5 / ADR-039 |
| Networking | TLS/WSS topology | Bridge network; publish `127.0.0.1:${HOST_PORT}:8080`; **host nginx** (`configure-proxy.sh`) serves static + TLS + `/ws` proxy | **FAIL** | `compose.yaml` L34–43; `configure-proxy.sh` L55–170 | **HD-D10** — all-in-container; host-split superseded |
| Configuration | runtime contract | `LOTTO_*` via compose; origins from install FQDN detect or flags; no `RUSBINGO_DOMAIN` | **GAP** | `compose.yaml` L23–31; `install.sh` L78–88 | Align installer with HD-D8 domain precedence |
| Security | container hardening | `read_only: true`, `cap_drop: [ALL]`, `no-new-privileges`, non-root, no docker.sock, no privileged | **PASS** | `compose.yaml` L12–17 | D8.1 vulnerability scan at gate D8.1 |
| Observability | logs / health | `LOTTO_*_LOG=php://stdout`; compose + Dockerfile HEALTHCHECK via `healthcheck.php` | **PASS** | `compose.yaml` L26–27, L36–41; `Dockerfile` L36–37, L42–43 | — |
| Installer | HD-D8 readiness | `install.sh`/`remove.sh` exist; **requires preinstalled Docker + git checkout**; partial idempotency | **GAP** | `common.sh` L46–59, L80–84; `install.sh` L76, L142–143 | Future unified installer; Docker Engine bootstrap |

### Material findings

#### F-D2-01 — Named Docker volume for application state (**FAIL**)

| Field | Value |
|-------|-------|
| Files | `deploy/docker/compose.yaml` L32–33, L49–51 |
| Observed | `data:/app/data` named volume `lotto-<instance>-data` stores `game.db`, WAL/SHM, `workerman.pid`, bootstrap temp files |
| Requirement | Docker V1: DB inside container; **no** persistent application volume; zero game-state residue after container deletion |
| Impact | `docker rm` / `compose down` without `--volumes` leaves game state on host; violates ADR-039 / roadmap storage contract |
| Next action | Remediation tracked under **HD-D5**; remove named volume, relocate DB to container writable layer |
| ADR / Human | **HD-D5** (known); ADR-036 superseded for V1 track |

#### F-D2-02 — Build uses mutable git checkout, not HD-D9 release archive (**FAIL**)

| Field | Value |
|-------|-------|
| Files | `deploy/docker/lib/common.sh` L80–84, L225; `install.sh` L146–147; `Dockerfile` L7–11 |
| Observed | `lotto_repo_check` requires git checkout; `LOTTO_BUILD_CONTEXT` = repo root; `COPY` from live tree |
| Requirement | HD-D9: single immutable application release archive → SHA256 → `docker build` |
| Impact | Build not reproducible from `v1.0` / `508cc28` without mutable `main`; silent drift possible |
| Next action | Implement archive ingestion path (hosting/download still open) |
| ADR / Human | Artifact hosting still **open**; HD-D9 policy decided |

#### F-D2-03 — Zero residue requires explicit volume removal (**FAIL**)

| Field | Value |
|-------|-------|
| Files | `deploy/docker/remove.sh` L52–59, L75; `install.sh` L90–96 |
| Observed | `remove.sh` deletes volume + `/var/lib/lotto-game/<instance>/` metadata; ordinary `compose down` without `--volumes` retains volume |
| Requirement | After uninstall, no game-server data on host |
| Impact | Current `remove.sh` can achieve zero residue **if** fully executed; container-only deletion does not |
| Next action | D10 must verify uninstall contract; installer must define safe teardown |
| ADR / Human | — |

#### F-D2-04 — Host-persistent installation metadata (**OBSERVATION** / partial **FAIL** for strict zero residue)

| Field | Value |
|-------|-------|
| Files | `deploy/docker/lib/common.sh` L9, L28–30; `configure-proxy.sh` L57–72, L81–154 |
| Observed | Host paths: `/var/lib/lotto-game/<instance>/` (instance.env, AHPC pending, static copy); nginx vhost + Let's Encrypt certs via `configure-proxy.sh` (not removed by `remove.sh`) |
| Requirement | D10 zero residue — host must not retain game-server artifacts after uninstall |
| Impact | `remove.sh` does not clean nginx/TLS artifacts; `configure-proxy.sh` copies `public/` to host |
| Next action | D10 must verify uninstall removes host `public/` copy, nginx vhost, certs per **HD-D10** |
| ADR / Human | **HD-D10** — host nginx/host `public/` not canonical Docker V1; teardown in D10 scope |

#### F-D2-05 — `public/` not in container image (**GAP** / **FAIL** vs HD-D10)

| Field | Value |
|-------|-------|
| Files | `deploy/docker/Dockerfile` (no `public/` COPY); `configure-proxy.sh` L55–72 |
| Observed | Container runs Workerman only; HTTPS static SPA served from host `STATIC_ROOT` copy of repo `public/` |
| Requirement | **HD-D10:** `public/` and SPA inside container; host `public/` copy not canonical |
| Impact | Installer depends on git checkout for static files + separate `configure-proxy.sh` step |
| Next action | Remediation: embed `public/` in image; remove host-static delivery from Docker V1 path |
| ADR / Human | **HD-D10** (decided 2026-09-06) |

#### F-D2-06 — Docker Engine not auto-installed (**GAP**)

| Field | Value |
|-------|-------|
| Files | `deploy/docker/lib/common.sh` L46–59 |
| Observed | `lotto_docker_check` fails if Docker missing; no install path |
| Requirement | HD-D8: installer installs Docker Engine when absent |
| Impact | Current scripts are not HD-D8-compliant installer |
| Next action | Future installer implementation |
| ADR / Human | HD-D8 policy only |

#### F-D2-07 — Domain resolution model differs from HD-D8 (**GAP**)

| Field | Value |
|-------|-------|
| Files | `install.sh` L78–88; `configure-proxy.sh` L51–52 |
| Observed | Uses FQDN from `hostnamectl` static hostname (must be public domain); no `RUSBINGO_DOMAIN` → hostname → prompt chain |
| Requirement | HD-D8 deterministic domain precedence |
| Impact | Interactive prompt path not implemented; hostname must pre-equal production domain |
| Next action | Align future installer with HD-D8 |
| ADR / Human | — |

#### F-D2-08 — Multi-instance design vs Docker V1 single topology (**OBSERVATION**)

| Field | Value |
|-------|-------|
| Files | `install.sh` L28–29; `common.sh` L217–220; ADR-036 |
| Observed | `--name` supports multiple instances per host; per-instance volumes/images |
| Requirement | Docker V1: one container / one worker / one SQLite; multi-instance unsupported |
| Impact | Tooling allows unsupported topology; not blocked at install time |
| Next action | D5.1 boundary test optional; installer should not expose multi-instance for V1 |
| ADR / Human | — |

#### F-D2-09 — `network_mode: host` not present (**PASS** / closes audit question)

| Field | Value |
|-------|-------|
| Files | `deploy/docker/compose.yaml` |
| Observed | Bridge network `lotto-<instance>-net`; publish `127.0.0.1:host:container` |
| Requirement | HD-D6: justify or exclude `network_mode: host` |
| Impact | No host-network isolation issue from host mode |
| Next action | Mark **HD-D6** resolved: exclude `network_mode: host` unless future Human decision |
| ADR / Human | Recommend close **HD-D6** as "excluded in current compose" |

### Section audits (summary)

| # | Section | Result | Notes |
|---|---------|--------|-------|
| 1 | Dockerfile | **GAP/FAIL** | Sound WS runtime; missing `public/`; build from mutable context |
| 2 | compose.yaml | **FAIL** | Named volume; otherwise good hardening |
| 3 | Workerman lifecycle | **PASS** | `count=1`; stdout logs; healthcheck; SIGTERM needs D3 runtime proof |
| 4 | Configuration | **GAP** | `LOTTO_*` wired; domain model incomplete vs HD-D8 |
| 5 | Networking | **OPEN** | Host nginx + loopback upstream; no TLS in container |
| 6 | Storage / zero residue | **FAIL** | Named volume + host metadata |
| 7 | DB initialization | **PASS** | `init_db.php` via compose run; AHPC per ADR-038 |
| 8 | Release artifact (HD-D9) | **FAIL** | No archive path |
| 9 | Security (static) | **PASS** | Hardening baseline present |
| 10 | Observability | **PASS** | stdout + healthcheck |
| 11 | Installer readiness (HD-D8) | **GAP** | Partial scripts only |

### Database initialization (detail)

| Item | Observation |
|------|-------------|
| Mechanism | `install.sh` runs `compose run … php init_db.php` on new volume (`install.sh` L149–157) |
| Path | `LOTTO_DB_PATH=/app/data/game.db` on named volume |
| AHPC | `LOTTO_ADMIN_BOOTSTRAP_FILE=/app/data/.admin_bootstrap` → promoted to host pending file (`common.sh` L284–310) |
| Repeat install | Existing volume preserved; `init_db` skipped (`install.sh` L142–143) |
| ADR-038 | Consistent with Docker AHPC path |

### Recommended remediation order (post Human review)

1. **HD-D5** — Remove named application volume; SQLite in container writable layer; define persistence-within-container-restart model.
2. **HD-D9 implementation** — Archive-based build context; SHA256 gate; decouple from mutable `main`.
3. **HD-D10 remediation** — Embed `public/` in container; retire host nginx/host-static as Docker V1 delivery path.
4. **HD-D8 installer** — Docker Engine bootstrap, `RUSBINGO_DOMAIN` precedence, idempotency, unified flow.
5. **D10 scope** — Teardown per HD-D10: container, volumes, host `public/` copy, nginx/certs, installer artifacts.
6. **D3 validation** — Runtime proof: SIGTERM, zero residue, cold install on certified OS.

### ADR triggers (recommendations only — no ADR created)

| Trigger | Recommendation |
|---------|----------------|
| Storage remediation | Update ADR-039 consequences or short amendment when implementation starts — ADR-036 already marked superseded for V1 |
| Host-split static/nginx | **Resolved by HD-D10** — ADR-036 supersession note documents historical staging model |

### Human decisions required

| ID | Question |
|----|----------|
| **HD-D5** | Approve remediation plan for named volume removal |
| **HD-D6** | Recommend **close** as excluded (`network_mode: host` not used) |
| — | Artifact hosting/download (still open from HD-D9) |

### Vulnerability scan

**Not performed** — no repository tooling invoked; static audit only. D8.1 gate remains **PENDING**.

| Field | Value |
|-------|-------|
| **Gate D2 result** | **AUDIT COMPLETE** (findings recorded; remediation not started) |
| **Date (UTC)** | 2026-09-06 |
| **Audited repo SHA** | `4f14b1418b575fb9e9a181596e9a5add34204d7b` |
| **Application baseline** | `v1.0` / `508cc280704ed72cc3e85df03e57bd6fb42d24ee` |
| **Mandatory constraints satisfied** | **NO** |
| **Proceed to D3** | **NO** — pending remediation + Human review |
| **Notes** | Implementation gates D3–D12 remain **PENDING** |

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
