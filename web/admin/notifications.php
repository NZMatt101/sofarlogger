<?php
// SMS alerts when equipment stops responding. Settings live in NOTIFY_FILE (read by collector/notifier.py,
// which reloads it within 30 s of a change). Secrets are never sent back to the browser: a blank secret
// field keeps the stored one. Protected by Apache basic auth like the rest of admin/.
require __DIR__ . '/../inc/common.php';
require __DIR__ . '/../inc/dropbox.php';   // json_file_read / json_file_write / http_request

const NOTIFY_FILE = '/etc/solar-logger/notify.json';
const NOTIFY_STATE = '/var/lib/solar-logger/notify-state.json';
const MAX_CHECKS = 5;
const NOTIFY_DEFAULTS = [
    'enabled' => false, 'prefix' => 'Solar Logger', 'down_after_s' => 180, 'solar_down_after_s' => 900, 'remind_hours' => 6,
    'max_sms_per_day' => 20, 'http_checks' => [], 'heartbeat_url' => null,
    'smsglobal' => ['api' => 'rest', 'api_key' => '', 'api_secret' => '', 'user' => '', 'password' => '', 'from' => '', 'to' => []],
];

session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function flash($type, $msg) { $_SESSION['flash'][] = [$type, $msg]; }

function notify_settings() {
    $n = json_file_read(NOTIFY_FILE) ?? [];
    $s = array_merge(NOTIFY_DEFAULTS['smsglobal'], $n['smsglobal'] ?? []);
    $n = array_merge(NOTIFY_DEFAULTS, $n);
    $n['smsglobal'] = $s;
    return $n;
}

function int_in($v, $min, $max, $what) {
    $i = filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
    if ($i === false) throw new InvalidArgumentException("$what must be a whole number from $min to $max.");
    return $i;
}

function clean_number($n) { return preg_replace('/[\s()+-]/', '', (string)$n); }

// Send one SMS through SMSGlobal. Returns a short description of the reply; throws on failure.
function sms_send($s, $to, $text) {
    if (($s['api'] ?? 'rest') === 'http') {
        $q = http_build_query(['action' => 'sendsms', 'user' => $s['user'], 'password' => $s['password'],
                               'to' => $to, 'text' => $text] + ($s['from'] !== '' ? ['from' => $s['from']] : []));
        $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true]]);
        $body = @file_get_contents('https://api.smsglobal.com/http-api.php?' . $q, false, $ctx);
        if ($body === false) throw new RuntimeException('could not reach SMSGlobal (no internet?)');
        $body = trim($body);
        if (!str_starts_with($body, 'OK')) throw new RuntimeException('SMSGlobal said: ' . substr($body, 0, 200));
        return substr($body, 0, 120);
    }
    // Path signed and requested must match exactly (SMSGlobal's example signs "/v2/sms", no trailing slash).
    $path = '/v2/sms';
    $ts = (string)time();
    $nonce = bin2hex(random_bytes(16));
    $signing = implode("\n", [$ts, $nonce, 'POST', $path, 'api.smsglobal.com', '443', '']) . "\n";
    $mac = base64_encode(hash_hmac('sha256', $signing, trim($s['api_secret']), true));
    $headers = [
        'Authorization: MAC id="' . trim($s['api_key']) . "\", ts=\"$ts\", nonce=\"$nonce\", mac=\"$mac\"",
        'Content-Type: application/json', 'Accept: application/json', 'User-Agent: solar-logger',
    ];
    // Don't follow redirects: a redirect would drop the Authorization header and hide the real problem.
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'header' => implode("\r\n", $headers) . "\r\n",
        'content' => json_encode(['destination' => $to, 'message' => $text] + ($s['from'] !== '' ? ['origin' => $s['from']] : [])),
        'timeout' => 20, 'ignore_errors' => true, 'follow_location' => 0,
    ]]);
    $body = @file_get_contents('https://api.smsglobal.com' . $path, false, $ctx);
    $hdrs = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
    $status = (isset($hdrs[0]) && preg_match('#\s(\d{3})(\s|$)#', $hdrs[0], $m)) ? (int)$m[1] : 0;
    if (!$status) throw new RuntimeException('could not reach SMSGlobal (no internet?)');
    if ($status >= 300 && $status < 400) {
        $loc = '';
        foreach ($hdrs as $hd) if (stripos($hd, 'Location:') === 0) $loc = trim(substr($hd, 9));
        throw new RuntimeException("SMSGlobal redirected (HTTP $status) to $loc; please report this.");
    }
    if ($status < 200 || $status >= 300) throw new RuntimeException("SMSGlobal replied HTTP $status: " . substr((string)$body, 0, 200));
    return "HTTP $status";
}

$cfg = load_config();
$siteName = $cfg['site']['name'] ?? 'Solar';
$n = notify_settings();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        flash('err', 'Form expired, please try again.');
    } else {
        try {
            $action = $_POST['action'] ?? '';
            if ($action === 'save') {
                $s = $n['smsglobal'];
                $s['api'] = ($_POST['api'] ?? 'rest') === 'http' ? 'http' : 'rest';
                $s['api_key'] = trim((string)($_POST['api_key'] ?? ''));
                if (trim((string)($_POST['api_secret'] ?? '')) !== '') $s['api_secret'] = trim($_POST['api_secret']);
                $s['user'] = trim((string)($_POST['user'] ?? ''));
                if ((string)($_POST['password'] ?? '') !== '') $s['password'] = (string)$_POST['password'];
                if (!empty($_POST['clear_secrets'])) { $s['api_secret'] = ''; $s['password'] = ''; }

                $from = trim((string)($_POST['from'] ?? ''));
                $fromNum = clean_number($from);
                if ($from !== '' && ctype_digit($fromNum)) {
                    if (!preg_match('/^\d{6,15}$/', $fromNum)) throw new InvalidArgumentException('Sender number must be international form without +, e.g. 6421234567.');
                    $from = $fromNum;
                } elseif ($from !== '' && !preg_match('/^[A-Za-z0-9 ]{1,11}$/', $from)) {
                    throw new InvalidArgumentException('Sender name must be up to 11 letters and digits.');
                }
                $s['from'] = $from;

                $to = [];
                foreach (preg_split('/[,;\r\n]+/', (string)($_POST['to'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $num) {
                    $num = clean_number($num);
                    if ($num === '') continue;
                    if (!preg_match('/^\d{8,15}$/', $num))
                        throw new InvalidArgumentException("\"$num\" isn't a phone number in international form, e.g. 6421234567.");
                    if (str_starts_with($num, '0'))
                        throw new InvalidArgumentException("Write $num in international form without the leading 0, e.g. 64 21 ... for a NZ mobile.");
                    $to[] = $num;
                }
                $to = array_values(array_unique($to));

                $checks = [];
                foreach (array_slice((array)($_POST['check'] ?? []), 0, MAX_CHECKS) as $i => $c) {
                    $name = trim((string)($c['name'] ?? ''));
                    $url = trim((string)($c['url'] ?? ''));
                    if ($name === '' && $url === '') continue;
                    if (!preg_match('#^https?://[^\s]+$#', $url)) throw new InvalidArgumentException('Check ' . ($i + 1) . ': the address must start with http:// or https://.');
                    if ($name === '') $name = parse_url($url, PHP_URL_HOST) ?: 'Device';
                    $checks[] = ['name' => substr($name, 0, 40), 'url' => $url,
                                 'down_after_s' => 60 * int_in($c['min'] ?? 5, 1, 120, 'Check ' . ($i + 1) . ' minutes')];
                }
                $hb = trim((string)($_POST['heartbeat_url'] ?? ''));
                if ($hb !== '' && !preg_match('#^https://[^\s]+$#', $hb)) throw new InvalidArgumentException('The heartbeat address must start with https://.');

                $enabled = !empty($_POST['enabled']);
                if ($enabled) {
                    if (!$to) throw new InvalidArgumentException('Add at least one phone number before switching alerts on.');
                    if ($s['api'] === 'rest' && ($s['api_key'] === '' || $s['api_secret'] === ''))
                        throw new InvalidArgumentException('Add the SMSGlobal API key and secret before switching alerts on.');
                    if ($s['api'] === 'http' && ($s['user'] === '' || $s['password'] === ''))
                        throw new InvalidArgumentException('Add the SMSGlobal username and password before switching alerts on.');
                }

                $new = array_merge($n, [
                    'enabled' => $enabled,
                    'down_after_s' => 60 * int_in($_POST['down_min'] ?? 3, 1, 60, 'Battery delay'),
                    'solar_down_after_s' => 60 * int_in($_POST['solar_min'] ?? 15, 5, 240, 'Solar delay'),
                    'remind_hours' => int_in($_POST['remind_hours'] ?? 6, 0, 48, 'Reminder hours'),
                    'max_sms_per_day' => int_in($_POST['max_sms'] ?? 20, 1, 100, 'Daily limit'),
                    'http_checks' => $checks,
                    'heartbeat_url' => $hb === '' ? null : $hb,
                    'prefix' => preg_match('/^.{1,30}/su', trim((string)($_POST['prefix'] ?? '')), $pm) ? $pm[0] : 'Solar Logger',
                ]);
                $s['to'] = $to;
                $new['smsglobal'] = $s;
                json_file_write(NOTIFY_FILE, $new);
                flash('ok', 'Notification settings saved. The notifier picks them up within 30 seconds.'
                    . ($enabled ? '' : ' Alerts are switched off.'));
            } elseif ($action === 'test') {
                $s = $n['smsglobal'];
                if (!$s['to']) throw new InvalidArgumentException('Save at least one phone number first.');
                $replies = [];
                foreach ($s['to'] as $num)
                    $replies[] = '…' . substr($num, -3) . ': ' . sms_send($s, $num, ($n['prefix'] ?: $siteName) . ': test message. Alerts are working.');
                flash('ok', 'Test SMS sent (' . implode('; ', $replies) . '). Check your phone.');
            }
        } catch (Throwable $e) {
            flash('err', $e->getMessage());
        }
    }
    header('Location: notifications.php');
    exit;
}

$flashes = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);
$csrf = h($_SESSION['csrf']);
$s = $n['smsglobal'];
$state = json_file_read(NOTIFY_STATE) ?? [];
$alive = (int)($state['alive'] ?? 0);
$problems = array_filter($state['items'] ?? [], fn($it) => !empty($it['first_bad']));
$log = array_reverse($state['log'] ?? []);
$checks = $n['http_checks'];
while (count($checks) < MAX_CHECKS) $checks[] = ['name' => '', 'url' => '', 'down_after_s' => 300];
$ago = function ($ts) {
    $m = intdiv(max(0, time() - $ts), 60);
    return $m < 60 ? "$m min ago" : intdiv($m, 60) . ' h ' . ($m % 60) . ' min ago';
};
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Notifications · Admin</title>
<link rel="stylesheet" href="../style.css?v=3">
<style>
  .flash { margin: 0 0 12px; padding: 10px 14px; border-radius: 8px; border: 1px solid var(--line); }
  .flash.ok { border-color: var(--good, #1f9d55); }
  .flash.err { border-color: var(--bad, #c0392b); color: var(--bad, #c0392b); }
  .help { color: var(--muted); font-size: 13px; }
  .warn { color: var(--warn, #b7791f); }
  .bad { color: var(--bad, #c0392b); }
  .good { color: var(--good, #1f9d55); }
  .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(240px, 100%), 1fr)); gap: 12px 16px; }
  .form-grid label, .stack label { display: grid; gap: 4px; font-size: 13px; color: var(--muted); align-content: start; }
  .form-grid input, .form-grid select, .form-grid textarea, .stack input { color: var(--text); width: 100%; box-sizing: border-box; }
  textarea { min-height: 60px; font: inherit; }
  .row-check { display: flex; align-items: center; gap: 8px; font-size: 14px; color: var(--text); }
  .table-scroll { overflow-x: auto; }
  table.simple { border-collapse: collapse; width: 100%; }
  table.simple th, table.simple td { padding: 6px; border-bottom: 1px solid var(--line); text-align: left; font-size: 13px; vertical-align: top; }
  table.simple th { color: var(--muted); font-weight: 600; }
  table.simple input { width: 100%; box-sizing: border-box; color: var(--text); }
  .actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 14px; }
  .api-rest, .api-http { display: contents; }
</style>
</head>
<body>
<header>
  <div>
    <div class="crumbs"><a href="admin.php">← Admin</a></div>
    <h1>📱 Notifications</h1>
  </div>
  <nav class="top"><a href="admin.php#equipment">Equipment</a> <a href="../guide.html">📘 Guide</a></nav>
</header>
<main>
<?php foreach ($flashes as [$type, $msg]): ?>
  <div class="flash <?= h($type) ?>"><?= h($msg) ?></div>
<?php endforeach; ?>

  <section class="card">
    <div class="section-head"><h2>Status</h2></div>
<?php if (!$alive): ?>
    <p class="warn">The notifier service hasn't reported yet. If this stays after a few minutes, it isn't installed or isn't running (<code>solar-notify</code>).</p>
<?php elseif (time() - $alive > 1500): ?>
    <p class="bad"><strong>The notifier hasn't checked in for <?= h($ago($alive)) ?>.</strong> The <code>solar-notify</code> service may have stopped.</p>
<?php else: ?>
    <p>Notifier running, last checked in <?= h($ago($alive)) ?>. Alerts are <strong class="<?= $n['enabled'] ? 'good' : 'warn' ?>"><?= $n['enabled'] ? 'on' : 'off' ?></strong>.</p>
<?php endif; ?>
<?php if ($problems): ?>
    <p><strong>Not responding now:</strong></p>
    <ul>
<?php foreach ($problems as $it): ?>
      <li><?= h($it['name'] ?? 'Device') ?> since <?= h(date('D H:i', (int)$it['first_bad'])) ?><?= !empty($it['alerted']) ? ' (SMS sent)' : '' ?></li>
<?php endforeach; ?>
    </ul>
<?php elseif ($alive): ?>
    <p class="good">Everything checked is responding.</p>
<?php endif; ?>
  </section>

  <form method="post" class="card" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <div class="section-head"><h2>SMS settings</h2></div>
    <p class="help">Texts are sent through your <a href="https://www.smsglobal.com" rel="noopener">SMSGlobal</a> account.
      REST uses an API key (MXT → Settings → API Keys) and is preferred; HTTP uses your account username and password.
      Saved secrets are never shown again: leave a secret box empty to keep the stored one.</p>

    <p><label class="row-check"><input type="checkbox" name="enabled" value="1" <?= $n['enabled'] ? 'checked' : '' ?>> Send SMS alerts</label></p>

    <div class="form-grid">
      <label>Connection
        <select name="api" id="api">
          <option value="rest" <?= $s['api'] === 'rest' ? 'selected' : '' ?>>REST API (API key + secret)</option>
          <option value="http" <?= $s['api'] === 'http' ? 'selected' : '' ?>>HTTP API (username + password)</option>
        </select>
      </label>
      <label class="api-rest-f">API key <input name="api_key" value="<?= h($s['api_key']) ?>" spellcheck="false"></label>
      <label class="api-rest-f">API secret <input type="password" name="api_secret" placeholder="<?= $s['api_secret'] !== '' ? 'saved (leave blank to keep)' : 'not set' ?>" autocomplete="new-password"></label>
      <label class="api-http-f">Username <input name="user" value="<?= h($s['user']) ?>" spellcheck="false"></label>
      <label class="api-http-f">Password <input type="password" name="password" placeholder="<?= $s['password'] !== '' ? 'saved (leave blank to keep)' : 'not set' ?>" autocomplete="new-password"></label>
      <label>Sender (leave blank for SMSGlobal's default number) <input name="from" value="<?= h($s['from']) ?>" placeholder="blank = default" spellcheck="false"></label>
      <label>Send to (international, no +; several separated by commas)
        <textarea name="to" placeholder="6421234567"><?= h(implode(', ', $s['to'])) ?></textarea>
      </label>
    </div>

    <div class="form-grid" style="margin-top:12px">
      <label>Message starts with <input name="prefix" maxlength="30" value="<?= h($n['prefix']) ?>" placeholder="Solar Logger"></label>
    </div>
    <p class="help">Example: <em><?= h($n['prefix'] ?: 'Solar Logger') ?>: SOFAR ME3000SP not responding since 20:10 (12 min). Last: discharging, 3290 W.</em></p>

    <h3>When to alert</h3>
    <div class="form-grid">
      <label>Battery inverter, BMS: after (minutes)
        <input type="number" name="down_min" min="1" max="60" value="<?= (int)round($n['down_after_s'] / 60) ?>"></label>
      <label>Solar inverter (daylight only): after (minutes)
        <input type="number" name="solar_min" min="5" max="240" value="<?= (int)round($n['solar_down_after_s'] / 60) ?>"></label>
      <label>Remind every (hours, 0 = never)
        <input type="number" name="remind_hours" min="0" max="48" value="<?= (int)$n['remind_hours'] ?>"></label>
      <label>At most (SMS per day)
        <input type="number" name="max_sms" min="1" max="100" value="<?= (int)$n['max_sms_per_day'] ?>"></label>
    </div>
    <p class="help">All enabled equipment is watched automatically. You get one text when something stops responding and one when it's back.
      The solar inverter is only expected while the sun is up.</p>

    <h3>Other devices to watch</h3>
    <p class="help">Web addresses that should always answer, for example the gateway Pi's status page.</p>
    <div class="table-scroll">
      <table class="simple">
        <thead><tr><th style="width:28%">Name</th><th>Address</th><th style="width:110px">Alert after (min)</th></tr></thead>
        <tbody>
<?php foreach ($checks as $i => $c): ?>
          <tr>
            <td><input name="check[<?= $i ?>][name]" value="<?= h($c['name']) ?>" placeholder="<?= $i === 0 ? 'Gateway Pi' : '' ?>"></td>
            <td><input name="check[<?= $i ?>][url]" value="<?= h($c['url']) ?>" placeholder="<?= $i === 0 ? 'http://192.168.1.60/api/status' : '' ?>" spellcheck="false"></td>
            <td><input type="number" name="check[<?= $i ?>][min]" min="1" max="120" value="<?= (int)round(($c['down_after_s'] ?? 300) / 60) ?>"></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <h3>If this Pi itself goes down</h3>
    <div class="stack">
      <label>Heartbeat address (optional) <input name="heartbeat_url" value="<?= h($n['heartbeat_url'] ?? '') ?>" placeholder="https://hc-ping.com/your-check-id" spellcheck="false"></label>
    </div>
    <p class="help">The logger can't text you if it has lost power or internet. A free <a href="https://healthchecks.io" rel="noopener">healthchecks.io</a>
      check fixes that: the notifier visits this address every 5 minutes, and healthchecks.io alerts you when the visits stop.</p>

    <div class="actions">
      <button type="submit" name="action" value="save">Save</button>
      <label class="row-check" style="font-size:13px;color:var(--muted)"><input type="checkbox" name="clear_secrets" value="1"> Remove saved secret / password</label>
    </div>
  </form>

  <form method="post" class="card">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <div class="section-head"><h2>Test</h2></div>
    <p class="help">Sends one text to every number above using the <em>saved</em> settings (save first), and shows SMSGlobal's reply.
      If REST is refused, switch the connection to HTTP and try again.</p>
    <button type="submit" name="action" value="test">Send test SMS</button>
  </form>

  <section class="card">
    <div class="section-head"><h2>Recent alerts</h2></div>
<?php if (!$log): ?>
    <p class="help">No alerts sent yet.</p>
<?php else: ?>
    <div class="table-scroll">
      <table class="simple">
        <thead><tr><th style="width:120px">When</th><th>Message</th><th style="width:80px">Result</th></tr></thead>
        <tbody>
<?php foreach ($log as $e): ?>
          <tr><td><?= h(date('D j M H:i', (int)$e['ts'])) ?></td><td><?= h($e['text']) ?></td>
            <td class="<?= $e['ok'] ? 'good' : 'bad' ?>" title="<?= h($e['error'] ?? '') ?>"><?= $e['ok'] ? 'sent' : 'failed' ?></td></tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>
<?php endif; ?>
  </section>
</main>
<script>
  // show only the credential fields for the chosen connection
  const api = document.getElementById('api');
  const sync = () => {
    document.querySelectorAll('.api-rest-f').forEach(e => e.style.display = api.value === 'rest' ? '' : 'none');
    document.querySelectorAll('.api-http-f').forEach(e => e.style.display = api.value === 'http' ? '' : 'none');
  };
  api.addEventListener('change', sync); sync();
</script>
</body>
</html>
