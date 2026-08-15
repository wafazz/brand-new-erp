#!/usr/bin/env bash
set -euo pipefail

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5433}"
DB_DATABASE="${DB_DATABASE:-sme_erp}"
DB_USERNAME="${DB_USERNAME:-$USER}"
OUT_DIR="${1:-storage/backups}"

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

mkdir -p "$OUT_DIR"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
FILE="$OUT_DIR/${DB_DATABASE}-${STAMP}.dump"

PG_DUMP="$(resolve_client pg_dump)"

"$PG_DUMP" --format=custom --no-owner --no-privileges \
    --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USERNAME" \
    --dbname="$DB_DATABASE" --file="$FILE"

SIZE="$(wc -c < "$FILE" | tr -d ' ')"

if [ "$SIZE" -lt 1024 ]; then
    echo "REFUSING: dump is only ${SIZE} bytes, which is not a backup." >&2
    exit 1
fi

echo "$FILE"
