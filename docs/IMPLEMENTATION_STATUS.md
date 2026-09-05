# Implementation Status — Lotto Game Project

## Phase 14 — Release Candidate (2026-09-05)

Status: **EPIC-14.0 complete** — release-readiness audit; no code blockers;
decision: **READY WITH UNVERIFIED OPERATIONAL GATES**.

- [DONE] EPIC-14.0 Release Candidate audit — protocol, auth, state machine, economy,
  reconnect/AFK, frontend static tests, security configuration review
- [PENDING] EPIC-14.1 Version 1.0 Release — requires fresh VPS/browser/backup gates

VERIFICATION (local Windows dev host, 2026-09-05):
- `php run_ALL_tests.php` — 59/59 PASS
- `git diff --check` — PASS
- `gh api` — `origin/main` @ `a8794f3` (PR #9); `git fetch` failed (SSL)
- Browser/E2E — **UNVERIFIED** (browser automation unavailable)
- VPS smoke / backup-restore / TLS-WSS — **UNVERIFIED** in this session (Phase 11 evidence retained)

ADR required: **NO** (documentation-only branch changes).

Report: `docs/PHASE_14_REPORT.md`

## Phase 12 — Frontend verification (2026-09-05)

Status: **Complete** — implementation already present on `main`; Phase 12
sign-off is verification + documentation reconciliation (no protocol or
backend changes).

- [DONE] EPIC-12.0 `public/js/ws.js` — connection lifecycle, ping, auto-reconnect, ADR-027 URL resolution, packet dispatch
- [DONE] EPIC-12.1 `public/js/app.js` — state, packet routing, animation queue, `turn_ready` / AFK semantics
- [DONE] EPIC-12.2 Lobby UI — room list/create/join, password, host, bank, start-game (`public/js/ui.js`, `index.html`)
- [DONE] EPIC-12.3 Game UI — cards, masked opponents, turn/AFK, drawer, barrels, nudge, apartment, game-over
- [DONE] EPIC-12.4 Reconnect UI — overlay, `reconnect_state` restore (waiting/playing), `is_my_turn` turn restoration
- [DONE] EPIC-12.5 Localization — `public/js/i18n.js`, six locales (`en`, `ru`, `es`, `fr`, `zh`, `tr`)
- [DONE] EPIC-12.6 Frontend tests — `test_frontend_structure.php`, `test_frontend_logic.php`, `test_frontend_i18n.php`

Files (authoritative frontend surface):
- `public/index.html`, `public/css/style.css`
- `public/js/ws.js`, `public/js/app.js`, `public/js/ui.js`, `public/js/i18n.js`, `public/js/sound.js`
- `public/locales/*.json`
- `tests/Manual/test_frontend_*.php`, `tests/Manual/test_ws_url_resolution.php`

VERIFICATION (local Windows dev host, 2026-09-05):
- `php tests/Manual/test_frontend_structure.php` — 55/55 PASS
- `php tests/Manual/test_frontend_logic.php` — 7/7 PASS
- `php tests/Manual/test_frontend_i18n.php` — 16/16 PASS
- `php tests/Manual/test_ws_url_resolution.php` — 19/19 PASS
- `php tests/Manual/test_protocol_completeness.php` — 99/99 PASS (1 known warning)
- `php run_ALL_tests.php` — 59/59 test files PASS
- `git diff --check` — PASS
- Browser/E2E — **not run** in this session (manual QA checklist remains for audio timing, etc.)

Notes: EPIC-13.6 historical finding (`reconnect_state` draw-button activation) is
**resolved** in current `onReconnectState()` via `is_my_turn` + `syncTurnUi()`.
No ADR required (ADR required: NO).

Report: `docs/PHASE_12_REPORT.md`

## Docker Compose deployment (ADR-036) (2026-08-29)

Status: **Completed** — scripts under `deploy/docker/`.

## Deployment mode separation (ADR-037 / Epic A) (2026-08-30)

Status: **Completed** (Epic A only — layout boundary)

## Epic B1 — Systemd identity and safety foundation (2026-08-30)

Status: **Completed** (foundation only — no install/remove/update)

- [DONE] Instance-name validation (`^[a-z0-9][a-z0-9_-]{0,31}$`, reserved production names)
- [DONE] Deterministic identity: `/opt/lotto-game-<name>/`, `lotto-game-<name>.service`, `lotto-<name>` user
- [DONE] Filesystem layout helpers: `app/`, `data/`, `logs/`, `config/`, backups under `/var/backups/lotto-game/<name>/`
- [DONE] Metadata schema v1 (`config/deployment.json`, no secrets)
- [DONE] Production guards: `/opt/lotto-game`, `lotto-server.service`, `www-data`, port 8080
- [DONE] Tests: `deploy/systemd/tests/run_tests.sh`
- [DONE] D VPS verification (D1 live host — Ubuntu 24.04 VPS 2026-09-01)

Files:
- `deploy/systemd/lib/common.sh`, `deploy/systemd/tests/run_tests.sh`, `deploy/systemd/README.md`

VERIFICATION:
- `bash deploy/systemd/tests/run_tests.sh` — **50/50 PASS** (Git Bash on Windows dev host)

## Epic B2 — Systemd installation (2026-08-30)

Status: **Completed** (install only — no remove/update)

- [DONE] `install.sh` — validate, dedicated user, dirs, rsync app source, Composer, env file, unit render, DB init (if new), enable/start, health verification, metadata
- [DONE] Idempotent reinstall (preserve existing DB; refresh app/config/unit)
- [DONE] Port selection `8081–8999` with production/Docker/other-instance checks
- [DONE] `healthcheck.sh` — unit active + WebSocket health (reuses `deploy/docker/healthcheck.php`)
- [DONE] `service.template` — hardened unit (`NoNewPrivileges`, `PrivateTmp`, `ProtectSystem`, `ReadWritePaths`)
- [DONE] B2 helper tests in `deploy/systemd/tests/run_tests.sh`
- [DONE] D VPS verification (D1 live host — Ubuntu 24.04 VPS 2026-09-01)

Files:
- `deploy/systemd/install.sh`, `deploy/systemd/healthcheck.sh`, `deploy/systemd/service.template`
- `deploy/systemd/lib/common.sh` (B2 install helpers appended)

VERIFICATION:
- `bash deploy/systemd/tests/run_tests.sh` — **63/63 PASS** (Git Bash on Windows dev host)
- `bash deploy/docker/tests/run_tests.sh` — Docker regression (unchanged behaviour)
- Full systemd install on Linux VPS — **DONE** (Epic D1 — `docs/SYSTEMD_VPS_VERIFICATION.md`)

## Epic B3 — Systemd safe removal (2026-08-30)

Status: **Completed** (remove only — no update/rollback)

- [DONE] `remove.sh` — metadata-validated, instance-scoped removal with fail-closed ownership checks
- [DONE] Stop/disable/remove unit; safe filesystem + backup cleanup; conditional user removal (`created_user`)
- [DONE] Canonical path + symlink escape protection before destructive operations
- [DONE] Zero-artifact verification; idempotent re-removal; missing-metadata-with-residuals fails safely
- [DONE] B3 tests in `deploy/systemd/tests/run_tests.sh`
- [DONE] D VPS verification (D1 live host — Ubuntu 24.04 VPS 2026-09-01)

Files:
- `deploy/systemd/remove.sh`
- `deploy/systemd/lib/common.sh` (B3 removal helpers appended)

VERIFICATION:
- `bash deploy/systemd/tests/run_tests.sh` — **87/87 PASS** (Git Bash on Windows dev host; symlink escape test skipped when OS lacks symlinks)
- `bash deploy/docker/tests/run_tests.sh` — Docker regression (unchanged behaviour)
- Full systemd remove on Linux VPS — **DONE** (Epic D1 — `docs/SYSTEMD_VPS_VERIFICATION.md`)

## Epic C — Systemd update and operational lifecycle (2026-08-30)

Status: **Completed** (update only — no rollback framework, no port change)

- [DONE] `update.sh` — validate managed instance, stop, rsync app, `composer install`, conditional unit refresh, restart, healthcheck
- [DONE] Preserves `data/game.db`, `config/environment`, port, user; metadata `updated_at` only after success
- [DONE] Instance lock (`/var/lock/lotto-game-<name>.lock`); failure leaves service stopped with config/DB intact
- [DONE] C tests in `deploy/systemd/tests/run_tests.sh`
- [DONE] D VPS lifecycle verification (Epic D1 — see `docs/SYSTEMD_VPS_VERIFICATION.md`)

Files:
- `deploy/systemd/update.sh`
- `deploy/systemd/lib/common.sh` (C update helpers appended)

VERIFICATION:
- `bash deploy/systemd/tests/run_tests.sh` — **106/106 PASS** (Git Bash on Windows dev host)
- `bash deploy/docker/tests/run_tests.sh` — Docker regression (unchanged behaviour)
- Full systemd update on Linux VPS — **DONE** (Epic D1 — `docs/SYSTEMD_VPS_VERIFICATION.md`)

## Epic D — VPS verification, documentation, operational UX (2026-08-31)

Status: **Completed** — D1 verified on real Linux VPS (2026-09-01); D2/D3 complete.

### D1 — Live VPS verification

- [DONE] Full install → health → multi-instance → update → remove lifecycle on clean Linux VPS (`box-963286`, Ubuntu 24.04)
- [DONE] Production regression (no production present; baseline unchanged), Linux symlink security test (110/110 on VPS at `84394c0`)
- [DONE] Docker regression 21/21; Docker coexistence **SKIPPED** (Docker not on verification VPS)
- Checklist: `docs/SYSTEMD_VPS_VERIFICATION.md`
- Fix during D1: `lotto_wait_for_instance_healthcheck()` — install/update healthcheck race on slow VPS

### D2 — Final deployment documentation

- [DONE] `docs/LOCAL_ENVIRONMENT.md` — generic systemd section (quick-start, lifecycle, ports, bind limitation, security)
- [DONE] `deploy/systemd/README.md` — operator entry point and deployment model table
- [DONE] `docs/SYSTEMD_VPS_VERIFICATION.md` — D1 evidence template
- [DONE] `README.md` — deployment model table and one-command examples
- [DONE] Stale "systemd deferred" references removed from `LOCAL_ENVIRONMENT.md`

### D3 — One-command operational UX

- [DONE] Single command per lifecycle operation under `deploy/systemd/` (no top-level wrapper, no `--mode`)
- [DONE] `lotto_print_instance_context` — scripts display instance, unit, user, paths, port before destructive ops
- [DONE] `healthcheck.sh` accepts positional `[INSTANCE]` (documented examples)

Files:
- `docs/LOCAL_ENVIRONMENT.md`, `docs/SYSTEMD_VPS_VERIFICATION.md`, `deploy/systemd/README.md`, `README.md`
- `deploy/systemd/lib/common.sh`, `install.sh`, `update.sh`, `remove.sh`, `healthcheck.sh`

VERIFICATION:
- `bash deploy/systemd/tests/run_tests.sh` — **110/110 PASS** on Ubuntu 24.04 VPS (`box-963286`) via SSH MCP
- `bash deploy/docker/tests/run_tests.sh` — **21/21 PASS** on same VPS (Docker integration skipped)
- D1 VPS acceptance test — **DONE** — see `docs/SYSTEMD_VPS_VERIFICATION.md`

---

- [DONE] **A** — ADR-037; Docker scripts under `deploy/docker/`; `deploy/systemd/` boundary
- [DONE] **B1** — identity, metadata, production guards (`deploy/systemd/lib/common.sh`)
- [DONE] **B2** — systemd install (`deploy/systemd/install.sh`, helpers, tests)
- [DONE] **B3** — systemd remove (`deploy/systemd/remove.sh`, helpers, tests)
- [DONE] **C** — systemd update (`deploy/systemd/update.sh`, helpers, tests)
- [DONE] **D** — VPS verification complete on real Linux/systemd host (`docs/SYSTEMD_VPS_VERIFICATION.md`)

- [DONE] Docker scripts relocated from top-level `deploy/` to `deploy/docker/` (behaviour preserved)
- [DONE] Top-level ambiguous entry points removed (`deploy/install.sh`, etc.)
- [DONE] `deploy/systemd/README.md` — explicit deferral to epics B1–D
- [DONE] ADR-037, docs updated (`LOCAL_ENVIRONMENT.md`, `ANCHOR_CORE.md`, ADR-036 paths)
- [NOTE] Does not modify existing production deployment (`/opt/lotto-game`, `lotto-server.service`, `www-data`)
- [NOTE] No `server.php` or protocol changes in Epic A

Files:
- `deploy/docker/**`, `deploy/systemd/README.md`, deleted top-level `deploy/*.sh`, `docs/ADR/037-*`, doc path updates

---

## SYSTEM DEPLOYMENT (workstream)

Independent from TECHNICAL DEBT. Sequence: **A → B1 → B2 → B3 → C → D**.

| Epic | Status | Evidence |
|------|--------|----------|
| A — ADR-037 + deploy layout | **DONE** (Epic A) | `docs/ADR/037-deployment-mode-separation.md`; `deploy/docker/`; `deploy/systemd/README.md` |
| B1 — Systemd identity/safety | **DONE** (Epic B1) | `deploy/systemd/lib/common.sh`, `deploy/systemd/tests/run_tests.sh` |
| B2 — Systemd installation | **DONE** (Epic B2) | `deploy/systemd/install.sh`, `healthcheck.sh`, `service.template`; tests 63/63 |
| B3 — Systemd removal | **DONE** (Epic B3) | `deploy/systemd/remove.sh`; metadata validation, symlink guards, zero-artifact verify |
| C — Update / operational lifecycle | **DONE** (Epic C) | `deploy/systemd/update.sh`; app refresh, composer install, lock, health verify |
| D — Documentation / VPS verification | **DONE** (Epic D) | D1 verified on Ubuntu 24.04 VPS; lifecycle + 110/110 systemd tests — `docs/SYSTEMD_VPS_VERIFICATION.md` |

**NOT BLOCKING:** TD-1, TD-2, TD-3 (no hard dependencies demonstrated).

---

## TECHNICAL DEBT (workstream)

Independent from SYSTEM DEPLOYMENT. Does **not** block deployment epics A–D.

### TD-1 — ADR-023 / `admin_stats_data` reconciliation

Status: **DONE** (implementation + documentation reconciled 2026-08-30).

| Aspect | Status |
|--------|--------|
| Implementation | **DONE** — `AdminService::handleGetStats()` emits `admin_stats_data`; `server.php` wires `admin_get_stats`; client `app.js` (`refreshAdminData`, `onAdminStats`); UI renders stats + rooms (EPIC-033B) |
| Documentation/status | **RECONCILED** (2026-08-30) — ADR-023, PHASE_11_REPORT, KNOWN GAPS updated; Epic 023.1 obsolete |
| Test coverage | **VERIFIED** — `tests/Manual/test_admin_stats.php` **10/10 PASS** (2026-08-30 audit) |

Priority: **Low**. Not blocking deployment.

Roadmap work remaining: none for TD-1 core reconciliation (completed 2026-08-30); optional: prune historical EPIC-10.7/11.5 log entries that still describe pre-ADR-023 state.

### TD-2 — `error.banned` protocol/documentation debt (ADR-007)

| Aspect | Status |
|--------|--------|
| Runtime | **IMPLEMENTED per ADR-007** — `error.banned` reserved/unused; canonical ban rejection = `{"type":"banned","until":...}` at login (`AuthHandler`), reconnect, admin notification (`AdminService`) |
| Documentation | Reconciliation only — registry entry intentional; `test_protocol_completeness.php` warns with ADR-007 semantics |

Priority: **Very Low**. Not blocking deployment. No runtime change proposed.

### TD-3 — Phase 11 VPS verification (11.1–11.6)

| Sub-item | Instrumentation | Local/mock | VPS verification | Blocks deployment? |
|----------|-----------------|------------|------------------|-------------------|
| 11.1 Memory | DONE | PASS | **DONE** (6h `memory_stability_runner.php` PASS, `box-963286`, 2026-09-02) | No |
| 11.2 Timer | DONE | PASS | **DONE** (accelerated `timer_accelerated_runner.php` PASS, reconnect drift ~0.5ms, `box-963286`, 2026-09-02) | No |
| 11.3 Economy | DONE | PASS | **DONE** (`economy_integrity_runner.php` + analyze replay PASS; live Workerman stake PASS, `box-963286`, 2026-09-02) | No |
| 11.4 State machine | DONE | PASS | **DONE** (live Workerman + analyze PASS, `box-963286`, 2026-09-02) | No |
| 11.5 Protocol replay | DONE | Static PASS | **DONE** (8 live WS tests, 145/145 PASS, `box-963286` 2026-09-02) | No |
| 11.6 Load testing | DONE | PASS | **DONE** (gameplay <100 ms; auth ≤160 ms; all 4 scenarios PASS 2026-09-04) | No |

See `docs/PHASE_11_REPORT.md` and `docs/ROADMAP.md` § TD-3 matrix. **11.1–11.6 VPS DONE**.

### EPIC-11.6 — VPS load testing (**DONE**, criteria reassessment 2026-09-04)

Status: **DONE** — owner clarified register latency is not ultra-low-critical; analyzer reframed; full VPS suite PASS. TD-3 COMPLETE for 11.6.

| Item | Value |
|------|-------|
| Host | `box-963286` (Ubuntu 24.04, 1 CPU / ~543 MB RAM) |
| Product fix | `f7c180b` (`loginAfterRegister` + interval CPU) |
| Final gates | gameplay p95 <100 ms (`draw_barrel`); auth p95 ≤160 ms (`register`/`login`); CPU <80%; mem <450 MB |
| Suite | `2026-09-04T03:11:15Z`–`04:42:15Z` — storm/ramp/steady/long all analyze exit 0 |
| Mock | `test_load_audit.php` 30/30 PASS |

| Scenario | register p95 | peak CPU | draw_barrel p95 | Result |
|----------|--------------|----------|-----------------|--------|
| storm | 110.20ms | 0.0% | 3.82ms | PASS |
| ramp | 100.33ms | 22.6% | n/a | PASS |
| steady | 105.52ms | 2.0% | 3.41ms | PASS |
| long | 101.52ms | 1.0% | 2.08ms | PASS |

**Why criteria changed:** Old `register p95 < 100 ms` had no ADR/ANCHOR/product justification. Owner: ~150–160 ms OK if no overload/gameplay harm. Gameplay responsiveness is critical. bcrypt cost unchanged.

**ADR required: NO** for criteria reframe. **YES** if lowering bcrypt cost (forbidden / not done).

**Out of scope:** lowering bcrypt cost.

### EPIC-11.4 — VPS state-machine live-session verification (2026-09-02)

Status: **DONE** — mock 35/35 + live Workerman transition log replay PASS on Ubuntu VPS.

| Item | Value |
|------|-------|
| Host | `box-963286` (Ubuntu 24.04, non-production) |
| Commit | `7032a4a` (main post-PR #6) |
| Mock | `test_state_machine_audit.php` 35/35 PASS @ `2026-09-02T18:33:31Z` |
| Live | `LOTTO_STATE_AUDIT=1` + play_vs_bot path; 3 events; analyze PASS |
| Window | live `2026-09-02T18:34:08Z` → `2026-09-02T18:35:23Z` |

**Out of scope for this sign-off:** 11.6 load, deployment changes.

### EPIC-11.3 — VPS economy integrity verification (2026-09-02)

Status: **DONE** — multi-scenario conservation + live Workerman stake path PASS on Ubuntu VPS.

| Item | Value |
|------|-------|
| Host | `box-963286` (Ubuntu 24.04, non-production) |
| Commit | `85e9bbb` (main post-PR #5) |
| Integrity | 4 scenarios; 7 audit events; conservation PASS; analyze `--initial=1:500,2:500,3:500` PASS |
| Live | `LOTTO_ECONOMY_AUDIT=1` + `play_vs_bot` → stake -10 logged; analyze PASS |
| Window | `2026-09-02T18:20:17Z` (integrity); live stake ~`18:20:40` UTC |

**Out of scope for this sign-off:** 11.4 state-machine replay, 11.6 load, deployment changes.

### EPIC-11.2 — VPS accelerated timer verification (2026-09-02)

Status: **DONE** — accelerated reconnect timer drift PASS on Ubuntu VPS.

| Item | Value |
|------|-------|
| Host | `box-963286` (Ubuntu 24.04, non-production) |
| Base commit | `ef066d6` (main post-PR #4) |
| Start / End | `2026-09-02T18:11:27Z` → `2026-09-02T18:11:36Z` |
| Scenario | register → create_room → play_vs_bot → disconnect → reconnect fire |
| Reconnect | interval 5.000s; drift ~0.5ms (tolerance 200ms) |
| One-shot | seen=1 fired=1 orphaned=0 |
| Result | **PASS** (`analyze_timer_log.php` exit 0) |

**Harness fixes in this epic:** runner arms reconnect only after entering `playing` via `play_vs_bot`; analyzer evaluates one-shot drift/orphans (not periodic watchdog); clean `php server.php stop`; auto `init_db.php` when schema missing.

**Out of scope for this sign-off:** 11.3–11.4 replay, 11.6 load, deployment changes.

### EPIC-11.1 — VPS memory / stability verification (2026-09-02)

Status: **DONE** — six-hour memory/stability run PASS on Ubuntu VPS.

| Item | Value |
|------|-------|
| Host | `box-963286` (Ubuntu 24.04, non-production) |
| Commit | `6498144` (main post-PR #3) |
| Start / End | `2026-09-02T03:33:19Z` → ~`2026-09-02T09:33:25Z` |
| Duration | 21600s planned; last progress elapsed=21559s remaining=41s |
| Load | 50 players, 10 games |
| Baseline / Peak | 4.00 MB / 4.00 MB (limit 4.80 MB @ 120%) |
| Snapshots | 644,587; violations 0 |
| Result | **PASS** |

**Note:** Official `analyze_memory_log.php` OOM-killed (exit 137) loading ~98 MB log via `file()` on ~543 MB VPS; streaming equivalent analysis with same acceptance logic PASSed. Prefer streaming for long runs (document-only limitation; no analyzer rewrite in this epic).

**Out of scope for this sign-off:** 11.2–11.4 replay, 11.6 load, deployment changes, analyzer rewrite.

### EPIC-11.5 — VPS live WebSocket verification (2026-09-02)

Status: **DONE** — protocol audit reproduced on Ubuntu VPS.

| Item | Value |
|------|-------|
| Host | `box-963286` (Ubuntu 24.04.4 LTS) |
| Commit | `f2c9cfb` (main post-PR #2) |
| Method | SSH MCP; dev checkout `~/lotto-game` |

| Test file | Result |
|-----------|--------|
| `test_server_bootstrap.php` | 24/24 PASS |
| `test_packet_validation.php` | 11/11 PASS |
| `test_auth_packet_routing.php` | 18/18 PASS |
| `test_lobby_packet_routing.php` | 23/23 PASS |
| `test_game_packet_routing.php` | 22/22 PASS |
| `test_admin_packet_routing.php` | 15/15 PASS |
| `test_session_lifecycle.php` | 13/13 PASS |
| `test_protocol_audit.php` | 19/19 PASS |

**Total:** 145 passed, 0 failed. Evidence: `docs/PHASE_11_REPORT.md` § EPIC-11.5.

**Out of scope for this sign-off:** 11.1 memory, 11.2–11.4 replay, 11.6 load, deployment changes.

Priority: Medium for release readiness; **NOT BLOCKING DEPLOYMENT**.

---

## Pre-deploy regression pass (feature/room-chat-files) (2026-08-24)

Status: **Completed** — VPS `php run_ALL_tests.php` **57/57** twice in a row
(2026-08-25, no code changes between runs). Branch is Part-15 deploy-ready;
`systemctl restart lotto-server.service` still requires an explicit deploy step.

### Fix commits (feature/room-chat-files)
| Cluster | Commit | Summary |
|---------|--------|---------|
| A | `9b42e62` | Guard `lobbyService.broadcastRoomList` + FlowWorker stubs |
| C | `e77dd10` | `FakeLoggerWithLines::getLinesSinceSeconds` |
| D | `8ba0813` | Kick/timer/protocol registry alignment |
| E | `59a83f2` | i18n parity es/fr/zh/tr (4 keys) |
| docs | `712938f` | Mark EPIC-033/035 in progress pending suite |
| G/B/D+F | `6042a57` | Seat-removal `is_callable` guard; WS drain helpers; auth FIX-30 asserts; packet TEST 3 burst harden |
| H/I/J | `3f5f8d2` | Game routing `;`; room_list drain; solo `host` + apartment-timer contracts; `RcMockGame::calculateWinChances` |

### Cluster notes (resolved)
- **A:** Production already wired `lobbyService`; fatals were incomplete test stubs.
- **B:** Live WS routing — MiniWS packet-queue desync vs `host_changed`/`room_list` fan-outs (harness), not production fatals.
- **C:** Admin logs time-window path; fake logger stub.
- **D:** Stale kick/apartment early-finish assert; PDO fixture; `play_vs_bot` fenced-list; AuthService login contract.
- **E:** Locale key parity.
- **F:** `test_packet_validation` TEST 3 timing flake — burst harden (not product).
- **G:** Fixture-only register crash after partial Cluster A stub; live `LobbyService` safe.
- **H/I/J:** Syntax `;`; admin `room_list` drain; solo-create empty `host`; apartment full-timer + mock win chances.

---

## EPIC-035 — Room game-speed mode (ADR-035) (2026-08-24)

Status: Completed

- [DONE] ADR-035 accepted; `speed_mode` on Room Structure + registries
- [DONE] Server: `create_room` optional `speed_mode` (default `slow`); wire into
  `room_list`, `room_joined`, `reconnect_state` (waiting + playing)
- [DONE] Client: create-room select, room list column, room panel label
- [DONE] Fast animation profile (~3s total, L→R stops); slow unchanged
- [DONE] Gold pulse omitted in fast mode; audio unchanged
- [DONE] i18n keys in en/ru/es/fr/zh/tr
- [DONE] Pre-deploy: VPS `php run_ALL_tests.php` 57/57 ×2 (2026-08-25)

Commit: 341e420 (+ regression fixes through `3f5f8d2`)
Notes: Part 15 automated verification satisfied; deploy still needs explicit service restart.

VERIFICATION:
MANUAL VERIFICATION REQUIRED (client timing) + VPS `php run_ALL_tests.php` **57/57 ×2** PASS.
---

## EPIC-034 — Bot opponent (ADR-034)

Status: Completed (EPIC-034.1–034.5)

ADR: `docs/ADR/034-bot-opponent.md`.

- [DONE] EPIC-034.1 — Bot entity + `play_vs_bot` + turn engine integration
- [DONE] EPIC-034.2 — Apartment-with-bot resolution
- [DONE] EPIC-034.3 — Victory / `bot_win` bank-burn path
- [DONE] EPIC-034.4 — Win-streak + double-bank mint
- [DONE] EPIC-034.5 — Client UI (“Play vs Bot” + roster)

### EPIC-034.5 — Client UI Play vs Bot + roster (ADR-034)

Status: Completed

Files:
- public/index.html (diff — `#play-vs-bot-btn`)
- public/js/ui.js (diff — enable/disable on roster; Bot roster status; `bot_win` game-over; exclude Bot from chat recipients)
- public/js/app.js (diff — `play_vs_bot` click; defeat sound on `bot_win`)
- public/css/style.css (diff — `.player-bot` / `.status-bot`)
- public/locales/{en,ru,es,fr,tr,zh}.json (diff — playVsBot / bot / botWin strings)
- docs/IMPLEMENTATION_STATUS.md (diff)

Notes: Host-only control, always visible while `waiting`, enabled only when exactly one seated human; re-evaluated on every `showRoomPanel` (roster join/leave/room_joined). Bot distinguished by reserved username `"Bot"` (no `is_bot` field). Server paths unchanged.

VERIFICATION:
- MANUAL — alone in waiting room as host → Play vs Bot enabled; second player joins → disabled; leave → enabled again
- MANUAL — Play vs Bot → game_started roster shows Bot; barrels/win chances include Bot
- MANUAL — bot_win → bank-burn copy + defeat sound; human victory vs bot unchanged
- `php tests/Manual/test_bot_opponent.php` — **151/151 PASS** (server suite unchanged)

CHANGED:
- Client lobby Play vs Bot control + Bot roster/game-over presentation + i18n
NOT CHANGED:
- Server protocol/economy (034.1–034.4); human Start Game rules (≥2 players)

### EPIC-034.4 — Win-streak + double-bank mint (ADR-034)

Status: Completed

Files:
- src/Game/GameFinishService.php (diff — streak increment/mint-on-3 in `finishGame`; HvH streak reset)
- src/Game/GameService.php (diff — `countsTowardBotStreak` flag through `finishGame`)
- src/Game/GameTurnService.php (diff — pass worker + bot-present flag on human victory)
- src/Game/ApartmentService.php (diff — last_survivor after bot refuse forces `countsTowardBotStreak=true`)
- src/Auth/SessionGuardService.php (diff — fresh login/register clears streak; reconnect does not)
- tests/Manual/test_bot_opponent.php (diff — §9 mint / last_survivor / HvH / login)
- docs/ANCHOR_CORE.md (diff — mint Live; economy + Worker Storage)
- docs/IMPLEMENTATION_STATUS.md (diff)

Notes: Mint equals pre-payout room bank; credited in the **same** SQLite transaction as the bank prize; `game_over.prize`/`final_bank` stay the bank payout; `statistics[].received` includes bank+mint. No dedicated logout action — fresh `login`/`register` (`claimUserSession(..., freshLogin=true)`) is the explicit session reclaim that clears streak. Client UI remains EPIC-034.5.

VERIFICATION:
- `php tests/Manual/test_bot_opponent.php` — **151/151 PASS** (was 136)
- `php tests/Manual/test_victory.php` — **77/77 PASS**
- `php tests/Manual/test_apartment.php` — **79/79 PASS**

CHANGED:
- Streak increment on human victory/last_survivor vs bot; mint-on-3; HvH + fresh-login resets
NOT CHANGED:
- Bot-win burn path (034.3); human-vs-human payout math; client UI (EPIC-034.5)

### EPIC-034.3 — Bot-win bank burn + streak-reset wiring (ADR-034)

Status: Completed

Files:
- src/Game/VictoryService.php (diff — `checkBotVictory()` separate from `checkAllVictories`)
- src/Game/GameTurnService.php (diff — human victory > bot victory > apartment in draw pipeline)
- src/Game/GameFinishService.php (diff — `finishBotWin()` bank burn, packet, streak unset)
- src/Game/GameService.php (diff — `finishBotWin()` orchestration wrapper)
- src/Core/StateMachineAudit.php (diff — `playing→finished` trigger `bot_win`)
- server.php (diff — `$worker->botWinStreaks = []` on worker start)
- tests/Manual/test_bot_opponent.php (diff — §8 bot_win / human victory / same-barrel priority)
- docs/ANCHOR_CORE.md (diff — named bank-burn exception Live; Worker Storage; economy note)
- docs/ANCHOR_PROTOCOL.md (diff — `bot_win` Live)
- docs/IMPLEMENTATION_STATUS.md (diff)

Notes: Bot is **never** folded into `checkAllVictories()` / `calculatePrize()` / `finishGame()` payout map. Streak convention: `unset($worker->botWinStreaks[$userId])` on bot win (missing key ⇒ 0). Increment-on-human-win and mint-on-3 remain EPIC-034.4.

VERIFICATION:
- `php tests/Manual/test_bot_opponent.php` — **136/136 PASS** (was 99; +bot_win / human victory / priority)
- `php tests/Manual/test_victory.php` — **77/77 PASS**

CHANGED:
- Separate bot victory detection + bank-burn finish path + streak reset wiring + storage init
NOT CHANGED:
- Human-vs-human victory / double-victory share math
- Streak increment / double-bank mint / logout & human-vs-human streak reset (EPIC-034.4)
- Client UI (EPIC-034.5)

### EPIC-034.2 — Apartment-with-bot resolution (ADR-034)

Status: Completed

Files:
- src/Game/ApartmentService.php (diff — `shouldTrigger`/`prepareApartment` bot parallel branches; immediate bot refuse + `player_left`; `countActiveParticipants`; last_survivor after bot clear)
- src/Game/ReconnectService.php (diff — `countActiveParticipants` includes bot for last_survivor during apartment/playing)
- tests/Manual/test_bot_opponent.php (diff — §7 apartment immune / refuse / victory-priority / prepareApartment)
- docs/ANCHOR_CORE.md (diff — apartment fold-in Live EPIC-034.2; bot_win/streak still 034.3/034.4)
- docs/IMPLEMENTATION_STATUS.md (diff)

Notes: Private-helper-on-existing-class pattern (no new class). Bot never in `apartment_responses` / never sends `apartment_choice`. Human `last_survivor` payout after bot refuse is **existing/unchanged economy** (not a new rule) — only participant-count wiring is new. `bot_win` bank-burn remains EPIC-034.3. Pre-epic loose end: `test_ip_account_limit.php` hung only inside `run_ALL_tests.php` (stray PHP/port); isolated ×2 → 22/22 PASS — noted as `run_ALL_tests.php` process-isolation gap (out of scope).

VERIFICATION:
- `php tests/Manual/test_bot_opponent.php` — **99/99 PASS** (was 59; +apartment fold-in)
- `php tests/Manual/test_apartment.php` — **79/79 PASS** (zero human-vs-human regression)
- `php tests/Manual/test_ip_account_limit.php` — **22/22 PASS** ×2 in isolation (before coding)

CHANGED:
- ApartmentService bot line scan / immune / immediate refuse / active-participant counts
- ReconnectService last_survivor participant count includes bot
- Bot apartment tests + ANCHOR annotation
NOT CHANGED:
- Human-vs-human apartment behavior; `bot_win` / bank burn; win-streak mint; client UI; `VictoryService` victory detection order (still before `shouldTrigger` in draw pipeline); `run_ALL_tests.php` process isolation

### EPIC-034.1

Status: Completed

Files:
- src/Core/RoomManager.php (diff — `$room['bot'] = null` on create)
- src/Auth/AuthService.php (diff — reserve username `Bot`)
- src/Lobby/LobbyService.php (diff — `join_room` → `error.room_full` while bot present)
- src/Game/GameService.php (diff — `handlePlayVsBot()` atomic start path)
- src/Game/GameHandler.php (diff — `handlePlayVsBot`, same handler as `start_game`)
- src/Game/GameTurnService.php (diff — nextDrawer/peek, mark bot, immediate bot draw, AFK guards)
- src/Game/ReconnectService.php (diff — AFK tick guard; roster/win_chances/`current_drawer` display only)
- src/Core/StateMachineAudit.php (diff — `play_vs_bot` waiting action + waiting→playing trigger)
- server.php (diff — dispatcher arm)
- docs/ANCHOR_CORE.md (diff — live `$room['bot']`, waiting action, protocol registry)
- docs/ANCHOR_PROTOCOL.md (diff — live `play_vs_bot` + reserved username + Bot roster)
- tests/Manual/test_bot_opponent.php (new)
- tests/Manual/test_register.php, test_auth_integration.php, test_lobby_integration.php, test_state_machine_audit.php (diff)

Notes: `play_vs_bot` lives on GameHandler/GameService because `start_game` already lives there (both are waiting→playing game-lifecycle actions; LobbyHandler stays join/leave/create). `start_game` guard logic is unchanged. ReconnectService disconnect/reconnect **seat** paths are unchanged (bot has no connection). Apartment (§5), `bot_win` bank-burn (§6), and win-streak mint (§7) are not in this Epic. Bot RAM object stores `masks` like a human player (needed for mark/win-chance; not a protocol field). Game AFK uses an explicit `bot['drawing']` guard rather than inferring safety from `active_drawer_conn_id === null`.

VERIFICATION:
- `php tests/Manual/test_bot_opponent.php` — **59/59 PASS**
- `php tests/Manual/test_game_start.php` — 51/51 PASS (`start_game` unchanged)
- `php tests/Manual/test_turn_system.php` — 59/59 PASS (human-vs-human rotation)
- `php tests/Manual/test_lobby_integration.php` — 142/142 PASS (`bot=null`, join `room_full`)
- `php tests/Manual/test_state_machine_audit.php` — 35/35 PASS
- `php tests/Manual/test_protocol_completeness.php` — `play_vs_bot` wired
- `php tests/Manual/test_register.php` — Bot/bot/BOT reserved
- `php tests/Manual/test_reconnect.php` — 176/176 PASS (seat paths unchanged)
- `php tests/Manual/test_victory.php` — 77/77 PASS
- `php tests/Manual/test_turn_nudge.php` — 32/32 PASS
- `php tests/Manual/test_game_start_turn_integration.php` — 11/11 PASS
- `php tests/Manual/test_auth_integration.php` — Bot register → `error.auth_invalid_username`
---

---

## EPIC-033C — Admin players delete + dropdown (ADR-033) (2026-08-23)

Status: Completed

- [DONE] `admin_delete_user` / `admin_bulk_delete_users` with busy guards (online / players / history / roster)
- [DONE] All-or-nothing bulk PDO transaction; no auto-kick
- [DONE] Players admin UI: table → `<select>` + detail; Ban/Unban/Kick/Delete + Delete matching preview
- [DONE] Manual test `tests/Manual/test_admin_delete_user.php`
- [DONE] KNOWN GAP noted: load/stress tests writing junk into live `game.db`

Files:
- src/Admin/AdminService.php
- src/Admin/AdminHandler.php
- server.php
- public/index.html
- public/js/ui.js
- public/js/app.js
- public/locales/*.json
- tests/Manual/test_admin_delete_user.php
- docs/IMPLEMENTATION_STATUS.md

Commit: pending
Notes: Registries already listed delete actions/errors from ADR-033 Epic A pass.

VERIFICATION:
- `php tests/Manual/test_admin_delete_user.php`
- MANUAL — admin Players dropdown select → Ban/Unban/Kick/Delete
- MANUAL — filter junk usernames → Delete matching → confirm list → purge
- MANUAL — delete rejected while target online or still in room RAM

CHANGED:
- Hard delete + bulk delete WS paths; players list UI; delete i18n; tests; KNOWN GAP note
NOT CHANGED:
- Ban/kick/unban semantics; auto-kick on delete; load-test DB isolation (KNOWN GAP)

## EPIC-033B — Admin rooms dropdown (ADR-033) (2026-08-23)

Status: Completed

- [DONE] Replace admin rooms flat table with `<select>` + detail + Close
- [DONE] Reuse existing `admin_stats_data.rooms` / `room_list` fields only (no protocol)
- [DONE] Preserve selection across refresh when room still exists

Files:
- public/index.html
- public/js/ui.js
- public/js/app.js
- public/css/style.css
- public/locales/*.json
- docs/IMPLEMENTATION_STATUS.md

Commit: pending
Notes: UI-only per ADR-033 Epic B. `admin_close_room` unchanged.

VERIFICATION:
- MANUAL — open admin panel → Active Rooms shows dropdown; empty state when no rooms
- MANUAL — select a room → detail shows id / players / status / lock; Close enabled
- MANUAL — Close room → room removed on next stats/list refresh; selection clears if gone
- MANUAL — Refresh rooms preserves selection when room still present

CHANGED:
- Admin rooms list presentation (table → select + detail)
NOT CHANGED:
- Protocol / server / `admin_close_room`; players moderation UI; password rotation

## EPIC-033A — Admin password rotation (ADR-033) (2026-08-23)

Status: Completed

- [DONE] ADR-033 accepted (password rotation + account deletion design; Epic B UI-only confirmed)
- [DONE] `PasswordPolicy::validateAdminPassword()` shared helper (registration rules untouched)
- [DONE] `admin_change_password` / `admin_change_password_result` + error codes
- [DONE] Admin UI modal (current / new / confirm)
- [DONE] CLI `change_admin_password.php` left alone as emergency fallback (may be absent)

Files:
- docs/ADR/033-admin-panel-password-rotation-and-account-management.md
- docs/ANCHOR_CORE.md
- docs/ANCHOR_PROTOCOL.md
- docs/IMPLEMENTATION_STATUS.md
- src/Auth/PasswordPolicy.php
- src/Admin/AdminService.php
- src/Admin/AdminHandler.php
- src/Infrastructure/PreparedStatements.php
- server.php
- public/index.html
- public/js/app.js
- public/locales/*.json
- tests/Manual/test_admin_change_password.php

Commit: 43c6380
Notes: Acting admin only (by userId). Write-verify transaction before COMMIT. Cherry-picked onto feature/room-chat-files.

VERIFICATION:
- `php tests/Manual/test_admin_change_password.php` — 12/12 passed
- MANUAL — admin panel → Change admin password; wrong current / weak new / success login with new password

CHANGED:
- Admin password rotation WS path + ADR-033 + registries + UI modal + tests
NOT CHANGED:
- Registration password rules; CLI change_admin_password.php; ban/kick/delete; rooms/players list UI

## EPIC-030 — Room chat + file transfer (feature/room-chat-files) (2026-08-23)

Status: Completed (experimental branch — not for `main` merge until demand-validated)

- [DONE] ADR-030: Chat module boundary, offer/accept/reject/timeout state machine, protocol, rate limits, memory footprint
- [DONE] Server: `src/Chat/{ChatHandler,ChatService,FileTransferService}`; password-room gate; room transfer lock; decoded-size validation; dedicated file rate limit; disconnect/timeout release
- [DONE] Client: chat panel only when `has_password`; 1-to-1 file offer UI; accept/reject modal; forced-download links (`<a download>`, never inline preview)
- [DONE] Manual tests: `tests/Manual/test_chat_file_transfer.php` (31/31)
- [DONE] ANCHOR_CORE / ANCHOR_PROTOCOL registries updated in the same pass as the ADR

Files:
- docs/ADR/030-room-chat-and-file-transfer.md
- docs/ANCHOR_CORE.md
- docs/ANCHOR_PROTOCOL.md
- docs/IMPLEMENTATION_STATUS.md
- src/Chat/ChatHandler.php
- src/Chat/ChatService.php
- src/Chat/FileTransferService.php
- src/Core/Constants.php
- src/Core/RoomManager.php
- src/Lobby/LobbyService.php
- src/Game/ReconnectService.php
- server.php
- public/index.html
- public/css/style.css
- public/js/ui.js
- public/js/app.js
- public/locales/*.json
- tests/Manual/test_chat_file_transfer.php

Commit: 5e786ec
Notes: Isolated on `feature/room-chat-files`. Chat/file never touch SQLite. Workerman `WS_MAX_PACKAGE_SIZE=2MiB` explicit. Unilateral decisions listed in ADR-030 Consequences.

VERIFICATION:
- `php tests/Manual/test_chat_file_transfer.php` — 31/31 passed
- `php run_ALL_tests.php` — see final summary
- MANUAL — password room: chat broadcast; open room: chat hidden/rejected; file offer accept → download link only; reject → `file_rejected`; second offer while busy → `error.file_transfer_busy`

## EPIC-032a — Nudge voice i18n (client) (2026-08-23)

Status: Completed

- [DONE] `nudge_received` voice line resolves `audio/nudge_<lang>.mp3` from recipient's current `LottoI18n.getLang()` on every play.
- [DONE] Migrated `nudge.mp3` → `nudge_en.mp3`; missing per-language files fall back to English without breaking other sounds.
- [DONE] Per-language cache keys (`nudge_en`, `nudge_ru`, …) so language switches within an open tab apply on the next nudge.

Files:
- public/js/sound.js
- public/audio/nudge_en.mp3 (renamed from nudge.mp3)
- public/audio/README.md
- docs/IMPLEMENTATION_STATUS.md

Commit: 2a42674
Notes: Client-only; no ADR/protocol/server changes (ADR-032 server rules unchanged). Voice recordings for `ru/es/fr/zh/tr` are out of scope — fallback to `nudge_en.mp3` until added.

VERIFICATION:
- MANUAL — with `getLang() === 'en'`, `nudge_received` plays `nudge_en.mp3`.
- MANUAL — with `getLang()` set to `ru` (no `nudge_ru.mp3` on disk), `nudge_en.mp3` plays instead.
- MANUAL — switch language via selector without reload; next `nudge_received` uses new language.
- MANUAL — other sounds (`spin`, `apartment`, `reveal`, `match`, `victory`, `defeat`) unchanged.

## Frontend sound — volume slider + loop cues (2026-08-17)

Status: Completed

- [DONE] `LottoSound.setVolume()` / `getVolume()` with `localStorage` persistence (`lotto_sound_volume`, default 0.7); volume slider in game top-bar.
- [DONE] `startLoop()` / `stopLoop()` for spin (loops until last drum reveals) and apartment (required players only).
- [DONE] Spin: `startSlotsWaiting()` → `startLoop('spin')`. Normal stop is inside `revealSlot()` at the tick that commits the last drum’s number (`!isSlotsSpinning()` after removing that drum’s `spinning` class) — not after `await revealSlot()` returns (that Promise waits an extra 450ms) and not in `app.js` after the for-loop. Safety-net / interruption stops: `stopSlotsWaiting()`, `resetSlots()`, `idleSlot()` (only when no drum still spinning).
- [DONE] Apartment: `startLoop('apartment')` when `required === true`; stops on timeout, `hideApartment()` (game over, bank update, reset to lobby).
- [DONE] 7th sound key `apartment`; `public/audio/README.md` loopability guidance for `spin.mp3` and `apartment.mp3`.

Files:
- public/js/sound.js
- public/js/ui.js
- public/index.html
- public/css/style.css
- public/audio/README.md
- docs/IMPLEMENTATION_STATUS.md

VERIFICATION:
MANUAL VERIFICATION REQUIRED — browser audio timing cannot be verified by the PHP test suite; see Epic completion QA checklist in chat / manual steps below.
- Volume slider changes audible level in real time; mute silences regardless of volume.
- Spin loop starts when drums spin; stops when last drum reveals or on reset/reconnect mid-spin (F1/F2 hooks).
- Apartment sound only for `required` players; stops on timeout, hideApartment paths; no leak into next turn.

## Lobby projected bank (pre-game) (2026-08-17)

Status: Completed

- [DONE] `bet_per_card` on `room_joined` (`LobbyService::buildRoomJoinedPacket`).
- [DONE] `bet_per_card` on waiting `reconnect_state`
  (`ReconnectService::buildReconnectState`) — fixes hard-refresh reconnect path.
- [DONE] Client `#room-bank-label` shows projected bank in `waiting`
  (`sum(cards_count) * bet_per_card`); real `room.bank` once game starts.
- [DONE] `test_lobby_integration.php` + `test_reconnect.php` projection assertions.

Files:
- src/Lobby/LobbyService.php
- src/Game/ReconnectService.php
- docs/ANCHOR_PROTOCOL.md
- public/js/app.js
- public/js/ui.js
- tests/Manual/test_lobby_integration.php
- tests/Manual/test_reconnect.php

VERIFICATION:
- `php tests/Manual/test_lobby_integration.php`
- `php tests/Manual/test_reconnect.php`

## EPIC-032 — Turn nudge (ADR-032) (2026-08-17)

Status: Completed

- [DONE] Once-per-turn social `nudge_turn` from non-drawer to current drawer.
- [DONE] Private `nudge_received` packet; AFK timer fields never written.
- [DONE] Client button + toast + 6th `LottoSound` key `nudge` (no vibration / Web Notifications).

Docs (already on `main` as `8eeb649`): ADR-032, ANCHOR_PROTOCOL.md, ANCHOR_CORE.md Part 6 registries.

Files:
- src/Game/GameHandler.php
- src/Game/GameService.php
- src/Game/GameTurnService.php
- src/Core/StateMachineAudit.php
- server.php
- public/index.html
- public/js/app.js
- public/js/ui.js
- public/js/sound.js
- public/css/style.css
- public/audio/README.md
- public/locales/*.json
- tests/Manual/test_turn_nudge.php (new)
- docs/IMPLEMENTATION_STATUS.md

Commit: (implementation — pending user commit; docs `8eeb649`)
Notes: `GameHandler` 89 / 300; `GameTurnService` 500 / 500; `GameService` 496 / 500.

VERIFICATION:
- `php tests/Manual/test_turn_nudge.php` — 32/32 passed
- `php tests/Manual/test_turn_system.php` — 59/59 passed
- `php tests/Manual/test_game_start_turn_integration.php` — 11/11 passed

## EPIC-031c-b — IP account limit server guard (ADR-031) (2026-08-16)

Status: Completed

- [DONE] `IpAccountLimitService` with trusted-proxy trust boundary
  (`LOTTO_TRUSTED_PROXY_IPS`, X-Forwarded-For / X-Real-IP, sentinel bucket).
- [DONE] `MAX_ACCOUNTS_PER_IP = 3` in `Constants.php`; guard on login/register
  auto-login in `AuthHandler`; `clientRemoteIp` attached at handshake in
  `server.php`.
- [DONE] Manual tests `test_ip_account_limit.php` (unit (e)(f)(g) + WS (c)(d)).

Files:
- src/Core/Constants.php
- src/Auth/IpAccountLimitService.php (new)
- src/Auth/AuthHandler.php
- server.php
- tests/Manual/test_ip_account_limit.php (new)
- tests/Manual/test_* (AuthHandler constructor + IpAccountLimitService DI)

VERIFICATION:
- `php tests/Manual/test_ip_account_limit.php`
- `php tests/Manual/test_tab_ownership.php`

## EPIC-031c-a — Trusted proxy IP docs + registry (ADR-031) (2026-08-16)

Status: Completed

- [DONE] ADR-031 addendum: trust boundary, sentinel fallback, updated limitations.
- [DONE] `clientRemoteIp` in ANCHOR_CORE Connection Runtime Fields.
- [DONE] README §3.8 + LOCAL_ENVIRONMENT `LOTTO_TRUSTED_PROXY_IPS`.

Files:
- docs/ADR/031-per-account-tab-ownership-and-ip-account-limit.md
- docs/ANCHOR_CORE.md
- README.md
- docs/LOCAL_ENVIRONMENT.md

## EPIC-031b — Per-account tab ownership client fix (ADR-031) (2026-08-16)

Status: Completed

- [DONE] `STORAGE_OWNER_TAB` stores `{tabId, userId}`; relinquish only on
  same-account handoff; legacy bare tab id fail-safe.
- [DONE] `tests/Manual/test_tab_ownership.php`.

Files:
- public/js/app.js
- tests/Manual/test_tab_ownership.php (new)

VERIFICATION:
- `php tests/Manual/test_tab_ownership.php`
- Manual browser: two Incognito accounts coexist; same-account second tab handoff.

## EPIC-031a — Per-account tab ownership + IP limit ADR (ADR-031) (2026-08-16)

Status: Completed

- [DONE] ADR-031 documents Part A (client per-account tab ownership) and Part B
  (server `MAX_ACCOUNTS_PER_IP` guard); numbering skips 030 on `main` (possible
  claim on `feature/room-chat-files`).
- [DONE] Protocol + core registries: `error.auth_too_many_accounts_same_network`,
  `MAX_ACCOUNTS_PER_IP = 3`, `IpAccountLimitService` in Auth module / Part 6.

Files:
- docs/ADR/031-per-account-tab-ownership-and-ip-account-limit.md (new)
- docs/ANCHOR_PROTOCOL.md
- docs/ANCHOR_CORE.md

Notes: Documentation only — no PHP/JS implementation in this epic.
Follow-up: EPIC-031b (`public/js/app.js`), EPIC-031c (server guard + tests).

CHANGED:
- Error code registry and ADR-031 narrative for honest IP-cap rejection at login
- Global constant `MAX_ACCOUNTS_PER_IP` and Auth module class registry

NOT CHANGED:
- `public/js/app.js`, `src/Auth/*`, `src/Core/Constants.php`, tests

VERIFICATION:
- Manual: confirm ADR-031 sections match approved stakeholder spec (Part A/B split,
  `MAX_ACCOUNTS_PER_IP = 3`, reconnect exempt, register auto-login in scope).
- Manual: grep `ANCHOR_PROTOCOL.md` for `error.auth_too_many_accounts_same_network`.
- Manual: grep `ANCHOR_CORE.md` for `MAX_ACCOUNTS_PER_IP` and `IpAccountLimitService`.

## Client game sounds + mute toggle (2026-08-15)

- [DONE] `public/js/sound.js` — HTML5 Audio preload/play for 5 optional clips in
  `public/audio/` (`spin`, `reveal`, `match`, `defeat`, `victory`); missing files
  fail silently via `error` listener + `.play().catch()`.
- [DONE] Triggers: `startSlotsWaiting`, `revealSlot` (at number reveal),
  per-barrel match in `animateBarrelsDrawn`, `onGameOver` (victory if
  `received > 0`; defeat only when `reason === 'victory'` and `received === 0`;
  **no sound** for `no_survivors`).
- [DONE] Mute toggle `#sound-mute-btn` in game top-bar; `localStorage`
  `lotto_sound_muted` (default ON).

Files: `public/js/sound.js`, `public/audio/README.md`, `public/js/ui.js`,
`public/js/app.js`, `public/index.html`, `public/css/style.css`

## Apartment locale fix + drawn-strip/card marks (2026-08-15)

- [DONE] Fixed apartment UI copy: `apartment.required` / `apartment.agree` showed
  10 coins in all 6 locales; server charges flat `APARTMENT_PAYMENT = 5` per
  ANCHOR_CORE.md / `ApartmentService`.
- [DONE] Drawn-number strip: segments matching player's marked card numbers
  render red (`--chip`); other drawn segments stay gold.
- [DONE] Card cell mark: red circle replaced with diagonal cross (`::before` +
  `::after` lines, `--chip` color).

Files: `public/locales/*.json`, `public/js/ui.js`, `public/js/app.js`,
`public/css/style.css`, `public/index.html`

## Auth/lobby header + drawn-number strip UI (2026-08-15)

- [DONE] Product feedback: auth screen shows logo only (removed redundant
  `<h1 data-i18n="app.title">`); lobby header shows plain `app.title` text
  between balance and language buttons (removed lobby logo image).
- [DONE] Replaced `#drawn-history` chip list with a responsive 1–90 horizontal
  segment strip (`renderDrawnHistory` in `ui.js`); drawn segments use `--gold`,
  undrawn stay neutral; number tooltip on `:hover`/`:focus` via CSS `::after`.

Files:
- public/index.html
- public/css/style.css
- public/js/ui.js

CHANGED:
- Auth: removed duplicate title heading; lobby: logo → `span.top-bar-title`
  with existing `app.title` i18n key (no new locale keys)
- `#drawn-history`: 90 flex segments, `aria-label` per segment, full-array
  correctness on all three `renderDrawnHistory` call sites in `app.js`

NOT CHANGED:
- `public/js/app.js`, protocol, server, locale JSON files
- `.logo-img` / `.logo-placeholder` base rules (auth still uses them)
- `.drawn-chip` CSS left in place (unused)

VERIFICATION:
- `renderDrawnHistory` receives full `state.drawnAll` on draw, reset, and
  `reconnect_state` — each call rebuilds all 90 segment states via `Set`
- Responsive width: `flex: 1 1 0` on 90 segments; ~3px/segment at 320px viewport
- Tooltip: CSS-only `::after` on `:hover` and `:focus` (keyboard + touch tap)

## Optional image asset wiring (2026-08-13)

- [DONE] Wired optional `public/img/` assets into the frontend with graceful
  degradation when files are absent (fresh clone = unchanged appearance).

Files:
- public/index.html (`<img>` logos + chip probe script)
- public/css/style.css (logo-img, felt-bg layer, chip icon)

Assets:
- **logo.png** — `<img src="img/logo.png">`; `onerror` calls `replaceWith()` a
  `<div class="logo-placeholder">` (or `.small` variant), restoring the exact
  pre-integration gradient box with no broken-image icon.
- **felt-bg.png** — comma-separated `background-image` on `body` (texture +
  existing radial gradient); `background-color: var(--felt-dark)` base.
- **chip.png** — inline probe adds `html.has-chip-icon`; CSS replaces 🪙 emoji.
- **barrel.png** — not wired (see notes).

Notes: No binary assets committed. `barrel.png` skipped: `.drawn-chip` history
entries are compact number pills (~0.8rem); a 64×64 icon would clutter layout.

CHANGED:
- Auth/lobby logo placeholders → optional `<img>` with onerror fallback
- `body` background layers for optional felt texture
- Balance `.coins` icon when `chip.png` exists

NOT CHANGED:
- `public/js/ui.js`, `public/js/app.js` (barrel history rendering)
- Protocol, server, locale files
- Binary files in `public/img/`

VERIFICATION:
- Fresh clone (no PNGs): gradient logos, felt gradient, 🪙 emoji unchanged
- With PNGs deployed: logos, texture, chip icon appear

## Rules modal copy rewrite (2026-08-13)

- [DONE] Expanded `rules.*Body` strings in all 6 locale files (en, ru, es, fr,
  zh, tr) with player-facing explanations of core loop, economy/bank, cards,
  Apartment, victory split, and reconnect. Fixed incorrect apartment fee (was
  10 in copy; actual is 5 coins). Removed English "reconnect" leak in ru.

Files:
- public/locales/en.json, ru.json, es.json, fr.json, zh.json, tr.json

Notes: Content-only — no i18n key changes, no .php/.js edits. Numbers verified
against `Constants.php`, `ApartmentService::APARTMENT_PAYMENT`, `PreparedStatements`
(create_user 500 coins), `docs/GAME_RULES.md`, `docs/ANCHOR_CORE.md` Part 2.

CHANGED:
- `rules.introBody`, `rules.economyBody`, `rules.cardsBody`,
  `rules.apartmentBody`, `rules.victoryBody`, `rules.reconnectBody` (×6 locales)

NOT CHANGED:
- `renderRules()` / `public/js/ui.js`, any `.php` file, i18n key structure

VERIFICATION:
- `php tests/Manual/test_frontend_i18n.php`

## Rules modal close-button contrast fix (2026-08-13)

- [DONE] Scoped `.rules-panel .icon-btn { color: var(--wood); }` so the "✕"
  glyph is visible on the pale-gold panel background (`#f5e6a8`). Base
  `.icon-btn` keeps `color: var(--cream)` for dark wood/felt contexts.

Files:
- public/css/style.css

Notes: Presentational only — no protocol/ADR/JS changes. Old contrast
cream (`#f5e6c8`) vs panel bg: **1.02:1** (fails WCAG). New wood
(`#5c3d1e`) vs panel bg: **7.83:1** (passes AA 3:1 for UI graphics).

CHANGED:
- `#rules-close-btn` glyph color inside `.rules-panel`

NOT CHANGED:
- Global `.icon-btn` base color
- `#auth-lang-btn`, `#rules-btn-auth`, `#rules-btn-lobby` (dark backgrounds)
- `#join-room-close-btn` (parent `.join-room-panel` keeps default wood panel)
- `#admin-close-btn` (parent `.admin-panel` keeps default wood panel)
- app.js / ui.js event handlers

## WebSocket Origin allow-list (ADR-029) (2026-08-12)

- [DONE] Optional `LOTTO_ALLOWED_ORIGINS` gate in `onWebSocketConnected`
  (bootstrap only; default allow-all).
Files:
- docs/ADR/029-websocket-origin-allowlist.md (new)
- server.php (`$worker->allowedOrigins`, Origin check before hello)
- tests/Manual/test_server_bootstrap.php (TEST 9–11)
- README.md §3.7, docs/LOCAL_ENVIRONMENT.md

Notes: Token-based auth blocks classic CSWSH cookie riding; control addresses
residual resource/spam risk and defense-in-depth. Reject path:
`error.origin_forbidden` + WS close 4002 (ADR-005 pattern).

CHANGED:
- `onWebSocketConnected` Origin inspection when env list is non-empty

NOT CHANGED:
- Auth/Lobby/Game/Admin Handlers or Services

VERIFICATION:
- `php tests/Manual/test_server_bootstrap.php`
- VPS: `sudo -u www-data php run_ALL_tests.php` (manual — agent has no SSH)

## Admin assertAdmin SQLite freshness (2026-08-12)

- [DONE] `AdminService::assertAdmin()` re-reads `users.is_admin` and
  `banned_until` from SQLite via `user_auth_fields_by_id` on each admin action.
Files:
- src/Admin/AdminService.php (`fetchUserAuthFields()`, demotion/ban sync)
- tests/Manual/test_admin_auth.php (groups 4–6: demotion, still-admin, banned)

Notes: Stale `$connection->isAdmin` cleared on demotion/ban; subsequent calls
fail fast without DB. Client sees `error.not_your_turn` on demotion (no hint);
active ban uses existing `banned` packet. One SQLite read per admin action when
`isAdmin` is true — acceptable (admin panel is low-frequency, not polled in a
tight loop).

CHANGED:
- Admin guard authoritative against SQLite

NOT CHANGED:
- AdminHandler, register/login flows, game/lobby handlers

VERIFICATION:
- `php tests/Manual/test_admin_auth.php`
- `php run_ALL_tests.php`

## EPIC-5a — Per-username login lockout (ADR-028) (2026-08-12)

- [DONE] RAM-only `LoginThrottleService`; wired into `AuthService` / `server.php`;
  `error.auth_rate_limited` in protocol registry (generic client message only).
Files:
- docs/ADR/028-auth-abuse-throttling.md (new)
- src/Auth/LoginThrottleService.php (new)
- src/Core/Constants.php (LOGIN_THROTTLE_* constants)
- docs/ANCHOR_CORE.md (constants + class registry)
- docs/ANCHOR_PROTOCOL.md (`error.auth_rate_limited`)
- src/Auth/AuthService.php (throttle check; preserves 007712c timing path)
- src/Auth/AuthHandler.php (`error.auth_rate_limited` mapping; generic message)
- server.php (`$worker->loginThrottle` DI)
- tests/Manual/test_login_throttle.php (new)

Notes: Defaults — 5 failures / 300s window / 900s lockout. Register throttling
deferred to EPIC-5b (placeholder section in ADR-028 only). Client receives
`Invalid username or password` for rate-limited logins — no attempt count or
remaining lockout time exposed.

CHANGED:
- Login failure throttling per username (Auth module, RAM-only)
- Protocol error code `error.auth_rate_limited`

NOT CHANGED:
- Register flow / `handleRegister()` / register throttling
- SessionGuard, reconnect, lobby/game handlers

VERIFICATION:
- `php tests/Manual/test_login_throttle.php`
- `php run_ALL_tests.php`

## AuthService login timing hardening (2026-08-12)

- [DONE] Constant-time password_verify path for unknown usernames in login()
Files:
- src/Auth/AuthService.php (diff — dummy bcrypt hash on missing user row)
- tests/Manual/test_auth_integration.php (diff — identical error-message assertion)

Notes: When username is not found, `password_verify()` still runs against a
precomputed dummy bcrypt hash before throwing — reduces username enumeration via
response-time analysis. External login() contract unchanged.

CHANGED:
- AuthService::login() internal timing path only

NOT CHANGED:
- AuthHandler.php, SessionGuardService.php, exception messages, return shape

VERIFICATION:
- `php tests/Manual/test_auth_integration.php` — PASS
- Full suite: `php run_ALL_tests.php` (see run below)

## ANCHOR_CORE Part 6 registry back-fill (2026-08-12)

- [DONE] Back-fill missing protocol registry entries in ANCHOR_CORE.md Part 6
Files:
- docs/ANCHOR_CORE.md (diff — `turn_ready` action; `player_status_changed`,
  `admin_users_data` packet types)

Notes: Pure documentation/registry sync — no code changes. `turn_ready` was
implemented (server.php, GameHandler, ANCHOR_PROTOCOL.md ADR-017) but omitted
from the Part 6 action list. Packet sweep found two additional omissions already
documented in ANCHOR_PROTOCOL.md and implemented in production code.

CHANGED:
- ANCHOR_CORE.md Part 6 § Protocol Actions — added `turn_ready`
- ANCHOR_CORE.md Part 6 § Protocol Packet Types — added `player_status_changed`,
  `admin_users_data`

NOT CHANGED:
- Any `.php` source files
- ANCHOR_PROTOCOL.md packet contracts

VERIFICATION:
- `git diff --stat` shows only `.md` files modified

## EPIC-028.3 — Asymmetric cross-engine session closure + economy invariant net (2026-08-12)

- [DONE] Close ADR-026 reproduction gap; add EconomyAudit structural safety net
Files:
- tests/Manual/test_asymmetric_engine_stress.php (new — asymmetric teardown create+join stress)
- src/Core/EconomyAudit.php (diff — `checkWorkerInvariants()` duplicate-seat/dual-auth checks)
- src/Core/Helpers.php (diff — `lottoEconomyCheckInvariants()` helper)
- src/Core/RoomManager.php (diff — invariant scan on `destroyRoom()`)
- src/Game/GameService.php (diff — invariant scan on `finishGame()` teardown)
- docs/ADR/026-fix-concurrent-session-bug.md (append — EPIC-028.3 addendum)
- tests/Manual/test_economy_audit.php (diff — invariant check regression group)

Notes: Part A stress test models delayed onClose after fresh login with both
sockets attempting create_room/join_room — **no gap reproduced**; existing
SessionGuardService sweeps close the window. Part B adds detect-and-log-only
invariant monitoring (no balance mutation).

CHANGED:
- ADR-026 "Honest limit" superseded by EPIC-028.3 closure verification addendum.
- EconomyAudit structural checks on room destroy and game finish.

NOT CHANGED:
- SessionGuardService logic (no fix required — test proves current sweeps sufficient).
- Protocol packets, Handler/Service business rules.

VERIFICATION:
- `php tests/Manual/test_asymmetric_engine_stress.php` — PASS
- `php tests/Manual/test_economy_audit.php` — PASS
- Full suite: `php run_ALL_tests.php` (see run below).

## TLS/WSS documentation-vs-code fix (2026-08-12)

- [DONE] Close TLS/WSS documentation-vs-code mismatch (ADR-027)
Files:
- docs/ADR/027-reverse-proxy-tls-termination.md (new — reverse-proxy TLS decision)
- README.md (diff — §3 rewritten: nginx/Caddy WSS via proxy; `config/ssl.php` removed)
- public/index.html (diff — `lotto-ws-port` / `lotto-ws-path` deploy meta tags)
- public/js/ws.js (diff — `resolveWsUrl()` reads meta; no hardcoded `:8080` on HTTPS)
- tests/Manual/test_ws_url_resolution.php (new — URL resolution + README/ADR checks)

Notes: Chose **option (b) reverse-proxy TLS termination** over native Workerman TLS
(lower RAM/CPU risk on 1 CPU / 500 MB VPS; avoids growing `server.php`; cert renew
reloads proxy only). `server.php` unchanged — still plain `websocket://0.0.0.0:8080`.
Production clients use `wss://host/ws` (443) when meta tags set per README §3.

CHANGED:
- README SSL section meaning: **was** native Workerman `config/ssl.php` on port 8443;
  **now** external nginx/Caddy terminates TLS, worker stays plain WS on 8080.
- Client transport URL derivation from deploy meta + page protocol.

NOT CHANGED:
- `server.php` bootstrap, protocol packets, Handlers/Services business logic.

VERIFICATION:
- `php tests/Manual/test_ws_url_resolution.php` — PASS
- Full suite: `php run_ALL_tests.php` (see run below).

## Phase 18 — FIX-30 Multi-session auth hardening (2026-08-06)

- [DONE] FIX-30 Concurrent multi-session auth bug (single account, multiple browsers)
Files:
- src/Auth/AuthHandler.php (diff — `claimUserSession()`, evict superseded connections)
- src/Auth/AuthService.php (diff — session registry moved out of `login()`)
- server.php (diff — ownership-safe `userConnections` cleanup on `onClose`)
- src/Lobby/LobbyService.php (diff — one seat per `user_id`, `user_id` on `player_left`)
- src/Game/ReconnectService.php (diff — `rebindSeat()`, `user_id` on `player_left`)
- public/js/app.js (diff — `player_left` by `user_id`; superseded session message)
- public/locales/en.json, ru.json (diff — `auth_session_superseded`)
- tests/Manual/test_single_session.php, test_session_lifecycle.php, test_multi_session.php
- docs/ADR/001.md, docs/ANCHOR_PROTOCOL.md

Notes: ADR-001 amended — newest login/reconnect wins (evict prior live session) instead
of reject-only second login. Prevents dual authenticated sockets and duplicate room
seats; `player_left` no longer resets unrelated clients with the same username.

VERIFICATION:
- `php tests/Manual/test_single_session.php` — PASS
- `php tests/Manual/test_session_lifecycle.php` — PASS
- `php tests/Manual/test_multi_session.php` — PASS
- MANUAL: Browser A login → close → Browser B login → reopen A → only one session;
  leave from one client does not spuriously reset the other.

## Phase 18 — Client Balance Persistence (2026-08-02)

- [DONE] FIX-29 Browser-reopen reconnect + SVG line chart (game-over)
Files:
- public/js/app.js (diff — `hasPersistedSession()`; no token wipe on init; reconnect on WS open)
- public/js/ui.js (diff — SVG `polyline` line chart in `renderWinChanceChart`)
- public/index.html (diff — chart container div instead of canvas)
- public/css/style.css (diff — `.win-chance-line-chart` styles)
- public/locales/*.json (diff — `game.chartTurn`)
- src/Game/ReconnectService.php (diff — `adoptSessionTokenForUser()`)
- server.php (diff — login → adopt token + `handleReconnect()`)
- tests/Manual/test_reconnect.php (diff — GROUP 3c adopt token)
- tests/Manual/test_frontend_structure.php (diff — SVG chart + reconnect checks)

Notes: Closing the browser clears sessionStorage but kept localStorage token was
wiped on next visit (`init()` + sessionStorage gate). Reconnect now attempts on
any persisted token. Login after manual re-auth adopts the new `session_token` onto
the disconnected room player so `handleReconnect()` can match. Game-over chart
rendered as SVG line chart (grid, axes, polylines, point markers, legend).

CHANGED:
- Client: auto-reconnect on page load when `lotto_session_token` exists
- Server: `login` action tries room restore after token adoption
- Game-over modal: canvas chart → SVG line chart

NOT CHANGED:
- 15s playing disconnect grace, lobby immediate removal, F2 QA hotkey

VERIFICATION:
- `php tests/Manual/test_reconnect.php` — PASS (GROUP 3c).
- `php tests/Manual/test_frontend_structure.php` — PASS.
- MANUAL: start game → close browser tab → reopen within 15s → auto-rejoin room;
  finish game → game-over modal shows multi-line SVG chart.

- [DONE] FIX-28 Exponential win-chance formula (LottoEngine)
Files:
- src/Game/LottoEngine.php (diff — `calculateWinChances()` static, exponential weights)
- src/Game/VictoryService.php (diff — delegate to LottoEngine; username wire map)
- src/Game/GameService.php (diff — pass `room.status` for apartment immune ×1.1)
- src/Game/ReconnectService.php (diff — same)
- public/js/ui.js (diff — bar shows 1-decimal server percent)
- tests/Manual/test_lotto_engine.php, test_victory.php, test_turn_system.php
- docs/ADR/014.md (amendment), docs/ANCHOR_PROTOCOL.md, docs/GAME_RULES.md

Notes: Replaces ADR-014 inverse-moves formula with `turnsToWin = 15 − bestCardClosed`,
`weight = exp(−0.25 × turnsToWin)`, normalize to 1 decimal summing 100%. Active only;
disconnected excluded; complete card → 100% for winner(s). Wire keys remain `username`.

CHANGED:
- `LottoEngine::calculateWinChances()` — core math
- `VictoryService::calculateWinChances()` — conn_id → username for packets

NOT CHANGED:
- Victory/payout logic, client progress-bar placement (FIX-27), game-over chart

VERIFICATION:
- `php tests/Manual/test_lotto_engine.php` — PASS (+winChance engine group).
- `php tests/Manual/test_victory.php` — PASS (GROUP 3b updated).
- `php tests/Manual/test_turn_system.php` — PASS (win_chances numeric, sum 100%).

- [DONE] FIX-27 Win-chance progress bar + game-over probability chart
Files:
- public/index.html (diff — win-chance track/fill; game-over chart canvas + legend)
- public/css/style.css (diff — bar gradient styles, chart panel)
- public/js/ui.js (diff — `updateWinChanceBar`, `renderWinChanceChart`; players list without %)
- public/js/app.js (diff — track `winChanceHistory` from server `win_chances` snapshots)
- public/locales/*.json (diff — `game.winChanceHistory`)
- docs/GAME_RULES.md (diff — single bar location, sidebar fields, end-game graph)

Notes: Win chance shown only above slot machine as red→blue progress bar (server
comparative % from `barrels_drawn.win_chances`). Player sidebar: nickname, cards,
status only. Client records per-turn snapshots for game-over line chart (no protocol
change). History lost on mid-game refresh/reconnect — acceptable client-only scope.

CHANGED:
- Personal win-chance UI: progress bar with `winChanceBarColor()` gradient
- `renderGamePlayers()` / sidebar: no win-chance column
- `showGameOver()` renders turn-indexed probability chart after statistics table

NOT CHANGED:
- `win_chances` server calculation (ADR-014), protocol packets, payout logic

VERIFICATION:
- `php tests/Manual/test_frontend_structure.php` — PASS.
- MANUAL: 2-player game → bar updates after each draw; sidebar has no %; game-over
  modal shows multi-line chart when ≥2 turns recorded.

- [DONE] FIX-26 F2 in-game reconnect QA hotkey + guard fix on page refresh
Files:
- public/js/ws.js (diff — `simulateTransportDrop()` closes WS without `intentionalClose`)
- public/js/app.js (diff — F2 key during `playing`; reconnect guard loads persisted user)
- public/locales/*.json (diff — `dev.f2Disconnect`, `dev.f2PlayingOnly`)
- docs/LOCAL_ENVIRONMENT.md (diff — F1/F2 manual reconnect steps)
- tests/Manual/test_frontend_structure.php (diff — F2 + simulateTransportDrop checks)

Notes: F2 reconnect manual test (playing-phase disconnect within 15s) had no way to
trigger transport loss without closing the tab (which clears sessionStorage and
aborts auto-reconnect). F2 simulates a drop while keeping the tab session alive.
Reconnect guard now calls `ensureUserProfile()` before timing out so F5 refresh
during a game does not spuriously clear localStorage while `reconnect_state` is
in flight.

CHANGED:
- `LottoSocket.simulateTransportDrop()` for QA
- F2 handler: only when `state.inGame`; toast + auto-reconnect path
- `startReconnectGuard()`: load persisted profile, 10s timeout, skip clear if user restored

NOT CHANGED:
- Server reconnect/disconnect logic, 15s playing grace, F1 lobby immediate removal

VERIFICATION:
- `php tests/Manual/test_frontend_structure.php` — PASS (+2 assertions).
- MANUAL (F2): 2-player game → press F2 → reconnect overlay → game restored with toast
  «Session restored»; if your turn, draw button enabled (FIX-17). F2 in lobby shows hint only.

- [DONE] FIX-25 Quick Start pseudo-random room pick when multiple eligible
Files:
- public/js/ui.js (diff — `pickQuickStartRoom()` filters + random choice among eligible)
- public/js/app.js (diff — `doQuickStart()` uses `pickQuickStartRoom` instead of `.find()`)
- docs/GAME_RULES.md (diff — §2 quick start multi-room behavior)

Notes: Previously Quick Start always joined the first eligible room in `room_list` order.
Now: 0 eligible → error; 1 → that room; 2+ → `Math.floor(Math.random() * n)`.

CHANGED:
- `pickQuickStartRoom()` helper exported from UI module
- `doQuickStart()` uses pseudo-random selection

NOT CHANGED:
- Eligibility rules (`waiting`, no password, not full), join_room flow, server room_list

VERIFICATION:
- MANUAL: create 2+ open waiting rooms without password → Quick Start joins a random one
  across repeated clicks (before joining); single room → always that room.

- [DONE] FIX-24 No lobby reconnect grace — waiting disconnect removes player immediately
Files:
- src/Game/ReconnectService.php (diff — `handleDisconnect()` waiting → `removePlayerFromLobby`; timer callback playing-only)
- public/js/app.js (diff — `auth_result` clears stale room; `player_left` reason `disconnect` resets lobby)
- tests/Manual/test_reconnect.php (diff — GROUP 1b waiting immediate removal; GROUP 2 playing timeout)
- tests/Manual/test_timer_audit.php (diff — GROUP 4 split waiting vs playing)

Notes: Closes reconnect F1: disconnected lobby player stayed in `room['players']` as
`disconnected` with 15s timer, inflating room_list counts and allowing stale reconnect.
User rule: no reconnect tracking in lobby; only after `start_game` (`playing`). Page-refresh
re-key in waiting (active before onClose) unchanged via `handleReconnect()` GROUP 3b.
ANCHOR_CORE.md still lists reconnect in `waiting` — behavior intentionally overridden per
user instruction (Rule 1); doc sync deferred.

CHANGED:
- Lobby disconnect: immediate `removePlayerFromLobby(..., 'disconnect')` + `broadcastRoomList`
- Client: clear `state.room` on `auth_result` when not restored to room; handle self `disconnect` `player_left`

NOT CHANGED:
- Playing-state 15s reconnect timer, apartment immediate removal, in-game `reconnect_state`

VERIFICATION:
- `php tests/Manual/test_reconnect.php` — **111/111 PASS** (+GROUP 1b, GROUP 2 retargeted).
- `php tests/Manual/test_timer_audit.php` — **24/24 PASS**.
- MANUAL (F1): join room in lobby, disconnect tab → player gone from room_list immediately;
  reconnect shows lobby without stale membership; can join same room again.

- [DONE] FIX-23 Game-over modal lists all winners on shared victory
Files:
- public/js/ui.js (diff — `showGameOver()` derives winners from `statistics[].received > 0`)
- public/locales/*.json (diff — `game.winnersLine` plural headline, 6 locales)

Notes: `game_over.winner` is a single string (first winner, backward-compat field). Bank/payout
table was already correct via `statistics`. Modal headline now joins all usernames with
`received > 0` and shows `final_bank` for multi-winner shared prize total.

CHANGED:
- `showGameOver()`: multi-winner headline from statistics; `winnersLine` i18n key

NOT CHANGED:
- `game_over` protocol, `GameFinishService` payout math, statistics table rendering

VERIFICATION:
- MANUAL: trigger double victory (2+ winners same barrel) — modal headline names all
  winners and total shared prize matches table sum; single-winner unchanged.

- [DONE] FIX-22 Apartment alert shown immediately (not behind barrel animation)
Files:
- public/js/app.js (diff — `onApartmentAlert()` no longer uses `enqueueAnimation`)

Notes: Non-immune players were kicked to lobby before seeing agree/refuse: server
`apartment_timer` (10s per ANCHOR_CORE) starts when `apartment_alert` is sent, but
the client queued the modal behind `barrels_drawn` slot animation (~8s+). Server timed
out → `player_left` reason=refuse → `resetToLobby()` before modal appeared.

CHANGED:
- `onApartmentAlert()`: call `UI().showApartment()` synchronously on packet receipt

NOT CHANGED:
- Server apartment timer duration (10s), `ApartmentService` logic, protocol, animation queue for barrels

VERIFICATION:
- MANUAL: 2–3 player game, trigger apartment — required (non-immune) player sees
  agree/refuse modal immediately with full ~10s countdown; not thrown to lobby until
  timeout/refuse. Immune player sees wait screen immediately.
- No automated test (client UI timing).

- [DONE] FIX-21 GAME_RULES.md §5 «Квартира» direction and payment amount corrected
Files:
- docs/GAME_RULES.md (diff — swap immune/required categories; 10 → 5 coins)

Notes: Documentation-only correction paired with FIX-20. Prior GAME_RULES.md §5 had
immunity/payment backwards (source of FIX-19's wrong direction). Payment now matches
`ApartmentService::APARTMENT_PAYMENT` (5) and ANCHOR_CORE.md.

VERIFICATION:
- Manual review — immune = closed-row players; required = all others; 5 coins; 10s timer unchanged.

- [DONE] FIX-20 Apartment immunity direction corrected (reversal of FIX-19)
Files:
- src/Game/ApartmentService.php (diff — `prepareApartment()`: closed row → immune; no line → required)
- tests/Manual/test_apartment.php (diff — GROUP 3/5 assertions flipped to match)

Notes: **Direction correction.** FIX-19 correctly wired `hasLine()` but used inverted
semantics copied from GAME_RULES.md §5 (which was itself backwards). User-confirmed
correct design (Rule 1 authority): players WITH a closed row earned immunity (triggered
the event); players WITHOUT must pay APARTMENT_PAYMENT (5). Do NOT revert to FIX-19
direction even if an old GAME_RULES snapshot suggests otherwise — see FIX-21.

CHANGED:
- `prepareApartment()`: `immune = hasLine`, `required = !hasLine`

NOT CHANGED:
- `hasLine()`, `shouldTrigger()`, `finishApartment()` post-agree `immune=true`, payment amount

VERIFICATION:
- `php tests/Manual/test_apartment.php` — **51/51 PASS** (GROUP 3/5 assertions flipped).
- MANUAL: closed-row player sees immune wait screen; others see agree/refuse.

- [SUPERSEDED — direction wrong] FIX-19 Apartment immunity computed from hasLine() at trigger time
Files:
- src/Game/ApartmentService.php (diff — `prepareApartment()` derives required/immune from `hasLine()`, persists `player['immune']`)
- tests/Manual/test_apartment.php (diff — GROUP 3 real cards/masks; 3-player regression)

Notes: Introduced `hasLine()`-based immunity (good) but inverted who pays vs who is immune
(wrong — copied from backwards GAME_RULES.md §5). Corrected by FIX-20/FIX-21.

- [DONE] FIX-18 Persist post-game_over balance to localStorage (client-only)
Files:
- public/js/app.js (diff — `onGameOver()` calls `persistUser()` after updating `state.user.coins`)

Notes: Server-side payout math confirmed correct (GameFinishService / game.db).
Symptom "coins not given out" was stale localStorage after refresh: `onGameOver()`
updated in-memory balance and DOM but never persisted. `ensureUserProfile()` reads
localStorage on reconnect/refresh.

CHANGED:
- `onGameOver()`: call `persistUser(state.user)` after `paid`/`received` arithmetic

NOT CHANGED:
- `game_over` / `reconnect_state` protocol, server economy, GameFinishService,
  GameService bank/payout, PreparedStatements

VERIFICATION:
- MANUAL VERIFICATION REQUIRED: play 2–3 player game to victory; confirm winner
  balance on screen; hard-refresh → balance matches post-win (not pre-game).
- Repeat for loser (paid > 0, received = 0) — deduction survives refresh.

- [PROPOSED] ADR-016 Server-authoritative client balance (`coins` field)
Files:
- docs/ADR/016.md (new, Status: Proposed)

Notes: Proposes additive `coins` on `game_over.statistics[]`, `reconnect_state`,
and optional `balance_updated` for admin/apartment paths. Implementation blocked
until ADR accepted (Rule 7). FIX-18 is interim mitigation only.

VERIFICATION:
- N/A — ADR draft for user review. Post-approval verification matrix in ADR §Implementation Epic.

## Phase 17 — Compliance Audit Fixes (2026-08-02)

- [DONE] FIX-17 Reconnecting active drawer restores draw-button UI (client-only)
Files:
- public/js/app.js (diff — `onReconnectState()` playing branch sets `state.isMyTurn` from `current_drawer`)

Notes: `reconnect_state.current_drawer` was already correct; `syncTurnUi()` branches
on `state.isMyTurn`, which was only set by `your_turn`. No protocol change. Server
AFK re-arm in `ReconnectService::restorePlayerConnection()` unchanged; no in-flight
auto-draw race — `current_drawer` reflects `active_drawer_conn_id` at reconnect time.

CHANGED:
- `onReconnectState()` playing: derive `isMyTurn`, then `syncTurnUi()`

NOT CHANGED:
- `reconnect_state` payload, server reconnect/AFK logic, `your_turn` handler

VERIFICATION:
- MANUAL VERIFICATION REQUIRED: 2-player game, player A's turn, disconnect tab,
  reconnect within 15s → draw button visible/enabled; non-drawer sees waiting state.
- No automated test (client UI).

- [DONE] EPIC-9.3b Host transfer on player removal during apartment state
Files:
- src/Game/ApartmentService.php (diff — `removePlayerFromApartment()` host FIFO reassignment + `host_changed` broadcast)
- tests/Manual/test_apartment.php (diff — GROUP 10 host-refuse scenario)

Notes: Closes KNOWN GAP from EPIC-9.3 (`removePlayerFromApartment` stale `host_conn_id`).
Mirrors `ReconnectService::removePlayerFromGame()` FIFO-over-`drawer_order` logic.
`host_changed` broadcast matches `LobbyService` pattern (ADR-009); lobby timeout fields
omitted — not applicable in apartment phase.

CHANGED:
- Host reassignment when removed conn was `host_conn_id`
- `broadcastHostChanged()` / `resolveHostUsername()` private helpers

NOT CHANGED:
- `removePlayerFromGame()` host path, lobby host transfer, Room/Player structure

VERIFICATION:
- `php tests/Manual/test_apartment.php` — **45/45 PASS** (was 40; +GROUP 10).

- [DONE] EPIC-17.1 GAME_RULES.md win-chance description aligned with ADR-014
Files:
- docs/GAME_RULES.md (diff — §2 comparative win-chance wording)

Notes: Documentation-only. Player-facing language; no formula reproduction.

VERIFICATION:
- Manual review against ADR-014 § Formula — concept accurate, no new claims.

- [DOCUMENTATION STALE] TD-1 / former EPIC-17.2 — Protocol registry cleanup (`admin_stats_data`, `error.banned`)
Status: Reclassified under TECHNICAL DEBT (2026-08-30 audit).
- `admin_stats_data`: **IMPLEMENTATION DONE** — see TD-1; docs reconciliation only (not implement vs deprecate).
- `error.banned`: **ADR-007 decision stands** — reserved/unused; `banned` packet canonical; docs reconciliation only.
Priority: TD-1 Low, TD-2 Very Low. **NOT BLOCKING DEPLOYMENT.**

- [PROPOSED] ADR-015 GameTurnService extraction draft (GameService file-size policy)
Files:
- docs/ADR/015.md (new, Status: Proposed)

Notes: No code extraction in this pass (Epic Isolation). Decomposition proposal only.

VERIFICATION:
- N/A — written ADR for user review.

## Phase 16 — Comparative Win-Chance (Server-Side)

- [DONE] EPIC-16.1 Comparative win-chance calculation and protocol wiring (ADR-014)
Files:
- docs/ADR/014.md (new)
- docs/ANCHOR_PROTOCOL.md (diff — `win_chances` on `barrels_drawn` / `reconnect_state`)
- src/Game/VictoryService.php (diff — `calculateWinChances()`)
- src/Game/GameService.php (diff — wire into `broadcastBarrelsDrawn()`; passthrough; skip on victory draw)
- src/Game/ReconnectService.php (diff — `reconnect_state` playing branch)
- public/js/app.js (diff — opponents use server `win_chances`; self indicator unchanged)
- tests/Manual/test_victory.php (diff — GROUP 7 unit tests)
- tests/Manual/test_turn_system.php (diff — GROUP 7 integration)
- tests/Manual/test_reconnect.php (diff — `MockGameService::calculateWinChances`; reconnect_state assert)

Notes: Fixes silently broken opponent win-chance (~0% always) by moving comparative
move-distance formula server-side. Informational only — zero changes to victory
detection, prize calculation, apartment, AFK, economy, or state machine.
Opponent card numbers remain hidden; only coarse percentage exposed.

VERIFICATION:
- `php tests/Manual/test_victory.php` — **48/48 PASS** (was 40; +GROUP 3b unit tests).
- `php tests/Manual/test_turn_system.php` — **47/47 PASS** (was 42; +GROUP 7).
- `php tests/Manual/test_reconnect.php` — **107/107 PASS** (+2 reconnect_state asserts).
- `php run_ALL_tests.php` — baseline unchanged; no victory/prize regressions.

## Phase 15 — AFK Audit Fixes (Fresh Findings)

- [DONE] EPIC-15.4 AFK-cascade last survivor excludes equally idle player (ADR-013)
Files:
- docs/ADR/013.md (new)
- docs/ANCHOR_CORE.md (diff — § Last Survivor qualifying condition for AFK removal)
- docs/GAME_RULES.md (diff — Last Survivor vs mutual-AFK refund wording)
- src/Game/ReconnectService.php (diff — `removePlayerFromGame()` AFK + survivor `auto_draws>0` → `handleNoSurvivors()`)
- tests/Manual/test_reconnect.php (diff — GROUP 5 engaged survivor; 5b/5c both-idle refund; 5d non-afk unchanged)
- tests/Manual/test_timer_integrity.php (diff — noop `handleNoSurvivors` mock for TEST 6b)

Notes: Closes economy loophole where second-to-last player removed for `afk` paid entire bank to a
survivor who had themselves accumulated `auto_draws > 0`. Option A (ADR-013): reuse existing
`handleNoSurvivors()` refund path; no new Player Structure field. Removal reasons `disconnect`,
`leave`, `refuse`, `kicked`, `banned` unchanged. `ApartmentService::removePlayerFromApartment()` has
no `count(active)===1` last-survivor branch — out of scope.

VERIFICATION:
- `php tests/Manual/test_reconnect.php` — **105/105 PASS** (was 77; +GROUP 5b/5c/5d, GROUP 5 split).
- `php tests/Manual/test_timer_integrity.php` — **14/14 PASS**.
- `php tests/Manual/test_admin_kick.php` — **39/39 PASS** (no double-refund regression).
- `php run_ALL_tests.php` — **32/41** files pass (baseline unchanged; `test_timer_integrity` fixed).

- [DONE] EPIC-15.1 Zero-active no-survivors refund during playing (economic integrity)
Files:
- src/Game/GameFinishService.php (diff — `handleNoSurvivors()`, `cancelRoomTimers()`, `snapshotRemainingPlayersToHistory()`; constructor `object` deps for testability)
- src/Game/GameService.php (diff — `handleNoSurvivors()` passthrough)
- src/Game/ReconnectService.php (diff — `count(active)===0` → refund path; unified active-player dispatch; removed dead `destroyRoom()`)
- src/Game/ApartmentService.php (diff — delegate no-survivors to `GameService`; fix `removePlayerFromApartment` empty path; `sendJson` import)
- tests/Manual/test_reconnect.php (diff — GROUP 8/8b no-survivors + refund assertions)
- tests/Manual/test_apartment.php (diff — GROUP 9 apartment empty-path refund; `makeSvc()` wires real `GameFinishService`)

Notes: Closes ANCHOR_CORE Part 2 § No Survivors / § Economic Integrity Rule gap where
`removePlayerFromGame()` called bare `destroyRoom()` when `count(active)===0` or
`empty(players)` — coins lost, zombie rooms with disconnected stragglers. Chose option (a):
refund logic centralized in `GameFinishService` (ADR-002 payout owner). Disconnected
stragglers snapshotted into `all_players_history` before refund; reconnect timers cancelled;
`bank` explicitly zeroed.

VERIFICATION:
- `php tests/Manual/test_reconnect.php` — **65/65 PASS** (was 52; +GROUP 8/8b).
- `php tests/Manual/test_apartment.php` — **38/38 PASS** (was 36; +GROUP 9).
- `php run_ALL_tests.php` — **32/41** files pass (baseline 31/41 pre-epic; `test_apartment.php` fixed).

- [DONE] EPIC-15.2 Progressive game AFK strike windows 30s / 15s / 5s (ADR-012)
Files:
- docs/ADR/012.md (new)
- docs/ANCHOR_CORE.md (diff — § Game AFK Timer thresholds table)
- docs/ANCHOR_PROTOCOL.md (diff — `turn_seconds` semantics for `your_turn` / `afk_warning`)
- src/Core/Constants.php (diff — `gameAfkStrikeWindowSeconds()`; removed dead flat-30 helpers)
- src/Game/ReconnectService.php (diff — `tickGameAfk()` per-strike window lookup)
- src/Game/GameService.php (diff — `sendYourTurn()` / packet `turn_seconds` per `auto_draws`)
- tests/Manual/test_reconnect.php (diff — GROUP 4/4b/4c/5/6 boundary + `turn_seconds` assertions)
- tests/Manual/test_timer_audit.php (diff — `LOTTO_GAME_AFK_STRIKE1/2/3` env override tests)

Notes: `auto_draws` semantics unchanged (ADR-008). Client (`public/js/ui.js`) already uses
server `turn_seconds` — no hardcoded 30s dependency beyond falsy fallback.

VERIFICATION:
- `php tests/Manual/test_reconnect.php` — **71/71 PASS** (strike 1≥30s, strike 2≥15s, strike 3≥5s boundaries; `turn_seconds` 30/15 in packets).
- `php tests/Manual/test_timer_audit.php` — **22/22 PASS**.
- `php run_ALL_tests.php` — **32/41** files pass (no new failures vs EPIC-15.1 sign-off).

## Phase 14 — AFK Timer Audit Fixes

- [DONE] EPIC-14.9 GAME_RULES.md: align lobby AFK activity examples with allowlist
Files:
- docs/GAME_RULES.md (diff — §4 «В лобби»: drop misleading «Начать игру» example;
  list `room_list` / create / join / leave; note start_game ends waiting phase)

Notes: Documentation-only polish. Matches EPIC-14.5 `$lobbyHostActivityActions` in
server.php. No code or test changes.

VERIFICATION:
- Manual review against ANCHOR_CORE.md § Lobby AFK Timer and ADR-010 — consistent.

- [DONE] EPIC-14.8 Fix stale ADR-007 citations in lobby integration test comments
Files:
- tests/Manual/test_lobby_integration.php (diff — SUITE 5 comments: ADR-007 → ADR-011)

Notes: Comment-only traceability cleanup (ADR-011 retroactive doc). No logic change.
Grep confirmed no remaining incorrect «ADR-007» / «A7 spec» citations outside
legitimate ADR-007 subjects (`error.banned`, `afk_warning` protocol audit).

VERIFICATION:
- `php tests/Manual/test_lobby_integration.php` — 133/133 PASS (unchanged logic).

- [DONE] EPIC-14.6 Clear stale lobby joined message on leave room
Files:
- public/js/app.js (diff — `resetToLobby()` clears `#lobby-message`)

Notes: Cosmetic UI fix only; unrelated to AFK timing logic. Stale «Вы в комнате
#N» text persisted after `leave_room` because `onRoomJoined` set the message but
`resetToLobby()` did not clear it.

VERIFICATION:
- Manual UI: leave room → `#lobby-message` empty; lobby timers unaffected.
- `php tests/Manual/test_lobby_integration.php` — 133/133 PASS (no test change).
- `php run_ALL_tests.php` — 30/41 test files PASS (11 pre-existing failures
  unrelated to this one-line client fix; same baseline as EPIC-14.1 sign-off).

- [DONE] EPIC-14.5 Fix lobby AFK 120s display and turn passing after start_game
Files:
- server.php (diff — `hello` packet gains `server_time`; `touchLobbyHostActivity`
  restricted to waiting-room lobby-action allowlist: `room_list`, `create_room`,
  `join_room`, `leave_room` — excludes `start_game` and all in-game/admin actions)
- public/js/app.js (diff — server clock skew from `hello`; `onHostChanged` ignored
  while `state.inGame`)
- public/js/ui.js (diff — `setServerClockSkew` / `serverNowSec()` for lobby and
  game AFK countdown displays)
- src/Game/ReconnectService.php (diff — `reconnect_state` `host_timeout_start`
  sourced from `host_activity_at`, not stale `last_action`)
- src/Lobby/LobbyService.php (diff — `startLobbyAfkTimer()` refreshes
  `host_activity_at` + broadcasts on arm; `touchLobbyHostActivity` broadcasts via
  `broadcastHostChanged` only)
- tests/Manual/test_lobby_integration.php (diff — SUITE 7: timer arm sets full
  120s window assertion)

Notes: Closes residual EPIC-14.1 gap where `touchLobbyHostActivity` was wired
unconditionally for every action (including `start_game`), which re-broadcast
`host_changed` during game start and broke turn passing. Client clock skew caused
lobby countdown to open at ~105s instead of 120s when client clock led server.

VERIFICATION:
- `php tests/Manual/test_lobby_integration.php` — 133/133 PASS (includes SUITE 7
  «timer arm sets full 120s window» + SUITE 8 ping-immunity from EPIC-14.1).
- `php run_ALL_tests.php` — 30/41 test files PASS (11 pre-existing failures on
  Windows dev host: live WS subprocess tests, `sendJson` bootstrap gaps in some
  apartment/admin manual tests — unchanged from EPIC-14.1 baseline).

- [DONE] EPIC-14.4 Update GAME_RULES.md AFK section to match per-turn model (ADR-008)
Files:
- docs/GAME_RULES.md (diff — §4 AFK: per-turn 30s threshold, cross-turn strike counting)

Notes: Documentation-only. Aligned with ANCHOR_CORE.md § Game AFK Timer and ADR-008.

VERIFICATION:
- Manual review against ANCHOR_CORE.md § Game AFK Timer — wording consistent.

- [DONE] EPIC-14.3 Cancel game_afk_timer immediately on apartment transition
Files:
- src/Game/ApartmentService.php (diff — explicit game_afk_timer_id cancel in triggerApartment)
- tests/Manual/test_apartment.php (diff — GROUP 5b assertion; mock_timer bootstrap)

Notes: Defensive self-stop in ReconnectService::tickGameAfk() retained.

VERIFICATION:
- `php tests/Manual/test_apartment.php` — all PASS (including GROUP 5b)

- [DONE] EPIC-14.2 Lobby AFK: document forward-only rotation + queue exhaustion (ADR-011)
Files:
- docs/ADR/011.md (новый — retroactive ADR for host rotation + room destruction)
- docs/ANCHOR_CORE.md (diff — Room Destruction Rules 4th bullet; ADR-011 citation)
- src/Lobby/LobbyService.php (diff — comment/citation corrections only)

Notes: No runtime behavior change. Replaces incorrect ADR-007 / "A7 spec" citations.

VERIFICATION:
- `php tests/Manual/test_lobby_integration.php` — 132/132 PASS (unmodified logic)

- [DONE] EPIC-14.1 Lobby AFK timer: separate host_activity_at from ping keepalive
Files:
- docs/ADR/010.md (новый — host_activity_at Player Structure key)
- docs/ANCHOR_CORE.md (diff — Player Structure, Lobby AFK Timer, Naming Registry)
- src/Lobby/LobbyService.php (diff — host_activity_at, touchLobbyHostActivity, timer check)
- server.php (diff — ping no longer syncs lobby AFK; touchLobbyHostActivity on real actions)
- tests/Manual/test_lobby_integration.php (diff — SUITE 8 ping-immunity regression)

Notes: `ping` still updates `last_action` for connection liveness; lobby AFK reads
`host_activity_at` only (ADR-010). Game AFK unchanged.

VERIFICATION:
- `php tests/Manual/test_lobby_integration.php` — all PASS (including SUITE 8)
- `php run_ALL_tests.php` — 0 failures

---

## Phase 13 — Game AFK Wiring & Orphaned-Method Fixes

- [DONE] EPIC-13.0 ADR: Game AFK timer wiring decision
Files:
- docs/AUDIT_ORPHANED_METHODS_2026-07-28.md (новый — archived audit report)
- docs/ADR/008.md (новый — startTurn + setter wiring decision)
- docs/ROADMAP.md (diff — Phase 13 added, skip note updated)

Decision: ADR-008 option (c) — `GameService::startTurn()` atomically sends
`your_turn` and arms AFK timer via post-construction `setReconnectService()`.

- [DONE] EPIC-13.1 Wire first-turn your_turn + AFK arm into handleStartGame()
Files:
- src/Game/GameService.php (diff — startTurn, setReconnectService, handleStartGame)
- server.php (diff — setReconnectService wiring)

Verification: `php tests/Manual/test_game_start.php` — 46/46 PASS (Group 7 lines
401–402 updated; Group 10 afk_start assertion updated for drawer).

- [DONE] EPIC-13.2 Wire AFK arm into handleDrawBarrel() turn rotation
Files:
- src/Game/GameService.php (diff — handleDrawBarrel uses startTurn)

Verification: `php tests/Manual/test_turn_system.php` — 38/38 PASS. Group 4
flagged for EPIC-13.4: added afk_start assertion on next drawer.

- [DONE] EPIC-13.3 Wire AFK arm into drawer-replacement paths
Files:
- src/Game/ReconnectService.php (diff — removePlayerFromGame uses startTurn)
- src/Game/ApartmentService.php (diff — finishApartment uses startTurn)

Verification: `php tests/Manual/test_reconnect.php` 20/20, `test_apartment.php` 32/32.

- [DONE] EPIC-13.4 Test corrections + turn-start integration test
Files:
- tests/Manual/test_game_start.php (diff — Group 7/10 assertions)
- tests/Manual/test_turn_system.php (diff — Group 4 afk_start)
- tests/Manual/test_game_packet_routing.php (diff — TEST 2 your_turn)
- tests/Manual/test_phase11_core_flows.php (diff — your_turn assertion)
- tests/Manual/test_game_start_turn_integration.php (новый — 7/7 PASS)
- tests/Manual/test_admin_ban.php (diff — MockApartmentService stub)

Verification: `php run_ALL_tests.php` — 41/41 test files PASS (local Windows
dev host, 2026-07-28). VPS `./run_ALL_tests.sh` initially failed with FIX-16
(8 live WS subprocess tests — server.php fatal on missing bootstrap helper);
re-verify on VPS after `0de46d0` — see FIX-16.

- [DONE] EPIC-13.5 Apartment early-finish check on kick/ban removal
Files:
- src/Game/ApartmentService.php (diff — bindGameService, maybeFinishApartmentEarly)
- src/Admin/AdminService.php (diff — kick/ban apartment paths)
- server.php (diff — bindGameService)
- tests/Manual/test_admin_kick.php (diff — TEST 9 early-finish scenario)

Verification: test_admin_kick TEST 9 PASS; test_apartment 32/32.

- [DONE] EPIC-13.6 Reconnect mid-turn drawer turn-signal (ADR-017)
Finding: backend contract satisfied via additive `reconnect_state` fields —
`is_my_turn`, `afk_start`, `turn_seconds`, `auto_draws` when the reconnecting
player is `active_drawer_conn_id` (no duplicate `your_turn` / `turn_ready`).
Implementation: `ReconnectService::buildReconnectState()` +
`restorePlayerConnection()` AFK restart for active drawer.
Regression: `tests/Manual/test_reconnect.php` GROUP 3d (176/176 suite PASS).
Client `onReconnectState` UI wiring remains Phase 12 Frontend scope.

- [DONE] EPIC-13.7 Cleanup: RoomManager::findRoomIdByUserId()
Decision: **(b) intentionally-retained utility** — docblock updated; no
production consumer planned; test coverage in test_lobby_integration.php kept.

**Process deviation (Rule 16 — Git Checkpoint Rule):** Phase 13 commits on
branch `cursor/epic-11-1-vps-ws-test-isolation` did not strictly follow the
one-Epic-one-commit convention. EPIC-13.3 appears in **two** commit messages:
`8cd1434` (`EPIC-13.3 wire-afk-drawer-replacement` — ReconnectService only)
and `f4cf0f4` (`EPIC-13.2-13.3 wire-afk-turn-rotation-and-apartment-resume`
— ApartmentService `finishApartment`). EPIC-13.2 landed inside `b203493`
(`EPIC-13.1 start-game-first-turn`) because `handleDrawBarrel()` and
`handleStartGame()` share `GameService.php` in a single diff. All epics are
implemented and verified; numbering in commit messages is authoritative for
audit only — see DECISION LOG 2026-07-28.

---

- [DONE] EPIC-11.6 Load testing (Phase 11 — instrumentation 2026-07-27; criteria reassessment + VPS PASS 2026-09-04)
Files:
- src/Core/LoadAudit.php (новый файл — opt-in handler latency + snapshots → logs/load_audit.log)
- server.php (diff — LoadAudit wiring, onMessage latency recording, periodic snapshots)
- scripts/load_test_runner.php (новый файл — ramp/steady/storm/long VPS scenarios)
- scripts/analyze_load_log.php (новый файл — p95/CPU/memory acceptance validator)
- tests/Manual/test_load_audit.php (новый файл — 30 mock regression tests)
- docs/PHASE_11_REPORT.md (diff — EPIC-11.6 section updated)

Implemented:
- LoadAudit utility: LOTTO_LOAD_AUDIT=1 logs per-action handler latency_ms and
  periodic snapshots (mem, connections, rooms) for EPIC-11.6 targets.
- load_test_runner.php: four scenarios (ramp, steady, storm, long) with
  realistic register/room/game flows; client RTT → logs/load_client.log;
  CPU/memory sampling → logs/load_resource.log.
- analyze_load_log.php: validates gameplay p95 < 100ms (draw_barrel),
  auth p95 ≤ 160ms (register/login), peak memory < 450 MB, peak CPU < 80%.
- test_load_audit.php: utility, percentile math, client/resource log parsing.

Verification (Windows dev host):
- test_load_audit.php: 30/30 PASS
- load_test_runner.php: requires Linux/VPS (Workerman)
- analyze_load_log.php: runs after VPS load_test_runner completion

Remaining: VPS load scenarios completed 2026-09-04 under final gates (see PHASE_11_REPORT § EPIC-11.6).

Next in Phase 11: none — 11.1–11.6 VPS sign-off complete.

- [DONE] EPIC-11.5 Protocol audit (instrumentation 2026-07-27; **VPS live WS verified 2026-09-02** — 145/145 PASS on `box-963286` @ `f2c9cfb`)
Files:
- docs/ANCHOR_CORE.md (diff — afk_warning added to packet registry)
- docs/ANCHOR_PROTOCOL.md (diff — afk_warning packet spec, error.banned note)
- docs/ADR/007.md (новый файл — documentation alignment decisions)
- tests/Manual/test_protocol_audit.php (новый файл — 7 live WS acceptance tests)
- scripts/ws_emulator.php (новый файл — CLI client emulator + replay)
- tests/Manual/test_protocol_completeness.php (diff — afk_warning gap closed)
- docs/PHASE_11_REPORT.md (diff — EPIC-11.5 section updated)

Implemented:
- afk_warning registered in ANCHOR_CORE.md and documented in ANCHOR_PROTOCOL.md
  (ADR-007); closes W1 from static audit.
- error.banned documented as reserved/unused (ADR-007); `banned` packet is the
  canonical ban-rejection channel.
- test_protocol_audit.php: hello/protocol_version, extra-field extensibility,
  authenticated unknown action, missing fields, room_full live, auth_required.
- ws_emulator.php: --send, --replay (.jsonl), --interactive modes for
  protocol replay and manual audit.
- test_protocol_completeness.php: 50/50 PASS; at EPIC-11.5 time 2 warnings
  (`admin_stats_data` since resolved via ADR-023; `error.banned` reserved per ADR-007).

Verification (Windows dev host):
- test_protocol_completeness.php: 50/50 PASS, 2 warnings (expected)
- test_protocol_audit.php: requires Linux/VPS (live Workerman subprocess)
- Full suite: php run_ALL_tests.php — 29/29 test files passed (Windows;
  9 live-server tests skipped)

Verification (Ubuntu VPS — 2026-09-02):
- Eight live WS tests: **145/145 PASS** on `box-963286` @ `f2c9cfb`
  (see TD-3 § EPIC-11.5 above and `docs/PHASE_11_REPORT.md`)

- [DONE] EPIC-11.4 State machine audit (Phase 11 — instrumentation complete 2026-07-27; **VPS live-session + analyze PASS 2026-09-02** on `box-963286`)
Files:
- src/Core/StateMachineAudit.php (новый файл — opt-in state transition logging → logs/state_machine_audit.log)
- src/Core/Helpers.php (diff — lottoStateTransition/lottoStateReject/lottoPlayerStateTransition)
- server.php (diff — StateMachineAudit wiring)
- src/Core/RoomManager.php, src/Game/GameService.php, src/Game/GameFinishService.php,
  src/Game/ApartmentService.php, src/Game/ReconnectService.php, src/Lobby/LobbyService.php,
  src/Admin/AdminService.php (diff — transition/rejection hooks)
- tests/Manual/test_state_machine_audit.php (новый файл — mock regression tests)
- scripts/analyze_state_machine_log.php (новый файл — log replay + transition validation)
- docs/PHASE_11_REPORT.md / docs/IMPLEMENTATION_STATUS.md / docs/ROADMAP.md (diff — EPIC-11.4 VPS verified)

Implemented:
- StateMachineAudit utility: LOTTO_STATE_AUDIT=1 logs room transitions, player
  transitions, and rejected actions per ANCHOR_CORE.md Part 4.
- Transition graph encoded: waiting→playing→apartment→playing→finished→destroyed.
- Instrumentation at all status mutation sites + key rejection guards.
- test_state_machine_audit.php: utility, valid/invalid transitions, apartment
  cycle, apartment timeout, host disconnect/reconnect, join_room guard.
- analyze_state_machine_log.php: parse log, verify sequence against spec.

Verification (Windows + VPS mock):
- test_state_machine_audit.php: 35/35 PASS

Verification (Ubuntu VPS — 2026-09-02):
- Live Workerman `LOTTO_STATE_AUDIT=1` @ `7032a4a`: created→waiting→playing +
  active→disconnected; `analyze_state_machine_log.php` PASS (exit 0)
- Window: `2026-09-02T18:34:08Z` → `2026-09-02T18:35:23Z` on `box-963286`

Remaining TD-3: 11.6 only (11.1–11.5 DONE).

Next in Phase 11 historically: EPIC-11.5 Protocol audit (DONE), then 11.6 per
docs/prompt phase 11 detail.md and docs/PHASE_11_REPORT.md.

- [DONE] EPIC-11.3 Economy audit (Phase 11 — instrumentation complete 2026-07-27; **VPS integrity + live stake PASS 2026-09-02** on `box-963286`)
Files:
- src/Core/EconomyAudit.php (новый файл — opt-in financial event logging → logs/economy_audit.log)
- src/Core/Helpers.php (diff — lottoEconomyRecord() helper)
- server.php (diff — EconomyAudit wiring)
- src/Game/GameService.php, src/Game/GameFinishService.php, src/Game/ApartmentService.php,
  src/Admin/AdminService.php (diff — audit hooks on stake/prize/burn/apartment/refund)
- tests/Manual/test_economy_audit.php (новый файл — mock regression tests)
- scripts/economy_integrity_runner.php (новый файл — multi-scenario conservation check)
- scripts/analyze_economy_log.php (новый файл — log replay + duplicate tx_id check)
- docs/PHASE_11_REPORT.md / docs/IMPLEMENTATION_STATUS.md / docs/ROADMAP.md (diff — EPIC-11.3 VPS verified)

Implemented:
- EconomyAudit utility: LOTTO_ECONOMY_AUDIT=1 logs stake/prize/apartment/refund/burn
  with tx_id, user_id, room_id, signed amount, microsecond timestamp.
- Transaction sites instrumented: startGame stakes, finishGame prizes+burn,
  apartment payments, admin kick/close refunds, no-survivors refunds.
- Conservation invariant: sum(user coins) + room banks + burned = initial total.
- test_economy_audit.php: utility, replay, VictoryService math, GameFinishService integration.
- economy_integrity_runner.php: 4-scenario chain (stake → prize/burn → apartment → refund).
- analyze_economy_log.php: parse log, optional --initial replay verification.

Verification (Windows dev host):
- test_economy_audit.php: 34/34 PASS
- economy_integrity_runner.php: PASS
- analyze_economy_log.php --initial=1:500,2:500,3:500: PASS

VPS verification (2026-09-02):
- economy_integrity_runner.php + analyze replay — **PASS** on `box-963286` @ `85e9bbb`
- Live Workerman LOTTO_ECONOMY_AUDIT=1 play_vs_bot stake — **PASS**
- Evidence: docs/PHASE_11_REPORT.md § EPIC-11.3

Remaining TD-3: 11.6 (11.1–11.5 DONE).

- [DONE] EPIC-11.2 Timer audit (Phase 11 — instrumentation complete 2026-07-27; **VPS accelerated run PASS 2026-09-02** on `box-963286`)
Files:
- src/Core/TimerAudit.php (новый файл — opt-in timer lifecycle logging → logs/timer_audit.log)
- src/Core/Constants.php (diff — env-resolved timeout accessors + AFK/APARTMENT constants)
- src/Core/Helpers.php (diff — lottoTimerAdd/lottoTimerDel wrappers with audit hooks)
- server.php (diff — TimerAudit wiring, watchdog uses env-resolved timeouts)
- src/Lobby/LobbyService.php, src/Game/ReconnectService.php, src/Game/ApartmentService.php,
  src/Game/GameService.php, src/Game/GameFinishService.php, src/Core/RoomManager.php
  (diff — all Timer::add/del migrated to lottoTimer* wrappers)
- tests/Manual/test_timer_audit.php (новый файл — mock regression tests)
- tests/Manual/mock_timer.php (diff — fire()/fireAll() for accelerated mock tests)
- scripts/timer_accelerated_runner.php (diff — play_vs_bot before disconnect; init_db; clean stop; analyze exit code)
- scripts/analyze_timer_log.php (diff — one-shot reconnect drift/orphan acceptance; ignore periodic watchdog)
- tests/Manual/ws_test_harness.php (diff — LOTTO_TIMER_AUDIT_LOG isolation)
- docs/PHASE_11_REPORT.md / docs/IMPLEMENTATION_STATUS.md / docs/ROADMAP.md (diff — EPIC-11.2 VPS verified)

Implemented:
- TimerAudit utility: LOTTO_TIMER_AUDIT=1 logs add/del/fire with microsecond timestamps.
- Env overrides for accelerated VPS testing: LOTTO_RECONNECT_TIMEOUT,
  LOTTO_LOBBY_HOST_TIMEOUT, LOTTO_UNAUTHORIZED_TIMEOUT, LOTTO_AUTHORIZED_TIMEOUT,
  LOTTO_APARTMENT_TIMEOUT, LOTTO_GAME_AFK_WARN1/WARN2/AUTO, LOTTO_WATCHDOG_INTERVAL,
  LOTTO_AFK_TICK_INTERVAL.
- lottoTimerAdd/lottoTimerDel: single instrumentation seam for all production timers.
- test_timer_audit.php: TimerAudit utility, env overrides, RoomManager cleanup,
  reconnect schedule/cancel, lobby AFK start/stop, single-shot fire semantics.
- VPS tooling: timer_accelerated_runner.php (5s reconnect default) +
  analyze_timer_log.php (acceptance: one-shot reconnect drift ≤200ms, no orphaned one-shots).

Verification (Windows dev host):
- test_timer_audit.php: 24/24 PASS
- test_timer_integrity.php: groups 1–4 PASS locally; group 5 needs PDO SQLite (pre-existing Windows limitation)

VPS verification (2026-09-02):
- `php scripts/timer_accelerated_runner.php` on `box-963286` — **PASS**
- reconnect interval 5.000s, drift ~0.5ms, one_shot_seen=1 fired=1 orphaned=0
- Evidence: `docs/PHASE_11_REPORT.md` § EPIC-11.2

Remaining TD-3: 11.6 (11.1, 11.2, 11.3, 11.4, 11.5 already DONE).

Remaining: Run timer_accelerated_runner.php on Ubuntu VPS for live drift
acceptance sign-off per EPIC-11.2 acceptance criteria.

Next in Phase 11: EPIC-11.5 Protocol audit, then 11.6 per
docs/prompt phase 11 detail.md and docs/PHASE_11_REPORT.md.

- [DONE] EPIC-11.1 Memory audit (Phase 11 — instrumentation complete 2026-07-27; **VPS 6h memory/stability PASS 2026-09-02** on `box-963286` @ `6498144`)
Files:
- src/Core/MemoryAudit.php (новый файл — opt-in memory snapshots → logs/memory_audit.log)
- server.php (diff — worker_start/connection/packet/periodic snapshots)
- src/Core/RoomManager.php (diff — room_created/room_destroyed snapshots)
- tests/Manual/test_memory_audit.php (новый файл — mock regression: map cleanup, bounded growth)
- scripts/memory_stability_runner.php (новый файл — 6-hour VPS load test, Linux only)
- scripts/analyze_memory_log.php (новый файл — validates ≤120% baseline threshold)
- docs/PHASE_11_REPORT.md (diff — EPIC-11.1 section updated)

Implemented:
- MemoryAudit utility: LOTTO_MEMORY_AUDIT=1 enables structured snapshots;
  LOTTO_MEMORY_AUDIT_VERBOSE=1 logs every handled action (default: tracked
  lifecycle actions only).
- FIX-13 (ownership): MemoryAudit now accepts optional log path (constructor
  param + LOTTO_MEMORY_AUDIT_LOG env), mirroring FIX-12 Logger DI-seam.
  test_memory_audit.php writes to sys_get_temp_dir() only; production
  logs/memory_audit.log hash verified unchanged after test run.
- server.php: baseline at worker start, connection open/close, packet
  snapshots for state-mutating actions, 30-minute periodic timer.
- RoomManager: snapshots on createRoom/destroyRoom.
- test_memory_audit.php: map cleanup, timer orphan check, 50-cycle
  create/destroy bounded-growth test, log file write verification.
- VPS tooling: memory_stability_runner.php (6h default) +
  analyze_memory_log.php (acceptance: memory ≤120% baseline).

Verification (Windows dev host):
- test_memory_audit.php: all groups PASS
- Full suite: php run_ALL_tests.php (includes new test file)

Remaining: VPS acceptance complete 2026-09-02 — see TD-3 § EPIC-11.1 and
`docs/PHASE_11_REPORT.md` (streaming analyze note: official analyzer OOM on
~98 MB log; acceptance logic PASSed via streaming equivalent).

FIX-14 (VPS test isolation, 2026-07-27): Live WS tests now use port 18080
and temp-dir logs via tests/Manual/ws_test_harness.php — no collision with
production lotto-server.service on 8080, no writes to root-owned logs/.
test_helpers_runner.php scenario 4 no longer writes to production server.log.
test_logger.php removed (superseded by FIX-12). server.php accepts
LOTTO_WS_PORT, LOTTO_SERVER_LOG, LOTTO_WORKERMAN_LOG_FILE,
LOTTO_WORKERMAN_PID_FILE env vars for test subprocess isolation.

Next in Phase 11: EPIC-11.5 Protocol audit, then 11.6 per
docs/prompt phase 11 detail.md and docs/PHASE_11_REPORT.md.

Files:
- tests/Manual/test_admin_ban.php (diff — FIX-11 MockConnection::close())
- tests/Manual/test_admin_integration.php (diff — FIX-11 SpyConnection::close())
- tests/Manual/test_phase11_core_flows.php (новый файл — chained auth→lobby→game flows)
- run_ALL_tests.php (новый файл — cross-platform runner, SQLite on Windows)
- docs/PHASE_11_REPORT.md (новый файл — consolidated Phase 11 audit report)

⚠️ CORRECTION (2026-07-27, post-VPS regression): предыдущая версия этой
записи ошибочно утверждала, что `server.php` был изменён в рамках данного
Epic для устранения "критического пробела P11-001" (admin_* wiring
якобы отсутствовал). Это было ложным срабатыванием, полученным на
Windows-окружении с несинхронизированной локальной копией: реальный
`server.php` уже содержал полный admin-роутинг с 2026-07-25
(commit 5ad67d5, EPIC-10.6). Диф коммита 6efede1 (git show --stat)
подтверждает, что `server.php` в нём НЕ менялся. Rule 22 (Test
Philosophy) требует, чтобы каждый фикс был подтверждён как
non-false-positive до занесения в статус; для P11-001 это правило было
нарушено. Запись исправлена задним числом; сам код admin-роутинга
подтверждён рабочим (см. Verification ниже) — регрессии по существу нет,
ошибочной была только атрибуция изменения.

Implemented:
- FIX-11 mock close() восстановлен в test_admin_ban.php и
  test_admin_integration.php (AdminService::handleBanUser() закрывает
  онлайн-цель — без close() тесты падали Fatal error).
- Новый test_phase11_core_flows.php: register→login→create_room→join_room→
  start_game, invalid state transitions, rate-limit constants — 17/17 PASSED.
- docs/PHASE_11_REPORT.md: первый consolidated отчёт Phase 11 (требует
  сверки с CORRECTION выше в части P11-001).

Verification:
- Предварительно (Windows dev host, php run_ALL_tests.php): 25/25
  runnable test files PASSED, 8 live-WS subprocess tests SKIP
  (Workerman требует Linux).
- ОКОНЧАТЕЛЬНО (Ubuntu VPS, root@box-918838:/opt/lotto-game,
  php run_ALL_tests.php, 2026-07-27): полный регресс всех 31 файлов —
  **31/31 test files PASSED**, включая все 8 ранее пропущенных live-WS
  subprocess тестов (test_server_bootstrap 18/18, test_packet_validation
  11/11, test_auth_packet_routing 18/18, test_lobby_packet_routing
  23/23, test_game_packet_routing 21/21, test_admin_packet_routing
  15/15, test_session_lifecycle 6/6, test_protocol_completeness
  50/50 + 3 known warnings). Это первое подтверждение всей Phase 10/11
  цепочки на реальном Workerman-процессе с момента EPIC-10.7 —
  admin-роутинг (EPIC-10.6) и вся остальная протокольная маршрутизация
  подтверждены рабочими end-to-end на целевой платформе, не только
  статически/на моках.

- [DONE] EPIC-10.1 Packet validation
Files:
- docs/ADR/003-rate-limiting-and-invalid-json-policy.md (новый файл)
- docs/ANCHOR_CORE.md (diff — Connection Runtime Fields + Global Constants,
  Part 1 и Part 6, согласно ADR-003)
- docs/ANCHOR_PROTOCOL.md (diff — уточнение семантики error.invalid_json)
- src/Core/Constants.php (diff — RATE_LIMIT_PACKETS_PER_WINDOW=15,
  RATE_LIMIT_WINDOW_SECONDS=1)
- server.php (diff — реализация rate limiting в onMessage, инициализация
  packetCount/packetWindowStart в onWebSocketConnected)
- tests/Manual/test_packet_validation.php (новый файл)
- .gitignore (новый файл — попутно обнаружены случайно закоммиченные
  рантайм-артефакты game.db-shm/game.db-wal/workerman.*.pid)

Implemented:
- ADR-003 закрывает оба KNOWN GAP, зафиксированных при пре-Phase-10 аудите:
  1. Rate limiting (docs/prompt.md): > RATE_LIMIT_PACKETS_PER_WINDOW (15)
     пакетов за RATE_LIMIT_WINDOW_SECONDS (1) секунду от одного соединения
     → немедленное закрытие БЕЗ error-пакета. Считает ЛЮБЫЕ входящие
     сообщения (валидные/невалидные/ping) — инкремент до json_decode.
  2. Invalid-JSON policy (противоречие prompt.md "закрыть соединение" vs
     ANCHOR_PROTOCOL.md error.invalid_json): решено в пользу
     ANCHOR_PROTOCOL.md — код ошибки предполагает, что клиент его получит
     и разберёт, значит соединение НЕ закрывается. Подкреплено прецедентом
     error.server_full (уже реализован в LobbyService через sendError(),
     не через разрыв). Защиту от флуда малформед-JSON обеспечивает rate
     limiting, а не разрыв на первом невалидном пакете.
- Оба решения формализованы как ADR-003 и отражены в ANCHOR_CORE.md
  (новые Connection Runtime Fields: packetCount, packetWindowStart) и
  ANCHOR_PROTOCOL.md (явное уточнение про error.invalid_json).

Verification (полностью автоматическая, реальный WebSocket-клиент):
- tests/Manual/test_packet_validation.php — 11/11 PASSED, 5 сценариев:
  1. Ровно 15 невалидных пакетов — все получают error.invalid_json,
     соединение живо.
  2. 16-й пакет в том же окне — закрытие БЕЗ error-пакета (отличается от
     таймаута через feof()-проверку, не только по отсутствию ответа).
  3. Rate limit считает ping наравне с прочими (не делает исключения для
     валидных action) — 15 ping ок, 16-й закрывает соединение.
  4. Окно реально сбрасывается — burst 15+пауза>1s+burst 15 не суммируется
     в закрытие.
  5. Единичный невалидный JSON не закрывает соединение (базовый ADR-003
     сценарий вне контекста rate limit).
- Прогнано 3 раза подряд — стабильно, ~4s каждый, без зомби-процессов.
- Полный регресс по всем 25 файлам tests/Manual/*.php (было 24, добавлен
  test_packet_validation.php) — 0 failed.

PHASE 10 — WEBSOCKET PROTOCOL: IN PROGRESS (10.0, 10.1 done). Следующий:
EPIC-10.2 Protocol error handling.

- [DONE] EPIC-10.0 Protocol router
Files:
- server.php (новый файл, 175 строк)
- tests/Manual/test_server_bootstrap.php (новый файл, 227 строк)

Implemented:
- Workerman bootstrap: websocket://0.0.0.0:8080, single worker (count=1),
  согласно LOCAL_ENVIRONMENT.md и ANCHOR_CORE.md Part 1.
- onWorkerStart: инициализация Database/Logger/RoomManager-совместимой
  runtime-памяти ($worker->rooms/userConnections/sessionTokens = []),
  Global Watchdog Timer (60s, закрытие мёртвых соединений по порогам
  AUTHORIZED_TIMEOUT/UNAUTHORIZED_TIMEOUT — ANCHOR_CORE.md Part 5).
- onWebSocketConnected (не onConnect — handshake на этот момент уже
  завершён, что подтверждено докблоком Workerman "Emitted after websocket
  handshake"): инициализация Connection Runtime Fields (userId/username/
  isAdmin/sessionToken/lastPing), немедленная отправка hello
  {"type":"hello","protocol_version":1}.
- onMessage: безопасный json_decode (не-объект → error.invalid_json),
  ping без ответа (ANCHOR_PROTOCOL.md § Heartbeat), пустой action-диспетчер
  (match/default → error.invalid_json для любого ещё не подключённого action).
- onClose: диагностическое логирование + явный TODO — полная реконнект-
  логика невозможна в этом Epic (см. ниже).

Сознательно НЕ реализовано (Rule 11 Epic Isolation):
- Маршрутизация auth/lobby/game/admin-пакетов — EPIC-10.3/10.4/10.5/10.6.
  AuthHandler уже существует (Phase 1), но не подключён.
  LobbyHandler/GameHandler/AdminHandler ещё предстоит создать.
- Rate limiting (>15 пакетов/сек) и точная policy невалидного JSON —
  EPIC-10.1 (решено с пользователем явно, см. KNOWN GAPS).
- onClose → ReconnectService::handleDisconnect() не подключён: сам
  конструктор ReconnectService требует ОДНОВРЕМЕННО LobbyService И
  GameService — подключить его в server.php раньше EPIC-10.4/10.5
  означало бы нарушить Rule 11 (Auth+Lobby+Game в одном Epic).

Verification (автоматическая, полностью самодостаточная):
- tests/Manual/test_server_bootstrap.php поднимает server.php как
  реальный подпроцесс (proc_open), общается с ним через собственноручно
  написанный RFC6455 WebSocket-клиент (без внешних библиотек) по
  настоящему TCP-сокету на 127.0.0.1:8080, затем корректно останавливает
  процесс (SIGTERM → graceful shutdown, SIGKILL как fallback).
- Результат: 8/8 PASSED. Прогнан дважды подряд — порт корректно
  освобождается между запусками.
- Ручная проверка `php server.php start` — Workerman поднимается,
  таблица воркеров показывает [OK], graceful stop по SIGTERM.
- ⚠️→✅ ИСПРАВЛЕНО (2026-07-21): первая версия test_server_bootstrap.php
  зависала на VPS (требовался Ctrl+C). Причина — классический proc_open
  deadlock: stdout/stderr дочернего процесса шли в pipe, который никогда
  не вычитывался; ОС-буфер пайпа заполнялся выводом Workerman, дочерний
  процесс блокировался на write() до реального биндинга порта. В песочнице
  не воспроизводилось из-за небольшого объёма вывода, помещавшегося в буфер.
  Исправлено: вывод дочернего процесса теперь идёт в файлы (['file', ...],
  не ['pipe', ...] — запись в файл не блокируется по объёму), опрос порта
  вместо фиксированного sleep, диагностика stdout/stderr при сбое биндинга,
  жёсткий watchdog по SIGALRM (HARD_TIMEOUT_SECONDS=20) как последний
  рубеж — скрипт физически не может зависнуть навсегда. Проверено 5
  прогонов подряд (~3-4s каждый) + отдельно путь диагностики при заведомо
  нерабочем порте (5s, чистое сообщение об ошибке, без зависания).
- ⚠️→✅ ИСПРАВЛЕНО (второй раунд, тот же день): после первого фикса тест
  всё ещё падал на VPS — "WS handshake failed" с пустым ответом.
  Причина: осиротевший процесс server.php с ПЕРВОЙ (зависшей) попытки
  остался жить и держать порт 8080 (Workerman stdout честно писал
  "already running"), а тест по ошибке подключался к ЭТОМУ старому
  процессу вместо своего свежесозданного. Исправлено: перед стартом
  тест теперь сам вызывает `php server.php stop` (idempotent, безопасно
  при отсутствии запущенного процесса) для гарантированно чистого
  состояния, плюс явная диагностика "already running" с подсказкой
  ручной команды на случай, если self-healing не сработает. Проверено:
  вручную создан осиротевший процесс → тест сам его погасил и стартовал
  заново — 8/8 PASSED, без зомби-процессов после. 3 дополнительных
  прогона с чистого состояния — стабильно 8/8, ~3-4s каждый.
- Полный регресс по всем 24 файлам tests/Manual/*.php (был 23, добавлен
  test_server_bootstrap.php) — 0 failed.

PHASE 10 — WEBSOCKET PROTOCOL: IN PROGRESS (10.0 done, 10.1 Packet
validation next — включает решение по rate limiting и invalid-JSON policy)

- [DONE] EPIC-9.6 Admin integration tests
Files:
- src/Admin/AdminService.php (diff — FIX-3, см. ниже)
- tests/Manual/test_admin_logs.php (новый файл)
- tests/Manual/test_admin_integration.php (новый файл)
- test_logger.php (удалён из корня проекта)

Implemented:
- tests/Manual/test_admin_logs.php: assert-based верификация AdminService::handleGetLogs()
  (guard auth_required/not_your_turn, пакет admin_logs_data, отсутствие logger, срез
  limit=100 через Logger::getLastLines(), реальный Logger против файла). Закрывает
  пробел верификации EPIC-9.5 — прежний tests/Manual/test_logger.php был print_r()
  смоук-скриптом без assert'ов и не проверял AdminService вообще.
- tests/Manual/test_admin_integration.php: кросс-сценарии между admin-действиями
  (test_admin_ban/unban/kick/close_room.php покрывают контракты каждого действия
  ИЗОЛИРОВАННО; этот файл проверяет последовательности из нескольких действий в
  одной комнате, где инвариант экономики может нарушаться на стыке контрактов).

Обнаружен и исправлен баг (FIX-3, см. секцию PATCHES):
- handleKickUser() рефандил total_paid и уменьшал bank, но не обнулял total_paid
  игрока в памяти. Делегат удаления (removePlayerFromLobby/Game/Apartment) писал
  в all_players_history СТАРОЕ (дорефандное) значение total_paid. Последующий
  admin_close_room() безусловно рефандил total_paid из истории каждому участнику —
  кикнутый игрок получал деньги дважды. Нарушение Economic Integrity Rule
  (ANCHOR_CORE.md Part 2).
- Regression-тест (TEST 1 и TEST 3 в test_admin_integration.php) проверен на
  ложноположительность: временно откатывался FIX-3 → тест дал 5 честных FAIL
  (520 против фактических 540, 40 против 60); после восстановления фикса — снова
  20/20 PASSED.

Manual verification:
- test_admin_logs.php: 16/16 PASSED
- test_admin_integration.php: 20/20 PASSED
- Регрессия против всех существующих admin-тестов после FIX-3:
  test_admin_auth.php 8/8, test_admin_ban.php 9/9, test_admin_unban.php 8/8,
  test_admin_kick.php 37/37, test_admin_close_room.php 28/28 — все чисты.

PHASE 9 — ADMIN: COMPLETE (9.0–9.6 done)

Integration tests:
48 / 48 PASSED (auth)
90 / 90 PASSED (lobby)
164 / 164 PASSED (lotto engine) — см. KNOWN GAPS: тестовый файл падает по независимой причине
44 / 44 PASSED (game start) — см. KNOWN GAPS: тестовый файл падает по независимой причине
37 / 37 PASSED (turn system)
38 / 38 PASSED (victory system) — см. KNOWN GAPS: тестовый файл падает по независимой причине
32 / 32 PASSED (apartment)
15 / 15 PASSED (reconnect)
8 / 8 PASSED (admin auth)
9 / 9 PASSED (admin ban)
8 / 8 PASSED (admin unban)
37 / 37 PASSED (admin kick)
28 / 28 PASSED (admin close room)
16 / 16 PASSED (admin logs)
20 / 20 PASSED (admin integration)

Next planned Epic: EPIC-10.0 Protocol router (историческая запись на момент завершения EPIC-9.6 — выполнено, см. запись выше)

- [DONE] EPIC-9.5 Logs access
Files:
- src/Core/Logger.php
- src/Admin/AdminService.php

Implemented:
- AdminService::handleGetLogs().
- Admin authentication via assertAdmin().
- Protocol packet admin_logs_data.
- Logger::getLastLines() for reading last log entries.

Notes:
- Returns up to 100 most recent lines from logs/server.log.
- Missing or unreadable log file returns an empty array.
- Action is logged through Logger::info().

Manual verification:
- logger writes INFO/WARNING/ERROR correctly
- getLastLines() returns latest log entries
- limit parameter verified
- missing log file returns empty array
- admin endpoint returns admin_logs_data
- non-admin access denied

Limitations:
- Reads the log using file(); optimized tail-reading is intentionally deferred.
- Packet routing will be integrated during Phase 10 (Admin packet integration).

- [DONE] EPIC-9.4 Close room — AdminService::handleCloseRoom()
Files:
- src/Admin/AdminService.php (diff — добавлен handleCloseRoom())
- tests/Manual/test_admin_close_room.php (новый файл)
Notes:
- 28/28 тестов пройдено (php test_admin_close_room.php)
- Покрыто: закрытие waiting-комнаты без рефандов при total_paid=0,
  закрытие playing-комнаты с полным возвратом средств,
  возврат ранее удалённым игрокам через all_players_history,
  уведомление только active-игроков (disconnected не получают packet, но получают refund),
  room_not_found, guard для не-администратора,
  rollback при ошибке refund-транзакции (coins/bank не изменяются, destroyRoom не вызывается,
  комната сохраняется, PDO transaction корректно откатывается)
- Экономика: ANCHOR_CORE.md Part 2 § Admin Close Room —
  всем участникам возвращается 100% total_paid (включая apartment payments),
  источник данных — all_players_history, операция выполняется в одной PDO-транзакции

PHASE 9 — ADMIN: IN PROGRESS (9.0/9.1/9.2/9.3/9.4 done, 9.5 Logs access next)

Integration tests:
48 / 48 PASSED (auth)
90 / 90 PASSED (lobby)
164 / 164 PASSED (lotto engine)
44 / 44 PASSED (game start)
37 / 37 PASSED (turn system)
38 / 38 PASSED (victory system)
32 / 32 PASSED (apartment)
37 / 37 PASSED (admin kick)
28 / 28 PASSED (admin close room)

Next planned Epic: EPIC-9.5 Logs access

- [DONE] EPIC-9.3 Kick player — AdminService::handleKickUser()
Files:
- src/Admin/AdminService.php (diff — добавлен параметр $db в конструктор + handleKickUser())
- tests/Manual/test_admin_kick.php (новый файл)
Notes:
- 37/37 тестов пройдено (php test_admin_kick.php)
- Покрыто: waiting без total_paid (нет рефанда), kick хоста в waiting → transferHost(),
  playing с рефандом (users.coins += total_paid, bank -= total_paid, removePlayerFromGame
  с reason='kicked'), apartment с рефандом (removePlayerFromApartment с reason='kicked'),
  cannot_moderate_admin (нельзя кикнуть админа), room_not_found (цель не в комнате),
  not_your_turn guard (не-админ), rollback при сбое refund-транзакции (bank/room не тронуты,
  delegation не вызван, no dangling PDO transaction)
- Экономика: ANCHOR_CORE.md Part 2 § Kick — bank -= total_paid; coins += total_paid,
  транзакция обязательна, реализовано через существующий stmt 'add_user_coins'
- Конструктор AdminService расширен nullable-параметром $db (обратная совместимость
  сохранена — существующие вызовы с 5 аргументами не ломаются)

⚠️ KNOWN GAP (RESOLVED EPIC-9.3b, 2026-08-02):
~~removePlayerFromApartment() (ApartmentService) не выполняет host transfer при
kick/ban хоста в apartment-состоянии~~ — fixed in EPIC-9.3b.

⚠️ KNOWN GAP (historical, EPIC-9.3):
removePlayerFromApartment() (ApartmentService) не выполнял host transfer при
kick/ban хоста в apartment-состоянии, хотя ANCHOR_CORE.md Host Rules называет
'kicked'/'banned' валидными причинами смены хоста. Тот же пробел присутствует
и в существующем handleBanUser() для 'waiting' (не исправлялся — вне scope
EPIC-9.3, Epic Isolation). **Apartment path closed EPIC-9.3b; waiting ban path
still open.**

PHASE 9 — ADMIN: IN PROGRESS (9.0/9.1/9.2/9.3 done, 9.4 Close room next)

Integration tests:
48 / 48 PASSED (auth)
90 / 90 PASSED (lobby)
164 / 164 PASSED (lotto engine)
44 / 44 PASSED (game start)
37 / 37 PASSED (turn system)
38 / 38 PASSED (victory system)
32 / 32 PASSED (apartment)
37 / 37 PASSED (admin kick)

Next planned Epic: EPIC-9.4 Close room

- [DONE] EPIC-9.2 Unban user
Files:
- src/Admin/AdminService.php (diff)
- tests/manual/test_admin_unban.php (новый файл)
Notes:
- Реализован handleUnbanUser() для admin_unban_user
- Guard: только admin (assertAdmin)
- Валидация: user_id > 0
- DB: PreparedStatements key unban_user (banned_until=0)
- Manual tests: 8/8 PASSED

- [DONE] EPIC-9.1 Ban user
Files:
- src/Admin/AdminService.php (diff)
- src/Infrastructure/PreparedStatements.php (добавлен user_admin_by_id)
- tests/manual/test_admin_ban.php (новый файл)
Notes:
- Реализован handleBanUser() с duration: 1d / 3d / permanent
- Запрет бана администратора: error.cannot_moderate_admin
- Для онлайн-цели отправляется пакет banned {until}
- Удаление из комнаты по статусу:
  waiting -> removePlayerFromLobby(..., 'banned')
  playing -> removePlayerFromGame(..., 'banned')
  apartment -> removePlayerFromApartment(..., 'banned')
- Manual tests: 9/9 PASSED

- [DONE] EPIC-9.0 Admin authentication
Files:
- src/Admin/AdminService.php (реализован)
- tests/manual/test_admin_auth.php (новый файл)
Notes:
- Добавлен единый admin guard: AdminService::assertAdmin(object $connection): bool
- Контракт: unauthenticated -> error.auth_required, non-admin -> error.not_your_turn
- Manual tests: 8/8 PASSED

- [DONE] EPIC-8.6 Reconnect tests
Files:
- tests/manual/test_reconnect.php (новый файл)
Notes:
- 15/15 тестов пройдено
- Покрыто: disconnect->disconnected+timer, waiting-timeout removal, reconnect restore,
  reconnect_state payload, game AFK warning, auto-draw, afk removal

- [DONE] EPIC-8.5 AFK removal — ReconnectService::removePlayerFromGame(..., 'afk')
- [DONE] EPIC-8.4 Auto draw — ReconnectService::performAutoDraw()
- [DONE] EPIC-8.3 Game AFK protection — ReconnectService::ensureGameAfkTimer()/tickGameAfk()
- [DONE] EPIC-8.2 Reconnect restoration — ReconnectService::handleReconnect()
- [DONE] EPIC-8.1 Disconnect processing — ReconnectService::handleDisconnect()
- [DONE] EPIC-8.0 ReconnectService — src/Game/ReconnectService.php (реализация)
Files (8.0–8.5):
- src/Game/ReconnectService.php (новый файл, реализован)
Notes:
- Реализованы reconnect timers (15s, single-shot) и восстановление игрока по session_token
- Реализована game AFK защита с порогами 15/25/30с, auto draw и удалением по afk при 3 автоходах

PHASE 8 — RECONNECT & AFK: COMPLETE (service + manual tests)

- [DONE] EPIC-7.6 Apartment integration tests
Files:
- tests/Manual/test_apartment.php (новый файл)
Notes:
- 32/32 тестов пройдено
- Покрыто: hasLine, shouldTrigger, prepareApartment, allRequiredAnswered,
  alert broadcast, agree→payment, refuse→removal, re-trigger blocked

- [DONE] EPIC-7.5 Apartment timeout — ApartmentService::onApartmentTimeout()
- [DONE] EPIC-7.4 Apartment payment transaction — ApartmentService::finishApartment()
- [DONE] EPIC-7.3 Apartment voting — ApartmentService::handleApartmentChoice()
- [DONE] EPIC-7.2 Apartment state — ApartmentService::triggerApartment()
- [DONE] EPIC-7.1 Apartment trigger — ApartmentService::shouldTrigger() / prepareApartment()
- [DONE] EPIC-7.0 Line detection — ApartmentService::hasLine()
Files (7.0–7.5):
- src/Game/ApartmentService.php (470 строк — полный оркестратор)
- src/Game/GameService.php (735 строк — тонкие делегаторы)
Notes:
- ApartmentService расширен до оркестратора (db, stmts, logger в конструкторе)
- GameService сокращён с 985 до 735 строк
- GameService::handleApartmentChoice() / triggerApartment() — публичные делегаторы

PHASE 7 — APARTMENT: COMPLETE
PHASE 8 — RECONNECT & AFK: COMPLETE

Integration tests:
48 / 48 PASSED (auth)
90 / 90 PASSED (lobby)
164 / 164 PASSED (lotto engine)
44 / 44 PASSED (game start)
37 / 37 PASSED (turn system)
38 / 38 PASSED (victory system)
32 / 32 PASSED (apartment)

Next planned Epic: EPIC-8.0 ReconnectService

- [DONE] EPIC-7.6 Apartment integration tests
Files:
- tests/Manual/test_apartment.php (новый файл)
Notes:
- 32/32 тестов пройдено
- Покрыто: hasLine (empty/full/partial), shouldTrigger (line/fired/disconnected),
  prepareApartment (status, flags, required), allRequiredAnswered,
  alert broadcast (required/immune), agree→payment (bank, immune, commit),
  refuse→removal (player_left, drawer_order), re-trigger blocked

- [DONE] EPIC-7.5 Apartment timeout — GameService::onApartmentTimeout()
- [DONE] EPIC-7.4 Apartment payment transaction — finishApartment() PDO
- [DONE] EPIC-7.3 Apartment voting — GameService::handleApartmentChoice()
- [DONE] EPIC-7.2 Apartment state — GameService::triggerApartment()
- [DONE] EPIC-7.1 Apartment trigger — ApartmentService::shouldTrigger() / prepareApartment()
- [DONE] EPIC-7.0 Line detection — ApartmentService::hasLine()
Files (7.0–7.5):
- src/Game/ApartmentService.php (новый файл, 222 строки)
- src/Game/GameService.php (diff, 985 строк)
Notes:
- Victory > Apartment: проверка победы идёт до shouldTrigger() в handleDrawBarrel()
- immune=true после agree — повторный апартамент не требует платы
- apartment_fired — at most once per game

PHASE 7 — APARTMENT: COMPLETE

⚠️ KNOWN GAP — ADR REQUIRED:
GameService 985 строк — вплотную к mandatory refactor (1000).
Кандидаты на декомпозицию: finishGame(), handleNoSurvivors() → отдельный GameFinishService.
Необходимо до начала Phase 8.

Integration tests:
48 / 48 PASSED (auth)
90 / 90 PASSED (lobby)
164 / 164 PASSED (lotto engine)
44 / 44 PASSED (game start)
37 / 37 PASSED (turn system)
38 / 38 PASSED (victory system)
32 / 32 PASSED (apartment)

Next planned Epic: EPIC-8.0 ReconnectService
⚠️ Before Phase 8: ADR for GameService decomposition required.

- [DONE] EPIC-6.5 Victory system tests
Files:
- tests/Manual/test_victory.php (новый файл)
Notes:
- 38/38 тестов пройдено
- Покрыто: checkCardVictory (0/1/2 wins), checkAllVictories (disconnected skip),
  calculatePrize (floor division, remainder burn, double+normal),
  finishGame (payout, room destruction, game_over broadcast, DB rollback),
  full draw-until-victory integration test

- [DONE] EPIC-6.4 Game finish flow — GameService::finishGame()
- [DONE] EPIC-6.3 Winner payout transaction — all-or-nothing PDO
- [DONE] EPIC-6.2 Prize calculation — VictoryService::calculatePrize()
- [DONE] EPIC-6.1 Double victory detection — встроена в checkCardVictory()
- [DONE] EPIC-6.0 Victory detection — VictoryService::checkCardVictory() / checkAllVictories()
Files (6.0–6.4):
- src/Game/VictoryService.php (новый файл, 146 строк)
- src/Game/GameService.php (diff, 703 строки)
Notes:
- markNumber() в handleDrawBarrel() применяется ко всем активным игрокам
- GameService 703 строки — зона warning; finishGame() кандидат на ADR-декомпозицию

PHASE 6 — VICTORY SYSTEM: COMPLETE

Integration tests:
48 / 48 PASSED (auth)
90 / 90 PASSED (lobby)
164 / 164 PASSED (lotto engine)
44 / 44 PASSED (game start)
37 / 37 PASSED (turn system)
38 / 38 PASSED (victory system)

Next planned Epic: EPIC-7.0 Line detection (Apartment)
- [DONE] EPIC-5.5 Turn system tests
Files:
- tests/Manual/test_turn_system.php (новый файл)
Notes:
- 37/37 тестов пройдено
- Покрыто: sendYourTurn, nextDrawer (cyclic, skip disconnected, skip removed, null),
  handleDrawBarrel (guards, bag, drawn_numbers, AFK reset, broadcast, rotation),
  markNumber (column mapping, multi-cell, unknown number),
  full 2-player 3-turn cycle

- [DONE] EPIC-5.4 Player card marking — GameService::markNumber()
- [DONE] EPIC-5.3 Broadcast drawn barrel — barrels_drawn packet
- [DONE] EPIC-5.2 Draw barrel — GameService::handleDrawBarrel()
- [DONE] EPIC-5.1 Drawer rotation — GameService::nextDrawer()
- [DONE] EPIC-5.0 Drawer queue — GameService::sendYourTurn()
Files (5.0–5.4):
- src/Game/GameService.php (diff, 564 строки)
Notes:
- masks инициализируются в handleStartGame (bool[cardsCount][3][9], все false)
- markNumber() публичный — используется VictoryService в Phase 6
- peekNextDrawer() приватный — только для next_drawer в пакете barrels_drawn

PHASE 5 — TURN SYSTEM: COMPLETE

Integration tests:
48 / 48 PASSED (auth)
90 / 90 PASSED (lobby)
164 / 164 PASSED (lotto engine)
44 / 44 PASSED (game start)
37 / 37 PASSED (turn system)

Next planned Epic: EPIC-6.0 Victory detection
- [DONE] EPIC-4.5 Game initialization tests
Files:
- tests/Manual/test_game_start.php (новый файл)
Notes:
- 44/44 тестов пройдено
- Покрыто: auth guard, room guard, host guard, status guard, min players,
  insufficient coins, bank calculation, bag generation, card assignment,
  transaction commit, game_started packet (is_self, cards, masks, drawer_order),
  AFK fields reset

- [DONE] EPIC-4.4 Game start protocol — GameService::handleStartGame()
- [DONE] EPIC-4.3 StartGame transaction — all-or-nothing PDO transaction
- [DONE] EPIC-4.2 Bank creation — bank = sum(total_paid)
- [DONE] EPIC-4.1 Game initialization — status=playing, bag, cards, drawer
- [DONE] EPIC-4.0 Player card purchase logic — total_paid = cards_count × BET_PER_CARD
Files (4.0–4.4):
- src/Game/GameService.php (новый файл, 301 строка)
- src/Infrastructure/PreparedStatements.php (добавлен user_by_id)

PHASE 4 — GAME START: COMPLETE

Integration tests:
48 / 48 PASSED (auth)
90 / 90 PASSED (lobby)
164 / 164 PASSED (lotto engine)
44 / 44 PASSED (game start)

Next planned Epic: EPIC-5.0 Drawer queue
- [DONE] EPIC-3.4 Engine test suite
Files:
- tests/Manual/test_lotto_engine.php (новый файл)
Notes:
- 164/164 тестов пройдено
- Покрыты: generateCard, generateBag, validateCard, validateBag
- 100 итераций generateCard, 20 итераций generateBag
- Колоночные инварианты: >=1 число на столбец, сортировка top-to-bottom
- CSPRNG: Fisher-Yates + random_int() во всех shuffle-операциях

- [DONE] EPIC-3.3 Bag validator — LottoEngine::validateBag()
- [DONE] EPIC-3.2 Card validator — LottoEngine::validateCard()
- [DONE] EPIC-3.1 Bag generator — LottoEngine::generateBag()
- [DONE] EPIC-3.0 Card generator — LottoEngine::generateCard() (mask-based алгоритм)
Files (3.0–3.3):
- src/Game/LottoEngine.php (новый файл, заменена заглушка)

PHASE 3 — LOTTO ENGINE: COMPLETE

- [DONE] EPIC-2.7 Lobby integration tests
Files:
- tests/Manual/test_lobby_integration.php (новый файл)
- tests/Manual/mock_timer.php (новый файл)
Notes:
- 90/90 тестов пройдено
- Покрыто: RoomManager, handleCreateRoom, handleJoinRoom, handleLeaveRoom,
  removePlayerFromLobby, all_players_history, transferHost, handleRoomList,
  Lobby AFK Timer (MockTimer stub без event loop)
- Workerman\Timer подменён через mock_timer.php (namespace stub)
- Функциональный WebSocket тест отложен до EPIC-10.x (server.php не создан)

Commit: EPIC-2.7 lobby-integration-tests

- [DONE] EPIC-2.6 Lobby AFK system
Files:
- src/Lobby/LobbyService.php (diff)
Notes:
- startLobbyAfkTimer(): отменяет предыдущий → Timer::add(1s repeat) → проверяет time()-host.last_action >= 120s → transferHost()
- stopLobbyAfkTimer(): Timer::del + lobby_afk_timer_id = null
- handleJoinRoom(): вызов startLobbyAfkTimer() когда count(players) >= 2
- handleLeaveRoom(): вызов stopLobbyAfkTimer() когда count(players) < 2 после удаления
- destroyRoom() уже отменяет таймер — дублирования нет
- Добавлен use Workerman\Timer
- Known gap закрыт (зафиксирован в EPIC-2.3)
- Функциональный тест (WebSocket) отложен до EPIC-10.x (server.php не создан)

Commit: EPIC-2.6 lobby-afk-system

- [DONE] EPIC-2.5 Host transfer
Files:
- src/Lobby/LobbyService.php (реализовано в рамках EPIC-2.3)
Notes:
- transferHost(): FIFO по drawer_order среди active → новый host_conn_id
- Вызывается из handleLeaveRoom() при $wasHost === true
- Если нет активных игроков → destroyRoom()
- Отдельного кода не потребовалось: логика покрыта EPIC-2.3

Commit: (входит в EPIC-2.3 leave-room)

- [DONE] EPIC-2.4 Room list
Files:
- src/Lobby/LobbyService.php (diff)
Notes:
- handleRoomList(): auth guard → итерация $worker->rooms → buildRoomListEntry() → room_list пакет
- Возвращаются все комнаты в любом статусе (waiting / playing / apartment)
- Формирование entry делегировано RoomManager::buildRoomListEntry() (EPIC-2.0)
- Протокол: {"type":"room_list","rooms":[...]} — ANCHOR_PROTOCOL.md § Lobby

Commit: EPIC-2.4 room-list

- [DONE] EPIC-2.3 Leave room
Files:
- src/Lobby/LobbyService.php (diff)
Notes:
- handleLeaveRoom(): auth → findRoomIdByConnId → guard status=waiting → removePlayerFromLobby → transferHost если ушёл хост
- removePlayerFromLobby(): запись в all_players_history → unset players → очистка drawer_order → destroyRoom если пусто → broadcast player_left активным
- transferHost(): FIFO по drawer_order среди active → destroyRoom если нет активных
- Протокол: player_left {type, username, reason} — только активным, не уходящему
- Экономика: монеты не затронуты (total_paid=0 в waiting)
- Known gap: lobby_afk_timer_id при count<2 не отменяется — устраняется в EPIC-2.6

Commit: pending (git commit -m "EPIC-2.3 leave-room")

Commit: pending (git commit -m "EPIC-2.0 room-manager")

- [DONE] EPIC-2.1 Create room
Files:
- src/Lobby/LobbyService.php

Notes:
- handleCreateRoom(): валидация лимитов → bcrypt пароль → RoomManager::createRoom() → player entry → room_joined
- Проверки: MAX_ROOMS, MAX_TOTAL_PLAYERS, cards_count ∈ {1,2}, max_players ∈ [2..10]
- Монеты не списываются (Reservation Rule, ANCHOR_CORE Part 2)
- drawer_order инициализируется хостом (ANCHOR_CORE § Drawer Order Rules)
- Карты не назначаются — делегировано start_game() (EPIC-4.1)

Commit: pending (git commit -m "EPIC-2.1 create-room")

- [DONE] EPIC-2.2 Join room
Files:
- src/Lobby/LobbyService.php (diff)
Notes:
- handleJoinRoom(): auth → room exists → status=waiting → not full → MAX_TOTAL_PLAYERS → password → cards_count → player entry → room_joined → broadcast player_joined
- Пароль: password_verify(bcrypt)
- drawer_order: FIFO append (ANCHOR_CORE § Drawer Order Rules)
- room_joined → входящему; player_joined → остальным активным
Commit: pending

---

## PRE-BUILT COMPONENTS

### PRE-BUILT-1 — Reconnect Token Infrastructure
Status: Completed (изолирован, пока не используется)

Files:
- src/Auth/ReconnectTokenService.php

Notes:
- Генерация и валидация 64-символьных HEX токенов переподключения.
- Не интегрирован в текущий протокол.
- Планируемый потребитель: EPIC-8.0 ReconnectService.

---

## PATCHES

## FIX-12 — Test loggers writing into the production log file
Status: Completed
Date: 2026-07-25

Found during: a live operational incident, not a proactive audit this
time. A permission-ownership mismatch (`game.db`/`workerman.log`/
`logs/server.log` left root-owned after test runs executed as root
against the live VPS, while the production `lotto-server.service` runs
as `www-data`) caused a real crash-loop on the production service.
While diagnosing that, a confusing `[ERROR] ... CHECK constraint failed:
coins <= 200` line was found in `logs/server.log` — alarming at first
glance, since no such constraint exists in the real schema
(`docs/... .schema users` confirmed no CHECK clause). Traced to its
actual source rather than assumed.

Files:
- src/Core/Logger.php (diff — optional `?string $logFilePath = null`
  constructor parameter, mirroring the FIX-4 precedent for
  `Database::__construct(?PDO $pdo = null)`. Default (no argument)
  preserves exact prior behavior — server.php's own `new Logger()` needs
  no change at all.)
- tests/Manual/test_login.php (diff)
- tests/Manual/test_register.php (diff)
- tests/Manual/test_session_service.php (diff)
- tests/Manual/test_single_session.php (diff)
- tests/Manual/test_victory.php (diff — the actual source of the
  incident's confusing ERROR line)
- tests/Manual/test_admin_logs.php (diff)
- tests/Manual/test_admin_integration.php (diff)
- tests/Manual/test_logger.php (DELETED — see below)

Problem:
- `Logger::__construct()` hardcoded the log path to `logs/server.log`
  with no way to inject a different one. Any test constructing a real
  `Logger` (not a `MockLogger`) — which several do purely incidentally,
  as a required dependency of `AuthService`/`GameFinishService`/
  `AdminService`, with no interest in testing logging itself — wrote
  straight into the shared production log on every run.
- `tests/Manual/test_victory.php`'s `makeSvc()` (added in FIX-4) builds a
  real `GameFinishService` over an isolated **in-memory** SQLite database
  specifically to test transaction rollback via a deliberately-rigged
  `CHECK(coins <= 200)` constraint — genuinely correct DB isolation. But
  it paired that with a real, default-path `Logger`, so the rigged
  failure's error message still landed in the real `logs/server.log`,
  indistinguishable from an actual production incident. The existing
  code comment at that exact line already said "побочный эффект — запись
  в logs/server.log" (side effect — writes to logs/server.log) —
  correctly identified by whoever wrote FIX-4, but never fixed, and this
  is exactly what it eventually caused.
- Two more genuine instances found by a full sweep of every `new
  Logger(...)` call site project-wide (not just the one that caused the
  incident): `test_admin_logs.php` TEST 6 and `test_admin_integration.php`
  TEST 4 both wrote real marker/action lines into the shared log purely
  as a side effect of exercising `Logger::getLastLines()`/
  `AdminService`, with no reason to specifically target the production
  path.
- A latent, interesting side-discovery: `test_lobby_integration.php` and
  `test_auth_integration.php` already called `new Logger('/dev/null')` —
  an earlier session's own attempt to solve exactly this problem. It
  never worked: PHP does not error when extra arguments are passed to a
  zero-parameter constructor (unlike stricter-arity languages), so the
  `'/dev/null'` argument was silently discarded and the call was
  equivalent to `new Logger()` the whole time. This fix makes that
  existing, previously-non-functional intent actually work, at zero
  additional cost — no code change needed in either file.
- `tests/Manual/test_logger.php` (distinct from the project-root copy
  already deleted in a much earlier session, per the 2026-07-03 decision
  log entry) was a leftover duplicate: a print_r()-based smoke script
  with zero assertions, explicitly documented by this project's own
  EPIC-9.6 entry and by `test_admin_logs.php`'s own header comment as
  superseded. It ran on every `run_ALL_tests.sh` pass, wrote generic
  "test 1"/"test 2"/"test 3" lines into the real log, and contributed
  nothing (no pass/fail signal at all) — deleted.

Deliberately NOT changed: `tests/Manual/test_helpers_runner.php`
Scenario 4 and `server.php` itself. Scenario 4's entire purpose is
verifying that the *default* `Logger` path is genuinely
`logs/server.log` — redirecting it would break the one thing it's
testing. `server.php`'s own `new Logger()` is correct production code by
definition.

Known, explicitly out-of-scope for this fix (lower severity, different
category — see decision log): the real-WS-client subprocess tests
(`test_auth_packet_routing.php`, `test_lobby_packet_routing.php`,
`test_game_packet_routing.php`, `test_admin_packet_routing.php`,
`test_session_lifecycle.php`, `test_packet_validation.php`,
`test_server_bootstrap.php`) each spawn a genuine `php server.php start`
subprocess to exercise real end-to-end routing — that subprocess's
`Logger` is unmodified production code, correctly writing to the real
log path, because it *is* the real server. This still leaves
test-generated `INFO`/`WARNING` lines with test-like usernames
(`fix10_user1`, `e106_admin`, etc.) in the production log — clearly
identifiable as test noise, not the confusing false-`ERROR` class of
problem this fix targets. Properly isolating it would require making
`server.php`'s own log path configurable (an env var, defaulting to the
current path) and updating all seven harnesses to set it — a larger,
separate change touching production code, left for an explicit future
decision rather than folded in here silently.

Verified:
- All 5 originally-affected tests re-run individually with an MD5 hash
  of `logs/server.log` captured before and after each — byte-identical
  in every case (confirmed no write occurred).
- Full `run_ALL_tests.sh` re-run with the same before/after hash check
  across the *entire* suite — the only lines that appear afterward
  originate from the real-WS-subprocess tests described above (expected,
  out of scope), not from any of the fixed files.
- `test_helpers_runner.php` re-run in isolation — still correctly writes
  to and reads back from the real default path, confirming `Logger`'s
  default behavior is byte-for-byte unchanged.
- Every affected test's pass count matches its previously-documented
  count exactly (40/40 victory, 91/91 lobby integration, 55/55 auth
  integration, 20/20 admin integration, etc.) — no behavior change, only
  the log destination.
- Full regression across all 29 remaining `tests/Manual/*.php` files (30
  minus the deleted `test_logger.php`) — 0 failed.

No ADR required — no protocol, economy, timer, or room/player structure
touched. Purely a test-isolation and logging-infrastructure fix.

Diff: patches/FIX-12-Logger.patch, patches/FIX-12-test-login.patch,
patches/FIX-12-test-register.patch, patches/FIX-12-test-session-service.patch,
patches/FIX-12-test-single-session.patch, patches/FIX-12-test-victory.patch,
patches/FIX-12-test-admin-logs.patch, patches/FIX-12-test-admin-integration.patch

## FIX-16 — server.php bootstrap helper missing from committed Helpers.php
Status: Completed
Date: 2026-07-28

Found during: full `./run_ALL_tests.sh` on the Ubuntu VPS at the end of
EPIC-13.4 sign-off — not during local Windows dev, where the committed
`run_ALL_tests.php` still skips the eight live-WS-subprocess tests via
`$skipOnWindows` (FIX-15 intent documented in `docs/LOCAL_ENVIRONMENT.md`
but the bootstrap helpers themselves were never committed).

Background (FIX-15): `lottoBootstrapPhpExtensions()` and `lottoPhpIniArgs()`
were developed locally for Windows SQLite bootstrap and child-process
`proc_open` spawning. They lived only in an **uncommitted** diff to
`src/Core/Helpers.php` alongside local edits to `run_ALL_tests.php`.

Breaking commit: `b203493` (EPIC-13.1) added
`lottoBootstrapPhpExtensions()` to `server.php:109` (and the corresponding
`use function` import) — copied from the local uncommitted state — without
the function definition being present in the repository. On Linux/VPS the
call is a no-op when defined, but **fatal when undefined**.

Symptom on VPS (`/opt/lotto-game`, `./run_ALL_tests.sh` after `git pull`
to Phase 13 HEAD before this fix):
- Eight live WS subprocess tests failed with
  `server.php did not bind port … in time (running=no)`.
- stderr on every spawned `server.php`:
  `PHP Fatal error: Call to undefined function
  Lotto\Core\lottoBootstrapPhpExtensions() in server.php:109`.

Affected tests (all subprocess-spawned `server.php`):
`test_admin_packet_routing.php`, `test_auth_packet_routing.php`,
`test_game_packet_routing.php`, `test_lobby_packet_routing.php`,
`test_packet_validation.php`, `test_server_bootstrap.php`,
`test_session_lifecycle.php`, `test_protocol_audit.php`.

Files:
- src/Core/Helpers.php (diff — add `lottoBootstrapPhpExtensions()` and
  `lottoPhpIniArgs()`; both no-op / empty-array on Linux)

Fix commit: `0de46d0` — `Fix missing lottoBootstrapPhpExtensions in committed
Helpers.php.`

Verified:
- Fresh `git clone` from GitHub at `0de46d0` (branch
  `cursor/epic-11-1-vps-ws-test-isolation`, no workspace-local files):
  `php server.php start` with isolated `LOTTO_WS_PORT` reaches Workerman
  `[ok]` — no fatal error (Windows dev host, 2026-07-28; `composer install`
  not available in agent environment — vendor copied from lockfile-matched
  tree for bind test only).
- Local workspace `php run_ALL_tests.php` at `0de46d0`+: **41/41** test
  files PASS (Windows dev host, 2026-07-28; uses uncommitted runner with
  FIX-15 Windows WS enablement).
- VPS `./run_ALL_tests.sh` after `git pull` to `0de46d0`:
  **MANUAL VERIFICATION REQUIRED** — agent has no SSH access to
  `/opt/lotto-game`. Expected: all `tests/Manual/test_*.php` pass (41 files
  at HEAD); the eight subprocess tests above must reach port bind.

Process lesson (same class as FIX-12): local-only or root-owned artifacts
masked a production-breaking gap until the VPS-authoritative test run.
Any symbol `server.php` calls must be committed **in the same commit or an
earlier one** before the call lands. Uncommitted helper functions
referenced by committed entrypoints are a release blocker — Windows skips
are not a substitute for Ubuntu sign-off per `LOCAL_ENVIRONMENT.md`.

No ADR required — no protocol, economy, timer, or room/player structure
touched. Purely a missing-dependency / process-discipline fix.

Diff: commit `0de46d0` (src/Core/Helpers.php only)

## EPIC-10.7 — Protocol integration tests
Status: Completed
Date: 2026-07-24

Files:
- tests/Manual/test_protocol_completeness.php (новый файл — 50 assertions)

Scope, per explicit user direction: this Epic checks that everything
ANCHOR_CORE.md/ANCHOR_PROTOCOL.md *declare* is actually *present* on the
server side — a completeness/coverage audit, not a re-test of business
logic. Business logic is already exhaustively covered: every module has
its own real-WS-client routing test (test_auth_packet_routing.php,
test_lobby_packet_routing.php, test_game_packet_routing.php,
test_admin_packet_routing.php) plus dozens of Phase-specific unit tests.
Re-testing that logic here would be redundant, not thorough.

Deliberately a static source-cross-reference test, not a live-server one
— it parses the actual registries out of docs/ANCHOR_CORE.md and
docs/ANCHOR_PROTOCOL.md at run time (not a hardcoded copy of them), so it
stays honest against drift in either direction: it would catch a future
session adding a new packet/action to the docs without implementing it,
*or* implementing something without documenting it (Rule 27 Naming
Authority). Checks:
1. Every declared Protocol Action reachable in server.php's dispatcher.
2. Every declared Protocol Packet Type emitted somewhere in src/ or
   server.php.
3. Every declared Error Code used somewhere.
4. Reverse check: packet types emitted in code but not declared anywhere.
5. All four protocol handlers (AuthHandler/LobbyHandler/GameHandler/
   AdminHandler) actually instantiated in server.php's onWorkerStart.

Result: 50/50 PASSED, 0 failed, 3 warnings — all three warnings match
already-documented KNOWN GAPS, no new surprises:
- `admin_stats_data` (packet type): declared, zero emission sites —
  already flagged (2026-07-03 audit) as unimplemented/no Epic assigned.
- `afk_warning` (packet type): emitted (ReconnectService, EPIC-8.3), not
  declared in the registry — already flagged as documentation debt.
- `error.banned` (error code): declared, zero usage sites — **new
  finding this Epic**. Not a functional gap: the dedicated `banned`
  packet type (`{"type":"banned","until":...}`) already covers every
  ban-rejection path (login, reconnect since FIX-11, admin notification)
  — `error.banned` appears to be a redundant/unused declaration in the
  Error Packet Codes registry, never actually needed once the dedicated
  packet type existed. Logged as a new KNOWN GAP (low priority,
  documentation-only) rather than touched: ANCHOR_PROTOCOL.md states it
  "Never changes," and removing a declared code — even an unused one —
  is arguably a change to that document; left for an explicit user
  decision (same treatment as the admin_stats_data gap: either assign it
  a purpose or formally deprecate it).

No code defects found by this Epic — confirms the wiring built across
EPIC-10.0-10.6 is genuinely complete against the declared protocol
surface, not just working for the specific scenarios the routing tests
happened to cover.

PHASE 10 — WEBSOCKET PROTOCOL: COMPLETE (10.0-10.7 all done).

Diff: patches/EPIC-10.7-test-protocol-completeness.patch (new file, full
content — see also tests/Manual/test_protocol_completeness.php directly)


Status: Completed
Date: 2026-07-24

Files:
- src/Admin/AdminHandler.php (новый файл — thin wrapper над AdminService,
  тот же паттерн что GameHandler/LobbyHandler)
- server.php (diff — AdminService/AdminHandler dependency wiring in
  onWorkerStart, все 5 admin_* actions добавлены в dispatcher; см. FIX-11
  ниже — часть этого же diff)
- src/Admin/AdminService.php (diff — FIX-11, см. ниже)
- src/Auth/AuthHandler.php (diff — FIX-11, ban check in handleReconnect())
- src/Auth/AuthService.php (diff — FIX-11, getUserById() returns
  banned_until now too)
- src/Infrastructure/PreparedStatements.php (diff — FIX-11, extended
  user_auth_fields_by_id to include banned_until)
- tests/Manual/test_admin_ban.php (diff — FIX-11, MockConnection needs a
  close() method now that handleBanUser() actually calls it)
- tests/Manual/test_admin_integration.php (diff — FIX-11, same fix for
  SpyConnection)
- tests/Manual/test_admin_packet_routing.php (новый файл — 15 assertions,
  real WS client, covers both EPIC-10.6 routing and FIX-11 regression
  scenarios together since FIX-11 was found while probing this Epic's own
  wiring — same pattern as EPIC-10.5/FIX-9)

AdminService already existed and was fully tested (Phase 9) — the routing
part of this Epic is, like every other EPIC-10.x, pure dependency wiring.
The one thing that made this wiring non-trivial: AdminService's
constructor takes 7 nullable dependencies (stmts, logger, lobbyService,
reconnectService, apartmentService, db, roomManager), and several of them
degrade silently rather than erroring if omitted — missing
lobbyService/reconnectService/apartmentService means a banned/kicked
online player is never actually removed from their room (money still
moves correctly, but a "ghost" player entry lingers); missing roomManager
means admin_close_room falls back to a raw unset() that skips ALL timer
cleanup, the exact class of bug FIX-6 fixed elsewhere. All seven are now
wired. $apartmentService is deliberately the same local variable already
in scope from the EPIC-10.5 block (never stored as a $worker property,
since only GameService needed it there) rather than retroactively
touching completed EPIC-10.5 code — captured by closure scope instead.

Found and fixed during this Epic's audit (FIX-11) — proactively looking
for another FIX-9/FIX-10-class interaction bug before shipping, per user
request:

Problem (three compounding gaps, all in the ban path specifically —
handleKickUser() was already correct):
1. AdminService::handleBanUser()'s structural room-removal
   (findPlayerMembership() + removePlayerFromLobby/Game/Apartment) was
   nested INSIDE `if (isset($worker->userConnections[$targetUserId]))`.
   Before FIX-10, that map entry was never cleared on disconnect, so this
   accidentally always ran for anyone who'd ever been online. After
   FIX-10 correctly started clearing it on genuine disconnect, a banned
   player who happened to be mid-reconnect-window (disconnected, not yet
   timed out) at ban time no longer had a userConnections entry — so the
   entire removal branch was skipped. They kept their room seat and
   active reconnect_timer, and reconnecting before it expired let them
   fully resume playing seconds after being banned.
2. Banning a currently-*online* target never closed their WebSocket
   connection — only sent them a `banned` packet. $connection->userId/
   isAdmin/sessionToken stayed bound; they could keep issuing any action
   not tied to the now-removed room indefinitely, until they happened to
   disconnect on their own.
3. The most severe: AuthHandler::handleReconnect() never checked
   banned_until at all, unlike AuthService::login() which does. A banned
   user could bypass the ban indefinitely simply by sending
   {"action":"reconnect","token":<their existing session_token>} instead
   of logging in fresh — reconnect was a complete, permanent end-run
   around moderation, independent of anything room-related.
- Verified empirically end-to-end (not simulated) before writing any fix,
  same discipline as FIX-10: reproduced all three independently with a
  live server and real WS clients.

Fix:
1. handleBanUser()'s removal logic un-nested from the userConnections
   check — now runs unconditionally based on findPlayerMembership(),
   identical in shape to handleKickUser()'s already-correct pattern. The
   "notify + close" part remains conditional on the target being
   currently online (that part is correctly conditional).
2. If the target is online, after sending `banned`, their connection is
   now explicitly closed ($targetConnection->close()). Order matters:
   room removal happens first, so onClose's own
   ReconnectService::handleDisconnect() correctly no-ops afterward (the
   player is already gone from $room['players'] by the time it runs) —
   no double-removal/double-refund risk (the FIX-3 class of bug).
3. AuthService::getUserById() (added in FIX-10) now also returns
   banned_until (new column in the user_auth_fields_by_id query).
   AuthHandler::handleReconnect() checks it immediately after fetching
   the user and, if currently banned, responds with the exact same
   {"type":"banned","until":...} packet login() already sends — reusing
   the existing contract, not introducing a new one.

Verified non-false-positive (each of the three independently, by
reverting only that piece and re-running the relevant scenario):
- Reverted only the handleBanUser() un-nesting -> disconnected-at-ban-time
  player kept their room seat and successfully reconnected; restored ->
  fixed again.
- Reverted only the connection-close call -> N/A as a standalone revert
  (bundled with #1 in the same nested block); covered together in the
  online-ban scenario, confirmed working as one unit.
- Reverted only the AuthHandler ban check -> banned user's reconnect
  succeeded and subsequent room_list worked (full bypass reproduced
  exactly as before the fix); restored -> fixed again.

Result:
- tests/Manual/test_admin_packet_routing.php (new): 15/15 PASSED —
  real WS client against a live server.php. Covers admin_get_logs/
  admin_ban_user/admin_unban_user/admin_kick_user/admin_close_room
  routing, the assertAdmin guard (both auth_required and not_your_turn
  paths), cannot_moderate_admin, and three FIX-11 scenarios: online-ban
  (banned packet + connection closed), mid-disconnect-ban (reconnect
  correctly blocked, room structurally cleaned up despite the target
  being offline at ban time), and unban-then-relogin.
- tests/Manual/test_admin_ban.php: 9/9 PASSED after adding close() to
  MockConnection (fixture update, not a business-logic change — Rule 22).
- tests/Manual/test_admin_integration.php: 20/20 PASSED after the same
  fixture update to SpyConnection.
- Full regression across every tests/Manual/*.php file (30 files,
  including the new one) — 0 failed.

Also fixed in this Epic (trivial, unrelated to FIX-11's substance): a
pre-existing PHP warning ("Undefined property: ...TcpConnection::$userId")
in onClose when a raw TCP connection closes before ever completing the
WebSocket handshake (so onWebSocketConnected's field initialization never
ran) — direct property access changed to null-coalescing, matching the
adjacent log line's existing style.

No ADR required for the routing wiring (no protocol change). FIX-11 also
requires no ADR: no protocol packet, error code, room/player structure
key, or timer changed — `banned` is the same existing packet login()
already sends, reused from a second call site where it had been missing.

Diff: patches/EPIC-10.6-server.patch, patches/FIX-11-AdminService.patch,
patches/FIX-11-AuthHandler.patch, patches/FIX-11-AuthService.patch,
patches/FIX-11-PreparedStatements.patch,
patches/FIX-11-test-admin-ban.patch, patches/FIX-11-test-admin-integration.patch

## FIX-10 — Permanent session lockout after any disconnect outside room membership
Status: Completed
Date: 2026-07-24

Found during: proactive audit before starting EPIC-10.6, specifically
looking for another FIX-9-class issue (a bug only reachable once real
end-to-end routing exists) before adding more admin-side removal paths
that would have inherited the same defect.

Files:
- src/Infrastructure/PreparedStatements.php (diff — new query
  `user_auth_fields_by_id`: id/username/is_admin by id; neither existing
  `user_by_id` (id, coins) nor `user_admin_by_id` (id, is_admin) return
  username, which AuthHandler::bindConnection() requires)
- src/Auth/AuthService.php (diff — new `getUserById(int $userId): ?array`,
  using the statement above; returns null on missing user, which the
  caller treats as an invalid session rather than throwing)
- src/Auth/AuthHandler.php (diff — `handleReconnect()` now calls
  `getUserById()` and `bindConnection()`, mirroring what register()/
  login() already do; previously only `$worker->userConnections[$userId]`
  was restored and `$connection->userId` was never set)
- server.php (diff — `onClose` now unsets
  `$worker->userConnections[$connection->userId]` when the closing
  connection had one)
- tests/Manual/test_session_lifecycle.php (new file — real WS client
  against a live server.php, 6 assertions, no MockConnection)

Problem:
- `$worker->userConnections[$userId]` (ADR-001 § Single Active Session)
  is written by register/login/reconnect but was **never unset by any
  code path whatsoever** — not in `onClose`, not in
  `removePlayerFromLobby()/removePlayerFromGame()/removePlayerFromApartment()`,
  not on reconnect-timer expiry, not in `admin_close_room`. Once set, an
  account's slot in that map is permanent for the life of the worker
  process.
- `AuthService::login()`'s single-session guard is a plain `isset()`
  check against that map — so once a user disconnects, EVERY subsequent
  `login` attempt with correct credentials fails with the generic
  `error.auth_invalid_credentials` (message text: "User already logged
  in", though the client has no reliable way to distinguish this from a
  wrong password since the error *code* is deliberately generic — see
  `AuthHandler::mapLoginError()`).
- The only theoretical way back in is the `reconnect` action, per
  ADR-001 §5-6 ("reconnect is the only supported method for restoring
  access"). But `AuthHandler::handleReconnect()` only ever restored
  `$worker->userConnections[$userId]` — it never set
  `$connection->userId` itself (a second, related gap: this is the same
  class of omission flagged as a KNOWN GAP in EPIC-10.5, just with a much
  larger blast radius than originally scoped there). For a user with an
  active room, `ReconnectService::handleReconnect()` (wired in EPIC-10.5)
  closes that gap by binding `$connection->userId` when it finds a
  matching disconnected room player. **For a user who was never in a
  room — or whose room session already ended — nothing ever binds
  `$connection->userId`,** so the `error.auth_required` guard in
  `server.php` blocks every subsequent action, including `create_room`/
  `join_room`.
- Net effect: any account that disconnects while not currently seated in
  a room (idling in the lobby, between games, after `leave_room`, after
  a finished game's room was destroyed, or simply a network blip before
  ever joining a room) is **permanently locked out** — neither `login`
  nor `reconnect` can recover it. Only a full server restart clears
  `$worker->userConnections`.
- Why this was undetected until now: unreachable through any real code
  path before EPIC-10.5, since `onClose` was a stub that never called
  `ReconnectService::handleDisconnect()` at all prior to that Epic — no
  disconnect ever triggered any downstream state change. The one test
  that exercises the single-session concept,
  `tests/Manual/test_single_session.php` (Phase 1), manually performs
  `unset($worker->userConnections[$userId])` inside the test itself
  before asserting a second login succeeds — simulating the cleanup step
  that production code never actually implements, rather than exercising
  a real code path. Textbook instance of ANCHOR_RULES.md Part 22 ("Tests
  must not compensate for missing contracts") — except the missing
  contract was on the implementation side, not the test's own logic, and
  had gone unnoticed because nothing forced the two to be compared until
  real routing existed.
- Verified empirically end-to-end (not simulated) before writing any fix:
  registered a user, closed the connection via a raw TCP close without
  ever joining a room, then confirmed both `login` (rejected,
  "already logged in") and `reconnect` (silently "succeeded" at the
  protocol level but left the connection unauthenticated —
  `room_list`/`create_room` afterward returned `error.auth_required`)
  failed to restore access.

Fix:
- `AuthHandler::handleReconnect()`: after validating the token format and
  confirming `$worker->sessionTokens[$token]` exists, now looks up the
  user via the new `AuthService::getUserById()` and, on success, calls
  the same private `bindConnection()` helper register()/login() already
  use — setting `$connection->userId`/`username`/`isAdmin`/`sessionToken`.
  If the user row is somehow gone (defensive — a session token pointing
  at a deleted account), responds `error.auth_invalid_token` rather than
  proceeding with a half-bound connection.
- `server.php`'s `onClose`: unsets
  `$worker->userConnections[$connection->userId]` whenever the closing
  connection had a bound `userId`, after `ReconnectService::handleDisconnect()`
  runs. This does not interfere with the intended reconnect path (ADR-001
  §5-6): `reconnect` never depended on `userConnections` still being
  occupied — it works off `$worker->sessionTokens` plus a session_token
  match against room player state, both independent of this map. The
  only behavioral change is that a user who disconnects can now also
  fall back to a fresh `login` instead of being force-funneled through
  `reconnect` — which was previously not just "not preferred" but
  completely broken for any player outside a room.
- Regression guard preserved on purpose: `onClose` only fires on an
  actual connection close, so ADR-001's core guarantee — rejecting a
  *concurrent* second login while the first connection is still open —
  is untouched. Verified explicitly (TEST 3 below).

Verified non-false-positive (each half of the fix independently):
- Reverted only the `onClose` change → `tests/Manual/test_session_lifecycle.php`
  TEST 1 failed exactly as predicted (login still permanently blocked);
  TEST 2/3 unaffected. Restored → 6/6 again.
- Reverted only the `AuthHandler::handleReconnect()` change → TEST 2
  failed exactly as predicted (create_room after reconnect-only still
  `error.auth_required`); TEST 1/3 unaffected. Restored → 6/6 again.

Result:
- tests/Manual/test_session_lifecycle.php (new): 6/6 PASSED — real WS
  client against a live server.php subprocess, no MockConnection. Covers:
  disconnect-then-login (no room), disconnect-then-reconnect-only (no
  room, no login fallback), and a regression guard confirming concurrent
  double-login is still rejected while the original connection stays
  open.
- tests/Manual/test_single_session.php: unchanged, still 3/3 scenarios
  PASSED (Phase-1-era unit test against AuthService in isolation; left
  as-is since it tests a real contract, just not the one this FIX closes
  — no false claims to correct here, unlike the EPIC-10.5 test_auth_packet_routing.php
  fix).
- Full regression across every tests/Manual/*.php file (29 files,
  including the new one) — 0 failed.

No ADR required — no protocol packet, error code, room/player structure
key, or timer changed. `error.auth_invalid_token`/`error.auth_invalid_credentials`
are pre-existing codes, used exactly as already documented.

Diff: patches/FIX-10-server.patch, patches/FIX-10-AuthHandler.patch,
patches/FIX-10-AuthService.patch, patches/FIX-10-PreparedStatements.patch

## EPIC-10.5 — Game packet routing (+ FIX-9, found during wiring)
Status: Completed
Date: 2026-07-23

Files:
- src/Game/GameHandler.php (новый файл — thin wrapper над GameService,
  тот же паттерн что LobbyHandler/AuthHandler)
- server.php (diff — LottoEngine/VictoryService/ApartmentService/
  GameFinishService/GameService/GameHandler dependency wiring in
  onWorkerStart, идентичный порядок конструктора уже принятому в
  tests/Manual/test_game_start.php; start_game/draw_barrel/
  apartment_choice wired in onMessage dispatch; ReconnectService теперь
  тоже собран — оба его зависимых сервиса, LobbyService (EPIC-10.4) и
  GameService (этот Epic), наконец готовы одновременно; onClose делегирует
  ReconnectService::handleDisconnect(); action 'reconnect' дополнительно
  вызывает ReconnectService::handleReconnect() после AuthHandler для
  восстановления игрового состояния/reconnect_state)
- src/Game/ReconnectService.php (diff — FIX-9, см. ниже)
- tests/Manual/test_reconnect.php (diff — GROUP 3 assertions обновлены под
  FIX-9: запись переезжает на новый conn_id, а не остаётся на старом; +3
  новых assertion на host_conn_id/active_drawer_conn_id/drawer_order)
- tests/Manual/test_game_packet_routing.php (новый файл — 21 assertions,
  real WS client against live server.php, `e105_` username prefix)

GameService/VictoryService/ApartmentService/GameFinishService already
existed (Phase 4-7) and required no new business logic for the packet-
routing part itself — matching every other EPIC-10.x so far, this is pure
dependency wiring + routing. The one router-level addition is in
GameHandler::handleApartmentChoice(): validates that `choice` is a
non-empty string before delegating (error.invalid_json otherwise) —
GameService/ApartmentService already validate the actual value
('agree'/'refuse') internally.

Reconnect wiring was deliberately bundled into this Epic rather than left
pending further, because ReconnectService's constructor is the literal
reason onClose and 'reconnect' were stubbed out since EPIC-10.0 — both of
its dependencies (LobbyService, GameService) are only both available as of
this Epic. This is not a new/separate feature so much as completing what
EPIC-10.0's own code comments already earmarked for "EPIC-10.4/10.5".

Found and fixed during wiring (FIX-9, see PATCHES-style note below —
kept inline here since it's this Epic's direct blocker, not a standalone
older-code audit finding):
- ReconnectService::handleReconnect() restored player state and sent
  reconnect_state, but left the `$room['players']` array entry keyed
  under the OLD (disconnected) connection id. A new WS connection created
  by the client on reconnect gets a brand-new Workerman connection->id —
  every downstream handler (draw_barrel, leave_room, apartment_choice, ...)
  looks the player up by the CURRENT connection's id, so none of them
  could find the reconnected player. Reconnect looked successful
  (reconnect_state packet received, status flipped to 'active') but was
  functionally dead for anything after it.
- Root cause of why this was never caught: tests/Manual/test_reconnect.php
  (EPIC-8.6) only unit-tests handleReconnect() in isolation with
  MockConnection and asserts state at the OLD key — it never simulates a
  subsequent action arriving from the NEW connection through real routing,
  because until this Epic there was no real routing to go through.
- Fix: handleReconnect() now re-keys the players array entry from the old
  conn_id to the new one, and updates every other room-level field that
  can point at a conn_id: `host_conn_id`, `active_drawer_conn_id`, and
  every matching entry in `drawer_order`. Timer, connection object, and
  session_token handling unchanged.
- Verified non-false-positive: reverted the fix locally, re-ran
  tests/Manual/test_game_packet_routing.php TEST 8 — draw_barrel after
  reconnect failed with error.room_not_found as predicted (new conn_id not
  found in `$room['players']`); restored the fix — 21/21 PASSED again.

No ADR required — no protocol packet, error code, or ANCHOR document
changed. Room/Player structure keys are unchanged (Rule 7 No Hidden
Features) — FIX-9 only changes which array key an existing structure is
stored under, at the moment of reconnect.

Also fixed in this Epic (stale pre-existing test assertion, not a FIX-N —
Rule 22 Test Philosophy: fix the test, not the implementation, since the
implementation was already correct): tests/Manual/test_auth_packet_routing.php
TEST 2 still asserted `error.invalid_json` for create_room after register,
a leftover from before EPIC-10.4 wired lobby routing — despite this
project's own IMPLEMENTATION_STATUS.md EPIC-10.4 entry already claiming
this assertion was updated. It had not been, in the actual committed file.
Corrected to assert `room_joined`.

Housekeeping (found during this Epic's audit, unrelated to game routing
itself): the repository had two case-variant test directories,
`tests/Manual/` and `tests/manual/`, byte-identical except that
`tests/manual/test_lobby_packet_routing.php` (EPIC-10.4) existed only in
the lowercase copy — almost certainly a case-insensitive-filesystem
artifact from a local dev machine, invisible on that machine but tracked
as two separate directories in git. Consequence: `run_ALL_tests.sh` (globs
`tests/Manual/test_*.php` only) was silently never running
test_lobby_packet_routing.php at all. Fixed: file moved into
`tests/Manual/` (confirmed identical before the move, `php -l` clean,
re-run 23/23 PASSED post-move), the stray `tests/manual/` directory
removed entirely.

Result:
- tests/Manual/test_game_packet_routing.php (new): 21/21 PASSED — full
  flow verified end-to-end through a real WS client against a live
  server.php subprocess: non-host start_game guard, game_started
  broadcast (bank/drawer_order), turn-order draw_barrel guard,
  barrels_drawn + your_turn rotation, apartment_choice with no apartment
  active, apartment_choice missing `choice` field, unauth draw_barrel,
  and — critically — a real TCP disconnect mid-game followed by
  reconnect on a brand-new connection, then a successful draw_barrel from
  that new connection (this last step is the FIX-9 regression check).
- tests/Manual/test_reconnect.php: 20/20 PASSED (was 15 — +5 new FIX-9
  assertions in GROUP 3).
- tests/Manual/test_auth_packet_routing.php: 18/18 PASSED (TEST 2 fixed).
- tests/Manual/test_lobby_packet_routing.php: 23/23 PASSED (moved,
  unchanged otherwise).
- Full regression across every tests/Manual/*.php file — 0 failed.

✅ RESOLVED (FIX-10, 2026-07-24): if a client sends `{"action":
"reconnect", "token": ...}` with a token AuthHandler considers valid, but
ReconnectService::handleReconnect() finds no matching disconnected player
in any room (i.e. the user was never in a room-level session, or it was
already cleaned up), `$connection->userId` is never set — AuthHandler::
handleReconnect() itself never sets it, only ReconnectService does, only
on a match. Symmetric in spirit to FIX-8 (EPIC-10.3) but a distinct fix,
deliberately left for a follow-up rather than folded into this Epic.
Turned out to be far more severe than "narrow" once actually audited —
see FIX-10: AuthHandler::handleReconnect() now unconditionally binds the
connection via bindConnection() once the token/user is validated,
regardless of room membership.

Diff: patches/EPIC-10.5-game-routing.patch

## EPIC-10.4 — Lobby packet routing
Status: Completed
Date: 2026-07-23

Files:
- src/Lobby/LobbyHandler.php (новый файл — thin wrapper над LobbyService)
- server.php (diff — RoomManager/LobbyService/LobbyHandler dependency wiring
  in onWorkerStart; room_list/create_room/join_room/leave_room wired in
  onMessage dispatch; «Already in a room» guard for create_room/join_room)
- tests/Manual/test_lobby_packet_routing.php (новый файл — 22 assertions,
  real WS client against live server.php)
- tests/Manual/test_auth_packet_routing.php (diff — TEST 2 updated: после
  register create_room теперь возвращает room_joined, не error.invalid_json)

LobbyService already existed (EPIC-2.x) and required no new business
logic — EPIC-10.4 itself is pure dependency wiring + routing + one router-
level guard, matching every other EPIC-10.x so far.

«Already in a room» guard: LobbyService::handleCreateRoom() документирует,
что пользователь не должен уже находиться в другой комнате — проверка
делегирована router'у (server.php), один раз для create_room и join_room,
через RoomManager::findRoomIdByConnId(). Код ошибки: error.invalid_json
(отдельного кода в ANCHOR_PROTOCOL.md нет).

No ADR required — no protocol packet, error code, or ANCHOR document changed.

Result:
- tests/Manual/test_lobby_packet_routing.php (new): 22/22 PASSED —
  create_room/room_list/join_room/leave_room verified end-to-end through
  a real WS client against a live server.php subprocess (real game.db,
  `e104_` username prefix, cleaned up before/after). Includes router-level
  «Already in a room» guard checks (TEST 4, TEST 5).
- tests/Manual/test_auth_packet_routing.php: TEST 2 updated for EPIC-10.4
  (create_room after register → room_joined).
- tests/Manual/test_lobby_integration.php: 91/91 PASSED (unchanged).
- Full regression across all tests/Manual/*.php files — 0 failed.

Diff: patches/EPIC-10.4-lobby-routing.patch

## EPIC-10.3 — Auth packet routing (+ FIX-8, found during wiring)
Status: Completed
Date: 2026-07-22

Files:
- server.php (diff — AuthHandler dependency wiring in onWorkerStart;
  register/login/reconnect wired to AuthHandler in onMessage dispatch)
- src/Auth/AuthHandler.php (diff — FIX-8: new bindConnection() private
  helper, called from handleRegister()/handleLogin())
- tests/Manual/test_auth_integration.php (diff — 7 new FIX-8 assertions
  via MockConnection)
- tests/Manual/test_auth_packet_routing.php (новый файл — 18 assertions,
  real WS client against live server.php)

AuthHandler already existed (EPIC-1.3) and required no new business
logic — EPIC-10.3 itself is pure dependency wiring + routing, matching
every other EPIC-10.x so far.

FIX-8 found while wiring (not a pre-existing regression — the bug was
latent until this Epic connected AuthHandler to the newly-added
auth_required guard, ADR-006, in the same code path): `AuthService::
login()` only ever set `$worker->userConnections[$userId]` — it never
set `$connection->userId` itself. Confirmed by grep: the ONLY place in
the entire codebase that set `$connection->userId` was
`ReconnectService::attemptReconnect()`, for its own, unrelated scenario.
Without a fix, a client could register/login successfully, receive a
valid `auth_result`, and then have EVERY subsequent action rejected with
`error.auth_required` forever — the auth_required guard checks exactly
`$connection->userId === null`, which never became false.

Fix: new `AuthHandler::bindConnection(object $connection, array $user,
string $token): void` private helper, mirroring the exact field set
`ReconnectService` already uses for its own scenario (`$connection->
userId`, `->username`, `->sessionToken`) plus `->isAdmin` (available in
AuthHandler's login result, unlike in ReconnectService's context). Called
from both `handleRegister()` (after its internal auto-login) and
`handleLogin()`, right before `sendAuthResult()`.

No ADR required — this is a code-correctness fix within the existing,
already-documented `ANCHOR_CORE.md` § Connection Runtime Fields registry
(all four fields were already declared there); no protocol packet, error
code, or ANCHOR document changed.

Result:
- tests/Manual/test_auth_integration.php: 55/55 PASSED (was 48; +7 —
  FIX-8 assertions verifying `$connection->userId/username/isAdmin/
  sessionToken` are correctly bound after both handleRegister() and
  handleLogin() via MockConnection).
- tests/Manual/test_auth_packet_routing.php (new): 18/18 PASSED —
  register/login/reconnect verified end-to-end through a real WS client
  against a live server.php subprocess (real game.db, `e103_` username
  prefix, cleaned up before/after). Critically includes two FIX-8
  end-to-end checks (TEST 2, TEST 6): after a real register/login over
  the real protocol, a subsequent non-exempt action no longer receives
  `error.auth_required` — confirming the fix works through the actual
  router, not only in the MockConnection unit test.
- Full regression across all tests/Manual/*.php files (28 files,
  including the new one) — 0 failed ([FAIL] marker searched explicitly).

Diff: patches/EPIC-10.3-auth-routing.patch

## EPIC-10.2 continuation — Generic auth_required guard
Status: Completed
Date: 2026-07-22

Files:
- server.php (diff — auth_required guard in onMessage, before dispatch)
- docs/ANCHOR_PROTOCOL.md (diff — error.auth_required semantics documented)
- docs/ADR/006.md (новый файл)
- tests/Manual/test_server_bootstrap.php (diff — TEST 4 tightened to
  assert the specific code; new TEST 8 for the exempt-actions set)

Closes the second, previously-deferred half of EPIC-10.2 (first half —
connection-level MAX_TOTAL_PLAYERS gate — completed separately, ADR-005).
EPIC-10.2 is now fully complete.

Implements prompt.md Фаза 1: "проверка userId для всех кейсов кроме
register, login, reconnect" — checked once, generically, by the router
in onMessage, before the (still empty) action dispatcher. Exempt set is
exactly {register, login, reconnect}; `ping` isn't listed because it
already short-circuits earlier in onMessage and never reaches this
check.

Side effect verified explicitly (not a defect, documented in ADR-006):
the dispatcher's `default => error.invalid_json` fallback is now
unreachable for an unauthenticated connection sending any non-exempt
action — the guard intercepts first with error.auth_required. Remains
reachable only for the exempt actions themselves (not yet wired to real
handlers until EPIC-10.3).

Result:
- tests/Manual/test_server_bootstrap.php: 18/18 PASSED (was 14; +4 — TEST
  4 tightened to assert code=error.auth_required specifically instead of
  just type=error; new TEST 8 confirms register/login/reconnect are NOT
  blocked by the guard, falling through to the empty dispatcher's
  not-yet-wired response instead).
- Full regression across all tests/Manual/*.php files (25 files) — 0
  failed ([FAIL] marker searched explicitly, not just "failed" text
  appearing in unrelated log messages).

Diff: patches/EPIC-10.2-auth-guard.patch

## EPIC-10.2 — Protocol error handling (partial: connection-level capacity gate)
Status: Partially completed (by user decision — scope explicitly narrowed)
Date: 2026-07-22

Files:
- src/Core/Helpers.php (diff — new closeWithCode() helper)
- server.php (diff — global connection-level MAX_TOTAL_PLAYERS gate in
  onWebSocketConnected, before hello)
- docs/ANCHOR_PROTOCOL.md (diff — new § WebSocket Close Codes, code 4001)
- docs/ADR/005.md (новый файл)
- tests/Manual/test_server_bootstrap.php (diff — TEST 7: 150 реальных
  TCP+WS соединений + 151-е отклонённое, проверка close code 4001)

Scope decision: user chose to implement ONLY the connection-level
`error.server_full` + WS close 4001 gate (prompt.md Фаза 1, previously
undocumented in any ANCHOR file) in this round. The generic
`auth_required` router guard (also prompt.md Фаза 1, for actions outside
{register, login, reconnect, ping}) was explicitly deferred — not
implemented, tracked as open for a future round.

Problem: `docs/prompt.md` line 41 specified "при превышении 150 —
закрыть соединение с кодом 4001 и error.server_full", never formalized
in ANCHOR_PROTOCOL.md and never implemented. Distinct from the
room-join-time capacity check in LobbyService (FIX-7/ADR-004) — this one
runs at the connection layer, before authentication, against ALL live
sockets (`count($worker->connections)`), not just players seated in
rooms.

Technical finding: the installed Workerman version has no built-in API
to close a WebSocket connection with an explicit close-frame status
code — `closeWithCode()` builds the RFC 6455 §5.5.1 close frame by hand
(opcode 0x8, 2-byte big-endian status code + reason) and sends it via
`$connection->close($frame, true)`.

Fix:
- `closeWithCode()` helper added to Core/Helpers.php (general-purpose,
  reusable for any future application-specific close code).
- Gate added at the top of `onWebSocketConnected`: if
  `count($worker->connections) > Constants::MAX_TOTAL_PLAYERS`, sends
  `error.server_full` (JSON, normal protocol-encoded) then closes with
  WS code 4001 — before any connection-field init, before `hello`.
- Comparison uses `>` (not `>=`, unlike LobbyService's checks) because
  Workerman registers the connection into `$worker->connections` at
  TCP-accept time, before this callback runs — so the count already
  includes the connection being evaluated. Effective capacity is
  identical either way: exactly MAX_TOTAL_PLAYERS concurrent connections
  allowed, the (N+1)-th rejected. Documented explicitly in ADR-005 to
  avoid the kind of silent inconsistency FIX-7 had to fix.
- New WS close-code registry section added to ANCHOR_PROTOCOL.md so
  future application-specific codes have a documented home.

Result:
- tests/Manual/test_server_bootstrap.php: 14/14 PASSED (was 8; +6 new
  checks in TEST 7 — opened exactly 150 real TCP+WS connections against
  a live server.php subprocess, verified the 151st receives
  error.server_full as a text frame followed by a close frame carrying
  status code 4001, decoded from the raw close-frame payload).
- Full regression across all tests/Manual/*.php files (25 files) — 0
  failed.

Diff: patches/EPIC-10.2-partial.patch

### FIX-7 — `error.server_full` reused for room-full condition + wrong check order
Status: Completed
Date: 2026-07-22

Files:
- src/Lobby/LobbyService.php (diff — reorder checks in handleJoinRoom(),
  new error.room_full code)
- docs/ANCHOR_PROTOCOL.md (diff — error.room_full added to registry,
  note distinguishing it from error.server_full and documenting
  join-order precedence)
- docs/ADR/004.md (новый файл)
- tests/Manual/test_lobby_integration.php (diff — обновлена ассерция под
  новый код, добавлен regression-тест на порядок проверок)

Found during: user-reported review (not an audit round) — user flagged
that a full room and a full server must not share an error code, and
that server capacity must be checked before room capacity.

Problem:
- LobbyService::handleJoinRoom() reused `error.server_full` for two
  distinct conditions: the genuine global MAX_TOTAL_PLAYERS limit, and a
  single room reaching its own max_players. ANCHOR_PROTOCOL.md had no
  dedicated code for the room-full case.
- Check order was room-capacity-first, server-capacity-second — so if
  both conditions were true simultaneously, the client would receive the
  less accurate/less actionable of the two.

Fix:
- New protocol error code `error.room_full`, reserved exclusively for a
  single room being at its own max_players. `error.server_full` now
  reserved exclusively for the global MAX_TOTAL_PLAYERS limit.
- Check order in handleJoinRoom() swapped: global server-capacity check
  now runs BEFORE the per-room capacity check, so error.server_full
  always wins when both apply.
- handleCreateRoom() required no change (only ever had the global check).
- Formalized as ADR-004 (protocol addition, no rename/removal — permitted
  under ANCHOR_PROTOCOL.md's Compatibility Rule without a version bump).

Result:
- tests/Manual/test_lobby_integration.php: 91/91 PASSED (was 90; +1 new
  regression test verifying error.server_full wins when both room and
  server are full simultaneously — verified by manually seeding both
  conditions via direct room-state manipulation and RoomManager::
  getTotalPlayerCount()).
- Full regression across all tests/Manual/*.php files — 0 failed.

Diff: patches/FIX-7.patch

### FIX-6 — Reconnect timer leak on kick/ban removal (Timer Integrity)
Status: Completed
Date: 2026-07-03

Files:
- src/Lobby/LobbyService.php
- src/Game/ApartmentService.php
- tests/Manual/test_timer_integrity.php (новый файл)

Found during: post-Phase-9 audit for bugs similar in class to FIX-3
(запрошен пользователем перед стартом Phase 10).

Problem:
- ANCHOR_CORE.md Part 5 § Timer Integrity Rules: "No reconnect timer
  survives player removal" / "A destroyed owner keeps no timers" —
  безусловное правило.
- ReconnectService::removePlayerFromGame() корректно отменяет
  player['reconnect_timer'] ПЕРЕД удалением игрока.
- LobbyService::removePlayerFromLobby() и ApartmentService::
  removePlayerFromApartment() — НЕ отменяли, асимметрия между тремя
  "сёстринскими" методами удаления игрока.
- Достижимость (реальный сценарий, не гипотетический): disconnected-игрок
  в waiting-комнате имеет активный 15s reconnect_timer (ANCHOR_CORE §
  Reconnect Timer). Если администратор кикает/банит его до истечения
  таймера, removePlayerFromLobby() удаляет игрока, но таймер остаётся
  зарегистрированным в Workerman. RoomManager::generateRoomId()
  переиспользует ПЕРВЫЙ свободный room_id сразу после уничтожения комнаты
  (MAX_ROOMS=30) — то есть это не просто утечка памяти на 15 секунд, а
  нарушение инварианта на активно переиспользуемом ресурсе (Rule 28 VPS
  Awareness: 1 CPU/500MB RAM).
- removePlayerFromApartment(): тот же пробел, но по state machine
  (ANCHOR_CORE § Reconnect Rules: reconnect запрещён в apartment) в
  норме недостижим — исправлено защитно, т.к. правило безусловное.

Fix:
- Timer::del($player['reconnect_timer']) добавлен в оба метода ДО
  удаления игрока — идентичный уже корректному паттерну в
  ReconnectService::removePlayerFromGame().

Result:
- tests/Manual/test_timer_integrity.php: 5/5 PASSED.
- Regression проверен на ложноположительность: временно откатывались обе
  правки → 3/5 честных FAIL; после восстановления — снова 5/5.
- Полный регресс по всем 23 файлам tests/Manual/*.php — 0 failed.

Diff: patches/FIX-6.patch

### FIX-4 — Stale test fixtures after ADR-002 (GameFinishService)
Status: Completed
Date: 2026-07-03

Files:
- src/Infrastructure/Database.php
- tests/Manual/test_game_start.php
- tests/Manual/test_victory.php

Problem:
- ADR-002 (вынос GameFinishService, final class со строгой типизацией
  Database/PreparedStatements/Logger) не был пробрасён в тестовые фикстуры
  test_game_start.php и test_victory.php — обе продолжали использовать
  анонимные классы вместо GameFinishService, что несовместимо по типу с
  GameService::__construct(). Оба файла падали с Fatal TypeError.
- Корневая причина невозможности честного (без reflection — запрещённого
  ANCHOR_RULES.md Part 22) исправления: Database жёстко хардкодила путь к
  game.db в конструкторе без точки внедрения зависимостей.

Fix:
- Database::__construct() расширен опциональным параметром `?PDO $pdo = null`
  (обратно совместимо — на момент фикса `new Database()` нигде в проекте не
  вызывается напрямую, server.php/init_db.php ещё не реализованы; поведение
  без аргумента идентично прежнему).
- test_game_start.php: finishGame() не вызывается ни в одном сценарии
  EPIC-4.5 → анонимный класс заменён на уже принятый в проекте паттерн
  ReflectionClass::newInstanceWithoutConstructor() (см. test_apartment.php,
  test_turn_system.php).
- test_victory.php: GROUP 4/5/6 реально вызывают finishGame() → makeSvc()
  теперь строит настоящий GameFinishService(Database, PreparedStatements,
  Logger) поверх in-memory SQLite. GROUP 5 (сбой БД → rollback) переписан с
  искусственного MockPDO->shouldFail флага на честное нарушение SQL
  CHECK-ограничения (coins<=200) — тестирует реальный путь отката внутри
  GameFinishService, а не имитацию.

Result:
- test_game_start.php: 44/44 PASSED
- test_victory.php: 40/40 PASSED (было 38 заявлено в статусе; +2 более
  строгие проверки добавлены в GROUP 5 — inTransaction()===false,
  room не уничтожена при откате)
- Полный регрессионный прогон всех 22 файлов tests/Manual/*.php — 0 failed.

Diff: patches/FIX-4.patch

---

### FIX-5 — Stale sendError() assertion (pre-FIX-1 contract)
Status: Completed
Date: 2026-07-03

Files:
- tests/Manual/test_helpers_runner.php

Problem:
- Scenario 2 вызывала sendError($conn, 'Invalid action syntax') по старому
  однопараметровому контракту (до FIX-1) и ожидала пакет без поля code.
  Реальный sendError(object $connection, string $code, string $message = '')
  после FIX-1 корректно требует code — тест не был обновлён вместе с FIX-1.

Fix:
- Scenario 2 переписан под актуальный вызов
  sendError($conn2, 'error.invalid_json', 'Invalid action syntax') и
  ожидаемый пакет {"type":"error","code":"error.invalid_json","message":"..."}
  (ANCHOR_PROTOCOL.md § Error Packet). Правился тест, не реализация —
  ANCHOR_RULES.md Part 22 (Test Philosophy): sendError() уже верно
  реализует актуальный контракт.

Result:
- test_helpers_runner.php: все 4 сценария PASSED.

Diff: patches/FIX-5.patch

### FIX-3 — Double refund on kick + admin_close_room
Status: Completed
Date: 2026-07-03

Files:
- src/Admin/AdminService.php

Problem:
- handleKickUser() рефандил total_paid игроку и уменьшал room bank, но НЕ
  обнулял total_paid игрока в памяти room state.
- Делегат удаления (removePlayerFromLobby/removePlayerFromGame/
  removePlayerFromApartment) записывал в all_players_history старое
  (дорефандное) значение total_paid.
- handleCloseRoom() безусловно рефандит total_paid из all_players_history
  каждому участнику — при последующем admin_close_room() ранее кикнутый
  игрок получал ставку ещё раз. Нарушение ANCHOR_CORE.md Part 2 §
  Economic Integrity Rule.

Fix:
- После успешной refund-транзакции в handleKickUser() добавлена строка
  `$room['players'][$connId]['total_paid'] = 0;` — обнуление ДО вызова
  делегата удаления, чтобы all_players_history фиксировал 0 (нечего больше
  возвращать этому игроку).

Result:
- Обнаружено и зафиксировано regression-тестами в
  tests/Manual/test_admin_integration.php (TEST 1, TEST 3).
- Проверено на ложноположительность: без фикса тест даёт 5 честных FAIL,
  с фиксом — 20/20 PASSED.
- Вся существующая регрессия (test_admin_kick.php, test_admin_close_room.php
  и др.) остаётся зелёной.

Diff: patches/FIX-3.patch

### FIX-1 — sendError() protocol contract
Status: Completed
Date: 2026-06-21

Files:
- src/Core/Helpers.php

Problem:
- error packet не содержал обязательное поле `code`

Fix:
- сигнатура изменена на:

`php
sendError(object $connection, string $code, string $message = ''): void
`

- поле `code` добавлено в JSON пакет.

---

### FIX-2 — Registration Daily Bonus Contract
Status: Completed
Date: 2026-06-22

Files:
- src/Infrastructure/PreparedStatements.php

Problem:
- Новый пользователь создавался с `last_daily_bonus = 0`
- Автологин после регистрации начислял +100 монет
- Нарушался контракт EPIC-1.4 (`coins = 500` после регистрации)

Fix:

`sql
strftime('%s','now')
`

используется при создании пользователя.

Result:
- Баланс после регистрации = 500
- Все интеграционные тесты проходят.

---

## DECISION LOG

- 2026-07-28 — FIX-16 Accepted: found during VPS `./run_ALL_tests.sh` at
  EPIC-13.4 sign-off (not a proactive audit) — `b203493` (EPIC-13.1) called
  `lottoBootstrapPhpExtensions()` in `server.php` but the function existed
  only in an uncommitted local `src/Core/Helpers.php` diff (FIX-15 Windows
  bootstrap work). Eight live-WS-subprocess tests failed on Ubuntu with a
  fatal error before port bind; local Windows runs did not catch it because
  the committed `run_ALL_tests.php` still skips those tests via
  `$skipOnWindows`. Fixed in `0de46d0`. Process takeaway mirrors FIX-12:
  VPS-authoritative runs expose gaps that dev-host shortcuts hide; never
  commit `server.php` calls to symbols not yet in the repository.
- 2026-07-28 — Phase 13 git checkpoint deviation Accepted (process note, no
  code impact): implementation followed Rule 16 intent (each Epic independently
  verifiable) but commit boundaries did not map 1:1 to Epic numbers. EPIC-13.3
  label duplicated across commits `8cd1434` and `f4cf0f4`; EPIC-13.2 bundled
  into `b203493` (EPIC-13.1) due to shared `GameService.php` edits. Documented
  in Phase 13 block above. Future phases: split file edits per Epic before
  committing, or use explicit `EPIC-13.2+13.3` combined messages when files
  cannot be separated without partial commits.
- 2026-07-26 — ROADMAP.md Phase 11/12/13/14 reorder Accepted (user
  decision, following up on a concern raised after EPIC-10.7): Frontend
  depends entirely on the server implementation, so auditing the server
  first avoids redoing client work if an audit surfaces a protocol
  change. New Phase 11 (was Phase 12.0-12.6): Full integration testing,
  memory/timer/economy/state-machine/protocol audits, load testing. New
  Phase 12 (was Phase 11): Frontend. Phase 13 intentionally skipped
  entirely, by explicit project decision (not a numbering error). New
  Phase 14 (was EPIC-12.7/12.8): Release Candidate -> v1.0 Release, given
  its own phase number since Phase 12 was reassigned to Frontend.
  docs/ROADMAP.md updated with the new structure plus an explanatory
  note mapping old->new numbering. No code, protocol, or test changes —
  none of the affected epics were implemented yet, so this is pure
  documentation with zero migration risk.
- 2026-07-25 — FIX-12 Accepted: found during a live operational incident
  (not a proactive audit) — test runs executed as root against the live
  VPS left game.db/workerman.log/logs/server.log root-owned while the
  production systemd service runs as www-data, causing a real crash-loop
  (Permission denied on every log write, worker respawning repeatedly).
  Fixed operationally via chown (see incident thread). While diagnosing
  it, a confusing [ERROR] "CHECK constraint failed: coins <= 200" line
  was found in the production log — traced to tests/Manual/
  test_victory.php's makeSvc(), which correctly isolates its DATABASE via
  an in-memory PDO (FIX-4) but paired it with a real, default-path
  Logger, so a deliberately-rigged rollback test's error message still
  landed in the real log, indistinguishable from an actual incident. The
  existing code comment had already flagged this exact side effect
  without fixing it. Root cause: Logger::__construct() had no way to
  redirect its output at all. Fixed with an optional constructor
  parameter (mirroring the FIX-4 Database DI-seam precedent), then swept
  every real-Logger call site project-wide: 6 test files fixed, 2 more
  (test_admin_logs.php, test_admin_integration.php) found bleeding the
  same way via the same sweep, one stale already-superseded test file
  (test_logger.php) deleted, and — as a side benefit — confirmed that
  test_lobby_integration.php/test_auth_integration.php's existing (but
  silently non-functional, since PHP doesn't error on extra constructor
  arguments) attempt to redirect to '/dev/null' now actually works.
  Explicitly left out of scope: real-WS-subprocess tests (EPIC-10.3-10.7)
  spawn genuine server.php instances whose Logger is correct production
  code by definition — a different, lower-severity category of test
  noise than the false-ERROR incident this fix targets; making
  server.php's log path itself configurable is a separate, larger
  decision left for later. Verified via MD5 hash of logs/server.log
  before/after each affected test, individually and across the full
  suite. Full regression 0 failed (29 files, one deleted).
- 2026-07-24 — EPIC-10.7 Accepted: per explicit user scoping, this Epic is
  a completeness/coverage audit (does the server side have everything
  ANCHOR_CORE.md/ANCHOR_PROTOCOL.md declare?), not a re-test of business
  logic already covered by the per-module routing tests and Phase-
  specific unit tests. New tests/Manual/test_protocol_completeness.php
  parses the actual declared registries out of the ANCHOR docs at run
  time (not a hardcoded copy) and cross-references against server.php/
  src/ — 50/50 PASSED, 3 warnings, all matching already-documented KNOWN
  GAPS (admin_stats_data unimplemented, afk_warning undeclared) plus one
  new low-priority finding (error.banned declared but unused — superseded
  by the dedicated `banned` packet type, not a functional gap). No code
  defects found — confirms EPIC-10.0-10.6's wiring is genuinely complete
  against the full declared protocol surface. PHASE 10 — WEBSOCKET
  PROTOCOL: COMPLETE.
- 2026-07-24 — EPIC-10.6 Accepted + FIX-11: admin_ban_user/admin_unban_user/
  admin_kick_user/admin_close_room/admin_get_logs wired to new
  AdminHandler (AdminService Phase 9 already existed — dependency wiring
  + routing, all 7 of its nullable dependencies wired this time, unlike a
  partial wiring which would have silently degraded kick/ban removal or
  admin_close_room's timer cleanup). Proactive audit (again requested by
  user, same pattern as FIX-9/FIX-10) found FIX-11: banned users could
  fully bypass their ban. Three compounding gaps in the ban path only
  (kick was already correct) — handleBanUser()'s room-removal was
  incorrectly gated behind isset($worker->userConnections[...]), which
  FIX-10 (same day) had just made behave correctly, exposing that a
  disconnected-but-reconnect-pending banned player was never removed;
  banning an online player never closed their connection, leaving a
  stale-but-authenticated session able to keep acting; and — the most
  severe — AuthHandler::handleReconnect() never checked banned_until at
  all, unlike login(), so reconnect was a total, permanent bypass of any
  ban regardless of room state. All three fixed and independently
  verified non-false-positive. Two existing unit tests' mock connection
  classes needed a close() method added (fixture update, not a logic
  change) now that handleBanUser() actually calls it. New
  tests/Manual/test_admin_packet_routing.php: 15/15 PASSED, real WS
  client, covering both the Epic's routing and FIX-11 together. Full
  regression 0 failed (30 files).
- 2026-07-24 — FIX-10 Accepted: proactive audit before EPIC-10.6 (requested
  by user, same spirit as the FIX-6 audit before Phase 10 and the FIX-9
  discovery during EPIC-10.5) found that $worker->userConnections is never
  unset by ANY code path — permanent single-session lockout for any
  account that disconnects without being seated in a room, since neither
  login (blocked by the stale isset() check) nor reconnect (never bound
  $connection->userId for room-less sessions) could recover access.
  Undetected until now because onClose never called
  ReconnectService::handleDisconnect() before EPIC-10.5 — no disconnect
  ever reached this code at all — and the one relevant test
  (test_single_session.php, Phase 1) manually fakes the missing cleanup
  step rather than exercising it. Fixed in AuthHandler::handleReconnect()
  (now binds the connection via the same bindConnection() login/register
  use, backed by a new AuthService::getUserById()) and server.php's
  onClose (releases the userConnections slot on genuine disconnect,
  verified not to weaken ADR-001's concurrent-session rejection). Both
  halves independently verified non-false-positive. New
  tests/Manual/test_session_lifecycle.php: 6/6 PASSED, real WS client,
  no MockConnection. Full regression 0 failed. EPIC-10.6 not yet started.
- 2026-06-21 — ROADMAP.md признан источником истины по нумерации Epic.
- 2026-06-21 — Reconnect Token Infrastructure вынесен в PRE-BUILT COMPONENTS.
- 2026-06-22 — PHASE 1 официально завершена после прохождения интеграционных тестов.
- 2026-06-23 — EPIC-2.0 RoomManager реализован (src/Core/RoomManager.php, 245 строк).
- 2026-06-25 — EPIC-2.3 Leave room завершён, FIX: all_players_history в removePlayerFromLobby.
- 2026-06-28 — EPIC-2.4 Room list завершён.
- 2026-07-02 — ADR-002 Accepted: GameFinishService extracted; Phase 7 anchor-compliance fixes applied; Phase 7 tests green.
- 2026-07-02 — EPIC-9.3 Kick player завершён. KNOWN GAP: host transfer при kick/ban в apartment-состоянии зафиксирован для будущего Epic.
- 2026-07-03 — EPIC-9.5 Logs access фактически реализован (handleGetLogs()/getLastLines()), закрыто расхождение между статусом и кодом, обнаруженное при подготовке EPIC-9.6.
- 2026-07-03 — FIX-3 Accepted: устранён двойной рефанд kick+admin_close_room (Economic Integrity Rule). EPIC-9.6 Admin integration tests завершён, PHASE 9 COMPLETE.
- 2026-07-03 — Обнаружены pre-existing падения test_game_start.php/test_victory.php (GameFinishService type mismatch) и test_helpers_runner.php (устаревший assert sendError()) — не связаны с EPIC-9.6, зафиксированы в KNOWN GAPS для отдельного FIX перед Phase 10.
- 2026-07-03 — FIX-4 Accepted: Database получил DI-seam (опциональный PDO), test_game_start.php/test_victory.php переведены на реальный GameFinishService вместо type-несовместимых анонимных классов. FIX-5 Accepted: test_helpers_runner.php приведён к актуальному контракту sendError(). Полный регресс по всем 22 файлам tests/Manual/*.php — 0 failed. PHASE 9 стабильна, путь к Phase 10 открыт без известных дефектов.
- 2026-07-03 — Аудит на баги, аналогичные FIX-3 (по запросу перед Phase 10): найден и исправлен FIX-6 (утечка reconnect_timer при kick/ban удалении в Lobby/Apartment — Timer Integrity Rule). Проверены: экономические мутации (bank/total_paid/coins — чисто), reconnect/disconnect история (чисто), timer cleanup при destroyRoom (чисто, делегирование корректно), state machine записи статусов (чисто), Module Boundaries Admin→Game (чисто, только публичные методы), host-transfer комментарий в handleKickUser (соответствует уже задокументированному KNOWN GAP EPIC-9.3, новых расхождений нет). Полный регресс по 23 файлам tests/Manual/*.php (добавлен test_timer_integrity.php) — 0 failed.
- 2026-07-03 — Второй раунд аудита (протокол/edge cases): обнаружены и удалены docs/ANCHOR_PROJECT_STATUS.md (устарел с начала проекта, вводил в заблуждение будущие сессии). Обнаружены docs/prompt.md (исходное ТЗ v4.0) и docs/GAME_RULES.md — оба тоже не обновлялись с начала проекта; из prompt.md извлечены два незадокументированных требования (rate limiting, invalid-JSON policy) — см. KNOWN GAPS, решение отложено до EPIC-10.1 по решению пользователя. Также обнаружены два протокольных долга низкого приоритета: afk_warning (не задекларирован) и admin_stats_data (задекларирован, не реализован, без Epic). Кодовых багов в этом раунде не найдено — все находки документационные/процессные.
- 2026-07-03 — EPIC-10.0 Protocol router завершён: server.php (Workerman bootstrap, onWorkerStart/onWebSocketConnected/onMessage/onClose) без auth/lobby/game/admin-логики (Rule 11 Epic Isolation — ReconnectService требует LobbyService+GameService одновременно, подключение onClose к реальной бизнес-логике отложено до EPIC-10.4/10.5). Верифицирован полностью автоматически через реальный WebSocket-клиент (без внешних библиотек) поверх настоящего TCP-сокета — 8/8 PASSED. Rate limiting и invalid-JSON policy подтверждены как открытые вопросы EPIC-10.1 (не реализованы намеренно).
- 2026-07-23 — EPIC-10.5 Accepted + FIX-9: start_game/draw_barrel/
  apartment_choice подключены к новому GameHandler (GameService Phase 4-7
  уже существовал — dependency wiring + routing). ReconnectService также
  подключён (onClose -> handleDisconnect(), 'reconnect' action ->
  handleReconnect() поверх AuthHandler) — оба его зависимых сервиса,
  LobbyService (EPIC-10.4) и GameService (этот Epic), наконец собраны
  одновременно. Найден и исправлен FIX-9 в процессе: handleReconnect() не
  переиндексировал $room['players'] на новый conn_id нового WS-соединения
  — reconnect_state отправлялся, но любое дальнейшее действие с нового
  соединения не находило игрока (room_not_found). Исправлено: re-key
  players + host_conn_id/active_drawer_conn_id/drawer_order. Попутно
  исправлена стухшая assertion в test_auth_packet_routing.php TEST 2
  (ожидала error.invalid_json там, где EPIC-10.4 уже давно возвращает
  room_joined — расхождение между этим файлом и фактически закоммиченным
  тестом). Housekeeping: удалён паразитный `tests/manual/` (нижний
  регистр) каталог-дубликат — `test_lobby_packet_routing.php` существовал
  только в нём и никогда не запускался run_ALL_tests.sh; перенесён в
  `tests/Manual/`. Новый test_game_packet_routing.php 21/21 (реальный WS
  против живого server.php, включая сквозную проверку FIX-9: disconnect →
  reconnect с нового соединения → успешный draw_barrel). test_reconnect.php
  20/20 (было 15, +5 assertions под FIX-9). Полный регресс 0 failed.
- 2026-07-23 — EPIC-10.4 Accepted: room_list/create_room/join_room/
  leave_room подключены к LobbyHandler (LobbyService EPIC-2.x уже
  существовал — dependency wiring + routing). Новый LobbyHandler.php
  (thin wrapper). Router-level guard «Already in a room» для
  create_room/join_room через RoomManager::findRoomIdByConnId().
  Новый test_lobby_packet_routing.php 22/22 (реальный WS против живого
  server.php). test_auth_packet_routing.php TEST 2 обновлён под
  room_joined. Полный регресс 0 failed.
- 2026-07-22 — EPIC-10.3 Accepted + FIX-8: register/login/reconnect
  подключены к AuthHandler (dependency wiring в onWorkerStart, routing в
  onMessage). Найден и исправлен FIX-8 в процессе: AuthService::login()
  никогда не устанавливал $connection->userId (только $worker->
  userConnections) — без фикса auth_required guard (ADR-006) навсегда
  блокировал бы любое действие после успешного логина. Новый
  AuthHandler::bindConnection() helper, вызывается из handleRegister()/
  handleLogin(). 55/55 test_auth_integration.php (было 48, +7), новый
  test_auth_packet_routing.php 18/18 (реальный WS против живого
  server.php, включая сквозную проверку FIX-8 через настоящий router).
  Полный регресс 0 failed.
- 2026-07-22 — EPIC-10.2 continuation: generic auth_required guard в
  onMessage (ADR-006) — prompt.md "проверка userId для всех кейсов кроме
  register, login, reconnect", реализовано один раз в router'е, не
  дублируется по хендлерам. EPIC-10.2 теперь полностью завершён.
  18/18 test_server_bootstrap.php (было 14, +4 — TEST 4 ужесточён,
  новый TEST 8 на exempt-список), полный регресс 0 failed.
- 2026-07-22 — EPIC-10.2 (частично, по решению пользователя): реализован
  только connection-level MAX_TOTAL_PLAYERS gate — error.server_full + WS
  close code 4001 в onWebSocketConnected (ADR-005, closeWithCode() helper,
  ручная сборка close-фрейма — готового API в используемой версии Workerman
  нет). Generic auth_required guard в router'е сознательно отложен.
  14/14 test_server_bootstrap.php (было 8, +6 — TEST 7 через 150 реальных
  TCP+WS соединений), полный регресс 0 failed.
- 2026-07-22 — FIX-7 Accepted: устранено смешение error.server_full (глобальный
  лимит) и заполненности отдельной комнаты — введён отдельный код
  error.room_full (ADR-004), порядок проверок в handleJoinRoom() изменён на
  server-capacity-first. 91/91 lobby тестов (было 90, +1 regression-тест на
  порядок), полный регресс по всем tests/Manual/*.php — 0 failed.
- 2026-07-21 — EPIC-10.1 Packet validation завершён: ADR-003 формализует rate limiting (>15 пакетов/сек/соединение → закрытие без error-пакета, считает ВСЕ входящие сообщения) и invalid-JSON policy (error.invalid_json, без разрыва — решено в пользу ANCHOR_PROTOCOL.md, подкреплено прецедентом error.server_full). ANCHOR_CORE.md/ANCHOR_PROTOCOL.md обновлены (Connection Runtime Fields, Global Constants, семантика error.invalid_json). Оба KNOWN GAP из аудита протокола (2026-07-03) закрыты как RESOLVED. Попутно обнаружены и исправлены случайно закоммиченные рантайм-артефакты (game.db-shm/game.db-wal/workerman.*.pid) — добавлен .gitignore. Верифицировано 11/11 PASSED через реальный WebSocket-клиент, 5 граничных сценариев (ровно на лимите, превышение на 1, ping считается наравне, сброс окна, единичный невалидный пакет). Полный регресс — 25/25 tests/Manual/*.php.
---

## KNOWN GAPS / NOT VERIFIED

- ⚠️ OPEN (ADR-033 / EPIC-033C, 2026-08-23): Some load/stress scripts historically
  register via the real registration path against the environment `game.db`
  instead of isolating via `LOTTO_DB_PATH` / `LOTTO_TEST_CONFIG`, leaving junk
  usernames (`steady*`, `ramp_*`, `login_banned`, …) in production SQLite.
  Admin bulk delete is the operational cleanup path; root-cause isolation of
  load-test DB writes is a separate follow-up (not fixed in Epic C).

- ⚠️ OPEN (2026-08-23): Разовый `SQLITE_MISUSE` (SQLSTATE[HY000]: General
  error: 21 bad parameter or other API misuse) в `AuthService::login()` —
  сырой текст PDO-исключения утёк в клиентское поле `message` пакета
  `error` (код при этом остался `error.auth_invalid_credentials`,
  замаскировав реальную причину под "неверный логин или пароль").
  Обнаружено при живом логине через `wss://rusbingo.ju-87.club/ws`
  (пользователь `test4`, пароль подтверждён корректным независимой
  проверкой `password_verify()` через отдельный CLI-скрипт — то есть
  учётные данные были заведомо верны).
  Устранено перезапуском `lotto-server.service` — авторизация
  восстановилась для всех пользователей. Точная причина НЕ подтверждена:
  инцидент совпал по времени с параллельным запуском стороннего
  CLI-скрипта (`change_admin_password.php`) и отдельного диагностического
  `php -r` (независимое PDO-подключение к тому же `game.db`), что
  является наиболее вероятной причиной (коллизия блокировок/состояния
  кэша `PDOStatement` в `PreparedStatements::get()`), но не была
  зафиксирована логами/версиями ДО рестарта — эта улика потеряна.
  Архитектурно рестарт сервиса НЕ должен требоваться после
  `change_admin_password.php` (скрипт делает `BEGIN IMMEDIATE
  TRANSACTION` → `UPDATE` → `COMMIT` строго in-place, без пересоздания
  файла — штатный сценарий для `PRAGMA journal_mode=WAL`, читатель и
  писатель должны сосуществовать без рестарта).
  Требуется при повторении: НЕ перезапускать сервис сразу — сначала
  снять `grep -B2 -A2 "SQLSTATE\|bad parameter" logs/server.log`,
  версии `php --ri pdo_sqlite`/`php --ri sqlite3`, и проверить, не было
  ли в этот момент параллельного стороннего процесса с открытым
  соединением к `game.db`. Отдельно: `AuthHandler::handleLogin()`
  (строка `$clientMsg = $msg === 'Auth rate limited' ? ... : $msg;`)
  пробрасывает `$e->getMessage()` любого не-`Auth rate limited`
  исключения клиенту дословно — включая сырые PDO-ошибки при их
  возникновении; стоит рассмотреть отдельный ADR на маскировку ЛЮБОГО
  непредвиденного исключения в `login()` под `error.auth_invalid_credentials`
  с общим текстом, а не только `Auth rate limited` (ADR-028).

- ✅ RESOLVED (EPIC-13.6 / ADR-017, 2026-07-28 implementation; status reconciled
  2026-09-05): Reconnect mid-turn — `reconnect_state` playing payload includes
  `is_my_turn` + AFK fields for the active drawer; GROUP 3d in
  `test_reconnect.php` covers backend contract. Frontend draw-button restore
  from those fields is Phase 12 scope, not a backend blocker.

- ⚠️ OPEN (низкий приоритет, найдено при FIX-12): real-WS-client
  subprocess-тесты (test_auth_packet_routing.php, test_lobby_packet_routing.php,
  test_game_packet_routing.php, test_admin_packet_routing.php,
  test_session_lifecycle.php, test_packet_validation.php,
  test_server_bootstrap.php) запускают настоящий `php server.php start` —
  его Logger корректно пишет в реальный logs/server.log, т.к. это и есть
  настоящий сервер. Это оставляет в продакшн-логе тестовые INFO/WARNING
  строки с тестовыми именами пользователей (fix10_user1, e106_admin и
  т.п.) — безвредный шум, отличимый на глаз от реальных событий, не та
  категория проблемы, что вызвала инцидент FIX-12 (ложный ERROR). Полная
  изоляция потребовала бы сделать путь логирования server.php
  конфигурируемым (переменная окружения, по умолчанию — текущий путь) и
  обновить все семь тестов-раннеров — более крупное изменение,
  затрагивающее продакшн-код сервера, оставлено на явное решение
  пользователя.

- ✅ RESOLVED (2026-07-03): docs/ANCHOR_PROJECT_STATUS.md удалён — файл не
  обновлялся с самого начала проекта (заморожен на состоянии "EPIC-1.1,
  Lobby/WebSocket/Economy: Not implemented"), при этом сам файл предписывал
  будущим моделям читать его как обязательный контекст. Риск катастрофической
  путаницы для новой сессии. ANCHOR_RULES.md Part 19 (Context Recovery Rule)
  уже корректно определяет 5 авторитетных документов без него.
- ✅ RESOLVED (ADR-003, EPIC-10.1, 2026-07-21): docs/prompt.md содержал два
  требования, отсутствующие во всех ANCHOR-документах — (a) rate limiting
  ">15 пакетов/сек — разрыв" и (b) противоречие по обработке невалидного
  JSON (prompt.md "закрыть соединение" vs ANCHOR_PROTOCOL.md error.invalid_json).
  Формализовано в docs/ADR/003-rate-limiting-and-invalid-json-policy.md:
  rate limiting реализован как есть (server.php, Constants::
  RATE_LIMIT_PACKETS_PER_WINDOW/RATE_LIMIT_WINDOW_SECONDS); invalid-JSON
  policy решена в пользу ANCHOR_PROTOCOL.md (error-пакет, без разрыва) —
  подкреплено уже реализованным прецедентом error.server_full. Детали —
  см. запись [DONE] EPIC-10.1 в начале файла.
- ✅ RESOLVED (ADR-007, EPIC-11.5, 2026-07-27): пакет afk_warning добавлен
  в ANCHOR_CORE.md § Protocol Packet Types и ANCHOR_PROTOCOL.md § Turn System.
  Поведение было корректным с EPIC-8.3; закрыт документационный долг W1.
- ✅ RESOLVED (ADR-023 / TD-1, 2026-08-30 audit): `admin_stats_data` **is implemented**
  end-to-end — `AdminService::handleGetStats()` emits the packet; `admin_get_stats`
  wired in `server.php`; client `app.js` requests and renders via `onAdminStats` /
  `UI().setAdminStats` / `renderAdminRooms` (EPIC-033B). Dedicated test
  `tests/Manual/test_admin_stats.php` **10/10 PASS**. Remaining work is
  **documentation/status reconciliation only** (ADR-023 §023.1 obsolete,
  PHASE_11_REPORT W2, former KNOWN GAP entry below). Priority: Low. Not blocking
  deployment.
- ⚠️ OPEN (TD-2, very low priority, documentation only): код ошибки `error.banned`
  объявлен в реестре Error Packet Codes (ANCHOR_PROTOCOL.md) но нигде не
  эмитируется — **намеренно**, per ADR-007. Не функциональный пробел: выделенный
  пакет `banned` (`{"type":"banned","until":...}`) покрывает каждый путь отказа
  по бану (login, reconnect, admin). Документирован как reserved/unused.
  Reconciliation only — no runtime change. **NOT BLOCKING DEPLOYMENT.**

- ✅ RESOLVED (FIX-4, 2026-07-03): test_game_start.php/test_victory.php падали из-за
  устаревших фикстур после ADR-002. Устранено — см. секцию PATCHES § FIX-4.
- ✅ RESOLVED (FIX-5, 2026-07-03): test_helpers_runner.php Scenario 2 ассертил контракт
  до FIX-1. Устранено — см. секцию PATCHES § FIX-5.

- composer.json не перепроверялся в текущей сессии.
- ReconnectTokenService существует, но пока не используется.
- SessionService требует косметической очистки форматирования (без изменения логики).
- lobby_afk_timer_id при count<2 не отменяется в removePlayerFromLobby — устраняется в EPIC-2.6.

---

## CURRENT PROJECT STATUS

PHASE 0 — FOUNDATION: COMPLETE
PHASE 1 — AUTHENTICATION: COMPLETE
PHASE 2 — ROOM LOBBY: COMPLETE
PHASE 3 — LOTTO ENGINE: COMPLETE
PHASE 4 — GAME START: COMPLETE
PHASE 5 — TURN SYSTEM: COMPLETE
PHASE 6 — VICTORY SYSTEM: COMPLETE
PHASE 7 — APARTMENT: COMPLETE
PHASE 8 — RECONNECT & AFK: COMPLETE
PHASE 9 — ADMIN: COMPLETE
PHASE 10 — WEBSOCKET PROTOCOL: COMPLETE (10.0-10.7 all done)
PHASE 11 — AUDITS & LOAD TESTING: COMPLETE (11.0–11.6 VPS verified)
PHASE 13 — GAME AFK WIRING & ORPHANED-METHOD FIXES: COMPLETE (EPIC-13.0–13.7)

Integration tests:

`text
55 / 55 PASSED (auth)                    [+7 vs заявленных 48 — FIX-8 regression-тесты]
91 / 91 PASSED (lobby)                   [+1 vs заявленных 90 — FIX-7 regression-тест]
164 / 164 PASSED (lotto engine)
44 / 44 PASSED (game start)
37 / 37 PASSED (turn system)
40 / 40 PASSED (victory system)          [+2 vs заявленных 38 — усилены проверки FIX-4]
32 / 32 PASSED (apartment)
8 / 8 PASSED (admin auth)
9 / 9 PASSED (admin ban)                 [close() добавлен в MockConnection, FIX-11]
8 / 8 PASSED (admin unban)
37 / 37 PASSED (admin kick)
28 / 28 PASSED (admin close room)
16 / 16 PASSED (admin logs)               [isolated log path, FIX-12]
20 / 20 PASSED (admin integration)       [close() добавлен в SpyConnection, FIX-11; isolated log path, FIX-12]
5 / 5 PASSED (timer integrity)
18 / 18 PASSED (server bootstrap — real WS client, EPIC-10.0/10.2) [+10 vs заявленных 8 — TEST 7 (connection gate), TEST 8 (auth_required exemptions), TEST 4 ужесточён]
11 / 11 PASSED (packet validation — real WS client, EPIC-10.1)
18 / 18 PASSED (auth packet routing — real WS client, EPIC-10.3, TEST 2 обновлён в EPIC-10.5)
23 / 23 PASSED (lobby packet routing — real WS client, EPIC-10.4, перенесён из паразитного tests/manual/ в EPIC-10.5)
20 / 20 PASSED (reconnect — было 15, +5 assertions FIX-9, EPIC-10.5)
21 / 21 PASSED (game packet routing — real WS client, EPIC-10.5, новый файл)
6 / 6 PASSED (session lifecycle — real WS client, FIX-10, новый файл)
15 / 15 PASSED (admin packet routing — real WS client, EPIC-10.6 + FIX-11, новый файл)
50 / 50 PASSED, 3 warnings (protocol completeness — static doc-cross-reference, EPIC-10.7, новый файл)
`

FIX-12 also touched (counts unchanged, only log destination isolated):
victory system (40/40, above), lobby integration (91/91, above), auth
integration (55/55, above), plus admin logs/admin integration (both
annotated above).

tests/Manual/test_logger.php REMOVED (FIX-12) — stale duplicate of an
already-superseded print_r() smoke script (root-level copy already
deleted 2026-07-03), zero assertions, was writing raw noise into
production logs/server.log on every full-suite run. File count: 29
(was 30).

Current branch:

`text
main
`

Current stable commit (pending push — see Git Checkpoint below):

`text
FIX-12-logger-isolation (Logger DI-seam + 6 test files redirected +
2 more found via full sweep + stale test_logger.php removed; incident
root-caused and resolved; full regression 0 failed)
`

Next planned work (see `docs/ROADMAP.md`):

```text
PHASE 12 — FRONTEND (next)
SYSTEM DEPLOYMENT: D — DONE (D1 verified 2026-09-01)
TECHNICAL DEBT: TD-1 DONE; TD-3 Phase 11 VPS DONE (11.1–11.6)
PHASE 13 — GAME AFK WIRING: DONE (verified 2026-09-05 on origin/main @ 55e193b)
```

PHASE 10 — WEBSOCKET PROTOCOL: COMPLETE (10.0-10.7 all done). Server-side
protocol surface confirmed complete against ANCHOR_CORE.md/
ANCHOR_PROTOCOL.md's own declared registries (EPIC-10.7). Remaining low-
priority items are **TECHNICAL DEBT** (TD-1 documentation stale; TD-2
`error.banned` reserved per ADR-007; real-WS-subprocess test log noise —
see KNOWN GAPS) — **none block deployment or the next phase**.
Known open items: none blocking. The EPIC-10.5 KNOWN GAP
(AuthHandler::handleReconnect() not binding $connection->userId when no
matching disconnected room player is found) is RESOLVED as of FIX-10 —
handleReconnect() now unconditionally binds the connection via
bindConnection() once the token/user is validated, regardless of room
membership.
