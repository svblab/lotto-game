#!/usr/bin/env bash
# Run the in-container WebSocket healthcheck against a running Docker instance.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

INSTANCE="${LOTTO_DEFAULT_INSTANCE}"

usage() {
    cat <<'EOF'
Usage: sudo ./deploy/docker/healthcheck.sh [--name NAME]
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --name) INSTANCE="$2"; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) lotto_err "Unknown option: $1"; usage; exit 2 ;;
    esac
done

lotto_validate_instance_name "${INSTANCE}"
lotto_docker_check

if ! lotto_instance_metadata_exists "${INSTANCE}"; then
    lotto_err "Instance '${INSTANCE}' is not installed."
    exit 1
fi

lotto_load_instance_env "${INSTANCE}"

CID="$(lotto_compose_cmd "${INSTANCE}" ps -q app 2>/dev/null || true)"
if [[ -z "${CID}" ]]; then
    lotto_err "Instance '${INSTANCE}' is not running."
    exit 1
fi

docker exec "${CID}" php /app/healthcheck.php
