# Native canonical production templates (V1.0)

**Canonical V1.0 production** — not Docker, not generic `deploy/systemd/` multi-instance.

| Item | Value |
|------|--------|
| Install path | `/opt/lotto-game` |
| Service | `lotto-server.service` |
| User | `www-data` |
| Workerman | `127.0.0.1:8080` (loopback; not public) |
| Public TLS | nginx on 443 |
| WebSocket path | `/ws` → upstream `http://127.0.0.1:8080` |

**Authoritative runbook:** `docs/ADMIN_VPS_DEPLOY.md`

These files are **placeholders** (`your-domain.com`). Copy to the VPS and substitute
the Human-approved production domain (H3). Do not commit real domains or secrets.

| File | Install on VPS |
|------|----------------|
| `lotto-server.service.example` | `/etc/systemd/system/lotto-server.service` |
| `nginx-lotto-game.example.conf` | `/etc/nginx/sites-available/lotto-game` |

Configuration model (no `APP_ENV` / `APP_DOMAIN`):

- `LOTTO_ALLOWED_ORIGINS` — in systemd unit (`https://<domain>`)
- `LOTTO_TRUSTED_PROXY_IPS` — default `127.0.0.1,::1`
- `public/index.html` meta: `lotto-ws-port=""`, `lotto-ws-path="/ws"`

After install: `docs/RELEASE_CONTRACT_V1.md` §8.1 meta verification after every `git pull`.
