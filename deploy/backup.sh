#!/usr/bin/env bash
# Nightly database dump.
#
# Uberspace takes its own snapshots, but those are the provider's safety net, not
# ours: they protect against hardware loss, not against a migration that drops the
# wrong column at 02:00. This is the copy we control.
set -euo pipefail

BACKUP_DIR="$HOME/backups/extdir"
KEEP_DAYS=14
DB="${EXTDIR_DB:-${USER}_extdir}"

mkdir -p "$BACKUP_DIR"
STAMP=$(date +%Y-%m-%d)
TARGET="$BACKUP_DIR/extdir-$STAMP.sql.gz"

mysqldump --single-transaction --quick --default-character-set=utf8mb4 "$DB" | gzip -9 > "$TARGET"

# A dump that silently produced nothing is worse than no dump, because it looks
# like a backup exists.
if [ ! -s "$TARGET" ]; then
    echo "ERROR: backup is empty, removing $TARGET" >&2
    rm -f "$TARGET"
    exit 1
fi

find "$BACKUP_DIR" -name 'extdir-*.sql.gz' -mtime "+$KEEP_DAYS" -delete
echo "$(date -Is) backup ok: $(du -h "$TARGET" | cut -f1) $TARGET"
