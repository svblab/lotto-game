#!/usr/bin/env bash
# Epic B1/B2 — systemd deployment tests (identity, guards, metadata, install helpers).

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
    for script in lib/common.sh install.sh healthcheck.sh; do
        if bash -n "${DEPLOY_DIR}/${script}"; then
            assert_true "${script} syntax" true
        else
            assert_false "${script} syntax" true
        fi
    done
    assert_true "README exists" test -f "${DEPLOY_DIR}/README.md"
    assert_true "install.sh exists (B2)" test -f "${DEPLOY_DIR}/install.sh"
    assert_false "remove.sh must not exist in B2" test -f "${DEPLOY_DIR}/remove.sh"
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
test_static_syntax

echo ""
echo "Systemd deployment tests: ${TESTS_PASSED}/${TESTS_RUN} passed, ${TESTS_FAILED} failed"
if [[ "${TESTS_FAILED}" -ne 0 ]]; then
    exit 1
fi
