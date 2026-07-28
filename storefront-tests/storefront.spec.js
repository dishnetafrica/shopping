// End-to-end tests for the EA Auto Part storefront (the single built autoparts.html).
// Runs the REAL deployable file over a real HTTP origin so cart/bike localStorage and the
// analytics beacons behave exactly as in production.
const { test, expect } = require('@playwright/test');
const { AxeBuilder } = require('@axe-core/playwright');
const { start } = require('./server');

let ctx, base;

test.beforeAll(async () => {
  ctx = await start(0);
  base = `http://127.0.0.1:${ctx.port}`;
});

test.afterAll(async () => {
  await new Promise((r) => ctx.server.close(r));
});

// Wait until the SPA has loaded the catalogue (STATE.loaded flips true).
async function waitLoaded(page) {
  await page.waitForFunction(() => window.STATE && window.STATE.loaded === true, { timeout: 15000 });
}

async function events(page) {
  return await page.evaluate(async () => (await fetch('/__events')).json());
}

test('every route renders with no console/runtime errors', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text()); });
  const routes = ['/', '/#/parts', '/#/part/101', '/#/system/Engine', '/#/model/boxer100',
    '/#/plate', '/#/diagrams', '/#/search/brake', '/#/ai', '/#/cart'];
  for (const r of routes) {
    await page.goto(base + r);
    await waitLoaded(page);
    await page.waitForTimeout(60);
  }
  // ignore benign favicon/resource 404s; only fail on real JS errors
  const real = errors.filter((e) => !/favicon|net::ERR|status of 404/i.test(e));
  expect(real, 'runtime errors across routes').toEqual([]);
});

test('no horizontal overflow across small viewports', async ({ page }) => {
  const widths = [320, 360, 390, 412];
  const routes = ['/', '/#/parts', '/#/part/101', '/#/cart', '/#/search/brake', '/#/ai', '/#/model/boxer100', '/#/diagrams'];
  const overflows = [];
  for (const w of widths) {
    await page.setViewportSize({ width: w, height: 800 });
    for (const r of routes) {
      await page.goto(base + r);
      await waitLoaded(page);
      await page.waitForTimeout(40);
      const over = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
      if (over) overflows.push(`@${w}${r}`);
    }
  }
  expect(overflows, 'pages must not scroll horizontally on phones').toEqual([]);
});

test('primary checkout CTA is reachable on a 320px phone', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 640 });
  await page.goto(base + '/#/part/101');
  await waitLoaded(page);
  await page.click('button.btn-p:has-text("Add to cart")');
  await page.goto(base + '/#/cart');
  const btn = page.locator('a.btn-w.big');
  await expect(btn).toBeVisible();
  const box = await btn.boundingBox();
  expect(box.width, 'checkout button fits the viewport').toBeLessThanOrEqual(320);
});

test('authoritative fitment from catalogue cross-reference + Compatible Models UI', async ({ page }) => {
  await page.goto(base + '/');
  await waitLoaded(page);
  const r = await page.evaluate(() => {
    const ctPart = { id: 1, name: 'X', kw: 'CT 125 HD | Engine | Cover | JV 5110 23 | plate 1', cat: 'CT 125 HD > Engine' }; // fits ct125hd + boxer125x
    const boxOnly = { id: 2, name: 'Y', kw: 'Boxer 100 | Engine | Z | PF 5910 00', cat: 'Boxer 100 > Engine' };              // boxer100 only
    localStorage.setItem('ea_bike', 'ct125hd');
    const ctView = { fitsCt: window.fitsBike(ctPart), fitsBoxOnly: window.fitsBike(boxOnly), compat: window.compatibleModels(ctPart).map((m) => m.id).sort() };
    localStorage.setItem('ea_bike', 'boxer100');
    const boxView = { fitsBoxOnly: window.fitsBike(boxOnly), fitsCt: window.fitsBike(ctPart) };
    return { ctView, boxView };
  });
  expect(r.ctView.fitsCt).toBe(true);
  expect(r.ctView.fitsBoxOnly).toBe(false);          // catalogue says this part is Boxer-100 only
  expect(r.ctView.compat).toEqual(['boxer125x', 'ct125hd']);
  expect(r.boxView.fitsBoxOnly).toBe(true);
  expect(r.boxView.fitsCt).toBe(false);
  // The PDP renders a Compatible Models block for a part with a catalogue part number.
  await page.goto(base + '/#/part/101');
  await waitLoaded(page);
  await expect(page.locator('.compat .cmh')).toContainText('Compatible models');
});

test('category sidebar renders on listing pages and filters work', async ({ page }) => {
  await page.goto(base + '/#/system/Engine');
  await waitLoaded(page);
  // sidebar present with category rows + the in-stock filter
  await expect(page.locator('.side')).toHaveCount(1);
  await expect(page.locator('.side .srow', { hasText: 'Engine' }).first()).toBeVisible();
  await expect(page.locator('.side .fchk', { hasText: 'In stock only' })).toBeVisible();
  // toggling a filter updates STATE and re-renders without error
  await page.locator('.side .fchk:has-text("In stock only") input').check();
  const stockOn = await page.evaluate(() => window.STATE.filters.stock);
  expect(stockOn).toBe(true);
  // search page also gets the sidebar
  await page.goto(base + '/#/search/bearing');
  await waitLoaded(page);
  await expect(page.locator('.side')).toHaveCount(1);
});

test('smart fitment: generic parts fit any bike, no false "not for"', async ({ page }) => {
  await page.goto(base + '/');
  await waitLoaded(page);
  const r = await page.evaluate(() => {
    localStorage.setItem('ea_bike', 'ct125hd'); // a real model
    const bearing = { id: 1, name: '6004 Bearing', kw: 'BOXER | Engine | 6004 BEARING', cat: 'BOXER > Engine' };
    const ctPart = { id: 2, name: 'Head Cylinder', kw: 'CT 125 HD | Engine | Cover', cat: 'CT 125 HD > Engine' };
    const otherModel = { id: 3, name: 'Assembly Silencer', kw: 'Boxer 100 | Engine | Silencer', cat: 'Boxer 100 > Engine' };
    return {
      bearing: window.fitsBike(bearing),   // generic -> fits
      ct: window.fitsBike(ctPart),         // same model -> fits
      other: window.fitsBike(otherModel),  // names a different model -> no
    };
  });
  expect(r.bearing).toBe(true);
  expect(r.ct).toBe(true);
  expect(r.other).toBe(false);
});

test('catalogue loads and hides out-of-stock parts', async ({ page }) => {
  await page.goto(base + '/');
  await waitLoaded(page);
  const counts = await page.evaluate(() => ({
    all: window.STATE.allProducts.length,
    shown: window.STATE.products.length,
  }));
  expect(counts.all).toBe(4);      // 4 in the mock catalogue
  expect(counts.shown).toBe(3);    // the stock:0 "Discontinued Gasket" is hidden (hideOOS)
});

test('product view fires a "view" analytics event', async ({ page }) => {
  await page.goto(base + '/#/part/101');
  await waitLoaded(page);
  await expect(page.locator('h1')).toContainText('Clutch Plate Set');
  await page.waitForFunction(async () => {
    const e = await (await fetch('/__events')).json();
    return e.some((x) => x.event === 'view' && String(x.product_id) === '101');
  }, { timeout: 8000 });
});

test('add to cart updates the cart and fires an "add" event', async ({ page }) => {
  await page.goto(base + '/#/part/102');
  await waitLoaded(page);
  await page.click('button.btn-p:has-text("Add to cart")');
  // cart count persisted in localStorage
  const n = await page.evaluate(() => window.cartCount());
  expect(n).toBe(1);
  const e = await events(page);
  expect(e.some((x) => x.event === 'add' && String(x.product_id) === '102')).toBeTruthy();
});

test('cart page shows the added item and total', async ({ page }) => {
  await page.goto(base + '/#/part/103');
  await waitLoaded(page);
  await page.click('button.btn-p:has-text("Add to cart")');
  await page.goto(base + '/#/cart');
  await expect(page.locator('.citems')).toContainText('Air Filter Element');
  const total = await page.evaluate(() => window.cartTotal());
  expect(total).toBeGreaterThan(0);
});

test('checkout button fires a "checkout" event with product ids', async ({ page }) => {
  await page.goto(base + '/#/part/101');
  await waitLoaded(page);
  await page.click('button.btn-p:has-text("Add to cart")');
  await page.goto(base + '/#/cart');
  // The WhatsApp order link opens wa.me in a new tab; prevent navigation, just assert the event.
  await page.evaluate(() => {
    const a = document.querySelector('a.btn-w.big');
    a.setAttribute('href', 'javascript:void 0');
  });
  await page.click('a.btn-w.big');
  const e = await events(page);
  const ck = e.find((x) => x.event === 'checkout');
  expect(ck).toBeTruthy();
  expect(Array.isArray(ck.product_ids)).toBeTruthy();
});

test('search fires a "search" event and shows results', async ({ page }) => {
  await page.goto(base + '/#/search/brake');
  await waitLoaded(page);
  await expect(page.locator('h2')).toContainText('brake');
  const e = await events(page);
  expect(e.some((x) => x.event === 'search')).toBeTruthy();
});

test('escapes hostile catalogue fields (stored XSS)', async ({ page }) => {
  await page.goto(base + '/');
  await waitLoaded(page);
  const r = await page.evaluate(() => {
    window.__xss = 0;
    // 1) name/desc must be escaped when rendered into innerHTML.
    const hostile = {
      id: 555, name: '<img src=x onerror="window.__xss=1">', kw: '', cat: 'Engine',
      price: 100, stock: 5, img: '', desc: "<script>window.__xss=1<\/script>",
    };
    const host = document.createElement('div');
    host.innerHTML = window.prodCard(hostile);
    document.body.appendChild(host);
    // 2) a hostile id must not break out of the onclick JS string.
    const idCard = window.prodCard({ id: "1');window.__xss=1;//", name: 'ok', kw: '', cat: 'Engine', price: 1, stock: 1, img: '' });
    return {
      breakout: /'\);window/.test(idCard),                  // id sink escaped?
      escapedText: window.prodCard(hostile).indexOf('&lt;img') >= 0, // name shown as text?
    };
  });
  // give any (accidentally created) <img onerror> a chance to fire before asserting.
  await page.waitForTimeout(150);
  const xss = await page.evaluate(() => window.__xss);
  expect(xss, 'no injected payload should execute').toBe(0);
  expect(r.breakout, 'hostile id must not break the onclick handler').toBe(false);
  expect(r.escapedText, 'hostile name must render as escaped text').toBe(true);
});

test('uncaught JS errors are reported to the client-error endpoint', async ({ page }) => {
  await page.goto(base + '/');
  await waitLoaded(page);
  // Throw asynchronously so it becomes an uncaught window error (caught by window.onerror).
  await page.evaluate(() => { setTimeout(function () { throw new Error('boom-test-xyz'); }, 0); });
  await page.waitForFunction(async () => {
    const e = await (await fetch('/__jserrors')).json();
    return e.some((x) => (x.message || '').indexOf('boom-test-xyz') >= 0);
  }, { timeout: 8000 });
});

test('makes no third-party requests and loads Font Awesome locally', async ({ page }) => {
  const external = [];
  page.on('request', (req) => {
    const u = req.url();
    if (!u.startsWith(base) && !u.startsWith('data:') && !u.startsWith('blob:')) {
      external.push(u);
    }
  });
  await page.goto(base + '/');
  await waitLoaded(page);
  // No cdnjs / Google Fonts / any off-origin fetch.
  expect(external, 'storefront must be fully self-contained: ' + external.join(', ')).toEqual([]);
  // Font Awesome is available as a self-hosted face.
  const faLoaded = await page.evaluate(async () => {
    await document.fonts.ready;
    return document.fonts.check('16px FontAwesome');
  });
  expect(faLoaded).toBeTruthy();
});

test('no serious/critical accessibility violations on key pages', async ({ page }) => {
  for (const path of ['/', '/#/part/101', '/#/cart', '/#/search/brake']) {
    await page.goto(base + path);
    await waitLoaded(page);
    const r = await new AxeBuilder({ page }).analyze();
    const bad = r.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(bad.map((v) => `${path}:${v.id}`), 'a11y serious/critical').toEqual([]);
  }
});

test('interactive onclick elements are keyboard-operable', async ({ page }) => {
  await page.goto(base + '/#/part/102');
  await waitLoaded(page);
  // The "Add to cart" button is a real <button>; a custom onclick control (e.g. a crumb link)
  // must be focusable and Enter-activatable via the delegated key handler.
  const activatable = await page.evaluate(() => {
    // find a custom (non-native) onclick element that got the role/tabindex enhancement
    const el = document.querySelector('#app [onclick][role="button"][tabindex="0"]');
    return !!el;
  });
  expect(activatable).toBeTruthy();
});

test('WhatsApp float click fires a "whatsapp" event', async ({ page }) => {
  await page.goto(base + '/');
  await waitLoaded(page);
  // The float can be hidden by CSS at small breakpoints; dispatch a real click event
  // directly so we test the analytics wiring regardless of viewport visibility.
  await page.evaluate(() => {
    const a = document.getElementById('waFloat');
    a.setAttribute('href', 'javascript:void 0');
    a.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
  });
  const e = await events(page);
  expect(e.some((x) => x.event === 'whatsapp')).toBeTruthy();
});
