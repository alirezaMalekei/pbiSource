<?php
// php xb.php cycle [--force]  -> ساخت دوباره دیتابیس از زنجیره بک‌آپ
// php xb.php status           -> نمایش زنجیره و آخرین LSN اعمال‌شده

define('DB_CONTAINER', getenv('DB_CONTAINER') ?: 'percona-db');
define('DEFAULT_UID', (int)(getenv('DB_UID') ?: 1001));
const BACKUPS = '/backups';
const WORK    = '/work/restore';
const DATADIR = '/var/lib/mysql';
const STATE   = '/state/last_restored_lsn';
const LOCK    = '/state/xb.lock';

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

// پوشه‌های .partial_* (ارسال ناقص) با glob دیده نمی‌شوند
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

// uid/gid کاربر mysql را از خود کانتینر db می‌خوانیم
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
    $lock = fopen(LOCK, 'c');
    if (!flock($lock, LOCK_EX | LOCK_NB)) { logm('Another cycle is running. Exit.'); return; }

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

        // --- مرحله ۱: prepare (دیتابیس همچنان بالاست، downtime صفر) ---
        run('rm -rf ' . escapeshellarg(WORK));
        run('mkdir -p /work');
        run('cp -a ' . escapeshellarg($chain[0]['dir']) . ' ' . escapeshellarg(WORK));

        $last = count($chain) - 1;
        run('xtrabackup --prepare ' . ($last > 0 ? '--apply-log-only ' : '')
          . '--target-dir=' . escapeshellarg(WORK));
        for ($i = 1; $i <= $last; $i++) {
            run('xtrabackup --prepare ' . ($i < $last ? '--apply-log-only ' : '')
              . '--target-dir=' . escapeshellarg(WORK)
              . ' --incremental-dir=' . escapeshellarg($chain[$i]['dir']));
        }

        // --- مرحله ۲: جایگزینی datadir (اینجا db چند دقیقه خاموش است) ---
        run('docker stop -t 120 ' . escapeshellarg(DB_CONTAINER), false);
        run('find ' . DATADIR . ' -mindepth 1 -delete');
        run('xtrabackup --copy-back --target-dir=' . escapeshellarg(WORK)
          . ' --datadir=' . DATADIR);
        run("chown -R $owner " . DATADIR);

        // --- مرحله ۳: بالا آوردن و بررسی ---
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
    $chain = bestChain(discover());
    if (!$chain) { echo "No valid chain.\n"; }
    foreach ($chain as $c) echo "{$c['type']}  {$c['dir']}  [{$c['from']} -> {$c['to']}]\n";
    echo 'Last restored LSN: ' . (file_exists(STATE) ? trim(file_get_contents(STATE)) : 'none') . "\n";
}

match ($argv[1] ?? 'status') {
    'cycle' => cycle(in_array('--force', $argv, true)),
    default => status(),
};