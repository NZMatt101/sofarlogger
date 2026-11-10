<?php
// One-off repair (CLI only): line up "today" where a device's own readings start after history imported from
// another logger on the same day (imports made before this was automatic). Own readings carry the raw reply.
//   sudo -u www-data php /var/www/html/inc/align-day.php <device-id>
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/common.php';
require __DIR__ . '/history.php';
$dev = $argv[1] ?? '';
if ($dev === '') { fwrite(STDERR, "usage: php align-day.php <device-id>\n"); exit(2); }
$db = db_rw();
$st = $db->prepare('SELECT MIN(ts) FROM readings WHERE device = :d AND raw IS NOT NULL');
$st->bindValue(':d', $dev);
$b = $st->execute()->fetchArray(SQLITE3_NUM)[0];
if (!$b) { echo "No readings of its own for $dev.\n"; exit(1); }
$delta = history_align_day($db, $dev, $b);
$db->close();
history_notify_poller();
printf("%s: own readings start %s; day total adjusted by +%.2f kWh\n", $dev, date('j M Y H:i', $b), $delta);
