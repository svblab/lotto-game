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
| **HD-D2** | HIGH vulnerability disposition policy | **APPROVED** | 2026-09-06 | PENDING (D8.1 scan) |
| **HD-D3** | Docker release versioning / provenance | **DECIDED** | 2026-09-06 | PENDING (release packaging) |
| **HD-D4** | Registry-independent OCI contract + GitHub Releases artifact channel (V1) | **DECIDED** | 2026-09-06 | PENDING (installer GitHub download) |
| **HD-D6** | Docker networking — `network_mode: host` excluded | **APPROVED** | 2026-09-06 | See compose bridge + published ports |
| **HD-D7** | Supported OS targets + Docker/VPS prerequisite floors | **APPROVED** | 2026-09-06 | PENDING (D3 validation matrix) |
| **HD-D8** | Installer-first / automated installation | **DECIDED** | 2026-09-06 | PENDING (installer implementation) |
| **HD-D9** | Immutable release archive; remediation `fd84a48`; Application **v1.1** canonical | **REMEDIATION APPROVED** | 2026-09-06 | See § HD-D9 remediation below |
| **HD-D10** | All-in-container application boundary (no host nginx/host `public/` runtime) | **REMEDIATED** | 2026-09-06 | See § HD-D10 remediation below |
| **HD-D5** | Container-only application storage (no named volume / bind mount) | **REMEDIATED** | 2026-09-06 | See § HD-D5 remediation below |

**Numeric HIGH count threshold:** **waived / N/A** (HD-D2 APPROVED 2026-09-06).

---

## Release identity

| Field | NLD baseline (unchanged) | Docker V1 canonical (APPROVED) |
|-------|--------------------------|--------------------------------|
| Application release tag | `v1.0` | `v1.1` |
| Application release SHA | `508cc280704ed72cc3e85df03e57bd6fb42d24ee` | `ed42d7a2d278a7f27260fc06249b14bc1d638b6d` |
| Release archive SHA256 | `780bb0ea9157a326908afee593f3f7acbbf1c043903094c2bbd7072e4eb166a8` | `568f528bd32c854f637fb2c31afaeeefeb57aceb8a50331d0dd0daeae60e944a` |
| Trusted manifest | `release-manifests/v1.0.env` | `release-manifests/v1.1.env` |
| HD-D9 remediation commit | — | `fd84a48` |
| Docker release version | *TBD — HD-D3* | *TBD* |
| NLD `v1.0` tag | **Unchanged** | **Unchanged** |

---

## Gate summary

| Gate | Title | Result | Date (UTC) | Tested SHA | Image identity | Notes |
|------|-------|--------|------------|------------|----------------|-------|
| **D0** | V1.0 baseline / immutable artifact | PENDING | | | | |
| **D1** | Docker project architecture | PENDING | | | | |
| **D1.1** | Artifact resolution | PENDING | | | | |
| **D2** | Docker implementation audit | **AUDIT COMPLETE** | 2026-09-06 | `4f14b14` | n/a | See § D2 below — remediation required before D3 |
| **D2.1** | Installation contract freeze | **FROZEN** | 2026-09-06 | `946c534` | n/a | See § D2.1 below |
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

**Not decided by HD-D9:** exact archive filename/format policy, installer GitHub
download code, OCI registry remote pull.

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

**Implementation evidence (HD-D10 remediation 2026-09-06):**

- `public/` embedded in container image (`COPY public/` in Dockerfile)
- Container-native HTTP (Workerman `LOTTO_HTTP_PUBLIC`) + WebSocket `/ws`
- Canonical install path does not call `configure-proxy.sh`
- **TLS/WSS in-container:** **OPEN** — separate architectural decision required (ADR-027)

| Field | Value |
|-------|-------|
| **HD-D10 decision** | **DECIDED** |
| **HD-D10 implementation** | **REMEDIATED** (HTTP/WS/static; TLS **OPEN**) |
| **Decision date (UTC)** | 2026-09-06 |
| **Remediation date (UTC)** | 2026-09-06 |
| **ADR reference** | ADR-039 §14; ADR-036 supersession note |
| **Notes / findings** | See § HD-D10 remediation; D10 zero-residue gate still **PENDING** |

---

## HD-D10 remediation — container-native application runtime

**Remediation date (UTC):** 2026-09-06  
**Scope:** F-D2-05 — entire RUSBINGO inside container; no host nginx/host `public/` dependency.

### Changes

| Component | Change |
|-----------|--------|
| `deploy/docker/Dockerfile` | `COPY public/`; `LOTTO_HTTP_PUBLIC`, `LOTTO_WS_PATH` env |
| `server.php` | Env-gated `LOTTO_HTTP_PUBLIC` → HTTP static + `/ws` upgrade (NLD unchanged when unset) |
| `src/Core/StaticHttpServer.php` | SPA/static serving with path traversal protection |
| `src/Core/ContainerFrontDoor.php` | WebSocket upgrade on `/ws` within single HTTP worker |
| `deploy/docker/compose.yaml` | `LOTTO_HTTP_PUBLIC`; Dockerfile from verified build context (`deploy/docker/Dockerfile`) |
| `deploy/docker/lib/release-artifact.sh` | Verified extract only — no post-verify application overlay |
| `deploy/docker/install.sh` | Removed canonical `configure-proxy.sh` handoff |
| `deploy/docker/healthcheck.php` | Healthcheck uses `LOTTO_WS_PATH` (`/ws`) |
| `deploy/docker/tests/test_hd_d10.sh` | Static HD-D10 checks |

### Finding status

| ID | D2 status | Post-HD-D10 |
|----|-----------|-------------|
| **F-D2-05** | FAIL | **REMEDIATED** — `public/` in image; container HTTP/WS |
| **F-D2-03** | PARTIAL | **PARTIAL** — D10 zero-residue validation still **PENDING** |
| Networking/TLS row | FAIL | **PARTIAL** — HTTP+WS in-container; **TLS OPEN** |

### TLS architecture (OPEN)

| Item | Status |
|------|--------|
| In-container HTTPS/WSS without second proxy process | **OPEN** |
| ADR-027 native Workerman TLS | Explicitly excluded for NLD |
| Host nginx as mandatory reverse proxy | **Removed** from canonical Docker path |
| Self-signed production certs | **Not implemented** |

**Blocker:** Production `wss://<domain>/ws` requires TLS termination. Completing
this without host nginx or an additional in-container proxy/server needs a
separate Human Decision — not implemented in this remediation.

### Verification (static)

| Check | Result |
|-------|--------|
| `deploy/docker/tests/test_hd_d10.sh` | *Run at commit time* |
| `public/` in Dockerfile | **PASS** |
| No host `public/` bind mount in compose | **PASS** |
| No application state volumes | **PASS** (HD-D5 retained) |
| `lotto-ws-port=""` / `lotto-ws-path="/ws"` preserved | **PASS** |
| HD-D9 verified archive flow | **PASS** — immutable release artifact is sole application runtime input |
| Security controls (`cap_drop`, non-root, …) | **PASS** |

### Verification (runtime)

| Check | Result |
|-------|--------|
| Docker image build + HTTP/WS smoke | **NOT RUN** if Docker daemon unavailable |

---

## HD-D5 remediation — container-only application storage

**Remediation date (UTC):** 2026-09-06
**Scope:** Remove Docker named volume / bind mount for application state (`data:/app/data`).

### Changes

| Component | Change |
|-----------|--------|
| `deploy/docker/compose.yaml` | Removed `volumes: data:/app/data` and top-level `volumes:` block |
| `deploy/docker/Dockerfile` | Pre-create `/app/data` (`1000:1000`, mode `750`) in image |
| `deploy/docker/install.sh` | `compose up` → `compose exec init_db.php`; no volume creation |
| `deploy/docker/remove.sh` | `compose down` without `--volumes`; legacy volume cleanup only |
| `deploy/docker/lib/common.sh` | Removed `lotto_prepare_data_volume`; container exec AHPC helpers |
| `deploy/docker/admin-bootstrap.sh` | Reset via `compose exec` (no volume mount) |

**Trade-off:** `read_only: true` removed from compose — incompatible with
container-local SQLite without host volumes or tmpfs (HD-D5). Other hardening
(`cap_drop`, non-root, `no-new-privileges`) retained.

### Finding status

| ID | D2 status | Post-HD-D5 |
|----|-----------|------------|
| **F-D2-01** | FAIL | **REMEDIATED** — no application named volume in compose |
| **F-D2-03** | FAIL | **PARTIAL** — `compose down` no longer retains app volume; full D10 gate **PENDING** |

### Verification (static)

| Check | Result |
|-------|--------|
| `git diff --check` | **PASS** |
| `compose.yaml` has no `volumes:` / `data:/app/data` | **PASS** |
| `docker compose config` (rendered) has no app volume | **PASS** — no `volumes:` key in rendered service |
| `docker compose config --volumes` | **PASS** — empty output |
| Repository grep `data:/app/data` in `deploy/docker/` | **PASS** (absent; historical refs only in `docs/`) |

### Verification (runtime)

| Check | Result |
|-------|--------|
| build/start → `game.db` inside container | **NOT RUN** — Docker daemon unavailable in validation environment |
| `docker inspect` — no application named volume/bind mount | **NOT RUN** |
| `compose down` → no application volume on host | **NOT RUN** |
| `deploy/docker/tests/run_tests.sh` | **NOT RUN** — bash/WSL unavailable on validation host |

**HD-D5 gate:** implementation **REMEDIATED**; full storage/zero-residue contract
validated at **D3** / **D10** (not PASS in this task).

---

## HD-D9 remediation — verified release archive build input

**Remediation date (UTC):** 2026-09-06
**Scope:** Docker build/install from immutable application release archive with
trusted SHA256 verification — no mutable Git checkout fallback.

### Release artifact contract

| Field | Value (v1.0 baseline) |
|-------|------------------------|
| Application version | `v1.0` |
| Full Git SHA | `508cc280704ed72cc3e85df03e57bd6fb42d24ee` |
| Archive format | `git archive --format=tar.gz --prefix=rusbingo/ v1.0` |
| Expected archive SHA256 | `780bb0ea9157a326908afee593f3f7acbbf1c043903094c2bbd7072e4eb166a8` |
| Trusted manifest | `deploy/docker/release-manifests/v1.0.env` |
| Build context | Extracted `rusbingo/` prefix inside instance `verified-release/` work dir |

**Identity separation:** Git SHA ≠ archive SHA256 ≠ image digest.

### Changes

| Component | Change |
|-----------|--------|
| `deploy/docker/lib/release-artifact.sh` | SHA256 verify, tar path validation, safe extract |
| `deploy/docker/release-manifests/v1.0.env` | Trusted expected SHA256 + release identity |
| `deploy/docker/install.sh` | Requires `--release-archive`; verifies before build |
| `deploy/docker/lib/common.sh` | `lotto_prepare_instance_release_build`; provenance in `instance.env` |
| `deploy/docker/compose.yaml` | Build args for provenance labels |
| `deploy/docker/Dockerfile` | `ARG`/`LABEL` for application version, Git SHA, archive SHA256 |
| `deploy/docker/tests/test_release_artifact.sh` | Verification + failure + provenance tests |

### Finding status

| ID | D2 status | Post-HD-D9 |
|----|-----------|------------|
| **F-D2-02** | FAIL | **REMEDIATED** — build from verified archive; no Git checkout fallback |

### Verification (static)

| Check | Result |
|-------|--------|
| `git diff --check` | *recorded at commit* |
| `deploy/docker/tests/test_release_artifact.sh` | **PASS** — 14/14 (1 skipped: Linux-only install test) |
| Install without `--release-archive` | **FAIL** (expected; Linux test skipped on non-Linux) |
| Tampered archive + trusted SHA256 | **FAIL** (expected) |
| Valid `v1.0` archive + manifest | **PASS** |
| Provenance metadata fields | **PASS** |
| No `LOTTO_BUILD_CONTEXT=${LOTTO_REPO_ROOT}` default | **PASS** |

### Verification (runtime)

| Check | Result |
|-------|--------|
| `docker build` from verified extracted archive | **NOT RUN** — Docker daemon unavailable |
| Image labels contain version/SHA/archive SHA256 | **NOT RUN** |
| `deploy/docker/tests/run_tests.sh` full integration | **NOT RUN** — requires Linux + Docker + sudo |

**HD-D9 gate:** implementation **REMEDIATION APPROVED** (2026-09-06); D1.1/D3 installation validation **PENDING**.

---

## HD-D9 post-HD-D10 audit remediation — immutable release provenance restored

**Remediation date (UTC):** 2026-09-06  
**Scope:** Post-HD-D10 audit findings F-HD9-01 … F-HD9-05 — remove post-verification
application runtime overlay; new application release identity; Dockerfile from
verified build context.

### Corrected provenance invariant

```text
immutable release artifact
    → SHA256 verification
    → verified extraction
    → Docker build context (application + deploy/docker/Dockerfile)
    → docker build
    → image application runtime
```

No mutable `LOTTO_REPO_ROOT` application overlay after SHA256 verification.

### Release identity (Docker V1 canonical — Application v1.1)

| Field | Value |
|-------|--------|
| Application version | `v1.1` |
| Full Git SHA | `ed42d7a2d278a7f27260fc06249b14bc1d638b6d` |
| Archive format | `git archive --format=tar.gz --prefix=rusbingo/ ed42d7a2d278a7f27260fc06249b14bc1d638b6d` |
| Expected archive SHA256 | `568f528bd32c854f637fb2c31afaeeefeb57aceb8a50331d0dd0daeae60e944a` |
| Trusted manifest | `deploy/docker/release-manifests/v1.1.env` |

**NLD baseline unchanged:** Application `v1.0` → `508cc280704ed72cc3e85df03e57bd6fb42d24ee`
(manifest `release-manifests/v1.0.env` unchanged).

### Changes

| Component | Change |
|-----------|--------|
| `deploy/docker/release-manifests/v1.1.env` | Trusted SHA256 + release identity for HD-D10 runtime |
| `deploy/docker/lib/release-artifact.sh` | Removed `lotto_apply_docker_v1_runtime_overlay()`; Dockerfile required in archive |
| `deploy/docker/lib/common.sh` | Install path = verify + extract only; no `LOTTO_DOCKERFILE` checkout pin |
| `deploy/docker/compose.yaml` | `dockerfile: deploy/docker/Dockerfile` (context-relative, immutable) |
| `deploy/docker/install.sh` | Default application version `v1.1` |
| `deploy/docker/tests/test_release_artifact.sh` | Install-path provenance tests A–G |

### Finding status (post-HD-D10 audit)

| ID | Status |
|----|--------|
| **F-HD9-01** | **REMEDIATED** — no post-verification application overlay |
| **F-HD9-02** | **REMEDIATED** — provenance matches verified archive runtime bytes |
| **F-HD9-03** | **REMEDIATED** — HD-D10 files in v1.1 release artifact |
| **F-HD9-04** | **REMEDIATED** — Dockerfile from verified build context |
| **F-HD9-05** | **REMEDIATED** — tests cover install-path build-context preparation |

### Verification (static)

| Check | Result |
|-------|--------|
| `deploy/docker/tests/test_release_artifact.sh` | *Run at commit time* |
| Tests A–G (archive contents, tamper, byte match, checkout isolation, Dockerfile, provenance, no overlay) | *Run at commit time* |
| `deploy/docker/tests/test_hd_d10.sh` | *Run at commit time* |

**HD-D9 remediation gate:** **REMEDIATION APPROVED** (Human approval 2026-09-06;
commit `fd84a48`; Application **v1.1** canonical). D3 installation validation **PENDING**.

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
| HD-D1 / HD-D9 release provenance | **REMEDIATED** — verified archive build input (HD-D9) |
| Ephemeral container-local state (no host app volume) | **FAIL** — `data:/app/data` named volume persists `game.db` on host |
| Zero residue after container deletion | **FAIL** — container removal alone leaves volume + host metadata |
| HD-D8 installer-first automation | **GAP** — scripts exist; Docker Engine not auto-installed; requires git clone |
| Security hardening (static) | **PASS** — non-root, `cap_drop: [ALL]`, `read_only`, no socket/privileged |
| Observability | **PASS** — stdout logging + RFC6455 healthcheck |
| `network_mode: host` | **N/A** — not used; bridge + published `127.0.0.1:port` |

### Requirements matrix

| Area | Requirement | Current state | Status | Evidence | Next action |
|------|-------------|---------------|--------|----------|-------------|
| Release provenance | HD-D1 / HD-D9 | Verified archive → SHA256 → extract → `LOTTO_BUILD_CONTEXT`; manifest `release-manifests/v1.0.env` | **REMEDIATED** | `release-artifact.sh`; `install.sh` | D3 runtime proof **PENDING** |
| Dockerfile | one-container WS runtime | Multi-stage PHP 8.4-cli; non-root uid 1000; `pcntl`+`pdo_sqlite`; **no `public/`** in image | **GAP** | `deploy/docker/Dockerfile` L1–45 | Remediation per **HD-D10** — embed `public/` in container |
| Workerman | one worker / lifecycle | `$worker->count = 1`; `CMD php server.php start`; PID file on `/app/data` | **PASS** / **OBSERVATION** | `server.php` L169–170, L686; `compose.yaml` L28 | Runtime-verify SIGTERM/`docker stop` at D3 |
| SQLite | inside container writable layer | `LOTTO_DB_PATH=/app/data/game.db` in container writable layer (no volume mount) | **REMEDIATED** | `compose.yaml`; `Dockerfile` `/app/data` | HD-D5 — D3 runtime proof **PENDING** |
| Volumes | no persistent app state | No `volumes:` in compose; legacy `lotto-*-data` cleaned on remove | **REMEDIATED** | `compose.yaml`; `remove.sh` | HD-D5 |
| Networking | TLS/WSS topology | Bridge network; publish `127.0.0.1:${HOST_PORT}:8080`; **host nginx** (`configure-proxy.sh`) serves static + TLS + `/ws` proxy | **FAIL** | `compose.yaml` L34–43; `configure-proxy.sh` L55–170 | **HD-D10** — all-in-container; host-split superseded |
| Configuration | runtime contract | `LOTTO_*` via compose; origins from install FQDN detect or flags; no `RUSBINGO_DOMAIN` | **GAP** | `compose.yaml` L23–31; `install.sh` L78–88 | Align installer with HD-D8 domain precedence |
| Security | container hardening | `cap_drop: [ALL]`, `no-new-privileges`, non-root; `read_only` removed for HD-D5 writable `/app/data` | **PASS** / **OBSERVATION** | `compose.yaml` | D8.1 vulnerability scan at gate D8.1 |
| Observability | logs / health | `LOTTO_*_LOG=php://stdout`; compose + Dockerfile HEALTHCHECK via `healthcheck.php` | **PASS** | `compose.yaml` L26–27, L36–41; `Dockerfile` L36–37, L42–43 | — |
| Installer | HD-D8 readiness | `install.sh`/`remove.sh` exist; **requires preinstalled Docker + git checkout**; partial idempotency | **GAP** | `common.sh` L46–59, L80–84; `install.sh` L76, L142–143 | Future unified installer; Docker Engine bootstrap |

### Material findings

#### F-D2-01 — Named Docker volume for application state (**REMEDIATED** — HD-D5)

| Field | Value |
|-------|-------|
| Files | `deploy/docker/compose.yaml` (historical L32–33, L49–51 at D2 audit) |
| Observed (D2) | `data:/app/data` named volume `lotto-<instance>-data` stored `game.db` on host |
| Remediation | HD-D5 (2026-09-06): volume mount removed; `/app/data` in container writable layer |
| Status | **REMEDIATED** — static + runtime checks at remediation commit |
| ADR / Human | **HD-D5** |

#### F-D2-02 — Build uses mutable git checkout, not HD-D9 release archive (**REMEDIATED** — HD-D9)

| Field | Value |
|-------|-------|
| Files | `deploy/docker/install.sh`; `deploy/docker/lib/common.sh` (historical); `deploy/docker/lib/release-artifact.sh` |
| Observed (D2) | `lotto_repo_check` required git checkout; `LOTTO_BUILD_CONTEXT` = repo root |
| Remediation | HD-D9 (2026-09-06): `--release-archive` + trusted manifest SHA256 → extract → build context |
| Status | **REMEDIATED** — no mutable Git checkout fallback |
| ADR / Human | **HD-D9** |

#### F-D2-03 — Zero residue requires explicit volume removal (**PARTIAL** — HD-D5)

| Field | Value |
|-------|-------|
| Files | `deploy/docker/remove.sh`; `deploy/docker/compose.yaml` |
| Observed (D2) | `remove.sh` required explicit volume deletion; `compose down` retained named volume |
| Remediation | HD-D5: no application volume; `compose down` removes container-local `game.db` |
| Remaining | D10 gate still **PENDING** — host metadata, legacy volumes, nginx/`public/` per HD-D10 |
| ADR / Human | **HD-D5** partial; **D10** open |

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
| Next action | *(historical D2 audit)* Recommend close — **superseded:** HD-D6 **APPROVED** 2026-09-06 |
| ADR / Human | **HD-D6 APPROVED** — `network_mode: host` excluded; bridge + published ports |

### Section audits (summary)

| # | Section | Result | Notes |
|---|---------|--------|-------|
| 1 | Dockerfile | **GAP/FAIL** | Sound WS runtime; missing `public/`; build from mutable context |
| 2 | compose.yaml | **REMEDIATED** / **GAP** | HD-D5: no app volume; HD-D10 networking/static still open |
| 3 | Workerman lifecycle | **PASS** | `count=1`; stdout logs; healthcheck; SIGTERM needs D3 runtime proof |
| 4 | Configuration | **GAP** | `LOTTO_*` wired; domain model incomplete vs HD-D8 |
| 5 | Networking | **OPEN** | Host nginx + loopback upstream; no TLS in container |
| 6 | Storage / zero residue | **PARTIAL** | HD-D5: container-local DB; D10 host metadata/nginx **PENDING** |
| 7 | DB initialization | **PASS** | `init_db.php` via `compose exec`; AHPC per ADR-038 |
| 8 | Release artifact (HD-D9) | **REMEDIATED** | Verified archive + SHA256; hosting/download still open |
| 9 | Security (static) | **PASS** | Hardening baseline present |
| 10 | Observability | **PASS** | stdout + healthcheck |
| 11 | Installer readiness (HD-D8) | **GAP** | Partial scripts only |

### Database initialization (detail)

| Item | Observation |
|------|-------------|
| Mechanism | `install.sh` runs `compose up`, then `compose exec … php init_db.php` when `game.db` absent |
| Path | `LOTTO_DB_PATH=/app/data/game.db` in container writable layer |
| AHPC | `LOTTO_ADMIN_BOOTSTRAP_FILE=/app/data/.admin_bootstrap` → promoted via `compose exec` |
| Repeat install | Skips `init_db` when `game.db` exists in running container |
| ADR-038 | Consistent with Docker AHPC path |

### Recommended remediation order (post Human review)

1. ~~**HD-D5**~~ — **REMEDIATED** (2026-09-06). See § HD-D5 remediation.
2. ~~**HD-D9 implementation**~~ — **REMEDIATED** (2026-09-06). See § HD-D9 remediation.
3. **HD-D10 remediation** — Embed `public/` in container; retire host nginx/host-static as Docker V1 delivery path.
4. **HD-D8 installer** — Docker Engine bootstrap, `RUSBINGO_DOMAIN` precedence, idempotency, unified flow.
5. **D10 scope** — Teardown per HD-D10: container, volumes, host `public/` copy, nginx/certs, installer artifacts.
6. **D3 validation** — Runtime proof: SIGTERM, zero residue, cold install on certified OS.

### ADR triggers (recommendations only — no ADR created)

| Trigger | Recommendation |
|---------|----------------|
| Storage remediation | Update ADR-039 consequences or short amendment when implementation starts — ADR-036 already marked superseded for V1 |
| Host-split static/nginx | **Resolved by HD-D10** — ADR-036 supersession note documents historical staging model |

### Human decisions required *(historical D2 audit — 2026-09-06)*

| ID | Question | Current status (2026-09-06) |
|----|----------|----------------------------|
| **HD-D6** | `network_mode: host` | **APPROVED** — excluded |
| — | Artifact hosting/download | Open (HD-D9 boundary) |

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

## D2.1 — Installation contract freeze — **FROZEN** (2026-09-06)

**Status:** Contract frozen for D3 validation. **Not** implementation complete; gaps
between contract/policy and current `deploy/docker/` scripts are explicitly recorded.

**Canonical application input (HD-D9 APPROVED):** Application **v1.1** —
`ed42d7a2d278a7f27260fc06249b14bc1d638b6d`, archive SHA256
`568f528bd32c854f637fb2c31afaeeefeb57aceb8a50331d0dd0daeae60e944a`, manifest
`deploy/docker/release-manifests/v1.1.env`. NLD `v1.0` unchanged.

Normative contract sections below. **Certification/release floor** = V1 release
requirement. **Installer enforcement** = future implementation (HD-D8); not required
to be fully implemented at D2.1 freeze.

---

### 1. Supported host

| Item | Certification / release floor (HD-D7 APPROVED) | Current installer enforcement |
|------|-----------------------------------------------|------------------------------|
| Certified OS | Ubuntu 22.04 LTS, Ubuntu 24.04 LTS, Debian 12 | `lotto_os_check`: Linux + debian/ubuntu only |
| Compatibility OS | Other Linux with Docker + Compose + resources — **not certified** until matrix PASS | Same debian/ubuntu gate only |
| Docker Engine | **≥ 24.0** | **GAP:** presence + daemon reachability only; no version floor |
| Compose | **V2 plugin** (`docker compose`) | **Partial:** `docker compose version` required |
| CPU | **≥ 1 vCPU** | **GAP:** not checked |
| RAM | **≥ 1 GiB** | **GAP:** not checked (container default `mem_limit=256m`) |
| Free disk | **≥ 5 GiB** | **GAP:** not checked |
| Host capabilities | Docker Engine + Compose plugin; root/sudo for install scripts | `lotto_docker_check` |

---

### 2. Installer entrypoint

| Item | Contract |
|------|----------|
| Canonical entrypoint | `sudo ./deploy/docker/install.sh` |
| Root/sudo | **Required** (Docker socket, `/var/lib/lotto-game/`, port bind) |
| Required input | `--release-archive PATH` (immutable application release archive) |
| Default application version | `v1.1` (`--application-version` overrides manifest lookup) |
| Default manifest | `deploy/docker/release-manifests/<version>.env` |
| Non-interactive | `--non-interactive` → exit **42** when AHPC admin credential pending |
| Success exit | **0** (or **42** for non-interactive handoff) |
| Failure exit | Non-zero; `set -euo pipefail`; invalid args → **2** |
| Auxiliary scripts | `healthcheck.sh`, `remove.sh`, `admin-bootstrap.sh` (not part of install contract) |

**HD-D8 policy gaps (not D2.1 blockers):** unified RUSBINGO installer wrapper; Docker
Engine bootstrap when absent; prerequisite version/resource enforcement.

---

### 3. Domain resolution

**Frozen policy order (HD-D8 APPROVED):**

1. `RUSBINGO_DOMAIN` — if set and valid
2. System hostname
3. Interactive prompt if hostname unusable

**Valid domain (D3 acceptance):** public FQDN matching
`^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$`;
for auto-detected origins, DNS **A record** must resolve (`lotto_validate_fqdn_dns`).

**Current implementation (`install.sh` / `common.sh`):**

| Policy step | Implemented | Notes |
|-------------|-------------|-------|
| `RUSBINGO_DOMAIN` | **GAP** | Not read; use `--allowed-origins` or auto-detect path |
| Hostname | **Partial** | `lotto_detect_provisioning_fqdn`: static hostname via `hostnamectl` / `/etc/hostname` |
| Interactive prompt | **GAP** | Not implemented |
| Override (testing) | Yes | `LOTTO_PROVISIONING_FQDN_OVERRIDE` env (installer helper, not policy variable) |

When FQDN auto-detect succeeds and `--allowed-origins` unset, installer sets
`LOTTO_ALLOWED_ORIGINS` to `http://<fqdn>,https://<fqdn>`.

In-container interactive domain configuration: **forbidden** (HD-D8).

---

### 4. Release acquisition

**Required flow (contract):**

```text
distribution channel (HD-D4 V1: GitHub Releases — policy)
    → immutable release archive file on host
    → trusted release manifest (version, full Git SHA, archive SHA256)
    → SHA256 verification
    → verified extraction → LOTTO_BUILD_CONTEXT
    → docker build (Dockerfile from verified context)
    → container runtime
```

**Forbidden:** `latest` / `main` checkout as build input; floating archive identity;
unverified archive; post-verification application overlay from `LOTTO_REPO_ROOT`.

**Current implementation:** Operator supplies local `--release-archive`; manifest
from `release-manifests/v1.1.env` by default; `lotto_prepare_instance_release_build`
verifies SHA256 and extracts only. **GAP:** installer does not download from GitHub
Releases (HD-D4 V1 channel policy; download implementation pending).

---

### 5. Container topology

| Item | Contract |
|------|----------|
| Containers per instance | **One** application container (`lotto-<name>-app`) |
| Workerman workers | **One** (`server.php` worker count = 1) |
| SQLite databases | **One** per container (`/app/data/game.db`) |
| Multi-writer / shared SQLite | **Unsupported** (D5.1 boundary test later) |
| Multi-instance on one host | Tooling allows `--name`; **not** certified V1 production topology |

---

### 6. Ports and network contract

**Networking (HD-D6 APPROVED):** Docker **bridge** network per instance
(`lotto-<name>-net`). **`network_mode: host` excluded.**

| Item | Contract |
|------|----------|
| HTTP | Published `${LOTTO_BIND_ADDRESS}:${LOTTO_HOST_PORT}` → container port (default **8080**) |
| WebSocket | Same published port; path **`/ws`** (`LOTTO_WS_PATH=/ws`); SPA contract `lotto-ws-port=""` |
| Bind address | Configurable (`--bind`; default **0.0.0.0**) |
| Host port | Configurable (`--port`; default auto-pick or reuse from `instance.env`) |
| Container port | Configurable (`--container-port`; default **8080**) |
| TLS/WSS | **OPEN** — not part of V1 install contract |

---

### 7. Configuration

| Layer | Variables / artifacts |
|-------|----------------------|
| Installer CLI | `--name`, `--port`, `--bind`, `--container-port`, `--mem-limit`, `--cpu-limit`, `--pids-limit`, `--allowed-origins`, `--trusted-proxy-ips`, `--max-accounts-per-ip`, `--application-version`, `--release-archive`, `--release-manifest`, `--non-interactive` |
| Installer env (FQDN testing) | `LOTTO_PROVISIONING_FQDN_OVERRIDE`, `LOTTO_STATE_ROOT`, `LOTTO_APPLICATION_VERSION`, `LOTTO_RELEASE_ARCHIVE` |
| Generated host metadata | `/var/lib/lotto-game/<instance>/instance.env` — image, build context, ports, provenance, compose project |
| Verified release provenance | `/var/lib/lotto-game/<instance>/verified-release/release-provenance.env` |
| Container environment | `LOTTO_WS_PORT`, `LOTTO_HTTP_PUBLIC`, `LOTTO_WS_PATH`, `LOTTO_DB_PATH`, `LOTTO_ALLOWED_ORIGINS`, `LOTTO_TRUSTED_PROXY_IPS`, `LOTTO_MAX_ACCOUNTS_PER_IP`, logging paths |
| Immutable release metadata | `LOTTO_APPLICATION_VERSION`, `LOTTO_APPLICATION_GIT_SHA`, `LOTTO_RELEASE_ARCHIVE_SHA256` in manifest, `instance.env`, image build args |

**Policy variable not yet wired:** `RUSBINGO_DOMAIN` (see §3 gap).

---

### 8. Storage and lifecycle contract

| Item | Contract |
|------|----------|
| Database | `/app/data/game.db` inside container writable layer |
| Writable paths | `/app/data/` (SQLite, Workerman PID file); `/tmp` (tmpfs) |
| Host application state | **None** — no named app volume, no `game.db` bind mount |
| Host metadata | `/var/lib/lotto-game/<instance>/` (instance.env, verified-release, AHPC pending) — **not** game state |
| Survives container restart | Yes — same container filesystem |
| Destroyed on container removal | Application state including `game.db` |
| Backup | Exportable artifact only (D6 gate — **PENDING**); not a persistent volume |

**Later validation:** D5 persistence/restart; D10 zero residue after uninstall.

---

### 9. Post-install verification (D3 acceptance criteria)

Contract-level success **before** D3 execution:

| Check | Method (current tooling) |
|-------|---------------------------|
| Container running | `docker ps`; `lotto_container_exists` |
| Healthcheck passing | Compose healthcheck + `lotto_wait_healthy` (120s) |
| HTTP reachable | `http://<bind>:<host-port>/` serves SPA from `/app/public` |
| WebSocket reachable | `ws://<bind>:<host-port>/ws` (or WSS when TLS decided) |
| Database initialized | `/app/data/game.db` exists in container |
| Provenance matches release | `instance.env` + `release-provenance.env` agree with `v1.1` manifest |
| Admin bootstrap (new install) | AHPC pending file; non-interactive exit **42** |

**Not in contract:** TLS/WSS termination (OPEN).

---

### 10. Idempotency

**Contract expectation (HD-D8):** Re-run must not create duplicate independent game
servers or duplicate application state.

**Current behavior:**

| Scenario | Behavior |
|----------|----------|
| Re-run same `--name` | Updates image/container; reuses saved port/bind; skips `init_db` if `game.db` exists |
| Fresh install failure | `cleanup_on_error` may remove partial instance (preserves AHPC pending) |
| Different `--name` | Separate instance — **not** certified multi-instance V1 topology |

**Gaps:** No unified installer guard against unsupported multi-instance production;
HD-D8 full idempotency semantics pending implementation.

---

### 11. Failure boundary

Practical contract (current `install.sh` behavior):

| Stage | On failure |
|-------|------------|
| Prerequisite / OS / Docker | Error message; exit non-zero; no partial container |
| Domain / origins auto-detect | FQDN/DNS failure skips auto origins (install may continue with empty `LOTTO_ALLOWED_ORIGINS`) |
| Missing / invalid archive | Exit before build |
| SHA256 verification | Exit before extract |
| Extract / manifest | Exit before build context use |
| Docker build / start | Exit; fresh install triggers partial cleanup |
| DB init / healthcheck | Exit; fresh install triggers partial cleanup |
| Rollback guarantee | **None** beyond partial instance cleanup on fresh-install ERR trap |

---

### 12. Uninstall boundary

| Item | Contract |
|------|----------|
| Entrypoint | `sudo ./deploy/docker/remove.sh --name <instance> --yes` |
| Must remove | Container, instance bridge network, instance image (if unshared), host metadata dir |
| Must not retain | Persistent host `game.db`, application named volumes, application bind mounts |
| Game state | Destroyed with container (no host recovery without D6 export) |

**D10** will validate zero residue; contract defined here for later gate.

---

### Human Decision references (unchanged)

HD-D2, HD-D6, HD-D7, HD-D9 remediation — **APPROVED** (see register above).

### Contract freeze record

| Field | Value |
|-------|-------|
| **Result** | **FROZEN** |
| **Date (UTC)** | 2026-09-06 |
| **Branch / basis** | `fix/docker-hd-d9-provenance` (`fd84a48`, `946c534`) |
| **Contract location** | This section + `ROADMAP_DOCKER_V1.md` § D2.1 |
| **D3** | **NOT STARTED** |

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

### Human Decision HD-D2 — **APPROVED** (2026-09-06)

| Severity | Policy |
|----------|--------|
| CRITICAL | **0 allowed** — any CRITICAL blocks release |
| HIGH | No numeric count cap; every HIGH requires individual documented technical disposition |
| HIGH (block) | Any exploitable or unresolved HIGH blocks release |

Per-accepted-HIGH evidence required: CVE, severity, affected component, runtime
affected (Y/N), exploitability, justification, disposition, mitigation if any.

Generic «HIGH not exploitable» without per-finding justification is **insufficient**.

**Numeric HIGH count threshold:** **waived / N/A** (APPROVED 2026-09-06).

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

## HD-D4 — Registry + distribution channel (policy)

**Status:** **DECIDED** (2026-09-06)

### HD-D4-A — OCI registry-independent contract

| Rule | Status |
|------|--------|
| OCI/container-image portable | **DECIDED** |
| No dependency on one registry's proprietary features | **DECIDED** |
| Production evidence uses immutable digest | **DECIDED** |
| Must not block future Docker Hub publication | **DECIDED** |
| Pre-built OCI image distribution as V1 prerequisite | **Rejected** |
| Specific OCI registry / repository / tag / credentials | **Not decided** — Docker Hub **not selected** |

### HD-D4 V1 — GitHub Releases distribution channel

**Decision:** GitHub Releases is the **official distribution channel** for Docker V1
immutable application release archives.

```text
Application Release → GitHub Release → immutable archive + trusted SHA256
        → Docker installer (future) → SHA256 verification → Docker build
```

| Rule | Status |
|------|--------|
| Official V1 distribution channel | **GitHub Releases** |
| Distribution identity | Application version + full Git SHA + artifact filename + artifact SHA256 |
| SHA256 verification | **Mandatory**; GitHub asset ≠ trusted expected SHA256 |
| Git / mutable checkout | **Forbidden** for Docker installation |
| Runtime dependency on GitHub | **None** after install |
| Distribution channel in runtime architecture | **Excluded** |
| Replaceability | Channel replaceable if immutable artifact + metadata + SHA256 + provenance contract preserved |
| Installer GitHub download | **Not implemented** (HD-D8 future) |
| GitHub Actions / Release publication | **Not implemented** |

**Baseline `v1.0` reference values:**

| Field | Value |
|-------|-------|
| Application version | `v1.0` |
| Full Git SHA | `508cc280704ed72cc3e85df03e57bd6fb42d24ee` |
| Archive SHA256 | `780bb0ea9157a326908afee593f3f7acbbf1c043903094c2bbd7072e4eb166a8` |
| Trusted manifest | `deploy/docker/release-manifests/v1.0.env` |

**Identity separation:** Git tag/version ≠ full Git SHA ≠ archive SHA256 ≠ image digest.

Implementation evidence (OCI registry): **PENDING**.
Implementation evidence (installer download): **PENDING**.

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
