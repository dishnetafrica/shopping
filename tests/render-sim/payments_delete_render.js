/*
 * Roadmap E9.3 render-simulation test (run: `node tests/render-sim/payments_delete_render.js`).
 *
 * Extracts the ACTUAL migrated delPay() from resources/panel/payments.html and runs it against mocked
 * CloudBSSUI + fetch, asserting the confirm()->confirmDialog() change works: on confirm it POSTs to
 * /papi/payments/{id}/delete (unchanged) and toasts success; on cancel it does NOT delete.
 */
const fs = require('fs');
const vm = require('vm');
const path = require('path');

const html = fs.readFileSync(path.join(__dirname, '..', '..', 'resources', 'panel', 'payments.html'), 'utf8');
const m = html.match(/function delPay\(id\)\{[\s\S]*?\n\}/);
if (!m) { console.log('FAIL: delPay() not found in payments.html'); process.exit(1); }
const code = m[0];

const fails = [];
function ok(label, cond) { if (!cond) fails.push(label); }

function run(confirmAnswer) {
  let fetchUrl = null; const toasts = [];
  const sb = {
    console, setTimeout, Promise,
    CloudBSSUI: {
      confirmDialog: () => Promise.resolve(confirmAnswer),
      toast: (msg, type) => { toasts.push({ msg, type }); },
      errorMessage: (c) => 'MSG:' + c
    },
    fetch: (url) => { fetchUrl = url; return Promise.resolve({ json: () => Promise.resolve({ ok: true }) }); },
    load: () => {}
  };
  sb.window = sb;
  vm.runInContext(code + '\ndelPay(42);', vm.createContext(sb));
  return new Promise((res) => setTimeout(() => res({ fetchUrl, toasts }), 10));
}

(async () => {
  const yes = await run(true);
  ok('confirm -> POSTs the delete', yes.fetchUrl === '/papi/payments/42/delete');
  ok('confirm -> success toast', yes.toasts.some(t => t.type === 'ok'));

  const no = await run(false);
  ok('cancel -> NO delete request', no.fetchUrl === null);
  ok('cancel -> no toast', no.toasts.length === 0);

  if (fails.length) { console.log('FAIL:\n - ' + fails.join('\n - ')); process.exit(1); }
  console.log('ALL PASS (payments delete render-sim)');
  process.exit(0);
})();
