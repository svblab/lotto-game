#!/usr/bin/env bash
# HD-D9 — release archive verification tests (install-path provenance).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
# shellcheck source=../lib/common.sh
source "${DEPLOY_DIR}/lib/common.sh"

TESTS_RUN=0
TESTS_PASSED=0
TESTS_FAILED=0
TESTS_SKIPPED=0

V11_GIT_SHA="ed42d7a2d278a7f27260fc06249b14bc1d638b6d"
V11_ARCHIVE_SHA256="568f528bd32c854f637fb2c31afaeeefeb57aceb8a50331d0dd0daeae60e944a"
V10_GIT_SHA="508cc280704ed72cc3e85df03e57bd6fb42d24ee"
V10_ARCHIVE_SHA256="780bb0ea9157a326908afee593f3f7acbbf1c043903094c2bbd7072e4eb166a8"
ARCHIVE_PREFIX="rusbingo"

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
        echo "FAIL: ${desc} (unexpected '${needle}')" >&2
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

make_v1_release_archive() {
    local output="$1"
    if ! command -v git >/dev/null 2>&1; then
        return 1
    fi
    git -C "${LOTTO_REPO_ROOT}" archive --format=tar.gz --prefix="${ARCHIVE_PREFIX}/" -o "${output}" v1.0
}

make_v11_release_archive() {
    local output="$1"
    if ! command -v git >/dev/null 2>&1; then
        return 1
    fi
    git -C "${LOTTO_REPO_ROOT}" archive --format=tar.gz --prefix="${ARCHIVE_PREFIX}/" -o "${output}" "${V11_GIT_SHA}"
}

context_file_sha256() {
    lotto_release_sha256_file "$1"
}

test_valid_archive_verification() {
    echo "--- valid archive verification (v1.0 baseline) ---"
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

    assert_true "valid v1.0 archive verifies" lotto_release_prepare_build_context "${archive}" "${manifest}" "${work_dir}"
    assert_eq "application version" "v1.0" "${LOTTO_APPLICATION_VERSION}"
    assert_eq "application git sha" "${V10_GIT_SHA}" "${LOTTO_APPLICATION_GIT_SHA}"
    assert_eq "archive sha256" "${V10_ARCHIVE_SHA256}" "${LOTTO_RELEASE_ARCHIVE_SHA256}"
    assert_true "build context has composer.json" test -f "${LOTTO_BUILD_CONTEXT}/composer.json"
    assert_true "build context has server.php" test -f "${LOTTO_BUILD_CONTEXT}/server.php"
    assert_true "provenance file exists" test -f "${LOTTO_RELEASE_PROVENANCE_FILE}"
    rm -rf "${tmp}"
}

test_tampered_archive_fails() {
    echo "--- Test B: tampered archive fails (v1.1) ---"
    local tmp archive manifest work_dir
    tmp="$(mktemp -d)"
    archive="${tmp}/rusbingo-v1.1.tar.gz"
    manifest="$(lotto_release_manifest_path_for_version v1.1)"
    work_dir="${tmp}/work"

    if ! make_v11_release_archive "${archive}"; then
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
    echo "--- provenance metadata (v1.0) ---"
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
    assert_eq "provenance application_git_sha" "${V10_GIT_SHA}" "${application_git_sha}"
    assert_eq "provenance artifact_sha256" "${V10_ARCHIVE_SHA256}" "${artifact_sha256}"
    assert_eq "provenance artifact_filename" "$(basename "${archive}")" "${artifact_filename}"
    rm -rf "${tmp}"
}

test_a_hd10_files_in_release_artifact() {
    echo "--- Test A: HD-D10 runtime files in v1.1 release archive ---"
    local tmp archive
    tmp="$(mktemp -d)"
    archive="${tmp}/rusbingo-v1.1.tar.gz"

    if ! make_v11_release_archive "${archive}"; then
        skip "git archive unavailable — Test A"
        rm -rf "${tmp}"
        return 0
    fi

    assert_true "archive lists server.php" bash -c "tar -tzf '${archive}' | grep -qx '${ARCHIVE_PREFIX}/server.php'"
    assert_true "archive lists StaticHttpServer.php" bash -c "tar -tzf '${archive}' | grep -qx '${ARCHIVE_PREFIX}/src/Core/StaticHttpServer.php'"
    assert_true "archive lists ContainerFrontDoor.php" bash -c "tar -tzf '${archive}' | grep -qx '${ARCHIVE_PREFIX}/src/Core/ContainerFrontDoor.php'"
    assert_true "archive lists public/index.html" bash -c "tar -tzf '${archive}' | grep -qx '${ARCHIVE_PREFIX}/public/index.html'"
    assert_true "archive lists deploy/docker/Dockerfile" bash -c "tar -tzf '${archive}' | grep -qx '${ARCHIVE_PREFIX}/deploy/docker/Dockerfile'"
    rm -rf "${tmp}"
}

test_c_runtime_files_match_verified_artifact() {
    echo "--- Test C: build context runtime files match verified archive (install path) ---"
    local tmp archive manifest state_root instance
    tmp="$(mktemp -d)"
    archive="${tmp}/rusbingo-v1.1.tar.gz"
    manifest="$(lotto_release_manifest_path_for_version v1.1)"
    state_root="${tmp}/state"
    instance="hd9c$$"

    if ! make_v11_release_archive "${archive}"; then
        skip "git archive unavailable — Test C"
        rm -rf "${tmp}"
        return 0
    fi

    LOTTO_STATE_ROOT="${state_root}"
    lotto_prepare_instance_release_build "${instance}" "${archive}" "${manifest}"

    local member expected actual rel
    for rel in server.php src/Core/StaticHttpServer.php src/Core/ContainerFrontDoor.php; do
        expected="$(lotto_release_archive_member_sha256 "${archive}" "${ARCHIVE_PREFIX}/${rel}")"
        actual="$(context_file_sha256 "${LOTTO_BUILD_CONTEXT}/${rel}")"
        assert_eq "context ${rel} matches archive member" "${expected}" "${actual}"
    done
    rm -rf "${tmp}"
}

test_d_mutable_checkout_cannot_override_runtime() {
    echo "--- Test D: mutable checkout cannot override verified application runtime ---"
    local tmp archive manifest state_root instance backup tampered expected actual
    tmp="$(mktemp -d)"
    archive="${tmp}/rusbingo-v1.1.tar.gz"
    manifest="$(lotto_release_manifest_path_for_version v1.1)"
    state_root="${tmp}/state"
    instance="hd9d$$"

    if ! make_v11_release_archive "${archive}"; then
        skip "git archive unavailable — Test D"
        rm -rf "${tmp}"
        return 0
    fi

    backup="${tmp}/server.php.bak"
    cp "${LOTTO_REPO_ROOT}/server.php" "${backup}"
    printf '\n# HD-D9 tamper marker\n' >>"${LOTTO_REPO_ROOT}/server.php"

    LOTTO_STATE_ROOT="${state_root}"
    lotto_prepare_instance_release_build "${instance}" "${archive}" "${manifest}"
    expected="$(lotto_release_archive_member_sha256 "${archive}" "${ARCHIVE_PREFIX}/server.php")"
    actual="$(context_file_sha256 "${LOTTO_BUILD_CONTEXT}/server.php")"
    assert_eq "tampered checkout does not change build context server.php" "${expected}" "${actual}"

    cp "${backup}" "${LOTTO_REPO_ROOT}/server.php"
    rm -f "${backup}"
    rm -rf "${tmp}"
}

test_e_dockerfile_provenance() {
    echo "--- Test E: Dockerfile provenance from verified build context ---"
    local tmp archive manifest state_root instance expected actual dockerfile_path
    tmp="$(mktemp -d)"
    archive="${tmp}/rusbingo-v1.1.tar.gz"
    manifest="$(lotto_release_manifest_path_for_version v1.1)"
    state_root="${tmp}/state"
    instance="hd9e$$"

    if ! make_v11_release_archive "${archive}"; then
        skip "git archive unavailable — Test E"
        rm -rf "${tmp}"
        return 0
    fi

    LOTTO_STATE_ROOT="${state_root}"
    lotto_prepare_instance_release_build "${instance}" "${archive}" "${manifest}"

    dockerfile_path="${LOTTO_BUILD_CONTEXT}/deploy/docker/Dockerfile"
    assert_true "Dockerfile exists in verified build context" test -f "${dockerfile_path}"
    expected="$(lotto_release_archive_member_sha256 "${archive}" "${ARCHIVE_PREFIX}/deploy/docker/Dockerfile")"
    actual="$(context_file_sha256 "${dockerfile_path}")"
    assert_eq "build context Dockerfile matches archive member" "${expected}" "${actual}"
    assert_not_contains "compose uses context-relative Dockerfile" "$(cat "${LOTTO_COMPOSE_FILE}")" 'dockerfile: ${LOTTO_DOCKERFILE}'
    assert_true "compose references deploy/docker/Dockerfile" grep -q 'dockerfile: deploy/docker/Dockerfile' "${LOTTO_COMPOSE_FILE}"
    rm -rf "${tmp}"
}

test_f_provenance_consistency() {
    echo "--- Test F: provenance consistency (release-provenance.env + instance.env) ---"
    local tmp archive manifest state_root instance env_file provenance
    tmp="$(mktemp -d)"
    archive="${tmp}/rusbingo-v1.1.tar.gz"
    manifest="$(lotto_release_manifest_path_for_version v1.1)"
    state_root="${tmp}/state"
    instance="hd9f$$"

    if ! make_v11_release_archive "${archive}"; then
        skip "git archive unavailable — Test F"
        rm -rf "${tmp}"
        return 0
    fi

    LOTTO_STATE_ROOT="${state_root}"
    lotto_prepare_instance_release_build "${instance}" "${archive}" "${manifest}"

    lotto_write_instance_env \
        "${instance}" \
        18095 \
        "127.0.0.1" \
        8080 \
        "256m" \
        "0.5" \
        256 \
        "" \
        "" \
        "" \
        "${LOTTO_BUILD_CONTEXT}" \
        "${LOTTO_APPLICATION_VERSION}" \
        "${LOTTO_APPLICATION_GIT_SHA}" \
        "${LOTTO_RELEASE_ARCHIVE_SHA256}" \
        "$(basename "${archive}")"

    local saved_context="${LOTTO_BUILD_CONTEXT}"
    env_file="$(lotto_instance_env_file "${instance}")"
    lotto_load_instance_env "${instance}"
    provenance="${LOTTO_RELEASE_PROVENANCE_FILE}"
    # shellcheck disable=SC1090
    source "${provenance}"

    assert_eq "instance application version" "v1.1" "${LOTTO_APPLICATION_VERSION}"
    assert_eq "instance git sha" "${V11_GIT_SHA}" "${LOTTO_APPLICATION_GIT_SHA}"
    assert_eq "instance archive sha256" "${V11_ARCHIVE_SHA256}" "${LOTTO_RELEASE_ARCHIVE_SHA256}"
    assert_eq "provenance application_version" "v1.1" "${application_version}"
    assert_eq "provenance application_git_sha" "${V11_GIT_SHA}" "${application_git_sha}"
    assert_eq "provenance artifact_sha256" "${V11_ARCHIVE_SHA256}" "${artifact_sha256}"
    assert_eq "instance build context" "${saved_context}" "${LOTTO_BUILD_CONTEXT}"
    assert_not_contains "instance.env has no LOTTO_DOCKERFILE checkout pin" "$(cat "${env_file}")" "LOTTO_DOCKERFILE="
    rm -rf "${tmp}"
}

test_g_no_application_overlay() {
    echo "--- Test G: no application runtime overlay from LOTTO_REPO_ROOT ---"
    assert_not_contains "release-artifact.sh has no runtime overlay helper" \
        "$(cat "${DEPLOY_DIR}/lib/release-artifact.sh")" \
        "lotto_apply_docker_v1_runtime_overlay"
    assert_not_contains "common.sh install path does not call overlay" \
        "$(cat "${DEPLOY_DIR}/lib/common.sh")" \
        "lotto_apply_docker_v1_runtime_overlay"
    assert_not_contains "overlay does not install server.php from repo" \
        "$(cat "${DEPLOY_DIR}/lib/release-artifact.sh")" \
        'install -D "${repo_root}/server.php"'
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
test_a_hd10_files_in_release_artifact
test_tampered_archive_fails
test_missing_expected_sha256_fails
test_invalid_archive_fails
test_provenance_metadata
test_c_runtime_files_match_verified_artifact
test_d_mutable_checkout_cannot_override_runtime
test_e_dockerfile_provenance
test_f_provenance_consistency
test_g_no_application_overlay
test_install_requires_release_archive

echo ""
echo "Release artifact tests: ${TESTS_PASSED}/${TESTS_RUN} passed, ${TESTS_FAILED} failed, ${TESTS_SKIPPED} skipped"
if [[ "${TESTS_FAILED}" -ne 0 ]]; then
    exit 1
fi
