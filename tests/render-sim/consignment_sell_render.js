/*
 * Roadmap E9.3 render-simulation test (run: `node tests/render-sim/consignment_sell_render.js`).
 *
 * Extracts the ACTUAL migrated sellStock() from resources/panel/consignment.html and drives it through
 * the shared dialogs (qty -> price -> buyer) against mocked CloudBSSUI + api, asserting it POSTs
 * qty/price/buyer to /papi/consignment-stock-sell (unchanged) and toasts the sale result. Also proves
 * a bad quantity warns and does NOT sell.
 */
const fs = require('fs');
const vm = require('vm');
const path = require('path');

const html = fs.readFileSync(path.join(__dirname, '..', '..', 'resources', 'panel', 'consignment.html'), 'utf8');
const m = html.match(/function sellStock\(lineId\)\{[\s\S]*?\n\}/);
if (!m) { console.log('FAIL: sellStock() not found in consignment.html'); process.exit(1); }
const code = m[0];

const fails = [];
function ok(label, cond) { if (!cond) fails.push(label); }

function run(qtyAnswer) {
  let apiCall = null; const toasts = [];
  const sb = {
    console, String, parseFloat, URLSearchParams, Promise, setTimeout,
    window: { __STK: { 5: { name: 'Rice', remaining: 40, unit_price: 3000 } } },
    CloudBSSUI: {
      promptDialog: (msg) => Promise.resolve(
        msg.indexOf('Sell how many') !== -1 ? qtyAnswer :
        msg.indexOf('Price') !== -1 ? '3200' : 'Market buyer'),
      toast: (msg, type) => { toasts.push({ msg, type }); },
      errorMessage: (c) => 'MSG:' + c
    },
    api: (url, opts) => { apiCall = { url, opts }; return Promise.resolve({ ok: true, sold: 10, order_no: 'ORD-9', total: 32000, margin: 2000, remaining: 30 }); },
    nf: (n) => String(n),
    loadDetail: () => {}
  };
  sb.window.CloudBSSUI = sb.CloudBSSUI;
  vm.runInContext(code + '\nsellStock(5);', vm.createContext(sb));
  return new Promise((res) => setTimeout(() => res({ apiCall, toasts }), 20));
}

(async () => {
  const good = await run('10');
  ok('POSTs to /papi/consignment-stock-sell', good.apiCall && good.apiCall.url === '/papi/consignment-stock-sell');
  const body = good.apiCall ? String(good.apiCall.opts.body) : '';
  ok('sends qty=10', body.indexOf('qty=10') !== -1);
  ok('sends price=3200', body.indexOf('price=3200') !== -1);
  ok('sends buyer', body.indexOf('buyer=Market') !== -1);
  ok('success toast with order', good.toasts.some(t => t.type === 'ok' && t.msg.indexOf('ORD-9') !== -1));

  const bad = await run('0');
  ok('bad qty warns', bad.toasts.some(t => t.type === 'warn'));
  ok('bad qty does NOT sell', bad.apiCall === null);

  if (fails.length) { console.log('FAIL:\n - ' + fails.join('\n - ')); process.exit(1); }
  console.log('ALL PASS (consignment sell render-sim)');
  process.exit(0);
})();
