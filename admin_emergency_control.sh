#!/bin/bash
#
# admin_emergency_control.sh — Emergency stop/restart for the Lotto Game
# WebSocket server.
#
# Purpose: a single, one-command tool for an administrator to safely stop,
# force-stop, start, or restart the server — specifically hardened against
# failure modes already observed in this project's own operations:
#   - `php server.php stop` reporting success while an orphaned child
#     process (often under a different user, e.g. www-data) still holds
#     the listening port.
#   - Stale workerman.*.pid files left behind after an ungraceful kill,
#     causing the next start attempt to think a master is still alive.
#   - "Address already in use" on start, with no clear indication of
#     which PID is actually squatting on the port.
#
# Usage:
#   sudo bash admin_emergency_control.sh status
#   sudo bash admin_emergency_control.sh stop            # graceful, waits, verifies
#   sudo bash admin_emergency_control.sh force-stop       # immediate SIGKILL sweep
#   sudo bash admin_emergency_control.sh start
#   sudo bash admin_emergency_control.sh restart          # graceful stop -> start
#   sudo bash admin_emergency_control.sh force-restart    # THE "emergency" one-liner:
#                                                          # force-stop -> clean -> start
#
# If no subcommand is given, defaults to `force-restart` — the single
# command an admin runs "just in case" when the server is in an unknown
# or hung state and needs to come back up clean, no questions asked.
#
# Prefers systemd (`lotto-server.service`, per docs/LOCAL_ENVIRONMENT.md)
# when the unit is installed, since that's the documented production
# control path. Always falls back to direct `php server.php` control
# and a raw process sweep if systemd isn't set up on this box (e.g. a
# dev/staging environment) or if graceful control fails.
#
# Requires root (or sudo) — process signals and systemctl both need it,
# and the live server has been observed running as www-data while
# operators work as root.

set -u

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PORT="${LOTTO_PORT:-8080}"
SERVICE_NAME="lotto-server.service"
LOG_FILE="${PROJECT_ROOT}/logs/admin_control.log"
GRACEFUL_STOP_TIMEOUT=10   # seconds to wait for graceful stop before escalating
START_BIND_TIMEOUT=15      # seconds to wait for the port to come back up

mkdir -p "${PROJECT_ROOT}/logs"

log() {
    local level="$1"; shift
    local msg="$*"
    local line
    line="$(date '+%Y-%m-%d %H:%M:%S') [${level}] [ADMIN-CONTROL] ${msg}"
    echo "${line}"
    echo "${line}" >> "${LOG_FILE}"
}

require_root() {
    if [ "$(id -u)" -ne 0 ]; then
        log ERROR "This command must be run as root (or via sudo) — process signals and systemctl both require it."
        exit 1
    fi
}

systemd_available() {
    command -v systemctl >/dev/null 2>&1 && systemctl list-unit-files 2>/dev/null | grep -q "^${SERVICE_NAME}"
}

port_in_use() {
    if command -v ss >/dev/null 2>&1; then
        ss -ltn 2>/dev/null | awk '{print $4}' | grep -qE "[:.]${PORT}\$" && return 0
        return 1
    fi
    # Fallback if `ss` isn't installed on this box: a raw bash TCP
    # connect attempt via /dev/tcp, no external tools required. Less
    # precise (can't distinguish LISTEN from other states) but good
    # enough as a "is anything answering on this port" signal so this
    # script never silently mis-reports "free" just because `ss` (or
    # `netstat`) happens to be missing.
    (exec 3<>"/dev/tcp/127.0.0.1/${PORT}") 2>/dev/null
    local result=$?
    exec 3>&- 2>/dev/null
    return ${result}
}

pids_holding_port() {
    if command -v ss >/dev/null 2>&1; then
        ss -ltnp 2>/dev/null | grep ":${PORT} " | grep -oE 'pid=[0-9]+' | grep -oE '[0-9]+' | sort -u
    fi
}

server_php_pids() {
    # Matches the master process (cmdline still contains "server.php" at
    # launch) AND every Workerman worker child — Workerman renames a
    # worker's /proc/PID/cmdline to "WorkerMan: worker process ..." via
    # cli_set_process_title() immediately after forking, which does NOT
    # contain "server.php" at all. Matching only "server.php" silently
    # misses every worker child once it's renamed itself — confirmed by
    # testing this script against a real running instance before this
    # fix, where a leftover worker process (still bound to the port) was
    # completely invisible to a "server.php"-only pgrep pattern.
    pgrep -f "server\.php|WorkerMan:" 2>/dev/null
}

clean_stale_artifacts() {
    log INFO "Removing stale pid/lock files (workerman.*.pid, server.php.pid) if present."
    rm -f "${PROJECT_ROOT}"/workerman.*.pid "${PROJECT_ROOT}"/server.php.pid 2>/dev/null
}

wait_for_port_state() {
    # wait_for_port_state <free|bound> <timeout_seconds>
    local desired="$1"
    local timeout="$2"
    local waited=0
    while [ "${waited}" -lt "${timeout}" ]; do
        if [ "${desired}" = "free" ] && ! port_in_use; then
            return 0
        fi
        if [ "${desired}" = "bound" ] && port_in_use; then
            return 0
        fi
        sleep 1
        waited=$((waited + 1))
    done
    return 1
}

do_status() {
    echo "--- Systemd ---"
    if systemd_available; then
        systemctl status "${SERVICE_NAME}" --no-pager 2>&1 || true
    else
        echo "(${SERVICE_NAME} not installed on this box — direct-control mode only)"
    fi

    echo ""
    echo "--- Port ${PORT} ---"
    if port_in_use; then
        echo "IN USE by PID(s): $(pids_holding_port | tr '\n' ' ')"
    else
        echo "free"
    fi

    echo ""
    echo "--- server.php processes ---"
    local pids
    pids="$(server_php_pids)"
    if [ -n "${pids}" ]; then
        # shellcheck disable=SC2086
        ps -o pid,ppid,user,etime,cmd -p "$(echo "${pids}" | tr '\n' ',' | sed 's/,$//')" 2>/dev/null
    else
        echo "none running"
    fi
}

do_stop_graceful() {
    log INFO "Attempting graceful stop."

    if systemd_available; then
        systemctl stop "${SERVICE_NAME}" 2>&1 | while read -r line; do log INFO "systemctl: ${line}"; done
    elif [ -f "${PROJECT_ROOT}/server.php" ]; then
        (cd "${PROJECT_ROOT}" && php server.php stop 2>&1) | while read -r line; do log INFO "server.php stop: ${line}"; done
    else
        log WARN "Neither systemd unit nor server.php found — nothing to stop gracefully."
    fi

    if wait_for_port_state free "${GRACEFUL_STOP_TIMEOUT}"; then
        log INFO "Graceful stop succeeded — port ${PORT} is free."
        clean_stale_artifacts
        return 0
    fi

    log WARN "Port ${PORT} still in use ${GRACEFUL_STOP_TIMEOUT}s after graceful stop — escalation needed."
    return 1
}

do_force_stop() {
    log WARN "Force-stop requested — sweeping all server.php processes with SIGKILL."

    local pids
    pids="$(server_php_pids)"
    if [ -n "${pids}" ]; then
        log WARN "Killing PID(s): $(echo "${pids}" | tr '\n' ' ')"
        # shellcheck disable=SC2086
        kill -9 ${pids} 2>/dev/null
    fi

    # Belt-and-suspenders: also kill by whatever is literally holding the
    # port, in case a process matched by the port but not by the
    # server.php cmdline pattern (e.g. spawned oddly) was missed above.
    local port_pids
    port_pids="$(pids_holding_port)"
    if [ -n "${port_pids}" ]; then
        log WARN "Also killing PID(s) still bound to port ${PORT}: $(echo "${port_pids}" | tr '\n' ' ')"
        # shellcheck disable=SC2086
        kill -9 ${port_pids} 2>/dev/null
    fi

    clean_stale_artifacts

    if wait_for_port_state free 5; then
        log INFO "Force-stop succeeded — port ${PORT} is free."
        return 0
    else
        log ERROR "Port ${PORT} STILL in use after SIGKILL sweep. Manual investigation required: 'sudo ss -ltnp | grep ${PORT}'."
        return 1
    fi
}

do_start() {
    log INFO "Starting server."

    if port_in_use; then
        log ERROR "Refusing to start — port ${PORT} is already in use. Run 'stop' or 'force-stop' first."
        return 1
    fi

    if systemd_available; then
        systemctl start "${SERVICE_NAME}" 2>&1 | while read -r line; do log INFO "systemctl: ${line}"; done
    else
        (cd "${PROJECT_ROOT}" && nohup php server.php start -d >> "${LOG_FILE}" 2>&1 &)
    fi

    if wait_for_port_state bound "${START_BIND_TIMEOUT}"; then
        log INFO "Server started — port ${PORT} is bound."
        return 0
    else
        log ERROR "Server did not bind port ${PORT} within ${START_BIND_TIMEOUT}s. Check logs/server.log and logs/admin_control.log."
        return 1
    fi
}

cmd="${1:-force-restart}"

case "${cmd}" in
    status)
        do_status
        ;;
    stop)
        require_root
        do_stop_graceful
        ;;
    force-stop)
        require_root
        do_force_stop
        ;;
    start)
        require_root
        do_start
        ;;
    restart)
        require_root
        do_stop_graceful || do_force_stop
        do_start
        ;;
    force-restart)
        require_root
        log WARN "=== EMERGENCY force-restart initiated ==="
        do_stop_graceful || do_force_stop
        do_start
        do_status
        ;;
    *)
        echo "Usage: $0 {status|stop|force-stop|start|restart|force-restart}"
        echo "  (no argument defaults to force-restart)"
        exit 1
        ;;
esac

exit $?
