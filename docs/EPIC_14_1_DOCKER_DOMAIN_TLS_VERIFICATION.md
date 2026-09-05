# EPIC-14.1 — Docker Domain / TLS Automation Verification

**Date:** 2026-09-05  
**Branch:** `feature/epic-14-1-docker-domain-tls-automation`  
**VPS:** `box-963286` / `186.246.50.81`  
**Domain:** `rusbingo.online` (operator-provided; DNS A → `186.246.50.81`)

---

## 1. Environment

| Item | Value |
|------|-------|
| VPS | `box-963286` |
| IP | `186.246.50.81` |
| Short hostname (`hostname`) | `box-963286` |
| Static hostname (`hostnamectl`) | **`rusbingo.online`** |
| FQDN (`hostname -f`) | `box-963286` (short; **not** used for detection) |
| DNS | `getent hosts rusbingo.online` → `186.246.50.81` |

---

## 2. Existing Architecture (before changes)

| Component | Status |
|-----------|--------|
| Docker (`deploy/docker/`) | App on `127.0.0.1:<port>` per ADR-036 |
| Reverse proxy | **External host nginx** per ADR-027 (not inside container) |
| TLS | **Not automated** in `install.sh` — operator manual per LOCAL_ENVIRONMENT |
| WebSocket | Container plain WS; public `wss://<domain>/ws` via nginx |
| Domain discovery | **None** — `--allowed-origins` manual only |

---

## 3. Domain Detection

### Mechanism implemented

`lotto_detect_provisioning_fqdn()` in `deploy/docker/lib/common.sh`:

1. Reads **`hostnamectl` static hostname** (or `/etc/hostname`)
2. Requires FQDN shape (`label.tld` — contains dot)
3. Does **not** map `box-963286` → `rusbingo.online`
4. Validates DNS A record via `getent`/`dig`
5. Sets `LOTTO_ALLOWED_ORIGINS=https://<fqdn>` when `--allowed-origins` omitted

### Provisioning contract

```bash
sudo hostnamectl set-hostname rusbingo.online
```

Operator must set static hostname to the **registered public domain** before `install.sh`.
Short provider machine names are rejected.

`hostname -f` returning a short name is acceptable; detection uses **static** hostname only.

---

## 4. Deployment

| Item | Result |
|------|--------|
| Clean Docker reinstall | **PASS** (existing volume preserved) |
| Detected domain | `rusbingo.online` |
| `LOTTO_ALLOWED_ORIGINS` | `https://rusbingo.online` (auto) |
| `configure-proxy.sh` | **PASS** — nginx + Let's Encrypt |
| HTTPS | **PASS** — `curl -sI https://rusbingo.online/` → `HTTP/2 200` |
| TLS certificate | **PASS** — Let's Encrypt, CN=`rusbingo.online`, valid 2026-09-05 — 2026-12-04 |
| WSS `/ws` | **PASS** — TLS handshake `101`; register+room via WSS (conn log `web_u1`) |
| HTTP → HTTPS redirect | **PASS** (nginx `301`) |

---

## 5. Browser E2E

| Gate | Result | Evidence |
|------|--------|----------|
| Page load `https://rusbingo.online` | **PASS** | HTTP 200; login/register UI present |
| Registration (browser) | **UNVERIFIED** | Not automated in this session |
| Login / lobby / gameplay / AFK / apartment / reconnect | **UNVERIFIED** | Requires interactive multi-client browser test |

---

## 6. Security

| Check | Result |
|-------|--------|
| Container UID | `1000` (non-root) |
| Volume `/app/data` | `1000:1000` mode `750` |
| Public ports | `:443`, `:80` (nginx); `:8080` **localhost only** |
| TLS | Valid Let's Encrypt |
| Origin policy | `https://rusbingo.online` enforced |
| Static files | `/var/lib/lotto-game/default/public` (`www-data`, dir `755`) |

---

## 7. Changes

| File | Change |
|------|--------|
| `deploy/docker/lib/common.sh` | `lotto_detect_provisioning_fqdn`, DNS validate, instance dir `755` |
| `deploy/docker/install.sh` | Auto `LOTTO_ALLOWED_ORIGINS` from FQDN |
| `deploy/docker/configure-proxy.sh` | **New** — ADR-027 nginx + certbot for Docker upstream |
| `deploy/docker/healthcheck.php` | Send `Origin` when `LOTTO_ALLOWED_ORIGINS` set |
| `deploy/docker/tests/run_tests.sh` | FQDN detection + configure-proxy static test |
| `docs/LOCAL_ENVIRONMENT.md` | Provisioning contract documented |

**Commits:** `bcd1dda` … `4ba6f27` on `feature/epic-14-1-docker-domain-tls-automation`

---

## 8. ADR

```text
ADR required: NO
Reason: Consumes VPS static hostname provisioning contract; applies existing ADR-027
host nginx TLS termination in front of ADR-036 Docker upstream. No new TLS architecture.
```

---

## 9. DoD Matrix

| Gate | Result |
|------|--------|
| FQDN auto-detection (no hard-code) | **PASS** |
| Docker install + health | **PASS** |
| HTTPS | **PASS** |
| TLS | **PASS** |
| WSS | **PASS** (handshake + server-side auth evidence) |
| Browser full E2E | **UNVERIFIED** |
| Canonical native production (`ADMIN_VPS_DEPLOY`) | **UNVERIFIED** (this task = Docker path) |
| PR #14 merged to `main` | **FAIL** (still open) |

---

## 10. Release Decision

```text
V1.0 — BLOCKED
```

Docker HTTPS/WSS automation verified on `rusbingo.online`, but full browser E2E gameplay,
canonical native production gates, and merge of fix branches to `main` remain incomplete.

---

## 11. PR

- Branch: `feature/epic-14-1-docker-domain-tls-automation`
- PR: (to be created)
- Merge: not requested

---

## 12. Next Smallest Action

Next smallest action: Run interactive browser E2E (register, lobby, two-player game, reconnect)
on `https://rusbingo.online`, then merge PRs #14 and domain/TLS automation to `main`.
