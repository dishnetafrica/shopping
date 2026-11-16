/*
 * AI Sales Coach render-simulation test (run: `node tests/render-sim/coach_render.js`).
 *
 * Runs the ACTUAL coachSuggest() from resources/v3/shell.js against stubs and proves: the suggestion
 * lands in the composer for the salesperson to edit, the reasoning shows above it, NOTHING is sent
 * (the only request is the coach's own), a refusal is shown without touching the composer, and with no
 * composer on screen the Reply form opens pre-filled instead.
 */
const fs = require('fs');
const vm = require('vm');
const path = require('path');

const root = path.join(__dirname, '..', '..');
const src = fs.readFileSync(path.join(root, 'resources', 'v3', 'shell.js'), 'utf8');
const fails = [];
function ok(label, cond) { console.log((cond ? 'PASS ' : 'FAIL ') + label); if (!cond) fails.push(label); }

const start = src.indexOf('async function coachSuggest(');
const end = src.indexOf('\n}\n', start);
ok('found coachSuggest in shell.js', start > 0 && end > start);
const code = src.slice(start, end + 2);
ok('doAction routes coach actions to coachSuggest', /if\(a\.coach\)\{ return coachSuggest\(a\); \}/.test(src));

function run(response, withComposer) {
  const els = {};
  if (withComposer) {
    els.cmpin = { value: 'old draft', focused: false, focus() { this.focused = true; } };
    els.cmpcoach = { style: {}, innerHTML: '' };
  }
  const calls = [];
  const sb = {
    String, Object, JSON,
    window: { __THREAD_COACH__: { key: 'coach', endpoint: '/api/v1/workspace/chat/7/coach' }, __THREAD_REPLY__: { key: 'reply', endpoint: '/papi/chats/send', body: { phone: '2119' }, labels: { body: 'Message' } } },
    document: { getElementById: id => els[id] || null },
    esc: s => String(s).replace(/</g, '&lt;'),
    toasts: [], forms: [],
    post: async (ep, body) => { calls.push(ep); return response; },
  };
  sb.toast = (m) => sb.toasts.push(m);
  sb.formModal = (a) => sb.forms.push(a);
  vm.createContext(sb);
  vm.runInContext(code, sb);
  return vm.runInContext('coachSuggest()', sb).then(() => ({ els, calls, sb }));
}

(async () => {
  const good = { ok: true, suggestion: 'Hi Akol, which area are you in?\nA landmark helps.', why: 'Coverage depends on the exact place.', check: 'Confirm the area <b>', used: ['Juba fibre coverage'] };

  let r = await run(good, true);
  ok('the suggestion is put in the composer, one line, for editing', r.els.cmpin.value === 'Hi Akol, which area are you in? A landmark helps.');
  ok('…and the composer is focused', r.els.cmpin.focused);
  ok('the reasoning is shown', r.els.cmpcoach.innerHTML.includes('Coverage depends on the exact place.'));
  ok('the check is shown, escaped', r.els.cmpcoach.innerHTML.includes('Confirm the area &lt;b>'));
  ok('what it was based on is shown', r.els.cmpcoach.innerHTML.includes('Juba fibre coverage'));
  ok('NOTHING is sent: the only request is the coach', JSON.stringify(r.calls) === JSON.stringify(['/api/v1/workspace/chat/7/coach']));

  r = await run({ ok: false, error: 'ai_not_configured', message: 'The AI key is not configured' }, true);
  ok('a refusal is explained', r.els.cmpcoach.innerHTML.includes('The AI key is not configured'));
  ok('…and the salesperson\'s own draft is left alone', r.els.cmpin.value === 'old draft');

  r = await run(good, false);
  ok('no composer on screen: the Reply form opens pre-filled', r.sb.forms.length === 1 && r.sb.forms[0].prefill.body === good.suggestion);
  ok('…through the normal reply endpoint', r.sb.forms[0].endpoint === '/papi/chats/send');
  ok('…still nothing sent by the coach', JSON.stringify(r.calls) === JSON.stringify(['/api/v1/workspace/chat/7/coach']));

  const served = fs.readFileSync(path.join(root, 'public', 'assets', 'v3', 'shell.js'), 'utf8');
  ok('served copy public/assets/v3/shell.js is identical to the source', served === src);

  console.log('\n' + (fails.length === 0 ? 'ALL PASS' : 'FAILURES: ' + fails.length));
  process.exit(fails.length === 0 ? 0 : 1);
})();
