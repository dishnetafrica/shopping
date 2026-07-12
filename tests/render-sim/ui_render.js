/*
 * Roadmap E9.2 render-simulation test (run: `node tests/render-sim/ui_render.js`).
 *
 * Executes the ACTUAL shared UI helpers (resources/panel/shared/ui.js) in a vm against a mock DOM and
 * asserts:
 *   - errorMessage maps known codes and falls back for unknown ones
 *   - toast() creates a typed toast element with the message into a host
 *   - confirmDialog() resolves true on OK and false on Cancel
 *   - window.CloudBSSUI is exposed and the bare globals are set defensively
 */
const fs = require('fs');
const vm = require('vm');
const path = require('path');

const SRC = path.join(__dirname, '..', '..', 'resources', 'panel', 'shared', 'ui.js');
const code = fs.readFileSync(SRC, 'utf8');

const byId = {};
function reg(el) { if (el && el.id) byId[el.id] = el; }
function makeEl() {
  return {
    className: '', id: '', textContent: '', innerHTML: '', style: {}, children: [], onclick: null,
    appendChild: function (c) { this.children.push(c); c.__parent = this; reg(c); return c; },
    remove: function () { this.__removed = true; if (this.__parent) { const i = this.__parent.children.indexOf(this); if (i >= 0) this.__parent.children.splice(i, 1); } },
    setAttribute: function (k, v) { this[k] = v; if (k === 'id') reg(this); }
  };
}

const doc = {
  head: makeEl(), body: makeEl(), documentElement: makeEl(),
  createElement: function () { return makeEl(); },
  getElementById: function (id) { return byId[id] || null; }
};
const sb = { document: doc, console, setTimeout, clearTimeout, Promise, String, module: { exports: {} } };
sb.window = sb;
vm.runInContext(code, vm.createContext(sb));

const fails = [];
function ok(label, cond) { if (!cond) fails.push(label); }

const UI = sb.CloudBSSUI;
ok('CloudBSSUI exposed', UI && typeof UI.toast === 'function' && typeof UI.confirmDialog === 'function');
ok('window.toast set defensively', typeof sb.toast === 'function');

// errorMessage
ok('known error code mapped', UI.errorMessage('not_allowed').indexOf('permission') !== -1);
ok('unknown code -> generic', UI.errorMessage('weird_code').indexOf('went wrong') !== -1);
ok('fallback used', UI.errorMessage('weird_code', 'custom fallback') === 'custom fallback');

// toast
const t = UI.toast('Saved!', 'ok');
ok('toast element created', !!t);
ok('toast has type class', t.className.indexOf('ds-toast--ok') !== -1);
ok('toast has message', t.textContent === 'Saved!');
ok('toast host exists', !!doc.getElementById('ds-toast-host'));
ok('styles injected once', !!doc.getElementById('ds-ui-styles'));

(async () => {
  // confirmDialog OK -> true
  const pOk = UI.confirmDialog('Delete this?', { danger: true, okText: 'Delete' });
  const overlayOk = doc.body.children[doc.body.children.length - 1];
  ok('overlay appended', overlayOk && overlayOk.className === 'ds-modal-overlay');
  ok('danger OK button', overlayOk.__ok.className.indexOf('ds-btn--danger') !== -1);
  overlayOk.__ok.onclick();
  ok('OK resolves true', (await pOk) === true);
  ok('overlay removed after choice', overlayOk.__removed === true);

  // confirmDialog Cancel -> false
  const pNo = UI.confirmDialog('Proceed?');
  const overlayNo = doc.body.children[doc.body.children.length - 1];
  overlayNo.__cancel.onclick();
  ok('Cancel resolves false', (await pNo) === false);

  // promptDialog OK -> the entered value
  ok('promptDialog exposed', typeof UI.promptDialog === 'function');
  const pVal = UI.promptDialog('Amount received:', { value: '250', okText: 'Record payment' });
  const overlayP = doc.body.children[doc.body.children.length - 1];
  ok('prompt prefilled value', overlayP.__input.value === '250');
  overlayP.__input.value = '300';
  overlayP.__ok.onclick();
  ok('promptDialog OK returns edited value', (await pVal) === '300');

  // promptDialog Cancel -> null
  const pCancel = UI.promptDialog('Amount?');
  const overlayPC = doc.body.children[doc.body.children.length - 1];
  overlayPC.__cancel.onclick();
  ok('promptDialog Cancel returns null', (await pCancel) === null);

  // selectDialog -> chosen value; defaults to first option
  ok('selectDialog exposed', typeof UI.selectDialog === 'function');
  const pSel = UI.selectDialog('Reason:', [{ value: 'return', label: 'Return' }, { value: 'goodwill', label: 'Goodwill' }]);
  const overlayS = doc.body.children[doc.body.children.length - 1];
  ok('select defaults to first option', overlayS.__select.value === 'return');
  overlayS.__select.value = 'goodwill';
  overlayS.__ok.onclick();
  ok('selectDialog returns chosen value', (await pSel) === 'goodwill');

  const pSelC = UI.selectDialog('Reason:', ['a', 'b']);
  const overlaySC = doc.body.children[doc.body.children.length - 1];
  overlaySC.__cancel.onclick();
  ok('selectDialog Cancel returns null', (await pSelC) === null);

  if (fails.length) { console.log('FAIL:\n - ' + fails.join('\n - ')); process.exit(1); }
  console.log('ALL PASS (ui render-sim)');
  process.exit(0);
})();
