#!/usr/bin/env bash
# Docker deployment helpers for deploy/docker/*.sh

set -euo pipefail

LOTTO_DEPLOY_LIB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOTTO_REPO_ROOT="$(cd "${LOTTO_DEPLOY_LIB_DIR}/../../.." && pwd)"
LOTTO_COMPOSE_FILE="${LOTTO_REPO_ROOT}/deploy/docker/compose.yaml"
LOTTO_DEFAULT_STATE_ROOT="/var/lib/lotto-game"
LOTTO_DEFAULT_INSTANCE="default"
LOTTO_DEFAULT_CONTAINER_PORT="8080"
LOTTO_DEFAULT_BIND_ADDRESS="127.0.0.1"
LOTTO_DATA_UID="1000"
LOTTO_DATA_GID="1000"

lotto_err() {
    echo "ERROR: $*" >&2
}

lotto_info() {
    echo "$*"
}

lotto_state_root() {
    echo "${LOTTO_STATE_ROOT:-${LOTTO_DEFAULT_STATE_ROOT}}"
}

lotto_instance_dir() {
    local instance="$1"
    echo "$(lotto_state_root)/${instance}"
}

lotto_instance_env_file() {
    local instance="$1"
    echo "$(lotto_instance_dir "${instance}")/instance.env"
}

lotto_validate_instance_name() {
    local name="$1"
    if [[ ! "${name}" =~ ^[a-zA-Z0-9][a-zA-Z0-9_-]{0,62}$ ]]; then
        lotto_err "Invalid instance name '${name}'. Use 1-63 chars: letters, digits, '_' or '-'."
        return 1
    fi
}

lotto_docker_check() {
    if ! command -v docker >/dev/null 2>&1; then
        lotto_err "Docker Engine is not installed or not in PATH."
        lotto_err "Install Docker on Debian/Ubuntu, then re-run this script."
        return 1
    fi
    if ! docker info >/dev/null 2>&1; then
        lotto_err "Docker daemon is not reachable. Start Docker and ensure you have permission (often: run with sudo)."
        return 1
    fi
    if ! docker compose version >/dev/null 2>&1; then
        lotto_err "Docker Compose plugin is not available (expected: docker compose)."
        return 1
    fi
}

lotto_os_check() {
    if [[ "$(uname -s)" != "Linux" ]]; then
        lotto_err "This deployment helper targets Linux (Debian/Ubuntu VPS)."
        return 1
    fi
    if [[ -r /etc/os-release ]]; then
        # shellcheck disable=SC1091
        source /etc/os-release
        case "${ID:-}" in
            debian|ubuntu) ;;
            *)
                lotto_err "Unsupported distribution '${ID:-unknown}'. Debian/Ubuntu expected."
                return 1
                ;;
        esac
    fi
}

lotto_repo_check() {
    if [[ ! -f "${LOTTO_REPO_ROOT}/server.php" || ! -f "${LOTTO_REPO_ROOT}/composer.json" ]]; then
        lotto_err "Run this script from a lotto-game Git checkout (missing server.php/composer.json)."
        return 1
    fi
}

# Provisioning contract (ADR-027 + Docker): the VPS static hostname must be the public
# FQDN before deploy, e.g. `sudo hostnamectl set-hostname rusbingo.online`.
# Short machine names (box-963286) are NOT converted to a DNS domain.
lotto_detect_provisioning_fqdn() {
    local candidate=""

    if [[ -n "${LOTTO_PROVISIONING_FQDN_OVERRIDE:-}" ]]; then
        candidate="${LOTTO_PROVISIONING_FQDN_OVERRIDE}"
    elif command -v hostnamectl >/dev/null 2>&1; then
        candidate="$(hostnamectl status 2>/dev/null | awk -F': ' '/Static hostname/ {gsub(/^[ \t]+/,"",$2); print $2; exit}')"
    fi
    if [[ -z "${candidate}" && -f /etc/hostname ]]; then
        candidate="$(tr -d '[:space:]' < /etc/hostname)"
    fi

    if [[ ! "${candidate}" =~ ^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$ ]]; then
        lotto_err "Provisioning FQDN not configured (static hostname must be a public domain)."
        lotto_err "Before deploy run: sudo hostnamectl set-hostname your.domain.example"
        lotto_err "Current static hostname: '${candidate:-<empty>}'"
        return 1
    fi

    echo "${candidate}"
}

lotto_validate_fqdn_dns() {
    local fqdn="$1"
    local resolved=""

    if command -v getent >/dev/null 2>&1; then
        resolved="$(getent ahosts "${fqdn}" 2>/dev/null | awk '/STREAM|RAW/ {print $1; exit}')"
    fi
    if [[ -z "${resolved}" ]] && command -v dig >/dev/null 2>&1; then
        resolved="$(dig +short "${fqdn}" A 2>/dev/null | head -1)"
    fi
    if [[ -z "${resolved}" ]]; then
        lotto_err "FQDN '${fqdn}' does not resolve to an A record."
        return 1
    fi
    echo "${resolved}"
}

lotto_https_origin_for_fqdn() {
    local fqdn="$1"
    echo "https://${fqdn}"
}

lotto_compose_cmd() {
    local instance="$1"
    shift
    local env_file
    env_file="$(lotto_instance_env_file "${instance}")"
    docker compose \
        -f "${LOTTO_COMPOSE_FILE}" \
        --env-file "${env_file}" \
        -p "lotto-${instance}" \
        "$@"
}

lotto_instance_metadata_exists() {
    local instance="$1"
    [[ -f "$(lotto_instance_env_file "${instance}")" ]]
}

lotto_volume_exists() {
    local volume_name="$1"
    docker volume inspect "${volume_name}" >/dev/null 2>&1
}

# Fresh Docker named volumes mount at /app/data as root:root. The app service runs as
# uid 1000 (see compose.yaml). Prepare ownership once before init_db / first start.
lotto_prepare_data_volume() {
    local volume_name="$1"
    local image="$2"

    if ! lotto_volume_exists "${volume_name}"; then
        docker volume create "${volume_name}" >/dev/null
    fi

    docker run --rm --user root \
        -v "${volume_name}:/app/data" \
        --entrypoint sh \
        "${image}" \
        -c "chown ${LOTTO_DATA_UID}:${LOTTO_DATA_GID} /app/data && chmod 750 /app/data"
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

lotto_pick_free_port() {
    local start="${1:-8080}"
    local end="${2:-8999}"
    local port
    for ((port=start; port<=end; port++)); do
        if ! lotto_port_in_use "${port}"; then
            echo "${port}"
            return 0
        fi
    done
    lotto_err "No free TCP port found in range ${start}-${end}."
    return 1
}

lotto_write_instance_env() {
    local instance="$1"
    local host_port="$2"
    local bind_address="$3"
    local container_port="$4"
    local mem_limit="$5"
    local cpu_limit="$6"
    local pids_limit="$7"
    local allowed_origins="${8:-}"
    local trusted_proxy_ips="${9:-}"
    local max_accounts_per_ip="${10:-}"

    local dir image volume network container
    dir="$(lotto_instance_dir "${instance}")"
    mkdir -p "${dir}"
    chmod 755 "${dir}"

    image="lotto-game:${instance}"
    volume="lotto-${instance}-data"
    network="lotto-${instance}-net"
    container="lotto-${instance}-app"

    cat > "$(lotto_instance_env_file "${instance}")" <<EOF
LOTTO_INSTANCE=${instance}
LOTTO_IMAGE=${image}
LOTTO_BUILD_CONTEXT=${LOTTO_REPO_ROOT}
LOTTO_CONTAINER_NAME=${container}
LOTTO_VOLUME_NAME=${volume}
LOTTO_NETWORK_NAME=${network}
LOTTO_HOST_PORT=${host_port}
LOTTO_CONTAINER_PORT=${container_port}
LOTTO_BIND_ADDRESS=${bind_address}
LOTTO_MEM_LIMIT=${mem_limit}
LOTTO_CPU_LIMIT=${cpu_limit}
LOTTO_PIDS_LIMIT=${pids_limit}
LOTTO_ALLOWED_ORIGINS=${allowed_origins}
LOTTO_TRUSTED_PROXY_IPS=${trusted_proxy_ips}
LOTTO_MAX_ACCOUNTS_PER_IP=${max_accounts_per_ip}
EOF
    chmod 600 "$(lotto_instance_env_file "${instance}")"
}

lotto_load_instance_env() {
    local instance="$1"
    local env_file
    env_file="$(lotto_instance_env_file "${instance}")"
    if [[ ! -f "${env_file}" ]]; then
        lotto_err "Missing instance metadata: ${env_file}"
        return 1
    fi
    # shellcheck disable=SC1090
    source "${env_file}"
}

lotto_image_used_by_other_instances() {
    local image="$1"
    local skip_instance="$2"
    local state_root env_file
    state_root="$(lotto_state_root)"
    if [[ ! -d "${state_root}" ]]; then
        return 1
    fi
    for env_file in "${state_root}"/*/instance.env; do
        [[ -f "${env_file}" ]] || continue
        # shellcheck disable=SC1090
        source "${env_file}"
        if [[ "${LOTTO_INSTANCE:-}" == "${skip_instance}" ]]; then
            continue
        fi
        if [[ "${LOTTO_IMAGE:-}" == "${image}" ]]; then
            return 0
        fi
    done
    return 1
}

lotto_ahpc_pending_path_docker() {
    echo "$(lotto_instance_dir "$1")/admin-bootstrap.pending"
}

lotto_ahpc_ack_path_docker() {
    echo "$(lotto_instance_dir "$1")/admin-bootstrap.ack"
}

lotto_promote_docker_bootstrap_credential() {
    local instance="$1"
    local image="$2"
    local volume_name="$3"
    local bootstrap_host_tmp pending_path ahpc_lib

    ahpc_lib="${LOTTO_DEPLOY_LIB_DIR}/../lib/admin-bootstrap-common.sh"
    # shellcheck source=../../lib/admin-bootstrap-common.sh
    source "${ahpc_lib}"

    bootstrap_host_tmp="$(mktemp)"
    chmod 600 "${bootstrap_host_tmp}"
    if ! docker run --rm \
        --entrypoint cat \
        -v "${volume_name}:/app/data:ro" \
        "${image}" \
        /app/data/.admin_bootstrap >"${bootstrap_host_tmp}" 2>/dev/null; then
        rm -f "${bootstrap_host_tmp}"
        lotto_err "Bootstrap credential not found in volume after init_db."
        return 1
    fi

    pending_path="$(lotto_ahpc_pending_path_docker "${instance}")"
    lotto_ahpc_promote_bootstrap_file "${instance}" "${bootstrap_host_tmp}" "${pending_path}"
    rm -f "${bootstrap_host_tmp}"

    lotto_delete_bootstrap_from_volume "${image}" "${volume_name}"
}

lotto_delete_bootstrap_from_volume() {
    local image="$1"
    local volume_name="$2"
    docker run --rm \
        --entrypoint rm \
        -v "${volume_name}:/app/data" \
        "${image}" \
        -f /app/data/.admin_bootstrap >/dev/null 2>&1 || true
}

lotto_wait_healthy() {
    local instance="$1"
    local timeout="${2:-120}"
    local start now cid status
    start=$(date +%s)
    cid="$(lotto_compose_cmd "${instance}" ps -q app 2>/dev/null || true)"
    if [[ -z "${cid}" ]]; then
        lotto_err "Container did not start."
        return 1
    fi
    while true; do
        status="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "${cid}" 2>/dev/null || echo missing)"
        if [[ "${status}" == "healthy" ]]; then
            return 0
        fi
        if [[ "${status}" == "unhealthy" ]]; then
            lotto_err "Container healthcheck failed."
            lotto_compose_cmd "${instance}" logs --tail 40 app || true
            return 1
        fi
        now=$(date +%s)
        if (( now - start >= timeout )); then
            lotto_err "Timed out waiting for container health (last status: ${status})."
            lotto_compose_cmd "${instance}" logs --tail 40 app || true
            return 1
        fi
        sleep 2
    done
}

lotto_cleanup_partial_instance() {
    local instance="$1"
    local pending_path
    pending_path="$(lotto_ahpc_pending_path_docker "${instance}" 2>/dev/null || true)"
    if [[ -n "${pending_path}" && -f "${pending_path}" ]]; then
        lotto_info "Pending AHPC credential preserved at ${pending_path}; skipping destructive cleanup."
        return 0
    fi
    lotto_load_instance_env "${instance}" 2>/dev/null || return 0
    lotto_compose_cmd "${instance}" down --remove-orphans >/dev/null 2>&1 || true
    if [[ -n "${LOTTO_VOLUME_NAME:-}" ]] && lotto_volume_exists "${LOTTO_VOLUME_NAME}"; then
        docker volume rm "${LOTTO_VOLUME_NAME}" >/dev/null 2>&1 || true
    fi
    if [[ -n "${LOTTO_NETWORK_NAME:-}" ]]; then
        docker network rm "${LOTTO_NETWORK_NAME}" >/dev/null 2>&1 || true
    fi
    if [[ -n "${LOTTO_IMAGE:-}" ]] && ! lotto_image_used_by_other_instances "${LOTTO_IMAGE}" "${instance}"; then
        docker image rm "${LOTTO_IMAGE}" >/dev/null 2>&1 || true
    fi
    rm -rf "$(lotto_instance_dir "${instance}")" 2>/dev/null || true
}
