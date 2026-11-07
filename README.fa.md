# MySQL (Percona) در Docker با ریستور xtrabackup و اتصال از Power BI Desktop

[English](README.md) | **فارسی**

هر پروژه یک پوشه است که یک Docker Compose دارد:

- **`db`**: Percona Server نسخه‌ی 8.0.35 (دیتابیسی که Power BI به آن وصل می‌شود).
- **`scheduler`**: یک کانتینر کمکی که با `xb.php` دیتابیس را از زنجیره‌ی بکاپ xtrabackup (یک بکاپ کامل به‌علاوه‌ی incrementalها) دوباره می‌سازد.

همه‌ی نام‌ها (کانتینرها، volumeها، پوشه‌ی بکاپ و پورت) از فایل `.env` ساخته می‌شوند، پس می‌توانید چند پروژه‌ی مستقل را روی یک سرور اجرا کنید.

---

## ۱. پیش‌نیازها

- سرور لینوکس با Docker و پلاگین Docker Compose (دستور `docker compose version` باید کار کند)
- `git`، `sudo` و `openssl`
- کاربری که بتواند `docker` اجرا کند (عضو گروه `docker` یا با `sudo`)
- روی ویندوز: Power BI Desktop و MySQL Connector/NET نسخه‌ی 8.x

---

## ۲. کلون پروژه با نام دلخواه

نام پوشه همان نام پروژه می‌شود. فقط حروف کوچک انگلیسی، عدد، `-` و `_` بنویسید.

```bash
cd ~
git clone https://github.com/alirezaMalekei/pbiSource.git myproject      # پوشه‌ی ~/myproject ساخته می‌شود
cd myproject
```

نام پوشه و `PROJECT` در `.env` (مرحله‌ی بعد) باید دقیقاً یکی باشند. پروژه می‌تواند در هر مسیری باشد (home، `/srv/apps`، `/opt` و ...).

اختیاری: کلون را از مخزن قالب جدا کنید تا اشتباهی `git push` نزنید:

```bash
git remote remove origin
```

---

## ۳. تنظیم `.env`

```bash
cp .env.example .env        # یا آن را بسازید
nano .env
chmod 600 .env
```

```env
PROJECT=myproject                  # باید با نام پوشه یکی باشد
DB_PORT=3307                       # پورت روی سرور، باید یکتا باشد
DB_BIND=0.0.0.0                    # 0.0.0.0 = از بیرون در دسترس، 127.0.0.1 = فقط خود سرور
MYSQL_ROOT_PASSWORD=CHANGE_ME      # با openssl rand -hex 16 بسازید
TZ=UTC
BACKUP_ROOT=/srv/backups           # مسیر مطلق؛ بکاپ‌ها در $BACKUP_ROOT/$PROJECT قرار می‌گیرند
```

نکته‌ها:

- `BACKUP_ROOT` باید مسیر مطلق باشد. `~` داخل `.env` باز نمی‌شود.
- فایل `.env` را هرگز در Git نگذارید.
- اختیاری: برای ثابت کردن نسخه‌ی xtrabackup، در `.env` بنویسید `XB_IMAGE=percona/percona-xtrabackup:<تگ-دقیق>` و در `docker-compose.yml` زیر `scheduler.environment` این خط را اضافه کنید: `XB_IMAGE: ${XB_IMAGE:-percona/percona-xtrabackup:8.0}`. نسخه‌ی major/minor آن باید با دیتابیس (8.0.35) یکی باشد.

---

## ۴. نصب دستورهای کمکی `pbi` (یک بار روی هر سرور)

مخزن دو اسکریپت مدیریتی در پوشه‌ی `tools/` دارد:

| اسکریپت | دستور | کاربرد |
|---|---|---|
| `tools/dcup.sh` | `pbi` | اجرای پروژه بعد از چند بررسی ایمنی |
| `tools/dcrm.sh` | `pbi-rm` | حذف کامل یک پروژه |

این‌ها ابزار مشترک‌اند و جزو یک پروژه‌ی خاص نیستند. یک بار نصب می‌شوند و برای همه‌ی پروژه‌های سرور کار می‌کنند:

```bash
sudo install -m 755 tools/dcup.sh /usr/local/bin/pbi
sudo install -m 755 tools/dcrm.sh /usr/local/bin/pbi-rm
which pbi pbi-rm
```

`install` یک **کپی** می‌گذارد، پس بعد از حذف پوشه‌ی پروژه هم دستورها کار می‌کنند. برای به‌روزرسانی، `git pull` بزنید و دو دستور `install` را دوباره اجرا کنید.

> به فایل‌های داخل پوشه‌ی پروژه لینک (symlink) نسازید (`ln -s ./tools/dcup.sh /usr/local/bin/pbi`). با حذف آن پروژه، لینک خراب می‌شود.

---

## ۵. اجرای پروژه

```bash
cd ~/myproject
pbi
```

`pbi` قبل از ساخت هر چیزی این موارد را بررسی می‌کند:

- `.env` وجود دارد و `PROJECT`، `DB_PORT` و `MYSQL_ROOT_PASSWORD` معتبرند
- `BACKUP_ROOT` مسیر مطلق است
- پورت توسط برنامه‌ی دیگری روی سرور استفاده نمی‌شود
- پروژه‌ی دیگری در همان پوشه‌ی والد همین پورت را ندارد
- نام کانتینرها متعلق به پروژه‌ای در پوشه‌ی دیگر نیست

اگر همه‌چیز درست باشد، پوشه‌ی بکاپ را می‌سازد و `docker compose up -d --build` را اجرا می‌کند. اگر مشکلی باشد، خطا می‌دهد و چیزی نمی‌سازد.

هر آرگومان دیگر مستقیم به `docker compose` داده می‌شود:

```bash
pbi ps
pbi logs --tail 20 db
pbi down            # کانتینرها را متوقف و حذف می‌کند، volumeها و داده می‌مانند
```

می‌توانید پوشه‌ی پروژه را هم صریح بدهید: `pbi ~/myproject ps`.

بررسی:

```bash
docker ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
ss -tlnp | grep 3307
```

انتظار: `myproject-db` در وضعیت `healthy` باشد و پورت `0.0.0.0:3307->3306/tcp` را نشان دهد (یا اگر `DB_BIND=127.0.0.1` است، `127.0.0.1:3307`).

---

## ۶. ریستور از بکاپ با `xb.php`

بکاپ‌ها را در `$BACKUP_ROOT/$PROJECT/<نام-بکاپ>/` بگذارید. هر پوشه یک بکاپ xtrabackup است که فایل `xtrabackup_checkpoints` دارد:

```
/srv/backups/myproject/
├── full_1/       (بکاپ کامل)
├── inc_1/        (incremental روی full_1)
└── inc_2/        (incremental روی inc_1)
```

از پوشه‌ی پروژه اجرا کنید:

```bash
docker compose exec -T scheduler php /app/xb.php status          # نمایش زنجیره و آخرین LSN ریستورشده
docker compose exec -T scheduler php /app/xb.php cycle           # اگر بکاپ جدیدتری هست ریستور کن
docker compose exec -T scheduler php /app/xb.php cycle --force   # حتی اگر چیز جدیدی نیست ریستور کن
```

کار `cycle`: بلندترین زنجیره‌ی معتبر را پیدا می‌کند، یک کپی از آن را در volume کاری آماده می‌کند (در این مدت دیتابیس روشن است)، سپس دیتابیس را خاموش می‌کند، **کل datadir را پاک می‌کند**، بکاپ آماده‌شده را کپی می‌کند، مالکیت فایل‌ها را درست می‌کند و دیتابیس را دوباره روشن می‌کند.

نتایج مهم:

- **همه‌ی داده‌ها، رمز root و همه‌ی کاربرهای MySQL با محتوای بکاپ جایگزین می‌شوند.** بعد از ریستور، رمز root همان رمز سرور مبدأ است، نه `MYSQL_ROOT_PASSWORD` در `.env`. به همین دلیل ورود root روی دیتابیس ریستورشده ممکن است `Access denied` بدهد.
- **کاربر `pbi_user` (بخش ۷) بعد از ریستور از بین می‌رود، مگر اینکه در دیتابیس مبدأ هم وجود داشته باشد.** آن را روی سرور مبدأ بسازید یا بعد از هر ریستور دوباره بسازید.
- برای اجرای خودکار، آن را روی میزبان زمان‌بندی کنید، مثلاً در `crontab -e`:
  ```cron
  0 11 * * * cd ~/pbisources/myproject && docker compose exec -T scheduler php /app/xb.php cycle >> ~/pbisources/myproject-cycle.log 2>&1
  ```

---

## ۷. ساخت کاربر فقط‌خواندنی MySQL برای Power BI

محیط MySQL را داخل کانتینر باز کنید (این دستور را در **ترمینال سرور** بزنید):

```bash
docker exec -it myproject-db mysql -uroot -p
```

رمز root را وقتی خواست تایپ کنید. اگر دیتابیس از بکاپ ریستور شده، رمز root سرور مبدأ را بدهید. اگر دیتابیس تازه ساخته شده، همان `MYSQL_ROOT_PASSWORD` در `.env` است.

در prompt `mysql>` فقط دستور SQL کار می‌کند. دستورهای لینوکس مثل `sudo` یا `iptables` خطای `ERROR 1064` می‌دهند. هر دستور باید به `;` ختم شود و با کیبورد انگلیسی تایپ کنید. برای لغو دستور نیمه‌کاره بنویسید `\c`.

```sql
SHOW DATABASES;
```

دیتابیس‌های `information_schema`، `mysql`، `performance_schema` و `sys` را نادیده بگیرید. دیتابیس خودتان را انتخاب کنید (مثلاً `testdb`) و بعد:

```sql
CREATE USER 'pbi_user'@'YOUR_PUBLIC_IP' IDENTIFIED WITH mysql_native_password BY 'A_LONG_RANDOM_PASSWORD';
GRANT SELECT ON testdb.* TO 'pbi_user'@'YOUR_PUBLIC_IP';
FLUSH PRIVILEGES;
SHOW GRANTS FOR 'pbi_user'@'YOUR_PUBLIC_IP';
```

- `YOUR_PUBLIC_IP` آی‌پی عمومی کامپیوتری است که Power BI روی آن اجرا می‌شود (روی همان کامپیوتر `whatismyip.com` را ببینید). اگر آی‌پی شما مرتب عوض می‌شود، به‌جای آن `'%'` بگذارید و به فایروال بخش ۸ تکیه کنید.
- `GRANT SELECT` یعنی کاربر فقط‌خواندنی است. هرگز حساب root را به Power BI ندهید.
- `mysql_native_password` از مشکل احراز هویت در Connectorهای قدیمی MySQL جلوگیری می‌کند. Percona 8.0.35 هنوز از آن پشتیبانی می‌کند.
- تغییر رمز: `ALTER USER 'pbi_user'@'YOUR_PUBLIC_IP' IDENTIFIED BY 'رمز جدید';`
- حذف کاربر: `DROP USER 'pbi_user'@'YOUR_PUBLIC_IP';`

با `exit;` از محیط MySQL خارج شوید.

---

## ۸. باز کردن پورت فقط برای آی‌پی شما

Docker قوانین `ufw` را دور می‌زند، پس دسترسی را در زنجیره‌ی `DOCKER-USER` محدود کنید. عدد `3307` را با `DB_PORT` خودتان و آی‌پی را با آی‌پی عمومی خودتان عوض کنید:

```bash
sudo iptables -I DOCKER-USER -p tcp --dport 3307 ! -s YOUR_PUBLIC_IP -j DROP
```

معنی: هر بسته‌ی TCP به این پورت که از آی‌پی شما نیست، حذف شود.

دیدن یا حذف قانون:

```bash
sudo iptables -L DOCKER-USER -n -v --line-numbers
sudo iptables -D DOCKER-USER -p tcp --dport 3307 ! -s YOUR_PUBLIC_IP -j DROP
```

قانون با ریبوت پاک می‌شود. برای ماندگار کردن:

```bash
sudo apt install iptables-persistent
sudo netfilter-persistent save
```

اگر سرور ابری است، در فایروال یا Security Group سرویس‌دهنده هم TCP روی `DB_PORT` را برای آی‌پی خودتان باز کنید. اگر آی‌پی شما عوض شود، تا وقتی کاربر MySQL و این قانون را به‌روز نکنید دسترسی ندارید.

مقدار `DB_BIND=127.0.0.1` پورت را فقط برای خود سرور باز می‌کند، پس Power BI روی کامپیوتر دیگر نمی‌تواند وصل شود. برای دسترسی از راه دور، `DB_BIND=0.0.0.0` را همراه با قانون فایروال بالا نگه دارید، یا از VPN استفاده کنید.

---

## ۹. اتصال از Power BI Desktop

### ۹.۱ بررسی شبکه (PowerShell ویندوز)

```powershell
Test-NetConnection SERVER_IP -Port 3307
```

`TcpTestSucceeded : True` یعنی مسیر شبکه باز است. اگر `False` بود، هنوز سراغ Power BI نروید؛ bind پورت، قانون فایروال، فایروال ابری و آی‌پی خودتان را بررسی کنید (بخش ۱۱).

### ۹.۲ نصب درایور

**MySQL Connector/NET 8.x** را از صفحه‌ی دانلود رسمی MySQL نصب کنید، سپس Power BI را کاملاً ببندید و دوباره باز کنید.

### ۹.۳ اتصال

1. **Home ← Get data ← More...**
2. عبارت **MySQL** را جست‌وجو کنید، **MySQL database** را انتخاب و **Connect** را بزنید.
3. فیلدها:

   | فیلد | مقدار |
   |---|---|
   | Server | `SERVER_IP:3307` (آی‌پی سرور و `DB_PORT` شما) |
   | Database | `testdb` (نام دیتابیس شما) |

4. **OK** را بزنید. در پنجره‌ی Credentials تب **Database** را انتخاب کنید (نه Windows):
   - User name: `pbi_user`
   - Password: رمز `pbi_user` (نه رمز root)
5. **Connect** را بزنید. در Navigator جدول‌های لازم را تیک بزنید و **Load** (یا برای ویرایش در Power Query، **Transform Data**) را بزنید.

در فیلد Server آی‌پی **سرور** را بنویسید. آی‌پی خودتان فقط در کاربر MySQL و قانون فایروال استفاده می‌شود.

اگر اطلاعات اشتباه ذخیره شده بود: **File ← Options and settings ← Data source settings ← Global permissions ←** سرور را انتخاب کنید **← Clear Permissions** و دوباره وصل شوید.

---

## ۱۰. حذف کامل یک پروژه

```bash
cd ~/myproject
pbi-rm
```

فهرست چیزهایی که پاک می‌شوند (کانتینرها، volumeها، پوشه‌ی پروژه و `$BACKUP_ROOT/$PROJECT`) را نشان می‌دهد و از شما می‌خواهد نام پروژه را تایپ کنید. این کار برگشت‌ناپذیر است. قانون فایروال پورت را بعداً خودتان حذف کنید.

برای متوقف کردن پروژه با حفظ داده از `pbi down` استفاده کنید. تا وقتی نمی‌خواهید دیتابیس پاک شود، `docker compose down -v` را نزنید.

---

## ۱۱. عیب‌یابی

| علامت | علت و راه‌حل |
|---|---|
| `Test-NetConnection` مقدار `False` می‌دهد | پورت روی `127.0.0.1` بایند شده (`DB_BIND=0.0.0.0` بگذارید و `pbi up -d` بزنید)، قانون فایروال مسدود کرده (آی‌پی `! -s` همان آی‌پی عمومی فعلی شماست؟)، فایروال ابری، یا VM پشت NAT است |
| Power BI: *We couldn't authenticate with the credentials provided* | شبکه درست است. رمز اشتباه، کاربر وجود ندارد، هاست در `'pbi_user'@'...'` با آی‌پی که سرور می‌بیند فرق دارد، یا مشکل پلاگین احراز هویت. با `SELECT user, host, plugin FROM mysql.user;` بررسی کنید |
| `Access denied for user 'root'@'localhost'` | رمز root داخل دیتابیس با `.env` فرق دارد (بعد از ریستور رایج است). رمز root سرور مبدأ را بدهید |
| `ERROR 1064` در `mysql>` | دستور لینوکس در محیط SQL تایپ شده، `;` فراموش شده یا کاراکتر ناخواسته paste شده. با `\c` لغو کنید و با کیبورد انگلیسی تایپ کنید |
| `pbi` می‌گوید پورت در حال استفاده است | `DB_PORT` دیگری انتخاب کنید |
| درایور در Power BI پیدا نمی‌شود | MySQL Connector/NET 8.x را نصب و Power BI را ریستارت کنید |
| خطای SSL | گزینه‌ی Encrypt را در Power BI تغییر دهید یا `SHOW VARIABLES LIKE 'have_ssl';` را بررسی کنید |
| `No valid backup chain found` | بکاپ‌ها در `$BACKUP_ROOT/$PROJECT/<name>/xtrabackup_checkpoints` نیستند |
| `Volume ... does not exist` | `PROJECT` در `.env` بعد از ساخته شدن پروژه عوض شده |
| تغییر پورت اثری ندارد | پورت‌ها فقط وقتی کانتینر دوباره ساخته شود اعمال می‌شوند: `pbi up -d` |

هشدار تکراری `mysql_native_password is deprecated` در `docker logs` از healthcheck می‌آید و بی‌خطر است.

---

## ۱۲. تست کل فرایند بکاپ و ریستور

`test.sh` یک دیتابیس منبع موقت می‌سازد، یک بکاپ کامل و دو incremental می‌گیرد، منبع را حذف می‌کند، با `xb.php` ریستور می‌کند و داده را بررسی می‌کند (`a,b,c,d`). فقط در **پروژه‌ی تست** اجرا کنید؛ دیتابیس، volumeها و بکاپ‌های همان پروژه را پاک می‌کند.

```bash
cd ~/test
chmod +x test.sh
./test.sh             # نام پروژه را برای تأیید تایپ می‌کنید؛ با -y بدون پرسش اجرا می‌شود
```

بعد از تست، رمز root دیتابیس تست `srcpass` است. پروژه را با `pbi-rm` حذف کنید یا آن رمز را عوض کنید.

---

## ۱۳. چک‌لیست امنیتی

- برای Power BI از کاربر اختصاصی فقط‌خواندنی (`GRANT SELECT`) استفاده کنید، نه root.
- از رمزهای طولانی و تصادفی استفاده کنید و آن‌ها را در چت یا جای عمومی ننویسید.
- پورت را فقط برای آی‌پی خودتان باز کنید؛ اگر دسترسی از راه دور لازم نیست از VPN یا `DB_BIND=127.0.0.1` استفاده کنید.
- `.env` را از Git دور نگه دارید (`.gitignore`) و `chmod 600 .env` بزنید.
- کانتینر `scheduler` فایل `/var/run/docker.sock` را mount می‌کند و این یعنی کنترل در سطح root روی میزبان. هرگز از آن پورتی منتشر نکنید.
- بکاپ‌ها را بیرون از volumeهای داکر (پوشه‌های ساده‌ی میزبان) نگه دارید و یک نسخه هم خارج از سرور کپی کنید.

---

## ساختار مخزن

```
myproject/
├── docker-compose.yml   # db + scheduler، همه‌چیز از .env
├── Dockerfile           # ایمیج scheduler (PHP CLI + docker CLI)
├── xb.php               # پیدا کردن زنجیره‌ی بکاپ و ریستور
├── test.sh              # تست کامل ریستور
├── .env.example         # قالب تنظیمات
├── .gitignore           # باید شامل .env باشد
├── README.md            # مستندات انگلیسی
├── README.fa.md         # مستندات فارسی
└── tools/
    ├── dcup.sh          # با نام `pbi` نصب می‌شود
    └── dcrm.sh          # با نام `pbi-rm` نصب می‌شود
```

فایل `.env` واقعی شما در پوشه‌ی هر پروژه می‌ماند و هرگز commit نمی‌شود.
