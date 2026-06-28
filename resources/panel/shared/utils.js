/* CloudBSS shared UI utils. Namespaced under window.CB. */
window.CB = window.CB || {};
(function (CB) {
  CB.esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };
  CB.money = function (n, cur) { return (cur ? cur + ' ' : '') + Number(n || 0).toLocaleString(); };
  CB.toast = function (msg) {
    var el = document.getElementById('cbToast');
    if (!el) {
      el = document.createElement('div'); el.id = 'cbToast';
      el.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:#111;color:#fff;padding:10px 16px;border-radius:10px;font-size:14px;z-index:200;opacity:0;transition:opacity .2s;max-width:90vw';
      document.body.appendChild(el);
    }
    el.textContent = msg; el.style.opacity = '1';
    clearTimeout(CB._tt); CB._tt = setTimeout(function () { el.style.opacity = '0'; }, 2600);
  };
})(window.CB);
