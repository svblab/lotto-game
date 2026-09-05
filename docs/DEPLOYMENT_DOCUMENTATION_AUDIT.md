# Deployment & Documentation Audit

**Date:** 2026-09-05  
**Branch:** `feature/epic-14-1-deployment-docs-audit`  
**Base:** `origin/main` @ `2c699086f6c10af3cfc497199eb585576a6630c9`  
**Scope:** Read-only audit; no VPS or runtime changes.

---

## 1. Baseline

- **Branch:** `feature/epic-14-1-deployment-docs-audit`
- **Base:** `2c699086f6c10af3cfc497199eb585576a6630c9` (`origin/main`, PR #10)
- **HEAD:** (audit commit on branch)
- **Working tree:** clean after doc commit
- **Starting commit:** `2c69908`

---

## 2. Deployment architecture

### Canonical production

**Existing single-instance native deployment:**

- Path: `/opt/lotto-game`
- Service: `lotto-server.service` (manual unit, `www-data`)
- Stack: PHP 8 + Workerman + SQLite + **nginx/Caddy** TLS termination (ADR-027)
- Public: HTTPS `:443`, WSS at `/ws` → upstream `127.0.0.1:8080`
- **Authoritative runbook:** `docs/ADMIN_VPS_DEPLOY.md`

Evidence: ADR-027, ADR-036 §Context, ADR-037 §Decision, `README.md` deployment table,
`docs/LOCAL_ENVIRONMENT.md` header.

This is **not** the generic `deploy/systemd/` installer (different paths, unit names,
and guards).

### systemd

Two distinct concepts — **both intentional:**

| Concept | Role | Evidence |
|---------|------|----------|
| `lotto-server.service` | Canonical **production** unit for `/opt/lotto-game` | `ADMIN_VPS_DEPLOY.md` §3.5 |
| `deploy/systemd/` | **Supported alternative** — multi-instance generic native deploy (`/opt/lotto-game-<name>/`, `lotto-game-<name>.service`) | ADR-037, Epics B1–D DONE, `deploy/systemd/README.md` |

Generic systemd is **not** a replacement for the existing production model; ADR-037
explicitly preserves `ADMIN_VPS_DEPLOY.md` production unchanged.

### Docker

**Supported alternative deployment** for a **fresh** VPS (ADR-036 Accepted).

- Entry: `deploy/docker/` (`install.sh`, `remove.sh`, `compose.yaml`, `Dockerfile`)
- Multi-instance via Compose project `lotto-<name>`
- SQLite on named Docker volume; stdout logging; reverse proxy/TLS external (ADR-027 pattern)
- **Does not** migrate or replace existing `/opt/lotto-game` production

Docker is **not** canonical production, **not** abandoned, **not** dev-only.

### Development/local

- `php server.php start` / `scripts/start_server.php` (`docs/LOCAL_ENVIRONMENT.md`)
- Windows dev with `run_ALL_tests.php` and optional live WS on port 18080
- Phase 11 audit harnesses on VPS (`/tmp/lotto-game-*`, manual Workerman) — **not** a documented production model

### VPS reality (`box-963286` / `186.246.50.81`)

Read-only evidence (EPIC-14.1 session, 2026-09-05):

| Item | Observed | vs repository intent |
|------|----------|----------------------|
| `lotto-server.service` | Absent | Production model **not deployed** |
| `/opt/lotto-game` | Absent | Production model **not deployed** |
| nginx / TLS / `:443` | Absent | Production WSS path **not available** |
| Workerman on `:8080` | Running from `/tmp/lotto-game-criteria-reframe` | **Dev/audit residue** (Phase 11.6) |
| `~/lotto-game` | Present, stale commit `6498144` | Not the active runtime |
| Docker | Not verified this audit (SSH intermittent) | — |

**Conclusion:** Current VPS state is **development/audit residue**, not proof of
canonical production architecture.

---

## 3. Evidence

| Source | Finding |
|--------|---------|
| ADR-036 (Accepted) | Docker = optional supported path; production native unchanged |
| ADR-037 (Accepted) | `deploy/docker/` vs `deploy/systemd/` separation; three deployment concepts |
| ADR-027 (Accepted) | TLS at reverse proxy; WSS via `/ws` |
| `README.md` | Three-model table (production / generic systemd / Docker) |
| `deploy/systemd/README.md` | Three-model comparison table; lifecycle scripts DONE |
| `docs/LOCAL_ENVIRONMENT.md` | Docker § + generic systemd references |
| `docs/ADMIN_VPS_DEPLOY.md` | Production runbook only — **no Docker/generic systemd** (gap, now cross-linked) |
| `docs/ROADMAP.md` SYSTEM DEPLOYMENT | Epics A–D DONE |
| Git `faace31` | ADR-036 Docker Compose added |
| Git `7d1748a` | ADR-037 deploy path separation |
| Git `b527ef1`…`f2c9cfb` | Generic systemd B2–D + VPS verification |

**Chronology:** Native production documented first → Docker added as alternative (ADR-036)
→ deploy paths separated (ADR-037) → generic systemd multi-instance implemented (B1–D).
**Both** Docker and generic systemd are **intentionally supported** alongside production
runbook — not a migration from one to the other.

---

## 4. Documentation classification

| Document | Classification | Authority | Action |
|----------|----------------|-----------|--------|
| `docs/ADMIN_VPS_DEPLOY.md` | AUTHORITATIVE / PRODUCTION | **Yes** (single-instance production) | **Updated** — add deployment-models cross-ref (this audit) |
| `docs/LOCAL_ENVIRONMENT.md` | DEVELOPMENT + DEPLOYMENT | High (env, tests, Docker/systemd ops) | Keep; already documents alternatives |
| `docs/ADR/027-reverse-proxy-tls-termination.md` | ARCHITECTURE | Yes (TLS/WSS) | Keep |
| `docs/ADR/036-docker-compose-deployment.md` | ARCHITECTURE | Yes (Docker alternative) | Keep |
| `docs/ADR/037-deployment-mode-separation.md` | ARCHITECTURE | Yes (deploy layout) | Keep; note B1–D now DONE (follow-up text in ADR body still says "future" in §Context — minor staleness) |
| `deploy/systemd/README.md` | AUTHORITATIVE (generic systemd) | Yes | Keep |
| `deploy/docker/*` | AUTHORITATIVE (Docker) | Yes | Keep; no README.md file (gap: operators use LOCAL_ENVIRONMENT § Docker) |
| `docs/SYSTEMD_VPS_VERIFICATION.md` | TESTING / RELEASE evidence | Episodic (D1 2026-09-01) | Keep as historical verification |
| `README.md` | OVERVIEW | High | Keep; deployment table is correct |
| `docs/ROADMAP.md` SYSTEM DEPLOYMENT | RELEASE / PLANNING | High | Keep |
| `docs/PHASE_11_REPORT.md` | TESTING (VPS audits) | Historical evidence | Keep; labels VPS non-production |
| `docs/EPIC_14_1_REPORT.md` | RELEASE (branch only, not on `main`) | Episodic | On `feature/epic-14-1-v1-release`; not merged |
| `docs/PHASE_14_REPORT.md` | RELEASE | On `main` | Keep |
| `docs/prompt.md` | UNCLEAR / INTERNAL | Low | Out of scope |

---

## 5. Contradictions found

| # | Contradiction | Severity |
|---|---------------|----------|
| 1 | `ADMIN_VPS_DEPLOY.md` silent on Docker/generic systemd while README/ADR-037 document three models | **Material** — fixed by cross-ref table |
| 2 | `ADR-037` §Context says generic systemd "future work" but B1–D are DONE in ROADMAP | **Minor staleness** in ADR narrative; `deploy/systemd/README.md` is current |
| 3 | `ADMIN_VPS_DEPLOY.md` §1 says Workerman on `0.0.0.0:8080`; ADR-027 recommends localhost upstream + firewall deny 8080 public | **Minor** — firewall §3.7 addresses exposure; binding address nuance |
| 4 | VPS `box-963286` used for Phase 11 evidence vs production model in ADMIN_VPS_DEPLOY | **Not a doc bug** — reports label host non-production; drift must not be read as architecture |

No contradiction that Docker **replaces** systemd production — ADRs explicitly forbid that.

---

## 6. ADMIN_VPS_DEPLOY.md assessment

**Authoritative but incomplete** (by scope, not obsolete).

- **Complete** for: single-instance production install, nginx, TLS, WSS, systemd unit template,
  backup cron, restore, restart, security env vars, git pull updates.
- **Incomplete** for: Docker, generic `deploy/systemd/`, multi-instance — **correctly
  delegated** to other docs but was **not cross-linked** until this audit.
- **Does not** cover: rollback beyond git pull guidance, monitoring, health checks
  (generic systemd has `healthcheck.sh`; production runbook uses manual checks §3.8).

---

## 7. ADR decision

```
ADR required: NO
Reason: ADR-036, ADR-037, and ADR-027 already establish the deployment architecture
(three coexisting models). This audit reconciles documentation cross-references only;
no new architectural decision.
```

---

## 8. VPS reset recommendation

```
Recommendation: DEFER
Reason: Deployment architecture is now documented, but reset prerequisites are not met —
no domain/TLS plan chosen for production-style test, PR #11 not merged, v1.0 not tagged.
Current VPS state (dev Workerman in /tmp) is useful diagnostic evidence.
Prerequisites before reset:
  1. Choose target model for test VPS (production runbook vs deploy/systemd demo vs Docker).
  2. Register domain + TLS if testing production WSS path.
  3. Merge release documentation PR(s) and confirm target git ref.
  4. Backup any audit DB if needed (/tmp/lotto-game-criteria-reframe/game.db).
```

---

## 9. Changes made

| File | Change |
|------|--------|
| `docs/ADMIN_VPS_DEPLOY.md` | Added deployment-models cross-reference table after canonical path statement |
| `docs/DEPLOYMENT_DOCUMENTATION_AUDIT.md` | This audit report |

---

## 10. Validation

- `git diff --check` — run on commit
- No application code changed
- No tests required (documentation-only)

---

## 11. DoD (ANCHOR_PROTOCOL process)

| Item | Status |
|------|--------|
| Protocol unchanged | PASS (N/A — docs only) |
| ADR trigger evaluated | PASS |
| Minimal diff | PASS |
| No VPS mutation | PASS |
| No PR #11 merge / v1.0 tag | PASS |
| Evidence-backed conclusions | PASS |

---

## 12. PR status

- Commit on `feature/epic-14-1-deployment-docs-audit`
- PR: to be created
- Merge: **NO**
- Ready for review: YES (after push)

---

## 13. Next smallest action

**Next smallest action:** Reset the disposable test VPS (`box-963286`) and perform a clean deployment using the chosen canonical model (`ADMIN_VPS_DEPLOY.md` production path with domain + nginx, or `deploy/systemd/install.sh demo` for a non-TLS smoke baseline) — after operator selects target model and domain.
