/* CloudBSS shared modal. Self-contained (creates its own #cbModal). Namespaced under window.CB. */
window.CB = window.CB || {};
(function (CB) {
  CB.modal = function (title, html) {
    var m = document.getElementById('cbModal');
    if (!m) {
      m = document.createElement('div'); m.id = 'cbModal';
      m.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:160;display:flex;align-items:center;justify-content:center;padding:18px';
      m.innerHTML = '<div style="background:var(--card,#fff);color:var(--ink,#111);max-width:560px;width:100%;border-radius:14px;overflow:hidden;box-shadow:0 10px 40px rgba(0,0,0,.25)">'
        + '<div style="display:flex;align-items:center;padding:14px 16px;border-bottom:1px solid var(--line,#eee)"><b id="cbModalTitle"></b>'
        + '<button id="cbModalX" style="margin-left:auto;border:0;background:none;font-size:22px;line-height:1;cursor:pointer;color:inherit">&times;</button></div>'
        + '<div id="cbModalBody" style="padding:16px;max-height:70vh;overflow:auto"></div></div>';
      document.body.appendChild(m);
      m.addEventListener('click', function (e) { if (e.target === m) CB.closeModal(); });
      m.querySelector('#cbModalX').addEventListener('click', CB.closeModal);
    }
    m.querySelector('#cbModalTitle').textContent = title || '';
    m.querySelector('#cbModalBody').innerHTML = html || '';
    m.style.display = 'flex';
    return m;
  };
  CB.closeModal = function () { var m = document.getElementById('cbModal'); if (m) m.style.display = 'none'; };
})(window.CB);
