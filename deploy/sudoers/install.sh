#!/usr/bin/env bash
# Install passwordless sudo rules for Lotto deploy scripts.
# Must be run as root (direct login or su — not via sudo).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TEMPLATE="${SCRIPT_DIR}/lotto-deploy.sudoers.template"
OUTPUT="/etc/sudoers.d/lotto-deploy"

DEPLOY_USER="${1:-${LOTTO_DEPLOY_USER:-cursor-user}}"
REPO_ROOT="${2:-${LOTTO_REPO_ROOT:-$(cd "${SCRIPT_DIR}/../.." && pwd)}}"

usage() {
    cat <<'EOF'
Usage: install.sh [DEPLOY_USER] [REPO_ROOT]

Install /etc/sudoers.d/lotto-deploy so DEPLOY_USER can run deploy scripts
without a password.

Must be executed as root:
  su -
  bash /path/to/lotto-game/deploy/sudoers/install.sh cursor-user

Environment overrides:
  LOTTO_DEPLOY_USER   deploy account (default: cursor-user)
  LOTTO_REPO_ROOT     repository checkout (default: auto-detected)
EOF
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
    usage
    exit 0
fi

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
    echo "ERROR: run as root (login or su -)." >&2
    exit 1
fi

if [[ ! -f "${TEMPLATE}" ]]; then
    echo "ERROR: missing template: ${TEMPLATE}" >&2
    exit 1
fi

if ! id -u "${DEPLOY_USER}" >/dev/null 2>&1; then
    echo "ERROR: deploy user '${DEPLOY_USER}' does not exist." >&2
    exit 1
fi

if command -v realpath >/dev/null 2>&1; then
    REPO_ROOT="$(realpath "${REPO_ROOT}")"
fi

for rel in \
    deploy/systemd/install.sh \
    deploy/systemd/update.sh \
    deploy/systemd/remove.sh \
    deploy/systemd/healthcheck.sh \
    deploy/docker/install.sh \
    deploy/docker/remove.sh \
    deploy/docker/healthcheck.sh
do
    if [[ ! -f "${REPO_ROOT}/${rel}" ]]; then
        echo "ERROR: expected deploy script missing: ${REPO_ROOT}/${rel}" >&2
        exit 1
    fi
done

tmp="$(mktemp)"
trap 'rm -f "${tmp}"' EXIT

sed \
    -e "s|@DEPLOY_USER@|${DEPLOY_USER}|g" \
    -e "s|@REPO_ROOT@|${REPO_ROOT}|g" \
    "${TEMPLATE}" >"${tmp}"

if ! visudo -cf "${tmp}" >/dev/null; then
    echo "ERROR: generated sudoers fragment is invalid." >&2
    visudo -cf "${tmp}" || true
    exit 1
fi

install -m 0440 -o root -g root "${tmp}" "${OUTPUT}"

echo "Installed ${OUTPUT}"
echo "  Deploy user: ${DEPLOY_USER}"
echo "  Repository:  ${REPO_ROOT}"
echo ""
echo "Verify as ${DEPLOY_USER}:"
echo "  bash ${REPO_ROOT}/deploy/sudoers/verify.sh"
echo "  sudo -n /bin/bash ${REPO_ROOT}/deploy/systemd/install.sh --help"
