/* CloudBSS shared API helper. Wraps fetch with the panel's ?token= auth + JSON + error handling.
   Namespaced under window.CB so it never clashes with a page's own globals. */
window.CB = window.CB || {};
(function (CB) {
  function token() {
    try { return CB._token || (new URLSearchParams(location.search)).get('token') || ''; }
    catch (e) { return ''; }
  }
  CB.setToken = function (t) { CB._token = t || ''; };
  CB.api = function (path, params) {
    var u = path + (path.indexOf('?') >= 0 ? '&' : '?') + 'token=' + encodeURIComponent(token());
    if (params) Object.keys(params).forEach(function (k) {
      u += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
    });
    return fetch(u, { cache: 'no-store' })
      .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Bad response' }; }); })
      .catch(function () { return { ok: false, message: 'Network error' }; });
  };
})(window.CB);
