#!/usr/bin/env bash
# Daily backup of Hellom: MySQL dump + private uploads (backend/storage/app: product files,
# KYC photos, withdrawal proofs) + public uploads. Run from cron (deploy/crontab.example).
#
#   bash deploy/backup.sh
#
# Options (env vars):
#   BACKUP_DIR=/www/backup/hellom   where archives go (outside the web root!)
#   BACKUP_KEEP_DAYS=14             delete local archives older than this
#   BACKUP_RCLONE_REMOTE=r2:hellom  optional off-site copy with rclone (recommended)
#
# Reads DB_* from backend/.env at run time; the password is passed through a temporary
# option file (mode 600), never on the command line. Archives are mode 600: they hold
# customer data and KYC documents.
set -euo pipefail
umask 077

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKUP_DIR="${BACKUP_DIR:-/www/backup/hellom}"
KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"
STAMP="$(date +%Y%m%d-%H%M%S)"
ENV_FILE="$ROOT/backend/.env"

[ -f "$ENV_FILE" ] || { echo "backend/.env not found" >&2; exit 1; }

env_value() {
  # Last KEY=value line, without surrounding quotes.
  grep -E "^$1=" "$ENV_FILE" | tail -n 1 | cut -d '=' -f 2- | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/"
}

DB_HOST="$(env_value DB_HOST)"; DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="$(env_value DB_PORT)"; DB_PORT="${DB_PORT:-3306}"
DB_DATABASE="$(env_value DB_DATABASE)"
DB_USERNAME="$(env_value DB_USERNAME)"
DB_PASSWORD="$(env_value DB_PASSWORD)"
[ -n "$DB_DATABASE" ] || { echo "DB_DATABASE is empty in backend/.env" >&2; exit 1; }

mkdir -p "$BACKUP_DIR"
OPTS="$(mktemp)"
trap 'rm -f "$OPTS"' EXIT
printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n' "$DB_HOST" "$DB_PORT" "$DB_USERNAME" "$DB_PASSWORD" > "$OPTS"

DB_FILE="$BACKUP_DIR/db-$DB_DATABASE-$STAMP.sql.gz"
FILES_FILE="$BACKUP_DIR/files-$STAMP.tar.gz"

echo "==> database → $DB_FILE"
# --single-transaction: consistent InnoDB snapshot without locking the shop.
mysqldump --defaults-extra-file="$OPTS" --single-transaction --quick --routines --triggers \
  --no-tablespaces --default-character-set=utf8mb4 "$DB_DATABASE" | gzip -6 > "$DB_FILE"
gzip -t "$DB_FILE"

echo "==> files → $FILES_FILE"
# storage/app = uploads (public + private); framework caches, logs and sessions are skipped.
tar -czf "$FILES_FILE" -C "$ROOT/backend/storage/app" .

echo "==> prune local archives older than $KEEP_DAYS days"
find "$BACKUP_DIR" -maxdepth 1 -type f \( -name 'db-*.sql.gz' -o -name 'files-*.tar.gz' \) -mtime +"$KEEP_DAYS" -delete

if [ -n "${BACKUP_RCLONE_REMOTE:-}" ]; then
  echo "==> off-site copy → $BACKUP_RCLONE_REMOTE"
  rclone copy "$DB_FILE" "$BACKUP_RCLONE_REMOTE/"
  rclone copy "$FILES_FILE" "$BACKUP_RCLONE_REMOTE/"
fi

echo "==> done: $(du -h "$DB_FILE" | cut -f1) database, $(du -h "$FILES_FILE" | cut -f1) files"
