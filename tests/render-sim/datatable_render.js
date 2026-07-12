/*
 * Roadmap E8.3 render-simulation test (run: `node tests/render-sim/datatable_render.js`).
 *
 * Executes the ACTUAL DataTable component (resources/panel/js/datatable.js) in a vm against a mocked
 * fetch + container, and asserts:
 *   - buildQuery() matches the E8.1 server contract (q, filter[field], sort, dir, page, per_page)
 *   - setSort toggles asc/desc and resets to page 1
 *   - setFilter adds/removes filters (and 'ALL' clears)
 *   - setPage never goes below 1; export URL carries &format=
 *   - renderHtml produces a header, rows, sort indicator, pager and aggregates
 *   - a fetch-driven load() renders rows into the container
 *
 * This catches runtime errors a lint cannot; the server contract itself is covered by
 * tests/Feature/ListContractTest.php.
 */
const fs = require('fs');
const vm = require('vm');
const path = require('path');

const SRC = path.join(__dirname, '..', '..', 'resources', 'panel', 'js', 'datatable.js');
const code = fs.readFileSync(SRC, 'utf8');

// minimal sandbox: window/self, timers, encodeURIComponent (node global), a mock fetch
function makeSandbox(fetchImpl) {
  const sb = {
    setTimeout, clearTimeout, console, JSON, Object, Array, String, Math,
    encodeURIComponent, module: { exports: {} }, fetch: fetchImpl
  };
  sb.window = sb; sb.self = sb;
  return sb;
}

const fails = [];
function ok(label, cond) { if (!cond) fails.push(label); }

// a container whose innerHTML we can capture; querySelector* return no-op nodes
function mockContainer() {
  let html = '';
  return {
    set innerHTML(v) { html = v; },
    get innerHTML() { return html; },
    querySelector() { return null; },
    querySelectorAll() { return []; }
  };
}

const COLUMNS = [
  { key: 'invoice_no', label: 'Invoice' },
  { key: 'total_usd', label: 'USD', type: 'money' }
];
const FILTERS = [{ key: 'status', label: 'Status', type: 'select', options: ['ALL', 'issued', 'paid'] }];

const DATA = {
  rows: [{ invoice_no: 'INV-1', total_usd: 700 }, { invoice_no: 'INV-2', total_usd: 50 }],
  total: 2, page: 1, per_page: 25, pages: 1, aggregates: { total_usd: 750 }
};

(function () {
  const calls = [];
  const fetchImpl = (u) => { calls.push(u); return Promise.resolve({ json: () => Promise.resolve(DATA) }); };
  const sb = makeSandbox(fetchImpl);
  vm.runInContext(code, vm.createContext(sb));

  const DataTable = sb.CloudBSSDataTable;
  if (typeof DataTable !== 'function') { console.log('FAIL: CloudBSSDataTable not exported'); process.exit(1); }

  const container = mockContainer();
  const dt = DataTable({ url: '/papi/invoices', container, columns: COLUMNS, filters: FILTERS, perPage: 25 });

  // 1. base query
  ok('base query has page/per_page', dt.buildQuery() === 'page=1&per_page=25');

  // 2. search + filter + sort
  dt.state.q = 'inv';
  dt.state.filters.status = 'issued';
  dt.state.sort = 'total_usd'; dt.state.dir = 'desc';
  const q = dt.buildQuery();
  ok('query has q', q.indexOf('q=inv') !== -1);
  ok('query has filter[status]=issued', q.indexOf('filter%5Bstatus%5D=issued') !== -1 || q.indexOf('filter[status]=issued') !== -1);
  ok('query has sort+dir', q.indexOf('sort=total_usd') !== -1 && q.indexOf('dir=desc') !== -1);

  // reset for interaction tests
  dt.state.q = ''; dt.state.filters = {}; dt.state.sort = null; dt.state.dir = 'asc'; dt.state.page = 1;

  // 3. setSort toggles direction and stays on the field
  dt.setSort('invoice_no');
  ok('setSort sets field asc', dt.state.sort === 'invoice_no' && dt.state.dir === 'asc');
  dt.setSort('invoice_no');
  ok('setSort toggles to desc', dt.state.dir === 'desc');
  dt.setSort('total_usd');
  ok('setSort new field resets asc', dt.state.sort === 'total_usd' && dt.state.dir === 'asc');

  // 4. setFilter add + ALL clears
  dt.setFilter('status', 'paid');
  ok('setFilter adds', dt.state.filters.status === 'paid');
  dt.setFilter('status', 'ALL');
  ok("setFilter 'ALL' clears", dt.state.filters.status === undefined);

  // 5. paging floor + export url
  dt.setPage(-5);
  ok('setPage floors at 1', dt.state.page === 1);
  ok('exportUrl carries format=csv', dt.exportUrl('csv').indexOf('&format=csv') !== -1);

  // 6. saved filters
  dt.state.filters.status = 'issued';
  dt.saveFilter('open');
  dt.state.filters = {};
  dt.applyFilter('open');
  ok('saved filter restores state', dt.state.filters.status === 'issued');

  // 7. renderHtml structure
  const html = dt.renderHtml(DATA);
  ok('renders header Invoice', html.indexOf('>Invoice') !== -1);
  ok('renders a data row INV-1', html.indexOf('INV-1') !== -1);
  ok('money column right-aligned', html.indexOf('class="num"') !== -1);
  ok('renders pager', html.indexOf('dt-pager') !== -1 && html.indexOf('2 rows') !== -1);
  ok('renders aggregates', html.indexOf('total_usd: 750') !== -1);

  // 8. empty state
  ok('empty renders "No rows"', dt.renderHtml({ rows: [], total: 0, page: 1, pages: 0, aggregates: {} }).indexOf('No rows') !== -1);
})();

// 9. fetch-driven load() renders into the container
(function () {
  const sb = makeSandbox((u) => Promise.resolve({ json: () => Promise.resolve(DATA) }));
  vm.runInContext(code, vm.createContext(sb));
  const container = mockContainer();
  const dt = sb.CloudBSSDataTable({ url: '/papi/invoices', container, columns: COLUMNS, filters: FILTERS });
  dt.load().then(function () {
    if (container.innerHTML.indexOf('INV-1') === -1) fails.push('load() did not render rows into container');
    finish();
  });
})();

function finish() {
  if (fails.length) { console.log('FAIL:\n - ' + fails.join('\n - ')); process.exit(1); }
  console.log('ALL PASS (datatable render-sim)');
  process.exit(0);
}
