# Systemd VPS verification checklist (Epic D1)

This document records the **required live Linux/systemd lifecycle verification** for
generic deployment under `deploy/systemd/`. Helper script tests in
`deploy/systemd/tests/run_tests.sh` are necessary but not sufficient.

**Status:** D1 live VPS verification — **DONE** (2026-09-01). Verified on real Linux VPS
`box-963286` (Ubuntu 24.04.4 LTS) via SSH MCP. Full install → health → multi-instance →
update → remove lifecycle passed. Epic D may be marked **DONE**.

Do **not** use the existing production deployment as a destructive test target.

---

## Verification session (2026-09-01)

| Field | Value |
|-------|-------|
| Host | `box-963286` (non-production verification VPS) |
| OS | Ubuntu 24.04.4 LTS, kernel 6.8.0-138-generic |
| Access | SSH MCP, user `cursor-user`, root via `su` for lifecycle scripts |
| Repository | `~/lotto-game` — branch `feature/epic-d-systemd-docs-vps` |
| Commit tested | `09d2cb41e35a7309140e6e9bb7e7d4cd167f9d6f` |
| Test instances | `d-vps-test`, `d-vps-test-2` |

---

## Environment preflight

| Check | Command | Result |
|-------|---------|--------|
| OS | `cat /etc/os-release` | **PASS** — Ubuntu 24.04.4 LTS (Noble Numbat) |
| Kernel | `uname -a` | **PASS** — Linux 6.8.0-138-generic x86_64 |
| systemd | `systemctl --version` | **PASS** — systemd 255 (255.4-1ubuntu8.17) |
| PHP | `php --version` | **PASS** — PHP 8.3.6 (installed during preflight) |
| PHP extensions | `php -m` | **PASS** — sqlite3, pdo_sqlite, pcntl, mbstring, PDO |
| Composer | `composer --version` | **PASS** — Composer 2.7.1 |
| rsync | `rsync --version` | **PASS** — rsync 3.2.7 |
| ss | `ss --version` | **PASS** — iproute2-6.1.0 |
| Git | `git --version` | **PASS** — git 2.43.0 |

---

## Production safety preflight (before any install)

This verification VPS has **no production deployment** (`/opt/lotto-game` absent,
`lotto-server.service` inactive). Baseline captured; production resources not modified.

| Resource | Expected | Verified |
|----------|----------|----------|
| `/opt/lotto-game` | exists, untouched | **N/A** — not present on verification VPS |
| `lotto-server.service` | not stopped/disabled by tests | **PASS** — inactive throughout |
| `www-data` | not removed/modified | **PASS** — `uid=33(www-data)` unchanged |
| Port 8080 | not used by test instances | **PASS** — no listener on `:8080` |

---

## Lifecycle acceptance test

Test instances: `d-vps-test` (port 8081), `d-vps-test-2` (port 8082).

| Step | Command / check | Result |
|------|-----------------|--------|
| Install A | `sudo ./deploy/systemd/install.sh d-vps-test` | **PASS** — port 8081 auto-selected; unit enabled |
| Health A | `sudo ./deploy/systemd/healthcheck.sh d-vps-test` | **PASS** |
| Unit active A | `systemctl status lotto-game-d-vps-test.service` | **PASS** — active (running) |
| WS health A | `healthcheck.php` via install/healthcheck scripts | **PASS** — WebSocket hello verified |
| DB created A | `data/game.db` exists | **PASS** — 20480 bytes |
| Install B (multi-instance) | `sudo ./deploy/systemd/install.sh d-vps-test-2` | **PASS** — port 8082 |
| Health A + B simultaneous | both healthchecks pass | **PASS** |
| Distinct ports/users/roots | compare metadata | **PASS** — 8081/8082, `lotto-d-vps-test` / `lotto-d-vps-test-2`, separate `/opt/lotto-game-*` trees |
| Update A | `sudo ./deploy/systemd/update.sh d-vps-test` | **PASS** |
| DB preserved A | sha256 unchanged across update | **PASS** — `1bd48956…85072` before and after |
| Config preserved A | `config/environment` sha256 unchanged | **PASS** — `8e4a8735…25e44` |
| Port preserved A | metadata port unchanged | **PASS** — 8081 |
| Re-install A (idempotent) | not required (update idempotency covers) | **N/A** |
| Update A ×2 | two consecutive updates | **PASS** — both succeeded; healthcheck pass |
| Port conflict | `install.sh d-vps-portconflict --port 8099` with listener on 8099 | **PASS** — rejected; no partial install dir |
| Production guards | reserved names/8080 rejected | **PASS** — `production`, `www-data`, `lotto-server`, port 8080 all rejected |
| Symlink escape (Linux) | B3 symlink test on real symlinks | **PASS** — `run_tests.sh` 111/111 on VPS |
| Remove B | `sudo ./deploy/systemd/remove.sh d-vps-test-2` | **PASS** — zero-artifact PASS |
| Remove B idempotent | second remove | **PASS** — "already absent" |
| Zero artifacts B | root, unit, metadata, user | **PASS** |
| Remove A | `sudo ./deploy/systemd/remove.sh d-vps-test` | **PASS** — zero-artifact PASS |
| Zero artifacts A | full B3 verification | **PASS** — note: empty `/var/lock/lotto-game-d-vps-test.lock` remains (update lock; not in B3 verify list) |
| Production regression | production resources unchanged | **PASS** — same baseline as preflight |
| Docker regression | `bash deploy/docker/tests/run_tests.sh` | **PASS** — 21/21 (PHP syntax OK after install); integration **SKIP** (Docker not on VPS) |
| Docker coexistence | optional if Docker available | **SKIPPED** — Docker not installed |

---

## Defect found and fixed during D1

| Symptom | Root cause | Fix | Verification |
|---------|------------|-----|--------------|
| Fresh install failed healthcheck immediately after `systemctl restart` (`Connection refused`) | Race: unit active before WebSocket listener ready | Added `lotto_wait_for_instance_healthcheck()` with retry; used in `install.sh` and `update.sh` | Re-run install on VPS **PASS** |

Files changed: `deploy/systemd/lib/common.sh`, `deploy/systemd/install.sh`, `deploy/systemd/update.sh`.

---

## Automated regression (executed on VPS via SSH MCP)

```bash
cd ~/lotto-game && bash deploy/systemd/tests/run_tests.sh
# Systemd deployment tests: 111/111 passed

bash deploy/docker/tests/run_tests.sh
# Results: 21/21 passed, 0 failed, 1 skipped (Docker runtime)

bash -n deploy/systemd/*.sh deploy/systemd/lib/*.sh deploy/systemd/tests/*.sh
# PASS

git diff --check   # on dev checkout — PASS
```

---

## Recording results

D1 mandatory criteria satisfied on real Linux/systemd VPS. Update status docs:

- `docs/IMPLEMENTATION_STATUS.md` — Epic D **Completed**
- `docs/ROADMAP.md` — D row **DONE**
- `docs/ANCHOR_CORE.md` — D1 verified note
