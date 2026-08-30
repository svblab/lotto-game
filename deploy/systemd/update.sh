#!/usr/bin/env bash
# Update one generic systemd Lotto Game instance (Epic C).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

INSTANCE="${LOTTO_DEFAULT_INSTANCE}"
UPDATE_STAGE="init"
SERVICE_STOPPED=0

usage() {
    cat <<'EOF'
Usage: sudo ./deploy/systemd/update.sh [INSTANCE]

Safely refresh application source and dependencies for a managed systemd instance.

Examples:
  sudo ./deploy/systemd/update.sh demo
  sudo ./deploy/systemd/update.sh lotto-01
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

cleanup_on_failure() {
    local code=$?
    lotto_release_update_lock
    if [[ "${code}" -ne 0 ]]; then
        lotto_err "Update failed at stage: ${UPDATE_STAGE}"
        if [[ "${SERVICE_STOPPED}" -eq 1 ]]; then
            lotto_err "Service was stopped and left inactive after failure (database and configuration preserved)."
            systemctl stop "$(lotto_systemd_unit "${INSTANCE}")" >/dev/null 2>&1 || true
        fi
    fi
}
trap cleanup_on_failure EXIT

lotto_require_root
lotto_os_check
lotto_repo_check

UPDATE_STAGE="validate instance"
lotto_validate_instance_name "${INSTANCE}" || exit 1

ROOT="$(lotto_instance_root "${INSTANCE}")"
UNIT="$(lotto_systemd_unit "${INSTANCE}")"
USER="$(lotto_service_user "${INSTANCE}")"
APP="$(lotto_app_path "${INSTANCE}")"
ENV_FILE="$(lotto_env_file "${INSTANCE}")"

lotto_assert_not_protected_path "${ROOT}" || exit 1
lotto_assert_not_protected_unit "${UNIT}" || exit 1
lotto_assert_not_protected_user "${USER}" || exit 1

UPDATE_STAGE="acquire lock"
lotto_acquire_update_lock "${INSTANCE}" || exit 1

UPDATE_STAGE="verify installation"
lotto_assert_managed_instance_installed "${INSTANCE}" || exit 1
lotto_load_instance "${INSTANCE}" || exit 1
lotto_validate_update_metadata "${INSTANCE}" || exit 1
lotto_assert_env_file_valid "${ENV_FILE}" || exit 1
lotto_assert_not_protected_port "${LOTTO_META_PORT}" || exit 1

HOST_PORT="${LOTTO_META_PORT}"
lotto_print_instance_context "${INSTANCE}" "update" "${HOST_PORT}"

CREATED_USER="false"
if [[ "${LOTTO_META_CREATED_USER}" == "True" || "${LOTTO_META_CREATED_USER}" == "true" ]]; then
    CREATED_USER="true"
fi

lotto_info "Updating systemd instance '${INSTANCE}'..."

UPDATE_STAGE="stop service"
lotto_stop_instance_for_update "${INSTANCE}"
SERVICE_STOPPED=1

UPDATE_STAGE="sync application"
lotto_sync_app_source "${APP}"
lotto_chown_app_tree "${INSTANCE}"

UPDATE_STAGE="composer install"
lotto_run_composer "${APP}" "${USER}"
lotto_chown_app_tree "${INSTANCE}"

UNIT_CHANGED=0
UPDATE_STAGE="refresh unit"
if lotto_refresh_instance_unit_if_needed "${INSTANCE}"; then
    UNIT_CHANGED=1
fi

UPDATE_STAGE="start service"
lotto_start_instance_after_update "${INSTANCE}"
SERVICE_STOPPED=0

UPDATE_STAGE="healthcheck"
lotto_run_instance_healthcheck "${HOST_PORT}"

UPDATE_STAGE="finalize metadata"
lotto_refresh_metadata_timestamp "${INSTANCE}"
chmod 640 "$(lotto_metadata_file "${INSTANCE}")"
chown root:"${USER}" "$(lotto_metadata_file "${INSTANCE}")"

trap - EXIT
lotto_release_update_lock

lotto_info ""
lotto_info "Lotto Game systemd instance '${INSTANCE}' updated successfully."
lotto_info "  Unit: ${UNIT}"
lotto_info "  App:  ${APP}"
lotto_info "  Port: ${HOST_PORT} (preserved)"
if [[ "${UNIT_CHANGED}" -eq 1 ]]; then
    lotto_info "  Unit file: refreshed"
else
    lotto_info "  Unit file: unchanged"
fi
lotto_info "  Healthcheck: PASS"
