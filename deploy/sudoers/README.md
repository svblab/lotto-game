# Passwordless sudo for deploy scripts

Allows a dedicated deploy account (e.g. `cursor-user`) to run lifecycle scripts
as root without a password — required for SSH MCP and CI automation.

## One-time setup (as root)

```bash
su -
bash /home/cursor-user/lotto-game/deploy/sudoers/install.sh cursor-user
```

Optional arguments: `install.sh [DEPLOY_USER] [REPO_ROOT]`

## Verify (as deploy user)

```bash
bash deploy/sudoers/verify.sh
```

Or manually:

```bash
sudo -n true
sudo -n bash deploy/systemd/install.sh --help
```

## Scope

Only these commands are whitelisted (with any arguments):

| Path | Scripts |
|------|---------|
| `deploy/systemd/` | `install.sh`, `update.sh`, `remove.sh`, `healthcheck.sh` |
| `deploy/docker/` | `install.sh`, `remove.sh`, `healthcheck.sh` |

Installed file: `/etc/sudoers.d/lotto-deploy` (mode `0440`).

Re-run `install.sh` after moving the repository checkout to a new path.
