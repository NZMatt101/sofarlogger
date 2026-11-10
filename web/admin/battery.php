<?php
// ME3000SP battery page: live values, manual control and the inverter's settings. Protected by Apache basic auth.
// This page never touches the serial port. It queues a command and the poller sends it (see Me3000Control).
require __DIR__ . '/../inc/common.php';
require __DIR__ . '/../inc/history.php';

const MAX_WATTS = 3000;
const MAX_MINUTES = 240;
const DEFAULT_MIN_DISCHARGE_V = 50.0;   // same defaults as poller.py
const DEFAULT_MAX_CHARGE_V = 53.5;
const PI_DEFAULTS = ['floor_pct' => 20, 'ceiling_pct' => 95, 'full_every_days' => 7, 'full_spread_mv' => 30];  // poller.py PI_DEFAULTS

session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function flash($type, $msg) { $_SESSION['flash'][] = [$type, $msg]; }

$id = $_GET['id'] ?? '';
$cfg = load_config();
$index = null;
foreach ($cfg['devices'] as $i => $d) if ($d['id'] === $id && $d['driver'] === 'me3000') $index = $i;
if ($index === null) { http_response_code(404); echo 'Unknown battery device.'; exit; }
$dev = $cfg['devices'][$index];
$self = 'battery.php?id=' . rawurlencode($id);
$floor = (float)($dev['min_discharge_v'] ?? DEFAULT_MIN_DISCHARGE_V);
$ceiling = (float)($dev['max_charge_v'] ?? DEFAULT_MAX_CHARGE_V);
$piMode = ($dev['controller'] ?? 'inverter') === 'pi';
$pi = array_merge(PI_DEFAULTS, $dev['pi_control'] ?? []);
$bmsDevices = array_values(array_filter($cfg['devices'], fn($d) => $d['driver'] === 'jbd' && empty($d['retired'])));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        flash('err', 'Form expired, please try again.');
    } else {
        try {
            if (($_POST['action'] ?? '') === 'controller') {
                $mode = $_POST['controller'] ?? '';
                if (!in_array($mode, ['inverter', 'pi'], true)) throw new InvalidArgumentException('Unknown controller.');
                $int = fn($k, $min, $max, $label) => ($v = filter_var($_POST[$k] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]])) === false
                    ? throw new InvalidArgumentException("$label must be $min to $max.") : $v;
                $new = ['floor_pct' => $int('floor_pct', 10, 50, 'Floor'), 'ceiling_pct' => $int('ceiling_pct', 60, 100, 'Ceiling'),
                        'full_every_days' => $int('full_every_days', 0, 30, 'Full charge interval'),
                        'full_spread_mv' => $int('full_spread_mv', 10, 200, 'Cell spread')];
                if ($new['floor_pct'] > $new['ceiling_pct'] - 20) throw new InvalidArgumentException('Keep the floor at least 20% below the ceiling.');
                $bms = $_POST['bms'] ?? '';
                if ($bmsDevices && !in_array($bms, array_column($bmsDevices, 'id'), true)) throw new InvalidArgumentException('Choose the battery BMS to follow.');
                if ($mode === 'pi' && !$bmsDevices) throw new InvalidArgumentException('Pi control needs a battery BMS in the equipment list.');
                $new['bms'] = $bms;
                $cfg['devices'][$index]['controller'] = $mode;
                $cfg['devices'][$index]['pi_control'] = $new;
                save_config($cfg);
                flash('ok', $mode === 'pi'
                    ? ($piMode ? 'Pi control settings saved.' : 'Pi control switched on. Make sure the inverter screen is in Passive mode, otherwise its commands are refused.')
                    : ($piMode ? 'Inverter control switched back on. Now set Work Mode to Auto on the inverter screen.' : 'Settings saved.'));
            } elseif (($_POST['action'] ?? '') === 'limits') {
                $f = filter_var($_POST['min_discharge_v'] ?? '', FILTER_VALIDATE_FLOAT);
                $c = filter_var($_POST['max_charge_v'] ?? '', FILTER_VALIDATE_FLOAT);
                if ($f === false || $f < 46 || $f > 53) throw new InvalidArgumentException('Stop discharging must be 46 to 53 V.');
                if ($c === false || $c < 50 || $c > 56) throw new InvalidArgumentException('Stop charging must be 50 to 56 V.');
                if ($f >= $c) throw new InvalidArgumentException('Stop discharging must be below stop charging.');
                $cfg['devices'][$index]['min_discharge_v'] = $f;
                $cfg['devices'][$index]['max_charge_v'] = $c;
                save_config($cfg);
                flash('ok', 'Limits saved. They apply to manual charge and discharge runs within a minute.');
            } else {
                $action = $_POST['command'] ?? '';
                if (!in_array($action, ['auto', 'standby', 'charge', 'discharge'], true)) throw new InvalidArgumentException('Unknown command.');
                $watts = $minutes = null;
                if ($action === 'charge' || $action === 'discharge') {
                    $watts = filter_var($_POST['watts'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 100, 'max_range' => MAX_WATTS]]);
                    $minutes = filter_var($_POST['minutes'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => MAX_MINUTES]]);
                    if ($watts === false) throw new InvalidArgumentException('Power must be 100 to ' . MAX_WATTS . ' W.');
                    if ($minutes === false) throw new InvalidArgumentException('Time limit must be 1 to ' . MAX_MINUTES . ' minutes.');
                }
                $db = db_rw();
                $st = $db->prepare('INSERT INTO control_commands(device, ts, action, watts, minutes) VALUES (:d, :t, :a, :w, :m)');
                $st->bindValue(':d', $id);
                $st->bindValue(':t', time(), SQLITE3_INTEGER);
                $st->bindValue(':a', $action);
                $st->bindValue(':w', $watts, $watts === null ? SQLITE3_NULL : SQLITE3_INTEGER);
                $st->bindValue(':m', $minutes, $minutes === null ? SQLITE3_NULL : SQLITE3_INTEGER);
                $st->execute();
                $cmd = $db->lastInsertRowID();
                // The poller picks it up within a second or so, longer if it is mid-way through polling a device.
                $res = null;
                for ($n = 0; $n < 50; $n++) {
                    usleep(500000);
                    $res = $db->querySingle("SELECT status, detail FROM control_commands WHERE id = $cmd", true);
                    if ($res && $res['status'] !== 'pending') break;
                }
                $db->close();
                if ($res && $res['status'] === 'accepted') flash('ok', $res['detail']);
                elseif ($res && $res['status'] !== 'pending') flash('err', $res['detail'] ?: 'The command failed.');
                else flash('err', 'No answer from the poller yet. Check Recent commands below before trying again.');
            }
        } catch (Exception $e) {
            flash('err', $e->getMessage());
        }
    }
    header("Location: $self");
    exit;
}

$flashes = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);
$csrf = h($_SESSION['csrf']);

$live = $settings = $state = $piState = $bmsLive = null;
$liveTs = null;
$commands = $controlEvents = [];
$dbError = null;
try {
    $db = db();
    $st = $db->prepare('SELECT ts, live, settings FROM device_snapshot WHERE device = :d');
    $st->bindValue(':d', $id);
    if ($snap = rows($st)[0] ?? null) {
        $liveTs = $snap['ts'];
        $live = json_decode($snap['live'], true);
        $settings = json_decode((string)$snap['settings'], true);
    }
    $st = $db->prepare('SELECT mode, watts, started_ts, until_ts, stopping FROM control_state WHERE device = :d');
    $st->bindValue(':d', $id);
    $state = rows($st)[0] ?? null;
    $st = $db->prepare('SELECT ts, action, watts, minutes, status, detail FROM control_commands WHERE device = :d ORDER BY id DESC LIMIT 10');
    $st->bindValue(':d', $id);
    $commands = rows($st);
    if ($db->querySingle("SELECT 1 FROM sqlite_master WHERE name = 'pi_control'")) {
        $st = $db->prepare('SELECT * FROM pi_control WHERE device = :d');
        $st->bindValue(':d', $id);
        $piState = rows($st)[0] ?? null;
    }
    $bmsId = $pi['bms'] ?? ($bmsDevices[0]['id'] ?? null);
    if ($bmsId) {
        $st = $db->prepare('SELECT ts, live FROM device_snapshot WHERE device = :d');
        $st->bindValue(':d', $bmsId);
        if ($b = rows($st)[0] ?? null) $bmsLive = json_decode($b['live'], true) + ['ts' => $b['ts']];
    }
    $st = $db->prepare("SELECT ts, code FROM events WHERE device = :d AND source = 'control' ORDER BY ts DESC LIMIT 8");
    $st->bindValue(':d', $id);
    $controlEvents = rows($st);
    $db->close();
} catch (Exception $e) {
    $dbError = $e->getMessage();
}
$stale = !$liveTs || time() - $liveTs > 180;
$val = fn($k, $unit = '') => isset($live[$k]) ? h($live[$k]) . ($unit ? " $unit" : '') : '—';
$estimated = ($settings['battery_type'] ?? '') === 'DEFAULT';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Battery control · <?= h($dev['name']) ?></title>
<link rel="stylesheet" href="../style.css?v=3">
<style>
  form.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px 14px; align-items: end; }
  form.grid label { display: grid; gap: 3px; font-size: 13px; color: var(--muted); }
  form.grid input, form.grid select { width: 100%; color: var(--text); }
  .flash { margin: 0 0 12px; padding: 10px 14px; border-radius: 8px; border: 1px solid var(--line); }
  .flash.ok { border-color: var(--good, #1f9d55); }
  .flash.err { border-color: var(--bad, #c0392b); color: var(--bad, #c0392b); }
  .help { color: var(--muted); font-size: 13px; }
  .warn { color: var(--warn, #b7791f); }
  .row { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 14px; }
  .run { border: 1px solid var(--line); border-radius: 8px; padding: 12px; margin-top: 12px; }
  .run h3 { margin: 0 0 8px; font-size: 15px; }
  .table-scroll { overflow-x: auto; }
</style>
</head>
<body>
<header>
  <div>
    <div class="crumbs"><a href="../device.html?id=<?= h(rawurlencode($id)) ?>">← <?= h($dev['name']) ?></a></div>
    <h1>🔋 Battery control</h1>
  </div>
  <nav class="top"><a href="tou.php?id=<?= h(rawurlencode($id)) ?>">Time-of-use</a> <a href="admin.php#dev-<?= h($id) ?>">Equipment settings</a></nav>
</header>
<main>
<?php foreach ($flashes as [$type, $msg]): ?>
  <div class="flash <?= h($type) ?>"><?= h($msg) ?></div>
<?php endforeach; ?>
<?php if ($dbError): ?>
  <div class="flash err">Database not ready: <?= h($dbError) ?></div>
<?php endif; ?>

  <section class="card">
    <div class="section-head"><h2>Now</h2><span class="updated"><?= $liveTs ? 'Read ' . h(date('H:i:s', $liveTs)) : 'No readings yet' ?></span></div>
<?php if ($stale): ?>
    <p class="help warn">These figures are out of date: the logger hasn't had a recent reading from the inverter.</p>
<?php endif; ?>
    <div class="metrics">
      <div class="metric"><div class="k">State</div><div class="v"><?= $val('state') ?></div></div>
      <div class="metric"><div class="k">Battery voltage</div><div class="v"><?= $val('batt_v', 'V') ?></div></div>
      <div class="metric"><div class="k">Battery power (+ charge / − discharge)</div><div class="v"><?= $val('batt_power_w', 'W') ?></div></div>
      <div class="metric"><div class="k">Battery current</div><div class="v"><?= $val('batt_a', 'A') ?></div></div>
      <div class="metric"><div class="k">Battery charge (BMS)</div><div class="v"><?= $bmsLive ? h($bmsLive['soc']) . ' %' : '—' ?></div></div>
      <div class="metric"><div class="k">Battery charge (inverter<?= $estimated ? ' estimate' : '' ?>)</div><div class="v"><?= $val('soc', '%') ?></div></div>
      <div class="metric"><div class="k">House load</div><div class="v"><?= $val('load_w', 'W') ?></div></div>
      <div class="metric"><div class="k">Grid (+ export / − import)</div><div class="v"><?= $val('grid_w', 'W') ?></div></div>
      <div class="metric"><div class="k">Faults</div><div class="v"><?= $val('faults') ?></div></div>
    </div>
<?php if ($estimated): ?>
    <p class="help warn">Battery type is DEFAULT, so the inverter estimates charge from voltage and it can read far too high. The BMS figure is the true charge level<?= $bmsLive ? '' : ' (add the battery BMS in Admin → Equipment to show it here)' ?>.</p>
<?php endif; ?>
  </section>

  <section class="card" id="controller">
    <div class="section-head"><h2>Who controls the battery</h2>
      <span class="badge <?= $piMode ? (($piState['problem'] ?? null) ? 'error' : 'online') : 'night' ?>"><?= $piMode ? 'Pi (uses the BMS)' : 'Inverter' ?></span></div>
<?php if ($piMode): ?>
<?php if ($piState): ?>
<?php if ($piState['problem']): ?>
    <p class="warn"><strong>Problem:</strong> <?= h($piState['problem']) ?></p>
<?php endif; ?>
    <dl class="info">
      <dt>Inverter told to</dt><dd><?= $piState['sent'] ? h($piState['sent'] === 'auto' ? 'Auto (follows the house load)'
          : ($piState['sent'] === 'standby' ? 'Standby' : 'Charge from the grid at ' . (int)substr($piState['sent'], 7) . ' W'))
          . ' since ' . h(date('H:i', $piState['sent_ts'])) : '—' ?></dd>
      <dt>Why</dt><dd><?= h($piState['reason'] ?? '—') ?></dd>
      <dt>Charging up to</dt><dd><?= $piState['target_pct'] ? h($piState['target_pct']) . '%' . ($piState['target_pct'] == 100 ? ' (full charge, to recalibrate the BMS and balance the cells)' : '') : '—' ?></dd>
      <dt>Last full charge</dt><dd><?= $piState['last_full_ts'] ? h(date('j M H:i', $piState['last_full_ts'])) : 'not seen yet' ?></dd>
      <dt>Last decision</dt><dd><?= $piState['ts'] ? h(date('H:i:s', $piState['ts'])) . (time() - $piState['ts'] > 120 ? ' <span class="warn">(the poller has stopped deciding)</span>' : '') : '—' ?></dd>
    </dl>
<?php else: ?>
    <p class="help">Starting up: the poller takes over within a minute.</p>
<?php endif; ?>
<?php endif; ?>
<?php if ($bmsLive): ?>
    <p class="help">BMS now: <strong><?= h($bmsLive['soc']) ?>%</strong> · <?= h($bmsLive['batt_v']) ?> V · <?= h($bmsLive['batt_a']) ?> A · cells <?= h($bmsLive['cell_min_v']) ?>–<?= h($bmsLive['cell_max_v']) ?> V · protection <?= h($bmsLive['faults']) ?> (read <?= h(date('H:i:s', $bmsLive['ts'])) ?>)</p>
<?php endif; ?>
    <p class="help">
      <strong>Inverter:</strong> the inverter runs itself (screen Work Mode = Auto) and judges charge from voltage, which on this battery type stops discharge early and can latch a low-battery alarm.<br>
      <strong>Pi:</strong> the inverter screen must be in <strong>Passive mode</strong>. The Pi uses the BMS's charge level: normally it lets the inverter follow the house load; at the floor it only charges from spare solar; at the ceiling it only discharges. If the BMS reading is missing or the BMS reports a protection, it puts the inverter in standby. If the Pi stops altogether, the inverter keeps its last mode and its own voltage limits still apply, so keep them set as a backstop.
    </p>
<?php $touOn = count(array_filter($dev['tou'] ?? [], fn($s) => !empty($s['on']))); ?>
    <p><a href="tou.php?id=<?= h(rawurlencode($id)) ?>">🕒 Time-of-use schedule</a>
      <span class="help">: <?= $touOn ? h($touOn) . ' slot' . ($touOn === 1 ? '' : 's') . ' on' . ($piMode ? '' : ' (runs only under Pi control)') : 'none set' ?>. Charge from the grid at cheap times, or keep the battery for later.</span></p>
    <form class="grid" method="post" action="<?= h($self) ?>#controller">
      <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="controller">
      <label>Controller
        <select name="controller">
          <option value="inverter"<?= $piMode ? '' : ' selected' ?>>Inverter (screen in Auto mode)</option>
          <option value="pi"<?= $piMode ? ' selected' : '' ?><?= $bmsDevices ? '' : ' disabled' ?>>Pi, using the BMS (screen in Passive mode)</option>
        </select></label>
      <label>BMS to follow
        <select name="bms">
<?php foreach ($bmsDevices as $b): ?>
          <option value="<?= h($b['id']) ?>"<?= ($pi['bms'] ?? '') === $b['id'] ? ' selected' : '' ?>><?= h($b['name']) ?></option>
<?php endforeach; ?>
<?php if (!$bmsDevices): ?>
          <option value="">No BMS in the equipment list</option>
<?php endif; ?>
        </select></label>
      <label>Floor: stop discharging at (%) <input name="floor_pct" type="number" min="10" max="50" value="<?= h($pi['floor_pct']) ?>" required></label>
      <label>Ceiling: stop charging at (%) <input name="ceiling_pct" type="number" min="60" max="100" value="<?= h($pi['ceiling_pct']) ?>" required></label>
      <label>Full charge to 100% every (days, 0 = never) <input name="full_every_days" type="number" min="0" max="30" value="<?= h($pi['full_every_days']) ?>" required></label>
      <label>…or sooner when cells differ by (mV) <input name="full_spread_mv" type="number" min="10" max="200" value="<?= h($pi['full_spread_mv']) ?>" required></label>
      <div class="actions"><button class="primary" onclick="return this.form.controller.value === '<?= $piMode ? 'pi' : 'inverter' ?>' || confirm(this.form.controller.value === 'pi'
        ? 'Switch to Pi control? Set the inverter screen to Passive mode as well (Settings > Work Mode Set > Passive Mode).'
        : 'Switch back to inverter control? Then set Work Mode to Auto on the inverter screen.')">Save</button></div>
    </form>
<?php if ($controlEvents): ?>
    <details style="margin-top:10px"><summary class="help">Recent control events</summary>
      <ul class="help">
<?php foreach ($controlEvents as $e): ?>
        <li><?= h(date('j M H:i', $e['ts'])) ?> · <?= h($e['code']) ?></li>
<?php endforeach; ?>
      </ul>
    </details>
<?php endif; ?>
  </section>

  <section class="card">
    <div class="section-head"><h2>Manual control</h2>
      <span class="badge <?= $state ? 'no_data' : 'online' ?>"><?= $state ? 'Manual: ' . h(ucfirst($state['mode'])) : 'Auto' ?></span></div>
<?php if ($state): ?>
    <p>
      <?= h(ucfirst($state['mode'])) ?><?= $state['watts'] ? ' at ' . h($state['watts']) . ' W' : '' ?> since <?= h(date('H:i', $state['started_ts'])) ?><?= $state['until_ts'] ? ', returns to auto at ' . h(date('H:i', $state['until_ts'])) : ', stays on until you change it' ?>.
      <?php if ($state['stopping']): ?><span class="warn">Returning to auto (<?= h($state['stopping']) ?>), still trying.</span><?php endif; ?>
    </p>
<?php endif; ?>
<?php if ($piMode): ?>
    <p class="help">Pi control pauses while a manual run or Standby from here is active, and takes over again when it ends or you choose Auto.</p>
<?php endif; ?>
    <p class="help">If the Pi loses power during a run, the inverter stays in that mode until it decides otherwise. SOFAR's documents don't say what happens when the heartbeat stops, so keep runs short and watch them until that has been tested.</p>
    <form method="post" action="<?= h($self) ?>" class="row">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <button class="primary" name="command" value="auto">Auto (normal operation)</button>
      <button name="command" value="standby" onclick="return confirm('Put the inverter in standby? The battery stops charging and discharging, including from solar, until you choose Auto.')">Standby (stop charging and discharging)</button>
    </form>

    <div class="run">
      <h3>Charge the battery</h3>
      <p class="help">Uses spare solar, then the grid, so it may raise your power bill. Stops at <?= h($ceiling) ?> V, on any fault, or at the time limit, then returns to auto.</p>
      <form class="grid" method="post" action="<?= h($self) ?>">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <label>Power (W) <input name="watts" type="number" min="100" max="<?= MAX_WATTS ?>" step="50" value="300" required></label>
        <label>Time limit (minutes) <input name="minutes" type="number" min="1" max="<?= MAX_MINUTES ?>" value="5" required></label>
        <div class="actions"><button class="primary" name="command" value="charge" onclick="return confirm('Start charging the battery?')">Start charging</button></div>
      </form>
    </div>

    <div class="run">
      <h3>Discharge the battery</h3>
      <p class="help">Refused at or below <?= h($floor) ?> V. Stops at that voltage, on any fault, or at the time limit, then returns to auto. Auto may keep discharging down to the inverter's own limit.</p>
      <form class="grid" method="post" action="<?= h($self) ?>">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <label>Power (W) <input name="watts" type="number" min="100" max="<?= MAX_WATTS ?>" step="50" value="300" required></label>
        <label>Time limit (minutes) <input name="minutes" type="number" min="1" max="<?= MAX_MINUTES ?>" value="5" required></label>
        <div class="actions"><button class="primary" name="command" value="discharge" onclick="return confirm('Start discharging the battery?')">Start discharging</button></div>
      </form>
    </div>
  </section>

  <section class="card">
    <div class="section-head"><h2>Limits for manual runs</h2></div>
    <p class="help">Set these to suit your battery's datasheet. They only apply to charge and discharge runs started on this page, and don't change anything on the inverter.</p>
    <form class="grid" method="post" action="<?= h($self) ?>">
      <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="limits">
      <label>Stop discharging at (V) <input name="min_discharge_v" type="number" step="0.1" min="46" max="53" value="<?= h($floor) ?>" required></label>
      <label>Stop charging at (V) <input name="max_charge_v" type="number" step="0.1" min="50" max="56" value="<?= h($ceiling) ?>" required></label>
      <div class="actions"><button class="primary">Save limits</button></div>
    </form>
  </section>

  <section class="card">
    <div class="section-head"><h2>Inverter's battery settings</h2></div>
    <p class="help">Read from the inverter. These can only be changed on the inverter's own screen, because SOFAR's protocol has no documented command for them. Changes show here within a minute.</p>
<?php if ($settings): ?>
    <dl class="info">
      <dt>Battery type</dt><dd><?= h($settings['battery_type']) ?></dd>
      <dt>Capacity</dt><dd><?= h($settings['capacity_ah']) ?> Ah</dd>
      <dt>Max charge voltage</dt><dd><?= h($settings['max_charge_v']) ?> V</dd>
      <dt>Max charge current</dt><dd><?= h($settings['max_charge_a']) ?> A</dd>
      <dt>Over-voltage protection</dt><dd><?= h($settings['over_voltage_v']) ?> V</dd>
      <dt>Min discharge voltage</dt><dd><?= h($settings['min_discharge_v']) ?> V</dd>
      <dt>Max discharge current</dt><dd><?= h($settings['max_discharge_a']) ?> A</dd>
      <dt>Under-voltage protection</dt><dd><?= h($settings['under_voltage_v']) ?> V</dd>
      <dt>Discharge depth</dt><dd><?= h($settings['discharge_depth_pct']) ?> %</dd>
      <dt>Empty battery voltage</dt><dd><?= h($settings['empty_v']) ?> V</dd>
      <dt>Full battery voltage</dt><dd><?= h($settings['full_v']) ?> V</dd>
    </dl>
<?php else: ?>
    <p class="help">Not read yet.</p>
<?php endif; ?>
  </section>

  <section class="card">
    <div class="section-head"><h2>Recent commands</h2></div>
<?php if ($commands): ?>
    <div class="table-scroll"><table class="events">
      <thead><tr><th>Time</th><th>Command</th><th>Result</th><th>Detail</th></tr></thead>
      <tbody>
<?php foreach ($commands as $c): ?>
        <tr><td><?= h(date('j M H:i:s', $c['ts'])) ?></td>
            <td><?= h(ucfirst($c['action'])) ?><?= $c['watts'] ? ' ' . h($c['watts']) . ' W' : '' ?><?= $c['minutes'] ? ', ' . h($c['minutes']) . ' min' : '' ?></td>
            <td><?= h($c['status']) ?></td><td><?= h($c['detail']) ?></td></tr>
<?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="help">The device page's Events section also records automatic returns to auto.</p>
<?php else: ?>
    <p class="help">None yet.</p>
<?php endif; ?>
  </section>
</main>
<script>
// Keep the figures current, but never reload while someone is filling in a form.
setInterval(() => {
  const a = document.activeElement;
  if (!a || a.tagName !== 'INPUT') location.reload();
}, 30000);
</script>
</body>
</html>
