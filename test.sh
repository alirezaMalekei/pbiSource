#!/usr/bin/env bash
# تست کامل بکاپ و ریستور: full + دو incremental روی یک دیتابیس منبع موقت،
# سپس ریستور با xb.php و مقایسه‌ی نتیجه.
#
# این اسکریپت باید کنار docker-compose.yml و .env پروژه باشد (مثلاً /opt/test/xb-test.sh)
# استفاده:  ./xb-test.sh        (قبل از شروع تأیید می‌گیرد)
#           ./xb-test.sh -y     (بدون تأیید)
#
# هشدار: دیتابیس، volumeها و بکاپ‌های همین پروژه را پاک می‌کند. فقط روی پروژه‌ی تست اجرا کنید.
set -euo pipefail
cd "$(dirname "$0")"

# ---------- خواندن تنظیمات از .env ----------
ENV=.env
[ -f "$ENV" ] || { echo "خطا: فایل .env کنار این اسکریپت نیست"; exit 1; }
get() { grep -E "^$1=" "$ENV" | tail -1 | cut -d= -f2- || true; }

PROJECT=$(get PROJECT)
BACKUP_ROOT=$(get BACKUP_ROOT); BACKUP_ROOT=${BACKUP_ROOT:-/srv/backups}
XB_IMAGE=$(get XB_IMAGE);       XB_IMAGE=${XB_IMAGE:-percona/percona-xtrabackup:8.0}
PORT=$(get DB_PORT)

[[ ${PROJECT:-} =~ ^[a-z0-9][a-z0-9_-]*$ ]] || { echo "خطا: PROJECT در .env نامعتبر یا خالی است"; exit 1; }

DB="$PROJECT-db"                       # کانتینر دیتابیس اصلی (مطابق compose)
BK="$BACKUP_ROOT/$PROJECT"             # پوشه‌ی بکاپ همین پروژه (مطابق compose)
SRC="$PROJECT-xb-src"                  # کانتینر موقت منبع
SRC_VOL="${PROJECT}_xb_src_data"       # volume موقت منبع
PW=srcpass                             # رمز دیتابیس منبع (بعد از ریستور، رمز root دیتابیس اصلی هم همین می‌شود)

# ایمیج دیتابیس از خود compose گرفته می‌شود تا همیشه با آن یکی باشد
IMG=$(docker compose config | awk '/image: percona\/percona-server/ {print $2; exit}')
[ -n "$IMG" ] || { echo "خطا: ایمیج percona-server در docker-compose.yml پیدا نشد"; exit 1; }

XB="docker run --rm --user root --network container:$SRC -v $SRC_VOL:/var/lib/mysql -v $BK:/backups --entrypoint xtrabackup $XB_IMAGE"
Q="docker exec $SRC mysql -uroot -p$PW -N -e"
BKARGS="--host=127.0.0.1 --user=root --password=$PW --datadir=/var/lib/mysql"

cleanup_src() {
  docker rm -f "$SRC" >/dev/null 2>&1 || true
  docker volume rm "$SRC_VOL" >/dev/null 2>&1 || true
}
trap cleanup_src EXIT

# ---------- تأیید ----------
echo "پروژه: $PROJECT | دیتابیس: $DB | بکاپ: $BK | ایمیج: $IMG | xtrabackup: $XB_IMAGE"
echo "این‌ها پاک می‌شوند: کانتینرها و volumeهای پروژه $PROJECT و همه‌ی محتوای $BK"
if [ "${1:-}" != "-y" ]; then
  read -r -p "برای ادامه، نام پروژه را تایپ کنید ($PROJECT): " ANS
  [ "$ANS" = "$PROJECT" ] || { echo "لغو شد."; exit 1; }
fi

echo "== 0) پاکسازی =="
docker compose down -v 2>/dev/null || true
cleanup_src
sudo mkdir -p "$BK"
sudo rm -rf "${BK:?}"/*

# بررسی پورت قبل از ساخت (پروژه الان پایین است، پس هر چیزی روی پورت از برنامه‌ی دیگری است)
if [ -n "${PORT:-}" ] && ss -Htln "sport = :$PORT" | grep -q .; then
  echo "خطا: پورت $PORT روی این سرور در حال استفاده است:"
  ss -tlnp "sport = :$PORT" 2>/dev/null || true
  exit 1
fi

echo "== 1) ساخت ایمیج و دیتابیس منبع =="
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

echo "== 5) حذف دیتابیس منبع =="
cleanup_src

echo "== 6) اجرای برنامه اصلی =="
docker compose up -d
for i in $(seq 1 60); do
  [ "$(docker inspect -f '{{.State.Health.Status}}' "$DB" 2>/dev/null)" = "healthy" ] && break
  [ "$i" -eq 60 ] && { echo "خطا: دیتابیس در زمان مناسب healthy نشد"; exit 1; }
  sleep 5
done
docker compose exec -T scheduler php /app/xb.php status
docker compose exec -T scheduler php /app/xb.php cycle --force

echo "== 7) بررسی نتیجه =="
# بعد از ریستور، رمز root همان رمز دیتابیس منبع (PW) است، نه MYSQL_ROOT_PASSWORD در .env
RES=$(docker exec "$DB" mysql -uroot -p"$PW" -N -e "SELECT GROUP_CONCAT(v ORDER BY id) FROM testdb.t")
echo "نتیجه: $RES"
[ "$RES" = "a,b,c,d" ] && echo "✅ تست موفق" || { echo "❌ تست ناموفق"; exit 1; }

echo "== 8) تست skip =="
docker compose exec -T scheduler php /app/xb.php cycle