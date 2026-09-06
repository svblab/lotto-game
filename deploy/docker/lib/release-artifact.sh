#!/usr/bin/env bash
# HD-D9 — verify and extract immutable application release archives.

set -euo pipefail

lotto_release_err() {
    echo "ERROR: $*" >&2
}

lotto_release_normalize_sha256() {
    local value="$1"
    value="${value#sha256:}"
    value="${value#SHA256:}"
    echo "${value,,}"
}

lotto_release_sha256_file() {
    local file="$1"
    local digest=""

    if [[ ! -f "${file}" ]]; then
        lotto_release_err "Release archive not found: ${file}"
        return 1
    fi

    if command -v sha256sum >/dev/null 2>&1; then
        digest="$(sha256sum "${file}" | awk '{print $1}')"
    elif command -v shasum >/dev/null 2>&1; then
        digest="$(shasum -a 256 "${file}" | awk '{print $1}')"
    else
        lotto_release_err "Neither sha256sum nor shasum is available for SHA256 verification."
        return 1
    fi

    lotto_release_normalize_sha256 "${digest}"
}

lotto_release_manifest_path_for_version() {
    local version="$1"
    local deploy_dir
    deploy_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
    echo "${deploy_dir}/release-manifests/${version}.env"
}

lotto_release_load_manifest() {
    local manifest_path="$1"

    if [[ ! -f "${manifest_path}" ]]; then
        lotto_release_err "Release manifest not found: ${manifest_path}"
        return 1
    fi

    # shellcheck disable=SC1090
    source "${manifest_path}"

    if [[ -z "${LOTTO_APPLICATION_VERSION:-}" ]]; then
        lotto_release_err "Manifest missing LOTTO_APPLICATION_VERSION: ${manifest_path}"
        return 1
    fi
    if [[ -z "${LOTTO_APPLICATION_GIT_SHA:-}" ]]; then
        lotto_release_err "Manifest missing LOTTO_APPLICATION_GIT_SHA: ${manifest_path}"
        return 1
    fi
    if [[ ! "${LOTTO_APPLICATION_GIT_SHA}" =~ ^[0-9a-fA-F]{40}$ ]]; then
        lotto_release_err "Manifest LOTTO_APPLICATION_GIT_SHA must be a full 40-character Git SHA."
        return 1
    fi
    if [[ -z "${LOTTO_RELEASE_ARCHIVE_SHA256:-}" ]]; then
        lotto_release_err "Manifest missing LOTTO_RELEASE_ARCHIVE_SHA256: ${manifest_path}"
        return 1
    fi
    if [[ -z "${LOTTO_RELEASE_ARCHIVE_PREFIX:-}" ]]; then
        lotto_release_err "Manifest missing LOTTO_RELEASE_ARCHIVE_PREFIX: ${manifest_path}"
        return 1
    fi

    LOTTO_APPLICATION_GIT_SHA="${LOTTO_APPLICATION_GIT_SHA,,}"
    LOTTO_RELEASE_ARCHIVE_SHA256="$(lotto_release_normalize_sha256 "${LOTTO_RELEASE_ARCHIVE_SHA256}")"
}

lotto_release_validate_tar_archive_paths() {
    local archive="$1"
    local entry=""

    if ! tar -tzf "${archive}" >/dev/null 2>&1; then
        lotto_release_err "Release archive is not a valid gzip-compressed tar: ${archive}"
        return 1
    fi

    while IFS= read -r entry; do
        [[ -z "${entry}" ]] && continue
        case "${entry}" in
            /*|-*)
                lotto_release_err "Unsafe archive path rejected: ${entry}"
                return 1
                ;;
        esac
        if [[ "${entry}" == *".."* ]]; then
            lotto_release_err "Unsafe archive path rejected: ${entry}"
            return 1
        fi
    done < <(tar -tzf "${archive}")
}

lotto_release_verify_archive_sha256() {
    local archive="$1"
    local expected="$2"
    local actual=""

    expected="$(lotto_release_normalize_sha256 "${expected}")"
    if [[ -z "${expected}" ]]; then
        lotto_release_err "Expected release archive SHA256 is required."
        return 1
    fi

    actual="$(lotto_release_sha256_file "${archive}")"
    if [[ "${actual}" != "${expected}" ]]; then
        lotto_release_err "Release archive SHA256 mismatch."
        lotto_release_err "  expected: ${expected}"
        lotto_release_err "  actual:   ${actual}"
        return 1
    fi
}

lotto_release_extract_archive() {
    local archive="$1"
    local extract_root="$2"

    mkdir -p "${extract_root}"
    chmod 700 "${extract_root}"
    tar -xzf "${archive}" -C "${extract_root}" --no-same-owner --no-same-permissions
}

lotto_release_build_context_path() {
    local extract_root="$1"
    local prefix="$2"
    echo "${extract_root}/${prefix}"
}

lotto_release_write_provenance_file() {
    local provenance_file="$1"
    local archive_path="$2"

    cat >"${provenance_file}" <<EOF
application_version=${LOTTO_APPLICATION_VERSION}
application_git_sha=${LOTTO_APPLICATION_GIT_SHA}
artifact_filename=$(basename "${archive_path}")
artifact_sha256=${LOTTO_RELEASE_ARCHIVE_SHA256}
EOF
    chmod 600 "${provenance_file}"
}

lotto_release_prepare_build_context() {
    local archive_path="$1"
    local manifest_path="$2"
    local work_dir="$3"

    local extract_root context_path provenance_file

    if [[ ! -f "${archive_path}" ]]; then
        lotto_release_err "Release archive is required: ${archive_path}"
        return 1
    fi

    lotto_release_load_manifest "${manifest_path}" || return 1
    lotto_release_verify_archive_sha256 "${archive_path}" "${LOTTO_RELEASE_ARCHIVE_SHA256}" || return 1
    lotto_release_validate_tar_archive_paths "${archive_path}" || return 1

    extract_root="${work_dir}/extracted"
    rm -rf "${extract_root}"
    lotto_release_extract_archive "${archive_path}" "${extract_root}"

    context_path="$(lotto_release_build_context_path "${extract_root}" "${LOTTO_RELEASE_ARCHIVE_PREFIX}")"
    if [[ ! -f "${context_path}/composer.json" || ! -f "${context_path}/server.php" ]]; then
        lotto_release_err "Verified archive does not contain expected application root at ${context_path}"
        return 1
    fi

    provenance_file="${work_dir}/release-provenance.env"
    lotto_release_write_provenance_file "${provenance_file}" "${archive_path}"

    LOTTO_BUILD_CONTEXT="${context_path}"
    LOTTO_RELEASE_PROVENANCE_FILE="${provenance_file}"
    LOTTO_RELEASE_WORK_DIR="${work_dir}"
}

lotto_apply_docker_v1_runtime_overlay() {
    local context_path="$1"
    local repo_root="${LOTTO_REPO_ROOT:-}"

    if [[ -z "${repo_root}" || ! -d "${repo_root}" ]]; then
        lotto_release_err "Docker runtime overlay requires LOTTO_REPO_ROOT."
        return 1
    fi

    # Distribution layer + container-native runtime hooks (HD-D10). Application
    # archive SHA is verified before overlay; these paths are installer-managed.
    install -D "${repo_root}/server.php" "${context_path}/server.php"
    install -D "${repo_root}/src/Core/StaticHttpServer.php" "${context_path}/src/Core/StaticHttpServer.php"
    install -D "${repo_root}/src/Core/ContainerFrontDoor.php" "${context_path}/src/Core/ContainerFrontDoor.php"
    mkdir -p "${context_path}/deploy/docker"
    cp -a "${repo_root}/deploy/docker/." "${context_path}/deploy/docker/"
}
