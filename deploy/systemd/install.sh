#!/usr/bin/env bash
# Install one generic systemd Lotto Game instance (Epic B2).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

INSTANCE="${LOTTO_DEFAULT_INSTANCE}"
HOST_PORT=""
BIND_ADDRESS="${LOTTO_DEFAULT_BIND}"
ALLOWED_ORIGINS=""
TRUSTED_PROXY_IPS=""
MAX_ACCOUNTS_PER_IP=""
FRESH_INSTALL=0
CREATED_USER="false"
NEW_DATABASE=0

usage() {
    cat <<'EOF'
Usage: sudo ./deploy/systemd/install.sh [options] [INSTANCE]

Options:
  --name NAME              Instance name (default: default)
  --port PORT              WebSocket port (default: auto from 8081)
  --bind ADDRESS           Documented bind/upstream address (default: 127.0.0.1)
  --allowed-origins V      LOTTO_ALLOWED_ORIGINS
  --trusted-proxy-ips V    LOTTO_TRUSTED_PROXY_IPS
  --max-accounts-per-ip N  LOTTO_MAX_ACCOUNTS_PER_IP
  -h, --help               Show this help

Examples:
  sudo ./deploy/systemd/install.sh demo
  sudo ./deploy/systemd/install.sh --name lotto-01 --port 8081
EOF
}

if [[ $# -gt 0 && "$1" != -* ]]; then
    INSTANCE="$1"
    shift
fi

while [[ $# -gt 0 ]]; do
    case "$1" in
        --name) INSTANCE="$2"; shift 2 ;;
        --port) HOST_PORT="$2"; shift 2 ;;
        --bind) BIND_ADDRESS="$2"; shift 2 ;;
        --allowed-origins) ALLOWED_ORIGINS="$2"; shift 2 ;;
        --trusted-proxy-ips) TRUSTED_PROXY_IPS="$2"; shift 2 ;;
        --max-accounts-per-ip) MAX_ACCOUNTS_PER_IP="$2"; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) lotto_err "Unknown option: $1"; usage; exit 2 ;;
    esac
done

cleanup_on_error() {
    if [[ "${FRESH_INSTALL}" -eq 1 ]]; then
        lotto_cleanup_failed_install "${INSTANCE}" || true
    fi
}
trap cleanup_on_error ERR

lotto_require_root
lotto_os_check
lotto_repo_check
lotto_validate_instance_name "${INSTANCE}"

ROOT="$(lotto_instance_root "${INSTANCE}")"
UNIT="$(lotto_systemd_unit "${INSTANCE}")"
USER="$(lotto_service_user "${INSTANCE}")"
APP="$(lotto_app_path "${INSTANCE}")"
DATA="$(lotto_data_path "${INSTANCE}")"
LOGS="$(lotto_logs_path "${INSTANCE}")"
CONFIG="$(lotto_config_path "${INSTANCE}")"
UNIT_FILE="$(lotto_unit_file "${INSTANCE}")"
ENV_FILE="$(lotto_env_file "${INSTANCE}")"
BACKUP="$(lotto_backup_dir "${INSTANCE}")"

lotto_assert_safe_instance_path "${ROOT}" || exit 1
lotto_assert_not_protected_unit "${UNIT}" || exit 1
lotto_assert_not_protected_user "${USER}" || exit 1

METADATA_EXISTS=0
if lotto_instance_metadata_exists "${INSTANCE}"; then
    METADATA_EXISTS=1
    lotto_load_instance "${INSTANCE}"
    if [[ -z "${HOST_PORT}" ]]; then
        HOST_PORT="${LOTTO_META_PORT}"
    fi
    if [[ "${BIND_ADDRESS}" == "${LOTTO_DEFAULT_BIND}" && -n "${LOTTO_META_BIND:-}" ]]; then
        BIND_ADDRESS="${LOTTO_META_BIND}"
    fi
    lotto_info "Reconciling existing systemd instance '${INSTANCE}'..."
else
    FRESH_INSTALL=1
    if [[ -z "${HOST_PORT}" ]]; then
        HOST_PORT="$(lotto_pick_free_systemd_port)"
    fi
    lotto_info "Creating new systemd instance '${INSTANCE}'..."
fi

lotto_assert_not_protected_port "${HOST_PORT}" || exit 1
ALLOW_SAME_PORT=0
if [[ "${METADATA_EXISTS}" -eq 1 && "${HOST_PORT}" == "${LOTTO_META_PORT:-}" ]]; then
    ALLOW_SAME_PORT=1
fi
lotto_assert_port_available "${HOST_PORT}" "${INSTANCE}" "${ALLOW_SAME_PORT}" || exit 1

CREATED_USER="$(lotto_create_service_user "${USER}" "${INSTANCE}")"

mkdir -p "${APP}" "${DATA}" "${LOGS}" "${CONFIG}" "${BACKUP}"
chmod 750 "${ROOT}" "${APP}" "${DATA}" "${LOGS}" "${CONFIG}"
chmod 700 "${BACKUP}"

lotto_sync_app_source "${APP}"
chown -R "${USER}:${USER}" "${ROOT}"

lotto_run_composer "${APP}" "${USER}"
chown -R "${USER}:${USER}" "${APP}"

lotto_write_env_file "${INSTANCE}" "${HOST_PORT}" "${BIND_ADDRESS}" \
    "${ALLOWED_ORIGINS}" "${TRUSTED_PROXY_IPS}" "${MAX_ACCOUNTS_PER_IP}"
chown "${USER}:${USER}" "${ENV_FILE}"
chmod 640 "${ENV_FILE}"

if [[ ! -f "${DATA}/game.db" ]]; then
    NEW_DATABASE=1
    lotto_info "Initializing SQLite database..."
    # shellcheck disable=SC1090
    set -a
    source "${ENV_FILE}"
    set +a
    sudo -u "${USER}" env \
        LOTTO_DB_PATH="${LOTTO_DB_PATH}" \
        LOTTO_ADMIN_BOOTSTRAP_FILE="${LOTTO_ADMIN_BOOTSTRAP_FILE}" \
        php "${APP}/init_db.php"
fi

lotto_info "Installing systemd unit ${UNIT}..."
lotto_render_unit_file "${UNIT_FILE}" "${USER}" "${APP}" "${ENV_FILE}" \
    "${DATA}" "${LOGS}" "${CONFIG}"

systemctl daemon-reload
systemctl enable "${UNIT}" >/dev/null
systemctl restart "${UNIT}"

lotto_wait_for_active_unit "${UNIT}" 60

lotto_info "Running application healthcheck..."
lotto_run_instance_healthcheck "${HOST_PORT}"

if [[ "${CREATED_USER}" == "false" && "${METADATA_EXISTS}" -eq 1 && ( "${LOTTO_META_CREATED_USER}" == "True" || "${LOTTO_META_CREATED_USER}" == "true" ) ]]; then
    CREATED_USER="true"
fi

lotto_write_metadata "${INSTANCE}" "${HOST_PORT}" "${BIND_ADDRESS}" "${CREATED_USER}"
chmod 640 "$(lotto_metadata_file "${INSTANCE}")"
chown root:"${USER}" "$(lotto_metadata_file "${INSTANCE}")"

if [[ "${NEW_DATABASE}" -eq 1 && -f "${DATA}/.admin_bootstrap" ]]; then
    lotto_info ""
    lotto_info "=== One-time admin bootstrap credential (save now) ==="
    cat "${DATA}/.admin_bootstrap"
    lotto_info "===================================================="
    rm -f "${DATA}/.admin_bootstrap"
fi

FRESH_INSTALL=0
trap - ERR

lotto_info ""
lotto_info "Lotto Game systemd instance '${INSTANCE}' is running."
lotto_info "  Unit: ${UNIT}"
lotto_info "  User: ${USER}"
lotto_info "  App:  ${APP}"
lotto_info "  Data: ${DATA}"
lotto_info "  Port: ${HOST_PORT} (upstream: ${BIND_ADDRESS}:${HOST_PORT})"
lotto_info "  Healthcheck: PASS"
