#!/usr/bin/env bash
set -euo pipefail

DUMP="${1:?usage: restore.sh <dump-file> [target-database]}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5433}"
DB_USERNAME="${DB_USERNAME:-$USER}"
TARGET="${2:-sme_erp_restore_check}"

# The client binary on PATH may be older than the server. pg_dump refuses a
# version mismatch, so resolve a client whose major version matches the server.
resolve_client() {
    local tool="$1"
    local server_major
    server_major="$(psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d postgres -tAc "show server_version_num" 2>/dev/null | cut -c1-2)"

    if [ -z "$server_major" ]; then
        echo "Cannot reach the server to determine its version." >&2
        exit 1
    fi

    for candidate in \
        "/opt/homebrew/opt/postgresql@${server_major}/bin/${tool}" \
        "/usr/lib/postgresql/${server_major}/bin/${tool}" \
        "$(command -v "$tool" 2>/dev/null || true)"
    do
        if [ -x "$candidate" ] && "$candidate" --version | grep -q " ${server_major}\."; then
            echo "$candidate"
            return 0
        fi
    done

    echo "No ${tool} matching server major version ${server_major} was found." >&2
    exit 1
}

dropdb --if-exists --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USERNAME" "$TARGET"
createdb --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USERNAME" "$TARGET"

PG_RESTORE="$(resolve_client pg_restore)"

"$PG_RESTORE" --no-owner --no-privileges --exit-on-error \
    --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USERNAME" \
    --dbname="$TARGET" "$DUMP"

echo "$TARGET"
