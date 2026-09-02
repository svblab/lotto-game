#!/usr/bin/env bash
# Verify passwordless sudo for Lotto deploy scripts (run as deploy user).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="${LOTTO_REPO_ROOT:-$(cd "${SCRIPT_DIR}/../.." && pwd)}"

fail() {
    echo "FAIL: $*" >&2
    exit 1
}

pass() {
    echo "PASS: $*"
}

if ! sudo -n /bin/bash "${REPO_ROOT}/deploy/systemd/install.sh" --help >/dev/null 2>&1; then
    fail "passwordless sudo is not configured for deploy/systemd/install.sh"
fi
pass "deploy/systemd/install.sh --help"

check_script() {
    local rel="$1"
    local path="${REPO_ROOT}/${rel}"
    if [[ ! -f "${path}" ]]; then
        fail "missing script: ${path}"
    fi
    if ! sudo -n /bin/bash "${path}" --help >/dev/null 2>&1; then
        fail "NOPASSWD denied for: /bin/bash ${path} --help"
    fi
    pass "${rel}"
}

for rel in \
    deploy/systemd/install.sh \
    deploy/systemd/update.sh \
    deploy/systemd/remove.sh \
    deploy/systemd/healthcheck.sh \
    deploy/docker/install.sh \
    deploy/docker/remove.sh \
    deploy/docker/healthcheck.sh
do
    check_script "${rel}"
done

echo ""
echo "Passwordless deploy sudo is configured."
