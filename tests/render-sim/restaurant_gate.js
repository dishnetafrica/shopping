/*
 * Roadmap E1.3 render-simulation test (run: `node tests/render-sim/restaurant_gate.js`).
 *
 * Extracts the ACTUAL applyFeatureGates() + restaurant globals from resources/panel/seller.html
 * and verifies that restaurant data-feature items (kitchen/pos/thali/riders/dispatch) are:
 *   - HIDDEN when RESTAURANT_ON is false (module off — the default for a trading tenant)
 *   - SHOWN  when RESTAURANT_ON is true  (module on)
 *   - still individually suppressible via FEATURES[key] === false when the module is on
 * while a NON-restaurant feature (image_search) keeps its existing default-on behaviour.
 */
const fs = require('fs');
const vm = require('vm');
const path = require('path');

const html = fs.readFileSync(path.join(__dirname, '..', '..', 'resources', 'panel', 'seller.html'), 'utf8');

const globals = html.match(/var RESTAURANT_KEYS=\[[^\]]*\];\s*\r?\n\s*var RESTAURANT_ON=false;/);
const fn = html.match(/function applyFeatureGates\(\)\{[\s\S]*?\n\}/);
if (!globals || !fn) { console.log('FAIL: could not extract restaurant globals / applyFeatureGates from seller.html'); process.exit(1); }

function makeEl(feature) {
  return { _f: feature, style: { display: '' }, getAttribute: (a) => (a === 'data-feature' ? feature : null) };
}

function run(restaurantOn, features) {
  const els = ['kitchen', 'pos', 'thali', 'riders', 'dispatch', 'image_search'].map(makeEl);
  const doc = {
    querySelectorAll: () => els,
    querySelector: () => null, // no active restaurant page in this harness
  };
  const sb = { document: doc, FEATURES: features, showPage: () => {}, console };
  sb.window = sb;
  const ctx = vm.createContext(sb);
  vm.runInContext(globals[0] + '\n' + fn[0], ctx);
  ctx.RESTAURANT_ON = restaurantOn;
  vm.runInContext('applyFeatureGates();', ctx);
  const map = {};
  els.forEach((e) => { map[e._f] = e.style.display; });
  return map; // '' = shown, 'none' = hidden
}

const fail = [];
const REST = ['kitchen', 'pos', 'thali', 'riders', 'dispatch'];

// 1. module OFF → all restaurant hidden, image_search shown
let m = run(false, {});
REST.forEach((k) => { if (m[k] !== 'none') fail.push('module off: ' + k + ' should be hidden'); });
if (m.image_search !== '') fail.push('module off: image_search should stay visible');

// 2. module ON → restaurant shown, image_search shown
m = run(true, {});
REST.forEach((k) => { if (m[k] !== '') fail.push('module on: ' + k + ' should be shown'); });
if (m.image_search !== '') fail.push('module on: image_search should be visible');

// 3. module ON but pos individually disabled → pos hidden, others shown
m = run(true, { pos: false });
if (m.pos !== 'none') fail.push('module on + pos:false → pos should be hidden');
if (m.kitchen !== '') fail.push('module on + pos:false → kitchen should still show');

// 4. non-restaurant feature can still be disabled
m = run(false, { image_search: false });
if (m.image_search !== 'none') fail.push('image_search:false should hide it');

if (fail.length) { console.log('FAIL:\n' + fail.map((x) => '  - ' + x).join('\n')); process.exit(1); }
console.log('E1.3 RESTAURANT-GATE SIM PASSED — restaurant hidden by default, shown only when module on; image_search unaffected');
