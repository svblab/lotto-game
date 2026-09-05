#!/usr/bin/env bash
# ADR-038 — Acknowledged Host Pending Credential (AHPC) shared helpers.

set -euo pipefail

AHPC_SCHEMA_VERSION=1
AHPC_USERNAME="admin"

lotto_ahpc_err() {
    echo "ERROR: $*" >&2
}

lotto_ahpc_iso8601_utc() {
    date -u +"%Y-%m-%dT%H:%M:%SZ"
}

lotto_ahpc_json_escape() {
    local value="$1"
    python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "${value}" 2>/dev/null \
        || php -r 'echo json_encode($argv[1], JSON_UNESCAPED_UNICODE);' "${value}"
}

lotto_ahpc_parse_bootstrap_file() {
    local file="$1"
    local password=""

    if [[ ! -f "${file}" ]]; then
        lotto_ahpc_err "Bootstrap file not found: ${file}"
        return 1
    fi

    password="$(awk '
        /^ADMIN PASSWORD:/ { getline; if (length($0) > 0) { print; exit 0 } }
    ' "${file}")"

    if [[ -z "${password}" ]]; then
        lotto_ahpc_err "Bootstrap file has no password payload: ${file}"
        return 1
    fi

    printf '%s' "${password}"
}

lotto_ahpc_validate_pending_permissions() {
    local file="$1"
    local mode owner

    mode="$(stat -c '%a' "${file}" 2>/dev/null || stat -f '%OLp' "${file}")"
    owner="$(stat -c '%u:%g' "${file}" 2>/dev/null || stat -f '%u:%g' "${file}")"

    if [[ "${mode}" != "600" ]]; then
        lotto_ahpc_err "Pending credential permissions must be 0600 (got ${mode})."
        return 1
    fi
    if [[ "${owner}" != "0:0" ]]; then
        lotto_ahpc_err "Pending credential owner must be root:root (got ${owner})."
        return 1
    fi
}

lotto_ahpc_write_pending_atomic() {
    local pending_path="$1"
    local instance="$2"
    local password="$3"
    local created_at dir tmp

    created_at="$(lotto_ahpc_iso8601_utc)"
    dir="$(dirname "${pending_path}")"
    mkdir -p "${dir}"
    chmod 755 "${dir}"

    tmp="$(mktemp "${dir}/.admin-bootstrap.pending.XXXXXX")"
    chmod 600 "${tmp}"
    chown root:root "${tmp}" 2>/dev/null || true

    if command -v python3 >/dev/null 2>&1; then
        AHPC_INSTANCE="${instance}" \
        AHPC_PASSWORD="${password}" \
        AHPC_CREATED_AT="${created_at}" \
        python3 - <<'PY' >"${tmp}"
import json, os
doc = {
    "schema_version": 1,
    "instance": os.environ["AHPC_INSTANCE"],
    "username": "admin",
    "password": os.environ["AHPC_PASSWORD"],
    "created_at": os.environ["AHPC_CREATED_AT"],
}
print(json.dumps(doc, separators=(",", ":")))
PY
    else
        php -r '
            $doc = [
                "schema_version" => 1,
                "instance" => getenv("AHPC_INSTANCE"),
                "username" => "admin",
                "password" => getenv("AHPC_PASSWORD"),
                "created_at" => getenv("AHPC_CREATED_AT"),
            ];
            echo json_encode($doc, JSON_UNESCAPED_UNICODE);
        ' \
            AHPC_INSTANCE="${instance}" \
            AHPC_PASSWORD="${password}" \
            AHPC_CREATED_AT="${created_at}" >"${tmp}"
    fi

    chmod 600 "${tmp}"
    chown root:root "${tmp}"
    mv -f "${tmp}" "${pending_path}"
    chmod 600 "${pending_path}"
    chown root:root "${pending_path}"
}

lotto_ahpc_promote_bootstrap_file() {
    local instance="$1"
    local bootstrap_file="$2"
    local pending_path="$3"
    local password

    password="$(lotto_ahpc_parse_bootstrap_file "${bootstrap_file}")"
    lotto_ahpc_write_pending_atomic "${pending_path}" "${instance}" "${password}"
    rm -f "${bootstrap_file}"
}

lotto_ahpc_read_pending_fields() {
    local pending_path="$1"
    local field="$2"

    if [[ ! -f "${pending_path}" ]]; then
        return 2
    fi

    lotto_ahpc_validate_pending_permissions "${pending_path}" || return 4

    if command -v python3 >/dev/null 2>&1; then
        python3 - "${pending_path}" "${field}" <<'PY'
import json, sys
path, field = sys.argv[1], sys.argv[2]
with open(path, encoding="utf-8") as fh:
    data = json.load(fh)
required = ("schema_version", "instance", "username", "password", "created_at")
for key in required:
    if key not in data or data[key] in (None, ""):
        raise SystemExit(4)
if data.get("schema_version") != 1:
    raise SystemExit(4)
print(data[field])
PY
    else
        php -r '
            $data = json_decode(file_get_contents($argv[1]), true);
            if (!is_array($data)) { exit(4); }
            $required = ["schema_version", "instance", "username", "password", "created_at"];
            foreach ($required as $key) {
                if (empty($data[$key]) && ($key !== "schema_version")) { exit(4); }
            }
            if (($data["schema_version"] ?? null) !== 1) { exit(4); }
            echo $data[$argv[2]];
        ' "${pending_path}" "${field}"
    fi
}

lotto_ahpc_pending_state() {
    local pending_path="$1"
    local ack_path="$2"

    if [[ -f "${pending_path}" ]]; then
        echo "pending"
        return 0
    fi
    if [[ -f "${ack_path}" ]]; then
        echo "acknowledged"
        return 0
    fi
    echo "none"
}

lotto_ahpc_emit_status_json() {
    local instance="$1"
    local pending_path="$2"
    local ack_path="$3"
    local state created_at username

    state="$(lotto_ahpc_pending_state "${pending_path}" "${ack_path}")"
    created_at=""
    username="${AHPC_USERNAME}"

    if [[ "${state}" == "pending" ]]; then
        created_at="$(lotto_ahpc_read_pending_fields "${pending_path}" created_at)" || return 4
        username="$(lotto_ahpc_read_pending_fields "${pending_path}" username)" || return 4
    fi

    if command -v python3 >/dev/null 2>&1; then
        AHPC_INSTANCE="${instance}" \
        AHPC_STATE="${state}" \
        AHPC_PATH="${pending_path}" \
        AHPC_CREATED_AT="${created_at}" \
        AHPC_USERNAME="${username}" \
        python3 - <<'PY'
import json, os
doc = {
    "schema_version": 1,
    "instance": os.environ["AHPC_INSTANCE"],
    "state": os.environ["AHPC_STATE"],
    "pending_path": os.environ["AHPC_PATH"] if os.environ["AHPC_STATE"] == "pending" else None,
    "username": os.environ["AHPC_USERNAME"],
    "created_at": os.environ["AHPC_CREATED_AT"] or None,
}
print(json.dumps(doc, separators=(",", ":")))
PY
    else
        php -r '
            $doc = [
                "schema_version" => 1,
                "instance" => getenv("AHPC_INSTANCE"),
                "state" => getenv("AHPC_STATE"),
                "pending_path" => getenv("AHPC_STATE") === "pending" ? getenv("AHPC_PATH") : null,
                "username" => getenv("AHPC_USERNAME"),
                "created_at" => getenv("AHPC_CREATED_AT") ?: null,
            ];
            echo json_encode($doc, JSON_UNESCAPED_UNICODE);
        ' \
            AHPC_INSTANCE="${instance}" \
            AHPC_STATE="${state}" \
            AHPC_PATH="${pending_path}" \
            AHPC_CREATED_AT="${created_at}" \
            AHPC_USERNAME="${username}"
    fi
}

lotto_ahpc_emit_status_human() {
    local instance="$1"
    local pending_path="$2"
    local ack_path="$3"
    local state

    state="$(lotto_ahpc_pending_state "${pending_path}" "${ack_path}")"
    echo "instance: ${instance}"
    echo "state: ${state}"
    if [[ "${state}" == "pending" ]]; then
        echo "pending_path: ${pending_path}"
        echo "username: ${AHPC_USERNAME}"
    fi
}

lotto_ahpc_emit_read_json() {
    local instance="$1"
    local pending_path="$2"
    local password created_at username

    password="$(lotto_ahpc_read_pending_fields "${pending_path}" password)" || return $?
    created_at="$(lotto_ahpc_read_pending_fields "${pending_path}" created_at)" || return $?
    username="$(lotto_ahpc_read_pending_fields "${pending_path}" username)" || return $?

    if command -v python3 >/dev/null 2>&1; then
        AHPC_INSTANCE="${instance}" \
        AHPC_PASSWORD="${password}" \
        AHPC_CREATED_AT="${created_at}" \
        AHPC_USERNAME="${username}" \
        python3 - <<'PY'
import json, os
doc = {
    "schema_version": 1,
    "instance": os.environ["AHPC_INSTANCE"],
    "username": os.environ["AHPC_USERNAME"],
    "password": os.environ["AHPC_PASSWORD"],
    "created_at": os.environ["AHPC_CREATED_AT"],
}
print(json.dumps(doc, separators=(",", ":")))
PY
    else
        php -r '
            $doc = [
                "schema_version" => 1,
                "instance" => getenv("AHPC_INSTANCE"),
                "username" => getenv("AHPC_USERNAME"),
                "password" => getenv("AHPC_PASSWORD"),
                "created_at" => getenv("AHPC_CREATED_AT"),
            ];
            echo json_encode($doc, JSON_UNESCAPED_UNICODE);
        ' \
            AHPC_INSTANCE="${instance}" \
            AHPC_PASSWORD="${password}" \
            AHPC_CREATED_AT="${created_at}" \
            AHPC_USERNAME="${username}"
    fi
}

lotto_ahpc_acknowledge() {
    local instance="$1"
    local pending_path="$2"
    local ack_path="$3"

    if [[ ! -f "${pending_path}" ]]; then
        if [[ -f "${ack_path}" ]]; then
            return 0
        fi
        return 2
    fi

    lotto_ahpc_read_pending_fields "${pending_path}" password >/dev/null || return 4

    local tmp ack_dir
    ack_dir="$(dirname "${ack_path}")"
    mkdir -p "${ack_dir}"
    tmp="$(mktemp "${ack_dir}/.admin-bootstrap.ack.XXXXXX")"
    chmod 600 "${tmp}"
    chown root:root "${tmp}" 2>/dev/null || true

    if command -v python3 >/dev/null 2>&1; then
        AHPC_INSTANCE="${instance}" \
        AHPC_ACK_AT="$(lotto_ahpc_iso8601_utc)" \
        python3 - <<'PY' >"${tmp}"
import json, os
doc = {
    "schema_version": 1,
    "instance": os.environ["AHPC_INSTANCE"],
    "acknowledged_at": os.environ["AHPC_ACK_AT"],
}
print(json.dumps(doc, separators=(",", ":")))
PY
    else
        php -r '
            $doc = [
                "schema_version" => 1,
                "instance" => getenv("AHPC_INSTANCE"),
                "acknowledged_at" => getenv("AHPC_ACK_AT"),
            ];
            echo json_encode($doc, JSON_UNESCAPED_UNICODE);
        ' \
            AHPC_INSTANCE="${instance}" \
            AHPC_ACK_AT="$(lotto_ahpc_iso8601_utc)" >"${tmp}"
    fi

    chmod 600 "${tmp}"
    chown root:root "${tmp}"
    rm -f "${pending_path}"
    mv -f "${tmp}" "${ack_path}"
    chmod 600 "${ack_path}"
    chown root:root "${ack_path}"
}

lotto_ahpc_emit_handoff_json() {
    local instance="$1"
    local pending_path="$2"
    local ack_path state

    ack_path="$(dirname "${pending_path}")/admin-bootstrap.ack"
    state="$(lotto_ahpc_pending_state "${pending_path}" "${ack_path}")"
    if command -v python3 >/dev/null 2>&1; then
        AHPC_INSTANCE="${instance}" \
        AHPC_STATE="${state}" \
        AHPC_PATH="${pending_path}" \
        python3 - <<'PY'
import json, os
doc = {
    "schema_version": 1,
    "instance": os.environ["AHPC_INSTANCE"],
    "state": os.environ["AHPC_STATE"],
    "pending_path": os.environ["AHPC_PATH"],
    "handoff_required": True,
}
print(json.dumps(doc, separators=(",", ":")))
PY
    else
        php -r '
            $doc = [
                "schema_version" => 1,
                "instance" => getenv("AHPC_INSTANCE"),
                "state" => getenv("AHPC_STATE"),
                "pending_path" => getenv("AHPC_PATH"),
                "handoff_required" => true,
            ];
            echo json_encode($doc, JSON_UNESCAPED_UNICODE);
        ' \
            AHPC_INSTANCE="${instance}" \
            AHPC_STATE="${state}" \
            AHPC_PATH="${pending_path}"
    fi
}

lotto_ahpc_verify_login_password() {
    local db_path="$1"
    local password="$2"

    AHPC_DB_PATH="${db_path}" AHPC_PASSWORD="${password}" php -r '
        $db = getenv("AHPC_DB_PATH");
        $password = getenv("AHPC_PASSWORD");
        $pdo = new PDO("sqlite:" . $db);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE username = :u LIMIT 1");
        $stmt->execute([":u" => "admin"]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { exit(1); }
        exit(password_verify($password, $row["password_hash"]) ? 0 : 1);
    '
}
