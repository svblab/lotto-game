#!/usr/bin/env bash
# Epic B1/B2/B3/C — systemd deployment tests.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
# shellcheck source=../lib/common.sh
source "${DEPLOY_DIR}/lib/common.sh"

TESTS_RUN=0
TESTS_PASSED=0
TESTS_FAILED=0

assert_true() {
    local desc="$1"
    shift
    TESTS_RUN=$((TESTS_RUN + 1))
    if "$@"; then
        TESTS_PASSED=$((TESTS_PASSED + 1))
        echo "PASS: ${desc}"
    else
        TESTS_FAILED=$((TESTS_FAILED + 1))
        echo "FAIL: ${desc}" >&2
    fi
}

assert_false() {
    local desc="$1"
    shift
    TESTS_RUN=$((TESTS_RUN + 1))
    if ! "$@"; then
        TESTS_PASSED=$((TESTS_PASSED + 1))
        echo "PASS: ${desc}"
    else
        TESTS_FAILED=$((TESTS_FAILED + 1))
        echo "FAIL: ${desc}" >&2
    fi
}

assert_eq() {
    local desc="$1"
    local expected="$2"
    local actual="$3"
    TESTS_RUN=$((TESTS_RUN + 1))
    if [[ "${expected}" == "${actual}" ]]; then
        TESTS_PASSED=$((TESTS_PASSED + 1))
        echo "PASS: ${desc}"
    else
        TESTS_FAILED=$((TESTS_FAILED + 1))
        echo "FAIL: ${desc} (expected '${expected}', got '${actual}')" >&2
    fi
}

test_valid_instance_names() {
    echo "--- valid instance names ---"
    assert_true "demo valid" lotto_validate_instance_name "demo"
    assert_true "lotto-01 valid" lotto_validate_instance_name "lotto-01"
    assert_true "lotto_02 valid" lotto_validate_instance_name "lotto_02"
    assert_true "a1 valid" lotto_validate_instance_name "a1"
    assert_true "default valid" lotto_validate_instance_name "default"
}

test_invalid_instance_names() {
    echo "--- invalid instance names ---"
    assert_false "empty rejected" lotto_validate_instance_name ""
    assert_false "whitespace rejected" lotto_validate_instance_name "bad name"
    assert_false "slash rejected" lotto_validate_instance_name "a/b"
    assert_false "dot-dot rejected" lotto_validate_instance_name "../prod"
    assert_false "absolute path rejected" lotto_validate_instance_name "/opt/lotto-game"
    assert_false "semicolon rejected" lotto_validate_instance_name "lotto;rm"
    assert_false "uppercase rejected" lotto_validate_instance_name "Demo"
    assert_false "too long rejected" lotto_validate_instance_name "abcdefghijklmnopqrstuvwxyz1234567"
    assert_false "leading hyphen rejected" lotto_validate_instance_name "-bad"
}

test_production_name_reservation() {
    echo "--- reserved production-related names ---"
    assert_false "lotto-server reserved" lotto_validate_instance_name "lotto-server"
    assert_false "lotto-server.service reserved" lotto_validate_instance_name "lotto-server.service"
    assert_false "www-data reserved" lotto_validate_instance_name "www-data"
    assert_false "production reserved" lotto_validate_instance_name "production"
}

test_production_guards() {
    echo "--- production guards ---"
    assert_eq "protected root constant" "/opt/lotto-game" "${LOTTO_PROTECTED_APP_ROOT}"
    assert_eq "generic root demo" "/opt/lotto-game-demo" "$(lotto_instance_root demo)"
    assert_false "generic root != production" test "$(lotto_instance_root demo)" = "${LOTTO_PROTECTED_APP_ROOT}"
    assert_eq "generic unit" "lotto-game-demo.service" "$(lotto_systemd_unit demo)"
    assert_false "generic unit != production unit" test "$(lotto_systemd_unit demo)" = "${LOTTO_PROTECTED_UNIT}"
    assert_eq "generic user" "lotto-demo" "$(lotto_service_user demo)"
    assert_false "protected path op rejected" lotto_assert_not_protected_path "${LOTTO_PROTECTED_APP_ROOT}"
    assert_false "protected unit op rejected" lotto_assert_not_protected_unit "${LOTTO_PROTECTED_UNIT}"
    assert_false "protected user op rejected" lotto_assert_not_protected_user "${LOTTO_PROTECTED_USER}"
    assert_false "path with .. rejected" lotto_assert_not_protected_path "/opt/lotto-game-demo/../lotto-game"
    assert_false "production port 8080 rejected for generic" lotto_assert_not_protected_port "8080"
    assert_true "non-production port allowed" lotto_validate_port_number "8081"
}

test_identity_collision() {
    echo "--- identity collision ---"
    local root_a root_b unit_a unit_b user_a user_b
    root_a="$(lotto_instance_root demo-a)"
    root_b="$(lotto_instance_root demo_b)"
    unit_a="$(lotto_systemd_unit demo-a)"
    unit_b="$(lotto_systemd_unit demo_b)"
    user_a="$(lotto_service_user demo-a)"
    user_b="$(lotto_service_user demo_b)"
    assert_false "distinct roots" test "${root_a}" = "${root_b}"
    assert_false "distinct units" test "${unit_a}" = "${unit_b}"
    assert_false "distinct users" test "${user_a}" = "${user_b}"
    assert_eq "hyphen preserved in root" "/opt/lotto-game-demo-a" "${root_a}"
    assert_eq "underscore preserved in root" "/opt/lotto-game-demo_b" "${root_b}"
}

test_resolve_identity() {
    echo "--- resolve instance identity ---"
    local identity
    identity="$(lotto_resolve_instance_identity demo 8099)"
    assert_true "identity resolves" test -n "${identity}"
    assert_eq "identity includes unit" "lotto-game-demo.service" "$(echo "${identity}" | grep '^LOTTO_UNIT=' | cut -d= -f2-)"
    assert_eq "identity includes port" "8099" "$(echo "${identity}" | grep '^LOTTO_PORT=' | cut -d= -f2-)"
}

test_metadata_schema() {
    echo "--- metadata schema ---"
    local tmp
    tmp="$(mktemp -d)"
    LOTTO_INSTANCE_ROOT_PREFIX="${tmp}/lotto-game-"
    LOTTO_BACKUP_ROOT="${tmp}/backups"

    lotto_write_metadata "test01" 8099 "127.0.0.1" false "2026-08-30T12:00:00Z"
    assert_true "metadata created" test -f "$(lotto_metadata_file test01)"

    lotto_load_instance "test01"
    assert_eq "schema version" "1" "${LOTTO_META_SCHEMA}"
    assert_eq "instance name" "test01" "${LOTTO_META_INSTANCE}"
    assert_eq "unit in metadata" "lotto-game-test01.service" "${LOTTO_META_UNIT}"
    assert_eq "user in metadata" "lotto-test01" "${LOTTO_META_USER}"
    assert_eq "port in metadata" "8099" "${LOTTO_META_PORT}"
    assert_false "metadata has no password key" grep -qi password "$(lotto_metadata_file test01)"
    assert_false "metadata has no secret key" grep -qi secret "$(lotto_metadata_file test01)"

    rm -rf "${tmp}"
}

test_static_syntax() {
    echo "--- shell syntax ---"
    for script in lib/common.sh install.sh remove.sh update.sh healthcheck.sh; do
        if bash -n "${DEPLOY_DIR}/${script}"; then
            assert_true "${script} syntax" true
        else
            assert_false "${script} syntax" true
        fi
    done
    assert_true "README exists" test -f "${DEPLOY_DIR}/README.md"
    assert_true "install.sh exists (B2)" test -f "${DEPLOY_DIR}/install.sh"
    assert_true "remove.sh exists (B3)" test -f "${DEPLOY_DIR}/remove.sh"
    assert_true "update.sh exists (C)" test -f "${DEPLOY_DIR}/update.sh"
    assert_true "service.template exists" test -f "${DEPLOY_DIR}/service.template"
}

test_env_file_generation() {
    echo "--- environment file ---"
    local tmp
    tmp="$(mktemp -d)"
    LOTTO_INSTANCE_ROOT_PREFIX="${tmp}/lotto-game-"
    LOTTO_BACKUP_ROOT="${tmp}/backups"

    lotto_write_env_file "demo" 8099 "127.0.0.1" "https://example.com" "127.0.0.1" "3"
    assert_true "env file created" test -f "$(lotto_env_file demo)"
    assert_false "env has no password" grep -qi password "$(lotto_env_file demo)"
    grep -q "LOTTO_WS_PORT=8099" "$(lotto_env_file demo)"
    assert_true "env sets LOTTO_WS_PORT" true
    grep -q "LOTTO_DB_PATH=.*/data/game.db" "$(lotto_env_file demo)"
    assert_true "env sets LOTTO_DB_PATH" true

    rm -rf "${tmp}"
}

test_unit_render() {
    echo "--- systemd unit render ---"
    local tmp unit
    tmp="$(mktemp -d)"
    unit="${tmp}/lotto-game-demo.service"
    LOTTO_SYSTEMD_SERVICE_TEMPLATE="${DEPLOY_DIR}/service.template"

    lotto_render_unit_file "${unit}" "lotto-demo" "${tmp}/app" "${tmp}/config/environment" \
        "${tmp}/data" "${tmp}/logs" "${tmp}/config"
    assert_true "unit file rendered" test -f "${unit}"
    grep -q "User=lotto-demo" "${unit}"
    assert_true "unit sets User" true
    grep -q "ExecStart=/usr/bin/php ${tmp}/app/server.php start" "${unit}"
    assert_true "unit sets ExecStart" true

    rm -rf "${tmp}"
}

test_port_helpers() {
    echo "--- port helpers ---"
    assert_false "8080 blocked for generic" lotto_assert_not_protected_port "8080"
    local tmp meta
    tmp="$(mktemp -d)"
    LOTTO_INSTANCE_ROOT_PREFIX="${tmp}/lotto-game-"
    LOTTO_BACKUP_ROOT="${tmp}/backups"
    lotto_write_metadata "other" 8099 "127.0.0.1" false
    assert_true "other instance owns 8099" lotto_port_used_by_other_systemd_instance "8099" "demo"
    assert_false "same instance skipped" lotto_port_used_by_other_systemd_instance "8099" "other"
    rm -rf "${tmp}"
}

test_b3_metadata_validation() {
    echo "--- B3 metadata validation ---"
    local tmp
    tmp="$(mktemp -d)"
    LOTTO_INSTANCE_ROOT_PREFIX="${tmp}/lotto-game-"
    LOTTO_BACKUP_ROOT="${tmp}/backups"

    lotto_test_create_managed_instance "demo" 8099 true
    lotto_load_instance "demo"
    assert_true "valid managed metadata passes" lotto_validate_removal_metadata "demo"

    if command -v python3 >/dev/null 2>&1; then
        python3 - "$(lotto_metadata_file demo)" <<'PY'
import json, sys
path = sys.argv[1]
with open(path, encoding="utf-8") as fh:
    data = json.load(fh)
data["data_path"] = "/etc"
with open(path, "w", encoding="utf-8") as fh:
    json.dump(data, fh)
PY
        lotto_load_instance "demo"
        assert_false "tampered data_path rejected" lotto_validate_removal_metadata "demo"
    else
        echo "SKIP: tampered metadata test (python3 unavailable)"
    fi

    rm -rf "${tmp}"
}

test_b3_missing_metadata() {
    echo "--- B3 missing metadata ---"
    local tmp
    tmp="$(mktemp -d)"
    LOTTO_INSTANCE_ROOT_PREFIX="${tmp}/lotto-game-"
    LOTTO_BACKUP_ROOT="${tmp}/backups"

    mkdir -p "$(lotto_instance_root residual)/app"
    assert_true "residual root detected" lotto_instance_has_unmanaged_residuals "residual"
    assert_false "fully absent with residuals" lotto_instance_fully_absent "residual"
    assert_true "fully absent when empty" lotto_instance_fully_absent "ghost"

    rm -rf "${tmp}"
}

test_b3_idempotent_absent() {
    echo "--- B3 idempotent absent ---"
    local tmp
    tmp="$(mktemp -d)"
    LOTTO_INSTANCE_ROOT_PREFIX="${tmp}/lotto-game-"
    LOTTO_BACKUP_ROOT="${tmp}/backups"

    assert_true "never-installed instance absent" lotto_instance_fully_absent "never"

    lotto_test_create_managed_instance "gone" 8099 true
    lotto_load_instance "gone"
    lotto_test_remove_managed_instance "gone"
    assert_true "removed instance fully absent" lotto_instance_fully_absent "gone"
    assert_true "second absent check safe" lotto_instance_fully_absent "gone"

    rm -rf "${tmp}"
}

test_b3_managed_removal() {
    echo "--- B3 managed instance removal ---"
    local tmp
    tmp="$(mktemp -d)"
    LOTTO_INSTANCE_ROOT_PREFIX="${tmp}/lotto-game-"
    LOTTO_BACKUP_ROOT="${tmp}/backups"

    lotto_test_create_managed_instance "demo" 8099 true
    touch "$(lotto_instance_lock_file demo)"
    assert_true "lock file exists before removal" test -f "$(lotto_instance_lock_file demo)"

    lotto_load_instance "demo"
    lotto_test_remove_managed_instance "demo"
    assert_false "instance root removed" test -d "$(lotto_instance_root demo)"
    assert_false "metadata removed" lotto_instance_metadata_exists "demo"
    assert_false "backup removed" test -d "$(lotto_backup_dir demo)"
    assert_false "lock file removed" test -f "$(lotto_instance_lock_file demo)"

    rm -rf "${tmp}"
}

test_b3_foreign_user_flag() {
    echo "--- B3 foreign user preservation ---"
    local tmp
    tmp="$(mktemp -d)"
    LOTTO_INSTANCE_ROOT_PREFIX="${tmp}/lotto-game-"
    LOTTO_BACKUP_ROOT="${tmp}/backups"

    lotto_test_create_managed_instance "foreign" 8099 false
    lotto_load_instance "foreign"
    assert_eq "created_user false recorded" "False" "${LOTTO_META_CREATED_USER}"
    assert_true "removal skips owned user delete" lotto_remove_owned_service_user "foreign"

    rm -rf "${tmp}"
}

test_b3_user_claimed_by_other() {
    echo "--- B3 user shared across instances ---"
    local tmp
    tmp="$(mktemp -d)"
    LOTTO_INSTANCE_ROOT_PREFIX="${tmp}/lotto-game-"
    LOTTO_BACKUP_ROOT="${tmp}/backups"

    lotto_test_create_managed_instance "one" 8099 true
    lotto_test_create_managed_instance "two" 8100 true

    if command -v python3 >/dev/null 2>&1; then
        python3 - "$(lotto_metadata_file two)" <<'PY'
import json, sys
path = sys.argv[1]
with open(path, encoding="utf-8") as fh:
    data = json.load(fh)
data["service_user"] = "lotto-one"
with open(path, "w", encoding="utf-8") as fh:
    json.dump(data, fh)
PY
        assert_true "shared user detected" lotto_user_claimed_by_other_instance "lotto-one" "one"
    else
        echo "SKIP: shared user tamper test (python3 unavailable)"
    fi

    rm -rf "${tmp}"
}

test_b3_symlink_escape() {
    echo "--- B3 symlink escape protection ---"
    if ! lotto_symlinks_supported; then
        echo "SKIP: symlink escape test (platform does not support symlinks)"
        return 0
    fi
    local tmp evil root
    tmp="$(mktemp -d)"
    evil="$(mktemp -d)"
    LOTTO_INSTANCE_ROOT_PREFIX="${tmp}/lotto-game-"
    LOTTO_BACKUP_ROOT="${tmp}/backups"

    lotto_test_create_managed_instance "demo" 8099 true
    root="$(lotto_instance_root demo)"
    ln -s "${evil}" "${root}/data/escape-link"
    touch "${evil}/precious.txt"

    lotto_load_instance "demo"
    assert_false "outside symlink blocks removal" lotto_remove_instance_tree "demo"
    assert_true "external target preserved" test -f "${evil}/precious.txt"
    assert_true "instance root preserved after blocked removal" test -d "${root}"

    rm -rf "${tmp}" "${evil}"
}

test_b3_wrong_unit_metadata() {
    echo "--- B3 wrong unit metadata ---"
    local tmp
    tmp="$(mktemp -d)"
    LOTTO_INSTANCE_ROOT_PREFIX="${tmp}/lotto-game-"
    LOTTO_BACKUP_ROOT="${tmp}/backups"

    lotto_test_create_managed_instance "demo" 8099 true
    if command -v python3 >/dev/null 2>&1; then
        python3 - "$(lotto_metadata_file demo)" <<'PY'
import json, sys
path = sys.argv[1]
with open(path, encoding="utf-8") as fh:
    data = json.load(fh)
data["unit"] = "lotto-game-evil.service"
with open(path, "w", encoding="utf-8") as fh:
    json.dump(data, fh)
PY
        lotto_load_instance "demo"
        assert_false "wrong unit rejected" lotto_validate_removal_metadata "demo"
    else
        echo "SKIP: wrong unit test (python3 unavailable)"
    fi

    rm -rf "${tmp}"
}

test_b3_production_remove_guard() {
    echo "--- B3 production removal guards ---"
    assert_false "remove production name blocked" lotto_validate_instance_name "production"
    assert_false "remove lotto-server blocked" lotto_validate_instance_name "lotto-server"
    assert_false "remove www-data blocked" lotto_validate_instance_name "www-data"
    assert_false "protected path blocked" lotto_assert_not_protected_path "${LOTTO_PROTECTED_APP_ROOT}"
    assert_false "protected unit blocked" lotto_assert_not_protected_unit "${LOTTO_PROTECTED_UNIT}"
}

test_c_update_preconditions() {
    echo "--- C update preconditions ---"
    local tmp
    tmp="$(mktemp -d)"
    LOTTO_INSTANCE_ROOT_PREFIX="${tmp}/lotto-game-"
    LOTTO_BACKUP_ROOT="${tmp}/backups"

    assert_false "missing metadata not installed" lotto_assert_managed_instance_installed "ghost"

    lotto_test_create_managed_instance "demo" 8099 true
    assert_true "managed instance installed" lotto_assert_managed_instance_installed "demo"
    lotto_load_instance "demo"
    assert_true "valid metadata passes update validation" lotto_validate_update_metadata "demo"
    assert_true "env file valid" lotto_assert_env_file_valid "$(lotto_env_file demo)"

    if command -v python3 >/dev/null 2>&1; then
        python3 - "$(lotto_metadata_file demo)" <<'PY'
import json, sys
path = sys.argv[1]
with open(path, encoding="utf-8") as fh:
    data = json.load(fh)
data["deployment_type"] = "docker"
with open(path, "w", encoding="utf-8") as fh:
    json.dump(data, fh)
PY
        lotto_load_instance "demo"
        assert_false "wrong deployment type rejected" lotto_validate_update_metadata "demo"
    else
        echo "SKIP: wrong deployment type test (python3 unavailable)"
    fi

    rm -rf "${tmp}"
}

test_c_update_preservation() {
    echo "--- C update preservation ---"
    local tmp env_before port_before
    tmp="$(mktemp -d)"
    LOTTO_INSTANCE_ROOT_PREFIX="${tmp}/lotto-game-"
    LOTTO_BACKUP_ROOT="${tmp}/backups"

    lotto_test_create_managed_instance "demo" 8099 true
    echo "seed-db-content" >"$(lotto_data_path demo)/game.db"
    echo "LOTTO_CUSTOM=keep" >>"$(lotto_env_file demo)"
    env_before="$(cat "$(lotto_env_file demo)")"
    port_before="$(lotto_json_get "$(lotto_metadata_file demo)" port)"

    assert_true "simulate update preserves state" lotto_test_simulate_update "demo"
    assert_eq "environment preserved" "${env_before}" "$(cat "$(lotto_env_file demo)")"
    assert_eq "database preserved" "seed-db-content" "$(cat "$(lotto_data_path demo)/game.db")"
    assert_eq "port preserved" "${port_before}" "$(lotto_json_get "$(lotto_metadata_file demo)" port)"
    assert_true "updated_at recorded" grep -q updated_at "$(lotto_metadata_file demo)"

    rm -rf "${tmp}"
}

test_c_update_idempotent() {
    echo "--- C update idempotency ---"
    local tmp
    tmp="$(mktemp -d)"
    LOTTO_INSTANCE_ROOT_PREFIX="${tmp}/lotto-game-"
    LOTTO_BACKUP_ROOT="${tmp}/backups"

    lotto_test_create_managed_instance "demo" 8099 true
    assert_true "first update simulation" lotto_test_simulate_update "demo"
    assert_true "second update simulation" lotto_test_simulate_update "demo"

    rm -rf "${tmp}"
}

test_c_unit_refresh_detection() {
    echo "--- C unit refresh detection ---"
    local tmp unit_file
    tmp="$(mktemp -d)"
    LOTTO_INSTANCE_ROOT_PREFIX="${tmp}/lotto-game-"
    LOTTO_BACKUP_ROOT="${tmp}/backups"
    LOTTO_SYSTEMD_UNIT_DIR="${tmp}/units"
    LOTTO_SYSTEMD_SERVICE_TEMPLATE="${DEPLOY_DIR}/service.template"
    mkdir -p "${LOTTO_SYSTEMD_UNIT_DIR}"

    lotto_test_create_managed_instance "demo" 8099 true
    assert_true "missing unit file needs refresh" lotto_unit_needs_refresh "demo"

    unit_file="$(lotto_unit_file demo)"
    lotto_render_unit_file "${unit_file}" "lotto-demo" "$(lotto_app_path demo)" \
        "$(lotto_env_file demo)" "$(lotto_data_path demo)" "$(lotto_logs_path demo)" \
        "$(lotto_config_path demo)"

    assert_false "identical unit not flagged" lotto_unit_needs_refresh "demo"

    rm -rf "${tmp}"
}

test_c_update_lock() {
    echo "--- C update lock ---"
    if ! command -v flock >/dev/null 2>&1; then
        echo "SKIP: update lock test (flock unavailable)"
        return 0
    fi
    local tmp
    tmp="$(mktemp -d)"
    LOTTO_UPDATE_LOCK_DIR="${tmp}"

    (
        lotto_acquire_update_lock "demo"
        sleep 1
    ) &
    local bg=$!
    sleep 0.2
    assert_false "concurrent update blocked" lotto_acquire_update_lock "demo"
    wait "${bg}" 2>/dev/null || true

    assert_true "other instance lock independent" lotto_acquire_update_lock "other"
    lotto_release_update_lock

    rm -rf "${tmp}"
}

test_c_update_failure_no_metadata_touch() {
    echo "--- C update failure metadata ---"
    local tmp meta_before
    tmp="$(mktemp -d)"
    LOTTO_INSTANCE_ROOT_PREFIX="${tmp}/lotto-game-"
    LOTTO_BACKUP_ROOT="${tmp}/backups"

    lotto_test_create_managed_instance "demo" 8099 true
    meta_before="$(cat "$(lotto_metadata_file demo)")"
    assert_false "missing env fails validation" lotto_assert_env_file_valid "/nonexistent/env"
    assert_eq "metadata unchanged on validation failure" "${meta_before}" "$(cat "$(lotto_metadata_file demo)")"
    assert_false "metadata has no updated_at yet" grep -q updated_at "$(lotto_metadata_file demo)"

    rm -rf "${tmp}"
}

test_valid_instance_names
test_invalid_instance_names
test_production_name_reservation
test_production_guards
test_identity_collision
test_resolve_identity
test_metadata_schema
test_env_file_generation
test_unit_render
test_port_helpers
test_b3_metadata_validation
test_b3_missing_metadata
test_b3_idempotent_absent
test_b3_managed_removal
test_b3_foreign_user_flag
test_b3_user_claimed_by_other
test_b3_symlink_escape
test_b3_wrong_unit_metadata
test_b3_production_remove_guard
test_c_update_preconditions
test_c_update_preservation
test_c_update_idempotent
test_c_unit_refresh_detection
test_c_update_lock
test_c_update_failure_no_metadata_touch
test_static_syntax

echo ""
echo "Systemd deployment tests: ${TESTS_PASSED}/${TESTS_RUN} passed, ${TESTS_FAILED} failed"
if [[ "${TESTS_FAILED}" -ne 0 ]]; then
    exit 1
fi
