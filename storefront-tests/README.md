# Storefront end-to-end tests

Automated Playwright tests for the EA Auto Part storefront — the single built
`resources/storefront/autoparts.html`. They run the **real deployable file** over a real
HTTP origin (so `localStorage` cart/bike state and the `navigator.sendBeacon('/event')`
analytics behave exactly as in production), against a small deterministic mock catalogue.

## What is covered

| Test | Critical path |
|---|---|
| catalogue loads / hides out-of-stock | `loadCatalogue`, `hideOOS` filter |
| product view fires `view` | product page + analytics |
| add to cart fires `add` | cart persistence + analytics |
| cart page shows item & total | cart rendering |
| checkout fires `checkout` | WhatsApp order + analytics |
| search fires `search` | search view + analytics |
| WhatsApp float fires `whatsapp` | contact CTA + analytics |

Each runs on **desktop** and a **360×740 (cheap Android)** viewport.

## Run

```bash
cd storefront-tests
npm install
npx playwright test          # uses /opt/pw-browsers/chromium if present, else system Chromium
```

Point the tests at a specific build with `STOREFRONT_HTML=/path/to/autoparts.html`.

## How it works

`server.js` injects the 3 runtime placeholders (`__AUTOPARTS_CONFIG__`, `__SEO_TITLE__`,
`__SEO_META__`) exactly like `StorefrontController::autoParts()` does, then serves
`/catalogue` and captures `POST /event` in memory (readable at `/__events`). This mirrors
the production request path without needing a running Laravel app.

## Rebuilding the storefront before testing

```bash
python3 ../../build_storefront.py     # autoparts_spa.html + build/*.json -> autoparts.html
```
