# EPIC-20 — Production Observability Verification Evidence (G9)

**Gate ID:** G9  
**Date (UTC):** 2026-09-06  
**Operator:** Cursor observability verification session  
**Production domain:** `rusbingo.online`  
**VPS:** `186.246.50.81`  
**Production SHA (runtime):** `b3531d14871f3963c8f4489d85fa1551fbfaf2af` (`b3531d1`)  
**Evidence repo HEAD:** `4039e7a` (docs only; production runtime unchanged)

**Conclusion:** **EPIC-20 COMPLETE — G9 PASS**

---

## 1. Identity

| Field | Value |
|-------|--------|
| EPIC | 20 (G9 observability gate) |
| Gate | G9 |
| Domain | `rusbingo.online` |
| VPS | `186.246.50.81` |
| Audited runtime SHA | `b3531d1` |
| Audit window (UTC) | ~06:36–06:37 |

---

## 2. Baseline

| Check | Result |
|-------|--------|
| `lotto-server` | `active`, `enabled` |
| MainPID | `10186` |
| Workerman workers | **1** (+ 1 master) |
| Listener | `0.0.0.0:8080` (UFW denies public 8080) |
| `nginx` | `active`, `enabled` |
| HTTPS | `200` (`https://rusbingo.online/`) |
| HTTP → HTTPS | `301` |
| WSS smoke | `GET /ws` → `101` in nginx access log; application `hello` (verified in G8/G9 window) |
| `PRAGMA integrity_check` | `ok` (from prior gates; not re-modified) |

---

## 3. systemd / journal

| Check | Result |
|-------|--------|
| `journalctl -u lotto-server` | Available; recent stop/start/restart entries with timestamps |
| Startup visibility | `Started lotto-server.service` entries present |
| Shutdown visibility | `Stopping` / `Stopped` / `Deactivated successfully` present |
| Failure/recovery visibility | G7 kill test visible: `Scheduled restart job, restart counter is at 1` @ 09:26:30 MSK |
| `journalctl --disk-usage` | **10.8M** — healthy |
| `systemctl status lotto-server` | Shows MainPID, CGroup, memory limits, worker processes |

**Result:** **PASS** — operators can see service lifecycle events.

---

## 4. Application / Workerman logging

| Field | Value |
|-------|--------|
| Primary log | `/opt/lotto-game/logs/server.log` |
| Format | `[YYYY-MM-DD HH:MM:SS] [LEVEL] message` |
| Startup event | `LottoGameServer started (protocol_version=1)` after restarts |
| Auth events | `User registered`, `User login`, `Login failed: Invalid username or password` |
| Room/game events | `Room created`, `Room destroyed`, connection open/close with `conn_id` / `user_id` |
| Warnings | e.g. trusted-proxy sentinel bucket, state-machine warnings |
| Secret-leak scan | `password` substring count **2** — message text only (`Invalid username or password`); **0** `session_token` matches |
| Passwords/tokens in log lines | **not observed** |

Additional audit logs (per code): `logs/economy_audit.log` path exists in codebase; production `server.log` is the operator-facing application log per contract §14.

**Result:** **PASS**

---

## 5. nginx

| Field | Value |
|-------|--------|
| Access log | `/var/log/nginx/access.log` |
| Error log | `/var/log/nginx/error.log` |
| HTTPS requests | Timestamped `GET / HTTP/2.0 200` entries |
| WSS requests | `GET /ws HTTP/1.1 101` entries |
| Upstream failure | G7 window: `connect() failed (111: Connection refused) while connecting to upstream` for `/ws` → `127.0.0.1:8080` |
| Redirect | `HEAD / HTTP/1.1 301` logged |
| Current size | ~48K under `/var/log/nginx` |

**Result:** **PASS** — proxy vs backend failures distinguishable.

---

## 6. WSS observability

| Event | Operator visibility |
|-------|---------------------|
| Successful WSS upgrade | nginx access: status `101`, path `/ws`, timestamp |
| Backend down during G7 | nginx error: upstream connection refused; systemd: service deactivated/restarted |
| Application recovery | `server.log`: `LottoGameServer started` after restart |
| Invalid Origin (G8) | Client receives error; not high-volume logged (acceptable) |

Timestamp correlation example: nginx error @ `09:26:29` MSK aligns with G7 backend gap; systemd restart @ `09:26:30`; app startup log @ `06:26:30` UTC.

**Result:** **PASS**

---

## 7. Error scenarios (harmless)

| Scenario | Client | Server-side visibility |
|----------|--------|------------------------|
| Failed login (G8) | `error.auth_invalid_credentials` | `server.log` WARNING: login failed (generic) |
| Invalid Origin (G8) | `error` / forbidden | No credential leakage |
| Unknown HTTP path | SPA `200` (documented behavior) | nginx access: `200` with bytes sent |
| Unauthenticated WS action | `error.auth_required` | Connection / auth logs |

**Result:** **PASS**

---

## 8. Recovery observability (G7 cross-reference)

Existing G7 evidence is sufficient; no additional restart performed for G9.

| G7 event | Observable evidence |
|----------|---------------------|
| Scenario A restart | journal: stop/start pairs @ 09:26:09 MSK |
| Scenario B kill | journal: `Deactivated` → `Scheduled restart job` @ 09:26:25–30; nginx upstream error; app restart log |
| Scenario C nginx restart | journal nginx stop/start @ 09:27:08 |
| Scenario D stop/start | journal @ 09:27:11–13 |

**Result:** **PASS**

---

## 9. Disk / log sanity

| Check | Result |
|-------|--------|
| `df -h /` | 8.7G total, **23%** used (6.7G avail) |
| `server.log` | ~10 KB |
| `/opt/lotto-game/logs/` | ~24 KB total |
| `/var/log/journal` | ~11 MB |
| Uncontrolled growth | **none observed** |
| logrotate for `server.log` | **not installed** on VPS (`/etc/logrotate.d/lotto-game` absent) — runbook recommends; see P3 |

**Result:** **PASS** for current capacity; rotation is a future ops task.

---

## 10. Incident diagnosis exercises

### Incident A — “Did lotto-server restart and recover?”

**Answer: Yes.** `journalctl -u lotto-server` shows exact stop/start timestamps (e.g. G7 kill: deactivated 09:26:25, scheduled restart 09:26:30, started). `systemctl status` confirms `active (running)` with MainPID and worker CGroup. `server.log` shows `LottoGameServer started` after recovery.

### Incident B — “HTTPS works but WSS fails — nginx or backend?”

**Answer: Distinguishable.** nginx error log records upstream `Connection refused` to `127.0.0.1:8080` for `/ws` when Workerman is down. HTTPS static paths can still return `200` from nginx. systemd/journal and `server.log` indicate backend process state separately.

### Incident C — “Auth/Origin rejection without credential exposure?”

**Answer: Yes.** Failed logins log generic message only (no password/token values). Origin rejections do not log secrets. Scan: 0 `session_token` strings in `server.log`.

---

## 11. Findings

| ID | Severity | Finding | Evidence | Release impact |
|----|----------|---------|----------|----------------|
| G9-01 | P3 | `logrotate` for `server.log` not configured on VPS | runbook §7 example; `no_lotto_logrotate` on host | Non-blocking; recommend before long-term ops |
| G9-02 | P3 | nginx access log does not record WebSocket `Origin` header | `/var/log/nginx/access.log` format | Diagnosis relies on app errors + proxy status |
| G9-03 | P3 | Not every WS connection logged in `server.log` | by design (noise reduction) | Acceptable for V1.0 contract |

**No P0/P1/P2 observability findings identified.**

---

## 12. Changes

```
No application/runtime/configuration architecture changes were made.
```

No service restart was performed solely for G9 (G7 journal evidence reused).

Only this evidence document was added to the repository.

---

## 13. Gate status

| Gate | Status |
|------|--------|
| **G9** | **PASS** |
| G0–G8 | PASS (unchanged) |
| G10–G11 | **unchanged** |

### Acceptance criteria

| Criterion | Result |
|-----------|--------|
| A. Service visibility | PASS |
| B. Restart/failure visibility | PASS |
| C. Workerman visibility | PASS |
| D. nginx visibility | PASS |
| E. WSS diagnosis | PASS |
| F. Timestamp correlation | PASS |
| G. Error visibility | PASS |
| H. Secret safety | PASS |
| I. Log availability | PASS |
| J. Disk sanity | PASS |
| K. No production drift | PASS |

---

## 14. References

- [`docs/RELEASE_CONTRACT_V1.md`](RELEASE_CONTRACT_V1.md) §14
- [`docs/ADMIN_VPS_DEPLOY.md`](ADMIN_VPS_DEPLOY.md) §7–9
- [`docs/EPIC_20_RECOVERY.md`](EPIC_20_RECOVERY.md)
- [`docs/EPIC_20_SECURITY.md`](EPIC_20_SECURITY.md)
