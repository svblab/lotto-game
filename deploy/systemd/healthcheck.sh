#!/usr/bin/env bash
# Healthcheck for one generic systemd Lotto instance.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

INSTANCE="${LOTTO_DEFAULT_INSTANCE}"

usage() {
    cat <<'EOF'
Usage: sudo ./deploy/systemd/healthcheck.sh [INSTANCE] [--name NAME]

Verify unit is active and WebSocket health passes for a managed instance.

Examples:
  sudo ./deploy/systemd/healthcheck.sh demo
  sudo ./deploy/systemd/healthcheck.sh --name lotto-01
EOF
}

if [[ $# -gt 0 && "$1" != -* ]]; then
    INSTANCE="$1"
    shift
fi

while [[ $# -gt 0 ]]; do
    case "$1" in
        --name) INSTANCE="$2"; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) lotto_err "Unknown option: $1"; usage; exit 2 ;;
    esac
done

lotto_validate_instance_name "${INSTANCE}" || exit 1
if ! lotto_instance_metadata_exists "${INSTANCE}"; then
    lotto_err "Instance '${INSTANCE}' is not installed."
    exit 1
fi

lotto_load_instance "${INSTANCE}"
lotto_print_instance_context "${INSTANCE}" "healthcheck"

if ! systemctl is-active --quiet "${LOTTO_META_UNIT}"; then
    lotto_err "Unit ${LOTTO_META_UNIT} is not active."
    exit 1
fi

lotto_run_instance_healthcheck "${LOTTO_META_PORT}"
lotto_info "Healthcheck passed for instance '${INSTANCE}'."
