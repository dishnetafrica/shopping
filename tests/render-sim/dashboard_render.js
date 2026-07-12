/*
 * Roadmap E10.2 render-simulation test (run: `node tests/render-sim/dashboard_render.js`).
 *
 * Executes the ACTUAL role-dashboard script embedded in resources/panel/seller.html against a mocked
 * DOM + fetch, and asserts:
 *   - with enabled:true, the widget cards are rendered into #role-dashboard (titles + value lines)
 *   - with enabled:false (dash_v2 off, the default), #role-dashboard is left empty (legacy home intact)
 *
 * Mirrors tests/render-sim/navv2_render.js — parse + execute the real inline script to catch runtime
 * errors a lint cannot. The widget values themselves are covered by tests/Feature/DashboardTest.php.
 */
const fs = require('fs');
const vm = require('vm');
const path = require('path');

const SELLER = path.join(__dirname, '..', '..', 'resources', 'panel', 'seller.html');
const html = fs.readFileSync(SELLER, 'utf8');
const m = html.match(/<script>\s*\/\* Roadmap E10\.2[\s\S]*?<\/script>/);
if (!m) { console.log('FAIL: E10.2 dashboard script block not found in seller.html'); process.exit(1); }
const code = m[0].replace(/^<script>/, '').replace(/<\/script>$/, '');

const WIDGETS = [
  { key: 'cash_on_hand', title: 'Cash on hand', type: 'stat_multi', value: { balances: { USD: 1000, UGX: 380000, SSP: 0 } } },
  { key: 'open_invoices', title: 'Open invoices', type: 'stat', value: { count: 3, balance_usd: 250 } },
  { key: 'net_profit', title: 'Net profit', type: 'stat', value: { enabled: true, net_profit: 600 } }
];

function runWith(resp) {
  let captured = null;
  const host = { set innerHTML(v) { captured = v; }, get innerHTML() { return captured; } };
  const doc = {
    readyState: 'complete',
    getElementById: (id) => (id === 'role-dashboard' ? host : null),
    addEventListener: () => {}
  };
  const sb = {
    document: doc, console, Number, String, Array,
    fetch: () => Promise.resolve({ json: () => Promise.resolve(resp) })
  };
  sb.window = sb;
  vm.runInContext(code, vm.createContext(sb), { timeout: 3000 });
  return new Promise((res) => setTimeout(() => res(captured), 5));
}

(async () => {
  const fails = [];

  const on = await runWith({ ok: true, enabled: true, role: 'owner', widgets: WIDGETS });
  if (!on || on.indexOf('Cash on hand') === -1) fails.push('enabled:true did not render Cash on hand card');
  if (!on || on.indexOf('USD 1,000') === -1) fails.push('enabled:true did not render cash value');
  if (!on || on.indexOf('Open invoices') === -1 || on.indexOf('3 open') === -1) fails.push('enabled:true did not render open invoices');
  if (!on || on.indexOf('Net profit') === -1) fails.push('enabled:true did not render net profit');

  const off = await runWith({ ok: true, enabled: false, role: 'owner', widgets: WIDGETS });
  if (off !== '') fails.push('enabled:false should leave #role-dashboard empty (got: ' + JSON.stringify(off) + ')');

  if (fails.length) { console.log('FAIL:\n - ' + fails.join('\n - ')); process.exit(1); }
  console.log('ALL PASS (dashboard render-sim)');
  process.exit(0);
})();
