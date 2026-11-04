<?php
// php xb.php cycle [--force]  -> rebuild the database from the backup chain
// php xb.php status           -> show the chain and the last applied LSN
//
// All project-specific names are read from environment variables (docker-compose / .env).
// Required: PROJECT, DB_CONTAINER, DB_VOLUME, BACKUP_HOST_DIR
// Optional: DB_UID, TZ, XB_IMAGE

date_default_timezone_set(getenv('TZ') ?: 'UTC');

// Required variable: exit with an error if missing (no defaults, so we never act on the wrong project)
function need(string $name): string {
    $v = getenv($name);
    if ($v === false || $v === '') {
        fwrite(STDERR, "Error: environment variable $name is not set\n");
        exit(1);
    }
    return $v;
}

$PROJECT   = need('PROJECT');
$DB_VOLUME = need('DB_VOLUME');          // e.g. test_mysql_data
$HOST_BKP  = need('BACKUP_HOST_DIR');    // e.g. /srv/backups/test (path on the host server)
$XB_IMAGE  = getenv('XB_IMAGE') ?: 'percona/percona-xtrabackup:8.0';

if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $PROJECT)) {
    fwrite(STDERR, "Error: PROJECT is invalid (lowercase letters, digits, - and _ only)\n");
    exit(1);
}
if ($HOST_BKP[0] !== '/') {
    fwrite(STDERR, "Error: BACKUP_HOST_DIR must be an absolute path\n");
    exit(1);
}

define('DB_CONTAINER', need('DB_CONTAINER'));
define('DEFAULT_UID', (int)(getenv('DB_UID') ?: 1001));

// Guards: the container, volume and backup directory must all belong to this project
if (DB_CONTAINER !== "$PROJECT-db") {
    fwrite(STDERR, "Error: DB_CONTAINER (" . DB_CONTAINER . ") does not match PROJECT ($PROJECT-db)\n");
    exit(1);
}
if ($DB_VOLUME !== "{$PROJECT}_mysql_data") {
    fwrite(STDERR, "Error: DB_VOLUME ($DB_VOLUME) does not match PROJECT ({$PROJECT}_mysql_data)\n");
    exit(1);
}
if (basename($HOST_BKP) !== $PROJECT) {
    fwrite(STDERR, "Error: BACKUP_HOST_DIR ($HOST_BKP) must end with a directory named after the project ($PROJECT)\n");
    exit(1);
}

$WORK_VOLUME = "{$PROJECT}_restore_work";

const BACKUPS = '/backups';
const WORK    = '/work/restore';
const DATADIR = '/var/lib/mysql';
const STATE   = '/state/last_restored_lsn';
const LOCK    = '/state/xb.lock';

// xtrabackup runs in a temporary container from the official image.
// This command runs on the host, so it needs the real volume name and server path.
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

// If a volume does not exist, docker run silently creates an empty one; so check before starting
function requireVolume(string $name): void {
    passthru('docker volume inspect ' . escapeshellarg($name) . ' >/dev/null 2>&1', $code);
    if ($code !== 0) {
        throw new RuntimeException("Volume $name does not exist (check the project name or DB_VOLUME)");
    }
}

// .partial_* directories are not matched by glob
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

        // Step 1: prepare (the db is still running)
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

        // Step 2: replace the datadir (db stopped)
        run('docker stop -t 120 ' . escapeshellarg(DB_CONTAINER), false);
        run('find ' . DATADIR . ' -mindepth 1 -delete');
        run(XB . ' --copy-back --target-dir=' . escapeshellarg(WORK)
          . ' --datadir=' . DATADIR);
        run("chown -R $owner " . DATADIR);

        // Step 3: start the db
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