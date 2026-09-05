# EPIC-14.1 — DOCKER DEPLOYMENT FIX

**Date:** 2026-09-05  
**Branch:** `feature/epic-14-1-fix-docker-volume-permissions`  
**Fix commit:** `36282a6`  
**VPS:** `box-963286` (`186.246.50.81`)

---

## 1. Baseline

| Item | Value |
|------|-------|
| Base commit | `2c699086f6c10af3cfc497199eb585576a6630c9` (`origin/main`) |
| Branch | `feature/epic-14-1-fix-docker-volume-permissions` |
| VPS | `box-963286` / `186.246.50.81` |
| Docker version | 29.8.0 (Compose v5.5.1) |
| PHP version (host) | 8.1.2 (Ubuntu 22.04 `apt`) |
| PHP version (container) | 8.4.25 (`php:8.4-cli-bookworm`) |
| Initial failure reproduced | **YES** — `init_db.php` / `unable to open database file` before fix |

---

## 2. Root cause

Docker creates fresh **named volumes** with mount point `/app/data` owned **`root:root`** mode `755`.
`deploy/docker/compose.yaml` runs the application as **`user: "1000:1000"`** with `read_only: true`.
`install.sh` invoked `compose run … init_db.php` as uid 1000 against that root-owned directory;
`is_writable('/app/data')` is **false**, so SQLite cannot create `game.db`.

---

## 3. Fix

| Item | Detail |
|------|--------|
| Files changed | `deploy/docker/lib/common.sh`, `deploy/docker/install.sh`, `deploy/docker/tests/run_tests.sh` |
| Mechanism | New `lotto_prepare_data_volume()` — one-shot **root** container: `chown 1000:1000 /app/data && chmod 750 /app/data` before `init_db.php` |
| Security properties | App still runs as uid 1000; data dir `750` (not world-writable); no permanent root service; no `chmod 777` |
| Why minimal | Fixes only volume bootstrap; no Dockerfile/compose architecture change; no `init_db.php` change |

---

## 4. Local validation

Local Windows host has no Docker in agent environment. Validation performed on VPS and via `deploy/docker/tests/run_tests.sh` (includes `test_data_volume_permissions` + live `install.sh` integration).

---

## 5. Clean VPS validation

| Check | Result |
|-------|--------|
| Fresh Docker state | **PASS** — removed `lotto-default` instance/volumes before install |
| `install.sh` | **PASS** — `deploy/docker/install.sh --name default --port 8080 --bind 127.0.0.1` |
| Volume ownership | **PASS** — `/app/data` → `1000:1000 750`; `game.db` → `1000:1000 644` |
| Database creation | **PASS** — `game.db` present in `lotto-default-data` |
| Container health | **PASS** — `healthy` |
| Application startup | **PASS** — Workerman on `127.0.0.1:8080` |

```text
Docker clean deployment: PASS
```

---

## 6. Functional verification

| Gate | Result | Evidence |
|---|---|---|
| Register | PASS | `ws_emulator` → `auth_result success:true` |
| Login | PASS | `ws_emulator` → `auth_result success:true` |
| Invalid login | PASS | `error.auth_invalid_credentials` |
| Duplicate registration | PASS | `error.auth_username_taken` on re-register |
| Create room | PASS | `room_joined` via `ws_emulator --replay` |
| Join/leave | UNVERIFIED | Not exercised on Docker instance |
| Game creation | UNVERIFIED | |
| First turn | UNVERIFIED | |
| `your_turn` | UNVERIFIED | |
| Barrel draw | UNVERIFIED | |
| Turn rotation | UNVERIFIED | |
| AFK | UNVERIFIED | |
| Apartment | UNVERIFIED | |
| Reconnect | UNVERIFIED | |
| Game completion | UNVERIFIED | |
| Persistence | PASS | User `dock_u5` in volume DB after `compose restart` |
| Restart | PASS | `compose restart app` → healthcheck PASS |
| Backup | UNVERIFIED | No Docker backup runbook |
| Restore | UNVERIFIED | No Docker backup runbook |
| Security | PASS | Container uid 1000; `/app/data` mode 750; bind `127.0.0.1:8080` only |

---

## 7. Regression

```text
bash deploy/docker/tests/run_tests.sh → 29/29 passed, 0 failed, 0 skipped
php run_ALL_tests.php (host PHP 8.1.2, Docker on :8080) → 45/59 test files passed
```

Docker container PHP: **8.4.25**. Host PHP 8.1.2 failures are the separate PHP 8.2+ standalone-type issue documented in sequential verification — **not modified in this task**.

---

## 8. Documentation

| File | Change |
|------|--------|
| `docs/EPIC_14_1_DOCKER_DEPLOYMENT_FIX.md` | This report |
| `docs/EPIC_14_1_SEQUENTIAL_DEPLOYMENT_VERIFICATION.md` | Follow-up section (Docker re-verify) |
| `docs/IMPLEMENTATION_STATUS.md` | EPIC-14.1 status updated |

---

## 9. Remaining issues

### Docker defects

None blocking clean-volume install after this fix.

### Documentation gaps

- No Docker SQLite backup/restore runbook (unchanged).
- `ADMIN_VPS_DEPLOY.md` does not pin PHP ≥ 8.2 for Ubuntu 22.04 `apt` packages.

### Environment limitations

- No operator test domain → HTTPS/TLS/WSS/browser **UNVERIFIED**.
- Full live gameplay on Docker instance **UNVERIFIED**.

### Unrelated findings

- Host `run_ALL_tests.php` **45/59** on PHP 8.1.2 when Docker binds `:8080` (vs **51/59** native-only session).

---

## 10. ADR

```text
ADR required: NO
Reason: Implementation defect within accepted ADR-036 Docker deployment; volume bootstrap only.
```

---

## 11. DoD

| Item | Status |
|------|--------|
| Protocol unchanged | PASS |
| Minimal deploy-script fix | PASS |
| Fresh volume startup gate | PASS |
| App not permanently root | PASS |
| `/app/data` not world-writable | PASS |
| Production TLS/WSS/browser | UNVERIFIED |
| Docker backup/restore | UNVERIFIED |
| PR #11 / v1.0 tag | N/A (not merged/tagged) |

---

## 12. Release decision

```text
V1.0 — READY WITH UNVERIFIED GATES
```

Docker clean deployment now passes. Native smoke previously passed. Production HTTPS/WSS/browser, full live gameplay, Docker backup docs, and PHP 8.1 host regression gap remain unverified/unaddressed.

---

## 13. Commit / PR

- **Commit:** `36282a6`
- **PR:** (to be created)
- **Merge status:** NOT merged
- **Ready for review:** YES

---

## 14. Next smallest action

Next smallest action: Provide a test domain and run production HTTPS/WSS/browser E2E on the native `ADMIN_VPS_DEPLOY.md` path, or document PHP ≥ 8.2 in `ADMIN_VPS_DEPLOY.md` and re-run host regression on PHP 8.3+.
