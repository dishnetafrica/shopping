// Minimal test server that mirrors what StorefrontController serves in production:
//  - GET  /            -> autoparts.html with the 3 runtime placeholders injected (config + SEO)
//  - GET  /catalogue   -> a small deterministic mock catalogue (same JSON shape as production)
//  - POST /event       -> records analytics events into an in-memory log (like recordEvent)
//  - GET  /__events     -> the captured event log (test-only introspection)
//
// This lets the Playwright suite exercise the REAL built storefront file over a real HTTP origin,
// so localStorage (cart/bike) and navigator.sendBeacon(/event) behave exactly as in production.
const http = require('http');
const fs = require('fs');
const path = require('path');

const HTML_PATH = process.env.STOREFRONT_HTML ||
  path.resolve(__dirname, '../resources/storefront/autoparts.html');

// A tiny catalogue: 3 in-stock parts, 1 explicitly out-of-stock (stock 0) to test hideOOS.
const CATALOGUE = {
  rows: [
    { id: 101, name: 'Clutch Plate Set', category: 'Boxer 100 > Engine > Clutch Assembly',
      price: 45000, stock: 12, keywords: 'Engine | Clutch Assembly | DF 1810 24', image: '',
      description: 'Genuine friction plate set.' },
    { id: 102, name: 'Front Brake Shoe', category: 'Boxer 100 > Brakes > Front Brake',
      price: 22000, stock: 5, keywords: 'Brakes | Front Brake | HB 2201 10', image: '',
      description: 'Front drum brake shoe.' },
    { id: 103, name: 'Air Filter Element', category: 'Boxer 100 > Fuel System > Air Filter',
      price: 18000, stock: 30, keywords: 'Fuel System | Air Filter | AF 3010 55', image: '',
      description: 'Paper air filter element.' },
    { id: 104, name: 'Discontinued Gasket', category: 'Boxer 100 > Engine > Cylinder Head',
      price: 9000, stock: 0, keywords: 'Engine | Cylinder Head | CG 0000 00', image: '',
      description: 'Out of stock — should be hidden when hideOOS on.' },
  ],
};

const CONFIG = {
  name: 'EA Auto Part',
  initials: 'EA',
  eyebrow: 'Genuine Spares',
  waNumber: '211900000000',
  currency: { symbol: 'SSP ' },
  catalogueUrl: '/catalogue',
  eventUrl: '/event',
  shopUrl: '/shop',
  hideOOS: true,
  enabledModels: ['boxer100', 'boxer125hd'],
  systems: [
    { name: 'Engine' }, { name: 'Brakes' }, { name: 'Fuel System' },
    { name: 'Transmission' }, { name: 'Electrical' }, { name: 'Suspension' },
  ],
  genuineLine: '100% genuine or your money back',
  deliveryEta: 'Delivery in Juba within 24h',
  payMethods: 'Mobile Money · Pay on Delivery',
  warranty: '1-year warranty',
  returns: 'Returns on unfitted parts',
};

const events = [];

function injectRuntime(html) {
  return html
    .replace('__AUTOPARTS_CONFIG__', JSON.stringify(CONFIG))
    .replace('__SEO_TITLE__', 'EA Auto Part — Genuine Bajaj Spares')
    .replace('__SEO_META__', '<meta name="description" content="Genuine Bajaj motorcycle spare parts">');
}

function start(port = 0) {
  const html = injectRuntime(fs.readFileSync(HTML_PATH, 'utf8'));
  const server = http.createServer((req, res) => {
    const url = req.url.split('?')[0];
    if (req.method === 'GET' && (url === '/' || url === '/index.html')) {
      res.writeHead(200, { 'Content-Type': 'text/html' });
      return res.end(html);
    }
    if (req.method === 'GET' && url === '/catalogue') {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      return res.end(JSON.stringify(CATALOGUE));
    }
    if (req.method === 'POST' && url === '/event') {
      let body = '';
      req.on('data', (c) => (body += c));
      req.on('end', () => {
        try { events.push(JSON.parse(body || '{}')); } catch (e) { events.push({ parseError: true }); }
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: true }));
      });
      return;
    }
    if (req.method === 'GET' && url === '/__events') {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      return res.end(JSON.stringify(events));
    }
    res.writeHead(404); res.end('not found');
  });
  return new Promise((resolve) => {
    server.listen(port, '127.0.0.1', () => {
      resolve({ server, port: server.address().port, events });
    });
  });
}

module.exports = { start, CATALOGUE, CONFIG };
