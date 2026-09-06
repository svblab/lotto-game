# EPIC-20 — Production Recovery Verification Evidence (G7)

**Gate ID:** G7  
**Date (UTC):** 2026-09-06  
**Operator:** Cursor operational verification session  
**Production domain:** `rusbingo.online`  
**VPS:** `186.246.50.81`  
**Production SHA:** `b3531d14871f3963c8f4489d85fa1551fbfaf2af` (`b3531d1`)

**Conclusion:** **EPIC-20 COMPLETE — G7 PASS**

---

## A. Identity

| Field | Value |
|-------|--------|
| EPIC | 20 |
| Gate | G7 |
| Domain | `rusbingo.online` |
| VPS | `186.246.50.81` |
| SHA (before/after) | `b3531d1` — unchanged |
| Test window (UTC) | ~06:25–06:27 |

---

## B. Baseline (before recovery scenarios)

| Check | Result |
|-------|--------|
| `git rev-parse HEAD` | `b3531d14871f3963c8f4489d85fa1551fbfaf2af` |
| `git status --short` | clean (detached HEAD) |
| `lotto-server` | `active`, `enabled` |
| `nginx` | `active`, `enabled` |
| Workerman workers | **1** worker process (+ 1 master) |
| Listener | `0.0.0.0:8080` (loopback path via nginx; UFW denies public 8080) |
| HTTPS | `200` |
| WSS `wss://rusbingo.online/ws` + Origin `https://rusbingo.online` | `hello`, `protocol_version=1` |
| `PRAGMA integrity_check` | `ok` |
| Known marker | `epic20_g7marker` id `5`, coins `500` (registered pre-test) |
| Baseline timestamp (UTC) | `2026-09-06T06:26:09Z` |

---

## C. Scenario A — `systemctl restart lotto-server`

| Field | Value |
|-------|--------|
| Action | `systemctl restart lotto-server` |
| Timestamp (UTC) | `2026-09-06T06:26:09Z` |
| Recovery duration | ~3 s |
| `lotto-server` after | `active` |
| Worker count after | **1** |
| `127.0.0.1:8080` / listener | present (PIDs rotated: 9620/9622) |
| HTTPS | `200` |
| WSS | `hello` OK |
| DB integrity | `ok` |
| Known state | `epic20_g7marker` id `5`, coins `500`; login OK |
| Logs | Expected stop/start messages; no fatal errors |
| Result | **PASS** |

---

## D. Scenario B — controlled process failure (MainPID SIGTERM)

| Field | Value |
|-------|--------|
| Action | `kill -TERM` on `lotto-server` MainPID (`9620`) |
| Timestamp (UTC) | ~`2026-09-06T06:26:24Z` |
| Observed behavior | Service `deactivated`; systemd scheduled restart (`restart counter is at 1`) |
| Recovery duration | ~5–6 s to `active` (new MainPID `9667`) |
| Transient WSS during gap | nginx `502` (expected while backend down) |
| `lotto-server` after | `active` |
| Worker count after | **1** |
| HTTPS | `200` (static/nginx; unaffected) |
| WSS after recovery | `hello` OK |
| DB integrity | `ok` (unchanged during failure) |
| Known state | marker present; login OK after recovery |
| Logs | `Scheduled restart job` — **Restart=always** behaved as configured |
| Result | **PASS** |

---

## E. Scenario C — `systemctl restart nginx`

| Field | Value |
|-------|--------|
| Action | `systemctl restart nginx` |
| Timestamp (UTC) | `2026-09-06T06:27:08Z` |
| Recovery duration | ~2 s |
| `nginx` after | `active` |
| `lotto-server` during/after | remained `active`; **1** worker |
| HTTPS | `200` |
| TLS cert | CN=`rusbingo.online`; valid 2026-09-06 — 2026-12-05 |
| WSS `/ws` proxy | `hello` OK |
| DB integrity | `ok` |
| Known state | marker intact; login OK |
| Logs | Normal nginx stop/start; no errors |
| Result | **PASS** |

---

## F. Scenario D (optional) — controlled stop then start

| Field | Value |
|-------|--------|
| Performed | **yes** — safe, unambiguous |
| Action | `systemctl stop lotto-server` → verify `inactive` → `systemctl start lotto-server` |
| Timestamp (UTC) | `2026-09-06T06:27:11Z` |
| Recovery duration | ~5 s |
| Post-checks | Same as Scenario A — all **PASS** |
| Result | **PASS** |

---

## G. Safety

| Check | Result |
|-------|--------|
| `game.db` deleted/corrupted | **no** |
| Schema / code / nginx / TLS / DNS changed | **no** |
| Unrelated processes killed | **no** (only `lotto-server` MainPID in Scenario B) |
| `admin` / `epic17_92c19235` modified | **no** |
| Secrets in evidence | **none** |
| Marker cleanup | `epic20_g7marker` deleted post-test; service healthy |
| VPS reboot | **not performed** |

---

## H. Gate status

| Gate | Status |
|------|--------|
| **G7** | **PASS** |
| G0–G6 | PASS (unchanged) |
| G8–G11 | **unchanged** (not evaluated) |

### Acceptance criteria mapping

| Criterion | Result |
|-----------|--------|
| A. Service recovery | **PASS** |
| B. One Workerman worker | **PASS** (after every scenario) |
| C. Network `8080` available | **PASS** |
| D. HTTPS 200 | **PASS** |
| E. WSS with valid Origin | **PASS** (after recovery; transient 502 only during B gap) |
| F. DB integrity `ok` | **PASS** |
| G. Known state preserved | **PASS** |
| H. Application operation (login) | **PASS** |
| I. nginx recovery | **PASS** |
| J. No SHA/config drift | **PASS** |
| K. No P0/P1 defect | **PASS** |

---

## I. Defects

| ID | Severity | Description |
|----|----------|-------------|
| — | — | None observed |

**Note:** Scenario B produced an expected brief WSS outage (`502`) while systemd restarted the backend — not a gate failure.

---

## J. References

- [`docs/ADMIN_VPS_DEPLOY.md`](ADMIN_VPS_DEPLOY.md)
- [`docs/RELEASE_CONTRACT_V1.md`](RELEASE_CONTRACT_V1.md)
- [`docs/EPIC_19_BACKUP_RESTORE.md`](EPIC_19_BACKUP_RESTORE.md)
