/*
 * Roadmap E9.3 render-simulation test (run: `node tests/render-sim/credit_note_render.js`).
 *
 * Extracts the ACTUAL migrated creditNote() from resources/panel/invoices.html and drives it through
 * the shared dialogs (amount -> reason select -> optional note) against mocked CloudBSSUI + api,
 * asserting it POSTs the entered amount + chosen reason_code + note to /papi/credit-note-issue
 * (unchanged) and shows an 'ok' toast on success. Catches runtime errors a lint cannot.
 */
const fs = require('fs');
const vm = require('vm');
const path = require('path');

const html = fs.readFileSync(path.join(__dirname, '..', '..', 'resources', 'panel', 'invoices.html'), 'utf8');
const m = html.match(/function creditNote\(id,no,cur,bal\)\{[\s\S]*?\n\}/);
if (!m) { console.log('FAIL: creditNote() not found in invoices.html'); process.exit(1); }
const code = m[0];

const fails = [];
function ok(label, cond) { if (!cond) fails.push(label); }

(async () => {
  let apiCall = null; const toasts = [];
  const sb = {
    console, String, parseFloat, parseInt, URLSearchParams, Promise, setTimeout,
    window: { open: () => {} },
    CloudBSSUI: {
      // amount -> '150', reason select -> 'goodwill', optional note -> 'over-charged'
      promptDialog: (msg) => Promise.resolve(msg.indexOf('Optional') !== -1 ? 'over-charged' : '150'),
      selectDialog: (msg, options) => Promise.resolve('goodwill'),
      confirmDialog: () => Promise.resolve(false),
      toast: (msg, type) => { toasts.push({ msg, type }); },
      errorMessage: (c) => 'MSG:' + c
    },
    api: (url, opts) => { apiCall = { url, opts }; return Promise.resolve({ ok: true, credit_note_no: 'CN-1', pdf: '/x.pdf' }); },
    nf: (n) => String(n),
    load: () => {}
  };
  sb.window.CloudBSSUI = sb.CloudBSSUI;
  vm.runInContext(code + '\ncreditNote(3,"INV-3","USD",200);', vm.createContext(sb));
  await new Promise((res) => setTimeout(res, 20));

  ok('POSTs to /papi/credit-note-issue', apiCall && apiCall.url === '/papi/credit-note-issue');
  const body = apiCall ? String(apiCall.opts.body) : '';
  ok('sends amount=150', body.indexOf('amount=150') !== -1);
  ok('sends reason_code=goodwill', body.indexOf('reason_code=goodwill') !== -1);
  ok('sends the note', body.indexOf('notes=over-charged') !== -1);
  ok('success toast shown', toasts.some(t => t.type === 'ok' && t.msg.indexOf('CN-1') !== -1));

  if (fails.length) { console.log('FAIL:\n - ' + fails.join('\n - ')); process.exit(1); }
  console.log('ALL PASS (credit note render-sim)');
  process.exit(0);
})();
