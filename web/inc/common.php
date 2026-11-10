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
