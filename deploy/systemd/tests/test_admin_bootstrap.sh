#!/usr/bin/env bash
# ADR-038 — AHPC bootstrap credential tests (systemd helpers).

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

test_systemd_pending_paths() {
    echo "--- systemd pending paths ---"
    local tmp
    tmp="$(mktemp -d)"
    LOTTO_INSTANCE_ROOT_PREFIX="${tmp}/lotto-game-"
    assert_eq "pending path" "${tmp}/lotto-game-demo/config/admin-bootstrap.pending" \
        "$(lotto_ahpc_pending_path_systemd demo)"
    assert_eq "ack path" "${tmp}/lotto-game-demo/config/admin-bootstrap.ack" \
        "$(lotto_ahpc_ack_path_systemd demo)"
    rm -rf "${tmp}"
}

test_systemd_promote_from_bootstrap() {
    echo "--- systemd promote ---"
    local tmp data config bootstrap pending
    tmp="$(mktemp -d)"
    LOTTO_INSTANCE_ROOT_PREFIX="${tmp}/lotto-game-"
    data="${tmp}/lotto-game-demo/data"
    config="${tmp}/lotto-game-demo/config"
    mkdir -p "${data}" "${config}"
    bootstrap="${data}/.admin_bootstrap"
    printf 'ADMIN PASSWORD:\nunit-test-pass\n' >"${bootstrap}"

    lotto_promote_systemd_bootstrap_credential "demo"
    pending="${config}/admin-bootstrap.pending"
    assert_true "pending created" test -f "${pending}"
    assert_false "temp bootstrap removed" test -f "${bootstrap}"
    assert_eq "promoted password" "unit-test-pass" "$(lotto_ahpc_read_pending_fields "${pending}" password)"
    rm -rf "${tmp}"
}

test_admin_bootstrap_syntax() {
    echo "--- admin-bootstrap.sh syntax ---"
    if bash -n "${DEPLOY_DIR}/admin-bootstrap.sh"; then
        assert_true "admin-bootstrap.sh syntax" true
    else
        TESTS_RUN=$((TESTS_RUN + 1))
        TESTS_FAILED=$((TESTS_FAILED + 1))
        echo "FAIL: admin-bootstrap.sh syntax" >&2
    fi
}

test_systemd_pending_paths
test_systemd_promote_from_bootstrap
test_admin_bootstrap_syntax

echo ""
echo "Systemd AHPC tests: ${TESTS_PASSED}/${TESTS_RUN} passed, ${TESTS_FAILED} failed"
if [[ "${TESTS_FAILED}" -ne 0 ]]; then
    exit 1
fi
