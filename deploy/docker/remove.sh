#!/usr/bin/env bash
# Remove one Lotto Game Docker instance and its owned artifacts.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

INSTANCE="${LOTTO_DEFAULT_INSTANCE}"
ASSUME_YES=0

usage() {
    cat <<'EOF'
Usage: sudo ./deploy/docker/remove.sh [options]

Options:
  --name NAME   Instance name (default: default)
  --yes         Non-interactive confirmation
  -h, --help    Show this help
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --name) INSTANCE="$2"; shift 2 ;;
        --yes) ASSUME_YES=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) lotto_err "Unknown option: $1"; usage; exit 2 ;;
    esac
done

lotto_validate_instance_name "${INSTANCE}"
lotto_docker_check

if ! lotto_instance_metadata_exists "${INSTANCE}"; then
    lotto_err "Instance '${INSTANCE}' is not installed (no metadata at $(lotto_instance_dir "${INSTANCE}"))."
    exit 1
fi

lotto_load_instance_env "${INSTANCE}"

if [[ "${ASSUME_YES}" -ne 1 ]]; then
    read -r -p "Remove Lotto Docker instance '${INSTANCE}' and ALL its data? [y/N] " reply
    case "${reply}" in
        y|Y|yes|YES) ;;
        *) lotto_info "Aborted."; exit 0 ;;
    esac
fi

lotto_info "Stopping instance '${INSTANCE}'..."
lotto_compose_cmd "${INSTANCE}" down --remove-orphans --volumes >/dev/null 2>&1 || true

if lotto_volume_exists "${LOTTO_VOLUME_NAME}"; then
    lotto_info "Removing volume ${LOTTO_VOLUME_NAME}..."
    docker volume rm "${LOTTO_VOLUME_NAME}" >/dev/null 2>&1 || {
        lotto_err "Failed to remove volume ${LOTTO_VOLUME_NAME}."
        exit 1
    }
fi

if docker network inspect "${LOTTO_NETWORK_NAME}" >/dev/null 2>&1; then
    docker network rm "${LOTTO_NETWORK_NAME}" >/dev/null 2>&1 || true
fi

if docker image inspect "${LOTTO_IMAGE}" >/dev/null 2>&1; then
    if lotto_image_used_by_other_instances "${LOTTO_IMAGE}" "${INSTANCE}"; then
        lotto_info "Keeping image ${LOTTO_IMAGE} (referenced by another instance)."
    else
        lotto_info "Removing image ${LOTTO_IMAGE}..."
        docker image rm "${LOTTO_IMAGE}" >/dev/null 2>&1 || true
    fi
fi

rm -rf "$(lotto_instance_dir "${INSTANCE}")"

FAIL=0
if docker ps -a --format '{{.Names}}' | grep -qx "${LOTTO_CONTAINER_NAME}"; then
    lotto_err "Container still exists: ${LOTTO_CONTAINER_NAME}"
    FAIL=1
fi
if lotto_volume_exists "${LOTTO_VOLUME_NAME}"; then
    lotto_err "Volume still exists: ${LOTTO_VOLUME_NAME}"
    FAIL=1
fi
if [[ -d "$(lotto_instance_dir "${INSTANCE}")" ]]; then
    lotto_err "Metadata directory still exists."
    FAIL=1
fi

if [[ "${FAIL}" -ne 0 ]]; then
    lotto_err "Removal verification failed for instance '${INSTANCE}'."
    exit 1
fi

lotto_info "Docker instance '${INSTANCE}' removed."
