#!/usr/bin/env bash
# Systemd deployment foundation (Epic B1): identity, layout, metadata, guards.
# B2 install and B3 remove helpers live in this library.

set -euo pipefail

LOTTO_SYSTEMD_LIB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOTTO_REPO_ROOT="$(cd "${LOTTO_SYSTEMD_LIB_DIR}/../../.." && pwd)"

# --- Protected production resources (docs/ADMIN_VPS_DEPLOY.md) ---
LOTTO_PROTECTED_APP_ROOT="/opt/lotto-game"
LOTTO_PROTECTED_UNIT="lotto-server.service"
LOTTO_PROTECTED_USER="www-data"
LOTTO_PROTECTED_PORT="8080"

# --- Generic systemd instance conventions (ADR-037 / Epic B1) ---
LOTTO_INSTANCE_ROOT_PREFIX="/opt/lotto-game-"
LOTTO_BACKUP_ROOT="/var/backups/lotto-game"
LOTTO_DEFAULT_INSTANCE="default"
LOTTO_METADATA_SCHEMA=1
LOTTO_DEFAULT_BIND="127.0.0.1"
LOTTO_DEFAULT_WS_PORT="8080"
LOTTO_MIN_PORT=1024
LOTTO_MAX_PORT=65535

# Reserved logical names — must not collide with production identity.
LOTTO_RESERVED_NAMES=(
    production
    www-data
    lotto-server
    server
    lotto-server.service
)

lotto_err() { echo "ERROR: $*" >&2; }

lotto_info() { echo "$*"; }

# Print operator-visible context so generic systemd is never confused with production or Docker.
lotto_print_instance_context() {
    local instance="$1"
    local operation="${2:-}"
    local port="${3:-}"

    lotto_info "=== Generic systemd deployment (NOT production, NOT Docker) ==="
    if [[ -n "${operation}" ]]; then
        lotto_info "Operation: ${operation}"
    fi
    lotto_info "  Deployment: deploy/systemd/"
    lotto_info "  Instance:   ${instance}"
    lotto_info "  Unit:       $(lotto_systemd_unit "${instance}")"
    lotto_info "  User:       $(lotto_service_user "${instance}")"
    lotto_info "  Root:       $(lotto_instance_root "${instance}")"
    lotto_info "  App:        $(lotto_app_path "${instance}")"
    lotto_info "  Data:       $(lotto_data_path "${instance}")"
    if [[ -n "${port}" ]]; then
        lotto_info "  Port:       ${port}"
    elif lotto_instance_metadata_exists "${instance}"; then
        local recorded
        recorded="$(lotto_json_get "$(lotto_metadata_file "${instance}")" port)"
        [[ -n "${recorded}" ]] && lotto_info "  Port:       ${recorded}"
    fi
    lotto_info "  Protected production (/opt/lotto-game, lotto-server.service) is NOT targeted."
}

# Validate generic systemd instance name.
# Rule: lowercase ASCII [a-z0-9], then [a-z0-9_-], length 1–32.
# Stricter than Docker (deploy/docker/lib/common.sh) to keep systemd unit,
# Unix username, and path segments unambiguous (no case collisions).
lotto_validate_instance_name() {
    local name="$1"

    if [[ -z "${name}" ]]; then
        lotto_err "Instance name must not be empty."
        return 1
    fi
    if [[ "${name}" =~ [[:space:]] ]]; then
        lotto_err "Instance name must not contain whitespace."
        return 1
    fi
    if [[ "${name}" == *"/"* || "${name}" == *".."* ]]; then
        lotto_err "Instance name must not contain '/' or '..'."
        return 1
    fi
    if [[ ! "${name}" =~ ^[a-z0-9][a-z0-9_-]{0,31}$ ]]; then
        lotto_err "Invalid instance name '${name}'. Use 1–32 chars: [a-z0-9], then [a-z0-9_-]."
        return 1
    fi

    local reserved
    for reserved in "${LOTTO_RESERVED_NAMES[@]}"; do
        if [[ "${name}" == "${reserved}" ]]; then
            lotto_err "Instance name '${name}' is reserved (production safety)."
            return 1
        fi
    done
}

lotto_instance_root() {
    echo "${LOTTO_INSTANCE_ROOT_PREFIX}${1}"
}

lotto_systemd_unit() {
    echo "lotto-game-${1}.service"
}

lotto_service_user() {
    echo "lotto-${1}"
}

lotto_unit_file() {
    if [[ -n "${LOTTO_SYSTEMD_UNIT_DIR:-}" ]]; then
        echo "${LOTTO_SYSTEMD_UNIT_DIR}/$(lotto_systemd_unit "$1")"
        return 0
    fi
    echo "/etc/systemd/system/$(lotto_systemd_unit "$1")"
}

lotto_metadata_file() {
    echo "$(lotto_instance_root "$1")/config/deployment.json"
}

lotto_env_file() {
    echo "$(lotto_instance_root "$1")/config/environment"
}

lotto_app_path() {
    echo "$(lotto_instance_root "$1")/app"
}

lotto_data_path() {
    echo "$(lotto_instance_root "$1")/data"
}

lotto_logs_path() {
    echo "$(lotto_instance_root "$1")/logs"
}

lotto_config_path() {
    echo "$(lotto_instance_root "$1")/config"
}

lotto_backup_dir() {
    echo "${LOTTO_BACKUP_ROOT}/${1}"
}

# Normalize a path string for guard comparisons (no symlink resolution on B1).
lotto_canonical_path_string() {
    local path="$1"
    local collapsed="${path//\/\//\/}"
    while [[ "${collapsed}" == */ ]]; do
        collapsed="${collapsed%/}"
    done
    echo "${collapsed}"
}

lotto_assert_not_protected_path() {
    local path
    path="$(lotto_canonical_path_string "$1")"
    local protected
    protected="$(lotto_canonical_path_string "${LOTTO_PROTECTED_APP_ROOT}")"

    if [[ -z "${path}" ]]; then
        lotto_err "Refusing empty path (production guard)."
        return 1
    fi
    if [[ "${path}" == "${protected}" || "${path}" == "${protected}/" ]]; then
        lotto_err "Refusing protected production app root '${LOTTO_PROTECTED_APP_ROOT}'."
        return 1
    fi
    if [[ "${path}" == *".."* ]]; then
        lotto_err "Refusing path with '..': '${path}'."
        return 1
    fi
}

lotto_assert_not_protected_unit() {
    local unit="$1"
    if [[ -z "${unit}" ]]; then
        lotto_err "Refusing empty systemd unit name."
        return 1
    fi
    if [[ "${unit}" == "${LOTTO_PROTECTED_UNIT}" ]]; then
        lotto_err "Refusing protected production unit '${LOTTO_PROTECTED_UNIT}'."
        return 1
    fi
}

lotto_assert_not_protected_user() {
    local user="$1"
    if [[ -z "${user}" ]]; then
        lotto_err "Refusing empty service user name."
        return 1
    fi
    if [[ "${user}" == "${LOTTO_PROTECTED_USER}" ]]; then
        lotto_err "Refusing protected production user '${LOTTO_PROTECTED_USER}'."
        return 1
    fi
}

# Validate TCP port for identity metadata (no allocation in B1).
lotto_validate_port_number() {
    local port="$1"
    if [[ ! "${port}" =~ ^[0-9]+$ ]]; then
        lotto_err "Port must be a numeric value."
        return 1
    fi
    if (( port < LOTTO_MIN_PORT || port > LOTTO_MAX_PORT )); then
        lotto_err "Port ${port} is outside allowed range ${LOTTO_MIN_PORT}-${LOTTO_MAX_PORT}."
        return 1
    fi
}

# Reject port 8080 when it would target production's default listener.
# B2 may extend with live systemctl checks; B1 uses static production guard.
lotto_assert_not_protected_port() {
    local port="$1"
    lotto_validate_port_number "${port}" || return 1
    if [[ "${port}" == "${LOTTO_PROTECTED_PORT}" ]]; then
        lotto_err "Refusing production default port ${LOTTO_PROTECTED_PORT} for generic instances (use another port)."
        return 1
    fi
}

# Resolve full deterministic identity for a validated instance name + port.
# Prints KEY=value lines suitable for tests and future lifecycle scripts.
lotto_resolve_instance_identity() {
    local instance="$1"
    local port="$2"

    lotto_validate_instance_name "${instance}" || return 1
    lotto_validate_port_number "${port}" || return 1

    local root unit user
    root="$(lotto_instance_root "${instance}")"
    unit="$(lotto_systemd_unit "${instance}")"
    user="$(lotto_service_user "${instance}")"

    lotto_assert_not_protected_path "${root}" || return 1
    lotto_assert_not_protected_unit "${unit}" || return 1
    lotto_assert_not_protected_user "${user}" || return 1

    echo "LOTTO_INSTANCE=${instance}"
    echo "LOTTO_ROOT=${root}"
    echo "LOTTO_UNIT=${unit}"
    echo "LOTTO_UNIT_FILE=$(lotto_unit_file "${instance}")"
    echo "LOTTO_USER=${user}"
    echo "LOTTO_APP=$(lotto_app_path "${instance}")"
    echo "LOTTO_DATA=$(lotto_data_path "${instance}")"
    echo "LOTTO_LOGS=$(lotto_logs_path "${instance}")"
    echo "LOTTO_CONFIG=$(lotto_config_path "${instance}")"
    echo "LOTTO_METADATA=$(lotto_metadata_file "${instance}")"
    echo "LOTTO_ENV=$(lotto_env_file "${instance}")"
    echo "LOTTO_BACKUP=$(lotto_backup_dir "${instance}")"
    echo "LOTTO_PORT=${port}"
    echo "LOTTO_BIND=${LOTTO_DEFAULT_BIND}"
}

# Write deployment metadata (JSON). Used by tests and B2/B3/C lifecycle scripts.
# Does not create directories or touch production paths unless caller sets temp roots.
lotto_write_metadata() {
    local instance="$1"
    local port="$2"
    local bind="${3:-${LOTTO_DEFAULT_BIND}}"
    local created_user="${4:-false}"
    local created_at="${5:-$(date -u +%Y-%m-%dT%H:%M:%SZ)}"
    local updated_at="${6:-}"

    lotto_validate_instance_name "${instance}" || return 1
    lotto_validate_port_number "${port}" || return 1

    local meta_path
    local updated_line=""
    meta_path="$(lotto_metadata_file "${instance}")"
    lotto_assert_not_protected_path "$(lotto_instance_root "${instance}")" || return 1

    mkdir -p "$(dirname "${meta_path}")"

    if [[ -n "${updated_at}" ]]; then
        updated_line="  \"updated_at\": \"${updated_at}\","
    fi

    cat >"${meta_path}" <<EOF
{
  "schema_version": ${LOTTO_METADATA_SCHEMA},
  "deployment_type": "systemd",
  "instance": "${instance}",
  "created_at": "${created_at}",
${updated_line}  "app_path": "$(lotto_app_path "${instance}")",
  "data_path": "$(lotto_data_path "${instance}")",
  "logs_path": "$(lotto_logs_path "${instance}")",
  "config_path": "$(lotto_config_path "${instance}")",
  "backup_path": "$(lotto_backup_dir "${instance}")",
  "unit": "$(lotto_systemd_unit "${instance}")",
  "service_user": "$(lotto_service_user "${instance}")",
  "port": ${port},
  "bind_address": "${bind}",
  "created_user": ${created_user},
  "metadata_path": "${meta_path}"
}
EOF
}

lotto_instance_metadata_exists() {
    local instance="$1"
    test -f "$(lotto_metadata_file "${instance}")"
}

# Load metadata into environment variables (LOTTO_META_*).
lotto_load_instance() {
    local instance="$1"
    local meta
    meta="$(lotto_metadata_file "${instance}")"

    if [[ ! -f "${meta}" ]]; then
        lotto_err "Metadata not found for instance '${instance}'."
        return 1
    fi

    LOTTO_META_INSTANCE="$(lotto_json_get "${meta}" instance)"
    LOTTO_META_ROOT="$(lotto_instance_root "${LOTTO_META_INSTANCE}")"
    LOTTO_META_APP="$(lotto_json_get "${meta}" app_path)"
    LOTTO_META_DATA="$(lotto_json_get "${meta}" data_path)"
    LOTTO_META_LOGS="$(lotto_json_get "${meta}" logs_path)"
    LOTTO_META_CONFIG="$(lotto_json_get "${meta}" config_path)"
    LOTTO_META_BACKUP="$(lotto_json_get "${meta}" backup_path)"
    LOTTO_META_UNIT="$(lotto_json_get "${meta}" unit)"
    LOTTO_META_USER="$(lotto_json_get "${meta}" service_user)"
    LOTTO_META_PORT="$(lotto_json_get "${meta}" port)"
    LOTTO_META_BIND="$(lotto_json_get "${meta}" bind_address)"
    LOTTO_META_CREATED_USER="$(lotto_json_get "${meta}" created_user)"
    LOTTO_META_SCHEMA="$(lotto_json_get "${meta}" schema_version)"

    lotto_assert_not_protected_path "${LOTTO_META_ROOT}" || return 1
    lotto_assert_not_protected_unit "${LOTTO_META_UNIT}" || return 1
    lotto_assert_not_protected_user "${LOTTO_META_USER}" || return 1
}

# Minimal JSON field reader (python3 when available; no jq dependency).
lotto_json_get() {
    local file="$1"
    local key="$2"
    if command -v python3 >/dev/null 2>&1; then
        python3 - "${file}" "${key}" <<'PY'
import json, sys
path, key = sys.argv[1], sys.argv[2]
with open(path, encoding="utf-8") as fh:
    data = json.load(fh)
val = data.get(key, "")
print(val if val is not None else "")
PY
        return 0
    fi
    local line
    line="$(grep -E "\"${key}\"" "${file}" | head -n 1)"
    if [[ -z "${line}" ]]; then
        echo ""
        return 0
    fi
    echo "${line}" | sed -E "s/.*: *\"?([^\",}]+)\"?,.*\"/\\1/; t; s/.*: *\"?([^\",}]+)\"?/\\1/"
}

# --- Epic B2: installation helpers (no remove/update lifecycle) ---

LOTTO_SYSTEMD_PICK_PORT_START=8081
LOTTO_SYSTEMD_PICK_PORT_END=8999
LOTTO_SYSTEMD_SERVICE_TEMPLATE="${LOTTO_SYSTEMD_LIB_DIR}/../service.template"

lotto_require_root() {
    if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
        lotto_err "This command must be run as root (sudo)."
        return 1
    fi
}

lotto_os_check() {
    if [[ "$(uname -s)" != "Linux" ]]; then
        lotto_err "Systemd deployment targets Linux (Debian/Ubuntu VPS)."
        return 1
    fi
    if [[ -r /etc/os-release ]]; then
        # shellcheck disable=SC1091
        source /etc/os-release
        case "${ID:-}" in
            debian|ubuntu) ;;
            *) lotto_err "Unsupported distribution '${ID:-unknown}'."; return 1 ;;
        esac
    fi
}

lotto_repo_check() {
    if [[ ! -f "${LOTTO_REPO_ROOT}/server.php" || ! -f "${LOTTO_REPO_ROOT}/composer.json" ]]; then
        lotto_err "Missing lotto-game checkout (server.php/composer.json)."
        return 1
    fi
}

lotto_canonical_path() {
    local path="$1"
    if command -v realpath >/dev/null 2>&1; then
        if [[ -e "${path}" ]]; then
            realpath "${path}"
            return 0
        fi
        local parent base
        parent="$(dirname "${path}")"
        base="$(basename "${path}")"
        if [[ -d "${parent}" ]]; then
            echo "$(realpath "${parent}")/${base}"
            return 0
        fi
    fi
    lotto_canonical_path_string "${path}"
}

lotto_assert_safe_instance_path() {
    local path="$1"
    local canon protected
    canon="$(lotto_canonical_path "${path}")"
    protected="$(lotto_canonical_path "${LOTTO_PROTECTED_APP_ROOT}")"
    lotto_assert_not_protected_path "${canon}" || return 1
    if [[ "${canon}" == "${protected}" ]]; then
        lotto_err "Refusing protected production app root '${LOTTO_PROTECTED_APP_ROOT}'."
        return 1
    fi
}

lotto_port_in_use() {
    local port="$1"
    if command -v ss >/dev/null 2>&1; then
        ss -ltn "sport = :${port}" 2>/dev/null | grep -q ":${port} "
        return $?
    fi
    if command -v netstat >/dev/null 2>&1; then
        netstat -ltn 2>/dev/null | awk '{print $4}' | grep -q ":${port}$"
        return $?
    fi
    return 1
}

lotto_port_published_by_docker() {
    local port="$1"
    if ! command -v docker >/dev/null 2>&1; then
        return 1
    fi
    docker ps --format '{{.Ports}}' 2>/dev/null | grep -qE "(0\.0\.0\.0|127\.0\.0\.1|\[::\]):${port}->"
}

lotto_port_used_by_other_systemd_instance() {
    local port="$1"
    local skip_instance="$2"
    local prefix meta inst recorded
    prefix="${LOTTO_INSTANCE_ROOT_PREFIX}"
    shopt -s nullglob
    for meta in "${prefix}"*/config/deployment.json; do
        [[ -f "${meta}" ]] || continue
        inst="$(lotto_json_get "${meta}" instance)"
        [[ -n "${inst}" && "${inst}" != "${skip_instance}" ]] || continue
        recorded="$(lotto_json_get "${meta}" port)"
        if [[ "${recorded}" == "${port}" ]]; then
            shopt -u nullglob
            return 0
        fi
    done
    shopt -u nullglob
    return 1
}

lotto_assert_port_available() {
    local port="$1"
    local instance="$2"
    local allow_same="${3:-0}"

    lotto_assert_not_protected_port "${port}" || return 1

    if lotto_port_in_use "${port}"; then
        if [[ "${allow_same}" -eq 1 ]]; then
            return 0
        fi
        lotto_err "Port ${port} is already in use (listening socket)."
        return 1
    fi
    if lotto_port_published_by_docker "${port}"; then
        lotto_err "Port ${port} is published by a Docker container."
        return 1
    fi
    if lotto_port_used_by_other_systemd_instance "${port}" "${instance}"; then
        lotto_err "Port ${port} is assigned to another systemd Lotto instance."
        return 1
    fi
}

lotto_pick_free_systemd_port() {
    local start="${1:-${LOTTO_SYSTEMD_PICK_PORT_START}}"
    local end="${2:-${LOTTO_SYSTEMD_PICK_PORT_END}}"
    local port
    for ((port=start; port<=end; port++)); do
        if [[ "${port}" == "${LOTTO_PROTECTED_PORT}" ]]; then
            continue
        fi
        if lotto_port_in_use "${port}"; then
            continue
        fi
        if lotto_port_published_by_docker "${port}"; then
            continue
        fi
        if lotto_port_used_by_other_systemd_instance "${port}" ""; then
            continue
        fi
        echo "${port}"
        return 0
    done
    lotto_err "No free TCP port found in range ${start}-${end}."
    return 1
}

lotto_create_service_user() {
    local user="$1"
    local instance="$2"

    lotto_assert_not_protected_user "${user}" || return 1

    if id -u "${user}" >/dev/null 2>&1; then
        if lotto_instance_metadata_exists "${instance}"; then
            lotto_load_instance "${instance}"
            if [[ "${LOTTO_META_USER}" == "${user}" ]]; then
                echo "false"
                return 0
            fi
        fi
        lotto_err "User '${user}' already exists and is not owned by instance '${instance}' metadata."
        return 1
    fi

    useradd -r -s /usr/sbin/nologin -M -d "/nonexistent" "${user}"
    echo "true"
}

lotto_sync_app_source() {
    local dest="$1"
    mkdir -p "${dest}"
    rsync -a --delete \
        --exclude '.git/' \
        --exclude 'vendor/' \
        --exclude 'logs/' \
        --exclude '*.db' \
        --exclude '*.db-wal' \
        --exclude '*.db-shm' \
        --exclude 'deploy/' \
        --exclude '.lotto-deploy/' \
        "${LOTTO_REPO_ROOT}/" "${dest}/"
}

lotto_run_composer() {
    local app_path="$1"
    local user="$2"
    if ! command -v composer >/dev/null 2>&1; then
        lotto_err "Composer is required on the host for systemd deployment."
        return 1
    fi
    sudo -u "${user}" -H composer install \
        --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts \
        --working-dir="${app_path}"
}

lotto_ahpc_pending_path_systemd() {
    echo "$(lotto_config_path "$1")/admin-bootstrap.pending"
}

lotto_ahpc_ack_path_systemd() {
    echo "$(lotto_config_path "$1")/admin-bootstrap.ack"
}

lotto_promote_systemd_bootstrap_credential() {
    local instance="$1"
    local data pending ahpc_lib bootstrap_file

    ahpc_lib="${LOTTO_SYSTEMD_LIB_DIR}/../lib/admin-bootstrap-common.sh"
    # shellcheck source=../../lib/admin-bootstrap-common.sh
    source "${ahpc_lib}"

    data="$(lotto_data_path "${instance}")"
    bootstrap_file="${data}/.admin_bootstrap"
    pending="$(lotto_ahpc_pending_path_systemd "${instance}")"

    if [[ ! -f "${bootstrap_file}" ]]; then
        lotto_err "Bootstrap credential not found after init_db: ${bootstrap_file}"
        return 1
    fi

    lotto_ahpc_promote_bootstrap_file "${instance}" "${bootstrap_file}" "${pending}"
    chmod 600 "${pending}"
    chown root:root "${pending}"
}

lotto_write_env_file() {
    local instance="$1"
    local port="$2"
    local bind="$3"
    local allowed_origins="${4:-}"
    local trusted_proxy_ips="${5:-}"
    local max_accounts_per_ip="${6:-}"

    local env_file app data logs
    env_file="$(lotto_env_file "${instance}")"
    app="$(lotto_app_path "${instance}")"
    data="$(lotto_data_path "${instance}")"
    logs="$(lotto_logs_path "${instance}")"

    mkdir -p "$(dirname "${env_file}")"
    cat >"${env_file}" <<EOF
LOTTO_WS_PORT=${port}
LOTTO_DB_PATH=${data}/game.db
LOTTO_SERVER_LOG=${logs}/server.log
LOTTO_WORKERMAN_LOG_FILE=${logs}/workerman.log
LOTTO_WORKERMAN_PID_FILE=${data}/workerman.pid
LOTTO_ALLOWED_ORIGINS=${allowed_origins}
LOTTO_TRUSTED_PROXY_IPS=${trusted_proxy_ips}
LOTTO_MAX_ACCOUNTS_PER_IP=${max_accounts_per_ip}
LOTTO_ADMIN_BOOTSTRAP_FILE=${data}/.admin_bootstrap
EOF
    chmod 640 "${env_file}"
}

lotto_render_unit_file() {
    local unit_file="$1"
    local user="$2"
    local app="$3"
    local env_file="$4"
    local data="$5"
    local logs="$6"
    local config="$7"

    if [[ ! -f "${LOTTO_SYSTEMD_SERVICE_TEMPLATE}" ]]; then
        lotto_err "Missing service template: ${LOTTO_SYSTEMD_SERVICE_TEMPLATE}"
        return 1
    fi

    sed \
        -e "s|@LOTTO_USER@|${user}|g" \
        -e "s|@LOTTO_APP@|${app}|g" \
        -e "s|@LOTTO_ENV@|${env_file}|g" \
        -e "s|@LOTTO_DATA@|${data}|g" \
        -e "s|@LOTTO_LOGS@|${logs}|g" \
        -e "s|@LOTTO_CONFIG@|${config}|g" \
        "${LOTTO_SYSTEMD_SERVICE_TEMPLATE}" >"${unit_file}"
    chmod 644 "${unit_file}"
}

lotto_run_instance_healthcheck() {
    local port="$1"
    LOTTO_WS_PORT="${port}" php "${LOTTO_REPO_ROOT}/deploy/docker/healthcheck.php"
}

lotto_wait_for_instance_healthcheck() {
    local port="$1"
    local timeout="${2:-60}"
    local start now
    start=$(date +%s)
    while true; do
        if LOTTO_WS_PORT="${port}" php "${LOTTO_REPO_ROOT}/deploy/docker/healthcheck.php"; then
            return 0
        fi
        now=$(date +%s)
        if (( now - start >= timeout )); then
            lotto_err "Timed out waiting for WebSocket health on port ${port}."
            return 1
        fi
        sleep 2
    done
}

lotto_wait_for_active_unit() {
    local unit="$1"
    local timeout="${2:-60}"
    local start now
    start=$(date +%s)
    while true; do
        if systemctl is-active --quiet "${unit}"; then
            return 0
        fi
        now=$(date +%s)
        if (( now - start >= timeout )); then
            lotto_err "Timed out waiting for ${unit} to become active."
            systemctl status "${unit}" --no-pager || true
            return 1
        fi
        sleep 2
    done
}

lotto_cleanup_failed_install() {
    local instance="$1"
    local pending_path
    pending_path="$(lotto_ahpc_pending_path_systemd "${instance}" 2>/dev/null || true)"
    if [[ -n "${pending_path}" && -f "${pending_path}" ]]; then
        lotto_info "Pending AHPC credential preserved at ${pending_path}; skipping destructive cleanup."
        return 0
    fi
    local unit user root created_user
    unit="$(lotto_systemd_unit "${instance}")"
    user="$(lotto_service_user "${instance}")"
    root="$(lotto_instance_root "${instance}")"

    systemctl stop "${unit}" >/dev/null 2>&1 || true
    systemctl disable "${unit}" >/dev/null 2>&1 || true
    rm -f "$(lotto_unit_file "${instance}")"
    systemctl daemon-reload >/dev/null 2>&1 || true

    if [[ -d "${root}" ]]; then
        rm -rf "${root}"
    fi
    local backup
    backup="$(lotto_backup_dir "${instance}")"
    if [[ -d "${backup}" ]]; then
        rm -rf "${backup}"
    fi

    if id -u "${user}" >/dev/null 2>&1; then
        if ! lotto_instance_metadata_exists "${instance}"; then
            userdel "${user}" >/dev/null 2>&1 || true
        fi
    fi
}

# --- Epic B3: safe removal helpers (no update lifecycle) ---

lotto_validate_removal_metadata() {
    local instance="$1"
    local meta expected

    meta="$(lotto_metadata_file "${instance}")"
    if [[ ! -f "${meta}" ]]; then
        lotto_err "Metadata not found for instance '${instance}'."
        return 1
    fi

    if [[ "${LOTTO_META_SCHEMA}" != "${LOTTO_METADATA_SCHEMA}" ]]; then
        lotto_err "Unsupported metadata schema version '${LOTTO_META_SCHEMA}'."
        return 1
    fi

    local deployment_type
    deployment_type="$(lotto_json_get "${meta}" deployment_type)"
    if [[ "${deployment_type}" != "systemd" ]]; then
        lotto_err "Metadata deployment_type is not 'systemd'."
        return 1
    fi

    if [[ "${LOTTO_META_INSTANCE}" != "${instance}" ]]; then
        lotto_err "Metadata instance '${LOTTO_META_INSTANCE}' does not match '${instance}'."
        return 1
    fi

    expected="$(lotto_systemd_unit "${instance}")"
    if [[ "${LOTTO_META_UNIT}" != "${expected}" ]]; then
        lotto_err "Metadata unit '${LOTTO_META_UNIT}' does not match expected '${expected}'."
        return 1
    fi

    expected="$(lotto_service_user "${instance}")"
    if [[ "${LOTTO_META_USER}" != "${expected}" ]]; then
        lotto_err "Metadata service_user '${LOTTO_META_USER}' does not match expected '${expected}'."
        return 1
    fi

    if [[ "${LOTTO_META_APP}" != "$(lotto_app_path "${instance}")" ]]; then
        lotto_err "Metadata app_path does not match deterministic layout."
        return 1
    fi
    if [[ "${LOTTO_META_DATA}" != "$(lotto_data_path "${instance}")" ]]; then
        lotto_err "Metadata data_path does not match deterministic layout."
        return 1
    fi
    if [[ "${LOTTO_META_LOGS}" != "$(lotto_logs_path "${instance}")" ]]; then
        lotto_err "Metadata logs_path does not match deterministic layout."
        return 1
    fi
    if [[ "${LOTTO_META_CONFIG}" != "$(lotto_config_path "${instance}")" ]]; then
        lotto_err "Metadata config_path does not match deterministic layout."
        return 1
    fi
    if [[ "${LOTTO_META_BACKUP}" != "$(lotto_backup_dir "${instance}")" ]]; then
        lotto_err "Metadata backup_path does not match deterministic layout."
        return 1
    fi

    local meta_path_field
    meta_path_field="$(lotto_json_get "${meta}" metadata_path)"
    if [[ -n "${meta_path_field}" && "${meta_path_field}" != "${meta}" ]]; then
        lotto_err "Metadata metadata_path does not match expected location."
        return 1
    fi

    lotto_assert_not_protected_path "$(lotto_instance_root "${instance}")" || return 1
    lotto_assert_not_protected_unit "${LOTTO_META_UNIT}" || return 1
    lotto_assert_not_protected_user "${LOTTO_META_USER}" || return 1
}

lotto_assert_path_under_root() {
    local path="$1"
    local canon_root="$2"

    if [[ ! -e "${path}" && ! -L "${path}" ]]; then
        return 0
    fi

    local canon
    canon="$(lotto_canonical_path "${path}")"
    lotto_assert_not_protected_path "${canon}" || return 1
    if [[ "${canon}" != "${canon_root}" && "${canon}" != "${canon_root}/"* ]]; then
        lotto_err "Path '${path}' resolves outside instance root."
        return 1
    fi
}

lotto_assert_no_outside_symlinks() {
    local root="$1"
    local canon_root="$2"
    local path target canon_target base

    if [[ ! -e "${root}" ]]; then
        return 0
    fi

    while IFS= read -r path; do
        [[ -n "${path}" && -L "${path}" ]] || continue
        target="$(readlink "${path}" 2>/dev/null || true)"
        [[ -n "${target}" ]] || continue
        if [[ "${target}" != /* ]]; then
            base="$(cd "$(dirname "${path}")" 2>/dev/null && pwd)"
            target="${base}/${target}"
        fi
        canon_target="$(lotto_canonical_path "${target}")"
        if [[ "${canon_target}" != "${canon_root}" && "${canon_target}" != "${canon_root}/"* ]]; then
            lotto_err "Symlink '${path}' points outside instance root (${canon_target})."
            return 1
        fi
    done < <(find "${root}" 2>/dev/null || true)
}

lotto_assert_instance_tree_safe_for_removal() {
    local instance="$1"
    local root canon_root
    root="$(lotto_instance_root "${instance}")"

    lotto_assert_safe_instance_path "${root}" || return 1
    canon_root="$(lotto_canonical_path "${root}")"

    lotto_assert_path_under_root "${LOTTO_META_APP}" "${canon_root}" || return 1
    lotto_assert_path_under_root "${LOTTO_META_DATA}" "${canon_root}" || return 1
    lotto_assert_path_under_root "${LOTTO_META_LOGS}" "${canon_root}" || return 1
    lotto_assert_path_under_root "${LOTTO_META_CONFIG}" "${canon_root}" || return 1

    if [[ -e "${root}" || -L "${root}" ]]; then
        lotto_assert_no_outside_symlinks "${root}" "${canon_root}" || return 1
    fi
}

lotto_user_claimed_by_other_instance() {
    local user="$1"
    local skip_instance="$2"
    local prefix meta inst recorded_user
    prefix="${LOTTO_INSTANCE_ROOT_PREFIX}"
    shopt -s nullglob
    for meta in "${prefix}"*/config/deployment.json; do
        [[ -f "${meta}" ]] || continue
        inst="$(lotto_json_get "${meta}" instance)"
        [[ -n "${inst}" && "${inst}" != "${skip_instance}" ]] || continue
        recorded_user="$(lotto_json_get "${meta}" service_user)"
        if [[ "${recorded_user}" == "${user}" ]]; then
            shopt -u nullglob
            return 0
        fi
    done
    shopt -u nullglob
    return 1
}

lotto_instance_has_unmanaged_residuals() {
    local instance="$1"
    local root unit backup svc_unit
    root="$(lotto_instance_root "${instance}")"
    unit="$(lotto_unit_file "${instance}")"
    backup="$(lotto_backup_dir "${instance}")"
    svc_unit="$(lotto_systemd_unit "${instance}")"

    if [[ -e "${root}" ]]; then
        return 0
    fi
    if [[ -f "${unit}" ]]; then
        return 0
    fi
    if [[ -d "${backup}" ]]; then
        return 0
    fi
    if command -v systemctl >/dev/null 2>&1; then
        if systemctl is-active --quiet "${svc_unit}" 2>/dev/null; then
            return 0
        fi
    fi
    return 1
}

lotto_instance_fully_absent() {
    local instance="$1"
    if lotto_instance_metadata_exists "${instance}"; then
        return 1
    fi
    if lotto_instance_has_unmanaged_residuals "${instance}"; then
        return 1
    fi
    return 0
}

lotto_stop_disable_instance_unit() {
    local instance="$1"
    local unit
    unit="$(lotto_systemd_unit "${instance}")"

    lotto_assert_not_protected_unit "${unit}" || return 1

    if ! command -v systemctl >/dev/null 2>&1; then
        lotto_err "systemctl is required for systemd removal."
        return 1
    fi

    if systemctl is-active --quiet "${unit}" 2>/dev/null; then
        if ! systemctl stop "${unit}"; then
            lotto_err "Failed to stop ${unit}."
            return 1
        fi
    fi

    if systemctl is-failed --quiet "${unit}" 2>/dev/null; then
        systemctl reset-failed "${unit}" >/dev/null 2>&1 || true
    fi

    if systemctl is-enabled --quiet "${unit}" 2>/dev/null; then
        if ! systemctl disable "${unit}" >/dev/null; then
            lotto_err "Failed to disable ${unit}."
            return 1
        fi
    fi
}

lotto_assert_unit_not_active() {
    local instance="$1"
    local unit
    unit="$(lotto_systemd_unit "${instance}")"

    lotto_assert_not_protected_unit "${unit}" || return 1

    if ! command -v systemctl >/dev/null 2>&1; then
        return 0
    fi

    if systemctl is-active --quiet "${unit}" 2>/dev/null; then
        lotto_err "Service ${unit} is still active; refusing filesystem removal."
        return 1
    fi
}

lotto_remove_instance_unit_file() {
    local instance="$1"
    local unit unit_file
    unit="$(lotto_systemd_unit "${instance}")"
    unit_file="$(lotto_unit_file "${instance}")"

    lotto_assert_not_protected_unit "${unit}" || return 1

    if [[ -f "${unit_file}" ]]; then
        rm -f "${unit_file}"
    fi

    if command -v systemctl >/dev/null 2>&1; then
        systemctl daemon-reload >/dev/null 2>&1 || true
    fi
}

lotto_remove_instance_tree() {
    local instance="$1"
    local root
    root="$(lotto_instance_root "${instance}")"

    if [[ ! -e "${root}" && ! -L "${root}" ]]; then
        return 0
    fi

    lotto_assert_instance_tree_safe_for_removal "${instance}" || return 1
    rm -rf "${root}"
}

lotto_remove_instance_backup_dir() {
    local instance="$1"
    local backup expected canon_backup canon_expected canon_parent

    expected="$(lotto_backup_dir "${instance}")"
    backup="${expected}"

    if [[ ! -e "${backup}" && ! -L "${backup}" ]]; then
        return 0
    fi

    canon_expected="$(lotto_canonical_path "${expected}")"
    canon_backup="$(lotto_canonical_path "${backup}")"

    if [[ "${canon_backup}" != "${canon_expected}" ]]; then
        lotto_err "Backup path canonical mismatch."
        return 1
    fi

    canon_parent="$(lotto_canonical_path "${LOTTO_BACKUP_ROOT}")"
    if [[ "${canon_backup}" == "${canon_parent}" ]]; then
        lotto_err "Refusing to remove shared backup root '${LOTTO_BACKUP_ROOT}'."
        return 1
    fi

    if [[ "${canon_backup}" != "${canon_parent}/${instance}" && "${canon_backup}" != "${canon_parent}/${instance}/"* ]]; then
        lotto_err "Backup path is outside instance-specific backup directory."
        return 1
    fi

    rm -rf "${backup}"
}

lotto_remove_owned_service_user() {
    local instance="$1"
    local user created

    user="$(lotto_service_user "${instance}")"
    created="${LOTTO_META_CREATED_USER:-false}"

    lotto_assert_not_protected_user "${user}" || return 1

    if [[ "${created}" != "True" && "${created}" != "true" ]]; then
        return 0
    fi

    if [[ "${user}" != "$(lotto_service_user "${instance}")" ]]; then
        lotto_err "Service user mismatch during removal."
        return 1
    fi

    if lotto_user_claimed_by_other_instance "${user}" "${instance}"; then
        lotto_err "Service user '${user}' is referenced by another managed instance."
        return 1
    fi

    if id -u "${user}" >/dev/null 2>&1; then
        if ! userdel "${user}"; then
            lotto_err "Failed to remove service user '${user}'."
            return 1
        fi
    fi
}

lotto_verify_zero_artifacts() {
    local instance="$1"
    local expect_user_removed="${2:-false}"
    local issues=0

    if [[ -e "$(lotto_instance_root "${instance}")" ]]; then
        lotto_err "Instance root still exists: $(lotto_instance_root "${instance}")"
        issues=$((issues + 1))
    fi
    if lotto_instance_metadata_exists "${instance}"; then
        lotto_err "Metadata still exists for '${instance}'."
        issues=$((issues + 1))
    fi
    if [[ -f "$(lotto_unit_file "${instance}")" ]]; then
        lotto_err "Unit file still exists: $(lotto_unit_file "${instance}")"
        issues=$((issues + 1))
    fi
    if command -v systemctl >/dev/null 2>&1; then
        if systemctl is-active --quiet "$(lotto_systemd_unit "${instance}")" 2>/dev/null; then
            lotto_err "Service $(lotto_systemd_unit "${instance}") is still active."
            issues=$((issues + 1))
        fi
    fi
    if [[ -d "$(lotto_backup_dir "${instance}")" ]]; then
        lotto_err "Instance backup directory still exists."
        issues=$((issues + 1))
    fi
    if [[ -f "$(lotto_instance_lock_file "${instance}")" ]]; then
        lotto_err "Update lock file still exists: $(lotto_instance_lock_file "${instance}")"
        issues=$((issues + 1))
    fi
    if [[ "${expect_user_removed}" == "true" ]]; then
        if id -u "$(lotto_service_user "${instance}")" >/dev/null 2>&1; then
            lotto_err "Installer-owned service user still exists."
            issues=$((issues + 1))
        fi
    fi

    return $((issues > 0 ? 1 : 0))
}

# Simulate managed instance layout for tests (no systemd).
lotto_symlinks_supported() {
    local tmp target link
    tmp="$(mktemp -d)"
    target="$(mktemp -d)"
    link="${tmp}/probe-link"
    if ! ln -s "${target}" "${link}" 2>/dev/null; then
        rm -rf "${tmp}" "${target}"
        return 1
    fi
    if [[ -L "${link}" ]]; then
        rm -rf "${tmp}" "${target}"
        return 0
    fi
    rm -rf "${tmp}" "${target}" "${link}"
    return 1
}

lotto_test_create_managed_instance() {
    local instance="$1"
    local port="${2:-8099}"
    local created_user="${3:-true}"

    lotto_validate_instance_name "${instance}" || return 1
    mkdir -p "$(lotto_app_path "${instance}")" \
        "$(lotto_data_path "${instance}")" \
        "$(lotto_logs_path "${instance}")" \
        "$(lotto_config_path "${instance}")" \
        "$(lotto_backup_dir "${instance}")"
    touch "$(lotto_data_path "${instance}")/game.db"
    lotto_write_env_file "${instance}" "${port}" "127.0.0.1"
    lotto_write_metadata "${instance}" "${port}" "127.0.0.1" "${created_user}"
}

lotto_test_remove_managed_instance() {
    local instance="$1"

    lotto_load_instance "${instance}" || return 1
    lotto_validate_removal_metadata "${instance}" || return 1
    lotto_assert_instance_tree_safe_for_removal "${instance}" || return 1
    lotto_remove_instance_tree "${instance}" || return 1
    lotto_remove_instance_backup_dir "${instance}" || return 1
    lotto_remove_instance_lock_file "${instance}" || true

    local remove_user="false"
    if [[ "${LOTTO_META_CREATED_USER}" == "True" || "${LOTTO_META_CREATED_USER}" == "true" ]]; then
        remove_user="true"
    fi

    lotto_verify_zero_artifacts "${instance}" "${remove_user}"
}

# --- Epic C: update / operational lifecycle helpers ---

LOTTO_UPDATE_LOCK_DIR="/var/lock"
LOTTO_UPDATE_LOCK_FD=9

lotto_instance_lock_file() {
    local instance="$1"
    echo "${LOTTO_UPDATE_LOCK_DIR}/lotto-game-${instance}.lock"
}

lotto_remove_instance_lock_file() {
    local instance="$1"
    local lock_file
    lock_file="$(lotto_instance_lock_file "${instance}")"
    if [[ -f "${lock_file}" ]]; then
        rm -f "${lock_file}"
    fi
}

lotto_validate_update_metadata() {
    lotto_validate_removal_metadata "$@"
}

lotto_assert_managed_instance_installed() {
    local instance="$1"

    if ! lotto_instance_metadata_exists "${instance}"; then
        lotto_err "Instance '${instance}' is not installed (metadata missing)."
        return 1
    fi
    if [[ ! -d "$(lotto_instance_root "${instance}")" ]]; then
        lotto_err "Instance root missing for '${instance}'."
        return 1
    fi
    if [[ ! -f "$(lotto_env_file "${instance}")" ]]; then
        lotto_err "Environment file missing for '${instance}'."
        return 1
    fi
}

lotto_assert_env_file_valid() {
    local env_file="$1"
    local key

    if [[ ! -f "${env_file}" ]]; then
        lotto_err "Environment file not found: ${env_file}"
        return 1
    fi
    for key in LOTTO_WS_PORT LOTTO_DB_PATH LOTTO_SERVER_LOG LOTTO_WORKERMAN_LOG_FILE LOTTO_WORKERMAN_PID_FILE; do
        if ! grep -q "^${key}=" "${env_file}"; then
            lotto_err "Required environment key missing: ${key}"
            return 1
        fi
    done
}

lotto_acquire_update_lock() {
    local instance="$1"
    local lock_file
    lock_file="$(lotto_instance_lock_file "${instance}")"

    mkdir -p "${LOTTO_UPDATE_LOCK_DIR}"
    # shellcheck disable=SC2086
    eval "exec ${LOTTO_UPDATE_LOCK_FD}>\"${lock_file}\""
    if ! command -v flock >/dev/null 2>&1; then
        lotto_err "flock is required for concurrent update protection."
        return 1
    fi
    if ! flock -n "${LOTTO_UPDATE_LOCK_FD}"; then
        lotto_err "Another update is already running for instance '${instance}'."
        return 1
    fi
}

lotto_release_update_lock() {
    if command -v flock >/dev/null 2>&1; then
        flock -u "${LOTTO_UPDATE_LOCK_FD}" 2>/dev/null || true
    fi
}

lotto_stop_instance_for_update() {
    local instance="$1"
    local unit
    unit="$(lotto_systemd_unit "${instance}")"

    lotto_assert_not_protected_unit "${unit}" || return 1
    if ! command -v systemctl >/dev/null 2>&1; then
        lotto_err "systemctl is required for systemd update."
        return 1
    fi
    if systemctl is-active --quiet "${unit}" 2>/dev/null; then
        if ! systemctl stop "${unit}"; then
            lotto_err "Failed to stop ${unit} before update."
            return 1
        fi
    fi
}

lotto_start_instance_after_update() {
    local instance="$1"
    local unit
    unit="$(lotto_systemd_unit "${instance}")"

    lotto_assert_not_protected_unit "${unit}" || return 1
    if ! systemctl restart "${unit}"; then
        lotto_err "Failed to restart ${unit} after update."
        return 1
    fi
    lotto_wait_for_active_unit "${unit}" 60
}

lotto_unit_needs_refresh() {
    local instance="$1"
    local unit_file tmp user app env data logs config
    unit_file="$(lotto_unit_file "${instance}")"
    user="$(lotto_service_user "${instance}")"
    app="$(lotto_app_path "${instance}")"
    env="$(lotto_env_file "${instance}")"
    data="$(lotto_data_path "${instance}")"
    logs="$(lotto_logs_path "${instance}")"
    config="$(lotto_config_path "${instance}")"

    tmp="$(mktemp)"
    lotto_render_unit_file "${tmp}" "${user}" "${app}" "${env}" "${data}" "${logs}" "${config}"

    if [[ ! -f "${unit_file}" ]]; then
        rm -f "${tmp}"
        return 0
    fi
    if cmp -s "${tmp}" "${unit_file}"; then
        rm -f "${tmp}"
        return 1
    fi
    rm -f "${tmp}"
    return 0
}

lotto_refresh_instance_unit_if_needed() {
    local instance="$1"
    local unit_file user app env data logs config
    unit_file="$(lotto_unit_file "${instance}")"
    user="$(lotto_service_user "${instance}")"
    app="$(lotto_app_path "${instance}")"
    env="$(lotto_env_file "${instance}")"
    data="$(lotto_data_path "${instance}")"
    logs="$(lotto_logs_path "${instance}")"
    config="$(lotto_config_path "${instance}")"

    if ! lotto_unit_needs_refresh "${instance}"; then
        return 1
    fi

    lotto_render_unit_file "${unit_file}" "${user}" "${app}" "${env}" "${data}" "${logs}" "${config}"
    if command -v systemctl >/dev/null 2>&1; then
        systemctl daemon-reload >/dev/null 2>&1 || true
    fi
    return 0
}

lotto_refresh_metadata_timestamp() {
    local instance="$1"
    local meta created_at created_user cu port bind updated_at
    meta="$(lotto_metadata_file "${instance}")"

    lotto_load_instance "${instance}" || return 1
    created_at="$(lotto_json_get "${meta}" created_at)"
    port="${LOTTO_META_PORT}"
    bind="${LOTTO_META_BIND}"
    created_user="${LOTTO_META_CREATED_USER}"
    updated_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

    cu="false"
    if [[ "${created_user}" == "True" || "${created_user}" == "true" ]]; then
        cu="true"
    fi

    lotto_write_metadata "${instance}" "${port}" "${bind}" "${cu}" "${created_at}" "${updated_at}"
}

lotto_chown_app_tree() {
    local instance="$1"
    local user app
    user="$(lotto_service_user "${instance}")"
    app="$(lotto_app_path "${instance}")"
    chown -R "${user}:${user}" "${app}"
}

lotto_test_simulate_update() {
    local instance="$1"
    local env_before db_before meta_port env_file data_db

    lotto_load_instance "${instance}" || return 1
    lotto_validate_update_metadata "${instance}" || return 1
    lotto_assert_env_file_valid "$(lotto_env_file "${instance}")" || return 1

    env_file="$(lotto_env_file "${instance}")"
    data_db="$(lotto_data_path "${instance}")/game.db"
    env_before="$(cat "${env_file}")"
    db_before=""
    if [[ -f "${data_db}" ]]; then
        db_before="$(cat "${data_db}")"
    fi
    meta_port="${LOTTO_META_PORT}"

    lotto_sync_app_source "$(lotto_app_path "${instance}")"

    if [[ "$(cat "${env_file}")" != "${env_before}" ]]; then
        lotto_err "Environment file changed during update simulation."
        return 1
    fi
    if [[ -f "${data_db}" && -n "${db_before}" && "$(cat "${data_db}")" != "${db_before}" ]]; then
        lotto_err "Database changed during update simulation."
        return 1
    fi

    lotto_refresh_metadata_timestamp "${instance}" || return 1
    lotto_load_instance "${instance}"
    if [[ "${LOTTO_META_PORT}" != "${meta_port}" ]]; then
        lotto_err "Port changed unexpectedly during update."
        return 1
    fi
}
