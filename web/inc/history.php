<?php
// Importing and clearing reading history (Admin → History). Requires inc/common.php.
// The admin page writes to the database directly; the DB file is group-writable by www-data and
// SQLite's WAL mode + busy timeout let this coexist with the poller.

function db_rw() {
    $db = new SQLite3(DB_PATH, SQLITE3_OPEN_READWRITE);
    $db->busyTimeout(15000);
    return $db;
}

// Local-day bounds for an optional date range: [from, to) as unix seconds (blank = open-ended).
function history_range($fromDate, $toDate) {
    $from = $fromDate !== '' ? local_day_bounds($fromDate)[0] : 0;
    $to = $toDate !== '' ? local_day_bounds($toDate)[1] : PHP_INT_MAX;
    if ($fromDate !== '' && !DateTime::createFromFormat('!Y-m-d', $fromDate)) throw new InvalidArgumentException("Bad date: $fromDate");
    if ($toDate !== '' && !DateTime::createFromFormat('!Y-m-d', $toDate)) throw new InvalidArgumentException("Bad date: $toDate");
    if ($from >= $to) throw new InvalidArgumentException('The "from" date must be on or before the "to" date.');
    return [$from, $to];
}

// Devices on another logger, from its public site API. Returns [id => "name (since date)"].
function history_source_devices($base) {
    $base = rtrim(trim($base), '/');
    if (!preg_match('#^https?://[^\s/]+(:\d+)?$#', $base)) throw new InvalidArgumentException('Enter the other logger\'s address, e.g. http://192.168.1.20');
    $ctx = stream_context_create(['http' => ['timeout' => 15]]);
    $j = json_decode((string)@file_get_contents("$base/api.php?action=site", false, $ctx), true);
    if (!isset($j['devices'])) throw new RuntimeException("No solar logger answered at $base.");
    $out = [];
    foreach ($j['devices'] as $d) {
        $out[$d['id']] = $d['name'] . ($d['first_ts'] ? ' (since ' . date('j M Y', $d['first_ts']) . ')' : ' (no readings)');
    }
    return $out;
}

// Import readings of $sourceDevice from a CSV stream (the format of api.php?action=csv) as $targetDevice.
// $onlyBefore: skip readings at or after the target's first existing reading, so two loggers'
// data never interleave. Returns ['imported', 'skipped', 'first', 'last'].
function history_import_csv($fh, $sourceDevice, $targetDevice, $onlyBefore = true) {
    $db = db_rw();
    if (!$db->querySingle("SELECT COUNT(*) FROM devices WHERE id = '" . SQLite3::escapeString($targetDevice) . "'"))
        throw new InvalidArgumentException('Unknown device on this logger.');
    $cutoff = PHP_INT_MAX;
    if ($onlyBefore) {
        $st = $db->prepare('SELECT MIN(ts) FROM readings WHERE device = :d');
        $st->bindValue(':d', $targetDevice);
        $cutoff = $st->execute()->fetchArray(SQLITE3_NUM)[0] ?? PHP_INT_MAX;
    }
    $head = fgetcsv($fh, 0, ',', '"', '');
    $need = ['device', 'ts', 'power_w', 'energy_today_kwh', 'energy_total_kwh'];
    if (!$head || array_diff($need, $head)) throw new InvalidArgumentException('Not a solar logger CSV export (missing columns).');
    $col = array_flip($head);
    $dataKeys = array_values(array_diff($head, ['local_time', 'device', 'ts', 'power_w', 'energy_today_kwh', 'energy_total_kwh']));

    $exists = $db->prepare('SELECT 1 FROM readings WHERE device = :d AND ts = :t');
    $ins = $db->prepare('INSERT INTO readings(device, ts, clock_synced, power_w, energy_today_kwh, energy_total_kwh, data, raw)
                         VALUES (:d, :t, 1, :p, :et, :eT, :data, NULL)');
    $num = fn($v) => $v === '' ? null : (is_numeric($v) ? $v + 0 : $v);
    $imported = 0; $skipped = 0; $first = null; $last = null;
    $db->exec('BEGIN');
    try {
        while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if (count($r) < count($head)) continue;
            if ($sourceDevice !== '' && $r[$col['device']] !== $sourceDevice) continue;
            $ts = (int)$r[$col['ts']];
            $exists->bindValue(':d', $targetDevice); $exists->bindValue(':t', $ts, SQLITE3_INTEGER);
            $dup = $exists->execute()->fetchArray(); $exists->reset();
            if ($ts <= 0 || $ts >= $cutoff || $dup) { $skipped++; continue; }
            $data = [];
            foreach ($dataKeys as $k) if (($v = $num($r[$col[$k]])) !== null) $data[$k] = $v;
            $ins->bindValue(':d', $targetDevice);
            $ins->bindValue(':t', $ts, SQLITE3_INTEGER);
            $ins->bindValue(':p', $num($r[$col['power_w']]));
            $ins->bindValue(':et', $num($r[$col['energy_today_kwh']]));
            $ins->bindValue(':eT', $num($r[$col['energy_total_kwh']]));
            $ins->bindValue(':data', json_encode($data));
            $ins->execute(); $ins->reset();
            $imported++;
            $first = $first === null ? $ts : min($first, $ts);
            $last = $last === null ? $ts : max($last, $ts);
        }
        $db->exec('COMMIT');
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
    // Imported readings ending on the same day this logger started: line up that day's running total.
    $aligned = ($imported && $cutoff !== PHP_INT_MAX) ? history_align_day($db, $targetDevice, $cutoff) : 0.0;
    $db->close();
    history_notify_poller();
    return ['imported' => $imported, 'skipped' => $skipped, 'first' => $first, 'last' => $last, 'aligned' => $aligned];
}

// "Today" is a running total per local day. Where earlier readings (e.g. imported from another logger) meet
// this logger's own readings at $boundaryTs on the same day, and this logger's total restarted lower,
// add the difference to its readings for the rest of that day. Returns the kWh added (0 if none).
function history_align_day($db, $device, $boundaryTs) {
    $dayEnd = local_day_bounds(date('Y-m-d', $boundaryTs))[1];
    $dayStart = local_day_bounds(date('Y-m-d', $boundaryTs))[0];
    $q = fn($sql) => (function () use ($db, $sql, $device, $boundaryTs, $dayStart) {
        $st = $db->prepare($sql);
        $st->bindValue(':d', $device); $st->bindValue(':b', $boundaryTs, SQLITE3_INTEGER); $st->bindValue(':s', $dayStart, SQLITE3_INTEGER);
        $r = $st->execute()->fetchArray(SQLITE3_NUM);
        return $r ? $r[0] : null;
    })();
    $before = $q('SELECT energy_today_kwh FROM readings WHERE device = :d AND ts >= :s AND ts < :b ORDER BY ts DESC LIMIT 1');
    $at = $q('SELECT energy_today_kwh FROM readings WHERE device = :d AND ts >= :b ORDER BY ts LIMIT 1');
    if ($before === null || $at === null || $at >= $before - 0.01) return 0.0;
    $delta = round($before - $at, 3);
    $st = $db->prepare('UPDATE readings SET energy_today_kwh = ROUND(energy_today_kwh + :x, 3) WHERE device = :d AND ts >= :b AND ts < :e');
    $st->bindValue(':x', $delta); $st->bindValue(':d', $device);
    $st->bindValue(':b', $boundaryTs, SQLITE3_INTEGER); $st->bindValue(':e', $dayEnd, SQLITE3_INTEGER);
    $st->execute();
    return $delta;
}

// The poller keeps a running "today" total in memory; touching the config makes it reload and
// re-read that total from the database (see poller.reload_config).
function history_notify_poller() {
    @touch(CONFIG_PATH);
}

// Fetch a device's readings for a date range from another logger and import them.
function history_import_url($base, $sourceDevice, $targetDevice, $fromDate, $toDate, $onlyBefore = true) {
    $base = rtrim(trim($base), '/');
    history_range($fromDate, $toDate);  // validates
    $q = http_build_query(['action' => 'csv', 'device' => $sourceDevice,
                           'from' => $fromDate ?: '2000-01-01', 'to' => $toDate ?: date('Y-m-d')]);
    $fh = @fopen("$base/api.php?$q", 'r', false, stream_context_create(['http' => ['timeout' => 120]]));
    if (!$fh) throw new RuntimeException("Could not download history from $base.");
    try { return history_import_csv($fh, $sourceDevice, $targetDevice, $onlyBefore); } finally { fclose($fh); }
}

// Permanently delete readings (and events) for one device or all ('*') within a local date range.
function history_delete($device, $fromDate, $toDate) {
    [$from, $to] = history_range($fromDate, $toDate);
    $db = db_rw();
    $out = [];
    foreach (['readings', 'events'] as $table) {
        $sql = "DELETE FROM $table WHERE ts >= :f AND ts < :t" . ($device === '*' ? '' : ' AND device = :d');
        $st = $db->prepare($sql);
        $st->bindValue(':f', $from, SQLITE3_INTEGER);
        $st->bindValue(':t', min($to, PHP_INT_MAX), SQLITE3_INTEGER);
        if ($device !== '*') $st->bindValue(':d', $device);
        $st->execute();
        $out[$table] = $db->changes();
    }
    $db->close();
    history_notify_poller();
    return $out;
}
