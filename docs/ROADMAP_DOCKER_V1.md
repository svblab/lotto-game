# ROADMAP — Docker V1 Validation & Release

**Status:** Accepted roadmap (documentation only — **not** a Docker release approval)  
**Repository:** `svblab/lotto-game`  
**Last reviewed against `main`:** `196f80c` (2026-09-06)

**Related:** [`docs/ROADMAP_V1_PRODUCTION.md`](ROADMAP_V1_PRODUCTION.md) (NLD V1.0 — **released**), [`docs/RELEASE_CONTRACT_V1.md`](RELEASE_CONTRACT_V1.md), [`docs/DOCKER_V1_EVIDENCE.md`](DOCKER_V1_EVIDENCE.md), [`docs/ADR/039-docker-v1-distribution-target.md`](ADR/039-docker-v1-distribution-target.md)

---

## Purpose

Зафиксировать **отдельный** путь валидации и release для **Docker V1** как
самостоятельного installation/distribution target RUSBINGO.

Этот документ:

- **не** реализует Docker;
- **не** изменяет NLD V1.0 (Native Linux Deployment);
- **не** заменяет [`docs/RELEASE_CONTRACT_V1.md`](RELEASE_CONTRACT_V1.md);
- **не** объявляет Docker release автоматически.

Docker V1 проходит **собственный** цикл валидации, собирает **собственные**
evidence и требует **собственное** Human release decision (**H-D1**).

---

## Status & scope boundaries

| Item | Contract |
|------|----------|
| **NLD V1.0** | **Released** — Git tag `v1.0` → `508cc280704ed72cc3e85df03e57bd6fb42d24ee`. **Не пересматривается** этим roadmap. |
| **Docker V1** | Отдельный distribution / installation target. Собственный validation track (D0–D12) и release identity. |
| **Application logic** | Остаётся в основном application repository / release artifact. **Не делать fork** application logic в Docker project. |
| **Docker project** | Отдельный слой: packaging, orchestration, installation, configuration, lifecycle, backup/restore procedures, uninstall, Docker-specific docs. |

### Non-goals (этот roadmap)

- Не менять `src/`, application runtime, NLD production nginx/systemd.
- Не создавать Docker release tag в рамках фиксации roadmap.
- Не выполнять VPS reset, SSH/deployment, image build как часть этой задачи.
- Не добавлять persistent host volume для `game.db`.
- Не изменять существующий NLD `v1.0` tag или release contract.

---

## Relationship to NLD V1.0

```text
Application release v1.0 (immutable SHA)
        │
        ├── NLD V1.0  ──► native systemd + nginx + /opt/lotto-game  [RELEASED]
        │
        └── Docker V1 ──► separate validation track (this document)  [NOT RELEASED]
```

NLD и Docker — **разные distribution targets** одного application release.
Docker validation **не** наследует PASS/FAIL gates G0–G11 NLD автоматически.

---

## Release flow

```text
D0  — V1.0 baseline / immutable artifact
        ↓
D1  — Docker project architecture
        ↓
D1.1 — Artifact resolution (mandatory)
        ↓
D2  — Docker implementation audit
        ↓
D2.1 — Installation contract freeze
        ↓
D3  — Clean installation
        ↓
D4  — Functional / browser E2E
        ↓
D5  — Persistence & container lifecycle
        ↓
D5.1 — Unsupported multi-instance / SQLite boundary test
        ↓
D6  — Backup / restore
        ↓
D7  — Recovery
        ↓
D8  — Security audit
        ↓
D8.1 — Image vulnerability scan
        ↓
D9  — Observability
        ↓
D10 — Zero residue / uninstall
        ↓
D11 — Clean reinstall
        ↓
D12 — Final regression
        ↓
H-D1 — Human approval
        ↓
Docker Release (separate identity — not NLD v1.0)
```

---

## D0 — V1.0 baseline / immutable artifact

### Goal

Зафиксировать исходный application release, на котором строится Docker
distribution. Docker validation **не** должна silently использовать изменяющийся
`main`.

### Requirements

| Requirement | Value |
|-------------|-------|
| Application release | Git tag **`v1.0`** |
| Release SHA (immutable) | `508cc280704ed72cc3e85df03e57bd6fb42d24ee` |
| Mutable `main` as build source | **Forbidden** for Docker V1 validation without explicit Human decision |
| Artifact integrity | Mandatory verification before Docker build/install |

### Evidence mechanism (identity)

Один из механизмов evidence идентичности application artifact:

```bash
git archive --format=tar.gz --prefix=rusbingo/ v1.0 | sha256sum
```

**Recorded example** (computed 2026-09-06, tag `v1.0`):

```text
sha256: 780bb0ea9157a326908afee593f3f7acbbf1c043903094c2bbd7072e4eb166a8
```

> **Note:** Artifact hash — это evidence **идентичности** application source tree
> на release SHA. Сам механизм **получения** application artifact для Docker
> build/install определяется на этапе **D1.1** (Artifact Resolution). D0 не
> предписывает единственный способ доставки — только фиксирует, **какой** release
> является baseline и как проверить его целостность.

### Acceptance

- [ ] Release SHA зафиксирован в evidence (`DOCKER_V1_EVIDENCE.md` D0)
- [ ] Artifact hash записан и верифицирован
- [ ] Docker validation plan ссылается на `v1.0`, не на floating `main`

---

## D1 — Docker project architecture

### Principle

**Один application → несколько distribution targets.**

Docker project — **отдельный проект/слой**, не второй fork приложения.

### Docker project responsibilities

| Area | Scope |
|------|-------|
| Dockerfile | Image build definition |
| Compose / orchestration | Container lifecycle |
| Installation | Host-side installer scripts |
| Configuration | Domain, origins, proxy integration |
| Lifecycle | Start, stop, restart, upgrade, uninstall |
| Backup / restore | Export/import SQLite as portable artifact |
| Documentation | Docker-specific operator docs |
| Release packaging | Image identity, registry push (when applicable) |

### Application repository responsibilities

| Area | Scope |
|------|-------|
| `src/`, `public/`, `server.php` | Application business logic and runtime |
| Protocol, economy, state machine | Unchanged by Docker layer |
| NLD production deployment | `docs/ADMIN_VPS_DEPLOY.md` — separate track |

### Forbidden

- Копировать `src/` и application business logic в Docker project как
  **независимую ветку разработки**.
- Дублировать runtime source вне immutable release artifact resolution (D1.1).

---

## D1.1 — Artifact resolution (mandatory)

### Human Decision HD-D1 — **RESOLVED** (2026-09-06)

**Decision:** **Immutable Release model** (variant B).

> Каждый Docker release жёстко соответствует конкретному immutable application release.

#### Release chain (RUSBINGO)

```text
Application v1.0
    ↓
immutable release artifact
    ↓
SHA256 verification
    ↓
Docker Release 1.0
```

При следующей модернизации приложения:

```text
Application v1.1
    ↓
immutable release artifact
    ↓
SHA256 verification
    ↓
Docker Release 1.1
```

#### Coexistence rule (no silent updates)

```text
Docker Release 1.0  →  продолжает работать на Application v1.0
Application v1.1    →  не изменяет существующие установки Docker 1.0
Docker Release 1.1  →  отдельный release, основанный на Application v1.1
```

Существующая установленная Docker версия **не должна автоматически получать
изменения** из `main`, latest source или другого mutable источника.

### Architectural principles (HD-D1)

| # | Principle |
|---|-----------|
| 1 | Docker release использует **immutable application release artifact**. |
| 2 | Artifact относится к **конкретному** application tag/release. |
| 3 | Artifact должен иметь **проверяемый SHA256**. |
| 4 | Docker build/install **не должен зависеть** от mutable `main`. |
| 5 | Docker release identity должна **однозначно определять** application release, из которого он построен. |
| 6 | Existing Docker installation **не обновляется автоматически** при появлении нового application release. |
| 7 | Новый application release порождает **отдельный** Docker release. |
| 8 | Upgrade существующей установки — **отдельная будущая операция**; **не входит** в HD-D1. |

### HD-D1 explicitly does NOT decide

Следующие вопросы остаются **отдельными** Human decisions / будущими этапами:

| Topic | Owner |
|-------|-------|
| Где хранится artifact (GitHub Release, другой storage, OCI artifact, …) | Future decision |
| Container registry | **HD-D4** |
| Docker image naming | **HD-D4** |
| Docker release tag naming | **HD-D3** |
| Upgrade command / procedure | Future decision |
| Backup-before-upgrade implementation | Future decision |
| Migration mechanism для SQLite | Future decision |

### Backup/restore note (future upgrade context)

> Поскольку Docker V1 хранит application state внутри container filesystem,
> будущий upgrade между immutable Docker releases должен учитывать
> сохранение/восстановление game state.

Upgrade **не проектируется и не реализуется** в рамках HD-D1. D6 Backup/Restore
и будущий upgrade lifecycle остаются отдельными этапами.

### Requirement (unchanged)

Docker build/install **должен быть воспроизводим** без доступа к mutable source
tree / `main` branch.

Baseline application release: tag **`v1.0`** → SHA
`508cc280704ed72cc3e85df03e57bd6fb42d24ee`.

### Must document before D3 (delivery mechanism — not HD-D1)

| Item | Description |
|------|-------------|
| **Artifact source** | Откуда Docker project получает immutable artifact (storage/delivery — **не** определено HD-D1) |
| **Artifact identifier** | Application tag + SHA + SHA256 content hash |
| **Verification mechanism** | SHA256 verification against D0 recorded hash |
| **Checksum mismatch** | Build/install **MUST FAIL**; no silent fallback to `main` or working tree |
| **Version pinning** | Docker release pinned to exactly one application release (e.g. `v1.0` / `508cc28`) |
| **Release identity mapping** | Docker release identity → application release (one-to-one) |

### Acceptance

- [x] HD-D1 Immutable Release model recorded
- [ ] Artifact delivery mechanism documented (storage location — separate decision)
- [ ] Reproducible build demonstrated without mutable `main` checkout
- [ ] Evidence linked in `DOCKER_V1_EVIDENCE.md` D1.1

---

## D2 — Docker implementation audit

### Goal

Перед установкой на чистый VPS провести аудит **существующей** Docker реализации
(`deploy/docker/`) против контракта Docker V1. Зафиксировать gaps.

**Known baseline conflict:** ADR-036 (historical) описывает named volume
`lotto-<instance>-data` для SQLite. Docker V1 contract (ADR-039) **запрещает**
persistent application volume. D2 audit **обязан** задокументировать расхождение
и план remediation **до** D3.

### Storage (critical)

| Rule | Status |
|------|--------|
| Game database **inside container filesystem** | **Required** |
| Host-mounted `game.db` | **Forbidden** |
| Persistent host directory for game state | **Forbidden** |
| Named Docker volume for application state | **Forbidden** |
| Bind mount with persistent game data | **Forbidden** |

**Docker V1 model:** после удаления container game state **исчезает** (unless
restored from explicit backup artifact per D6).

### Logs

| Rule | Status |
|------|--------|
| Runtime logs create persistent game-server data on host | **Forbidden** |
| Preferred: stdout/stderr → Docker logging | **Required** |
| Additional in-container log files | Only with explicit justification in audit |

### Network

Audit and document:

- `network_mode: host` — **not automatically acceptable**; must be justified or excluded
- Published ports vs exposed ports
- Unix sockets
- nginx/proxy model (host-side vs container)
- WSS path (`/ws`)
- Loopback / bridge networking
- Which ports are reachable from host/network

### Workerman

Audit and document:

- Worker count (`Worker->count`)
- Startup sequence
- SIGTERM / graceful shutdown
- Restart behaviour
- Container stop/start interaction
- Absence of multiple independent Workerman instances

**Supported V1 model:**

```text
1 container → 1 Workerman worker → 1 SQLite database
```

### Configuration

Audit and document:

- Domain resolution (see D2.1)
- `LOTTO_ALLOWED_ORIGINS`
- Trusted proxy configuration
- WebSocket URL/path
- Absence of hardcoded production domain in image/build
- Absence of secrets in Git

### Acceptance

- [ ] Audit report in evidence D2 with PASS/FAIL per area
- [ ] Gaps documented with remediation plan before D3

---

## D2.1 — Installation contract freeze

### Goal

До реальной установки на VPS зафиксировать **Docker Installation Contract**.

### Domain resolution

Supported UX (host-side installer only):

1. `RUSBINGO_DOMAIN` — if explicitly set
2. Else system hostname
3. If hostname missing / invalid / unsuitable — **interactive prompt**

| Rule | Detail |
|------|--------|
| Interactive input | Allowed **only** at host-side install time |
| In-container interactive config | **Forbidden** |
| Domain validation | Must reject invalid hostnames; document rules |
| Precedence | `RUSBINGO_DOMAIN` > hostname > prompt |
| Generated configuration | Document what files/env/meta are produced |
| Invalid input | Document retry / abort behaviour |

### Installation model

Must define:

| Item | To be specified |
|------|-----------------|
| Prerequisites | Docker Engine, Compose plugin, OS |
| Supported OS | e.g. Debian/Ubuntu LTS (exact matrix — Human at D2.1) |
| Required Docker version | Minimum tested version |
| Required Compose version | Plugin vs standalone |
| Required ports | Host ports for HTTP/HTTPS/WSS upstream |
| Filesystem assumptions | Host paths for install metadata only (no game data volume) |
| Install command | Canonical entry point |
| Uninstall command | Canonical entry point |
| Upgrade model | How image/app version changes without breaking contract |

### Persistence model

> **Docker V1 intentionally does NOT persist application state on the host.**

If backup is required to preserve data, backup is an **exportable artifact** —
not a persistent Docker volume.

### Supported topology

| Item | Contract |
|------|----------|
| Containers | **One** application container per instance |
| Workerman workers | **One** |
| SQLite databases | **One** per container |
| Shared SQLite between containers | **Unsupported** |
| Multi-instance production topology | **Unsupported** in Docker V1 |

### Acceptance

- [ ] Installation contract document frozen in evidence D2.1
- [ ] Human review of domain resolution and topology rules

---

## D3 — Clean installation

### Goal

Установка на VPS с **чистой OS** (only OS + prerequisites).

### Checks

- Docker installation (if not preinstalled — operator responsibility)
- Image build or pull (per D1.1 artifact resolution)
- Container creation
- Application startup
- Domain configuration
- HTTPS/WSS integration
- SQLite creation **inside container**
- Workerman startup
- Cold start time measurement
- Installation repeatability (second run on fresh host)

### Evidence

- Installation audit/evidence log with commands and outputs
- Temporary installation logs/evidence **must not** violate Zero Residue (D10)

### Acceptance

- [ ] Clean install PASS on disposable VPS
- [ ] Evidence recorded in `DOCKER_V1_EVIDENCE.md` D3

---

## D4 — Functional / browser E2E

### Goal

Полный пользовательский сценарий, аналогичный NLD V1.0 production E2E.

### Minimum checks

- HTTPS
- WSS (`wss://<domain>/ws`)
- Authentication (register, login, wrong password)
- Room create/join
- Game lifecycle (start, draw, finish, payout)
- Page reload
- Reconnect
- Relevant frontend/backend smoke tests

### Deliverable

Отдельный evidence document (section in `DOCKER_V1_EVIDENCE.md` D4 or linked
report).

### Acceptance

- [ ] Browser E2E PASS on Docker installation @ `v1.0` application SHA

---

## D5 — Persistence & container lifecycle

### Goal

Подтвердить модель persistence **внутри жизненного цикла container**, не через
host volume.

### Procedure

1. Application works
2. Create known game state (account, room, in-progress or completed game)
3. **Container restart** (`docker restart` / compose restart)
4. State **persists** after restart of **same** container
5. **Container removal** (`docker rm` / compose down without restore)
6. **New** container without restore **does not** contain old game state

### Critical interpretation

```text
Docker V1 persistence = persistence within existing container lifecycle
Docker V1 persistence ≠ persistence via host-mounted volume
```

### Acceptance

- [ ] Steps 1–6 verified with evidence
- [ ] No host volume used for game state

---

## D5.1 — Unsupported multi-instance / SQLite boundary test

### Goal

Зафиксировать, что shared SQLite между несколькими production containers **не
является** supported configuration.

### Rules

- **Do not** create shared persistent volume for SQLite as part of supported architecture
- **Do not** promote negative test into mandatory production topology

### Optional

Negative/boundary test demonstrating failure or corruption risk when multiple
containers access one SQLite file.

### Acceptance

- [ ] Boundary documented as **unsupported**
- [ ] Optional negative test recorded if executed

---

## D6 — Backup / restore

### Model

1. Create known game state
2. Export SQLite backup artifact (from running container)
3. Destroy / recreate container
4. Create clean container
5. Import backup
6. Verify restored state

### Rules

| Rule | Detail |
|------|--------|
| Backup artifact location | May temporarily exist on host or as external artifact |
| Persistent application volume | **Forbidden** |
| Integrity | Verify backup hash / `PRAGMA integrity_check` |

### Acceptance

- [ ] Full backup/restore cycle executed and evidenced

---

## D7 — Recovery

### Scenarios

| Scenario | Expected behaviour (to be documented per scenario) |
|----------|---------------------------------------------------|
| Container restart | |
| Container stop/start | |
| Application restart (in-container) | |
| Proxy restart (if proxy in installation) | |
| Host reboot (if possible) | |
| Recovery after container failure | |
| Recovery after container recreate (no backup) | Clean state |
| Restore from backup | State from backup artifact |

### Acceptance

- [ ] Each scenario: expected vs actual documented
- [ ] Recovery PASS for supported scenarios

---

## D8 — Security audit

### Scope

Docker-specific security review:

- Exposed / published ports
- Container privileges and capabilities
- Root vs non-root execution
- Filesystem permissions
- Secrets handling
- Image provenance
- Network exposure
- Unnecessary services/packages
- Docker socket exposure
- Host filesystem mounts

### Requirements

| Requirement | Threshold |
|-------------|-----------|
| P0 / P1 findings | **None unresolved** at release |
| CRITICAL vulnerabilities in release image | **0** |
| HIGH vulnerability policy | **Explicitly defined** in D8.1 (not arbitrary) |
| Capabilities | No additions without necessity |
| Base image | No mandate for Alpine/slim for its own sake |

### Finding record

Each finding: severity, evidence, impact, disposition, PASS/FAIL.

### Acceptance

- [ ] Security audit complete with disposition for all findings

---

## D8.1 — Image vulnerability scan

### Tool

Reproducible scanner (e.g. **Trivy** or equivalent).

### Record

| Field | Required |
|-------|----------|
| Image identifier / digest | |
| Scanner name and version | |
| Scan date (UTC) | |
| Findings by severity | |
| Release threshold | |
| Result | PASS / FAIL |

### Minimum threshold

```text
CRITICAL = 0   (hard gate)
HIGH       = policy defined separately with justification (Human at D8.1)
```

**Forbidden:** arbitrary criteria such as «fewer than 5 CRITICAL».

### Acceptance

- [ ] Scan executed on release candidate image
- [ ] PASS per defined thresholds

---

## D9 — Observability

### Checks

- Container stdout
- Container stderr
- `docker logs` / Docker logging driver
- Startup / shutdown events
- Application errors visible in logs
- Restart behaviour
- Health / smoke mechanism

### Rules

| Rule | Detail |
|------|--------|
| JSON logging | **Not** mandatory by itself |
| Health endpoint | Add only if architecturally necessary; prefer existing application smoke (e.g. `healthcheck.php`) |
| PHP-FPM observability | **Not applicable** — Docker V1 uses Workerman, not PHP-FPM |

### Acceptance

- [ ] Operator can determine container health from documented signals

---

## D10 — Zero residue / uninstall

### Goal (critical)

После uninstall Docker installation на host **не должно остаться** game-server data.

### Must verify absence of

- `game.db` on host
- Game state on host
- Application logs persisted on host
- Backups left on host (unless operator explicitly retained)
- Persistent bind mounts for application data
- Application-named Docker volumes
- Application containers
- Application-created Docker networks
- Installation-specific persistent directories
- Application-specific host artifacts

### Verification method

- Docker CLI / API (`docker ps`, `docker volume ls`, `docker network ls`, …)
- Known host paths from installation contract (D2.1)
- **Not** exclusively via `/var/lib/docker` internal structure (implementation detail)

### Post-uninstall state

```text
Host contains only OS + Docker infrastructure + unrelated system data.
No RUSBINGO game-server residue.
```

### Acceptance

- [ ] Uninstall + verification PASS
- [ ] Evidence with command outputs

---

## D11 — Clean reinstall

### Goal

После успешного Zero Residue (D10) доказать воспроизводимость установки.

### Procedure

1. Execute clean install again (D3 procedure)
2. Verify application startup
3. Verify HTTPS/WSS
4. Run smoke / E2E subset
5. Confirm installation does not depend on previous install residue

### Acceptance

- [ ] Reinstall PASS independent of prior installation artifacts

---

## D12 — Final regression

### Goal

На том же Docker release candidate выполнить полный regression.

### Mandatory verification

- Exact application release SHA (`v1.0` / `508cc28`)
- Exact Docker artifact / image identity (digest)
- Clean working tree of Docker project at recorded SHA
- Installation reproducibility
- Functional tests
- Browser E2E
- Persistence (D5)
- Backup/restore (D6)
- Recovery (D7)
- Security (D8)
- Vulnerability scan (D8.1)
- Observability (D9)
- Zero residue (D10)
- Clean reinstall (D11)

### Release identity rule

```text
docker_tested_application_sha  = v1.0 release SHA (508cc28)
docker_tested_image_digest     = immutable image identity
docker_released_image_digest   = docker_tested_image_digest  (at H-D1)
```

### Acceptance

- [ ] All mandatory gates PASS
- [ ] Single unambiguous release identity bundle

---

## H-D1 — Human approval

Docker release **не считается released** автоматически после D12.

Human (**H-D1**) must explicitly confirm:

- [ ] Docker roadmap completed (D0–D12)
- [ ] All mandatory gates PASS
- [ ] Accepted P2/P3 findings listed
- [ ] No unresolved P0/P1
- [ ] Final Docker artifact / image identity
- [ ] Final application release SHA (`v1.0` / `508cc28`)
- [ ] Docker release naming / tagging decided

Only after **H-D1** is Docker Release permitted.

---

## Docker release

### Identity

| Rule | Detail |
|------|--------|
| Separate from NLD | Docker release has **own** version tag / image identity |
| NLD `v1.0` | **Do not modify** |
| `latest` tag | If used — **convenience alias only**, not sole release identity |
| Preferred | Immutable version tag (e.g. `rusbingo-docker-v1.0.0` — exact name: Human decision) |
| Registry | Image naming defined after registry selection (Human decision) |

### Not in scope of this document

- Creating release tag
- Pushing to registry
- Production go-live

---

## Forbidden architectural decisions (Docker V1)

The following are **explicitly forbidden** in Docker V1 supported architecture:

| # | Forbidden |
|---|-----------|
| 1 | Host-mounted `game.db` |
| 2 | Persistent application Docker volume for game state |
| 3 | Persistent host game-data directory |
| 4 | Shared SQLite database between containers |
| 5 | Multi-worker Docker V1 topology (`Worker->count > 1`) |
| 6 | Docker socket inside application container (without separate ADR justification) |
| 7 | Hardcoded production domain in image or Git-tracked config |
| 8 | Secrets in Git |
| 9 | Changing NLD V1.0 release contract or `v1.0` tag for Docker convenience |
| 10 | Forking application logic into Docker project as independent development branch |
| 11 | Using mutable `main` as silent Docker validation baseline |

---

## Evidence

All gate evidence: [`docs/DOCKER_V1_EVIDENCE.md`](DOCKER_V1_EVIDENCE.md).

### Template (per gate)

```text
gate_id:
application_release_tag:    # v1.0
application_release_sha:    # 508cc28
docker_image_id:            # digest when applicable
environment:                # docker-clean-vps | docker-staging | ...
os:
docker_version:
compose_version:
domain:
date_time_utc:
procedure:
expected:
actual:
result:                     # PASS | FAIL | PENDING
artifacts:
notes:
```

---

## Human decision register

| ID | Decision | Status | Owner | When |
|----|----------|--------|-------|------|
| **HD-D1** | Immutable Release model (variant B) — artifact resolution architecture | **RESOLVED** (2026-09-06) | Human | D1.1 |
| **HD-D2** | HIGH vulnerability threshold policy (D8.1) | Open | Human | Before D8.1 PASS |
| **HD-D3** | Docker release naming / tagging | Open | Human | Before H-D1 |
| **HD-D4** | Container registry and image naming | Open | Human | Before Docker release |
| **HD-D5** | Remediation of ADR-036 named-volume implementation vs Docker V1 contract | Open | Human | After D2 audit |
| **HD-D6** | `network_mode: host` — justify or exclude | Open | Human | D2 audit |
| **HD-D7** | Supported OS matrix (exact versions) | Open | Human | D2.1 freeze |
| — | Artifact storage / delivery mechanism (where artifact is hosted) | Open | Human | Before D3 |
| — | Upgrade command, procedure, SQLite migration | Open | Human | Future (post HD-D1) |

---

## References

- [`docs/RELEASE_CONTRACT_V1.md`](RELEASE_CONTRACT_V1.md) — NLD V1.0 (unchanged)
- [`docs/ROADMAP_V1_PRODUCTION.md`](ROADMAP_V1_PRODUCTION.md) — NLD gates G0–G11
- [`docs/DOCKER_V1_EVIDENCE.md`](DOCKER_V1_EVIDENCE.md) — Docker evidence index
- [`docs/ADR/036-docker-compose-deployment.md`](ADR/036-docker-compose-deployment.md) — historical Docker implementation (audit baseline for D2)
- [`docs/ADR/039-docker-v1-distribution-target.md`](ADR/039-docker-v1-distribution-target.md) — Docker V1 architectural decision
- [`deploy/docker/README.md`](../deploy/docker/README.md) — current Docker tooling (to be audited)
