#!/usr/bin/env bash
# Host nginx + Let's Encrypt for a Docker Lotto instance (ADR-027 upstream pattern).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

INSTANCE="${LOTTO_DEFAULT_INSTANCE}"
CERTBOT_EMAIL=""
ASSUME_YES=0

usage() {
    cat <<'EOF'
Usage: sudo ./deploy/docker/configure-proxy.sh [options]

Configures host nginx + Let's Encrypt TLS in front of an existing Docker instance.
Requires provisioning FQDN on the VPS (hostnamectl static hostname = public domain).

Options:
  --name NAME     Instance name (default: default)
  --email EMAIL   Let's Encrypt contact email (optional; uses register-unsafely-without-email if omitted)
  -h, --help      Show this help
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --name) INSTANCE="$2"; shift 2 ;;
        --email) CERTBOT_EMAIL="$2"; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) lotto_err "Unknown option: $1"; usage; exit 2 ;;
    esac
done

if [[ "${EUID}" -ne 0 ]]; then
    lotto_err "Run as root (sudo)."
    exit 1
fi

lotto_validate_instance_name "${INSTANCE}"
lotto_os_check
lotto_repo_check

if ! lotto_instance_metadata_exists "${INSTANCE}"; then
    lotto_err "Instance '${INSTANCE}' is not installed. Run deploy/docker/install.sh first."
    exit 1
fi

FQDN="$(lotto_detect_provisioning_fqdn)"
RESOLVED_IP="$(lotto_validate_fqdn_dns "${FQDN}")"
lotto_load_instance_env "${INSTANCE}"

UPSTREAM_PORT="${LOTTO_HOST_PORT}"
PUBLIC_ROOT="${LOTTO_REPO_ROOT}/public"
STATIC_ROOT="$(lotto_instance_dir "${INSTANCE}")/public"
SITE_NAME="lotto-docker-${INSTANCE}"
SITE_FILE="/etc/nginx/sites-available/${SITE_NAME}"
ORIGIN="$(lotto_https_origin_for_fqdn "${FQDN}")"

if [[ ! -d "${PUBLIC_ROOT}" ]]; then
    lotto_err "Missing static root: ${PUBLIC_ROOT}"
    exit 1
fi

mkdir -p "$(lotto_instance_dir "${INSTANCE}")"
rm -rf "${STATIC_ROOT}"
cp -a "${PUBLIC_ROOT}" "${STATIC_ROOT}"
chown -R www-data:www-data "${STATIC_ROOT}"
chmod -R a+rX "${STATIC_ROOT}"

lotto_info "Configuring nginx TLS proxy for ${FQDN} → 127.0.0.1:${UPSTREAM_PORT}"
lotto_info "DNS ${FQDN} resolves to ${RESOLVED_IP}"

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq nginx certbot python3-certbot-nginx

cat > "${SITE_FILE}" <<NGINX
map \$http_upgrade \$connection_upgrade {
    default upgrade;
    ''      close;
}

server {
    listen 80;
    server_name ${FQDN};
    root ${STATIC_ROOT};
    index index.html;
    location / {
        try_files \$uri \$uri/ /index.html;
    }
}
NGINX

ln -sf "${SITE_FILE}" "/etc/nginx/sites-enabled/${SITE_NAME}"
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

CERTBOT_ARGS=(certbot --nginx -d "${FQDN}" --non-interactive --agree-tos --redirect)
if [[ -n "${CERTBOT_EMAIL}" ]]; then
    CERTBOT_ARGS+=(--email "${CERTBOT_EMAIL}")
else
    CERTBOT_ARGS+=(--register-unsafely-without-email)
fi
"${CERTBOT_ARGS[@]}"

# Full HTTPS + WebSocket config (certbot may have modified the file; replace with complete template).
cat > "${SITE_FILE}" <<NGINX
map \$http_upgrade \$connection_upgrade {
    default upgrade;
    ''      close;
}

server {
    listen 443 ssl http2;
    server_name ${FQDN};

    ssl_certificate     /etc/letsencrypt/live/${FQDN}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/${FQDN}/privkey.pem;

    root ${STATIC_ROOT};
    index index.html;

    location / {
        try_files \$uri \$uri/ /index.html;
    }

    location /ws {
        proxy_pass http://127.0.0.1:${UPSTREAM_PORT};
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection \$connection_upgrade;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }
}

server {
    listen 80;
    server_name ${FQDN};
    return 301 https://\$host\$request_uri;
}
NGINX

nginx -t
systemctl reload nginx

# Ensure container allow-list matches detected origin (idempotent reinstall).
export LOTTO_ALLOWED_ORIGINS="${ORIGIN}"
export LOTTO_TRUSTED_PROXY_IPS="${LOTTO_TRUSTED_PROXY_IPS:-127.0.0.1,::1}"
bash "${SCRIPT_DIR}/install.sh" --name "${INSTANCE}" --port "${UPSTREAM_PORT}" \
    --bind "${LOTTO_BIND_ADDRESS}" \
    --allowed-origins "${ORIGIN}" \
    --trusted-proxy-ips "${LOTTO_TRUSTED_PROXY_IPS}"

lotto_info ""
lotto_info "TLS proxy configured."
lotto_info "  HTTPS: ${ORIGIN}/"
lotto_info "  WSS:   wss://${FQDN}/ws"
lotto_info "  Upstream: http://127.0.0.1:${UPSTREAM_PORT}"
