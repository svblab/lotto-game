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

if ! sudo -n true 2>/dev/null; then
    fail "passwordless sudo is not configured (sudo -n true failed)"
fi
pass "sudo -n true"

check_script() {
    local rel="$1"
    local path="${REPO_ROOT}/${rel}"
    if [[ ! -f "${path}" ]]; then
        fail "missing script: ${path}"
    fi
    if ! sudo -n bash "${path}" --help >/dev/null 2>&1; then
        fail "NOPASSWD denied for: bash ${path} --help"
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
