#!/bin/bash
# Start a project with pre-flight checks. Works from any directory.
#   cd ~/test && pbi            -> checks + docker compose up -d --build
#   pbi ps | pbi logs --tail 20 | pbi down   -> any docker compose command
#   pbi ~/test [command...]     -> pass the project directory explicitly
set -euo pipefail

# If the first argument is a directory it is the project; otherwise use the current directory
if [ $# -gt 0 ] && [ -d "$1" ]; then
  DIR=$(realpath "$1"); shift
else
  DIR=$(realpath "$PWD")
fi
ENV="$DIR/.env"

[ -f "$ENV" ] || { echo "Error: $ENV does not exist"; exit 1; }
[ -f "$DIR/docker-compose.yml" ] || { echo "Error: docker-compose.yml not found in $DIR"; exit 1; }

get() { grep -E "^$1=" "$ENV" | tail -1 | cut -d= -f2- || true; }

PROJECT=$(get PROJECT)
PORT=$(get DB_PORT)
BIND=$(get DB_BIND); BIND=${BIND:-0.0.0.0}
BACKUP_ROOT=$(get BACKUP_ROOT); BACKUP_ROOT=${BACKUP_ROOT:-/srv/backups}

# Validation
[ -n "$PROJECT" ] || { echo "Error: PROJECT is empty in .env"; exit 1; }
[[ $PROJECT =~ ^[a-z0-9][a-z0-9_-]*$ ]] || { echo "Error: PROJECT may contain only lowercase letters, digits, - and _"; exit 1; }
[[ $PORT =~ ^[0-9]+$ ]] && [ "$PORT" -ge 1 ] && [ "$PORT" -le 65535 ] || { echo "Error: DB_PORT is invalid"; exit 1; }
[ -n "$(get MYSQL_ROOT_PASSWORD)" ] || { echo "Error: MYSQL_ROOT_PASSWORD is empty in .env"; exit 1; }
[[ $BACKUP_ROOT = /* ]] || { echo "Error: BACKUP_ROOT must be an absolute path (~ is not expanded in .env; e.g. /home/maleki/backups)"; exit 1; }
[ "$(basename "$DIR")" = "$PROJECT" ] || echo "Warning: directory name ($(basename "$DIR")) differs from PROJECT ($PROJECT)"

# If this project's db container is already running, the port being in use is expected
if [ "$(docker inspect -f '{{.State.Running}}' "$PROJECT-db" 2>/dev/null || true)" != "true" ]; then

  # 1) Is the port already in use on this server?
  if ss -Htln "sport = :$PORT" | grep -q .; then
    echo "Error: port $PORT is already in use on this server:"
    ss -tlnp "sport = :$PORT" 2>/dev/null || true
    exit 1
  fi

  # 2) Does another project in the same parent directory (even a stopped one) use this port in its .env?
  for other in "$(dirname "$DIR")"/*/.env; do
    [ -f "$other" ] || continue
    [ "$(realpath "$other")" = "$(realpath "$ENV")" ] && continue
    if [ "$(grep -E '^DB_PORT=' "$other" | tail -1 | cut -d= -f2-)" = "$PORT" ]; then
      echo "Error: port $PORT is also defined in $other (another project)"
      exit 1
    fi
  done
fi

# 3) Container name collision with a project from a different directory
for c in "$PROJECT-db" "$PROJECT-scheduler"; do
  existing=$(docker inspect -f '{{index .Config.Labels "com.docker.compose.project.working_dir"}}' "$c" 2>/dev/null || true)
  if [ -n "$existing" ] && [ "$(realpath "$existing")" != "$DIR" ]; then
    echo "Error: container $c belongs to another directory ($existing). Change PROJECT."
    exit 1
  fi
done

# Backup directory (created with sudo if the path is owned by root)
if [ ! -d "$BACKUP_ROOT/$PROJECT" ]; then
  mkdir -p "$BACKUP_ROOT/$PROJECT" 2>/dev/null || sudo mkdir -p "$BACKUP_ROOT/$PROJECT"
fi

echo "Project: $PROJECT | dir: $DIR | port: $BIND:$PORT | backups: $BACKUP_ROOT/$PROJECT"

cd "$DIR"
if [ $# -eq 0 ]; then
  docker compose up -d --build
else
  docker compose "$@"
fi
