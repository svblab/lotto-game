#!/usr/bin/env bash
# HD-D9 — release archive verification tests.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
# shellcheck source=../lib/common.sh
source "${DEPLOY_DIR}/lib/common.sh"

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

skip() {
    local desc="$1"
    TESTS_SKIPPED=$((TESTS_SKIPPED + 1))
    echo "SKIP: ${desc}"
}

make_v1_release_archive() {
    local output="$1"
    if ! command -v git >/dev/null 2>&1; then
        return 1
    fi
    git -C "${LOTTO_REPO_ROOT}" archive --format=tar.gz --prefix=rusbingo/ -o "${output}" v1.0
}

test_valid_archive_verification() {
    echo "--- valid archive verification ---"
    local tmp archive manifest work_dir
    tmp="$(mktemp -d)"
    archive="${tmp}/rusbingo-v1.0.tar.gz"
    manifest="$(lotto_release_manifest_path_for_version v1.0)"
    work_dir="${tmp}/work"

    if ! make_v1_release_archive "${archive}"; then
        skip "git archive unavailable — valid archive verification"
        rm -rf "${tmp}"
        return 0
    fi

    assert_true "valid archive verifies" lotto_release_prepare_build_context "${archive}" "${manifest}" "${work_dir}"
    assert_eq "application version" "v1.0" "${LOTTO_APPLICATION_VERSION}"
    assert_eq "application git sha" "508cc280704ed72cc3e85df03e57bd6fb42d24ee" "${LOTTO_APPLICATION_GIT_SHA}"
    assert_eq "archive sha256" "780bb0ea9157a326908afee593f3f7acbbf1c043903094c2bbd7072e4eb166a8" "${LOTTO_RELEASE_ARCHIVE_SHA256}"
    assert_true "build context has composer.json" test -f "${LOTTO_BUILD_CONTEXT}/composer.json"
    assert_true "build context has server.php" test -f "${LOTTO_BUILD_CONTEXT}/server.php"
    assert_true "provenance file exists" test -f "${LOTTO_RELEASE_PROVENANCE_FILE}"
    rm -rf "${tmp}"
}

test_tampered_archive_fails() {
    echo "--- tampered archive fails ---"
    local tmp archive manifest work_dir
    tmp="$(mktemp -d)"
    archive="${tmp}/rusbingo-v1.0.tar.gz"
    manifest="$(lotto_release_manifest_path_for_version v1.0)"
    work_dir="${tmp}/work"

    if ! make_v1_release_archive "${archive}"; then
        skip "git archive unavailable — tampered archive test"
        rm -rf "${tmp}"
        return 0
    fi

    printf 'tamper' >>"${archive}"
    assert_false "tampered archive rejected" lotto_release_prepare_build_context "${archive}" "${manifest}" "${work_dir}"
    rm -rf "${tmp}"
}

test_missing_expected_sha256_fails() {
    echo "--- missing expected sha256 fails ---"
    local tmp archive manifest work_dir
    tmp="$(mktemp -d)"
    archive="${tmp}/rusbingo-v1.0.tar.gz"
    manifest="${tmp}/bad.env"
    work_dir="${tmp}/work"

    if ! make_v1_release_archive "${archive}"; then
        skip "git archive unavailable — missing sha256 test"
        rm -rf "${tmp}"
        return 0
    fi

    cat >"${manifest}" <<'EOF'
LOTTO_APPLICATION_VERSION=v1.0
LOTTO_APPLICATION_GIT_SHA=508cc280704ed72cc3e85df03e57bd6fb42d24ee
LOTTO_RELEASE_ARCHIVE_SHA256=
LOTTO_RELEASE_ARCHIVE_PREFIX=rusbingo
EOF

    assert_false "missing expected sha256 rejected" lotto_release_prepare_build_context "${archive}" "${manifest}" "${work_dir}"
    rm -rf "${tmp}"
}

test_invalid_archive_fails() {
    echo "--- invalid archive fails ---"
    local tmp archive manifest work_dir
    tmp="$(mktemp -d)"
    archive="${tmp}/invalid.tar.gz"
    manifest="$(lotto_release_manifest_path_for_version v1.0)"
    work_dir="${tmp}/work"

    printf 'not-a-tar-archive' >"${archive}"
    assert_false "invalid archive rejected" lotto_release_prepare_build_context "${archive}" "${manifest}" "${work_dir}"
    rm -rf "${tmp}"
}

test_provenance_metadata() {
    echo "--- provenance metadata ---"
    local tmp archive manifest work_dir provenance
    tmp="$(mktemp -d)"
    archive="${tmp}/rusbingo-v1.0.tar.gz"
    manifest="$(lotto_release_manifest_path_for_version v1.0)"
    work_dir="${tmp}/work"

    if ! make_v1_release_archive "${archive}"; then
        skip "git archive unavailable — provenance metadata test"
        rm -rf "${tmp}"
        return 0
    fi

    lotto_release_prepare_build_context "${archive}" "${manifest}" "${work_dir}"
    provenance="${LOTTO_RELEASE_PROVENANCE_FILE}"
    # shellcheck disable=SC1090
    source "${provenance}"

    assert_eq "provenance application_version" "v1.0" "${application_version}"
    assert_eq "provenance application_git_sha" "508cc280704ed72cc3e85df03e57bd6fb42d24ee" "${application_git_sha}"
    assert_eq "provenance artifact_sha256" "780bb0ea9157a326908afee593f3f7acbbf1c043903094c2bbd7072e4eb166a8" "${artifact_sha256}"
    assert_eq "provenance artifact_filename" "$(basename "${archive}")" "${artifact_filename}"
    rm -rf "${tmp}"
}

test_install_requires_release_archive() {
    echo "--- install requires release archive ---"
    if [[ "$(uname -s)" != "Linux" ]]; then
        skip "Linux-only install archive requirement test"
        return 0
    fi
    local tmp_root rc
    tmp_root="$(mktemp -d)"
    set +e
    LOTTO_STATE_ROOT="${tmp_root}/state" bash "${DEPLOY_DIR}/install.sh" --name noarchive --port 18094 >/dev/null 2>&1
    rc=$?
    set -e
    assert_eq "install without archive fails" "1" "${rc}"
    rm -rf "${tmp_root}"
}

test_valid_archive_verification
test_tampered_archive_fails
test_missing_expected_sha256_fails
test_invalid_archive_fails
test_provenance_metadata
test_install_requires_release_archive

echo ""
echo "Release artifact tests: ${TESTS_PASSED}/${TESTS_RUN} passed, ${TESTS_FAILED} failed, ${TESTS_SKIPPED} skipped"
if [[ "${TESTS_FAILED}" -ne 0 ]]; then
    exit 1
fi
