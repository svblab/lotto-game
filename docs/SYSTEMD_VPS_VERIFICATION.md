# Systemd VPS verification checklist (Epic D1)

This document records the **required live Linux/systemd lifecycle verification** for
generic deployment under `deploy/systemd/`. Helper script tests in
`deploy/systemd/tests/run_tests.sh` are necessary but not sufficient.

**Status:** D1 live VPS verification — **NOT RUN** (development host is Windows; no clean
Linux VPS was available during Epic D documentation work). Mark Epic D **partial** until
this checklist is executed and results recorded below.

Do **not** use the existing production deployment as a destructive test target.

---

## Environment preflight (record when run)

| Check | Command | Result |
|-------|---------|--------|
| OS | `cat /etc/os-release` | NOT RUN |
| Kernel | `uname -a` | NOT RUN |
| systemd | `systemctl --version` | NOT RUN |
| PHP | `php --version` | NOT RUN |
| PHP extensions | `php -m` (sqlite, pdo_sqlite, pcntl, mbstring) | NOT RUN |
| Composer | `composer --version` | NOT RUN |
| rsync | `rsync --version` | NOT RUN |
| ss | `ss --version` or `netstat --version` | NOT RUN |

---

## Production safety preflight (before any install)

Confirm production remains protected and is **not** the test target:

| Resource | Expected | Verified |
|----------|----------|----------|
| `/opt/lotto-game` | exists, untouched | NOT RUN |
| `lotto-server.service` | not stopped/disabled by tests | NOT RUN |
| `www-data` | not removed/modified | NOT RUN |
| Port 8080 | not used by test instances | NOT RUN |

Record production unit uptime before/after if practical.

---

## Lifecycle acceptance test

Suggested test instances: `d-vps-a`, `d-vps-b` (or any valid B1 names).

| Step | Command / check | Result |
|------|-----------------|--------|
| Install A | `sudo ./deploy/systemd/install.sh d-vps-a` | NOT RUN |
| Health A | `sudo ./deploy/systemd/healthcheck.sh d-vps-a` | NOT RUN |
| Unit active A | `systemctl status lotto-game-d-vps-a.service` | NOT RUN |
| WS health A | healthcheck.php / client hello | NOT RUN |
| DB created A | `data/game.db` exists | NOT RUN |
| Install B (multi-instance) | `sudo ./deploy/systemd/install.sh d-vps-b` | NOT RUN |
| Health A + B simultaneous | both healthchecks pass | NOT RUN |
| Distinct ports/users/roots | compare metadata | NOT RUN |
| Update A | `sudo ./deploy/systemd/update.sh d-vps-a` | NOT RUN |
| DB preserved A | hash/size unchanged across update | NOT RUN |
| Config preserved A | `config/environment` unchanged | NOT RUN |
| Port preserved A | metadata port unchanged | NOT RUN |
| Re-install A (idempotent) | `sudo ./deploy/systemd/install.sh d-vps-a` | NOT RUN |
| Update A ×2 | two consecutive updates | NOT RUN |
| Port conflict | install with occupied port fails safely | NOT RUN |
| Production guards | reserved names/8080 rejected | NOT RUN |
| Symlink escape (Linux) | B3 symlink test passes on real symlinks | NOT RUN |
| Remove B | `sudo ./deploy/systemd/remove.sh d-vps-b` | NOT RUN |
| Remove B idempotent | second remove succeeds | NOT RUN |
| Zero artifacts B | no root, unit, metadata, user (if owned) | NOT RUN |
| Remove A | `sudo ./deploy/systemd/remove.sh d-vps-a` | NOT RUN |
| Zero artifacts A | full B3 verification | NOT RUN |
| Production regression | `lotto-server.service` still active; `/opt/lotto-game` intact | NOT RUN |
| Docker regression | `bash deploy/docker/tests/run_tests.sh` | NOT RUN |
| Docker coexistence | optional if Docker available | NOT RUN |

---

## Recording results

When verification is performed on a VPS:

1. Fill the **Result** column with `PASS`, `FAIL`, or `SKIPPED` and brief notes.
2. Update `docs/IMPLEMENTATION_STATUS.md` Epic D section to **Completed**.
3. Update `docs/ROADMAP.md` D row to **DONE** with VPS evidence summary.

Until then, Epic D status remains: **D2/D3 documentation complete; D1 VPS verification pending**.
