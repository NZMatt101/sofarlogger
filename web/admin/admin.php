<?php
// Solar logger admin. Protected by Apache basic auth (see deploy/solar-admin.conf).
require __DIR__ . '/../inc/common.php';
require __DIR__ . '/../inc/backup.php';
require __DIR__ . '/../inc/dropbox.php';
require __DIR__ . '/../inc/history.php';

session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

// IGEN/Omnik logger web-server account settings (from the logger's wirepoint.html / utils.js).
const LOGGER_CFG_USER = 0x010b0200;
const LOGGER_CFG_PWD = 0x01010200;
const LOGGER_CRED_RE = '/^[A-Za-z0-9_.@#%*!-]{1,15}$/';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function flash($type, $msg) { $_SESSION['flash'][] = [$type, $msg]; }
function num_or_null($v) { $v = trim((string)$v); return $v === '' ? null : (float)$v; }

function logger_request($ip, $user, $pass, $path, $post = null, $timeout = 8) {
    $opts = ['http' => [
        'method' => $post === null ? 'GET' : 'POST',
        'header' => "Authorization: Basic " . base64_encode("$user:$pass") . "\r\n" .
                    ($post === null ? '' : "Content-Type: application/x-www-form-urlencoded\r\n"),
        'content' => $post === null ? '' : http_build_query($post),
        'timeout' => $timeout, 'ignore_errors' => true,
    ]];
    $body = @file_get_contents("http://$ip/$path", false, stream_context_create($opts));
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) $status = (int)$m[1];
    return [$status, $body];
}

// The logger's web server occasionally refuses a valid login, so retry before giving up.
function logger_login_ok($ip, $user, $pass, $tries = 3) {
    for ($i = 0; $i < $tries; $i++) {
        if ($i) sleep(2);
        [$status] = logger_request($ip, $user, $pass, 'status.html', null, 5);
        if ($status === 200) return true;
    }
    return false;
}

function find_device(&$cfg, $id) {
    foreach ($cfg['devices'] as $i => $d) if ($d['id'] === $id) return $i;
    return null;
}

function history_import_msg($r) {
    if (!$r['imported']) return 'Nothing imported (' . number_format($r['skipped']) . ' readings skipped as duplicates or not earlier than this device\'s own readings).';
    return 'Imported ' . number_format($r['imported']) . ' readings (' . date('j M Y H:i', $r['first']) . ' to ' . date('j M Y H:i', $r['last']) . ')'
         . ($r['skipped'] ? ', skipped ' . number_format($r['skipped']) : '')
         . (!empty($r['aligned']) ? '. The day\'s running total on ' . date('j M', $r['last']) . ' adjusted by +' . number_format($r['aligned'], 2) . ' kWh to continue from the imported readings' : '') . '.';
}

// Serial port from a form, limited to USB serial devices. The by-path names stay with a USB socket, so two
// identical adapters can't swap over after a restart the way /dev/ttyUSB0 and /dev/ttyUSB1 can.
function rs485_port($post) {
    $port = trim($post['port'] ?? '');
    if (!preg_match('#^/dev/(tty(USB|ACM)\d{1,2}|serial/by-(id|path)/[A-Za-z0-9_.:-]+)$#', $port))
        throw new InvalidArgumentException('Serial port must look like /dev/ttyUSB0 or /dev/serial/by-path/…');
    return $port;
}

// Serial port and Modbus address from a form.
function rs485_settings($post) {
    $port = rs485_port($post);
    $slave = filter_var($post['slave'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 247]]);
    if ($slave === false) throw new InvalidArgumentException('RS485 address must be 1 to 247');
    return [$port, $slave];
}

// USB serial ports present now, fixed per-socket names first, each with the ttyUSB name it currently points to.
function serial_ports() {
    $ports = [];
    foreach (array_merge(glob('/dev/serial/by-path/*-usb-0:*') ?: [], glob('/dev/ttyUSB*') ?: [], glob('/dev/ttyACM*') ?: []) as $p)
        $ports[$p] = is_link($p) ? basename(realpath($p)) : '';
    return $ports;
}

function slug($s) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-') ?: 'device'; }

function htpasswd_user() {
    $line = trim((string)@file_get_contents(HTPASSWD_PATH));
    return $line ? explode(':', $line, 2) : [null, null];
}

// ---------------------------------------------------------------- backup downloads
// admin.php?download=config | full | full_sd   (GET, read-only; the page itself is behind basic auth)
if (isset($_GET['download'])) {
    $what = $_GET['download'];
    if ($what === 'config') {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="solar-config-' . date('Ymd-His') . '.json"');
        header('Cache-Control: no-store');
        readfile(CONFIG_PATH);
        exit;
    }
    if ($what !== 'full' && $what !== 'full_sd') { http_response_code(400); exit('Unknown download'); }
    set_time_limit(600);
    try {
        $b = backup_build(backup_tmpdir(), $what === 'full_sd');
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $b['name'] . '"');
        header('Content-Length: ' . $b['size']);
        header('Cache-Control: no-store');
        readfile($b['path']);
    } catch (Exception $e) {
        http_response_code(500);
        header('Content-Type: text/plain');
        echo 'Backup failed: ' . $e->getMessage();
    }
    exit;
}

// ---------------------------------------------------------------- actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        flash('err', 'Form expired, please try again.');
        header('Location: admin.php'); exit;
    }
    $cfg = load_config();
    $action = $_POST['action'] ?? '';
    $anchor = '';
    try {
        switch ($action) {
        case 'site':
            $cfg['site']['name'] = trim($_POST['name']) ?: 'Solar';
            $cfg['site']['lat'] = (float)$_POST['lat'];
            $cfg['site']['lon'] = (float)$_POST['lon'];
            $cfg['poll_seconds'] = max(15, min(3600, (int)$_POST['poll_seconds']));
            save_config($cfg);
            flash('ok', 'Site settings saved.');
            $anchor = 'site';
            break;

        case 'green':
            $pos = function ($k, $max) {
                $v = num_or_null($_POST[$k] ?? '');
                if ($v !== null && ($v < 0 || $v > $max)) throw new InvalidArgumentException("Value out of range: $k");
                return $v;
            };
            $date = trim($_POST['install_date'] ?? '');
            if ($date !== '' && !DateTime::createFromFormat('!Y-m-d', $date)) throw new InvalidArgumentException("Bad date: $date");
            $panels = $pos('panels', 10000);
            $adjust = num_or_null($_POST['lifetime_adjust_kwh'] ?? '');
            $cfg['green'] = [
                'heading' => substr(trim($_POST['heading'] ?? ''), 0, 80),
                'message' => substr(trim($_POST['message'] ?? ''), 0, 500),
                'panels' => $panels === null ? null : (int)$panels,
                'panel_w' => $pos('panel_w', 2000),
                'install_date' => $date ?: null,
                'lifetime_adjust_kwh' => $adjust ?? 0,
                'co2_kg_per_kwh' => $pos('co2_kg_per_kwh', 2),
                'tree_kg_per_year' => $pos('tree_kg_per_year', 1000),
                'car_kg_per_km' => $pos('car_kg_per_km', 2),
                'home_kwh_per_day' => $pos('home_kwh_per_day', 500),
            ];
            save_config($cfg);
            flash('ok', 'Going Green settings saved.');
            $anchor = 'green';
            break;

        case 'tariffs':
            $periods = [];
            foreach ($_POST['periods'] ?? [] as $p) {
                if (!empty($p['delete']) || trim($p['from'] ?? '') === '') continue;
                if (!DateTime::createFromFormat('!Y-m-d', $p['from'])) throw new InvalidArgumentException("Bad date: {$p['from']}");
                $periods[] = ['from' => $p['from'], 'import_rate' => (float)$p['import_rate'],
                              'export_rate' => (float)$p['export_rate'],
                              'self_use_pct' => max(0, min(100, (float)$p['self_use_pct']))];
            }
            usort($periods, fn($a, $b) => strcmp($a['from'], $b['from']));
            $cfg['tariffs'] = ['currency' => strtoupper(trim($_POST['currency'])) ?: 'NZD',
                               'symbol' => trim($_POST['symbol']) ?: '$', 'periods' => $periods];
            save_config($cfg);
            flash('ok', 'Tariffs saved (' . count($periods) . ' period' . (count($periods) === 1 ? '' : 's') . ').');
            $anchor = 'tariffs';
            break;

        case 'device':
            $i = find_device($cfg, $_POST['id']);
            if ($i === null) throw new InvalidArgumentException('Unknown device');
            $d = &$cfg['devices'][$i];
            if (!empty($_POST['remove'])) {
                array_splice($cfg['devices'], $i, 1);
                save_config($cfg);
                flash('ok', 'Device removed from configuration. Its history is kept in the database.');
                $anchor = 'equipment';
                break;
            }
            $d['name'] = trim($_POST['name']) ?: $d['name'];
            foreach (['model', 'location', 'notes'] as $k) $d[$k] = trim($_POST[$k] ?? '');
            $d['rated_w'] = num_or_null($_POST['rated_w']);
            $d['install_cost'] = num_or_null($_POST['install_cost']);
            $d['install_date'] = trim($_POST['install_date'] ?? '') ?: null;
            $d['retired'] = !empty($_POST['retired']);
            if ($d['driver'] === 'me3000') {
                [$d['port'], $d['slave']] = rs485_settings($_POST);
                $bms = (string)($_POST['bms'] ?? '');
                $bmsIds = array_column(array_filter($cfg['devices'], fn($x) => $x['driver'] === 'jbd' && empty($x['retired'])), 'id');
                if ($bms !== '' && !in_array($bms, $bmsIds, true)) throw new InvalidArgumentException('Unknown battery BMS');
                foreach ($cfg['devices'] as $other)
                    if ($bms !== '' && $other['id'] !== $d['id'] && $other['driver'] === 'me3000' && empty($other['retired'])
                        && linked_bms_id($cfg, $other) === $bms)
                        throw new InvalidArgumentException("That BMS is already the battery for {$other['name']}. Each battery belongs to one inverter.");
                $hasCt = !empty($_POST['has_ct']);
                if (!$hasCt && ($d['controller'] ?? 'inverter') === 'pi' && empty($d['pair']))
                    throw new InvalidArgumentException('This unit is under Pi control, which needs its CT. Switch it back to inverter control on its battery page first.');
                $d['bms'] = $bms;
                $d['has_ct'] = $hasCt;
                unset($d['pi_control']['bms']);   // the link above replaces Pi control's own copy
            } elseif ($d['driver'] === 'jbd') {
                $d['port'] = rs485_port($_POST);
            } elseif ($d['driver'] !== 'import') {
                if (!filter_var($_POST['ip'], FILTER_VALIDATE_IP)) throw new InvalidArgumentException('Invalid IP address');
                $d['ip'] = $_POST['ip'];
                $d['serial'] = (int)$_POST['serial'];
            }
            save_config($cfg);
            flash('ok', "Saved {$d['name']}.");
            $anchor = 'dev-' . $d['id'];
            break;

        case 'add_device':
            if (!filter_var($_POST['ip'], FILTER_VALIDATE_IP)) throw new InvalidArgumentException('Invalid IP address');
            if (!ctype_digit(trim($_POST['serial']))) throw new InvalidArgumentException('Logger serial must be a number');
            $id = $base = 'inv-' . slug($_POST['name']);
            for ($n = 2; find_device($cfg, $id) !== null; $n++) $id = "$base-$n";
            $cfg['devices'][] = ['id' => $id, 'name' => trim($_POST['name']), 'type' => 'inverter', 'driver' => 'omnik',
                                 'model' => trim($_POST['model']), 'rated_w' => num_or_null($_POST['rated_w']),
                                 'location' => '', 'ip' => $_POST['ip'], 'serial' => (int)$_POST['serial'],
                                 'web_user' => 'admin', 'web_pass' => 'admin'];
            save_config($cfg);
            flash('ok', "Added {$_POST['name']} ($id). The poller picks it up within a minute.");
            $anchor = 'equipment';
            break;

        case 'add_me3000':
            [$port, $slave] = rs485_settings($_POST);
            $name = trim($_POST['name']);
            if ($name === '') throw new InvalidArgumentException('Name is required');
            $id = $base = 'bat-' . slug($name);
            for ($n = 2; find_device($cfg, $id) !== null; $n++) $id = "$base-$n";
            $cfg['devices'][] = ['id' => $id, 'name' => $name, 'type' => 'battery', 'driver' => 'me3000',
                                 'model' => trim($_POST['model']), 'rated_w' => num_or_null($_POST['rated_w']),
                                 'location' => '', 'port' => $port, 'slave' => $slave];
            save_config($cfg);
            flash('ok', "Added $name ($id). The poller picks it up within a minute.");
            $anchor = 'equipment';
            break;

        case 'add_jbd':
            $port = rs485_port($_POST);
            foreach ($cfg['devices'] as $other)
                if (($other['port'] ?? null) === $port && empty($other['retired']))
                    throw new InvalidArgumentException("$port is already used by {$other['name']}");
            $name = trim($_POST['name']);
            if ($name === '') throw new InvalidArgumentException('Name is required');
            $id = $base = 'bms-' . slug($name);
            for ($n = 2; find_device($cfg, $id) !== null; $n++) $id = "$base-$n";
            $cfg['devices'][] = ['id' => $id, 'name' => $name, 'type' => 'battery', 'driver' => 'jbd',
                                 'model' => trim($_POST['model']), 'rated_w' => null, 'location' => '', 'port' => $port];
            save_config($cfg);
            flash('ok', "Added $name ($id). The poller picks it up within a minute.");
            $anchor = 'equipment';
            break;

        case 'logger_test':
        case 'logger_store':
        case 'logger_change':
            $i = find_device($cfg, $_POST['id']);
            if ($i === null) throw new InvalidArgumentException('Unknown device');
            $d = &$cfg['devices'][$i];
            $anchor = 'login-' . $d['id'];
            $curUser = $d['web_user'] ?? 'admin';
            $curPass = $d['web_pass'] ?? 'admin';

            if ($action === 'logger_test') {
                $ok = logger_login_ok($d['ip'], $curUser, $curPass);
                if ($ok && !empty($d['web_pass_unverified'])) { unset($d['web_pass_unverified']); save_config($cfg); }
                flash($ok ? 'ok' : 'err', $ok ? "Stored login works on {$d['ip']}."
                                              : "Stored login was rejected by {$d['ip']} (or the logger is offline).");
                break;
            }

            $newUser = trim($_POST['new_user'] ?? '');
            $newPass = $_POST['new_pass'] ?? '';
            if (!preg_match(LOGGER_CRED_RE, $newUser) || !preg_match(LOGGER_CRED_RE, $newPass))
                throw new InvalidArgumentException('Username and password must be 1–15 characters: letters, digits and _ . @ # % * ! -');
            if ($newPass !== ($_POST['new_pass2'] ?? '')) throw new InvalidArgumentException('Passwords do not match');

            if ($action === 'logger_store') {
                // Record credentials that were changed elsewhere (e.g. on the logger's own page).
                if (!logger_login_ok($d['ip'], $newUser, $newPass))
                    throw new InvalidArgumentException("The logger at {$d['ip']} rejected that login; not saved.");
                $d['web_user'] = $newUser; $d['web_pass'] = $newPass;
                unset($d['web_pass_unverified']);
                save_config($cfg);
                flash('ok', 'Login verified and stored.');
                break;
            }

            if (!logger_login_ok($d['ip'], $curUser, $curPass))
                throw new InvalidArgumentException("The current stored login doesn't work on {$d['ip']}. Use “Store existing login” first.");
            set_time_limit(150);
            logger_request($d['ip'], $curUser, $curPass, 'do_cmd.html', [
                'CMD' => 'USER', 'GO' => 'wirepoint.html',
                'SET0' => LOGGER_CFG_USER . '=' . $newUser,
                'SET1' => LOGGER_CFG_PWD . '=' . $newPass,
            ], 10);
            // The logger may restart; wait for the new login to be accepted.
            $ok = false;
            for ($t = 0; $t < 120 && !$ok; $t += 5) { sleep(5); $ok = logger_login_ok($d['ip'], $newUser, $newPass); }
            if ($ok) {
                $d['web_user'] = $newUser; $d['web_pass'] = $newPass;
                unset($d['web_pass_unverified']);
                save_config($cfg);
                flash('ok', "Logger login changed on {$d['ip']} and verified.");
            } elseif (logger_login_ok($d['ip'], $curUser, $curPass)) {
                flash('err', 'The logger did not accept the change; the old login still works. Nothing was saved.');
            } else {
                // Neither works yet (maybe still rebooting): keep the new one, since the logger accepted the request.
                $d['web_user'] = $newUser; $d['web_pass'] = $newPass;
                $d['web_pass_unverified'] = true;
                save_config($cfg);
                flash('err', 'Change sent but could not be verified yet (logger may still be restarting). The new login has been stored — use “Test stored login” in a minute.');
            }
            break;

        case 'backup_settings':
            $keep = (int)($_POST['keep'] ?? 8);
            if ($keep < 1 || $keep > 100) throw new InvalidArgumentException('Copies to keep must be 1–100');
            $cfg['backup'] = ['dropbox_enabled' => !empty($_POST['dropbox_enabled']),
                              'frequency' => ($_POST['frequency'] ?? '') === 'daily' ? 'daily' : 'weekly',
                              'keep' => $keep, 'include_sources' => !empty($_POST['include_sources'])];
            if ($cfg['backup']['dropbox_enabled'] && !dropbox_creds())
                throw new InvalidArgumentException('Connect Dropbox first, then turn on automatic backups.');
            save_config($cfg);
            flash('ok', 'Backup settings saved.');
            $anchor = 'backup';
            break;

        case 'dropbox_start':
            $key = trim($_POST['app_key'] ?? '');
            if (!preg_match('/^[a-z0-9]{8,32}$/i', $key)) throw new InvalidArgumentException('That does not look like a Dropbox App key.');
            [$verifier, $challenge] = pkce_pair();
            $_SESSION['dbx'] = ['key' => $key, 'verifier' => $verifier, 'url' => dropbox_auth_url($key, $challenge)];
            $anchor = 'backup';
            break;

        case 'dropbox_finish':
            $anchor = 'backup';
            if (empty($_SESSION['dbx'])) throw new InvalidArgumentException('Start the Dropbox connection again.');
            dropbox_finish_auth($_SESSION['dbx']['key'], $_POST['code'] ?? '', $_SESSION['dbx']['verifier']);
            unset($_SESSION['dbx']);
            flash('ok', 'Dropbox connected. Use “Back up now” to test it, then tick “Automatic backups”.');
            break;

        case 'dropbox_cancel':
            unset($_SESSION['dbx']);
            $anchor = 'backup';
            break;

        case 'dropbox_disconnect':
            dropbox_disconnect();
            if (!empty($cfg['backup']['dropbox_enabled'])) { $cfg['backup']['dropbox_enabled'] = false; save_config($cfg); }
            flash('ok', 'Dropbox disconnected and automatic backups turned off. Backups already in Dropbox are kept.');
            $anchor = 'backup';
            break;

        case 'backup_now':
            set_time_limit(600);
            $msg = run_auto_backup(true);
            flash(str_starts_with($msg, 'failed') ? 'err' : 'ok', 'Dropbox backup ' . $msg . '.');
            $anchor = 'backup';
            break;

        case 'history_source':
            $anchor = 'history';
            $base = rtrim(trim($_POST['base'] ?? ''), '/');
            $_SESSION['hist'] = ['base' => $base, 'devices' => history_source_devices($base)];
            break;

        case 'history_source_clear':
            unset($_SESSION['hist']);
            $anchor = 'history';
            break;

        case 'history_import_url':
            $anchor = 'history';
            if (empty($_SESSION['hist'])) throw new InvalidArgumentException('Load the other logger\'s devices first.');
            set_time_limit(600);
            $r = history_import_url($_SESSION['hist']['base'], $_POST['source'] ?? '', $_POST['target'] ?? '',
                                    trim($_POST['from'] ?? ''), trim($_POST['to'] ?? ''), !empty($_POST['only_before']));
            flash('ok', history_import_msg($r));
            break;

        case 'history_import_file':
            $anchor = 'history';
            $f = $_FILES['csv'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
                $tooBig = $f && in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
                throw new InvalidArgumentException($tooBig ? 'File too large to upload (limit ' . ini_get('upload_max_filesize') . '). Import from the other logger\'s address instead, or export a shorter date range.'
                                                           : 'Choose a CSV file to upload.');
            }
            set_time_limit(600);
            $fh = fopen($f['tmp_name'], 'r');
            try { $r = history_import_csv($fh, trim($_POST['source'] ?? ''), $_POST['target'] ?? '', !empty($_POST['only_before'])); }
            finally { fclose($fh); }
            flash('ok', history_import_msg($r));
            break;

        case 'history_delete':
            $anchor = 'history';
            if (empty($_POST['confirm'])) throw new InvalidArgumentException('Tick the box to confirm the deletion.');
            $dev = $_POST['device'] ?? '';
            if ($dev === '') throw new InvalidArgumentException('Choose a device.');
            $n = history_delete($dev, trim($_POST['from'] ?? ''), trim($_POST['to'] ?? ''));
            flash('ok', "Deleted " . number_format($n['readings']) . " readings and " . number_format($n['events']) . " events.");
            break;

        case 'admin_password':
            [$user, $hash] = htpasswd_user();
            if (!$hash || !password_verify($_POST['current'] ?? '', $hash)) throw new InvalidArgumentException('Current admin password is wrong');
            $new = $_POST['new'] ?? '';
            if (strlen($new) < 8) throw new InvalidArgumentException('New password must be at least 8 characters');
            if ($new !== ($_POST['new2'] ?? '')) throw new InvalidArgumentException('Passwords do not match');
            if (file_put_contents(HTPASSWD_PATH, $user . ':' . password_hash($new, PASSWORD_BCRYPT) . "\n", LOCK_EX) === false)
                throw new RuntimeException('Could not write password file');
            flash('ok', 'Admin password changed. Your browser will ask you to log in again.');
            $anchor = 'admin';
            break;

        default:
            throw new InvalidArgumentException('Unknown action');
        }
    } catch (Exception $e) {
        flash('err', $e->getMessage());
    }
    header('Location: admin.php' . ($anchor ? "#$anchor" : '')); exit;
}

// ---------------------------------------------------------------- page data
$cfg = load_config();
$site = $cfg['site'] ?? [];
$tariffs = $cfg['tariffs'] ?? ['currency' => 'NZD', 'symbol' => '$', 'periods' => []];
$periods = tariff_periods($cfg);
$green = $cfg['green'] ?? [];
$backup = backup_settings($cfg);
$backupStatus = backup_status();
$dbxCreds = dropbox_creds();
$dbxPending = $_SESSION['dbx'] ?? null;
$hist = $_SESSION['hist'] ?? null;
$histDevices = []; $histStats = [];
try {
    $hdb = db();
    foreach (rows($hdb->prepare('SELECT id, name, retired FROM devices ORDER BY sort, name')) as $d) $histDevices[$d['id']] = $d;
    foreach (rows($hdb->prepare('SELECT device, COUNT(*) AS n, MIN(ts) AS first, MAX(ts) AS last FROM readings GROUP BY device')) as $r) $histStats[$r['device']] = $r;
    $hdb->close();
} catch (Throwable $e) { /* database unavailable or empty: section shows no devices */ }
$flashes = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);
$csrf = h($_SESSION['csrf']);

$status = [];
try {
    $db = db();
    foreach (rows($db->prepare('SELECT * FROM device_status')) as $s) $status[$s['device']] = $s;
    $readingCount = $db->querySingle('SELECT COUNT(*) FROM readings');
} catch (Exception $e) { $readingCount = null; }
$pollerActive = trim((string)shell_exec('systemctl is-active solar-poller 2>/dev/null'));
$dbSize = @filesize(DB_PATH) + @filesize(DB_PATH . '-wal');
$diskFree = disk_free_space('/');
$diskTotal = disk_total_space('/');
[$adminUser] = htpasswd_user();
$fmtTime = fn($ts) => $ts ? date('j M Y H:i', $ts) : '—';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin · <?= h($site['name'] ?? 'Solar') ?></title>
<link rel="stylesheet" href="../style.css?v=3">
<style>
  form.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px 14px; align-items: end; }
  form.grid label, .field { display: grid; gap: 3px; font-size: 13px; color: var(--muted); }
  form.grid input, form.grid select, form.grid textarea { width: 100%; color: var(--text); }
  .wide { grid-column: 1 / -1; }
  .actions { grid-column: 1 / -1; display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
  button.primary { background: var(--link); border-color: var(--link); color: #fff; }
  button.danger { color: var(--bad); }
  .flash { padding: 10px 14px; border-radius: 8px; border: 1px solid; }
  .flash.ok { color: var(--ok); } .flash.err { color: var(--bad); }
  table.form { width: 100%; border-collapse: collapse; font-size: 14px; }
  table.form th { text-align: left; color: var(--muted); font-weight: 500; font-size: 12px; padding: 4px 6px; }
  table.form td { padding: 4px 6px; }
  table.form input { width: 100%; }
  .dev { border: 1px solid var(--line); border-radius: 10px; background: var(--bg); padding: 14px 16px; margin-top: 14px; }
  .dev.retired { opacity: .8; }
  .dev.add { border-style: dashed; }
  .dev-sub { border-top: 1px solid var(--line); margin-top: 14px; padding-top: 14px; }
  .dev h3 { font-size: 15px; margin: 0 0 10px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
  .help { color: var(--muted); font-size: 13px; margin: 0 0 12px; }
  .check { display: flex !important; grid-auto-flow: column; gap: 6px; align-items: center; color: var(--text) !important; }
  .check input { width: auto !important; }
  nav.top a { margin-left: 14px; font-size: 14px; }
</style>
</head>
<body>
<header>
  <div>
    <div class="crumbs"><a href="../">← <?= h($site['name'] ?? 'Overview') ?></a></div>
    <h1>⚙ Admin</h1>
  </div>
  <nav class="top"><a href="../">Overview</a><a href="../savings.html">Savings</a><a href="../green.html">Going Green</a><a href="#site">Site</a><a href="#green">Green settings</a><a href="#tariffs">Tariffs</a><a href="#equipment">Equipment</a><a href="#history">History</a><a href="#backup">Backup</a><a href="#admin">Admin login</a></nav>
</header>
<main>
<?php foreach ($flashes as [$type, $msg]): ?>
  <div class="flash <?= h($type) ?>"><?= h($msg) ?></div>
<?php endforeach; ?>

  <section class="card" id="system">
    <div class="section-head"><h2>System</h2></div>
    <dl class="info">
      <dt>Poller service</dt><dd><span class="badge <?= $pollerActive === 'active' ? 'online' : 'error' ?>"><?= h($pollerActive ?: 'unknown') ?></span></dd>
      <dt>Readings stored</dt><dd><?= $readingCount === null ? '—' : number_format($readingCount) ?></dd>
      <dt>Database size</dt><dd><?= number_format($dbSize / 1048576, 1) ?> MB</dd>
      <dt>SD card free</dt><dd><?= number_format($diskFree / 1048576) ?> MB of <?= number_format($diskTotal / 1048576) ?> MB</dd>
<?php foreach ($cfg['devices'] as $d): if (!empty($d['retired']) || $d['driver'] === 'import') continue; $s = $status[$d['id']] ?? null; ?>
      <dt><?= h($d['name']) ?></dt><dd><?= h($s['status'] ?? 'not polled yet') ?> · last OK <?= $fmtTime($s['last_ok'] ?? null) ?></dd>
<?php endforeach; ?>
    </dl>
  </section>

  <section class="card" id="site">
    <div class="section-head"><h2>Site</h2></div>
    <p class="help">Location is used for sunrise/sunset (night-time status). Changes apply within a minute.</p>
    <form class="grid" method="post">
      <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="site">
      <label>Site name <input name="name" value="<?= h($site['name'] ?? '') ?>" required></label>
      <label>Latitude <input name="lat" type="number" step="0.0001" min="-90" max="90" value="<?= h($site['lat'] ?? '') ?>" required></label>
      <label>Longitude <input name="lon" type="number" step="0.0001" min="-180" max="180" value="<?= h($site['lon'] ?? '') ?>" required></label>
      <label>Poll interval (seconds) <input name="poll_seconds" type="number" min="15" max="3600" value="<?= h($cfg['poll_seconds'] ?? 60) ?>"></label>
      <div class="actions"><button class="primary">Save site</button></div>
    </form>
  </section>

  <section class="card" id="green">
    <div class="section-head"><h2>Going Green page</h2></div>
    <p class="help">Site specification and impact factors for the <a href="../green.html">Going Green</a> page. Leave a field blank to hide it
      (or, for factors, to use the default shown). Lifetime adjustment adds or subtracts kWh from the lifetime total, e.g. generation from before logging began.</p>
    <form class="grid" method="post">
      <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="green">
      <label class="wide">Heading <input name="heading" maxlength="80" value="<?= h($green['heading'] ?? '') ?>" placeholder="<?= h(GREEN_DEFAULTS['heading']) ?>"></label>
      <label class="wide">Message <input name="message" maxlength="500" value="<?= h($green['message'] ?? '') ?>" placeholder="Optional note for guests"></label>
      <label>Number of panels <input name="panels" type="number" min="0" step="1" value="<?= h($green['panels'] ?? '') ?>"></label>
      <label>Panel rating (W each) <input name="panel_w" type="number" min="0" step="1" value="<?= h($green['panel_w'] ?? '') ?>"></label>
      <label>Array size <input value="<?= !empty($green['panels']) && !empty($green['panel_w']) ? h(number_format($green['panels'] * $green['panel_w'] / 1000, 2)) . ' kWp' : '—' ?>" disabled></label>
      <label>Generating since <input name="install_date" type="date" value="<?= h($green['install_date'] ?? '') ?>"></label>
      <label>Lifetime adjustment (kWh) <input name="lifetime_adjust_kwh" type="number" step="0.1" value="<?= h($green['lifetime_adjust_kwh'] ?? '') ?>" placeholder="0"></label>
      <label>Grid CO₂ (kg per kWh) <input name="co2_kg_per_kwh" type="number" min="0" step="0.001" value="<?= h($green['co2_kg_per_kwh'] ?? '') ?>" placeholder="<?= GREEN_DEFAULTS['co2_kg_per_kwh'] ?>"></label>
      <label>CO₂ per tree per year (kg) <input name="tree_kg_per_year" type="number" min="0" step="0.1" value="<?= h($green['tree_kg_per_year'] ?? '') ?>" placeholder="<?= GREEN_DEFAULTS['tree_kg_per_year'] ?>"></label>
      <label>Car CO₂ (kg per km) <input name="car_kg_per_km" type="number" min="0" step="0.001" value="<?= h($green['car_kg_per_km'] ?? '') ?>" placeholder="<?= GREEN_DEFAULTS['car_kg_per_km'] ?>"></label>
      <label>Household use (kWh per day) <input name="home_kwh_per_day" type="number" min="0" step="0.1" value="<?= h($green['home_kwh_per_day'] ?? '') ?>" placeholder="<?= GREEN_DEFAULTS['home_kwh_per_day'] ?>"></label>
      <div class="actions"><button class="primary">Save green settings</button> <a href="../green.html">View page →</a></div>
    </form>
  </section>

  <section class="card" id="tariffs">
    <div class="section-head"><h2>Electricity prices</h2></div>
    <p class="help">Used for the <a href="../savings.html">Savings</a> page. Each generated kWh is valued as:
      self-used share × <em>import price</em> (what you'd otherwise pay per kWh) + exported share × <em>export price</em> (buy-back rate).
      With only one price, set self-use to 100%. Add a new row whenever prices change — each day uses the latest row on or before it.</p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="tariffs">
      <div class="controls" style="margin-bottom:10px">
        <label class="field">Currency <input name="currency" value="<?= h($tariffs['currency'] ?? 'NZD') ?>" size="5"></label>
        <label class="field">Symbol <input name="symbol" value="<?= h($tariffs['symbol'] ?? '$') ?>" size="3"></label>
      </div>
      <table class="form">
        <thead><tr><th>From date</th><th>Import price per kWh</th><th>Export price per kWh</th><th>Self-used %</th><th>Delete</th></tr></thead>
        <tbody>
<?php foreach (array_merge($periods, [['from' => '', 'import_rate' => '', 'export_rate' => '', 'self_use_pct' => 100]]) as $i => $p): ?>
          <tr>
            <td><input type="date" name="periods[<?= $i ?>][from]" value="<?= h($p['from']) ?>"></td>
            <td><input type="number" step="0.0001" min="0" name="periods[<?= $i ?>][import_rate]" value="<?= h($p['import_rate']) ?>" placeholder="e.g. 0.32"></td>
            <td><input type="number" step="0.0001" min="0" name="periods[<?= $i ?>][export_rate]" value="<?= h($p['export_rate']) ?>" placeholder="e.g. 0.12"></td>
            <td><input type="number" step="1" min="0" max="100" name="periods[<?= $i ?>][self_use_pct]" value="<?= h($p['self_use_pct']) ?>"></td>
            <td><?php if ($p['from'] !== ''): ?><input type="checkbox" name="periods[<?= $i ?>][delete]" value="1" aria-label="Delete"><?php else: ?><span class="help">new</span><?php endif; ?></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
      <div class="actions" style="margin-top:10px"><button class="primary">Save prices</button></div>
    </form>
  </section>

  <section class="card" id="equipment">
    <div class="section-head"><h2>Equipment</h2></div>
    <p class="help">Install cost and date are used for payback on the Savings page. Marking a device retired stops polling and moves it to “Retired equipment”.</p>
<?php $ports = serial_ports(); ?>
    <datalist id="serial-ports">
<?php foreach ($ports as $p => $tty): ?>
      <option value="<?= h($p) ?>"><?= h($tty ? "currently $tty" : '') ?></option>
<?php endforeach; ?>
    </datalist>
    <p class="help">Serial ports: use a <code>/dev/serial/by-path/…</code> name (offered when you click the field). It stays with the USB socket, whereas <code>/dev/ttyUSB0</code> and <code>/dev/ttyUSB1</code> can swap between identical adapters after a restart.</p>
<?php foreach ($cfg['devices'] as $d): $imported = $d['driver'] === 'import'; $rs485 = in_array($d['driver'], ['me3000', 'jbd'], true); ?>
    <div class="dev<?= !empty($d['retired']) ? ' retired' : '' ?>" id="dev-<?= h($d['id']) ?>">
      <h3><?= h($d['name']) ?> <span class="badge <?= !empty($d['retired']) ? 'retired' : 'online' ?>"><?= !empty($d['retired']) ? 'Retired' : 'Active' ?></span>
          <span class="help" style="margin:0"><?= h($d['id']) ?></span></h3>
      <form class="grid" method="post">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="device"><input type="hidden" name="id" value="<?= h($d['id']) ?>">
        <label>Name <input name="name" value="<?= h($d['name']) ?>" required></label>
        <label>Model <input name="model" value="<?= h($d['model'] ?? '') ?>"></label>
        <label>Location <input name="location" value="<?= h($d['location'] ?? '') ?>"></label>
        <label>Rated power (W) <input name="rated_w" type="number" min="0" value="<?= h($d['rated_w'] ?? '') ?>"></label>
<?php if ($rs485): ?>
        <label>Serial port <input name="port" list="serial-ports" value="<?= h($d['port'] ?? '/dev/ttyUSB0') ?>" required></label>
<?php if ($d['driver'] === 'me3000'): $myBms = linked_bms_id($cfg, $d); ?>
        <label>RS485 address <input name="slave" type="number" min="1" max="247" value="<?= h($d['slave'] ?? 1) ?>" required></label>
        <label>Battery BMS
          <select name="bms">
            <option value="">None</option>
<?php foreach ($cfg['devices'] as $b): if ($b['driver'] !== 'jbd' || !empty($b['retired'])) continue; ?>
            <option value="<?= h($b['id']) ?>"<?= $myBms === $b['id'] ? ' selected' : '' ?>><?= h($b['name']) ?></option>
<?php endforeach; ?>
          </select></label>
        <label class="check" title="The ME3000SP's current clamp, on the grid cable. Needed for it to follow the house load by itself.">
          <input type="checkbox" name="has_ct" value="1"<?= ($d['has_ct'] ?? true) ? ' checked' : '' ?>> CT fitted (on the grid cable)</label>
<?php elseif ($d['driver'] === 'jbd'):
        $owner = null;
        foreach ($cfg['devices'] as $m) if ($m['driver'] === 'me3000' && empty($m['retired']) && linked_bms_id($cfg, $m) === $d['id']) $owner = $m; ?>
        <p class="help wide" style="margin:0">Battery for: <?= $owner ? '<a href="#dev-' . h($owner['id']) . '">' . h($owner['name']) . '</a>'
          : 'no battery inverter yet. Choose it under "Battery BMS" on the inverter\'s entry.' ?></p>
<?php endif; ?>
<?php elseif (!$imported): ?>
        <label>Logger IP <input name="ip" value="<?= h($d['ip'] ?? '') ?>" required></label>
        <label>Logger serial <input name="serial" inputmode="numeric" value="<?= h($d['serial'] ?? '') ?>" required></label>
<?php endif; ?>
        <label>Install date <input name="install_date" type="date" value="<?= h($d['install_date'] ?? '') ?>"></label>
        <label>Install cost (<?= h($tariffs['symbol'] ?? '$') ?>) <input name="install_cost" type="number" step="0.01" min="0" value="<?= h($d['install_cost'] ?? '') ?>"></label>
        <label class="wide">Notes <input name="notes" value="<?= h($d['notes'] ?? '') ?>"></label>
        <div class="actions">
          <label class="check"><input type="checkbox" name="retired" value="1" <?= !empty($d['retired']) ? 'checked' : '' ?>> Retired</label>
          <button class="primary">Save</button>
          <button class="danger" name="remove" value="1" onclick="return confirm('Remove <?= h($d['name']) ?> from the configuration? Its history stays in the database.')">Remove</button>
        </div>
      </form>
<?php if ($d['driver'] === 'me3000'): ?>
      <p class="help">Battery control: <strong><?= ($d['controller'] ?? 'inverter') === 'pi' ? 'Pi, using the BMS' : 'Inverter' ?></strong> ·
        <a href="battery.php?id=<?= h(rawurlencode($d['id'])) ?>#controller">change or view</a></p>
<?php endif; ?>

<?php if (!$imported && !$rs485): ?>
      <div class="dev-sub" id="login-<?= h($d['id']) ?>">
        <h3>Logger web login</h3>
        <p class="help">Login for the logger's own settings page (http://<?= h($d['ip'] ?? '') ?>/). Stored user: <strong><?= h($d['web_user'] ?? 'admin') ?></strong>
          <?= ($d['web_pass'] ?? 'admin') === 'admin' ? ' — <span style="color:var(--bad)">still the factory password</span>' : '' ?>
          <?= !empty($d['web_pass_unverified']) ? ' — <span style="color:var(--warn)">not yet verified</span>' : '' ?>.
          Changing it restarts the logger (data collection pauses for a minute or so).</p>
        <form class="grid" method="post">
          <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="id" value="<?= h($d['id']) ?>">
          <label>New username <input name="new_user" maxlength="15" value="<?= h($d['web_user'] ?? 'admin') ?>" autocomplete="off"></label>
          <label>New password <input name="new_pass" type="password" maxlength="15" autocomplete="new-password"></label>
          <label>Repeat password <input name="new_pass2" type="password" maxlength="15" autocomplete="new-password"></label>
          <div class="actions">
            <button class="primary" name="action" value="logger_change" onclick="return confirm('Change the login on the logger? It will restart. This can take up to 2 minutes.')">Change on logger</button>
            <button name="action" value="logger_store" title="Use if you already changed it on the logger's own page">Store existing login</button>
            <button name="action" value="logger_test" formnovalidate>Test stored login</button>
          </div>
        </form>
      </div>
<?php endif; ?>
    </div>
<?php endforeach; ?>

    <div class="dev add">
      <h3>➕ Add inverter (SOFAR / IGEN logger)</h3>
      <form class="grid" method="post">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="add_device">
        <label>Name <input name="name" required placeholder="SOFAR 5000TLM"></label>
        <label>Model <input name="model"></label>
        <label>Rated power (W) <input name="rated_w" type="number" min="0"></label>
        <label>Logger IP <input name="ip" required placeholder="192.168.1.x"></label>
        <label>Logger serial <input name="serial" inputmode="numeric" required placeholder="from logger status page"></label>
        <div class="actions"><button class="primary">Add</button></div>
      </form>
    </div>

    <div class="dev add">
      <h3>➕ Add battery inverter (SOFAR ME3000SP on RS485)</h3>
      <p class="help">Monitoring only: the logger reads the inverter and never sends it commands. Battery figures are kept out of solar totals, savings and the Going Green page.</p>
      <form class="grid" method="post">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="add_me3000">
        <label>Name <input name="name" required value="SOFAR ME3000SP"></label>
        <label>Model <input name="model" value="ME3000SP"></label>
        <label>Rated power (W) <input name="rated_w" type="number" min="0" value="3000"></label>
        <label>Serial port <input name="port" list="serial-ports" required value="/dev/ttyUSB0"></label>
        <label>RS485 address <input name="slave" type="number" min="1" max="247" required value="1"></label>
        <div class="actions"><button class="primary">Add</button></div>
      </form>
    </div>

    <div class="dev add">
      <h3>➕ Add battery BMS (JBD, on its own RS485 adapter)</h3>
      <p class="help">Monitoring only: the logger reads the BMS (charge, cells, temperatures, protection) and never changes its settings. It needs its own USB adapter, not the battery inverter's.</p>
      <form class="grid" method="post">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="add_jbd">
        <label>Name <input name="name" required value="Battery BMS"></label>
        <label>Model <input name="model" value="JBD BMS"></label>
        <label>Serial port <input name="port" list="serial-ports" required placeholder="/dev/serial/by-path/…"></label>
        <div class="actions"><button class="primary">Add</button></div>
      </form>
    </div>
  </section>

  <section class="card" id="history">
    <div class="section-head"><h2>History</h2></div>
    <table class="events" style="margin-bottom:12px">
      <thead><tr><th>Device</th><th>Id</th><th class="num">Readings</th><th>From</th><th>To</th></tr></thead>
      <tbody>
<?php foreach ($histDevices as $id => $d): $st = $histStats[$id] ?? null; ?>
        <tr><td><?= h($d['name']) ?><?= $d['retired'] ? ' <span class="help">(retired)</span>' : '' ?></td><td class="help"><?= h($id) ?></td>
            <td class="num"><?= $st ? number_format($st['n']) : '0' ?></td>
            <td><?= $st ? h(date('j M Y H:i', $st['first'])) : '—' ?></td><td><?= $st ? h(date('j M Y H:i', $st['last'])) : '—' ?></td></tr>
<?php endforeach; ?>
      </tbody>
    </table>

    <div class="dev">
      <h3>⬇ Import history from another logger</h3>
      <p class="help">For a newly commissioned logger: copy a device's earlier readings from the logger that recorded them.
        Readings are filed under the device you choose here. By default only readings from <em>before</em> this device's
        first reading here are imported, so the two loggers' data don't overlap.</p>
<?php if (!$hist): ?>
      <form class="grid" method="post">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="history_source">
        <label class="wide"><strong>Step 1:</strong> the other logger's address <input name="base" placeholder="type it here, e.g. http://192.168.1.20" required></label>
        <div class="actions"><button class="primary">Load its devices →</button>
          <span class="help">Step 2 then lets you choose which device to copy, where to file it here, and the dates.</span></div>
      </form>
<?php else: ?>
      <form class="grid" method="post">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <p class="help wide" style="margin:0"><strong>Step 2:</strong> choose what to copy. Leave the dates blank to copy everything.</p>
        <label>From device on <?= h($hist['base']) ?>
          <select name="source"><?php foreach ($hist['devices'] as $id => $label): ?><option value="<?= h($id) ?>"><?= h($label) ?></option><?php endforeach; ?></select></label>
        <label>Into device here
          <select name="target"><?php foreach ($histDevices as $id => $d): ?><option value="<?= h($id) ?>"><?= h($d['name']) ?></option><?php endforeach; ?></select></label>
        <label>From date (blank = all) <input type="date" name="from"></label>
        <label>To date (blank = today) <input type="date" name="to"></label>
        <label class="check wide"><input type="checkbox" name="only_before" value="1" checked> Only readings before this device's first reading here</label>
        <div class="actions">
          <button class="primary" name="action" value="history_import_url">Import</button>
          <button name="action" value="history_source_clear" formnovalidate>Change logger</button>
        </div>
      </form>
<?php endif; ?>
      <details style="margin-top:10px"><summary class="help">Or upload a CSV file (CSV export from the other logger's Overview page, max <?= h(ini_get('upload_max_filesize')) ?>)</summary>
        <form class="grid" method="post" enctype="multipart/form-data" style="margin-top:8px">
          <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="history_import_file">
          <label>CSV file <input type="file" name="csv" accept=".csv,text/csv" required></label>
          <label>Source device id in the file (blank = all rows) <input name="source" placeholder="e.g. inv-4000"></label>
          <label>Into device here
            <select name="target"><?php foreach ($histDevices as $id => $d): ?><option value="<?= h($id) ?>"><?= h($d['name']) ?></option><?php endforeach; ?></select></label>
          <label class="check wide"><input type="checkbox" name="only_before" value="1" checked> Only readings before this device's first reading here</label>
          <div class="actions"><button class="primary">Upload and import</button></div>
        </form>
      </details>
    </div>

    <div class="dev">
      <h3>🗑 Clear data</h3>
      <p class="help">Permanently deletes readings and events. Leave both dates blank to delete everything for that device.
        Take a <a href="#backup">backup</a> first if you might want the data back.</p>
      <form class="grid" method="post">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="history_delete">
        <label>Device
          <select name="device" required>
            <option value="">— choose —</option>
<?php foreach ($histDevices as $id => $d): ?>            <option value="<?= h($id) ?>"><?= h($d['name']) ?> (<?= h($id) ?>)</option>
<?php endforeach; ?>            <option value="*">ALL devices</option>
          </select></label>
        <label>From date <input type="date" name="from"></label>
        <label>To date <input type="date" name="to"></label>
        <label class="check wide"><input type="checkbox" name="confirm" value="1"> I understand this permanently deletes the selected data</label>
        <div class="actions"><button class="danger" onclick="return confirm('Permanently delete the selected data?')">Delete data</button></div>
      </form>
    </div>
  </section>

  <section class="card" id="backup">
    <div class="section-head"><h2>Backup</h2></div>
    <p class="help">Download a copy to keep somewhere safe (not on this Pi) in case the SD card fails. The full backup is a
      consistent snapshot taken while logging carries on, plus the settings and a README with restore steps.
      Backups include the inverter loggers' web logins, so store them privately.</p>
    <div class="controls">
      <form method="get" action="admin.php" style="margin:0"><button name="download" value="full" class="primary">⬇ Full backup (history + settings)</button></form>
      <form method="get" action="admin.php" style="margin:0"><button name="download" value="full_sd">⬇ Full backup + original SD-card files</button></form>
      <form method="get" action="admin.php" style="margin:0"><button name="download" value="config">⬇ Settings only</button></form>
    </div>
    <p class="help" style="margin-top:10px">Full backups are <code>.tar.gz</code> files (open with 7-Zip, or Windows 11 File Explorer).
      Database: <?= number_format($dbSize / 1048576, 1) ?> MB<?= $readingCount === null ? '' : ', ' . number_format($readingCount) . ' readings' ?>.
      For spreadsheets, use CSV export on the <a href="../">Overview</a> page instead.</p>

    <div class="dev">
      <h3>☁ Automatic backups to Dropbox
        <?php if ($dbxCreds): ?><span class="badge online">Connected</span><?php else: ?><span class="badge no_data">Not connected</span><?php endif; ?></h3>
<?php if (!$dbxCreds && !$dbxPending): ?>
      <p class="help">One-time setup (about 2 minutes). The Pi only gets access to its own folder in your Dropbox, nothing else.</p>
      <ol class="help" style="padding-left:18px">
        <li>Go to <a href="https://www.dropbox.com/developers/apps" target="_blank" rel="noopener">dropbox.com/developers/apps</a> → <strong>Create app</strong> →
            <strong>Scoped access</strong> → <strong>App folder</strong> → name it, e.g. <em>Solar logger backup</em>.</li>
        <li>On the app's <strong>Permissions</strong> tab tick <code>files.metadata.read</code>, <code>files.content.write</code> and
            <code>files.content.read</code>, then <strong>Submit</strong>.</li>
        <li>On the <strong>Settings</strong> tab copy the <strong>App key</strong> and paste it here.</li>
      </ol>
      <form class="grid" method="post">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="dropbox_start">
        <label>Dropbox App key <input name="app_key" required autocomplete="off" spellcheck="false"></label>
        <div class="actions"><button class="primary">Next →</button></div>
      </form>
<?php elseif (!$dbxCreds && $dbxPending): ?>
      <p class="help"><strong>1.</strong> <a href="<?= h($dbxPending['url']) ?>" target="_blank" rel="noopener">Open Dropbox to allow access</a>
        (sign in, then click <strong>Continue</strong> / <strong>Allow</strong>).<br>
        <strong>2.</strong> Dropbox then shows an access code. Paste it here.</p>
      <form class="grid" method="post">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <label>Access code <input name="code" autocomplete="off" spellcheck="false"></label>
        <div class="actions">
          <button class="primary" name="action" value="dropbox_finish">Connect</button>
          <button name="action" value="dropbox_cancel" formnovalidate>Cancel</button>
        </div>
      </form>
<?php else: ?>
      <form class="grid" method="post">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="backup_settings">
        <label class="check wide"><input type="checkbox" name="dropbox_enabled" value="1" <?= $backup['dropbox_enabled'] ? 'checked' : '' ?>> Automatic backups to Dropbox</label>
        <label>How often
          <select name="frequency">
            <option value="daily" <?= $backup['frequency'] === 'daily' ? 'selected' : '' ?>>Daily (about 2:30am)</option>
            <option value="weekly" <?= $backup['frequency'] === 'weekly' ? 'selected' : '' ?>>Weekly</option>
          </select></label>
        <label>Copies to keep in Dropbox <input name="keep" type="number" min="1" max="100" value="<?= h($backup['keep']) ?>"></label>
        <label class="check"><input type="checkbox" name="include_sources" value="1" <?= $backup['include_sources'] ? 'checked' : '' ?>> Include original source files</label>
        <div class="actions"><button class="primary">Save backup settings</button></div>
      </form>
      <dl class="info" style="margin-top:12px">
        <dt>Last successful</dt><dd><?= !empty($backupStatus['last_success']) ? h($fmtTime($backupStatus['last_success']) . ' — ' . ($backupStatus['last_file'] ?? '') . ' (' . number_format(($backupStatus['last_size'] ?? 0) / 1048576, 1) . ' MB)') : 'never' ?></dd>
<?php if (!empty($backupStatus['last_error'])): ?>
        <dt>Last attempt</dt><dd style="color:var(--bad)"><?= h($fmtTime($backupStatus['last_attempt'] ?? null)) ?> — <?= h($backupStatus['last_error']) ?></dd>
<?php endif; ?>
        <dt>Saved to</dt><dd>Dropbox → Apps → <em>(your app's name)</em></dd>
      </dl>
      <form method="post" class="controls" style="margin-top:10px">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <button name="action" value="backup_now">⬆ Back up now</button>
        <button name="action" value="dropbox_disconnect" class="danger" onclick="return confirm('Disconnect Dropbox and turn off automatic backups? Backups already in Dropbox are kept.')">Disconnect Dropbox</button>
      </form>
<?php endif; ?>
    </div>
  </section>

  <section class="card" id="admin">
    <div class="section-head"><h2>Admin login</h2></div>
    <p class="help">Login for this admin page (user <strong><?= h($adminUser) ?></strong>).</p>
    <form class="grid" method="post">
      <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="admin_password">
      <label>Current password <input name="current" type="password" required autocomplete="current-password"></label>
      <label>New password <input name="new" type="password" minlength="8" required autocomplete="new-password"></label>
      <label>Repeat new password <input name="new2" type="password" minlength="8" required autocomplete="new-password"></label>
      <div class="actions"><button class="primary">Change admin password</button></div>
    </form>
  </section>
</main>
</body>
</html>
