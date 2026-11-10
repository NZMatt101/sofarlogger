<?php
// Full backup archive, shared by the admin download buttons and the automatic (Dropbox) backup.
// Requires inc/common.php.

function rm_tree($dir) {
    if (!is_dir($dir)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                                           RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

// New private temp dir, removed automatically when the script ends.
function backup_tmpdir() {
    $tmp = sys_get_temp_dir() . '/solar-backup-' . bin2hex(random_bytes(6));
    mkdir($tmp, 0700);
    register_shutdown_function('rm_tree', $tmp);
    return $tmp;
}

// Builds solar-backup-YYYYMMDD-HHMMSS.tar.gz in $tmp. $withSources adds the data directory's
// sub-folders (original source files, e.g. an old inverter's SD-card history).
// Returns ['path', 'name', 'size', 'readings', 'check'].
function backup_build($tmp, $withSources = false) {
    $stamp = date('Ymd-His');
    // Consistent snapshot of the live database (safe while the poller is writing).
    $src = new SQLite3(DB_PATH, SQLITE3_OPEN_READONLY);
    $src->busyTimeout(10000);
    $dst = new SQLite3("$tmp/solar.db");
    if (!$src->backup($dst)) throw new RuntimeException('database snapshot failed');
    $src->close();
    $check = $dst->querySingle('PRAGMA integrity_check');
    $count = $dst->querySingle('SELECT COUNT(*) FROM readings');
    $range = $dst->querySingle("SELECT date(MIN(ts),'unixepoch','localtime') || ' to ' || date(MAX(ts),'unixepoch','localtime') FROM readings");
    $dst->close();
    if ($check !== 'ok') throw new RuntimeException("database integrity check failed: $check");
    $siteName = load_config()['site']['name'] ?? 'Solar';

    // archive name => source file; built in one pass (adding files one by one rewrites the archive each time)
    $files = ['solar.db' => "$tmp/solar.db", 'config.json' => CONFIG_PATH, 'README.txt' => "$tmp/README.txt"];
    $sources = [];
    if ($withSources) {
        $dataDir = dirname(DB_PATH);
        foreach (glob("$dataDir/*", GLOB_ONLYDIR) as $dir) {
            $sources[] = basename($dir) . '/';
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && $f->isReadable()) $files[substr($f->getPathname(), strlen($dataDir) + 1)] = $f->getPathname();
            }
        }
    }
    $srcList = $sources ? implode(', ', $sources) : '(none - use the "with source files" backup to include them)';
    file_put_contents("$tmp/README.txt", <<<TXT
Solar logger backup - {$stamp} (Pacific/Auckland)
Site: {$siteName}
Readings: {$count} ({$range})
Database integrity check: {$check}

Contents
  solar.db      all readings, devices and events (SQLite; open with DB Browser for SQLite or sqlite3)
  config.json   site, equipment, tariffs, Going Green and backup settings
                NOTE: includes the inverter loggers' web logins - keep this file private.
  source files  {$srcList}
Not included: the admin page password, the Dropbox connection, OS settings, SSH keys.

Restore onto a rebuilt Pi (after installing the app, e.g. with the new-site kit):
  sudo systemctl stop solar-poller
  sudo install -o solarcap -g www-data -m 0644 solar.db /var/lib/solar-logger/solar.db
  sudo rm -f /var/lib/solar-logger/solar.db-wal /var/lib/solar-logger/solar.db-shm
  sudo install -o www-data -g www-data -m 0660 config.json /etc/solar-logger/config.json
  (source files) sudo cp -r <folder> /var/lib/solar-logger/ && sudo chown -R solarcap:solarcap /var/lib/solar-logger/<folder>
  sudo htpasswd -cB /etc/solar-logger/admin.htpasswd admin
  sudo systemctl start solar-poller
Then reconnect Dropbox in Admin -> Backup if automatic backups were on.
TXT);
    $tar = "$tmp/solar-backup-$stamp.tar";
    $p = new PharData($tar);
    $p->buildFromIterator(new ArrayIterator($files));
    $p->compress(Phar::GZ);
    unset($p);
    $gz = "$tar.gz";
    return ['path' => $gz, 'name' => basename($gz), 'size' => filesize($gz), 'readings' => $count, 'check' => $check];
}
