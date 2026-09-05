# 038 — Admin bootstrap credential delivery (AHPC)

## Status

Accepted

## Context

Fresh Docker and generic systemd deployments create a one-time `admin` account via
`init_db.php` (`random_bytes(12)` → bcrypt → SQLite row). Before ADR-038, installers
used an unsafe lifecycle:

```text
generate → stdout (or host cat) → delete
```

That pattern is insufficient for cloud and CI provisioning:

- **Secret leakage** — passwords appear in shell history, CI logs, terminal scrollback,
  and redirected install output.
- **No acknowledgement** — installers auto-deleted the only delivery artifact; operators
  could lose the credential with no recovery path except DB surgery.
- **No machine-readable handoff** — automation cannot detect “credential pending” without
  parsing human text.
- **Interruption unsafe** — partial installs could leave an admin row with no retrievable
  credential.

Native production (`/opt/lotto-game`, `lotto-server.service`) is unchanged: operators run
`init_db.php` interactively as `www-data` per `docs/ADMIN_VPS_DEPLOY.md`.

ADR-036 §6 described the previous Docker bootstrap (temporary volume file → host
terminal → delete). **Where ADR-036 conflicts with this ADR, ADR-038 supersedes
ADR-036 for bootstrap credential delivery only.** ADR-036 remains authoritative for
Compose layout, volumes, healthcheck, and removal semantics.

## Threat model

| Actor | Capability | Mitigation |
|-------|------------|------------|
| Unprivileged host user | Read world-readable files | Pending file `root:root` `0600` on host metadata path |
| Container / service user | Read host pending | Pending never stored in data volume or service-writable paths (Docker: `/var/lib/lotto-game/<instance>/`; systemd: `config/` promoted as root-owned) |
| CI / log aggregator | Capture install stdout | Install never prints password; exit `42` + JSON handoff metadata only |
| Operator mistake (`read > log`) | Redirect password to file | Human `read` requires controlling TTY; automation must use `read --format=json` explicitly |
| Instance A operator | Affect instance B | Per-instance pending paths; no global store |
| Attacker with old pending file | Login after reset | `reset` rotates DB hash; old plaintext never recovered or printed |

Out of scope: operator-supplied passwords (`--admin-password-file`), Kubernetes
operators, cloud secret managers (provisioning layer stores secrets externally).

## Decision — Acknowledged Host Pending Credential (AHPC)

### State machine

```text
(none)
  │ fresh install / reset
  ▼
pending ──read──► operator/provisioner retrieves password (TTY or JSON)
  │
  │ acknowledge (explicit only)
  ▼
acknowledged (marker file, no password)
```

- `read` does **not** acknowledge.
- `install.sh` does **not** acknowledge.
- Successful install completion leaves `pending` intact until `acknowledge`.

### Pending credential location

| Deployment | Path |
|------------|------|
| Docker | `/var/lib/lotto-game/<instance>/admin-bootstrap.pending` |
| Generic systemd | `/opt/lotto-game-<instance>/config/admin-bootstrap.pending` |

Acknowledgement marker (no password): sibling `admin-bootstrap.ack`.

Ownership: `root:root`. Mode: `0600`. Format: versioned JSON (`schema_version: 1`).

### Promotion (fresh install)

```text
init_db.php → temporary .admin_bootstrap (existing env contract)
           → atomic promote to canonical pending file
           → remove temporary artifact
           → leave canonical pending intact
```

Never delete the only plaintext credential before successful promotion. Promotion
failure fails the installation.

### CLI contract

```text
admin-bootstrap.sh --name <instance> status [--format=json]
admin-bootstrap.sh --name <instance> read [--format=json]
admin-bootstrap.sh --name <instance> acknowledge
admin-bootstrap.sh --name <instance> reset
```

Implemented per deployment tree: `deploy/docker/admin-bootstrap.sh`,
`deploy/systemd/admin-bootstrap.sh`.

### Exit codes

| Code | Meaning |
|------|---------|
| 0 | Success |
| 2 | No pending credential |
| 3 | Unknown instance |
| 4 | Corrupt pending credential |
| 10 | Reset refused (e.g. pending exists, missing DB) |
| 42 | Install handoff required (`install.sh --non-interactive` on fresh DB) |

### Acknowledgement semantics

1. Verify pending JSON.
2. Write acknowledgement marker (no password).
3. Remove pending file.
4. Idempotent if already acknowledged.

### Interruption semantics

If install fails after promotion, pending file and database survive; destructive
fresh-install cleanup is skipped when pending exists. Operator may `read` → store →
`acknowledge` or `reset`.

### Retry semantics

Re-running `install.sh` on an existing instance (`NEW_DATABASE=0`) does not regenerate
credentials, create pending files, or print passwords.

### Reset / recovery

`reset` (root only):

1. Generate new `random_bytes(12)` password.
2. Update `admin` password hash in SQLite.
3. Create new pending credential.
4. Never recover or print the old plaintext password.

Operational flow:

```text
reset → read (new) → store in secret system → acknowledge → login
```

If the game server is running during `reset`, active admin sessions may remain valid
until reconnect; new password applies to subsequent logins. Prefer maintenance window.

### Multi-instance isolation

Instances `default` and `test` (and any other `--name`) have independent pending
files, databases, and credentials.

### Non-interactive provisioning

```text
install.sh --non-interactive
  → create admin + pending
  → exit 42
  → JSON: instance, state, pending_path (no password)
```

Provisioner responsibility: detect exit `42`, `status`, `read --format=json`, store in
external secret manager, `acknowledge`.

Example (provisioning layer — not provided by this repository):

```bash
if ! out=$(sudo ./deploy/docker/install.sh --name "$INSTANCE" --non-interactive 2>&1); then
  rc=$?
  if [[ "$rc" -ne 42 ]]; then exit "$rc"; fi
fi
sudo ./deploy/docker/admin-bootstrap.sh --name "$INSTANCE" status --format=json
pass=$(sudo ./deploy/docker/admin-bootstrap.sh --name "$INSTANCE" read --format=json \
  | jq -r .password)
# store "$pass" in your vault (Vault, SSM, etc.) — not in git or CI logs
sudo ./deploy/docker/admin-bootstrap.sh --name "$INSTANCE" acknowledge
```

### Secret leakage constraints

Password must never appear in: application logs, Docker logs, Compose config,
`instance.env`, process argv, ordinary environment variables, or Git.

`status` and `status --format=json` are safe for automation logs (metadata only).

### Applicability

- **Docker Compose** — full AHPC.
- **Generic systemd** (`deploy/systemd/`) — full AHPC.
- **Native production** (`/opt/lotto-game`) — manual `init_db.php` unchanged; docs
  cross-reference AHPC for container/systemd paths.

No Kubernetes or cloud-provider integration in this ADR.

## Consequences

**Positive**

- Cloud-safe, auditable bootstrap handoff.
- Explicit operator acknowledgement.
- Recovery via `reset` without old-password disclosure.
- Backward compatible with existing databases.

**Negative**

- Operators must run `admin-bootstrap.sh` after fresh install.
- Pending file on host is a short-lived high-value target (mitigated by `0600`).

**Compatibility:** Deployment/bootstrap only — no protocol, schema, or auth model changes.

## References

- ADR-036 — Docker Compose deployment (bootstrap §6 superseded where noted)
- ADR-037 — Deployment mode separation
- `deploy/lib/admin-bootstrap-common.sh` — shared AHPC helpers
- `deploy/lib/reset_admin_bootstrap.php` — password rotation for `reset`
