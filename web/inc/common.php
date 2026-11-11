<?php
// Shared helpers for api.php and admin. Not web-accessible (denied in Apache config).

date_default_timezone_set('Pacific/Auckland');
const DB_PATH = '/var/lib/solar-logger/solar.db';
const CONFIG_PATH = '/etc/solar-logger/config.json';
const HTPASSWD_PATH = '/etc/solar-logger/admin.htpasswd';

function db($readonly = true) {
    $db = new SQLite3(DB_PATH, $readonly ? SQLITE3_OPEN_READONLY : SQLITE3_OPEN_READWRITE);
    $db->busyTimeout(3000);
    return $db;
}

function rows($stmt) {
    $res = $stmt->execute();
    $out = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $out[] = $r;
    return $out;
}

function local_day_bounds($date) {
    $d = DateTime::createFromFormat('!Y-m-d', (string)$date) ?: new DateTime('today');
    $start = $d->getTimestamp();
    $d->modify('+1 day');
    return [$start, $d->getTimestamp()];
}

function json_out($data) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data);
}

function load_config() {
    $cfg = json_decode(@file_get_contents(CONFIG_PATH), true);
    return is_array($cfg) ? $cfg : ['devices' => []];
}

// The battery BMS an ME3000SP is linked to (same rule as poller.py linked_bms_id): its "bms" setting if present
// ('' = none), else the older Pi-control setting, else the only BMS when the site has exactly one ME3000SP and
// one BMS. Returns a device id or null.
function linked_bms_id($cfg, $dev) {
    $active = array_filter($cfg['devices'] ?? [], fn($d) => empty($d['retired']));
    $bmsIds = array_values(array_column(array_filter($active, fn($d) => $d['driver'] === 'jbd'), 'id'));
    if (array_key_exists('bms', $dev)) return in_array($dev['bms'], $bmsIds, true) ? $dev['bms'] : null;
    $old = $dev['pi_control']['bms'] ?? null;
    if ($old && in_array($old, $bmsIds, true)) return $old;
    $mes = array_filter($active, fn($d) => $d['driver'] === 'me3000');
    return count($mes) === 1 && count($bmsIds) === 1 ? $bmsIds[0] : null;
}

// Time-of-use helpers, same rules as poller.py tou_active / tou_reserve_pct.
function hhmm_minutes($t) {
    return preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', (string)$t, $m) ? (int)$m[1] * 60 + (int)$m[2] : null;
}
// Number (from 1) of the first switched-on slot covering time $ts, or null. Slots may run past midnight.
function tou_active_slot($slots, $ts) {
    $now = (int)date('G', $ts) * 60 + (int)date('i', $ts);
    $day = (int)date('N', $ts) - 1;   // Monday = 0, like Python's tm_wday
    $prev = ($day + 6) % 7;
    foreach (array_slice($slots ?? [], 0, 10) as $i => $s) {
        if (empty($s['on']) || !in_array($s['action'] ?? '', ['charge', 'hold', 'reserve'], true)) continue;
        $a = hhmm_minutes($s['start'] ?? ''); $b = hhmm_minutes($s['end'] ?? '');
        if ($a === null || $b === null || $a === $b) continue;
        $days = $s['days'] ?? [];
        $hit = $a < $b ? in_array($day, $days) && $now >= $a && $now < $b
                       : (in_array($day, $days) && $now >= $a) || (in_array($prev, $days) && $now < $b);
        if ($hit) return $i + 1;
    }
    return null;
}
function tou_reserve_pct($dev) { return max(0, min(50, (int)($dev['tou_reserve']['pct'] ?? 0))); }
// Pi control's floor right now: the floor, plus the reserve except inside a "Use reserve" slot.
function pi_floor_now($dev, $ts) {
    $floor = (int)($dev['pi_control']['floor_pct'] ?? 20);
    $reserve = tou_reserve_pct($dev);
    $n = tou_active_slot($dev['tou'] ?? [], $ts);
    $using = $n && ($dev['tou'][$n - 1]['action'] ?? '') === 'reserve';
    return ['now' => $floor + ($using ? 0 : $reserve), 'floor' => $floor, 'reserve' => $reserve, 'using' => $using];
}

// Atomic write: the poller reloads the file when its mtime changes.
function save_config($cfg) {
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) throw new RuntimeException('could not encode config');
    $tmp = CONFIG_PATH . '.tmp';
    if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) throw new RuntimeException('could not write config');
    chmod($tmp, 0660);
    if (!rename($tmp, CONFIG_PATH)) throw new RuntimeException('could not replace config');
}

// Going Green page settings with defaults. Factors are owner-editable estimates.
const GREEN_DEFAULTS = [
    'heading' => 'Powered by the sun',
    'message' => '',
    'panels' => null,
    'panel_w' => null,
    'install_date' => null,
    'lifetime_adjust_kwh' => 0.0,
    'co2_kg_per_kwh' => 0.074,   // NZ grid average emissions (MfE guidance, recent years)
    'tree_kg_per_year' => 22.0,  // CO2 absorbed by one tree in a year
    'car_kg_per_km' => 0.17,     // average NZ petrol car
    'home_kwh_per_day' => 20.0,  // average NZ household
];

function green_settings($cfg) {
    $g = array_merge(GREEN_DEFAULTS, array_filter($cfg['green'] ?? [], fn($v) => $v !== null && $v !== ''));
    foreach (['lifetime_adjust_kwh', 'co2_kg_per_kwh', 'tree_kg_per_year', 'car_kg_per_km', 'home_kwh_per_day'] as $k)
        $g[$k] = (float)$g[$k];
    $g['panels'] = $g['panels'] === null ? null : (int)$g['panels'];
    $g['panel_w'] = $g['panel_w'] === null ? null : (float)$g['panel_w'];
    return $g;
}

// Tariff periods sorted by start date; each day uses the latest period starting on or before it.
function tariff_periods($cfg) {
    $p = $cfg['tariffs']['periods'] ?? [];
    usort($p, fn($a, $b) => strcmp($a['from'], $b['from']));
    return $p;
}

// Value per kWh generated: self-used share avoids the import price, the rest earns the export price.
function rate_for_day($periods, $day) {
    if (!$periods) return 0.0;
    $use = $periods[0];
    foreach ($periods as $p) { if ($p['from'] <= $day) $use = $p; else break; }
    $self = max(0, min(100, (float)($use['self_use_pct'] ?? 100))) / 100;
    return $self * (float)($use['import_rate'] ?? 0) + (1 - $self) * (float)($use['export_rate'] ?? 0);
}
