/*
 * Roadmap E9.3 render-simulation test (run: `node tests/render-sim/invoice_pay_render.js`).
 *
 * Extracts the ACTUAL migrated payInv() from resources/panel/invoices.html and runs it against mocked
 * CloudBSSUI + api, asserting:
 *   - it opens the shared promptDialog (not window.prompt) and POSTs the entered amount to
 *     /papi/invoice-pay unchanged
 *   - success shows an 'ok' toast; a server error shows an 'error' toast via errorMessage
 * Catches runtime errors a lint cannot. The payment endpoint itself is covered by InvoicePayTest.php.
 */
const fs = require('fs');
const vm = require('vm');
const path = require('path');

const html = fs.readFileSync(path.join(__dirname, '..', '..', 'resources', 'panel', 'invoices.html'), 'utf8');
const m = html.match(/function payInv\(id,no,cur,bal\)\{[\s\S]*?\n\}/);
if (!m) { console.log('FAIL: payInv() not found in invoices.html'); process.exit(1); }
const code = m[0];

const fails = [];
function ok(label, cond) { if (!cond) fails.push(label); }

function run(apiResp) {
  const toasts = [];
  let apiCall = null, loaded = false;
  const sb = {
    console, String, parseFloat, URLSearchParams, Promise, setTimeout,
    CloudBSSUI: {
      promptDialog: (msg, opts) => Promise.resolve('200'),
      toast: (msg, type) => { toasts.push({ msg: msg, type: type }); },
      errorMessage: (code) => 'MSG:' + code
    },
    api: (url, opts) => { apiCall = { url, opts }; return Promise.resolve(apiResp); },
    nf: (n) => String(n),
    load: () => { loaded = true; }
  };
  sb.window = sb;
  vm.runInContext(code + '\npayInv(7,"INV-7","USD",50);', vm.createContext(sb));
  return new Promise((res) => setTimeout(() => res({ toasts, apiCall, loaded }), 10));
}

(async () => {
  const okRun = await run({ ok: true, balance: 100 });
  ok('POSTs to /papi/invoice-pay', okRun.apiCall && okRun.apiCall.url === '/papi/invoice-pay');
  ok('sends the entered amount', okRun.apiCall && String(okRun.apiCall.opts.body).indexOf('amount=200') !== -1);
  ok('success shows ok toast', okRun.toasts.some(t => t.type === 'ok' && t.msg.indexOf('Recorded') !== -1));
  ok('reloads on success', okRun.loaded === true);

  const errRun = await run({ ok: false, error: 'bad_amount' });
  ok('server error shows error toast via errorMessage', errRun.toasts.some(t => t.type === 'error' && t.msg.indexOf('MSG:bad_amount') !== -1));

  if (fails.length) { console.log('FAIL:\n - ' + fails.join('\n - ')); process.exit(1); }
  console.log('ALL PASS (invoice pay render-sim)');
  process.exit(0);
})();
