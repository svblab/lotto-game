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

> **Note (D0):** Artifact hash — evidence **идентичности** application source tree
> на release SHA. **HD-D9** (2026-09-06) определяет **тип** canonical input для
> Docker V1 (single immutable release archive). Механизм **доставки** archive
> (hosting, download) остаётся открытым до отдельного решения.

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

> Каждый Docker release жёстко связан с конкретным immutable application release.

#### Release chain (RUSBINGO)

```text
Application v1.0
    ↓
immutable release artifact
    ↓
SHA256 verification
    ↓
Docker Release 1.0

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
изменения** из:

- `main`;
- latest source;
- mutable source archive;
- floating application version.

#### Release metadata / provenance (required)

Docker version может иметь **собственную нумерацию**, но release metadata
**обязана** сохранять provenance:

| Field | Requirement |
|-------|-------------|
| Application version | Explicit (e.g. `v1.0`) |
| Application Git SHA | **Full 40-character SHA** (normative documents, acceptance criteria, commands) |
| Immutable artifact | Reference to the artifact used for build |
| Artifact SHA256 | When applicable — recorded and verifiable |

> Короткий SHA (`508cc28`) допускается **только** как визуальное сокращение в
> prose. В нормативных документах, acceptance criteria и командах — **полный SHA**:
> `508cc280704ed72cc3e85df03e57bd6fb42d24ee`.

Storage, registry, tagging и upgrade implementation **не входят** в HD-D1.

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
| Где хранится artifact / exact download mechanism | Open (HD-D9 does **not** decide hosting) |
| Формат archive filename (`.tar` / `.tar.gz` / `.rar`) | Open unless established elsewhere |
| OCI image distribution / pre-built image pull | **Not** V1 prerequisite; may be evaluated later |
| Container registry | **HD-D4** (contract only; selection TBD) |
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

### Human Decision HD-D9 — **DECIDED** (2026-09-06)

**Decision:** Docker V1 uses the **single immutable application release artifact**
as the canonical input for Docker installation.

> Docker V1 использует единственный immutable application release artifact как
> canonical input для Docker installation.

Each application release has **exactly one** release artifact file available for
distribution (currently an archive such as `.tar`, `.tar.gz`, or `.rar` — exact
filename/format policy not decided here).

#### Target flow (policy)

```text
Application Release
        ↓
single immutable release archive
        ↓
SHA256 verification
        ↓
Docker installer
        ↓
docker build
        ↓
RUSBINGO container
```

#### Future installer responsibilities (not implemented in this decision)

1. Obtain the specific application release artifact
2. Verify its SHA256
3. Use the verified artifact as the Docker build input
4. Build the Docker image
5. Start the RUSBINGO container

#### Boundaries (HD-D9 does NOT decide / implement)

| Topic | Status |
|-------|--------|
| OCI image distribution pipeline | **Not** a Docker V1 prerequisite; may be evaluated later from practical evidence |
| Registry selection, Docker Hub publication | Open (**HD-D4**) |
| Registry credentials, repository naming, image tag scheme | Open |
| Remote image pull workflow | Open |
| Artifact hosting provider | Open |
| Exact download mechanism | Open |
| Installer implementation | Future (**HD-D8** policy only) |
| Exact archive filename / `.tar` vs `.tar.gz` vs `.rar` | Open unless existing docs establish it |

> Deciding the release archive as canonical input does **not** mean D1.1
> implementation evidence, D2 audit, or D3 installation validation has passed.
> All such gates remain **PENDING**.

### Must document before D3 (delivery — hosting still open)

| Item | Description |
|------|-------------|
| **Artifact type (canonical input)** | Single immutable application release archive per application release (**HD-D9**) |
| **Artifact source (hosting/delivery)** | Where/how installer obtains the archive — **not** decided by HD-D9 |
| **Artifact identifier** | Application tag + full SHA + SHA256 content hash |
| **Verification mechanism** | SHA256 verification against D0 recorded hash |
| **Checksum mismatch** | Build/install **MUST FAIL**; no silent fallback to `main` or working tree |
| **Version pinning** | Docker release pinned to exactly one application release (e.g. `v1.0` / `508cc280704ed72cc3e85df03e57bd6fb42d24ee`) |
| **Release identity mapping** | Docker Release → Application Version → Full Git SHA (**HD-D3**) |
| **Build model** | `docker build` from verified archive — not pre-built OCI pull (**HD-D9**) |

### Acceptance

- [x] HD-D1 Immutable Release model recorded
- [x] HD-D9 canonical release archive input recorded
- [ ] Artifact hosting / download mechanism documented
- [ ] Reproducible build demonstrated without mutable `main` checkout
- [ ] D1.1 implementation evidence recorded in `DOCKER_V1_EVIDENCE.md`

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

- [x] Audit report in evidence D2 with PASS/FAIL per area (`DOCKER_V1_EVIDENCE.md` § D2)
- [x] Gaps documented with remediation plan before D3 (remediation **not** started)

**D2 gate:** audit **COMPLETE** — mandatory constraints **not satisfied**; do **not** proceed to D3 until Human reviews findings and authorizes remediation.

---

## Human Decision HD-D10 — Application boundary inside container (**DECIDED** 2026-09-06)

**Decision:** Docker V1 places the **entire RUSBINGO application stack inside the
container**. A clean supported Linux VPS requires only Docker/Compose
infrastructure on the host — not RUSBINGO application runtime.

> **Docker V1: весь RUSBINGO размещается внутри контейнера.**

Resolves D2 audit **OPEN** question (host nginx + host `public/` vs all-in-container).

### Inside container (application boundary)

| Component | Included |
|-----------|----------|
| RUSBINGO application code (`src/`, `server.php`, `vendor/`) | **Yes** |
| `public/` and browser SPA | **Yes** |
| Workerman WebSocket server | **Yes** |
| HTTP / WebSocket serving (including TLS termination if part of container design) | **Yes** |
| SQLite engine and `game.db` | **Yes** |
| Application runtime state (in-RAM game state) | **Yes** |
| Application logs | **Yes** |
| Healthcheck | **Yes** |
| Required runtime dependencies (PHP extensions, etc.) | **Yes** |

### Not required on host (Docker V1)

| Item | Status |
|------|--------|
| Host nginx as RUSBINGO runtime | **Not part of Docker V1** |
| Host PHP | **Not required** |
| Host SQLite | **Not required** |
| Host application source tree / `public/` copy | **Not required** |
| Host-mounted `public/` as canonical delivery | **Forbidden** |
| Specific PHP/SQLite/nginx versions on VPS | **Not a dependency** — versions come from container image |

Historical `deploy/docker/configure-proxy.sh` (host nginx + host `public/` copy) describes
**ADR-036 staging** behaviour — **not** canonical Docker V1 architecture (see ADR-036
supersession note, ADR-039 §14).

### Storage boundary (reaffirms ADR-039)

| Rule | Docker V1 |
|------|-----------|
| `game.db` location | **Inside container** writable layer |
| Named volume for application state | **Not used** |
| Bind mount for `game.db` | **Not used** |
| Persistent host storage for game activity/state | **Not part of Docker V1** |
| Container deletion | **Removes** game state with the container (unless restored from exportable backup artifact per D6) |

### Host may still contain (not RUSBINGO application runtime)

The following on the host are **allowed** and are **not** RUSBINGO application
runtime/state:

- Docker Engine and Compose plugin
- Installer scripts / installer metadata
- Temporary release artifact during install (HD-D9)
- Temporary backup/export artifacts (D6)
- Ordinary Docker metadata (images, networks — when not application-persistent volumes)

### D10 (Zero Residue) implications

Uninstall verification **must** confirm absence of:

- Running RUSBINGO container
- RUSBINGO application state on host
- `game.db` on host
- Persistent application-named Docker volumes
- Application bind mounts for game data/logs
- Host-served copy of `public/` created for RUSBINGO Docker install
- RUSBINGO-specific Docker deployment artifacts the installer created, when no longer needed

Host may retain only OS + Docker infrastructure + explicitly unrelated system data.

### HD-D10 does NOT decide

- Registry, artifact hosting, installer implementation, archive-based build
- Exact in-container TLS/nginx layout (implementation detail for remediation)
- Remediation of current `deploy/docker/` (HD-D5, post-D2)

---

## D2.1 — Installation contract freeze

### Human Decision HD-D8 — **DECIDED** (2026-09-06)

**Model:** Installer-first / automated installation.

> Цель Docker V1 — позволить обычному пользователю установить игровой сервер на
> чистый поддерживаемый Linux VPS с максимально возможной автоматизацией.

#### Target installation flow

```text
Clean supported Linux VPS
        ↓
RUSBINGO installer
        ↓
OS compatibility check
        ↓
Docker Engine installation if absent
        ↓
Docker Compose availability/configuration
        ↓
Domain resolution
        ↓
Immutable RUSBINGO Docker image acquisition
        ↓
Image/provenance verification
        ↓
Container creation/start
        ↓
Post-install verification
        ↓
Working RUSBINGO server
```

**Key decision:** Docker Engine **не является обязательным предварительным
условием** для конечного пользователя. Если Docker отсутствует, installer
устанавливает Docker Engine сам.

Installer **должен** (future implementation — not in scope now):

| Step | Responsibility |
|------|----------------|
| Detect supported OS | HD-D7 compatibility / certified targets |
| Check prerequisites | OS, resources (exact matrix — after D2/D3 audit) |
| Install Docker Engine | If absent |
| Ensure Compose functionality | Plugin or equivalent mechanism |
| Acquire immutable release archive | Per HD-D1 / HD-D9; verify SHA256 → `docker build` |
| Configure domain | Per domain resolution below |
| Start server | Single container topology |
| Post-install verification | Smoke / health checks |
| Present result to user | Server address and status |

#### Domain resolution (approved)

Deterministic precedence (host-side installer **only**):

1. `RUSBINGO_DOMAIN` — if set and valid
2. VPS hostname
3. If hostname unsuitable — **interactive prompt**

Interactive domain configuration occurs on the **host installer**, **not** inside
the application container.

#### Idempotency (future implementation requirement)

> Повторный запуск installer не должен случайно создавать второй игровой server
> или второй независимый application state.

При существующей установке installer должен определить состояние и либо безопасно
продолжить/проверить установку, либо явно сообщить пользователю о существующей
установке. **Не реализуется** в рамках фиксации HD-D8.

### Human Decision HD-D7 — **DECIDED** (2026-09-06)

**Target audience:** пользователь может установить игровой сервер на Ubuntu **не
старше 22.04**, Debian **не старше 12** или аналогичные Linux systems.

#### Officially certified targets (minimum)

| OS | Version |
|----|---------|
| Ubuntu | 22.04 LTS |
| Ubuntu | 24.04 LTS |
| Debian | 12 |

#### Compatibility target (not certified)

Допускается установка на другие Linux distributions, если они:

- поддерживают требуемый Docker Engine;
- поддерживают требуемый Compose mechanism;
- удовлетворяют системным требованиям RUSBINGO.

Такие ОС **не считаются officially supported/certified**, пока не прошли
соответствующую validation matrix.

> **Не** формулировать как «RUSBINGO поддерживает любой Linux».

Матрица точных версий Docker Engine / Compose и минимальных VPS resources —
**уточняется после D2/D3 audit**; не выдумывается на этапе policy decisions.

### Goal

До реальной установки на VPS зафиксировать **Docker Installation Contract**.

### Domain resolution

Supported UX (host-side installer only) — **see HD-D8** (approved):

1. `RUSBINGO_DOMAIN` — if explicitly set and valid
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

**Installer-first model:** see **HD-D8** (approved). Must additionally define at
D2.1 freeze / D3 validation (audit-dependent):

| Item | To be specified |
|------|-----------------|
| Prerequisites | Exact Docker Engine / Compose versions (after D2/D3 audit) |
| Supported OS | **HD-D7** certified + compatibility targets |
| Required ports | Host ports for HTTP/HTTPS/WSS upstream |
| Filesystem assumptions | Host paths for install metadata only (no game data volume) |
| Install command | Canonical entry point |
| Uninstall command | Canonical entry point |
| Upgrade model | Future decision (not HD-D1) |
| Minimum VPS resources | After D2/D3 audit |

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

- OS compatibility check (HD-D7)
- Docker installation by installer if absent (HD-D8 — not operator prerequisite)
- Image build from verified release archive (HD-D9) — not remote OCI pull prerequisite
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

### Human Decision HD-D2 — **DECIDED** (2026-09-06)

**Policy:**

| Severity | Rule |
|----------|------|
| **CRITICAL** | **Release blocker** — must be 0 at release |
| **HIGH** | May be **ACCEPTED** only with **individual documented disposition** |

HIGH vulnerability **сама по себе не блокирует** Docker release, если доказано,
что vulnerability не exploitable / не применима к runtime scenario и это
**обосновано**.

**Insufficient:** generic statement such as «HIGH not exploitable» without
per-finding justification.

#### Required fields per accepted HIGH

Each accepted HIGH **must** have separate evidence/disposition record:

| Field | Required |
|-------|-------------|
| CVE / identifier | |
| Severity | |
| Affected component | |
| Runtime affected | YES / NO |
| Exploitability in supported RUSBINGO configuration | |
| Technical justification | |
| Disposition | ACCEPTED / REJECTED / MITIGATED |
| Mitigation (if applicable) | |

**Not decided by HD-D2:** numeric threshold for count of HIGH findings (separate
decision if needed).

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
CRITICAL = 0   (hard gate — HD-D2)
HIGH       = individual disposition required per finding (HD-D2)
```

**Forbidden:** arbitrary criteria such as «fewer than 5 CRITICAL» or blanket
«all HIGH accepted» without per-CVE evidence.

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

### Human Decision HD-D10 context

Zero Residue scope follows **HD-D10** (all-in-container application boundary).
See § Human Decision HD-D10 for host vs application distinction.

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
- Host-served copy of `public/` (e.g. from historical `configure-proxy.sh`)
- RUSBINGO-specific installer-created deployment artifacts when obsolete

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

- Exact application release SHA (`v1.0` / `508cc280704ed72cc3e85df03e57bd6fb42d24ee`)
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
docker_tested_application_sha  = v1.0 release SHA (508cc280704ed72cc3e85df03e57bd6fb42d24ee)
docker_tested_image_digest     = immutable image identity
docker_released_image_digest   = docker_tested_image_digest  (at H-D1 gate)
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
- [ ] Final application release SHA (`v1.0` / `508cc280704ed72cc3e85df03e57bd6fb42d24ee`)
- [ ] Docker release versioning / provenance per HD-D3

Only after **H-D1** is Docker Release permitted.

---

## Docker release

### Human Decision HD-D3 — **DECIDED** (2026-09-06)

**Versioning policy:**

> Docker release должен иметь явную связь с application version, но **не обязан**
> использовать абсолютно тот же номер версии.

| Rule | Detail |
|------|--------|
| Docker release version | May differ from application version |
| Release metadata | **Must** explicitly state application version |
| Provenance | **Required** to specific immutable application release |
| Minimum traceability | `Docker Release → Application Version → Full Git SHA` |
| Forbidden | Versioning scheme where application version cannot be determined from Docker release |

**Not decided by HD-D3:** exact Docker release tag string, image tag naming.

### Human Decision HD-D4 — **DECIDED** (2026-09-06)

**Registry strategy:**

> Сначала определить registry-independent contract; конкретный registry выбрать
> до Docker Release. Архитектура не должна создавать препятствий для
> последующей публикации через Docker Hub.

| Rule | Detail |
|------|--------|
| Image portability | OCI/container-image portable |
| Contract dependency | **Must not** depend on one registry's proprietary features |
| Production evidence | Image identity via **immutable digest** when image is used |
| Registry selection | **Not chosen yet** — mandatory before Docker Release |
| Docker Hub | Architecture must not block future publication |

**Not decided by HD-D4:** registry name, repository name, image tag, credentials,
registry account.

### Identity

| Rule | Detail |
|------|--------|
| Separate from NLD | Docker release has **own** version identity (HD-D3) |
| NLD `v1.0` | **Do not modify** |
| Provenance | `Docker Release → Application Version → Full Git SHA` (HD-D3) |
| `latest` tag | If used — **convenience alias only**, not sole release identity |
| Registry | Per HD-D4 — contract defined; specific registry TBD before release |

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
application_release_sha:    # full 40-char SHA, e.g. 508cc280704ed72cc3e85df03e57bd6fb42d24ee
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

**Legend:** **Policy** = preliminary Human decision (contract); **Audit** = requires
D2/D3 validation or implementation evidence before gate PASS.

| ID | Decision | Type | Status | Date |
|----|----------|------|--------|------|
| **HD-D1** | Immutable Release model — artifact resolution architecture | Policy | **DECIDED** | 2026-09-06 |
| **HD-D2** | HIGH vulnerability disposition policy (CRITICAL=0; HIGH per-finding) | Policy | **DECIDED** | 2026-09-06 |
| **HD-D3** | Docker release versioning / provenance linkage | Policy | **DECIDED** | 2026-09-06 |
| **HD-D4** | Registry-independent contract (registry TBD before release) | Policy | **DECIDED** | 2026-09-06 |
| **HD-D5** | ADR-036 named-volume remediation vs Docker V1 contract | Audit | **REMEDIATED** | 2026-09-06 — implementation commit; D3/D10 validation **PENDING** |
| **HD-D6** | `network_mode: host` — justify or exclude | Audit | **Recommend CLOSE** | D2 audit: not used in `compose.yaml` |
| **HD-D7** | Supported OS targets (certified + compatibility) | Policy | **DECIDED** | 2026-09-06 |
| **HD-D8** | Installer-first / automated installation model | Policy | **DECIDED** | 2026-09-06 |
| **HD-D9** | Canonical input = single immutable application release archive (`docker build`) | Policy | **REMEDIATED** | 2026-09-06 — implementation commit; D3 validation **PENDING** |
| **HD-D10** | All-in-container application boundary (no host nginx/host `public/` runtime) | Policy | **DECIDED** | 2026-09-06 |
| — | Artifact hosting / download mechanism | Policy | Open | Before D3 |
| — | OCI image distribution / registry publication | Future | Open | After first Docker cycle evidence |
| — | Exact Docker Engine / Compose versions | Audit | Open | After D2/D3 |
| — | Minimum VPS resources | Audit | Open | After D2/D3 |
| — | Specific registry, repository, image tag, credentials | Implementation | Open | Before Docker Release |
| — | Upgrade command, procedure, SQLite migration | Future | Open | Post HD-D1 |
| — | Numeric HIGH count threshold | Policy | Open | If needed |

---

## References

- [`docs/RELEASE_CONTRACT_V1.md`](RELEASE_CONTRACT_V1.md) — NLD V1.0 (unchanged)
- [`docs/ROADMAP_V1_PRODUCTION.md`](ROADMAP_V1_PRODUCTION.md) — NLD gates G0–G11
- [`docs/DOCKER_V1_EVIDENCE.md`](DOCKER_V1_EVIDENCE.md) — Docker evidence index
- [`docs/ADR/036-docker-compose-deployment.md`](ADR/036-docker-compose-deployment.md) — historical Docker implementation (audit baseline for D2)
- [`docs/ADR/039-docker-v1-distribution-target.md`](ADR/039-docker-v1-distribution-target.md) — Docker V1 architectural decision
- [`deploy/docker/README.md`](../deploy/docker/README.md) — current Docker tooling (to be audited)
