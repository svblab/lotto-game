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
