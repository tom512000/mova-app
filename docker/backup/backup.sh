#!/bin/sh
# Periodic pg_dump, verified before it is trusted and before anything older is deleted.
#
# The re-rating history in this database exists nowhere else: a Letterboxd export only ever
# carries the current value of a rating, so an old note that was overwritten is gone from
# every future export. That makes this the one job in the stack whose failure is not
# recoverable by re-running something, which is why it is noisy and why it verifies.
set -eu

: "${POSTGRES_USER:?POSTGRES_USER is required}"
: "${POSTGRES_DB:?POSTGRES_DB is required}"
: "${PGPASSWORD:?PGPASSWORD is required}"

HOST="${POSTGRES_HOST:-postgres}"
DIR="${BACKUP_DIR:-/backups}"
KEEP="${BACKUP_KEEP:-14}"
INTERVAL="${BACKUP_INTERVAL_SECONDS:-86400}"

log() { echo "[backup] $(date -u '+%Y-%m-%dT%H:%M:%SZ') $*"; }

mkdir -p "$DIR" 2>/dev/null || true

# Checked once, at startup, and fatal. A backup job that cannot write is worth a container
# that visibly refuses to start; the alternative is one that looks healthy in `docker ps`
# and has been logging the same failure every night since the deploy.
if ! touch "$DIR/.writable" 2>/dev/null; then
    cat >&2 <<ERROR
[backup] FATAL: cannot write to $DIR

  This container runs as uid 70 (postgres) on purpose — the dumps hold every rating and
  review in the library. A bind-mounted host directory keeps its own ownership, so grant it:

      sudo mkdir -p <your BACKUP_PATH> && sudo chown 70:70 <your BACKUP_PATH>

ERROR
    exit 1
fi
rm -f "$DIR/.writable"

run_once() {
    stamp=$(date -u '+%Y%m%dT%H%M%SZ')
    target="$DIR/mova-$stamp.dump"

    log "dumping $POSTGRES_DB from $HOST"
    # Custom format: compressed, and pg_restore can read a single table out of it.
    # Written to .part first so an interrupted dump can never be mistaken for a good one.
    if ! pg_dump -h "$HOST" -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc -f "$target.part"; then
        log "FAILED: pg_dump returned non-zero. Nothing was pruned."
        rm -f "$target.part"
        return 1
    fi

    # A dump nobody has read is not a backup. pg_restore --list parses the whole archive
    # header and table of contents, so a truncated or corrupt file fails here rather than on
    # the night it is needed.
    if ! pg_restore --list "$target.part" >/dev/null 2>&1; then
        log "FAILED: the dump is unreadable. Kept as $target.part for inspection, nothing pruned."
        return 1
    fi

    mv "$target.part" "$target"
    log "wrote $target ($(du -h "$target" | cut -f1))"

    # Pruning happens only after a verified dump, never before. A run that failed must not
    # be able to take the last good copy with it.
    total=$(find "$DIR" -maxdepth 1 -name 'mova-*.dump' | wc -l)
    if [ "$total" -gt "$KEEP" ]; then
        find "$DIR" -maxdepth 1 -name 'mova-*.dump' | sort | head -n "$((total - KEEP))" | while read -r old; do
            log "pruning $old"
            rm -f "$old"
        done
    fi

    log "done, $(find "$DIR" -maxdepth 1 -name 'mova-*.dump' | wc -l) backup(s) held"
}

log "starting: every ${INTERVAL}s, keeping $KEEP, into $DIR"

while true; do
    # A failed run must not kill the loop — tomorrow's attempt may well succeed, and a
    # container that exited is a backup job nobody notices has stopped.
    run_once || log "run failed; will try again in ${INTERVAL}s"
    sleep "$INTERVAL"
done
