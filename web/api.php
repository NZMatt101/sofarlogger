<?php
// Read-only JSON/CSV API over the solar logger SQLite database.
//   api.php?action=site                                   site totals + all devices with status
//   api.php?action=device&id=ID                           one device: info, fields, latest values
//   api.php?action=day&date=YYYY-MM-DD[&device=ID]        readings for a local day
//   api.php?action=daily&days=30[&device=ID]              per-day energy
//   api.php?action=monthly[&device=ID]                    per-month energy, all history
//   api.php?action=events&device=ID[&limit=100]           fault/event log
//   api.php?action=csv&from=YYYY-MM-DD&to=YYYY-MM-DD[&device=ID]
//   api.php?action=savings                                value of generation using configured tariffs
//   api.php?action=green                                  site power now/today/lifetime as CO2, trees, km, home-days

require __DIR__ . '/inc/common.php';
const STALE_SECONDS = 600;
const DAYLIGHT_MARGIN = 1800;   // devices may still report up to 30 min outside sunrise/sunset
// Batteries store energy rather than generate it: keep them out of site generation charts and savings.
const NOT_BATTERY = "device NOT IN (SELECT id FROM devices WHERE type = 'battery')";

function site_info() {
    $cfg = load_config();
    $site = $cfg['site'] ?? [];
    $now = time();
    $info = ['name' => $site['name'] ?? 'Solar', 'daylight' => true, 'sunrise' => null, 'sunset' => null];
    if (isset($site['lat'], $site['lon'])) {
        $sun = date_sun_info($now, $site['lat'], $site['lon']);
        $info['sunrise'] = $sun['sunrise'];
        $info['sunset'] = $sun['sunset'];
        $info['daylight'] = $now >= $sun['sunrise'] - DAYLIGHT_MARGIN && $now <= $sun['sunset'] + DAYLIGHT_MARGIN;
    }
    return $info;
}

// retired | online | night | no_data | unreachable | error | never
function device_state($status, $daylight, $now) {
    if (!empty($status['retired'])) return 'retired';
    if (!$status || !$status['last_poll']) return 'never';
    $fresh = $status['last_ok'] && ($now - $status['last_ok']) <= STALE_SECONDS;
    if ($status['status'] === 'ok' && $fresh) return 'online';
    // Solar inverters sleep at night; a battery inverter that stops answering is a real problem at any hour.
    if (!$daylight && ($status['type'] ?? '') !== 'battery' && in_array($status['status'], ['ok', 'no_data', 'unreachable'])) return 'night';
    return $status['status'] === 'ok' ? 'no_data' : $status['status'];
}

function devices_with_latest($db, $site, $id = null) {
    $now = time();
    $sql = 'SELECT d.*, s.last_poll, s.last_ok, s.status, s.detail FROM devices d
            LEFT JOIN device_status s ON s.device = d.id WHERE ' . ($id === null ? 'd.enabled = 1' : 'd.id = :id') .
           ' ORDER BY d.sort, d.name';
    $stmt = $db->prepare($sql);
    if ($id !== null) $stmt->bindValue(':id', $id);
    $devices = rows($stmt);
    $latest = $db->prepare('SELECT ts, power_w, energy_today_kwh, energy_total_kwh, data FROM readings
                            WHERE device = :d ORDER BY ts DESC LIMIT 1');
    $first = $db->prepare('SELECT MIN(ts) FROM readings WHERE device = :d');
    // The battery's history is saved every few minutes, but its newest values are kept from every poll.
    $live = $db->prepare('SELECT ts, live FROM device_snapshot WHERE device = :d');
    $cfg = load_config();
    $devCfg = array_column($cfg['devices'] ?? [], null, 'id');
    foreach ($devices as &$d) {
        // Battery inverters: which BMS is their battery (null = none) and whether their CT is fitted.
        if ($d['driver'] === 'me3000') {
            $c = $devCfg[$d['id']] ?? [];
            $d['bms'] = $c ? linked_bms_id($cfg, $c) : null;
            $d['has_ct'] = (bool)($c['has_ct'] ?? true);
        }
        $d['fields'] = json_decode($d['fields'], true);
        $d['retired'] = (bool)$d['retired'];
        $latest->bindValue(':d', $d['id']);
        $r = rows($latest)[0] ?? null;
        $latest->reset();
        $first->bindValue(':d', $d['id']);
        $d['first_ts'] = $first->execute()->fetchArray(SQLITE3_NUM)[0];
        $first->reset();
        if ($r) $r['data'] = json_decode($r['data'], true);
        if ($d['type'] === 'battery') {
            $live->bindValue(':d', $d['id']);
            $snap = rows($live)[0] ?? null;
            $live->reset();
            if ($snap && (!$r || $snap['ts'] > $r['ts'])) {
                $r = ['ts' => $snap['ts'], 'power_w' => null, 'energy_today_kwh' => null,
                      'energy_total_kwh' => null, 'data' => json_decode($snap['live'], true)];
            }
        }
        $d['latest'] = $r;
        $d['state'] = device_state($d, $site['daylight'], $now);
        // Today's energy only counts if the latest reading is from today.
        $d['energy_today_kwh'] = ($r && date('Y-m-d', $r['ts']) === date('Y-m-d')) ? $r['energy_today_kwh'] : 0;
        $d['power_w'] = $d['state'] === 'online' && $r ? $r['power_w'] : null;
    }
    return $devices;
}

$action = $_GET['action'] ?? 'site';

// Browser-side error reports (from common.js) go to Apache's error log.
if ($action === 'clientlog') {
    $r = json_decode(substr((string)file_get_contents('php://input'), 0, 2000), true) ?: [];
    $clean = fn($s) => preg_replace('/[^\x20-\x7E]/', '?', substr((string)$s, 0, 500));
    error_log(sprintf('solar-client %s [%s] %s | %s | %s', $_SERVER['REMOTE_ADDR'] ?? '?', $clean($r['kind'] ?? ''),
                      $clean($r['msg'] ?? ''), $clean($r['page'] ?? ''), $clean($r['ua'] ?? '')));
    http_response_code(204);
    exit;
}

try {
    $db = db();
} catch (Exception $e) {
    http_response_code(503);
    json_out(['error' => 'database unavailable']);
    exit;
}

switch ($action) {
case 'site':
    $site = site_info();
    $devices = devices_with_latest($db, $site);
    $totals = ['power_w' => 0, 'energy_today_kwh' => 0, 'energy_total_kwh' => 0, 'rated_w' => 0,
               'online' => 0, 'count' => 0, 'retired' => 0];
    foreach ($devices as $d) {
        // Retired equipment counts toward site lifetime energy only.
        $totals['energy_total_kwh'] += $d['latest']['energy_total_kwh'] ?? 0;
        if ($d['retired']) { $totals['retired']++; continue; }
        $totals['count']++;
        if ($d['state'] === 'online') $totals['online']++;
        if ($d['type'] === 'battery') continue;  // not generation
        $totals['power_w'] += $d['power_w'] ?? 0;
        $totals['energy_today_kwh'] += $d['energy_today_kwh'] ?? 0;
        $totals['rated_w'] += $d['rated_w'] ?? 0;
    }
    json_out(['now' => time(), 'site' => $site, 'totals' => $totals, 'devices' => $devices]);
    break;

case 'device':
    $site = site_info();
    $devices = devices_with_latest($db, $site, $_GET['id'] ?? '');
    if (!$devices) { http_response_code(404); json_out(['error' => 'unknown device']); break; }
    json_out(['now' => time(), 'site' => $site, 'device' => $devices[0]]);
    break;

case 'day':
    [$from, $to] = local_day_bounds($_GET['date'] ?? date('Y-m-d'));
    $sql = 'SELECT device, ts, power_w, energy_today_kwh' . (isset($_GET['device']) ? ', data' : '') .
           ' FROM readings WHERE ts >= :f AND ts < :t' . (isset($_GET['device']) ? ' AND device = :d' : ' AND ' . NOT_BATTERY) . ' ORDER BY ts';
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':f', $from, SQLITE3_INTEGER);
    $stmt->bindValue(':t', $to, SQLITE3_INTEGER);
    if (isset($_GET['device'])) $stmt->bindValue(':d', $_GET['device']);
    $out = rows($stmt);
    foreach ($out as &$r) if (isset($r['data'])) $r['data'] = json_decode($r['data'], true);
    json_out(['from' => $from, 'to' => $to, 'rows' => $out]);
    break;

case 'daily':
    $days = max(1, min(3660, (int)($_GET['days'] ?? 30)));
    [$from] = local_day_bounds(date('Y-m-d', strtotime('-' . ($days - 1) . ' days')));
    $sql = "SELECT device, date(ts, 'unixepoch', 'localtime') AS day, MAX(energy_today_kwh) AS energy_kwh,
                   MAX(power_w) AS peak_w
            FROM readings WHERE ts >= :f" . (isset($_GET['device']) ? ' AND device = :d' : ' AND ' . NOT_BATTERY) .
           ' GROUP BY device, day ORDER BY day';
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':f', $from, SQLITE3_INTEGER);
    if (isset($_GET['device'])) $stmt->bindValue(':d', $_GET['device']);
    json_out(['rows' => rows($stmt)]);
    break;

case 'monthly':
    $sql = "SELECT device, substr(day, 1, 7) AS month, SUM(e) AS energy_kwh, COUNT(*) AS days FROM (
              SELECT device, date(ts, 'unixepoch', 'localtime') AS day, MAX(energy_today_kwh) AS e
              FROM readings" . (isset($_GET['device']) ? ' WHERE device = :d' : ' WHERE ' . NOT_BATTERY) . '
              GROUP BY device, day)
            GROUP BY device, month ORDER BY month';
    $stmt = $db->prepare($sql);
    if (isset($_GET['device'])) $stmt->bindValue(':d', $_GET['device']);
    json_out(['rows' => rows($stmt)]);
    break;

case 'events':
    $limit = max(1, min(2000, (int)($_GET['limit'] ?? 100)));
    $stmt = $db->prepare('SELECT ts, code, source FROM events WHERE device = :d ORDER BY ts DESC LIMIT :n');
    $stmt->bindValue(':d', $_GET['device'] ?? '');
    $stmt->bindValue(':n', $limit, SQLITE3_INTEGER);
    $recent = rows($stmt);
    $stmt = $db->prepare("SELECT CASE WHEN code LIKE 'Calictime%' THEN 'Calictime' ELSE code END AS code,
                                 COUNT(*) AS n, MAX(ts) AS last_ts
                          FROM events WHERE device = :d GROUP BY 1 ORDER BY n DESC");
    $stmt->bindValue(':d', $_GET['device'] ?? '');
    json_out(['recent' => $recent, 'counts' => rows($stmt)]);
    break;

case 'savings':
    $cfg = load_config();
    $periods = tariff_periods($cfg);
    $devCfg = [];
    foreach ($cfg['devices'] ?? [] as $d) $devCfg[$d['id']] = $d;
    $names = [];
    foreach (rows($db->prepare('SELECT id, name, retired FROM devices ORDER BY sort')) as $d) $names[$d['id']] = $d;

    $daily = rows($db->prepare("SELECT device, date(ts, 'unixepoch', 'localtime') AS day, MAX(energy_today_kwh) AS kwh
                                FROM readings WHERE " . NOT_BATTERY . " GROUP BY device, day ORDER BY day"));
    $today = date('Y-m-d'); $month = date('Y-m'); $year = date('Y');
    $since365 = date('Y-m-d', strtotime('-365 days'));
    $tot = ['today' => 0, 'month' => 0, 'year' => 0, 'all' => 0, 'last365' => 0,
            'kwh_today' => 0, 'kwh_month' => 0, 'kwh_year' => 0, 'kwh_all' => 0];
    $monthly = []; $yearly = []; $perDevice = [];
    foreach ($daily as $r) {
        $kwh = (float)$r['kwh'];
        $val = $kwh * rate_for_day($periods, $r['day']);
        $m = substr($r['day'], 0, 7); $y = substr($r['day'], 0, 4);
        $key = $r['device'] . '|' . $m;
        $monthly[$key] ??= ['device' => $r['device'], 'month' => $m, 'kwh' => 0, 'value' => 0];
        $monthly[$key]['kwh'] += $kwh; $monthly[$key]['value'] += $val;
        $yearly[$y] ??= ['year' => $y, 'kwh' => 0, 'value' => 0];
        $yearly[$y]['kwh'] += $kwh; $yearly[$y]['value'] += $val;
        $perDevice[$r['device']] ??= ['kwh' => 0, 'value' => 0, 'last365' => 0];
        $perDevice[$r['device']]['kwh'] += $kwh; $perDevice[$r['device']]['value'] += $val;
        $tot['all'] += $val; $tot['kwh_all'] += $kwh;
        if ($r['day'] === $today) { $tot['today'] += $val; $tot['kwh_today'] += $kwh; }
        if ($m === $month) { $tot['month'] += $val; $tot['kwh_month'] += $kwh; }
        if ($y === $year) { $tot['year'] += $val; $tot['kwh_year'] += $kwh; }
        if ($r['day'] > $since365) { $tot['last365'] += $val; $perDevice[$r['device']]['last365'] += $val; }
    }

    // Payback: install cost vs value generated, per device and for active equipment overall.
    $devices = []; $cost = 0; $recovered = 0;
    foreach ($names as $id => $d) {
        $c = (float)($devCfg[$id]['install_cost'] ?? 0);
        $p = $perDevice[$id] ?? ['kwh' => 0, 'value' => 0, 'last365' => 0];
        $devices[] = ['id' => $id, 'name' => $d['name'], 'retired' => (bool)$d['retired'], 'kwh' => $p['kwh'],
                      'value' => $p['value'], 'install_cost' => $c ?: null,
                      'install_date' => $devCfg[$id]['install_date'] ?? null,
                      'payback_pct' => $c ? min(100, $p['value'] / $c * 100) : null];
        if ($c && !$d['retired']) { $cost += $c; $recovered += $p['value']; }
    }
    // Remaining time is estimated from the whole site's last 365 days (new equipment has little history).
    $remaining = max(0, $cost - $recovered);
    $payback = ['cost' => $cost, 'recovered' => $recovered, 'annual_estimate' => $tot['last365'],
                'years_remaining' => $cost && $tot['last365'] > 0 ? $remaining / $tot['last365'] : null];

    json_out(['currency' => $cfg['tariffs']['currency'] ?? 'NZD', 'symbol' => $cfg['tariffs']['symbol'] ?? '$',
              'periods' => $periods, 'current_rate' => rate_for_day($periods, $today),
              'totals' => $tot, 'monthly' => array_values($monthly), 'yearly' => array_values($yearly),
              'devices' => $devices, 'payback' => $payback]);
    break;

case 'green':
    // Whole-site generation as environmental impact (no per-device detail).
    $site = site_info();
    $devices = devices_with_latest($db, $site);
    $g = green_settings(load_config());
    $now_w = 0; $online = 0; $active = 0; $today = 0; $lifetime = 0; $first = null; $last = null;
    foreach ($devices as $d) {
        if ($d['type'] === 'battery') continue;  // a healthy battery must not make a dead solar logger look "online"
        $lifetime += $d['latest']['energy_total_kwh'] ?? 0;
        if ($d['first_ts'] && (!$first || $d['first_ts'] < $first)) $first = $d['first_ts'];
        if ($d['retired']) continue;
        $active++;
        $now_w += $d['power_w'] ?? 0;
        $today += $d['energy_today_kwh'] ?? 0;
        if ($d['state'] === 'online') $online++;
        if ($d['latest'] && (!$last || $d['latest']['ts'] > $last)) $last = $d['latest']['ts'];
    }
    $lifetime += $g['lifetime_adjust_kwh'];
    // online: live figure; night: sun is down; stale: no fresh readings in daylight (not the same as 0 kW)
    $state = $online ? 'online' : (!$site['daylight'] ? 'night' : 'stale');
    $impact = function ($kwh) use ($g) {
        $co2 = $kwh * $g['co2_kg_per_kwh'];
        return ['kwh' => $kwh, 'co2_kg' => $co2,
                'trees' => $g['tree_kg_per_year'] > 0 ? $co2 / $g['tree_kg_per_year'] : null,
                'car_km' => $g['car_kg_per_km'] > 0 ? $co2 / $g['car_kg_per_km'] : null,
                'home_days' => $g['home_kwh_per_day'] > 0 ? $kwh / $g['home_kwh_per_day'] : null];
    };
    $since = $g['install_date'] ?: ($first ? date('Y-m-d', $first) : null);
    json_out(['now' => time(), 'name' => $site['name'], 'daylight' => $site['daylight'],
              'sunrise' => $site['sunrise'], 'sunset' => $site['sunset'],
              'state' => $state, 'now_kw' => $state === 'online' ? $now_w / 1000 : null, 'last_reading' => $last,
              'today' => $impact($today), 'lifetime' => $impact($lifetime),
              'system' => ['panels' => $g['panels'], 'panel_w' => $g['panel_w'],
                           'kwp' => $g['panels'] && $g['panel_w'] ? $g['panels'] * $g['panel_w'] / 1000 : null,
                           'since' => $since],
              'settings' => $g]);
    break;

case 'csv':
    [$from] = local_day_bounds($_GET['from'] ?? date('Y-m-d'));
    [, $to] = local_day_bounds($_GET['to'] ?? date('Y-m-d'));
    $sql = 'SELECT device, ts, power_w, energy_today_kwh, energy_total_kwh, data FROM readings
            WHERE ts >= :f AND ts < :t' . (isset($_GET['device']) ? ' AND device = :d' : '') . ' ORDER BY ts';
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':f', $from, SQLITE3_INTEGER);
    $stmt->bindValue(':t', $to, SQLITE3_INTEGER);
    if (isset($_GET['device'])) $stmt->bindValue(':d', $_GET['device']);
    $all = rows($stmt);
    $keys = [];
    foreach ($all as &$r) { $r['data'] = json_decode($r['data'], true) ?: []; $keys += array_flip(array_keys($r['data'])); }
    $keys = array_keys($keys);
    $name = 'solar-' . ($_GET['device'] ?? 'site') . '-' . date('Ymd', $from) . '-' . date('Ymd', $to - 1) . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name) . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array_merge(['local_time', 'device', 'ts', 'power_w', 'energy_today_kwh', 'energy_total_kwh'], $keys));
    foreach ($all as $r) {
        $line = [date('Y-m-d H:i:s', $r['ts']), $r['device'], $r['ts'], $r['power_w'], $r['energy_today_kwh'], $r['energy_total_kwh']];
        foreach ($keys as $k) $line[] = $r['data'][$k] ?? '';
        fputcsv($out, $line);
    }
    break;

default:
    http_response_code(400);
    json_out(['error' => 'unknown action']);
}
