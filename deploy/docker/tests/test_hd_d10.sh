#!/usr/bin/env bash
# HD-D10 static checks — container-native Docker V1 runtime.

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

assert_contains() {
    local desc="$1"
    local haystack="$2"
    local needle="$3"
    TESTS_RUN=$((TESTS_RUN + 1))
    if [[ "${haystack}" == *"${needle}"* ]]; then
        TESTS_PASSED=$((TESTS_PASSED + 1))
        echo "PASS: ${desc}"
    else
        TESTS_FAILED=$((TESTS_FAILED + 1))
        echo "FAIL: ${desc} (missing '${needle}')" >&2
    fi
}

skip() {
    TESTS_SKIPPED=$((TESTS_SKIPPED + 1))
    echo "SKIP: $1"
}

DOCKERFILE="${LOTTO_REPO_ROOT}/deploy/docker/Dockerfile"
COMPOSE="${LOTTO_COMPOSE_FILE}"
INSTALL="${DEPLOY_DIR}/install.sh"
INDEX="${LOTTO_REPO_ROOT}/public/index.html"

echo "--- HD-D10 container-native runtime ---"

assert_contains "Dockerfile copies public/" "$(cat "${DOCKERFILE}")" "COPY public/"
assert_contains "Dockerfile sets LOTTO_HTTP_PUBLIC" "$(cat "${DOCKERFILE}")" "LOTTO_HTTP_PUBLIC=/app/public"
assert_contains "Dockerfile sets LOTTO_WS_PATH" "$(cat "${DOCKERFILE}")" "LOTTO_WS_PATH=/ws"
assert_contains "compose sets LOTTO_HTTP_PUBLIC" "$(cat "${COMPOSE}")" "LOTTO_HTTP_PUBLIC: /app/public"
assert_false "compose has no host public bind mount" grep -qE '(\./public|/public:.*bind|type:\s*bind.*public)' "${COMPOSE}"
assert_false "compose has no host nginx dependency" grep -qi 'nginx' "${COMPOSE}"
assert_false "install.sh does not require configure-proxy" grep -q 'configure-proxy.sh' "${INSTALL}"
assert_false "install.sh does not reference host public root" grep -q 'LOTTO_REPO_ROOT}/public' "${INSTALL}"
assert_contains "game.db path in Dockerfile env" "$(cat "${DOCKERFILE}")" "LOTTO_DB_PATH=/app/data/game.db"
assert_contains "compose retains cap_drop" "$(cat "${COMPOSE}")" "cap_drop:"
assert_contains "compose retains no-new-privileges" "$(cat "${COMPOSE}")" "no-new-privileges"
assert_contains "compose retains non-root user" "$(cat "${COMPOSE}")" 'user: "1000:1000"'
assert_contains "release artifact flow intact" "$(cat "${INSTALL}")" "--release-archive"
assert_contains "index.html preserves empty ws port" "$(cat "${INDEX}")" 'name="lotto-ws-port" content=""'
assert_contains "index.html preserves /ws path" "$(cat "${INDEX}")" 'name="lotto-ws-path" content="/ws"'
assert_contains "server.php supports container HTTP mode" "$(cat "${LOTTO_REPO_ROOT}/server.php")" "LOTTO_HTTP_PUBLIC"
assert_contains "ContainerFrontDoor present" "$(cat "${LOTTO_REPO_ROOT}/src/Core/ContainerFrontDoor.php")" "tryWebSocketUpgrade"

assert_contains "runtime overlay helper present" "$(cat "${DEPLOY_DIR}/lib/release-artifact.sh")" "lotto_apply_docker_v1_runtime_overlay"

if lotto_docker_check >/dev/null 2>&1; then
    tmp="$(mktemp -d)"
    archive="${tmp}/rusbingo-v1.0.tar.gz"
    manifest="$(lotto_release_manifest_path_for_version v1.0)"
    work_dir="${tmp}/work"
    image="lotto-game:hdd10$$"

    if git -C "${LOTTO_REPO_ROOT}" archive --format=tar.gz --prefix=rusbingo/ -o "${archive}" v1.0 2>/dev/null; then
        lotto_release_prepare_build_context "${archive}" "${manifest}" "${work_dir}"
        lotto_apply_docker_v1_runtime_overlay "${LOTTO_BUILD_CONTEXT}"

        docker build -t "${image}" \
            -f "${LOTTO_DOCKERFILE}" \
            --build-arg "LOTTO_APPLICATION_VERSION=${LOTTO_APPLICATION_VERSION}" \
            --build-arg "LOTTO_APPLICATION_GIT_SHA=${LOTTO_APPLICATION_GIT_SHA}" \
            --build-arg "LOTTO_RELEASE_ARCHIVE_SHA256=${LOTTO_RELEASE_ARCHIVE_SHA256}" \
            "${LOTTO_BUILD_CONTEXT}" >/dev/null

        assert_true "image contains /app/public/index.html" \
            docker run --rm --entrypoint sh "${image}" -c 'test -f /app/public/index.html'
        assert_true "image contains /app/data directory" \
            docker run --rm --entrypoint sh "${image}" -c 'test -d /app/data'
        docker image rm "${image}" >/dev/null 2>&1 || true
    else
        skip "git archive unavailable — Docker image content checks skipped"
    fi
    rm -rf "${tmp}"
else
    skip "Docker runtime unavailable — image content checks skipped"
fi

echo ""
echo "HD-D10 results: ${TESTS_PASSED}/${TESTS_RUN} passed, ${TESTS_FAILED} failed, ${TESTS_SKIPPED} skipped"
if [[ "${TESTS_FAILED}" -ne 0 ]]; then
    exit 1
fi
