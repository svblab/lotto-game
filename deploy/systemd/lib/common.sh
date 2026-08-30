#!/usr/bin/env bash
# Systemd deployment foundation (Epic B1): identity, layout, metadata, guards.
# Lifecycle install/remove/update belongs to epics B2/B3/C — not implemented here.

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

# Write deployment metadata (JSON). Used by tests and future B2 install.
# Does not create directories or touch production paths unless caller sets temp roots.
lotto_write_metadata() {
    local instance="$1"
    local port="$2"
    local bind="${3:-${LOTTO_DEFAULT_BIND}}"
    local created_user="${4:-false}"
    local created_at="${5:-$(date -u +%Y-%m-%dT%H:%M:%SZ)}"

    lotto_validate_instance_name "${instance}" || return 1
    lotto_validate_port_number "${port}" || return 1

    local meta_path
    meta_path="$(lotto_metadata_file "${instance}")"
    lotto_assert_not_protected_path "$(lotto_instance_root "${instance}")" || return 1

    mkdir -p "$(dirname "${meta_path}")"

    cat >"${meta_path}" <<EOF
{
  "schema_version": ${LOTTO_METADATA_SCHEMA},
  "deployment_type": "systemd",
  "instance": "${instance}",
  "created_at": "${created_at}",
  "app_path": "$(lotto_app_path "${instance}")",
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
    echo "${line}" | sed -E 's/.*: *"?([^",}]*)"?,.*/\1/; s/.*: *"?([^",}]*)"?/\1/'
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
