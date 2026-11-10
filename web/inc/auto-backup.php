<?php
// Scheduled backup (solar-backup.timer, runs as solarcap). Uploads a full backup to Dropbox when due.
//   php /var/www/html/inc/auto-backup.php [--force]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/common.php';
require __DIR__ . '/backup.php';
require __DIR__ . '/dropbox.php';

$msg = run_auto_backup(in_array('--force', $argv, true));
echo "solar-backup: $msg\n";
exit(str_starts_with($msg, 'failed') ? 1 : 0);
