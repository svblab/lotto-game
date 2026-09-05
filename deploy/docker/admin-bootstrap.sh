#!/usr/bin/env bash
# ADR-038 — Docker AHPC credential CLI.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"
# shellcheck source=../lib/admin-bootstrap-common.sh
source "${SCRIPT_DIR}/../lib/admin-bootstrap-common.sh"

INSTANCE="${LOTTO_DEFAULT_INSTANCE}"
COMMAND=""
FORMAT="human"

usage() {
    cat <<'EOF'
Usage: sudo ./deploy/docker/admin-bootstrap.sh --name <instance> <command> [options]

Commands:
  status       Show pending credential state (no password)
  read         Output pending credential (TTY or --format=json only)
  acknowledge  Remove pending credential after operator confirmation
  reset        Generate a new admin password and pending credential

Options:
  --name NAME       Instance name (default: default)
  --format FORMAT   human|json (default: human)
  -h, --help        Show this help

Exit codes:
  0  success
  2  no pending credential
  3  unknown instance
  4  corrupt pending credential
  10 reset refused
EOF
}

lotto_ahpc_docker_paths() {
    local instance="$1"
    local pending ack
    pending="$(lotto_ahpc_pending_path_docker "${instance}")"
    ack="$(lotto_ahpc_ack_path_docker "${instance}")"
    echo "${pending}|${ack}"
}

lotto_ahpc_docker_reset() {
    local instance="$1"
    local pending volume image bootstrap_tmp password

    if ! lotto_instance_metadata_exists "${instance}"; then
        return 3
    fi
    lotto_load_instance_env "${instance}"

    pending="$(lotto_ahpc_pending_path_docker "${instance}")"
    if [[ -f "${pending}" ]]; then
        lotto_ahpc_err "Pending credential already exists; acknowledge before reset."
        return 10
    fi

    volume="${LOTTO_VOLUME_NAME}"
    image="${LOTTO_IMAGE}"
    if ! lotto_volume_exists "${volume}"; then
        lotto_ahpc_err "Instance volume missing."
        return 10
    fi

    bootstrap_tmp="$(mktemp)"
    chmod 600 "${bootstrap_tmp}"
    trap 'rm -f "${bootstrap_tmp}"' RETURN

    docker run --rm \
        --user "${LOTTO_DATA_UID}:${LOTTO_DATA_GID}" \
        -v "${volume}:/app/data" \
        -v "${LOTTO_REPO_ROOT}/deploy/lib/reset_admin_bootstrap.php:/app/reset_admin_bootstrap.php:ro" \
        -e LOTTO_DB_PATH=/app/data/game.db \
        -e LOTTO_ADMIN_BOOTSTRAP_FILE=/app/data/.admin_bootstrap_reset \
        --entrypoint php \
        "${image}" \
        /app/reset_admin_bootstrap.php || return 10

    docker run --rm \
        --entrypoint cat \
        -v "${volume}:/app/data:ro" \
        "${image}" \
        /app/data/.admin_bootstrap_reset >"${bootstrap_tmp}"

    docker run --rm \
        --entrypoint rm \
        -v "${volume}:/app/data" \
        "${image}" \
        -f /app/data/.admin_bootstrap_reset >/dev/null 2>&1 || true

    password="$(lotto_ahpc_parse_bootstrap_file "${bootstrap_tmp}")" || return 10
    lotto_ahpc_write_pending_atomic "${pending}" "${instance}" "${password}"
    rm -f "${bootstrap_tmp}"
    trap - RETURN
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --name) INSTANCE="$2"; shift 2 ;;
        --format) FORMAT="$2"; shift 2 ;;
        status|read|acknowledge|reset) COMMAND="$1"; shift ;;
        -h|--help) usage; exit 0 ;;
        *) lotto_ahpc_err "Unknown argument: $1"; usage; exit 2 ;;
    esac
done

if [[ -z "${COMMAND}" ]]; then
    lotto_ahpc_err "Missing command."
    usage
    exit 2
fi

if [[ "${EUID}" -ne 0 ]]; then
    lotto_ahpc_err "Run as root (sudo)."
    exit 1
fi

lotto_validate_instance_name "${INSTANCE}" || exit 3
if ! lotto_instance_metadata_exists "${INSTANCE}"; then
    lotto_ahpc_err "Unknown instance '${INSTANCE}'."
    exit 3
fi

read -r PENDING_PATH ACK_PATH < <(lotto_ahpc_docker_paths "${INSTANCE}" | tr '|' ' ')

case "${COMMAND}" in
    status)
        if [[ "${FORMAT}" == "json" ]]; then
            lotto_ahpc_emit_status_json "${INSTANCE}" "${PENDING_PATH}" "${ACK_PATH}"
        else
            lotto_ahpc_emit_status_human "${INSTANCE}" "${PENDING_PATH}" "${ACK_PATH}"
        fi
        ;;
    read)
        if [[ ! -f "${PENDING_PATH}" ]]; then
            exit 2
        fi
        if [[ "${FORMAT}" == "json" ]]; then
            lotto_ahpc_emit_read_json "${INSTANCE}" "${PENDING_PATH}"
        else
            if [[ ! -t 1 ]]; then
                lotto_ahpc_err "Password output requires a controlling terminal; use --format=json for automation."
                exit 1
            fi
            password="$(lotto_ahpc_read_pending_fields "${PENDING_PATH}" password)" || exit 4
            echo "${password}"
        fi
        ;;
    acknowledge)
        lotto_ahpc_acknowledge "${INSTANCE}" "${PENDING_PATH}" "${ACK_PATH}"
        ;;
    reset)
        lotto_ahpc_docker_reset "${INSTANCE}"
        ;;
esac
