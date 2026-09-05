#!/usr/bin/env bash
# One-command Docker deployment for a Lotto Game instance.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

INSTANCE="${LOTTO_DEFAULT_INSTANCE}"
HOST_PORT=""
BIND_ADDRESS="${LOTTO_DEFAULT_BIND_ADDRESS}"
CONTAINER_PORT="${LOTTO_DEFAULT_CONTAINER_PORT}"
MEM_LIMIT="${LOTTO_MEM_LIMIT:-256m}"
CPU_LIMIT="${LOTTO_CPU_LIMIT:-0.5}"
PIDS_LIMIT="${LOTTO_PIDS_LIMIT:-256}"
ALLOWED_ORIGINS="${LOTTO_ALLOWED_ORIGINS:-}"
TRUSTED_PROXY_IPS="${LOTTO_TRUSTED_PROXY_IPS:-}"
MAX_ACCOUNTS_PER_IP="${LOTTO_MAX_ACCOUNTS_PER_IP:-}"
FRESH_INSTALL=0
NON_INTERACTIVE=0

usage() {
    cat <<'EOF'
Usage: sudo ./deploy/docker/install.sh [options]

Options:
  --name NAME          Instance name (default: default)
  --port PORT          Host port to publish (default: auto / reuse saved)
  --bind ADDRESS       Bind address (default: 127.0.0.1)
  --container-port P   In-container WS port (default: 8080)
  --mem-limit VALUE    Docker mem_limit (default: 256m)
  --cpu-limit VALUE    Docker cpus limit (default: 0.5)
  --pids-limit N       Docker pids_limit (default: 256)
  --allowed-origins V  LOTTO_ALLOWED_ORIGINS (comma-separated)
  --trusted-proxy-ips V LOTTO_TRUSTED_PROXY_IPS
  --max-accounts-per-ip N LOTTO_MAX_ACCOUNTS_PER_IP
  --non-interactive    Machine-readable handoff (exit 42 when credential pending)
  -h, --help           Show this help

Examples:
  sudo ./deploy/docker/install.sh
  sudo ./deploy/docker/install.sh --name lotto-01
  sudo ./deploy/docker/install.sh --name lotto-02 --port 8081
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --name) INSTANCE="$2"; shift 2 ;;
        --port) HOST_PORT="$2"; shift 2 ;;
        --bind) BIND_ADDRESS="$2"; shift 2 ;;
        --container-port) CONTAINER_PORT="$2"; shift 2 ;;
        --mem-limit) MEM_LIMIT="$2"; shift 2 ;;
        --cpu-limit) CPU_LIMIT="$2"; shift 2 ;;
        --pids-limit) PIDS_LIMIT="$2"; shift 2 ;;
        --allowed-origins) ALLOWED_ORIGINS="$2"; shift 2 ;;
        --trusted-proxy-ips) TRUSTED_PROXY_IPS="$2"; shift 2 ;;
        --max-accounts-per-ip) MAX_ACCOUNTS_PER_IP="$2"; shift 2 ;;
        --non-interactive) NON_INTERACTIVE=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) lotto_err "Unknown option: $1"; usage; exit 2 ;;
    esac
done

cleanup_on_error() {
    if [[ "${FRESH_INSTALL}" -eq 1 ]]; then
        lotto_cleanup_partial_instance "${INSTANCE}" || true
    fi
}
trap cleanup_on_error ERR

lotto_validate_instance_name "${INSTANCE}"
lotto_os_check
lotto_docker_check
lotto_repo_check

DETECTED_FQDN=""
if [[ -z "${ALLOWED_ORIGINS}" ]]; then
    if DETECTED_FQDN="$(lotto_detect_provisioning_fqdn 2>/dev/null)"; then
        lotto_validate_fqdn_dns "${DETECTED_FQDN}" >/dev/null
        ALLOWED_ORIGINS="$(lotto_https_origin_for_fqdn "${DETECTED_FQDN}")"
        lotto_info "Detected provisioning FQDN '${DETECTED_FQDN}' → LOTTO_ALLOWED_ORIGINS=${ALLOWED_ORIGINS}"
    fi
fi
if [[ -z "${TRUSTED_PROXY_IPS}" ]]; then
    TRUSTED_PROXY_IPS="127.0.0.1,::1"
fi

STATE_DIR="$(lotto_instance_dir "${INSTANCE}")"
METADATA_EXISTS=0
VOLUME_NAME="lotto-${INSTANCE}-data"
if lotto_instance_metadata_exists "${INSTANCE}"; then
    METADATA_EXISTS=1
    lotto_load_instance_env "${INSTANCE}"
    VOLUME_NAME="${LOTTO_VOLUME_NAME}"
    if [[ -z "${HOST_PORT}" ]]; then
        HOST_PORT="${LOTTO_HOST_PORT}"
    fi
    if [[ "${BIND_ADDRESS}" == "${LOTTO_DEFAULT_BIND_ADDRESS}" ]]; then
        BIND_ADDRESS="${LOTTO_BIND_ADDRESS:-${LOTTO_DEFAULT_BIND_ADDRESS}}"
    fi
fi

if [[ -z "${HOST_PORT}" ]]; then
    HOST_PORT="$(lotto_pick_free_port)"
fi

if lotto_port_in_use "${HOST_PORT}"; then
    if [[ "${METADATA_EXISTS}" -eq 1 && "${HOST_PORT}" == "${LOTTO_HOST_PORT:-}" ]]; then
        :
    else
        lotto_err "Host port ${HOST_PORT} is already in use."
        exit 1
    fi
fi

lotto_write_instance_env \
    "${INSTANCE}" \
    "${HOST_PORT}" \
    "${BIND_ADDRESS}" \
    "${CONTAINER_PORT}" \
    "${MEM_LIMIT}" \
    "${CPU_LIMIT}" \
    "${PIDS_LIMIT}" \
    "${ALLOWED_ORIGINS}" \
    "${TRUSTED_PROXY_IPS}" \
    "${MAX_ACCOUNTS_PER_IP}"

lotto_load_instance_env "${INSTANCE}"

NEW_DATABASE=0
if ! lotto_volume_exists "${LOTTO_VOLUME_NAME}"; then
    if [[ "${METADATA_EXISTS}" -eq 1 ]]; then
        lotto_err "Instance metadata exists but volume ${LOTTO_VOLUME_NAME} is missing."
        lotto_err "Run: sudo ./deploy/docker/remove.sh --name ${INSTANCE} --yes  then reinstall."
        exit 1
    fi
    NEW_DATABASE=1
    FRESH_INSTALL=1
    lotto_info "Creating new instance '${INSTANCE}' (volume ${LOTTO_VOLUME_NAME})..."
else
    lotto_info "Updating existing instance '${INSTANCE}' (preserving volume ${LOTTO_VOLUME_NAME})..."
fi

lotto_info "Building image ${LOTTO_IMAGE}..."
lotto_compose_cmd "${INSTANCE}" build --pull

if [[ "${NEW_DATABASE}" -eq 1 ]]; then
    lotto_info "Preparing data volume permissions..."
    lotto_prepare_data_volume "${LOTTO_VOLUME_NAME}" "${LOTTO_IMAGE}"
    lotto_info "Initializing SQLite database..."
    lotto_compose_cmd "${INSTANCE}" run --rm --no-deps \
        --entrypoint php \
        -e LOTTO_DB_PATH=/app/data/game.db \
        -e LOTTO_ADMIN_BOOTSTRAP_FILE=/app/data/.admin_bootstrap \
        app init_db.php
    lotto_info "Promoting admin bootstrap credential to AHPC pending file..."
    lotto_promote_docker_bootstrap_credential "${INSTANCE}" "${LOTTO_IMAGE}" "${LOTTO_VOLUME_NAME}"
fi

lotto_info "Starting container ${LOTTO_CONTAINER_NAME}..."
lotto_compose_cmd "${INSTANCE}" up -d --remove-orphans

lotto_info "Waiting for healthcheck..."
lotto_wait_healthy "${INSTANCE}" 120

if [[ "${NEW_DATABASE}" -eq 1 ]]; then
    # shellcheck source=../lib/admin-bootstrap-common.sh
    source "${SCRIPT_DIR}/../lib/admin-bootstrap-common.sh"
    pending_path="$(lotto_ahpc_pending_path_docker "${INSTANCE}")"
    if [[ "${NON_INTERACTIVE}" -eq 1 ]]; then
        lotto_ahpc_emit_handoff_json "${INSTANCE}" "${pending_path}"
        FRESH_INSTALL=0
        trap - ERR
        exit 42
    fi
    lotto_info ""
    lotto_info "Admin bootstrap credential is pending (AHPC)."
    lotto_info "Retrieve once: sudo ./deploy/docker/admin-bootstrap.sh --name ${INSTANCE} read"
    lotto_info "Then acknowledge: sudo ./deploy/docker/admin-bootstrap.sh --name ${INSTANCE} acknowledge"
    lotto_info "Pending path: ${pending_path}"
fi

FRESH_INSTALL=0
trap - ERR

lotto_info ""
lotto_info "Lotto Game instance '${INSTANCE}' is running."
lotto_info "  WebSocket: ws://${BIND_ADDRESS}:${HOST_PORT}/"
lotto_info "  Reverse proxy upstream: http://${BIND_ADDRESS}:${HOST_PORT} (see docs/LOCAL_ENVIRONMENT.md)"
if [[ -n "${DETECTED_FQDN}" ]]; then
    lotto_info "  Public HTTPS (after proxy): https://${DETECTED_FQDN}/"
    lotto_info "  Configure host TLS proxy: sudo ./deploy/docker/configure-proxy.sh --name ${INSTANCE}"
fi
lotto_info "  Logs: docker compose -f deploy/docker/compose.yaml --env-file ${STATE_DIR}/instance.env -p lotto-${INSTANCE} logs -f app"
lotto_info "  Remove: sudo ./deploy/docker/remove.sh --name ${INSTANCE}"
