#!/usr/bin/env bash
# Dependency-free deployment helper tests (assert-based, no external framework).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
# shellcheck source=../lib/common.sh
source "${DEPLOY_DIR}/lib/common.sh"

TESTS_RUN=0
TESTS_PASSED=0
TESTS_FAILED=0
TESTS_SKIPPED=0

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

skip() {
    local desc="$1"
    TESTS_SKIPPED=$((TESTS_SKIPPED + 1))
    echo "SKIP: ${desc}"
}

test_instance_name_validation() {
    echo "--- instance name validation ---"
    assert_true "default is valid" lotto_validate_instance_name "default"
    assert_true "lotto-01 is valid" lotto_validate_instance_name "lotto-01"
    assert_false "empty is invalid" lotto_validate_instance_name ""
    assert_false "leading hyphen invalid" lotto_validate_instance_name "-bad"
    assert_false "spaces invalid" lotto_validate_instance_name "bad name"
}

test_port_selection() {
    echo "--- port selection ---"
    local port
    port="$(LOTTO_PICK_PORT_START=49152 LOTTO_PICK_PORT_END=49160 lotto_pick_free_port 49152 49160)"
    assert_true "picked port in range" bash -c "[[ ${port} -ge 49152 && ${port} -le 49160 ]]"
}

test_compose_env_generation() {
    echo "--- compose env generation ---"
    local tmp_root
    tmp_root="$(mktemp -d)"
    LOTTO_STATE_ROOT="${tmp_root}/state"
    lotto_write_instance_env "test01" 8099 "127.0.0.1" 8080 "256m" "0.5" 256 "" "" ""
    assert_true "metadata file created" test -f "${tmp_root}/state/test01/instance.env"
    lotto_load_instance_env "test01"
    assert_eq "instance name recorded" "test01" "${LOTTO_INSTANCE}"
    assert_eq "volume name" "lotto-test01-data" "${LOTTO_VOLUME_NAME}"
    assert_eq "host port" "8099" "${LOTTO_HOST_PORT}"
    rm -rf "${tmp_root}"
}

test_image_reference_count() {
    echo "--- image cleanup reference counting ---"
    local tmp_root
    tmp_root="$(mktemp -d)"
    LOTTO_STATE_ROOT="${tmp_root}/state"
    mkdir -p "${tmp_root}/state/a" "${tmp_root}/state/b"
    lotto_write_instance_env "a" 8080 "127.0.0.1" 8080 "256m" "0.5" 256 "" "" ""
    lotto_write_instance_env "b" 8081 "127.0.0.1" 8080 "256m" "0.5" 256 "" "" ""
    lotto_load_instance_env "a"
    local image_a="${LOTTO_IMAGE}"
    assert_false "same-tag other instance not detected when tags differ" lotto_image_used_by_other_instances "${image_a}" "a"
    rm -rf "${tmp_root}"
}

test_static_files() {
    echo "--- static file checks ---"
    assert_true "Dockerfile exists" test -f "${LOTTO_REPO_ROOT}/deploy/docker/Dockerfile"
    assert_true "compose.yaml exists" test -f "${LOTTO_COMPOSE_FILE}"
    assert_true "healthcheck.php exists" test -f "${LOTTO_REPO_ROOT}/deploy/docker/healthcheck.php"
    if bash -n "${DEPLOY_DIR}/install.sh"; then
        assert_true "install.sh syntax" true
    else
        assert_false "install.sh syntax" true
    fi
    if bash -n "${DEPLOY_DIR}/remove.sh"; then
        assert_true "remove.sh syntax" true
    else
        assert_false "remove.sh syntax" true
    fi
    if bash -n "${DEPLOY_DIR}/healthcheck.sh"; then
        assert_true "healthcheck.sh syntax" true
    else
        assert_false "healthcheck.sh syntax" true
    fi
    if php -l "${LOTTO_REPO_ROOT}/deploy/docker/healthcheck.php" >/dev/null 2>&1; then
        assert_true "healthcheck.php syntax" true
    else
        assert_false "healthcheck.php syntax" true
    fi
    if php -l "${LOTTO_REPO_ROOT}/init_db.php" >/dev/null 2>&1; then
        assert_true "init_db.php syntax" true
    else
        assert_false "init_db.php syntax" true
    fi
    if php -l "${LOTTO_REPO_ROOT}/src/Core/Logger.php" >/dev/null 2>&1; then
        assert_true "Logger.php syntax" true
    else
        assert_false "Logger.php syntax" true
    fi
}

test_healthcheck_failure_handling() {
    echo "--- healthcheck failure handling ---"
    if LOTTO_WS_PORT=1 php "${LOTTO_REPO_ROOT}/deploy/docker/healthcheck.php" >/dev/null 2>&1; then
        assert_false "healthcheck fails on closed port" true
    else
        assert_true "healthcheck fails on closed port" true
    fi
}

test_docker_integration() {
    echo "--- docker integration (optional) ---"
    if ! lotto_docker_check >/dev/null 2>&1; then
        skip "Docker not available — runtime install/remove tests skipped"
        return 0
    fi

    local tmp_root instance
    tmp_root="$(mktemp -d)"
    instance="itest$$"
    LOTTO_STATE_ROOT="${tmp_root}/state"

    if ! sudo -n true 2>/dev/null; then
        skip "passwordless sudo not available — skipping live docker install test"
        rm -rf "${tmp_root}"
        return 0
    fi

    LOTTO_STATE_ROOT="${tmp_root}/state" bash "${DEPLOY_DIR}/install.sh" --name "${instance}" --port 18091 --mem-limit 128m
    assert_true "instance metadata after install" lotto_instance_metadata_exists "${instance}"
    assert_true "volume after install" lotto_volume_exists "lotto-${instance}-data"

    LOTTO_STATE_ROOT="${tmp_root}/state" bash "${DEPLOY_DIR}/install.sh" --name "${instance}" --port 18091
    assert_true "idempotent reinstall keeps volume" lotto_volume_exists "lotto-${instance}-data"

    LOTTO_STATE_ROOT="${tmp_root}/state" bash "${DEPLOY_DIR}/healthcheck.sh" --name "${instance}"
    assert_true "healthcheck succeeds while running" test $? -eq 0

    LOTTO_STATE_ROOT="${tmp_root}/state" bash "${DEPLOY_DIR}/remove.sh" --name "${instance}" --yes
    assert_false "metadata removed" lotto_instance_metadata_exists "${instance}"
    assert_false "volume removed" lotto_volume_exists "lotto-${instance}-data"

    rm -rf "${tmp_root}"
}

test_instance_name_validation
test_port_selection
test_compose_env_generation
test_image_reference_count
test_static_files
test_healthcheck_failure_handling
test_docker_integration

echo ""
echo "Results: ${TESTS_PASSED}/${TESTS_RUN} passed, ${TESTS_FAILED} failed, ${TESTS_SKIPPED} skipped"
if [[ "${TESTS_FAILED}" -ne 0 ]]; then
    exit 1
fi
