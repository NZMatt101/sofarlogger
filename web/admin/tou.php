<?php
// Time-of-use schedule for an ME3000SP: up to 10 slots that charge the battery from the grid at cheap times,
// or keep it for later. Saved in the device's "tou" list in the config; the poller's Pi control carries them out
// (tou_active / tou_decide in poller.py). Protected by Apache basic auth like the rest of admin/.
require __DIR__ . '/../inc/common.php';

const TOU_MAX_SLOTS = 10;          // poller.py TOU_MAX_SLOTS
const MAX_WATTS = 3000;            // Me3000Control.MAX_WATTS
const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];   // index = Python tm_wday (Monday = 0)

session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function flash($type, $msg) { $_SESSION['flash'][] = [$type, $msg]; }
function hhmm_minutes($t) {
    return preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', (string)$t, $m) ? (int)$m[1] * 60 + (int)$m[2] : null;
}
function blank_slot() {
    return ['on' => false, 'name' => '', 'days' => [0, 1, 2, 3, 4, 5, 6], 'start' => '', 'end' => '',
            'action' => 'charge', 'watts' => 2000, 'target_pct' => 90];
}
// Same rule as poller.py tou_active(): first switched-on slot covering the time wins.
function active_slot($slots, $ts) {
    $now = (int)date('G', $ts) * 60 + (int)date('i', $ts);
    $day = (int)date('N', $ts) - 1;
    $prev = ($day + 6) % 7;
    foreach (array_slice($slots, 0, TOU_MAX_SLOTS) as $i => $s) {
        if (empty($s['on'])) continue;
        $a = hhmm_minutes($s['start'] ?? ''); $b = hhmm_minutes($s['end'] ?? '');
        if ($a === null || $b === null || $a === $b) continue;
        $days = $s['days'] ?? [];
        $hit = $a < $b ? in_array($day, $days) && $now >= $a && $now < $b
                       : (in_array($day, $days) && $now >= $a) || (in_array($prev, $days) && $now < $b);
        if ($hit) return $i + 1;
    }
    return null;
}

$id = $_GET['id'] ?? '';
$cfg = load_config();
$index = null;
foreach ($cfg['devices'] as $i => $d) if ($d['id'] === $id && $d['driver'] === 'me3000') $index = $i;
if ($index === null) { http_response_code(404); echo 'Unknown battery device.'; exit; }
$dev = $cfg['devices'][$index];
$self = 'tou.php?id=' . rawurlencode($id);
$piMode = ($dev['controller'] ?? 'inverter') === 'pi';
$slots = array_values($dev['tou'] ?? []);
while (count($slots) < TOU_MAX_SLOTS) $slots[] = blank_slot();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        flash('err', 'Form expired, please try again.');
    } else {
        try {
            $in = $_POST['slot'] ?? [];
            $new = [];
            for ($n = 0; $n < TOU_MAX_SLOTS; $n++) {
                $r = $in[$n] ?? [];
                $label = 'Slot ' . ($n + 1);
                $s = [
                    'on' => !empty($r['on']),
                    // first 30 characters (UTF-8 safe without the mbstring extension, which isn't installed)
                    'name' => preg_match('/^.{0,30}/su', trim((string)($r['name'] ?? '')), $m) ? $m[0] : '',
                    'days' => array_values(array_filter(array_map('intval', (array)($r['days'] ?? [])), fn($d) => $d >= 0 && $d <= 6)),
                    'start' => trim((string)($r['start'] ?? '')),
                    'end' => trim((string)($r['end'] ?? '')),
                    'action' => in_array($r['action'] ?? '', ['charge', 'hold'], true) ? $r['action'] : 'charge',
                    'watts' => filter_var($r['watts'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 100, 'max_range' => MAX_WATTS]]),
                    'target_pct' => filter_var($r['target_pct'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 20, 'max_range' => 100]]),
                ];
                sort($s['days']);
                $s['days'] = array_values(array_unique($s['days']));
                if ($s['on']) {
                    $a = hhmm_minutes($s['start']); $b = hhmm_minutes($s['end']);
                    if ($a === null || $b === null) throw new InvalidArgumentException("$label: enter a start and end time.");
                    if ($a === $b) throw new InvalidArgumentException("$label: the start and end times are the same.");
                    if (!$s['days']) throw new InvalidArgumentException("$label: tick at least one day.");
                    if ($s['action'] === 'charge' && $s['watts'] === false) throw new InvalidArgumentException("$label: power must be 100 to " . MAX_WATTS . ' W.');
                    if ($s['action'] === 'charge' && $s['target_pct'] === false) throw new InvalidArgumentException("$label: charge to must be 20 to 100%.");
                }
                if ($s['watts'] === false) $s['watts'] = 2000;
                if ($s['target_pct'] === false) $s['target_pct'] = 90;
                $new[] = $s;
            }
            // Drop trailing unused rows so the config stays tidy.
            while ($new && !end($new)['on'] && end($new)['start'] === '' && end($new)['end'] === '' && end($new)['name'] === '') array_pop($new);
            $cfg['devices'][$index]['tou'] = $new;
            save_config($cfg);
            $count = count(array_filter($new, fn($s) => $s['on']));
            flash('ok', "Schedule saved: $count slot" . ($count === 1 ? '' : 's') . ' switched on. The poller applies it within a minute.'
                . ($piMode ? '' : ' It only runs once the battery is under Pi control.'));
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

$piState = null;
try {
    $db = db();
    if ($db->querySingle("SELECT 1 FROM sqlite_master WHERE name = 'pi_control'")) {
        $st = $db->prepare('SELECT * FROM pi_control WHERE device = :d');
        $st->bindValue(':d', $id);
        $piState = rows($st)[0] ?? null;
    }
    $db->close();
} catch (Exception $e) {
}
$now = time();
$active = active_slot($slots, $now);
$ceiling = (float)($dev['max_charge_v'] ?? 53.5);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Time-of-use · <?= h($dev['name']) ?></title>
<link rel="stylesheet" href="../style.css?v=3">
<style>
  .flash { margin: 0 0 12px; padding: 10px 14px; border-radius: 8px; border: 1px solid var(--line); }
  .flash.ok { border-color: var(--good, #1f9d55); }
  .flash.err { border-color: var(--bad, #c0392b); color: var(--bad, #c0392b); }
  .help { color: var(--muted); font-size: 13px; }
  .warn { color: var(--warn, #b7791f); }
  .table-scroll { overflow-x: auto; }
  table.tou { border-collapse: collapse; width: 100%; min-width: 900px; }
  table.tou th, table.tou td { padding: 6px 5px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: middle; font-size: 13px; }
  table.tou th { color: var(--muted); font-weight: 600; }
  table.tou input, table.tou select { color: var(--text); }
  table.tou input[type=text] { width: 120px; }
  table.tou input[type=number] { width: 72px; }
  table.tou tr.now td { background: color-mix(in srgb, var(--good, #1f9d55) 14%, transparent); }
  table.tou tr.hold .charge-only { opacity: .35; }
  .days { display: flex; gap: 2px; }
  .days label { display: grid; justify-items: center; font-size: 11px; color: var(--muted); gap: 1px; }
  .presets { display: flex; gap: 4px; margin-top: 3px; }
  .presets button { font-size: 11px; padding: 1px 6px; }
</style>
</head>
<body>
<header>
  <div>
    <div class="crumbs"><a href="battery.php?id=<?= h(rawurlencode($id)) ?>">← Battery control</a></div>
    <h1>🕒 Time-of-use schedule</h1>
  </div>
  <nav class="top"><a href="battery.php?id=<?= h(rawurlencode($id)) ?>#controller">Who controls the battery</a></nav>
</header>
<main>
<?php foreach ($flashes as [$type, $msg]): ?>
  <div class="flash <?= h($type) ?>"><?= h($msg) ?></div>
<?php endforeach; ?>

  <section class="card">
    <div class="section-head"><h2>Now</h2><span class="updated">Pi time <?= h(DAYS[(int)date('N', $now) - 1] . ' ' . date('H:i', $now)) ?></span></div>
<?php if (!$piMode): ?>
    <p class="warn"><strong>Not running:</strong> the schedule only works while the Pi controls the battery (inverter screen in Passive mode).
      Switch to Pi under <a href="battery.php?id=<?= h(rawurlencode($id)) ?>#controller">Who controls the battery</a>. You can still set it up here first.</p>
<?php else: ?>
    <dl class="info">
      <dt>Active slot</dt><dd><?= $active ? 'Slot ' . h($active) . ($slots[$active - 1]['name'] ? ' (' . h($slots[$active - 1]['name']) . ')' : '') : 'None: normal Pi control' ?></dd>
      <dt>Pi control is doing</dt><dd><?= h($piState['reason'] ?? '—') ?></dd>
<?php if (!empty($piState['problem'])): ?>
      <dt>Problem</dt><dd class="warn"><?= h($piState['problem']) ?></dd>
<?php endif; ?>
    </dl>
<?php endif; ?>
  </section>

  <section class="card">
    <div class="section-head"><h2>Slots</h2></div>
    <p class="help">
      <strong>Charge from grid:</strong> charges the battery at the set power, from the grid if there isn't enough solar, until the BMS reaches
      <em>charge to</em>. It then holds the battery (no discharging) until the slot ends, and tops up again if it falls 3% below.<br>
      <strong>Keep battery:</strong> no discharging during the slot, so the charge is saved for later; spare solar can still charge it.<br>
      Outside the slots, normal Pi control applies (floor and ceiling on the battery page). A slot can run past midnight (for example 23:00 to 07:00);
      its days are the days it starts on. If slots overlap, the lower number wins.
    </p>
    <p class="help">Charging always stops at once if the BMS reading goes missing or it reports a protection, a cell reaches 3.55 V, the battery reaches
      <?= h($ceiling) ?> V (the battery page's "stop charging" limit) or the inverter reports a fault. Grid charging adds to the house load on your
      main fuse: allow for that when choosing the power.</p>
    <form method="post" action="<?= h($self) ?>">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <div class="table-scroll"><table class="tou">
        <thead><tr><th>#</th><th>On</th><th>Name</th><th>Days</th><th>Start</th><th>End</th><th>Action</th><th>Power (W)</th><th>Charge to (%)</th></tr></thead>
        <tbody>
<?php foreach ($slots as $n => $s): $p = "slot[$n]"; ?>
          <tr class="<?= $active === $n + 1 ? 'now ' : '' ?><?= ($s['action'] ?? 'charge') === 'hold' ? 'hold' : '' ?>">
            <td><?= $n + 1 ?></td>
            <td><input type="checkbox" name="<?= $p ?>[on]" value="1"<?= !empty($s['on']) ? ' checked' : '' ?> aria-label="Slot <?= $n + 1 ?> on"></td>
            <td><input type="text" name="<?= $p ?>[name]" maxlength="30" value="<?= h($s['name'] ?? '') ?>" placeholder="e.g. Night rate"></td>
            <td>
              <div class="days">
<?php foreach (DAYS as $d => $dn): ?>
                <label><?= ['M', 'Tu', 'W', 'Th', 'F', 'Sa', 'Su'][$d] ?><input type="checkbox" name="<?= $p ?>[days][]" value="<?= $d ?>"<?= in_array($d, $s['days'] ?? []) ? ' checked' : '' ?> title="<?= $dn ?>"></label>
<?php endforeach; ?>
              </div>
              <div class="presets">
                <button type="button" data-days="0,1,2,3,4,5,6">Every day</button>
                <button type="button" data-days="0,1,2,3,4">Weekdays</button>
                <button type="button" data-days="5,6">Weekends</button>
              </div>
            </td>
            <td><input type="time" name="<?= $p ?>[start]" value="<?= h($s['start'] ?? '') ?>"></td>
            <td><input type="time" name="<?= $p ?>[end]" value="<?= h($s['end'] ?? '') ?>"></td>
            <td><select name="<?= $p ?>[action]">
                <option value="charge"<?= ($s['action'] ?? '') === 'charge' ? ' selected' : '' ?>>Charge from grid</option>
                <option value="hold"<?= ($s['action'] ?? '') === 'hold' ? ' selected' : '' ?>>Keep battery</option>
              </select></td>
            <td class="charge-only"><input type="number" name="<?= $p ?>[watts]" min="100" max="<?= MAX_WATTS ?>" step="50" value="<?= h($s['watts'] ?? 2000) ?>"></td>
            <td class="charge-only"><input type="number" name="<?= $p ?>[target_pct]" min="20" max="100" value="<?= h($s['target_pct'] ?? 90) ?>"></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="actions" style="margin-top:12px"><button class="primary">Save schedule</button></div>
    </form>
  </section>
</main>
<script>
document.querySelectorAll('.presets button').forEach(b => b.addEventListener('click', () => {
  const want = b.dataset.days.split(',');
  b.closest('td').querySelectorAll('input[type=checkbox]').forEach(c => { c.checked = want.includes(c.value); });
}));
document.querySelectorAll('table.tou select').forEach(s => s.addEventListener('change', () => {
  s.closest('tr').classList.toggle('hold', s.value === 'hold');
}));
</script>
</body>
</html>
