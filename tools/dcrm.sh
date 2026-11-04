#!/bin/bash
# Completely remove a project: containers, volumes, backups and the project directory.
# Works from any directory.
#   cd ~/test && pbi-rm        or        pbi-rm ~/test
# This is irreversible; you must type the project name to confirm.
set -euo pipefail

DIR=$(realpath "${1:-$PWD}")
ENV="$DIR/.env"
[ -f "$ENV" ] || { echo "Error: $ENV does not exist"; exit 1; }

get() { grep -E "^$1=" "$ENV" | tail -1 | cut -d= -f2- || true; }
PROJECT=$(get PROJECT)
BACKUP_ROOT=$(get BACKUP_ROOT); BACKUP_ROOT=${BACKUP_ROOT:-/srv/backups}
PORT=$(get DB_PORT)

[ -n "$PROJECT" ] || { echo "Error: PROJECT is empty"; exit 1; }
[[ $BACKUP_ROOT = /* ]] || { echo "Error: BACKUP_ROOT must be an absolute path"; exit 1; }

# Guard 1: the directory name must match PROJECT
[ "$(basename "$DIR")" = "$PROJECT" ] || { echo "Error: directory name ($DIR) does not match PROJECT ($PROJECT); aborting for safety"; exit 1; }

# Guard 2: never delete critical system paths or the user's own home directory
case "$DIR" in
  /|/home|/root|/opt|/srv|/usr|/etc|/var|/tmp|"$HOME") echo "Error: dangerous path ($DIR)"; exit 1;;
esac

BKP="$BACKUP_ROOT/$PROJECT"
echo "The following will be permanently deleted:"
echo "  - containers and volumes of project $PROJECT"
echo "  - project directory: $DIR"
echo "  - backups: $BKP"
read -r -p "To confirm, type the project name ($PROJECT): " ANS
[ "$ANS" = "$PROJECT" ] || { echo "Cancelled."; exit 1; }

cd "$DIR"
docker compose down -v --rmi local

cd "$(dirname "$DIR")"
sudo rm -rf "${BKP:?}"      # backups are written by root
sudo rm -rf "${DIR:?}"

echo "Project $PROJECT removed. Remove the firewall rule for port ${PORT:-?} manually."
