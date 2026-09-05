# EPIC-14.1 — Final Production Verification

**Date:** 2026-09-05  
**Branch:** `feature/epic-14-1-final-production-verification`  
**Base commit:** `2c699086f6c10af3cfc497199eb585576a6630c9` (`origin/main`)  
**VPS:** `box-963286` (`186.246.50.81`)  
**Domain:** **Not supplied by operator**

---

## 1. Scope

Controlled final verification of the **canonical production** deployment per
`docs/ADMIN_VPS_DEPLOY.md` (HTTPS `:443`, WSS `/ws`, browser E2E).

This session did **not** perform TLS/WSS/browser verification because the
blocking prerequisite (real domain) is missing. No hostname, DNS record,
certificate, or browser E2E results were invented.

---

## 2. Preconditions evaluated

| Prerequisite | Status |
|--------------|--------|
| `origin/main` verified | `2c699086f6c10af3cfc497199eb585576a6630c9` |
| PR #14 (Docker volume fix) | **OPEN** — not merged (`https://github.com/svblab/lotto-game/pull/14`) |
| PR #11 (v1.0 sign-off) | **OPEN** — not merged (per instructions) |
| Operator test/production domain | **NOT PROVIDED** |
| `v1.0` tag | Not created (per instructions) |

### PR #14 note

Final **native** production gates do not require PR #14 (Docker-only fix).
Evidence from PR #14 branch (`feature/epic-14-1-fix-docker-volume-permissions`) is
**not** treated as merged to `main`. Docker re-verification on `main` remains
pending merge of PR #14.

### Domain blocker

Per task instructions: without a real domain, TLS/WSS/browser verification **must not**
proceed. Let's Encrypt, trusted WSS, and browser mixed-content checks cannot be
executed on bare IP `186.246.50.81` per `ADMIN_VPS_DEPLOY.md` §3.6.

---

## 3. VPS baseline (read-only, 2026-09-05)

| Item | Observed |
|------|----------|
| OS | Ubuntu 22.04.5 LTS, 1 CPU, ~539 MiB RAM |
| PHP (host) | 8.1.2 (`apt`) |
| `/opt/lotto-game` | **Absent** |
| `lotto-server.service` | **Inactive / absent** |
| nginx | **Active** (default install from prior sessions) |
| TLS certificates | **None** (`/etc/letsencrypt/live/` empty) |
| Public listeners | `:22` only (no `:80`/`:443` for lotto) |
| Docker | `lotto-default-app` healthy on `127.0.0.1:8080` (PR #14 branch evidence; not canonical production) |
| UFW | Inactive |

**Clean canonical production baseline:** **FAIL** — no native production deployment;
Docker dev instance present from prior sequential/fix sessions.

---

## 4. Deployment (canonical native)

| Item | Result |
|------|--------|
| Runbook | `docs/ADMIN_VPS_DEPLOY.md` |
| Deployment performed | **NO** — blocked by missing domain (certbot/TLS prerequisite) |
| systemd | **UNVERIFIED** |
| HTTPS / TLS | **UNVERIFIED** |
| WSS `/ws` | **UNVERIFIED** |
| Browser E2E | **UNVERIFIED** |

Prior evidence (feature branches, not on `main`):

- Native HTTP smoke on same VPS without domain — `docs/EPIC_14_1_SEQUENTIAL_DEPLOYMENT_VERIFICATION.md` (branch `feature/epic-14-1-sequential-deployment-verification`, PR #13)
- Docker fix — `docs/EPIC_14_1_DOCKER_DEPLOYMENT_FIX.md` (branch `feature/epic-14-1-fix-docker-volume-permissions`, PR #14, **not merged**)

---

## 5. Evidence matrix

| Gate | Result | Evidence |
|------|--------|----------|
| Clean VPS baseline (canonical production) | **FAIL** | No `/opt/lotto-game`; Docker on `:8080` only |
| Canonical native deployment | **UNVERIFIED** | Not deployed — no domain |
| systemd service | **UNVERIFIED** | |
| HTTPS | **UNVERIFIED** | No domain; no certs |
| TLS certificate | **UNVERIFIED** | |
| WSS `/ws` | **UNVERIFIED** | |
| Browser E2E | **UNVERIFIED** | |
| Authentication (production) | **UNVERIFIED** | |
| Lobby | **UNVERIFIED** | |
| Gameplay | **UNVERIFIED** | |
| AFK | **UNVERIFIED** | |
| Apartment early finish | **UNVERIFIED** | |
| Reconnect | **UNVERIFIED** | |
| Persistence | **UNVERIFIED** | |
| Restart | **UNVERIFIED** | |
| Backup/Restore (production native) | **UNVERIFIED** | Procedure exists in runbook; not executed this session |
| Security (production TLS model) | **UNVERIFIED** | |
| Regression suite (this session) | **N/A** | No native deployment on VPS this session |

---

## 6. Findings

### BLOCKERS

1. **No operator test/production domain** — mandatory for HTTPS, TLS, WSS, browser E2E.
2. **Canonical native production not deployed on VPS** at verification time.
3. **Mandatory production gates remain UNVERIFIED** — cannot classify V1.0 as READY.

### DEFECTS

None newly identified in this session (verification not executed).

### DOCUMENTATION GAPS

- Docker backup/restore runbook still absent (from prior audits).
- `ADMIN_VPS_DEPLOY.md` does not pin PHP ≥ 8.2 (Ubuntu 22.04 `apt` ships 8.1).

### ENVIRONMENT LIMITATIONS

- VPS has only IP `186.246.50.81`; no DNS name for Let's Encrypt.
- Host PHP 8.1.2 vs PHP 8.2+ test syntax (separate from TLS gates).

### NON-BLOCKING FINDINGS

- PR #14 Docker volume fix verified on feature branch; merge to `main` pending.
- Prior native HTTP smoke (no TLS) documented on PR #13 branch.

---

## 7. ADR

```text
ADR required: NO
Reason: Verification-only session; no architecture or protocol changes. Blocked by
missing operator infrastructure (domain), not by undocumented design choices.
```

---

## 8. DoD

| Item | Status |
|------|--------|
| Protocol unchanged | N/A (no code changes) |
| Production HTTPS verified | **UNVERIFIED** |
| Production WSS verified | **UNVERIFIED** |
| Browser E2E verified | **UNVERIFIED** |
| Canonical native deploy on VPS | **UNVERIFIED** |
| Evidence recorded without secrets | PASS |
| PR #11 not merged / v1.0 not tagged | PASS |

---

## 9. Release decision

```text
V1.0 — BLOCKED
```

Mandatory production gates (HTTPS, TLS, WSS, browser E2E, live gameplay on
canonical deployment) are **UNVERIFIED** because no domain was supplied and
canonical native production was not deployed in this session.

`V1.0 — READY WITH UNVERIFIED GATES` is **not** used — the documented release
DoD does not permit treating mandatory production gates as optional for final
V1.0 sign-off.

---

## 10. Operator prerequisites to unblock

1. **Provide a test/production domain** with DNS A-record → `186.246.50.81`.
2. **Clean VPS** for canonical native deploy: remove Docker test instance if
   using same host (`deploy/docker/remove.sh --name default --yes`), then follow
   `ADMIN_VPS_DEPLOY.md` end-to-end including certbot.
3. **Merge PR #14** if Docker on `main` is required before tag (optional for
   native-only final gate).
4. Re-run this verification checklist with browser access to `https://<domain>/`.

---

## 11. Commit / PR

- Documentation-only branch from `origin/main`
- PR: (to be created)
- Merge: not requested
- Tag `v1.0`: not created

---

## 12. Next smallest action

Next smallest action: Operator registers a domain (or subdomain), points DNS A-record
to `186.246.50.81`, and provides the hostname so certbot + WSS + browser E2E can run
per `ADMIN_VPS_DEPLOY.md`.
