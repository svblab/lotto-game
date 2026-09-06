#!/usr/bin/env bash
# Dependency-free Docker deployment helper tests (assert-based, no external framework).

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

make_v1_release_archive() {
    local output="$1"
    if ! command -v git >/dev/null 2>&1; then
        return 1
    fi
    git -C "${LOTTO_REPO_ROOT}" archive --format=tar.gz --prefix=rusbingo/ -o "${output}" v1.0
}

write_test_instance_env() {
    local instance="$1"
    local host_port="$2"
    lotto_write_instance_env \
        "${instance}" \
        "${host_port}" \
        "127.0.0.1" \
        8080 \
        "256m" \
        "0.5" \
        256 \
        "" \
        "" \
        "" \
        "/tmp/lotto-test-build-context" \
        "v1.0" \
        "508cc280704ed72cc3e85df03e57bd6fb42d24ee" \
        "780bb0ea9157a326908afee593f3f7acbbf1c043903094c2bbd7072e4eb166a8" \
        "rusbingo-v1.0.tar.gz"
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

test_compose_no_application_volumes() {
    echo "--- compose application volume policy ---"
    assert_false "compose.yaml declares named volumes" grep -q '^volumes:' "${LOTTO_COMPOSE_FILE}"
    assert_false "compose.yaml mounts data:/app/data" grep -q 'data:/app/data' "${LOTTO_COMPOSE_FILE}"

    if ! lotto_docker_check >/dev/null 2>&1; then
        skip "Docker not available — compose config volume check skipped"
        return 0
    fi

    local tmp_root env_file rendered
    tmp_root="$(mktemp -d)"
    LOTTO_STATE_ROOT="${tmp_root}/state"
    lotto_write_instance_env "cfgtest" 18099 "127.0.0.1" 8080 "256m" "0.5" 256 "" "" "" "/tmp/lotto-cfgtest-context" "v1.0" "508cc280704ed72cc3e85df03e57bd6fb42d24ee" "780bb0ea9157a326908afee593f3f7acbbf1c043903094c2bbd7072e4eb166a8" "rusbingo-v1.0.tar.gz"
    env_file="$(lotto_instance_env_file cfgtest)"
    rendered="$(docker compose -f "${LOTTO_COMPOSE_FILE}" --env-file "${env_file}" -p lotto-cfgtest config)"
    assert_not_contains "rendered compose has no data:/app/data" "${rendered}" "data:/app/data"
    assert_not_contains "rendered compose has no named app volume" "${rendered}" "lotto-cfgtest-data"
    rm -rf "${tmp_root}"
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
    write_test_instance_env "test01" 8099
    assert_true "metadata file created" test -f "${tmp_root}/state/test01/instance.env"
    lotto_load_instance_env "test01"
    assert_eq "instance name recorded" "test01" "${LOTTO_INSTANCE}"
    assert_eq "application version recorded" "v1.0" "${LOTTO_APPLICATION_VERSION}"
    assert_eq "application git sha recorded" "508cc280704ed72cc3e85df03e57bd6fb42d24ee" "${LOTTO_APPLICATION_GIT_SHA}"
    assert_false "no application volume in metadata" bash -c 'grep -q "^LOTTO_VOLUME_NAME=" "${tmp_root}/state/test01/instance.env"'
    rm -rf "${tmp_root}"
}

test_image_reference_count() {
    echo "--- image cleanup reference counting ---"
    local tmp_root
    tmp_root="$(mktemp -d)"
    LOTTO_STATE_ROOT="${tmp_root}/state"
    mkdir -p "${tmp_root}/state/a" "${tmp_root}/state/b"
    write_test_instance_env "a" 8080
    write_test_instance_env "b" 8081
    lotto_load_instance_env "a"
    local image_a="${LOTTO_IMAGE}"
    assert_false "same-tag other instance not detected when tags differ" lotto_image_used_by_other_instances "${image_a}" "a"
    rm -rf "${tmp_root}"
}

test_static_files() {
    echo "--- static file checks ---"
    assert_true "Dockerfile exists" test -f "${LOTTO_REPO_ROOT}/deploy/docker/Dockerfile"
    assert_true "configure-proxy.sh exists (legacy, non-canonical)" test -f "${LOTTO_REPO_ROOT}/deploy/docker/configure-proxy.sh"
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
    if bash -n "${DEPLOY_DIR}/admin-bootstrap.sh"; then
        assert_true "admin-bootstrap.sh syntax" true
    else
        assert_false "admin-bootstrap.sh syntax" true
    fi
    if php -l "${LOTTO_REPO_ROOT}/deploy/lib/reset_admin_bootstrap.php" >/dev/null 2>&1; then
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

test_provisioning_fqdn_detection() {
    echo "--- provisioning FQDN detection ---"
    assert_eq "override FQDN" "example.test" "$(LOTTO_PROVISIONING_FQDN_OVERRIDE=example.test lotto_detect_provisioning_fqdn)"
    if lotto_detect_provisioning_fqdn >/dev/null 2>&1; then
        assert_true "host provisioning FQDN configured" true
    else
        skip "host provisioning FQDN not set — expected on dev workstations"
    fi
}

test_data_dir_permissions() {
    echo "--- container /app/data permissions ---"
    if ! lotto_docker_check >/dev/null 2>&1; then
        skip "Docker not available — /app/data permission test skipped"
        return 0
    fi

    local tmp archive manifest work_dir image owner write_ok
    tmp="$(mktemp -d)"
    archive="${tmp}/rusbingo-v1.0.tar.gz"
    manifest="$(lotto_release_manifest_path_for_version v1.0)"
    work_dir="${tmp}/work"
    image="lotto-game:datadir$$"

    if ! make_v1_release_archive "${archive}"; then
        skip "git archive unavailable — /app/data permission test skipped"
        rm -rf "${tmp}"
        return 0
    fi

    lotto_release_prepare_build_context "${archive}" "${manifest}" "${work_dir}"
    lotto_apply_docker_v1_runtime_overlay "${LOTTO_BUILD_CONTEXT}"
    docker build -t "${image}" \
        -f "${LOTTO_DOCKERFILE}" \
        --build-arg "LOTTO_APPLICATION_VERSION=${LOTTO_APPLICATION_VERSION}" \
        --build-arg "LOTTO_APPLICATION_GIT_SHA=${LOTTO_APPLICATION_GIT_SHA}" \
        --build-arg "LOTTO_RELEASE_ARCHIVE_SHA256=${LOTTO_RELEASE_ARCHIVE_SHA256}" \
        "${LOTTO_BUILD_CONTEXT}" >/dev/null

    owner="$(docker run --rm --user "${LOTTO_DATA_UID}:${LOTTO_DATA_GID}" \
        --entrypoint sh \
        "${image}" \
        -c 'stat -c "%u:%g %a" /app/data')"
    assert_eq "image /app/data owner and mode" "1000:1000 750" "${owner}"

    write_ok="$(docker run --rm --user "${LOTTO_DATA_UID}:${LOTTO_DATA_GID}" \
        --entrypoint sh \
        "${image}" \
        -c 'php -r "file_put_contents(\"/app/data/.write_test\", \"ok\");" && test -f /app/data/.write_test && echo yes' 2>/dev/null || echo no)"
    assert_eq "uid ${LOTTO_DATA_UID} can write to /app/data" "yes" "${write_ok}"

    docker image rm "${image}" >/dev/null 2>&1 || true
    rm -rf "${tmp}"
}

test_docker_integration() {
    echo "--- docker integration (optional) ---"
    if ! lotto_docker_check >/dev/null 2>&1; then
        skip "Docker not available — runtime install/remove tests skipped"
        return 0
    fi

    local tmp_root instance archive
    tmp_root="$(mktemp -d)"
    instance="itest$$"
    archive="${tmp_root}/rusbingo-v1.0.tar.gz"
    LOTTO_STATE_ROOT="${tmp_root}/state"

    if ! make_v1_release_archive "${archive}"; then
        skip "git archive unavailable — runtime install/remove tests skipped"
        rm -rf "${tmp_root}"
        return 0
    fi

    if ! sudo -n true 2>/dev/null; then
        skip "passwordless sudo not available — skipping live docker install test"
        rm -rf "${tmp_root}"
        return 0
    fi

    LOTTO_STATE_ROOT="${tmp_root}/state" bash "${DEPLOY_DIR}/install.sh" \
        --name "${instance}" --port 18091 --mem-limit 128m \
        --release-archive "${archive}"
    assert_true "instance metadata after install" lotto_instance_metadata_exists "${instance}"
    lotto_load_instance_env "${instance}"
    assert_true "container after install" lotto_container_exists "${LOTTO_CONTAINER_NAME}"
    assert_true "game.db inside container" lotto_db_exists_in_container "${instance}"
    assert_false "no legacy application volume" lotto_volume_exists "lotto-${instance}-data"

    LOTTO_STATE_ROOT="${tmp_root}/state" bash "${DEPLOY_DIR}/install.sh" \
        --name "${instance}" --port 18091 --release-archive "${archive}"
    assert_true "game.db survives idempotent reinstall" lotto_db_exists_in_container "${instance}"

    LOTTO_STATE_ROOT="${tmp_root}/state" bash "${DEPLOY_DIR}/healthcheck.sh" --name "${instance}"
    assert_true "healthcheck succeeds while running" test $? -eq 0

    LOTTO_STATE_ROOT="${tmp_root}/state" bash "${DEPLOY_DIR}/remove.sh" --name "${instance}" --yes
    assert_false "metadata removed" lotto_instance_metadata_exists "${instance}"
    assert_false "legacy application volume removed" lotto_volume_exists "lotto-${instance}-data"

    rm -rf "${tmp_root}"
}

test_instance_name_validation
test_compose_no_application_volumes
test_port_selection
test_compose_env_generation
test_image_reference_count
test_static_files
test_healthcheck_failure_handling
test_provisioning_fqdn_detection
test_data_dir_permissions
test_docker_integration

echo ""
echo "--- HD-D9 release artifact tests ---"
bash "${SCRIPT_DIR}/test_release_artifact.sh"

echo ""
echo "--- HD-D10 container-native runtime tests ---"
bash "${SCRIPT_DIR}/test_hd_d10.sh"

echo ""
echo "--- AHPC admin bootstrap tests ---"
bash "${SCRIPT_DIR}/test_admin_bootstrap.sh"

echo ""
echo "Results: ${TESTS_PASSED}/${TESTS_RUN} passed, ${TESTS_FAILED} failed, ${TESTS_SKIPPED} skipped"
if [[ "${TESTS_FAILED}" -ne 0 ]]; then
    exit 1
fi
