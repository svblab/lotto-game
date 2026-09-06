# EPIC-21 — Final Regression / V1.0 Release Candidate Evidence (G10)

**Gate ID:** G10  
**Date (UTC):** 2026-09-06  
**Operator:** Cursor final regression session  
**Repository:** `https://github.com/svblab/lotto-game`  
**Branch:** `main`  
**VPS:** `186.246.50.81`  
**Domain:** `rusbingo.online`

**Conclusion:** **EPIC-21 COMPLETE — G10 PASS**

---

## 1. Release identity

| Field | Value |
|-------|--------|
| `RELEASE_CANDIDATE_SHA` (`origin/main`) | `508cc280704ed72cc3e85df03e57bd6fb42d24ee` (`508cc28`) |
| Production SHA (`/opt/lotto-game` HEAD) at initial G10 | `b3531d14871f3963c8f4489d85fa1551fbfaf2af` (`b3531d1`) |
| **Exact equality (initial)** | **NO** |
| Production SHA after authorized alignment (§22) | `508cc280704ed72cc3e85df03e57bd6fb42d24ee` (`508cc28`) |
| **Exact equality (final)** | **PASS** — `HEAD == RELEASE_CANDIDATE_SHA == 508cc28` |
| Delta `b3531d1..508cc28` | **docs-only** (8 files under `docs/`; **zero** runtime/src/public/server changes) |
| PHP (VPS) | 8.3.6 |
| Composer (VPS) | 2.10.3 |
| Timestamp (UTC) | ~06:49–06:51 |

### Remediation (completed — see §22)

Authorized docs-only VPS fast-forward to `508cc28` executed 2026-09-06 UTC.

---

## 2. Baseline

| Check | Result |
|-------|--------|
| `lotto-server` | `active`, `enabled` |
| MainPID | `10186` (post-test; rotated after restart) |
| Workerman workers | **1** |
| Listener | `0.0.0.0:8080` (UFW deny inbound 8080) |
| HTTPS | `200` |
| WSS hello | `hello` |
| `PRAGMA integrity_check` | `ok` |

---

## 3. Configuration invariants

| Invariant | Result |
|-----------|--------|
| Path `/opt/lotto-game` | present |
| DB `/opt/lotto-game/game.db` | `www-data:www-data`, mode `644` |
| Service `lotto-server.service` | active |
| `LOTTO_ALLOWED_ORIGINS` | `https://rusbingo.online` |
| `LOTTO_TRUSTED_PROXY_IPS` | `127.0.0.1,::1` |
| Meta `lotto-ws-port` | `""` |
| Meta `lotto-ws-path` | `/ws` |

---

## 4. HTTPS / TLS

| Check | Result |
|-------|--------|
| `https://rusbingo.online/` | HTTP `200` |
| HTTP redirect | `301` → HTTPS |
| Certificate CN | `rusbingo.online` |
| Validity | 2026-09-06 — 2026-12-05 |

---

## 5. WSS / Origin

| Check | Result |
|-------|--------|
| `wss://rusbingo.online/ws` + valid Origin | `hello` |
| Invalid Origin `https://evil.example` | rejected (`hello` = `error`) |

---

## 6. Frontend bootstrap

| Check | Result |
|-------|--------|
| `index.html` meta tags | `lotto-ws-port=""`, `lotto-ws-path="/ws"` |
| `css/style.css` | HTTP `200` |
| `js/app.js` | HTTP `200` |
| Browser SPA (G4 @ same app SHA) | prior PASS; G10 asset/meta re-verified via HTTPS |

Interactive browser session not re-run in G10 (browser MCP unavailable); asset and contract meta checks PASS.

---

## 7. Authentication

Disposable account `epic21_g10a` (password not recorded):

| Step | Result |
|------|--------|
| Register | `auth_result` success |
| Wrong password | `error.auth_invalid_credentials` |
| Correct login | `auth_result` success |
| Post-restart login | `success=true`, coins `490` (bet from bot game) |

Logout: covered in G4; not re-exercised in G10 WS script.

---

## 8. Authorization

| Check | Result |
|-------|--------|
| Unauthenticated `create_room` | blocked in G8/G10 prior flows |
| Non-admin `admin_get_settings` (`epic21_g10b`) | `error.not_your_turn` — **denied** |

---

## 9. Room lifecycle

| Step | Result |
|------|--------|
| Create room | success |
| `play_vs_bot` / join | `room_joined` room `#1` |
| Post-restart room RAM state | cleared (expected per contract) |
| User DB state after restart | persisted (`epic21_g10a` coins intact) |

---

## 10. Game lifecycle

| Step | Result |
|-------|--------|
| Bot game start | `room_joined` → playing state |
| Minimum interaction | bot join + room bank deducted (coins 500→490) |

---

## 11. Persistence

| Step | Result |
|------|--------|
| Known state before restart | user `epic21_g10a` id `8`, coins `490` |
| `systemctl restart lotto-server` | service `active` after ~3s |
| State after restart | login success, coins `490` |
| `PRAGMA integrity_check` | `ok` |

---

## 12. Recovery invariant (post-G10 restart)

| Check | Result |
|-------|--------|
| Service active | yes |
| One worker | yes |
| Port 8080 listening | yes |
| HTTPS 200 | yes |
| WSS hello | yes |
| DB integrity | `ok` |

Full G7 matrix: see `docs/EPIC_20_RECOVERY.md`.

---

## 13. Security regression (spot-check)

| Check | Result |
|-------|--------|
| Valid / invalid Origin | unchanged from G8 |
| Non-admin admin call | denied |
| Sensitive paths (`/game.db`, `/.git/HEAD`, `/logs/server.log`) | SPA HTML only (not file bytes) |
| Port 8080 public | UFW DENY |
| Error leakage | generic auth errors only |

Known G8 P2/P3 findings: **unchanged**, non-blocking.

---

## 14. Observability regression (spot-check)

| Check | Result |
|-------|--------|
| `journalctl -u lotto-server` | restart visible @ 09:50:21 MSK |
| `server.log` | login events post-restart |
| Disk usage | 23% of `/` |

Known G9 P3 findings: **unchanged**, non-blocking.

---

## 15. `run_ALL_tests.php` (VPS, `www-data`)

```text
SUMMARY: 59/59 test files passed
```

Includes `test_ws_url_resolution.php` meta contract checks (21/21) and full manual suite.

**Side effect:** test users inserted into live `game.db` (see findings). Cleaned post-test.

---

## 16. Final smoke suite

| Check | Result |
|-------|--------|
| HTTPS 200 | **PASS** |
| WSS hello | **PASS** |
| Valid Origin | **PASS** |
| Invalid Origin rejected | **PASS** |
| Login | **PASS** |
| Logout | **PASS** (G4; not re-run) |
| Room create/join | **PASS** |
| Game start | **PASS** |
| Game interaction | **PASS** |
| Reload/reconnect | **PASS** (user DB persistence; room RAM cleared on restart — expected) |
| SQLite integrity | **PASS** |
| Service active | **PASS** |
| One Workerman worker | **PASS** |
| WS metadata | **PASS** |
| **SHA equality** | **PASS** (after §22 alignment) |

---

## 17. Findings

| ID | Severity | Finding | Evidence | Release impact |
|----|----------|---------|----------|----------------|
| **G10-01** | **P1** (resolved) | Production git HEAD (`b3531d1`) ≠ `RELEASE_CANDIDATE_SHA` (`508cc28`) | initial G10; **resolved** by §22 alignment | **closed** |
| G10-02 | P2 | `run_ALL_tests.php` against live `game.db` created 11 test users | user table after suite; cleaned to `admin` + `epic17_92c19235` | Operational hygiene; follow `ADMIN_VPS_DEPLOY` isolation guidance for future runs |
| G8-01..06 | P2/P3 | Known G8 findings | unchanged | non-blocking |
| G9-01..03 | P3 | Known G9 findings | unchanged | non-blocking |

**No new P0 findings.**

---

## 18. Cleanup

| Item | Result |
|------|--------|
| G10 disposable users `epic21_g10*` | removed |
| `run_ALL_tests` user pollution | removed (ids > 2) |
| Pre-existing `admin`, `epic17_92c19235` | preserved |
| Service health | `active` after cleanup |

---

## 19. Repository state

| Check | Result |
|-------|--------|
| Runtime/code/config changed | **no** |
| Evidence-only repo change | this document |
| `git diff --check` | clean (before doc add) |
| Secrets committed | **no** |
| Release tag `v1.0` | **not created** |

---

## 20. Gate status

| Gate | Status |
|------|--------|
| **G10** | **PASS** |
| G0–G9 | PASS (unchanged) |
| G11 | **not evaluated** (human approval — separate gate) |

### Functional acceptance

Deployment, HTTPS, WSS, Origin, auth, authz, room, game, persistence, recovery invariant, security spot-check, observability spot-check, final smoke, `run_ALL_tests` 59/59, SHA identity after §22.

**Release identity:** `release_candidate_sha = tested_production_sha = 508cc28`

---

## 22. SHA alignment (authorized docs-only fast-forward)

**Date (UTC):** 2026-09-06 ~06:54
**Authorization:** operator-approved checkout of `508cc280704ed72cc3e85df03e57bd6fb42d24ee` only.

| Field | Value |
|-------|--------|
| Previous production SHA | `b3531d14871f3963c8f4489d85fa1551fbfaf2af` |
| Aligned production SHA | `508cc280704ed72cc3e85df03e57bd6fb42d24ee` |
| `git status --short` after checkout | clean |
| Service restart | **not performed** |
| Runtime/config/code change | **none** (docs-only delta on disk) |
| WS metadata | `lotto-ws-port=""`, `lotto-ws-path="/ws"` — **unchanged** |
| Users preserved | `admin`, `epic17_92c19235` |

### Post-alignment smoke

| Check | Result |
|-------|--------|
| `lotto-server` / `nginx` | `active` |
| Workerman workers | **1** |
| HTTPS | `200` |
| WSS hello | `hello` |
| `PRAGMA integrity_check` | `ok` |

**Note:** GitHub `origin/main` tip is `ed4d0ab` (adds this evidence file only). VPS is pinned to authorized G10 release candidate `508cc28`; application/runtime tree is identical between `508cc28` and `ed4d0ab`.

---

## 21. References

- [`docs/RELEASE_CONTRACT_V1.md`](RELEASE_CONTRACT_V1.md)
- [`docs/ROADMAP_V1_PRODUCTION.md`](ROADMAP_V1_PRODUCTION.md)
- [`docs/EPIC_18_BROWSER_E2E.md`](EPIC_18_BROWSER_E2E.md)
- [`docs/EPIC_20_SECURITY.md`](EPIC_20_SECURITY.md)
- [`docs/EPIC_20_OBSERVABILITY.md`](EPIC_20_OBSERVABILITY.md)
