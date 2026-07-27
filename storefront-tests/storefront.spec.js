// End-to-end tests for the EA Auto Part storefront (the single built autoparts.html).
// Runs the REAL deployable file over a real HTTP origin so cart/bike localStorage and the
// analytics beacons behave exactly as in production.
const { test, expect } = require('@playwright/test');
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
