#!/usr/bin/env bash
# ADR-038 — AHPC bootstrap credential tests (shared + Docker-specific).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
# shellcheck source=../lib/common.sh
source "${DEPLOY_DIR}/lib/common.sh"
# shellcheck source=../../lib/admin-bootstrap-common.sh
source "${DEPLOY_DIR}/../lib/admin-bootstrap-common.sh"

TESTS_RUN=0
TESTS_PASSED=0
TESTS_FAILED=0
TESTS_SKIPPED=0

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

assert_not_contains() {
    local desc="$1"
    local haystack="$2"
    local needle="$3"
    TESTS_RUN=$((TESTS_RUN + 1))
    if [[ "${haystack}" == *"${needle}"* ]]; then
        TESTS_FAILED=$((TESTS_FAILED + 1))
        echo "FAIL: ${desc} (unexpected substring present)" >&2
    else
        TESTS_PASSED=$((TESTS_PASSED + 1))
        echo "PASS: ${desc}"
    fi
}

skip() {
    local desc="$1"
    TESTS_SKIPPED=$((TESTS_SKIPPED + 1))
    echo "SKIP: ${desc}"
}

test_parse_bootstrap_file() {
    echo "--- parse bootstrap file ---"
    local tmp
    tmp="$(mktemp)"
    printf 'ADMIN PASSWORD:\nsecret-pass-123\n' >"${tmp}"
    assert_eq "parse password" "secret-pass-123" "$(lotto_ahpc_parse_bootstrap_file "${tmp}")"
    rm -f "${tmp}"
}

test_pending_lifecycle() {
    echo "--- pending lifecycle ---"
    local tmp instance pending ack password
    tmp="$(mktemp -d)"
    instance="testinst"
    pending="${tmp}/admin-bootstrap.pending"
    ack="${tmp}/admin-bootstrap.ack"
    password="generated-secret-abc"

    lotto_ahpc_write_pending_atomic "${pending}" "${instance}" "${password}"
    assert_true "pending file exists" test -f "${pending}"
    assert_eq "pending permissions" "600" "$(stat -c '%a' "${pending}" 2>/dev/null || stat -f '%OLp' "${pending}")"
    assert_eq "pending owner uid" "0" "$(stat -c '%u' "${pending}" 2>/dev/null || echo 0)"

    assert_eq "read password field" "${password}" "$(lotto_ahpc_read_pending_fields "${pending}" password)"
    status_json="$(lotto_ahpc_emit_status_json "${instance}" "${pending}" "${ack}")"
    assert_not_contains "status json hides password" "${status_json}" "${password}"
    assert_eq "status state pending" "pending" "$(echo "${status_json}" | python3 -c 'import json,sys; print(json.load(sys.stdin)["state"])')"

    read_json="$(lotto_ahpc_emit_read_json "${instance}" "${pending}")"
    assert_eq "read json password" "${password}" "$(echo "${read_json}" | python3 -c 'import json,sys; print(json.load(sys.stdin)["password"])')"

    lotto_ahpc_acknowledge "${instance}" "${pending}" "${ack}"
    assert_false "pending removed after ack" test -f "${pending}"
    assert_true "ack marker exists" test -f "${ack}"
    assert_eq "state after ack" "acknowledged" "$(lotto_ahpc_pending_state "${pending}" "${ack}")"

    rm -rf "${tmp}"
}

test_multi_instance_isolation() {
    echo "--- multi-instance isolation ---"
    local tmp pending_default pending_test
    tmp="$(mktemp -d)"
    pending_default="${tmp}/default/admin-bootstrap.pending"
    pending_test="${tmp}/test/admin-bootstrap.pending"
    mkdir -p "${tmp}/default" "${tmp}/test"

    lotto_ahpc_write_pending_atomic "${pending_default}" "default" "pass-default"
    lotto_ahpc_write_pending_atomic "${pending_test}" "test" "pass-test"

    assert_eq "default password" "pass-default" "$(lotto_ahpc_read_pending_fields "${pending_default}" password)"
    assert_eq "test password" "pass-test" "$(lotto_ahpc_read_pending_fields "${pending_test}" password)"

    lotto_ahpc_acknowledge "default" "${pending_default}" "${tmp}/default/admin-bootstrap.ack"
    assert_false "default removed" test -f "${pending_default}"
    assert_true "test still pending" test -f "${pending_test}"

    rm -rf "${tmp}"
}

test_corrupt_pending_exit() {
    echo "--- corrupt pending ---"
    local tmp pending rc
    tmp="$(mktemp -d)"
    pending="${tmp}/admin-bootstrap.pending"
    echo '{"schema_version":1}' >"${pending}"
    chmod 600 "${pending}"
    chown root:root "${pending}" 2>/dev/null || true

    set +e
    lotto_ahpc_read_pending_fields "${pending}" password >/dev/null 2>&1
    rc=$?
    set -e
    assert_eq "corrupt pending rc" "4" "${rc}"

    rm -rf "${tmp}"
}

test_handoff_json_no_password() {
    echo "--- handoff json ---"
    local tmp pending out password
    tmp="$(mktemp -d)"
    pending="${tmp}/admin-bootstrap.pending"
    password="super-secret-handoff"
    lotto_ahpc_write_pending_atomic "${pending}" "default" "${password}"
    out="$(lotto_ahpc_emit_handoff_json "default" "${pending}")"
    assert_not_contains "handoff hides password" "${out}" "${password}"
    assert_eq "handoff required" "true" "$(echo "${out}" | python3 -c 'import json,sys; print(json.load(sys.stdin)["handoff_required"])')"
    rm -rf "${tmp}"
}

test_admin_bootstrap_cli_syntax() {
    echo "--- admin-bootstrap.sh syntax ---"
    if bash -n "${DEPLOY_DIR}/admin-bootstrap.sh"; then
        assert_true "admin-bootstrap.sh syntax" true
    else
        assert_false "admin-bootstrap.sh syntax" true
    fi
}

test_docker_ahpc_integration() {
    echo "--- docker AHPC integration (optional) ---"
    if ! lotto_docker_check >/dev/null 2>&1; then
        skip "Docker not available"
        return 0
    fi
    if ! sudo -n true 2>/dev/null; then
        skip "passwordless sudo not available"
        return 0
    fi
    if [[ "$(uname -s)" != "Linux" ]]; then
        skip "Linux-only docker AHPC integration"
        return 0
    fi

    local tmp_root instance install_out pending_path password status_json
    tmp_root="$(mktemp -d)"
    instance="ahpc$$"
    LOTTO_STATE_ROOT="${tmp_root}/state"

    set +e
    install_out="$(LOTTO_STATE_ROOT="${tmp_root}/state" bash "${DEPLOY_DIR}/install.sh" \
        --name "${instance}" --port 18092 --mem-limit 128m --non-interactive 2>&1)"
    install_rc=$?
    set -e

    assert_eq "non-interactive install exit 42" "42" "${install_rc}"
    pending_path="$(lotto_ahpc_pending_path_docker "${instance}")"
    assert_true "pending file exists after install" test -f "${pending_path}"
    assert_not_contains "install output hides password" "${install_out}" "$(lotto_ahpc_read_pending_fields "${pending_path}" password 2>/dev/null || echo __none__)"

    password="$(LOTTO_STATE_ROOT="${tmp_root}/state" bash "${DEPLOY_DIR}/admin-bootstrap.sh" \
        --name "${instance}" read --format=json | python3 -c 'import json,sys; print(json.load(sys.stdin)["password"])')"
    lotto_load_instance_env "${instance}"
    db_tmp="$(mktemp)"
    docker run --rm --entrypoint cat \
        -v "${LOTTO_VOLUME_NAME}:/app/data:ro" \
        "${LOTTO_IMAGE}" /app/data/game.db >"${db_tmp}"
    assert_true "pending password verifies against db" lotto_ahpc_verify_login_password "${db_tmp}" "${password}"
    rm -f "${db_tmp}"

    LOTTO_STATE_ROOT="${tmp_root}/state" bash "${DEPLOY_DIR}/install.sh" --name "${instance}" --port 18092 >/dev/null
    assert_true "pending survives reinstall" test -f "${pending_path}"

    LOTTO_STATE_ROOT="${tmp_root}/state" bash "${DEPLOY_DIR}/admin-bootstrap.sh" --name "${instance}" acknowledge
    assert_false "pending removed after acknowledge" test -f "${pending_path}"

    set +e
    LOTTO_STATE_ROOT="${tmp_root}/state" bash "${DEPLOY_DIR}/admin-bootstrap.sh" --name "${instance}" read >/dev/null 2>&1
    read_rc=$?
    set -e
    assert_eq "read after ack exit 2" "2" "${read_rc}"

    status_json="$(LOTTO_STATE_ROOT="${tmp_root}/state" bash "${DEPLOY_DIR}/admin-bootstrap.sh" \
        --name "${instance}" status --format=json)"
    assert_not_contains "status json safe" "${status_json}" "${password}"

    env_file="$(lotto_instance_env_file "${instance}")"
    env_content="$(cat "${env_file}")"
    assert_not_contains "instance.env hides password" "${env_content}" "${password}"

    logs="$(lotto_compose_cmd "${instance}" logs --tail 50 app 2>/dev/null || true)"
    assert_not_contains "docker logs hide password" "${logs}" "${password}"

    LOTTO_STATE_ROOT="${tmp_root}/state" bash "${DEPLOY_DIR}/admin-bootstrap.sh" --name "${instance}" reset
    db_tmp="$(mktemp)"
    docker run --rm --entrypoint cat \
        -v "${LOTTO_VOLUME_NAME}:/app/data:ro" \
        "${LOTTO_IMAGE}" /app/data/game.db >"${db_tmp}"
    assert_false "old password fails after reset" lotto_ahpc_verify_login_password "${db_tmp}" "${password}"
    new_password="$(LOTTO_STATE_ROOT="${tmp_root}/state" bash "${DEPLOY_DIR}/admin-bootstrap.sh" \
        --name "${instance}" read --format=json | python3 -c 'import json,sys; print(json.load(sys.stdin)["password"])')"
    assert_true "new password works after reset" lotto_ahpc_verify_login_password "${db_tmp}" "${new_password}"
    rm -f "${db_tmp}"

    LOTTO_STATE_ROOT="${tmp_root}/state" bash "${DEPLOY_DIR}/remove.sh" --name "${instance}" --yes
    rm -rf "${tmp_root}"
}

test_parse_bootstrap_file
test_pending_lifecycle
test_multi_instance_isolation
test_corrupt_pending_exit
test_handoff_json_no_password
test_admin_bootstrap_cli_syntax
test_docker_ahpc_integration

echo ""
echo "AHPC tests: ${TESTS_PASSED}/${TESTS_RUN} passed, ${TESTS_FAILED} failed, ${TESTS_SKIPPED} skipped"
if [[ "${TESTS_FAILED}" -ne 0 ]]; then
    exit 1
fi
