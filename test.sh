#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"

SRC=xb-src
BK=/srv/backups
PW=srcpass
IMG=percona/percona-server:8.0
XB="docker run --rm --network container:$SRC -v xb_src_data:/var/lib/mysql -v $BK:/backups pbisource-scheduler"
Q="docker exec $SRC mysql -uroot -p$PW -N -e"
BKARGS="--host=127.0.0.1 --user=root --password=$PW --datadir=/var/lib/mysql"

echo "== 0) پاکسازی =="
docker rm -f $SRC 2>/dev/null || true
docker volume rm xb_src_data 2>/dev/null || true
sudo rm -rf $BK/*

echo "== 1) ساخت ایمیج و دیتابیس منبع =="
docker compose build scheduler
docker run -d --name $SRC -e MYSQL_ROOT_PASSWORD=$PW -v xb_src_data:/var/lib/mysql $IMG >/dev/null
until docker exec $SRC mysqladmin ping -uroot -p$PW --silent 2>/dev/null; do sleep 3; done
sleep 10
$Q "CREATE DATABASE testdb; CREATE TABLE testdb.t (id INT AUTO_INCREMENT PRIMARY KEY, v VARCHAR(50)); INSERT INTO testdb.t (v) VALUES ('a'),('b');"

echo "== 2) full =="
$XB xtrabackup --backup $BKARGS --target-dir=/backups/full_1

echo "== 3) inc1 (+c) =="
$Q "INSERT INTO testdb.t (v) VALUES ('c')"
$XB xtrabackup --backup $BKARGS --target-dir=/backups/inc_1 --incremental-basedir=/backups/full_1

echo "== 4) inc2 (+d) =="
$Q "INSERT INTO testdb.t (v) VALUES ('d')"
$XB xtrabackup --backup $BKARGS --target-dir=/backups/inc_2 --incremental-basedir=/backups/inc_1

echo "== 5) حذف دیتابیس منبع =="
docker rm -f $SRC >/dev/null
docker volume rm xb_src_data >/dev/null

echo "== 6) اجرای برنامه اصلی =="
docker compose up -d
until [ "$(docker inspect -f '{{.State.Health.Status}}' pbisource-db)" = "healthy" ]; do sleep 5; done
docker compose exec -T scheduler php /app/xb.php status
docker compose exec -T scheduler php /app/xb.php cycle --force

echo "== 7) بررسی نتیجه =="
RES=$(docker exec pbisource-db mysql -uroot -p$PW -N -e "SELECT GROUP_CONCAT(v ORDER BY id) FROM testdb.t")
echo "نتیجه: $RES"
[ "$RES" = "a,b,c,d" ] && echo "✅ تست موفق" || { echo "❌ تست ناموفق"; exit 1; }

echo "== 8) تست skip =="
docker compose exec -T scheduler php /app/xb.php cycle