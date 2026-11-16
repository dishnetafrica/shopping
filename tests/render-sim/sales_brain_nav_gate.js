/*
 * Sales Brain render-simulation test (run: `node tests/render-sim/sales_brain_nav_gate.js`).
 *
 * Runs the ACTUAL navigation code from resources/v3/shell.js against a stub page and proves the
 * "Sales Brain" menu entry appears ONLY for an Internet-provider business whose user has the
 * permission — never for any other business type, nor when the server does not say the type.
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
const hasBrain = html => html.indexOf('data-key="salesBrain"') !== -1;
const staff = ['customers', 'leads', 'salesPipeline', 'salesBrain', 'salesTraining', 'trips', 'chats'];
const hasPipeline = html => html.indexOf('data-key="salesPipeline"') !== -1;
const hasTraining = html => html.indexOf('data-key="salesTraining"') !== -1;

ok('Internet provider with permission sees Sales Brain', hasBrain(nav('isp', staff)));
ok('…labelled "Sales Brain"', nav('isp', staff).indexOf('>Sales Brain<') !== -1);
ok('…in the Customers group after Leads', /data-key="leads"[\s\S]*data-key="salesBrain"/.test(nav('isp', staff)));
ok('Internet provider WITHOUT the permission does not', !hasBrain(nav('isp', ['chats'])));
ok('Internet provider sees Sales Training after Sales Brain', /data-key="salesBrain"[\s\S]*data-key="salesTraining"/.test(nav('isp', staff)));
ok('…labelled "Sales Training"', nav('isp', staff).indexOf('>Sales Training<') !== -1);
ok('Internet provider sees Pipeline right after Leads', /data-key="leads"[^]*?data-key="salesPipeline"/.test(nav('isp', staff)) && nav('isp', staff).indexOf('>Pipeline<') !== -1);
for (const v of ['grocery', 'restaurant', 'snacks', 'manufacturer', 'solar', 'auto_parts', 'technology', 'travel']) {
  ok(v + ' business never sees Sales Brain', !hasBrain(nav(v, staff)));
  ok(v + ' business never sees Sales Training', !hasTraining(nav(v, staff)));
  ok(v + ' business never sees Pipeline', !hasPipeline(nav(v, staff)));
}
ok('unknown business type does not see it (strict gate)', !hasBrain(nav('', staff)) && !hasTraining(nav('', staff)) && !hasPipeline(nav('', staff)));
ok('server sent no business type: not shown', !hasBrain(nav(null, staff)));
ok('an Internet provider does not get Trips', nav('isp', staff).indexOf('data-key="trips"') === -1);
ok('a travel agency still gets Trips', nav('travel', staff).indexOf('data-key="trips"') !== -1);

const served = fs.readFileSync(path.join(root, 'public', 'assets', 'v3', 'shell.js'), 'utf8');
ok('served copy public/assets/v3/shell.js is identical to the source', served === src);

console.log('\n' + (fails.length === 0 ? 'ALL PASS' : 'FAILURES: ' + fails.length));
process.exit(fails.length === 0 ? 0 : 1);
