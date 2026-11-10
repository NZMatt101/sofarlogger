// Shared helpers for the overview and device pages.

// Show script errors on the page and report them to the Pi (logged in Apache's error log).
(function () {
  function report(kind, msg) {
    try {
      const body = JSON.stringify({ kind, msg: String(msg).slice(0, 500), page: location.pathname + location.search, ua: navigator.userAgent });
      if (navigator.sendBeacon) navigator.sendBeacon('api.php?action=clientlog', body);
    } catch (e) { /* reporting must never break the page */ }
  }
  function banner(msg) {
    let b = document.getElementById('jsError');
    if (!b) {
      b = document.createElement('div');
      b.id = 'jsError';
      b.style.cssText = 'margin:8px 16px;padding:10px 14px;border:1px solid #c0392b;border-radius:8px;color:#c0392b;font-size:14px';
      (document.body || document.documentElement).prepend(b);
    }
    b.textContent = 'Page error: ' + msg;
  }
  window.addEventListener('error', e => { const m = `${e.message} (${(e.filename || '').split('/').pop()}:${e.lineno})`; report('error', m); banner(m); });
  window.addEventListener('unhandledrejection', e => { const m = (e.reason && (e.reason.stack || e.reason.message)) || e.reason; report('rejection', m); banner(m); });
  window.solarReport = report;
  report('boot', 'common.js loaded');
})();
const COLORS = ['#e8a317', '#3b7dd8', '#1f9d55', '#c0392b', '#8e5bd8', '#17a2b8'];
const STATE_LABEL = { retired: 'Retired', online: 'Online', night: 'Offline · night', no_data: 'No data', never: 'Waiting',
                      unreachable: 'Unreachable', bad_reply: 'Bad reply', error: 'Error' };
const TYPE_ICON = { inverter: '☀', battery: '🔋', meter: '⚡' };

const css = n => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
const fmt = (v, d = 1) => v === null || v === undefined || v === '' ? '—'
  : (d === null || typeof v !== 'number') ? String(v) : v.toFixed(d);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const isoDate = d => d.toLocaleDateString('en-CA');
const timeStr = ts => new Date(ts * 1000).toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' });
const dateTimeStr = ts => ts ? new Date(ts * 1000).toLocaleString('en-NZ', { dateStyle: 'medium', timeStyle: 'short' }) : 'never';
const badge = state => `<span class="badge ${esc(state)}">${esc(STATE_LABEL[state] || state)}</span>`;

// Stable colour per device, based on its order in the site device list.
const deviceColor = {};
function assignColors(devices) { devices.forEach((d, i) => { deviceColor[d.id] = COLORS[i % COLORS.length]; }); }
const colorFor = id => deviceColor[id] || COLORS[0];

async function getJSON(url) {
  try {
    const r = await fetch(url, { cache: 'no-store' });
    if (!r.ok) throw new Error(`HTTP ${r.status}`);
    return await r.json();
  } catch (e) {
    window.solarReport('fetch', `${url}: ${e.message || e}`);
    throw e;
  }
}

function baseOptions() {
  const grid = css('--line'), tick = css('--muted');
  return {
    responsive: true, maintainAspectRatio: false, animation: false,
    interaction: { mode: 'index', intersect: false },
    plugins: { legend: { labels: { color: tick, boxWidth: 12 } } },
    scales: { x: { grid: { color: grid }, ticks: { color: tick, maxRotation: 0, autoSkipPadding: 16 } },
              y: { grid: { color: grid }, ticks: { color: tick }, beginAtZero: true } },
  };
}

// Day power chart. names: {deviceId: label}; stacked shows site total as a stacked area.
function renderDayChart(canvas, existing, day, names, stacked) {
  const byDevice = {};
  for (const r of day.rows) (byDevice[r.device] ??= []).push(r);
  const datasets = Object.entries(byDevice).map(([id, rs]) => ({
    label: names[id] || id, data: rs.map(r => ({ x: r.ts * 1000, y: r.power_w })),
    borderColor: colorFor(id), backgroundColor: colorFor(id) + (stacked ? '99' : '33'),
    fill: stacked ? 'stack' : true, pointRadius: 0, borderWidth: 2, tension: 0.2, spanGaps: 15 * 60 * 1000,
  }));
  const opts = baseOptions();
  opts.parsing = false;
  opts.scales.x = { ...opts.scales.x, type: 'linear', min: day.from * 1000, max: day.to * 1000,
                    ticks: { ...opts.scales.x.ticks, stepSize: 3 * 3600 * 1000, callback: v => timeStr(v / 1000) } };
  opts.scales.y.title = { display: true, text: 'W', color: css('--muted') };
  if (stacked) opts.scales.y.stacked = true;
  opts.interaction = { mode: 'nearest', axis: 'x', intersect: false };
  opts.plugins.tooltip = { callbacks: { title: items => timeStr(items[0].parsed.x / 1000),
                                        label: i => `${i.dataset.label}: ${Math.round(i.parsed.y)} W` } };
  existing?.destroy();
  return { chart: new Chart(canvas, { type: 'line', data: { datasets }, options: opts }), byDevice };
}

const monthStr = ts => new Date(ts * 1000).toLocaleDateString('en-NZ', { month: 'short', year: 'numeric' });

// Daily bars for the `days` days ending at endDate (default today).
function renderDailyChart(canvas, existing, daily, days, names, endDate = new Date()) {
  const labelsIso = [];
  for (let i = days - 1; i >= 0; i--) { const t = new Date(endDate); t.setDate(t.getDate() - i); labelsIso.push(isoDate(t)); }
  const ids = [...new Set(daily.rows.map(r => r.device))];
  const datasets = ids.map(id => {
    const m = Object.fromEntries(daily.rows.filter(r => r.device === id).map(r => [r.day, r.energy_kwh]));
    return { label: names[id] || id, data: labelsIso.map(d => m[d] ?? null), backgroundColor: colorFor(id), borderRadius: 3 };
  });
  const opts = baseOptions();
  opts.scales.x.stacked = true; opts.scales.y.stacked = true;
  opts.scales.y.title = { display: true, text: 'kWh', color: css('--muted') };
  opts.plugins.tooltip = { callbacks: { label: i => `${i.dataset.label}: ${fmt(i.parsed.y, 2)} kWh` } };
  const labels = labelsIso.map(s => new Date(s + 'T12:00').toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' }));
  existing?.destroy();
  const total = daily.rows.reduce((s, r) => s + (r.energy_kwh || 0), 0);
  return { chart: new Chart(canvas, { type: 'bar', data: { labels, datasets }, options: opts }), total };
}

// Monthly bars over the full history in `monthly.rows` (stacked by device).
function renderMonthlyChart(canvas, existing, monthly, names) {
  const months = [...new Set(monthly.rows.map(r => r.month))].sort();
  if (months.length) {  // fill gaps so the time axis is continuous
    const all = [];
    let [y, m] = months[0].split('-').map(Number);
    const [ly, lm] = months[months.length - 1].split('-').map(Number);
    while (y < ly || (y === ly && m <= lm)) { all.push(`${y}-${String(m).padStart(2, '0')}`); if (++m > 12) { m = 1; y++; } }
    months.splice(0, months.length, ...all);
  }
  const ids = [...new Set(monthly.rows.map(r => r.device))];
  const datasets = ids.map(id => {
    const m = Object.fromEntries(monthly.rows.filter(r => r.device === id).map(r => [r.month, r.energy_kwh]));
    return { label: names[id] || id, data: months.map(k => m[k] ?? null), backgroundColor: colorFor(id), borderRadius: 2 };
  });
  const opts = baseOptions();
  opts.scales.x.stacked = true; opts.scales.y.stacked = true;
  opts.scales.y.title = { display: true, text: 'kWh', color: css('--muted') };
  opts.plugins.tooltip = { callbacks: { label: i => `${i.dataset.label}: ${fmt(i.parsed.y, 1)} kWh` } };
  const labels = months.map(k => new Date(k + '-15T12:00').toLocaleDateString('en-NZ', { month: 'short', year: '2-digit' }));
  existing?.destroy();
  const total = monthly.rows.reduce((s, r) => s + (r.energy_kwh || 0), 0);
  return { chart: new Chart(canvas, { type: 'bar', data: { labels, datasets }, options: opts }), total, months: months.length };
}

// Wires up a ◀ date ▶ Today control; calls onChange(Date).
function dayNavigator(onChange) {
  let current = new Date();
  const picker = document.getElementById('dayPicker');
  const label = document.getElementById('dayLabel');
  const set = d => {
    current = d;
    picker.value = isoDate(current);
    label.textContent = current.toLocaleDateString('en-NZ', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
    onChange(current);
  };
  const shift = n => { const d = new Date(current); d.setDate(d.getDate() + n); set(d); };
  document.getElementById('prevDay').onclick = () => shift(-1);
  document.getElementById('nextDay').onclick = () => shift(1);
  document.getElementById('today').onclick = () => set(new Date());
  picker.onchange = e => { if (e.target.value) set(new Date(e.target.value + 'T12:00')); };
  return { refresh: () => set(current), isToday: () => isoDate(current) === isoDate(new Date()),
           start: (d = new Date()) => set(new Date(d)) };
}

function rangeButtons(onChange) {
  const box = document.getElementById('rangeBtns');
  box.onclick = e => {
    const b = e.target.closest('button'); if (!b) return;
    box.querySelectorAll('button').forEach(x => x.classList.toggle('active', x === b));
    onChange(+b.dataset.days);
  };
}

function initCsvForm() {
  const t = isoDate(new Date());
  document.getElementById('csvFrom').value = t;
  document.getElementById('csvTo').value = t;
}
