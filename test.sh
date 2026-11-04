#!/usr/bin/env bash
# End-to-end backup/restore test: full + two incrementals on a temporary source database,
# then restore with xb.php and compare the result.
#
# Place this script next to the project's docker-compose.yml and .env (e.g. ~/test/xb-test.sh)
# Usage:  ./xb-test.sh        (asks for confirmation first)
#         ./xb-test.sh -y     (no confirmation)
#
# WARNING: deletes this project's database, volumes and backups. Run only on a test project.
set -euo pipefail
cd "$(dirname "$0")"

# ---------- read settings from .env ----------
ENV=.env
[ -f "$ENV" ] || { echo "Error: .env is not next to this script"; exit 1; }
get() { grep -E "^$1=" "$ENV" | tail -1 | cut -d= -f2- || true; }

PROJECT=$(get PROJECT)
BACKUP_ROOT=$(get BACKUP_ROOT); BACKUP_ROOT=${BACKUP_ROOT:-/srv/backups}
XB_IMAGE=$(get XB_IMAGE);       XB_IMAGE=${XB_IMAGE:-percona/percona-xtrabackup:8.0}
PORT=$(get DB_PORT)

[[ ${PROJECT:-} =~ ^[a-z0-9][a-z0-9_-]*$ ]] || { echo "Error: PROJECT in .env is invalid or empty"; exit 1; }

DB="$PROJECT-db"                       # main database container (matches compose)
BK="$BACKUP_ROOT/$PROJECT"             # this project's backup directory (matches compose)
SRC="$PROJECT-xb-src"                  # temporary source container
SRC_VOL="${PROJECT}_xb_src_data"       # temporary source volume
PW=srcpass                             # source DB password (after restore, the main DB's root password becomes this too)

# The database image is read from compose itself so it always matches
IMG=$(docker compose config | awk '/image: percona\/percona-server/ {print $2; exit}')
[ -n "$IMG" ] || { echo "Error: percona-server image not found in docker-compose.yml"; exit 1; }

XB="docker run --rm --user root --network container:$SRC -v $SRC_VOL:/var/lib/mysql -v $BK:/backups --entrypoint xtrabackup $XB_IMAGE"
Q="docker exec $SRC mysql -uroot -p$PW -N -e"
BKARGS="--host=127.0.0.1 --user=root --password=$PW --datadir=/var/lib/mysql"

cleanup_src() {
  docker rm -f "$SRC" >/dev/null 2>&1 || true
  docker volume rm "$SRC_VOL" >/dev/null 2>&1 || true
}
trap cleanup_src EXIT

# ---------- confirmation ----------
echo "Project: $PROJECT | db: $DB | backups: $BK | image: $IMG | xtrabackup: $XB_IMAGE"
echo "These will be deleted: containers and volumes of project $PROJECT, and everything in $BK"
if [ "${1:-}" != "-y" ]; then
  read -r -p "To continue, type the project name ($PROJECT): " ANS
  [ "$ANS" = "$PROJECT" ] || { echo "Cancelled."; exit 1; }
fi

echo "== 0) Cleanup =="
docker compose down -v 2>/dev/null || true
cleanup_src
sudo mkdir -p "$BK"
sudo rm -rf "${BK:?}"/*

# Check the port before building (the project is down now, so anything on the port belongs to another program)
if [ -n "${PORT:-}" ] && ss -Htln "sport = :$PORT" | grep -q .; then
  echo "Error: port $PORT is already in use on this server:"
  ss -tlnp "sport = :$PORT" 2>/dev/null || true
  exit 1
fi

echo "== 1) Build image and source database =="
docker compose build scheduler
docker pull "$XB_IMAGE"
docker run -d --name "$SRC" -e MYSQL_ROOT_PASSWORD="$PW" -v "$SRC_VOL":/var/lib/mysql "$IMG" >/dev/null
until docker exec "$SRC" mysqladmin ping -uroot -p"$PW" --silent 2>/dev/null; do sleep 3; done
sleep 10
$Q "CREATE DATABASE testdb; CREATE TABLE testdb.t (id INT AUTO_INCREMENT PRIMARY KEY, v VARCHAR(50)); INSERT INTO testdb.t (v) VALUES ('a'),('b');"

echo "== 2) full =="
$XB --backup $BKARGS --target-dir=/backups/full_1

echo "== 3) inc1 (+c) =="
$Q "INSERT INTO testdb.t (v) VALUES ('c')"
$XB --backup $BKARGS --target-dir=/backups/inc_1 --incremental-basedir=/backups/full_1

echo "== 4) inc2 (+d) =="
$Q "INSERT INTO testdb.t (v) VALUES ('d')"
$XB --backup $BKARGS --target-dir=/backups/inc_2 --incremental-basedir=/backups/inc_1

echo "== 5) Remove the source database =="
cleanup_src

echo "== 6) Run the main application =="
docker compose up -d
for i in $(seq 1 60); do
  [ "$(docker inspect -f '{{.State.Health.Status}}' "$DB" 2>/dev/null)" = "healthy" ] && break
  [ "$i" -eq 60 ] && { echo "Error: the database did not become healthy in time"; exit 1; }
  sleep 5
done
docker compose exec -T scheduler php /app/xb.php status
docker compose exec -T scheduler php /app/xb.php cycle --force

echo "== 7) Verify the result =="
# After the restore, the root password is the source DB's password (PW), not MYSQL_ROOT_PASSWORD from .env
RES=$(docker exec "$DB" mysql -uroot -p"$PW" -N -e "SELECT GROUP_CONCAT(v ORDER BY id) FROM testdb.t")
echo "Result: $RES"
[ "$RES" = "a,b,c,d" ] && echo "✅ Test passed" || { echo "❌ Test failed"; exit 1; }

echo "== 8) Skip test =="
docker compose exec -T scheduler php /app/xb.php cycle