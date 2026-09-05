#!/bin/sh
# Restore one dump over the live database. Destructive on purpose, and interactive on purpose.
#
#   docker compose -f docker-compose.prod.yml run --rm backup restore.sh /backups/mova-....dump
#
# Read this before you need it, not while you need it.
set -eu

DUMP="${1:?usage: restore.sh /backups/mova-YYYYmmddTHHMMSSZ.dump}"
HOST="${POSTGRES_HOST:-postgres}"

: "${POSTGRES_USER:?POSTGRES_USER is required}"
: "${POSTGRES_DB:?POSTGRES_DB is required}"
: "${PGPASSWORD:?PGPASSWORD is required}"

[ -f "$DUMP" ] || { echo "no such dump: $DUMP" >&2; exit 1; }

echo "Verifying $DUMP..."
pg_restore --list "$DUMP" >/dev/null

cat <<WARNING

  About to restore $DUMP into $POSTGRES_DB on $HOST.
  Every table it contains will be DROPPED and recreated. Anything written since this
  dump was taken is lost.

  Stop the backend and the worker first, or they will write into a half-restored schema:
      docker compose -f docker-compose.prod.yml stop backend backend-worker

WARNING

printf 'Type the database name to confirm: '
read -r answer
[ "$answer" = "$POSTGRES_DB" ] || { echo "aborted."; exit 1; }

# --clean --if-exists so a restore onto a populated database works; single-transaction so a
# failure half-way leaves the old data rather than a broken schema.
pg_restore -h "$HOST" -U "$POSTGRES_USER" -d "$POSTGRES_DB" \
    --clean --if-exists --no-owner --no-privileges --single-transaction "$DUMP"

echo "Restored. Start the application again:"
echo "    docker compose -f docker-compose.prod.yml up -d"
