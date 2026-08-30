#!/usr/bin/env bash
# Remove one generic systemd Lotto Game instance (Epic B3).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

INSTANCE="${LOTTO_DEFAULT_INSTANCE}"
CREATED_USER="false"

usage() {
    cat <<'EOF'
Usage: sudo ./deploy/systemd/remove.sh [INSTANCE]

Safely remove a generic systemd Lotto instance installed by deploy/systemd/install.sh.

Examples:
  sudo ./deploy/systemd/remove.sh demo
  sudo ./deploy/systemd/remove.sh lotto-01
EOF
}

if [[ $# -gt 0 && "$1" != -* ]]; then
    INSTANCE="$1"
    shift
fi

while [[ $# -gt 0 ]]; do
    case "$1" in
        -h|--help) usage; exit 0 ;;
        *) lotto_err "Unknown option: $1"; usage; exit 2 ;;
    esac
done

lotto_require_root
lotto_os_check

lotto_validate_instance_name "${INSTANCE}" || exit 1

ROOT="$(lotto_instance_root "${INSTANCE}")"
UNIT="$(lotto_systemd_unit "${INSTANCE}")"
USER="$(lotto_service_user "${INSTANCE}")"

lotto_assert_not_protected_path "${ROOT}" || exit 1
lotto_assert_not_protected_unit "${UNIT}" || exit 1
lotto_assert_not_protected_user "${USER}" || exit 1

if lotto_instance_fully_absent "${INSTANCE}"; then
    lotto_info "Instance '${INSTANCE}' is already absent."
    exit 0
fi

if ! lotto_instance_metadata_exists "${INSTANCE}"; then
    lotto_err "Cannot establish ownership: metadata missing but residual resources exist for '${INSTANCE}'."
    lotto_err "Refusing blind deletion. Restore metadata or remove resources manually after verification."
    exit 1
fi

lotto_load_instance "${INSTANCE}" || exit 1
lotto_validate_removal_metadata "${INSTANCE}" || exit 1

if [[ "${LOTTO_META_CREATED_USER}" == "True" || "${LOTTO_META_CREATED_USER}" == "true" ]]; then
    CREATED_USER="true"
fi

lotto_info "Removing systemd instance '${INSTANCE}'..."

if [[ -f "$(lotto_unit_file "${INSTANCE}")" ]] || systemctl cat "${UNIT}" >/dev/null 2>&1; then
    lotto_stop_disable_instance_unit "${INSTANCE}" || exit 1
    lotto_assert_unit_not_active "${INSTANCE}" || exit 1
    lotto_remove_instance_unit_file "${INSTANCE}" || exit 1
elif systemctl is-active --quiet "${UNIT}" 2>/dev/null; then
    lotto_err "Service ${UNIT} is active but unit file is missing; cannot establish safe removal."
    exit 1
fi

if [[ -e "${ROOT}" || -L "${ROOT}" ]]; then
    lotto_remove_instance_tree "${INSTANCE}" || exit 1
fi

lotto_remove_instance_backup_dir "${INSTANCE}" || exit 1

if [[ "${CREATED_USER}" == "true" ]]; then
    lotto_remove_owned_service_user "${INSTANCE}" || exit 1
else
    lotto_info "Preserving service user '${USER}' (created_user=false)."
fi

if ! lotto_verify_zero_artifacts "${INSTANCE}" "${CREATED_USER}"; then
    lotto_err "Removal incomplete for instance '${INSTANCE}'."
    exit 1
fi

lotto_info ""
lotto_info "Lotto Game systemd instance '${INSTANCE}' removed successfully."
lotto_info "  Zero-artifact verification: PASS"
