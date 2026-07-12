/*
 * CloudBSS DataTable (Roadmap E8.3) — the ONE register component.
 *
 * A framework-free table that consumes the shared server list contract (E8.1:
 * q, filter[], sort, dir, page, per_page -> rows, total, pages, aggregates) and gives every
 * register the SAME behaviour: search, whitelist filters, sortable columns, a pager, column
 * show/hide, saved filters, and CSV/PDF export (E8.2) via ?format=. Registers are retrofitted onto
 * it in E8.4/E8.5 behind the table_v2 flag.
 *
 * Design: the core is pure and side-effect-free (buildQuery / renderHtml / state transitions) so it
 * is render-simulation testable (tests/render-sim/datatable_render.js). mount() wires DOM events in
 * the real browser but is best-effort, so the pure core never depends on a live DOM.
 *
 * Usage:
 *   var dt = CloudBSSDataTable({
 *     url: '/papi/invoices', container: document.querySelector('#reg'),
 *     columns: [{key:'invoice_no',label:'Invoice'},{key:'total_usd',label:'USD',type:'money'}],
 *     filters: [{key:'status',label:'Status',type:'select',options:['ALL','issued','paid']}],
 *     perPage: 25
 *   });
 *   dt.load();
 */
(function (root) {
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function CloudBSSDataTable(opts) {
    opts = opts || {};
    var columns = opts.columns || [];
    var filters = opts.filters || [];
    var hidden = {}; // column key -> true when hidden

    var state = {
      q: '',
      filters: {},
      sort: null,
      dir: 'asc',
      page: 1,
      per_page: opts.perPage || 25
    };

    var savedFilters = []; // [{name, state}]

    function buildQuery() {
      var p = [];
      if (state.q) p.push('q=' + encodeURIComponent(state.q));
      Object.keys(state.filters).forEach(function (k) {
        p.push('filter[' + encodeURIComponent(k) + ']=' + encodeURIComponent(state.filters[k]));
      });
      if (state.sort) {
        p.push('sort=' + encodeURIComponent(state.sort));
        p.push('dir=' + encodeURIComponent(state.dir));
      }
      p.push('page=' + state.page);
      p.push('per_page=' + state.per_page);
      return p.join('&');
    }

    function url(extra) {
      return opts.url + '?' + buildQuery() + (extra || '');
    }

    function exportUrl(format) {
      return url('&format=' + encodeURIComponent(format || 'csv'));
    }

    function visibleColumns() {
      return columns.filter(function (c) { return !hidden[c.key]; });
    }

    function renderToolbar() {
      var f = filters.map(function (fl) {
        if (fl.type === 'select') {
          var optsHtml = (fl.options || []).map(function (o) {
            var sel = (state.filters[fl.key] === o) ? ' selected' : '';
            return '<option value="' + esc(o) + '"' + sel + '>' + esc(o) + '</option>';
          }).join('');
          return '<select class="dt-filter" data-filter="' + esc(fl.key) + '">' + optsHtml + '</select>';
        }
        return '<input class="dt-filter" data-filter="' + esc(fl.key) + '" placeholder="' +
          esc(fl.label || fl.key) + '" value="' + esc(state.filters[fl.key] || '') + '">';
      }).join('');
      return '<div class="dt-toolbar">' +
        '<input class="dt-search" placeholder="Search…" value="' + esc(state.q) + '">' +
        f +
        '<span class="dt-actions">' +
          '<a class="dt-export" data-format="csv" href="' + esc(exportUrl('csv')) + '">CSV</a>' +
          '<a class="dt-export" data-format="pdf" href="' + esc(exportUrl('pdf')) + '">PDF</a>' +
        '</span></div>';
    }

    function renderHead() {
      return visibleColumns().map(function (c) {
        var sortable = c.sortable !== false;
        var ind = (state.sort === c.key) ? (state.dir === 'asc' ? ' ▲' : ' ▼') : '';
        return '<th' + (sortable ? ' class="dt-sortable" data-sort="' + esc(c.key) + '"' : '') + '>' +
          esc(c.label || c.key) + ind + '</th>';
      }).join('');
    }

    function renderBody(rows) {
      var cols = visibleColumns();
      if (!rows || !rows.length) {
        return '<tr><td class="dt-empty" colspan="' + cols.length + '">No rows</td></tr>';
      }
      return rows.map(function (r) {
        return '<tr>' + cols.map(function (c) {
          var num = (c.type === 'number' || c.type === 'money');
          return '<td class="' + (num ? 'num' : '') + '">' + esc(r[c.key]) + '</td>';
        }).join('') + '</tr>';
      }).join('');
    }

    function renderAggregates(data) {
      var agg = (data && data.aggregates) || {};
      var keys = Object.keys(agg);
      if (!keys.length) return '';
      var cells = keys.map(function (k) { return esc(k) + ': ' + esc(agg[k]); }).join(' · ');
      return '<tfoot><tr><td class="dt-aggr" colspan="' + visibleColumns().length + '">' + cells + '</td></tr></tfoot>';
    }

    function renderPager(data) {
      var page = data.page || state.page;
      var pages = data.pages || 0;
      var total = data.total || 0;
      var prevDis = page <= 1 ? ' disabled' : '';
      var nextDis = (pages && page >= pages) ? ' disabled' : '';
      return '<div class="dt-pager">' +
        '<button class="dt-prev"' + prevDis + '>Prev</button>' +
        '<span class="dt-pageinfo">Page ' + page + ' of ' + (pages || 1) + ' · ' + total + ' rows</span>' +
        '<button class="dt-next"' + nextDis + '>Next</button>' +
        '</div>';
    }

    function renderHtml(data) {
      data = data || { rows: [], total: 0, page: state.page, per_page: state.per_page, pages: 0, aggregates: {} };
      return '<div class="dt">' +
        renderToolbar() +
        '<table class="dt-table"><thead><tr>' + renderHead() + '</tr></thead>' +
        '<tbody>' + renderBody(data.rows) + '</tbody>' +
        renderAggregates(data) +
        '</table>' +
        renderPager(data) +
        '</div>';
    }

    function render(data) {
      if (opts.container) opts.container.innerHTML = renderHtml(data);
      mount();
    }

    function load() {
      return root.fetch(url()).then(function (r) { return r.json(); }).then(function (data) {
        render(data);
        return data;
      });
    }

    // Best-effort DOM wiring in a real browser; the pure core above does not need it.
    function mount() {
      var c = opts.container;
      if (!c || typeof c.querySelector !== 'function') return;
      var s = c.querySelector('.dt-search');
      if (s) s.oninput = debounce(function () { api.setSearch(s.value); }, 300);
      (c.querySelectorAll('.dt-sortable') || []).forEach(function (th) {
        th.onclick = function () { api.setSort(th.getAttribute('data-sort')); };
      });
      (c.querySelectorAll('.dt-filter') || []).forEach(function (el) {
        el.onchange = function () { api.setFilter(el.getAttribute('data-filter'), el.value); };
      });
      var prev = c.querySelector('.dt-prev'); if (prev) prev.onclick = function () { api.setPage(state.page - 1); };
      var next = c.querySelector('.dt-next'); if (next) next.onclick = function () { api.setPage(state.page + 1); };
    }

    function debounce(fn, ms) {
      var t;
      return function () { var a = arguments, self = this; clearTimeout(t); t = setTimeout(function () { fn.apply(self, a); }, ms); };
    }

    var api = {
      state: state,
      buildQuery: buildQuery,
      renderHtml: renderHtml,
      url: url,
      exportUrl: exportUrl,
      load: load,
      setSearch: function (q) { state.q = q || ''; state.page = 1; return load(); },
      setSort: function (field) {
        if (state.sort === field) { state.dir = state.dir === 'asc' ? 'desc' : 'asc'; }
        else { state.sort = field; state.dir = 'asc'; }
        state.page = 1; return load();
      },
      setFilter: function (key, value) {
        if (value === '' || value == null || value === 'ALL') delete state.filters[key];
        else state.filters[key] = value;
        state.page = 1; return load();
      },
      setPage: function (p) { state.page = Math.max(1, p | 0); return load(); },
      setPerPage: function (n) { state.per_page = Math.max(1, n | 0); state.page = 1; return load(); },
      toggleColumn: function (key) { hidden[key] = !hidden[key]; return load(); },
      savedFilters: savedFilters,
      saveFilter: function (name) {
        savedFilters.push({ name: name, state: { q: state.q, filters: JSON.parse(JSON.stringify(state.filters)), sort: state.sort, dir: state.dir } });
        return savedFilters.length;
      },
      applyFilter: function (name) {
        var f = savedFilters.filter(function (x) { return x.name === name; })[0];
        if (!f) return false;
        state.q = f.state.q; state.filters = JSON.parse(JSON.stringify(f.state.filters));
        state.sort = f.state.sort; state.dir = f.state.dir; state.page = 1;
        load(); return true;
      }
    };
    return api;
  }

  root.CloudBSSDataTable = CloudBSSDataTable;
  if (typeof module !== 'undefined' && module.exports) module.exports = CloudBSSDataTable;
})(typeof window !== 'undefined' ? window : this);
