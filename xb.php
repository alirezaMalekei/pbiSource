<?php
// php xb.php cycle [--force]  -> ساخت دوباره دیتابیس از زنجیره بک‌آپ
// php xb.php status           -> نمایش زنجیره و آخرین LSN اعمال‌شده
//
// همه‌ی نام‌های وابسته به پروژه از متغیرهای محیطی (docker-compose / .env) خوانده می‌شود.
// متغیرهای ضروری: PROJECT, DB_CONTAINER, DB_VOLUME, BACKUP_HOST_DIR
// متغیرهای اختیاری: DB_UID, TZ, XB_IMAGE

date_default_timezone_set(getenv('TZ') ?: 'UTC');

// متغیر ضروری: اگر نبود، با خطا متوقف شو (هیچ مقدار پیش‌فرضی نداریم تا روی پروژه‌ی اشتباه عمل نشود)
function need(string $name): string {
    $v = getenv($name);
    if ($v === false || $v === '') {
        fwrite(STDERR, "خطا: متغیر محیطی $name تنظیم نشده است\n");
        exit(1);
    }
    return $v;
}

$PROJECT   = need('PROJECT');
$DB_VOLUME = need('DB_VOLUME');          // مثلاً test_mysql_data
$HOST_BKP  = need('BACKUP_HOST_DIR');    // مثلاً /srv/backups/test (مسیر روی سرور میزبان)
$XB_IMAGE  = getenv('XB_IMAGE') ?: 'percona/percona-xtrabackup:8.0';

if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $PROJECT)) {
    fwrite(STDERR, "خطا: PROJECT نامعتبر است (فقط حروف کوچک، عدد، - و _)\n");
    exit(1);
}
if ($HOST_BKP[0] !== '/') {
    fwrite(STDERR, "خطا: BACKUP_HOST_DIR باید مسیر مطلق باشد\n");
    exit(1);
}

define('DB_CONTAINER', need('DB_CONTAINER'));
define('DEFAULT_UID', (int)(getenv('DB_UID') ?: 1001));

// محافظ: نام کانتینر، volume و پوشه‌ی بکاپ باید همگی متعلق به همین پروژه باشند
if (DB_CONTAINER !== "$PROJECT-db") {
    fwrite(STDERR, "خطا: DB_CONTAINER (" . DB_CONTAINER . ") با PROJECT ($PROJECT-db) هماهنگ نیست\n");
    exit(1);
}
if ($DB_VOLUME !== "{$PROJECT}_mysql_data") {
    fwrite(STDERR, "خطا: DB_VOLUME ($DB_VOLUME) با PROJECT ({$PROJECT}_mysql_data) هماهنگ نیست\n");
    exit(1);
}
if (basename($HOST_BKP) !== $PROJECT) {
    fwrite(STDERR, "خطا: BACKUP_HOST_DIR ($HOST_BKP) باید به پوشه‌ای با نام پروژه ($PROJECT) ختم شود\n");
    exit(1);
}

$WORK_VOLUME = "{$PROJECT}_restore_work";

const BACKUPS = '/backups';
const WORK    = '/work/restore';
const DATADIR = '/var/lib/mysql';
const STATE   = '/state/last_restored_lsn';
const LOCK    = '/state/xb.lock';

// xtrabackup در یک کانتینر موقت از ایمیج رسمی اجرا می‌شود
// این دستور روی میزبان اجرا می‌شود، پس نام volume و مسیر واقعی سرور لازم است
define('XB', 'docker run --rm --user root --entrypoint xtrabackup '
    . '-v ' . escapeshellarg($DB_VOLUME) . ':/var/lib/mysql '
    . '-v ' . escapeshellarg($WORK_VOLUME) . ':/work '
    . '-v ' . escapeshellarg($HOST_BKP) . ':/backups '
    . escapeshellarg($XB_IMAGE));

function logm(string $m): void { echo '[' . date('Y-m-d H:i:s') . "] $m\n"; }

function run(string $cmd, bool $must = true): int {
    logm("$ $cmd");
    passthru($cmd . ' 2>&1', $code);
    if ($must && $code !== 0) {
        throw new RuntimeException("Command failed (exit $code): $cmd");
    }
    return $code;
}

function out(string $cmd): string {
    return trim((string)shell_exec($cmd . ' 2>/dev/null'));
}

// اگر volume وجود نداشته باشد، docker run بی‌صدا یک volume خالی می‌سازد؛ پس قبل از شروع چک می‌کنیم
function requireVolume(string $name): void {
    passthru('docker volume inspect ' . escapeshellarg($name) . ' >/dev/null 2>&1', $code);
    if ($code !== 0) {
        throw new RuntimeException("Volume $name وجود ندارد (نام پروژه یا DB_VOLUME را بررسی کنید)");
    }
}

// پوشه‌های .partial_* با glob دیده نمی‌شوند
function discover(): array {
    $items = [];
    foreach (glob(BACKUPS . '/*/xtrabackup_checkpoints') as $f) {
        $c = parse_ini_file($f, false, INI_SCANNER_RAW);
        if (!$c || !isset($c['backup_type'], $c['from_lsn'], $c['to_lsn'])) continue;
        $items[] = [
            'dir'  => dirname($f),
            'type' => $c['backup_type'] === 'full-backuped' ? 'full' : 'inc',
            'from' => (int)$c['from_lsn'],
            'to'   => (int)$c['to_lsn'],
        ];
    }
    return $items;
}

function bestChain(array $items): array {
    $best = [];
    foreach ($items as $full) {
        if ($full['type'] !== 'full') continue;
        $chain = [$full];
        $cur = $full['to'];
        while (true) {
            $next = null;
            foreach ($items as $it) {
                if ($it['type'] === 'inc' && $it['from'] === $cur) { $next = $it; break; }
            }
            if (!$next) break;
            $chain[] = $next;
            $cur = $next['to'];
        }
        if (!$best || end($chain)['to'] > end($best)['to']) $best = $chain;
    }
    return $best;
}

function dbOwner(): string {
    $id = out('docker exec ' . escapeshellarg(DB_CONTAINER) . ' id mysql');
    if (preg_match('/uid=(\d+).*gid=(\d+)/', $id, $m)) return "$m[1]:$m[2]";
    return DEFAULT_UID . ':' . DEFAULT_UID;
}

function waitHealthy(int $timeout = 300): void {
    $start = time();
    while (time() - $start < $timeout) {
        $s = out("docker inspect -f '{{.State.Health.Status}}' " . escapeshellarg(DB_CONTAINER));
        if ($s === 'healthy') { logm('DB is healthy.'); return; }
        sleep(5);
    }
    throw new RuntimeException('DB did not become healthy in time');
}

function cycle(bool $force): void {
    global $DB_VOLUME, $WORK_VOLUME, $PROJECT, $HOST_BKP, $XB_IMAGE;

    @mkdir('/state', 0755, true);
    $lock = fopen(LOCK, 'c');
    if (!flock($lock, LOCK_EX | LOCK_NB)) { logm('Another cycle is running. Exit.'); return; }

    logm("Project: $PROJECT | db: " . DB_CONTAINER . " | volume: $DB_VOLUME | backups: $HOST_BKP | xtrabackup: $XB_IMAGE");

    try {
        requireVolume($DB_VOLUME);
        requireVolume($WORK_VOLUME);
    } catch (Throwable $e) {
        logm('ERROR: ' . $e->getMessage());
        exit(1);
    }

    $chain = bestChain(discover());
    if (!$chain) { logm('No valid backup chain found in ' . BACKUPS); exit(2); }

    $target = end($chain)['to'];
    $done   = file_exists(STATE) ? (int)trim(file_get_contents(STATE)) : -1;
    if (!$force && $target === $done) {
        logm("No new backup since last restore (LSN $target). Skipping.");
        return;
    }

    logm('Chain:');
    foreach ($chain as $c) logm("  {$c['type']}  {$c['dir']}  [{$c['from']} -> {$c['to']}]");

    try {
        $owner = dbOwner();

        // مرحله ۱: prepare (db هنوز روشن است)
        run('rm -rf ' . escapeshellarg(WORK));
        run('mkdir -p /work');
        run('cp -a ' . escapeshellarg($chain[0]['dir']) . ' ' . escapeshellarg(WORK));

        $last = count($chain) - 1;
        run(XB . ' --prepare ' . ($last > 0 ? '--apply-log-only ' : '')
          . '--target-dir=' . escapeshellarg(WORK));
        for ($i = 1; $i <= $last; $i++) {
            run(XB . ' --prepare ' . ($i < $last ? '--apply-log-only ' : '')
              . '--target-dir=' . escapeshellarg(WORK)
              . ' --incremental-dir=' . escapeshellarg($chain[$i]['dir']));
        }

        // مرحله ۲: جایگزینی datadir (db خاموش)
        run('docker stop -t 120 ' . escapeshellarg(DB_CONTAINER), false);
        run('find ' . DATADIR . ' -mindepth 1 -delete');
        run(XB . ' --copy-back --target-dir=' . escapeshellarg(WORK)
          . ' --datadir=' . DATADIR);
        run("chown -R $owner " . DATADIR);

        // مرحله ۳: روشن کردن
        run('docker start ' . escapeshellarg(DB_CONTAINER));
        waitHealthy();

        file_put_contents(STATE, (string)$target);
        run('rm -rf ' . escapeshellarg(WORK));
        logm("Restore completed. LSN = $target");
    } catch (Throwable $e) {
        logm('ERROR: ' . $e->getMessage());
        logm('DB may be stopped; next scheduled cycle will retry automatically.');
        exit(1);
    }
}

function status(): void {
    global $PROJECT;
    echo "Project: $PROJECT\n";
    $chain = bestChain(discover());
    if (!$chain) echo "No valid chain.\n";
    foreach ($chain as $c) echo "{$c['type']}  {$c['dir']}  [{$c['from']} -> {$c['to']}]\n";
    echo 'Last restored LSN: ' . (file_exists(STATE) ? trim(file_get_contents(STATE)) : 'none') . "\n";
}

match ($argv[1] ?? 'status') {
    'cycle' => cycle(in_array('--force', $argv, true)),
    default => status(),
};