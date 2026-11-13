// Storefront prices must use fixed 2,700,000 grouping, whatever the visitor's device locale is.
// A phone set to English (India) renders a locale-less toLocaleString() as 27,00,000 — reported on a
// Uganda (UGX) shop. Run from the repo root:  node tests/storefront/number-grouping.test.js
const http = require('http'), fs = require('fs'), path = require('path');
let chromium;
try { ({ chromium } = require('playwright')); } catch (e) { ({ chromium } = require('/opt/node-tools/node_modules/playwright-core')); }

const DIR = 'resources/storefront';
let pass = 0, fail = 0;
const check = (n, c, x) => { (c ? pass++ : fail++); console.log((c ? 'PASS ' : 'FAIL ') + n + (x != null ? (' :: ' + x) : '')); };

// 1) Static guard: no storefront template may format a number in the visitor's own locale.
for (const f of fs.readdirSync(DIR).filter(f => f.endsWith('.html'))) {
  const src = fs.readFileSync(path.join(DIR, f), 'utf8');
  const bad = src.match(/toLocaleString\(\s*(undefined[^)]*)?\)/g) || [];
  check('no device-locale number formatting in ' + f, bad.length === 0, bad.length ? bad.length + ' call(s)' : null);
}

// 2) Behaviour: the technology storefront's money() in a browser whose locale is en-IN.
const tech = fs.readFileSync(path.join(DIR, 'technology.html'), 'utf8');
const page = (currency) => tech.replace('__TECH_CONFIG__', JSON.stringify({ currency, name: 'T', base: '', catalogueUrl: '/catalogue' }));
let currency = 'UGX';
const server = http.createServer((req, res) => {
  if (req.url.indexOf('/catalogue') >= 0) { res.writeHead(200, { 'Content-Type': 'application/json' }); res.end(JSON.stringify({ products: [], settings: {} })); return; }
  res.writeHead(200, { 'Content-Type': 'text/html' }); res.end(page(currency));
});
server.listen(0, async () => {
  const port = server.address().port;
  const b = await chromium.launch(process.env.PW_CHROMIUM ? { executablePath: process.env.PW_CHROMIUM } : {});
  const ctx = await b.newContext({ locale: 'en-IN' });
  const pg = await ctx.newPage();

  await pg.goto('http://localhost:' + port + '/'); await pg.waitForTimeout(500);
  // The test only means something if this browser really groups the Indian way by default.
  const deviceStyle = await pg.evaluate(() => (2700000).toLocaleString());
  check('browser locale is Indian-style (test precondition)', deviceStyle === '27,00,000', deviceStyle);
  const ugx = await pg.evaluate(() => money(2700000));
  check('UGX price uses 2,700,000 grouping, no decimals', ugx === 'UGX 2,700,000', ugx);
  const ugxKit = await pg.evaluate(() => money(1568984));
  check('UGX 1,568,984 (Starlink Standard Kit)', ugxKit === 'UGX 1,568,984', ugxKit);

  currency = 'USD';
  await pg.goto('http://localhost:' + port + '/'); await pg.waitForTimeout(500);
  const usd = await pg.evaluate(() => money(2700000));
  check('USD price keeps 2 decimals with 2,700,000 grouping', usd === 'USD 2,700,000.00', usd);

  await b.close(); server.close();
  console.log('\n' + (fail === 0 ? 'ALL PASS' : 'FAILURES') + ': ' + pass + ' passed, ' + fail + ' failed');
  process.exit(fail === 0 ? 0 : 1);
});
