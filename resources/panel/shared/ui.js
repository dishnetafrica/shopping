/*
 * CloudBSS shared UI helpers (Roadmap E9.2) — one toast(), one confirmDialog(), and one
 * error-code -> friendly-message map, so every screen gives the same non-blocking feedback instead of
 * prompt()/alert()/confirm(). E9.3/E9.4 migrate the money flows and remaining pages onto these.
 *
 * Exposed as window.CloudBSSUI.{toast,confirmDialog,errorMessage}. It also sets window.toast /
 * window.confirmDialog ONLY when a page hasn't already defined its own, so it never clobbers existing
 * behaviour. Styling uses the shared design tokens (E9.1); a <style> is injected once on first use.
 */
(function (root) {
  var doc = root.document;

  /* error code -> human message. Unknown codes fall back to a generic line. */
  var ERRORS = {
    bad_input: 'Please check the values and try again.',
    bad_amount: 'Enter a valid amount.',
    bad_qty: 'Enter a valid quantity.',
    not_allowed: 'You do not have permission to do that.',
    unauthorized: 'Please sign in again.',
    no_tenant: 'Your workspace could not be loaded. Please sign in again.',
    order_not_found: 'That order could not be found.',
    not_found: 'That item could not be found.',
    supplier_required: 'Choose the supplier this payment is for.',
    not_enough: 'There is not enough stock for that.',
    period_locked: 'That accounting period is locked.',
    stock_v2_off: 'Enable the stock ledger first (stock_v2).',
    empty_csv: 'The file looks empty. Please check it and try again.',
    unknown_report: 'That report does not exist.'
  };

  function errorMessage(code, fallback) {
    if (code && ERRORS[code]) return ERRORS[code];
    return fallback || 'Something went wrong. Please try again.';
  }

  function ensureStyles() {
    if (!doc || doc.getElementById && doc.getElementById('ds-ui-styles')) return;
    var st = doc.createElement('style');
    st.id = 'ds-ui-styles';
    st.textContent =
      '.ds-toast-host{position:fixed;right:16px;bottom:16px;display:flex;flex-direction:column;gap:8px;z-index:9999}' +
      '.ds-toast{min-width:200px;max-width:360px;padding:10px 14px;border-radius:var(--radius-md,9px);' +
      'color:#fff;font-family:var(--font-sans,sans-serif);font-size:var(--fs-sm,13px);font-weight:700;' +
      'box-shadow:var(--shadow-2,0 4px 14px rgba(0,0,0,.15));opacity:.98}' +
      '.ds-toast--info{background:var(--info,#2563A8)}.ds-toast--ok{background:var(--ok,#15803D)}' +
      '.ds-toast--warn{background:var(--warn,#C2700A)}.ds-toast--error{background:var(--danger,#B4232A)}' +
      '.ds-modal-overlay{position:fixed;inset:0;background:rgba(11,61,34,.35);display:flex;' +
      'align-items:center;justify-content:center;z-index:10000}' +
      '.ds-modal{background:var(--surface,#fff);border-radius:var(--radius-lg,14px);padding:20px;' +
      'max-width:400px;width:calc(100% - 40px);box-shadow:var(--shadow-2,0 4px 14px rgba(0,0,0,.2))}' +
      '.ds-modal-msg{font-family:var(--font-sans,sans-serif);font-size:var(--fs-md,15px);color:var(--text,#16231C);margin-bottom:16px}' +
      '.ds-modal-input{width:100%;box-sizing:border-box;padding:9px 11px;font-size:var(--fs-md,15px);' +
      'border:1px solid var(--border,#E2E8E2);border-radius:var(--radius-md,9px);margin-bottom:16px}' +
      '.ds-modal-actions{display:flex;justify-content:flex-end;gap:8px}';
    (doc.head || doc.body || doc.documentElement).appendChild(st);
  }

  function ensureHost() {
    var host = doc.getElementById ? doc.getElementById('ds-toast-host') : null;
    if (!host) {
      host = doc.createElement('div');
      host.id = 'ds-toast-host';
      host.className = 'ds-toast-host';
      (doc.body || doc.documentElement).appendChild(host);
    }
    return host;
  }

  /* non-blocking toast. type: 'info' | 'ok' | 'warn' | 'error'. Returns the element. */
  function toast(message, type) {
    if (!doc) return null;
    ensureStyles();
    type = type || 'info';
    var host = ensureHost();
    var el = doc.createElement('div');
    el.className = 'ds-toast ds-toast--' + type;
    el.textContent = String(message == null ? '' : message);
    host.appendChild(el);
    root.setTimeout(function () { if (el.remove) el.remove(); }, type === 'error' ? 6000 : 3500);
    return el;
  }

  /* promise-based confirm replacing window.confirm. Resolves true/false. */
  function confirmDialog(message, opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      if (!doc) { resolve(false); return; }
      ensureStyles();
      var overlay = doc.createElement('div'); overlay.className = 'ds-modal-overlay';
      var box = doc.createElement('div'); box.className = 'ds-modal';
      var msg = doc.createElement('div'); msg.className = 'ds-modal-msg'; msg.textContent = String(message == null ? '' : message);
      var actions = doc.createElement('div'); actions.className = 'ds-modal-actions';
      var cancel = doc.createElement('button'); cancel.className = 'ds-btn'; cancel.textContent = opts.cancelText || 'Cancel';
      var ok = doc.createElement('button');
      ok.className = 'ds-btn ' + (opts.danger ? 'ds-btn--danger' : 'ds-btn--primary');
      ok.textContent = opts.okText || 'Confirm';

      function done(v) { if (overlay.remove) overlay.remove(); resolve(v); }
      cancel.onclick = function () { done(false); };
      ok.onclick = function () { done(true); };

      actions.appendChild(cancel); actions.appendChild(ok);
      box.appendChild(msg); box.appendChild(actions); overlay.appendChild(box);
      (doc.body || doc.documentElement).appendChild(overlay);

      // expose for keyboard handlers / testing
      overlay.__ok = ok; overlay.__cancel = cancel;
    });
  }

  /* promise-based prompt replacing window.prompt. Resolves the entered string, or null on cancel. */
  function promptDialog(message, opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      if (!doc) { resolve(null); return; }
      ensureStyles();
      var overlay = doc.createElement('div'); overlay.className = 'ds-modal-overlay';
      var box = doc.createElement('div'); box.className = 'ds-modal';
      var msg = doc.createElement('div'); msg.className = 'ds-modal-msg'; msg.textContent = String(message == null ? '' : message);
      var input = doc.createElement('input'); input.className = 'ds-modal-input'; input.type = opts.type || 'text';
      if (opts.value != null) input.value = String(opts.value);
      if (opts.placeholder) input.placeholder = String(opts.placeholder);
      var actions = doc.createElement('div'); actions.className = 'ds-modal-actions';
      var cancel = doc.createElement('button'); cancel.className = 'ds-btn'; cancel.textContent = opts.cancelText || 'Cancel';
      var ok = doc.createElement('button'); ok.className = 'ds-btn ds-btn--primary'; ok.textContent = opts.okText || 'OK';

      function done(v) { if (overlay.remove) overlay.remove(); resolve(v); }
      cancel.onclick = function () { done(null); };
      ok.onclick = function () { done(input.value); };

      actions.appendChild(cancel); actions.appendChild(ok);
      box.appendChild(msg); box.appendChild(input); box.appendChild(actions); overlay.appendChild(box);
      (doc.body || doc.documentElement).appendChild(overlay);
      if (input.focus) { try { input.focus(); } catch (e) {} }

      overlay.__ok = ok; overlay.__cancel = cancel; overlay.__input = input;
    });
  }

  /* promise-based single-choice dialog (dropdown). Resolves the chosen value, or null on cancel.
     `options` is an array of strings or {value,label}. */
  function selectDialog(message, options, opts) {
    opts = opts || {};
    var norm = (options || []).map(function (o) { return (typeof o === 'string') ? { value: o, label: o } : o; });
    return new Promise(function (resolve) {
      if (!doc) { resolve(null); return; }
      ensureStyles();
      var overlay = doc.createElement('div'); overlay.className = 'ds-modal-overlay';
      var box = doc.createElement('div'); box.className = 'ds-modal';
      var msg = doc.createElement('div'); msg.className = 'ds-modal-msg'; msg.textContent = String(message == null ? '' : message);
      var sel = doc.createElement('select'); sel.className = 'ds-modal-input';
      norm.forEach(function (o) { var op = doc.createElement('option'); op.value = o.value; op.textContent = o.label; sel.appendChild(op); });
      if (opts.value != null) sel.value = String(opts.value);
      else if (norm.length) sel.value = String(norm[0].value);
      var actions = doc.createElement('div'); actions.className = 'ds-modal-actions';
      var cancel = doc.createElement('button'); cancel.className = 'ds-btn'; cancel.textContent = opts.cancelText || 'Cancel';
      var ok = doc.createElement('button'); ok.className = 'ds-btn ds-btn--primary'; ok.textContent = opts.okText || 'OK';

      function done(v) { if (overlay.remove) overlay.remove(); resolve(v); }
      cancel.onclick = function () { done(null); };
      ok.onclick = function () { done(sel.value); };

      actions.appendChild(cancel); actions.appendChild(ok);
      box.appendChild(msg); box.appendChild(sel); box.appendChild(actions); overlay.appendChild(box);
      (doc.body || doc.documentElement).appendChild(overlay);

      overlay.__ok = ok; overlay.__cancel = cancel; overlay.__select = sel;
    });
  }

  var api = { toast: toast, confirmDialog: confirmDialog, promptDialog: promptDialog, selectDialog: selectDialog, errorMessage: errorMessage, ERRORS: ERRORS };
  root.CloudBSSUI = api;
  // Only set the bare globals if the page hasn't already defined its own (never clobber).
  if (typeof root.toast !== 'function') root.toast = toast;
  if (typeof root.confirmDialog !== 'function') root.confirmDialog = confirmDialog;

  if (typeof module !== 'undefined' && module.exports) module.exports = api;
})(typeof window !== 'undefined' ? window : this);
