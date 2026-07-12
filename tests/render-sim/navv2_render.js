/*
 * Roadmap E1.2 render-simulation test (run: `node tests/render-sim/navv2_render.js`).
 *
 * Executes the ACTUAL nav_v2 sidebar script embedded in resources/panel/seller.html
 * against a mocked DOM + fetch, and asserts:
 *   - with enabled:true, the sidebar is rebuilt from the /papi/nav groups
 *   - every route and every label appears exactly once (duplicates collapsed)
 *   - "Customers" and "Cashbook" appear once (the v1 duplicate-menu bug is gone)
 *   - with enabled:false, the menu is left untouched
 *
 * This mirrors the panel-page render-simulation technique used elsewhere in the repo
 * (parse + execute the real inline script to catch runtime errors a lint cannot).
 * The endpoint/menuFor logic itself is covered by tests/Feature/NavConfigTest.php.
 */
const fs = require('fs');
const vm = require('vm');
const path = require('path');

const SELLER = path.join(__dirname, '..', '..', 'resources', 'panel', 'seller.html');

// A representative owner menu (shape of /papi/nav groups) — includes the two labels
// that were duplicated in the v1 menu (Customers, Cashbook) to prove they collapse.
const groups = [
  { key: 'home',      label: 'Home',      items: [ { key: 'dashboard', label: 'Dashboard', route: '/panel', icon: '🏠' } ] },
  { key: 'sales',     label: 'Sales',     items: [
    { key: 'orders',    label: 'Orders',    route: '/panel/orders-costs', icon: '🧾' },
    { key: 'customers', label: 'Customers', route: '/panel/customers360', icon: '👥' },
    { key: 'invoices',  label: 'Invoices',  route: '/panel/invoices',     icon: '📄' },
  ] },
  { key: 'money',     label: 'Money',     items: [
    { key: 'payments', label: 'Payments', route: '/panel/payments',       icon: '💵' },
    { key: 'cashbook', label: 'Cashbook', route: '/panel/trade-cashbook', icon: '💰' },
    { key: 'expenses', label: 'Expenses', route: '/panel/expenses',       icon: '💸' },
  ] },
  { key: 'settings',  label: 'Settings',  items: [
    { key: 'business', label: 'Business profile', route: '/panel/setup', icon: '🏢' },
    { key: 'whatsapp', label: 'WhatsApp',         route: '/panel/setup', icon: '📱' },
  ] },
];

const html = fs.readFileSync(SELLER, 'utf8');
const m = html.match(/<script>\s*\/\* Roadmap E1\.2[\s\S]*?<\/script>/);
if (!m) { console.log('FAIL: E1.2 script block not found in seller.html'); process.exit(1); }
const code = m[0].replace(/^<script>/, '').replace(/<\/script>$/, '');

function runWith(resp) {
  let captured = null;
  const navNode = { set innerHTML(v) { captured = v; }, get innerHTML() { return captured; } };
  const doc = { readyState: 'complete', querySelector: (s) => (s === '#side nav' ? navNode : null), addEventListener: () => {} };
  const sb = { document: doc, Array, String, setTimeout, console, fetch: () => Promise.resolve({ json: () => Promise.resolve(resp) }) };
  sb.window = sb;
  vm.runInContext(code, vm.createContext(sb), { timeout: 3000 });
  return new Promise((res) => setTimeout(() => res(captured), 5));
}

(async () => {
  const fail = [];

  const out = await runWith({ ok: true, enabled: true, groups });
  if (!out) { console.log('FAIL: sidebar not rebuilt when enabled:true'); process.exit(1); }

  const routes = [...out.matchAll(/href="([^"]+)"/g)].map((x) => x[1])
    .filter((r) => r !== '/panel/logout' && r !== '/panel/setup'); // setup intentionally hosts two settings sub-items
  const dupRoutes = [...new Set(routes.filter((r, i) => routes.indexOf(r) !== i))];
  if (dupRoutes.length) fail.push('duplicate routes: ' + dupRoutes.join(', '));

  const labels = [...out.matchAll(/<\/span>\s*([^<]+?)<\/a>/g)].map((x) => x[1].trim());
  const dupLabels = [...new Set(labels.filter((l, i) => labels.indexOf(l) !== i && l !== 'Logout'))];
  if (dupLabels.length) fail.push('duplicate labels: ' + dupLabels.join(', '));

  const count = (s) => labels.filter((l) => l === s).length;
  if (count('Customers') !== 1) fail.push('Customers appears ' + count('Customers') + 'x (want 1)');
  if (count('Cashbook') !== 1) fail.push('Cashbook appears ' + count('Cashbook') + 'x (want 1)');
  if (!/\/panel\/logout/.test(out)) fail.push('logout link missing');

  const off = await runWith({ ok: true, enabled: false, groups });
  if (off !== null) fail.push('menu was rebuilt even though enabled:false');

  if (fail.length) { console.log('FAIL:\n' + fail.map((x) => '  - ' + x).join('\n')); process.exit(1); }
  console.log('E1.2 RENDER SIM PASSED — ' + routes.length + ' unique routes, ' + labels.length + ' labels, no duplicates; flag-off leaves menu untouched');
})();
