/*
 * Travel Vertical · M1 render-simulation test (run: `node tests/render-sim/trips_nav_gate.js`).
 *
 * Runs the ACTUAL navigation code from resources/v3/shell.js (labels, groups and renderNav) against
 * a stub page and proves the "Trips" menu entry appears ONLY for a travel agency whose user has the
 * permission — and never for any other business type, nor when the server does not say which type
 * the business is. Also proves the served copy public/assets/v3/shell.js is identical.
 */
const fs = require('fs');
const vm = require('vm');
const path = require('path');

const root = path.join(__dirname, '..', '..');
const src = fs.readFileSync(path.join(root, 'resources', 'v3', 'shell.js'), 'utf8');
const lines = src.split('\n');
const fails = [];
function ok(label, cond) { console.log((cond ? 'PASS ' : 'FAIL ') + label); if (!cond) fails.push(label); }

// Lines 2..34 are the constants (LABELS, GROUPS, NAVCOUNT, READY, SF_KEYS, SOON, fmtCount);
// renderNav is a top-level function that ends at the first line that is just "}".
const constsEnd = lines.findIndex(l => l.startsWith('function fmtCount('));
const navStart = lines.findIndex(l => l.startsWith('function renderNav('));
const navEnd = lines.findIndex((l, i) => i > navStart && l === '}');
ok('found the navigation code in shell.js', constsEnd > 0 && navStart > 0 && navEnd > navStart);
const code = lines.slice(1, constsEnd + 1).join('\n') + '\n' + lines.slice(navStart, navEnd + 1).join('\n');

function nav(vertical, capKeys) {
  const el = { innerHTML: '', style: {} };
  const sb = {
    console, Set, Number, String,
    CFG: { flags: vertical === null ? {} : { vertical }, counts: {} },
    icon: () => '',
    document: { getElementById: () => el },
  };
  vm.runInContext(code + '\nrenderNav(' + JSON.stringify(capKeys.map(k => ({ key: k }))) + ');', vm.createContext(sb));
  return el.innerHTML;
}
const hasTrips = html => html.indexOf('data-key="trips"') !== -1;
const staff = ['customers', 'leads', 'trips', 'chats'];

ok('travel agency with permission sees Trips', hasTrips(nav('travel', staff)));
ok('…labelled "Trips"', nav('travel', staff).indexOf('>Trips<') !== -1);
ok('…next to Leads in the Customers group', /data-key="leads"[\s\S]*data-key="trips"/.test(nav('travel', staff)));
ok('travel agency WITHOUT the permission does not', !hasTrips(nav('travel', ['chats'])));
for (const v of ['grocery', 'restaurant', 'snacks', 'manufacturer', 'solar', 'auto_parts', 'technology']) {
  ok(v + ' business never sees Trips', !hasTrips(nav(v, staff)));
}
ok('unknown business type does not see Trips (strict gate)', !hasTrips(nav('', staff)));
ok('server sent no business type: no Trips', !hasTrips(nav(null, staff)));
ok('other entries are unaffected for a grocery', nav('grocery', staff).indexOf('data-key="leads"') !== -1 && nav('grocery', staff).indexOf('data-key="customers"') !== -1);
ok('other entries are unaffected for travel', nav('travel', staff).indexOf('data-key="leads"') !== -1);

const served = fs.readFileSync(path.join(root, 'public', 'assets', 'v3', 'shell.js'), 'utf8');
ok('served copy public/assets/v3/shell.js is identical to the source', served === src);

console.log('\n' + (fails.length === 0 ? 'ALL PASS' : 'FAILURES: ' + fails.length));
process.exit(fails.length === 0 ? 0 : 1);
