# EA Auto Part — Storefront Hardening Plan

**Purpose.** This document answers the production-readiness review ("70–75% ready; freeze
new feature work and modularize the storefront with automated tests, analytics, and
production observability"). It records what already exists in the codebase (with evidence),
what this hardening milestone delivered, and the prioritized roadmap to a genuinely
production-ready storefront.

The review was done from the ZIP's README and the built HTML, not the backend, so a few of
its "missing" items already exist. Those are corrected below with file references — not to
score points, but so we don't rebuild things that are already there.

---

## 1. What already existed (verified in code)

| Review concern | Reality | Evidence |
|---|---|---|
| No analytics | Endpoint + storage existed; the **frontend wasn't calling it** (real gap, now fixed) | `StorefrontController::recordEvent()`, `product_events`, `ProductEvents::log/logMany` |
| No monitoring / observability | Request-id + build stamp on every response; Sentry hooks; structured error log + throttled webhook alert | `app/Http/Middleware/RequestId.php`; `bootstrap/app.php` `withExceptions()` |
| No rate limiting | `/event` IP-throttled 600/min; a `throttle:30,1` route group exists | `recordEvent()` RateLimiter; `routes/web.php:37` |
| CSRF would block beacons | `/event`, `/order`, `/quote` are CSRF-exempt (correct for public POST APIs) | `bootstrap/app.php` `validateCsrfTokens(except: …)` |
| No SEO | OpenGraph, canonical, schema.org (Organization + FAQPage), sitemap route | `StorefrontController::autoParts()`, `sitemap()` |

**Net:** the platform (multi-tenant Laravel, settings hub, verticals) is genuinely strong.
The weak spot the review correctly identified is the **storefront delivery layer**: one large
`autoparts.html`, no automated tests, and analytics that were never wired on the client.

---

## 2. Delivered in this milestone

1. **Analytics wired end-to-end.** The storefront now emits six events via
   `navigator.sendBeacon('/event')` (falls back to `fetch`):
   `view` (product page), `add` (add-to-cart), `checkout` (single-part *Order now* and cart
   *Place order*), `search` (query + result count), and `whatsapp` (float CTA click).
   Backend `recordEvent()` allow-list extended to accept `search` and `whatsapp`
   (`StorefrontController.php`). These feed the existing `product_events` table and the
   Trending rail, and are the raw signal for checkout-drop-off analysis.

2. **Reproducible build pipeline.** `build_storefront.py` assembles the deployable
   `resources/storefront/autoparts.html` from the `autoparts_spa.html` template + the
   `bajaj-genuine-parts/build/*.json` data files, and *fails the build* if any data
   placeholder is left unresolved. This is the first concrete step of modularization:
   **data is fully separated from the template**, and the single deployable file is now a
   build artifact, not a hand-edited 3.7 MB blob.

3. **Automated test suite** (`storefront-tests/`, Playwright). Fourteen tests (7 scenarios ×
   desktop + 360 px mobile) run the **real built file** over a real HTTP origin against a
   mock catalogue, asserting: catalogue load, out-of-stock hiding, product view, add-to-cart
   + persistence, cart totals, checkout, search, WhatsApp click — and that each fires the
   correct analytics event. All passing.

---

## 3. Roadmap (prioritized)

### P0 — before scaling traffic  ✅ DONE (this milestone)

**Self-host Font Awesome and fonts. ✅** FA 4.7, Inter (5 weights) and JetBrains Mono are now
vendored into the storefront as embedded woff2 `@font-face` rules (`vendor_fonts.py`), and the
three third-party origins (`cdnjs.cloudflare.com`, `fonts.googleapis.com`, `fonts.gstatic.com`)
are gone. A blocked or slow CDN — a real risk on South Sudan connectivity — no longer breaks
icons or fonts. A test asserts the storefront makes **zero** off-origin requests and that
FontAwesome loads locally.

**XSS audit of the SPA. ✅** `esc()` now also escapes single quotes. Product/plate/problem
**ids** — which flow into single-quoted JS strings inside `onclick` handlers — are run through
a new `sid()` whitelist (`[^\w-]` stripped), closing the one real stored-XSS vector (a product
id like `');...//` breaking out of the handler). Display fields (name, desc, keywords) were
already `esc()`'d. A regression test injects `<img onerror>` / `<script>` into name/desc and a
breakout payload into an id, and asserts nothing executes.

**Order-path bot protection. ✅** `StorefrontController::botGuard()` adds a honeypot (hidden
`website`/`company_url`/`hp_email` fields → fake success so bots don't learn they were caught)
and a per-IP + tenant rate limit (12 submits/min) to `placeOrder` and `requestQuote`. The
retail checkout form now carries the hidden honeypot input. A PHPUnit feature test covers both
the honeypot and the rate limit (`tests/Feature/StorefrontBotGuardTest.php`).

### P1 — observability of the funnel  ✅ DONE (this milestone)

**Checkout-drop-off + abandoned-cart view. ✅** The read side already existed as a panel
capability (`StorefrontInsightsCapability` — views → add → checkout funnel, top searches,
search gaps). Two gaps closed: (1) storefront `search` events now also write to `search_events`
with the query text (`recordEvent`), so web searches feed the existing "Top searches"/"Search
gaps" reports (previously only the bot's did); (2) a new **Abandoned** figure + tab surfaces
products added to a cart but never checked out (the actionable follow-up list).

**Client-side error capture. ✅** The SPA now beacons `window.onerror` /
`unhandledrejection` to a new `/clienterror` endpoint (`StorefrontController::clientError`),
which logs structured and forwards to Sentry if configured — so front-end crashes surface in
the same place as server errors. Capped at 5/session client-side and IP-rate-limited server
side. (Confirming the production Sentry DSN is set remains an ops step.)

### P2 — accessibility & mobile

**Accessibility pass. ✅** A runtime enhancement gives every `onclick` control (that isn't a
native button/link) `role="button"` + `tabindex`, with a delegated Enter/Space handler, while
skipping containers that wrap their own controls (no nested-interactive). Added visible
`:focus-visible` rings, `aria-label`s on icon-only controls, an `aria-live` toast, a `<main>`
landmark, and darkened the green/WhatsApp colors to meet AA contrast. An axe-core check runs
on home/product/cart/search and **fails the build on any serious/critical violation** (now
zero; a few moderate best-practice items — heading order, landmark region — remain tracked).

**Mobile breakpoint matrix. ✅** The suite now asserts **no horizontal overflow** at 320 / 360
/ 390 / 412 px across eight routes, and that the checkout CTA fits a 320 px phone. This caught
and fixed a real bug: the AI-assistant input row overflowed at 320 px (a flex child without
`min-width:0`). Every test also still runs at 360 px.

### P2.5 — performance strategy (measured)

The review noted "no performance strategy." Here it is, with numbers. The deployed
`autoparts.html` is **4.04 MB**, and the breakdown is the whole story:

| Part | Size | Notes |
|---|---|---|
| Inlined images (45 base64) | **3.58 MB** | downloaded on *every* first visit |
| Fonts (7 woff2) | 0.29 MB | self-hosted, `font-display:swap` |
| Markup + JS + CSS + catalogue JSON | 0.16 MB | the actual app |

So ~89% of first-load weight is images that are inlined as base64 — which means they **cannot
be lazy-loaded** and every visitor pays for all 45 up front, even the diagram/gallery images
they may never open. On weak South Sudan connectivity that is the single biggest cost.

Done now (safe): all 16 `<img>` tags carry `loading="lazy"`, and the storefront already has a
hosted-image path built in (`px()` + `CFG.assetBase`) — so the moment images become URLs, only
the visible ones load.

Recommended next (high impact, needs your go-ahead — it changes production image delivery,
which broke once before): **serve the 45 images as files instead of base64.** The earlier
breakage was specifically `public/` not syncing on deploy; `resources/` *does* sync (it's where
`autoparts.html` itself lives and has updated all along). So the safe design is: ship the images
under `resources/storefront/autoparts-assets/`, add a small `app/` controller route
`/storefront/asset/{file}` (app/ always syncs) that streams them with a far-future cache header,
and set `CFG.assetBase` to that route. Expected result: **first load ~4.0 MB → ~0.5 MB**, with
images arriving lazily as the shopper scrolls. Both pieces live in deploy-synced territory, so
it won't repeat the `public/` failure. I can implement this as an opt-in build mode
(`build_storefront.py --hosted-images`) so the proven self-contained file stays the default
until you've verified a hosted deploy.

### P3 — storefront modularization (staged, not big-bang)  ✅ STARTED (this milestone)

The review's headline recommendation. Done incrementally so the live store never regresses:

1. **✅ Data extracted** from template; the single deployable file is a build artifact.
2. **✅ The ~800-line inline `<script>` is split into 12 cohesive modules** under `src/`
   (`01-core` … `12-a11y-boot`), assembled by `build_storefront.py`. The split is a contiguous
   physical partition, so concatenation reproduces the original script **byte-for-byte** — zero
   behavior change, proven by the suite plus a new "no runtime errors on every route" smoke test.
3. **✅ Build pipeline** concatenates the modules (optional esbuild `--minify`) and emits the
   same single self-contained file the deploy path expects — hosting is unchanged.
4. **Next increment:** convert the shared-scope modules to strict ES modules (explicit
   `import`/`export`, a `window`-exposed API for the inline handlers, esbuild `--bundle`),
   file-by-file behind the tests, and add per-module unit tests. See `src/README.md`.

Each step ships behind the green test suite; no step requires a freeze longer than a day.

---

## 4. Definition of "production ready" for the storefront

- [x] Analytics events fire for view/add/checkout/search/whatsapp and reach the backend
- [x] Reproducible build with placeholder validation
- [x] Automated e2e tests on desktop + mobile, all green
- [x] Zero third-party CDN dependencies (self-hosted FA + fonts)
- [x] XSS audit passed with a regression test
- [x] Order/quote bot protection
- [x] Funnel/abandoned-cart visibility for the tenant
- [x] Client-side error capture to Sentry
- [x] Accessibility (keyboard + screen reader) baseline with axe-core in CI
- [x] Storefront script split into modules behind the test suite

All 10 checklist items are done (Milestones 1–4). The storefront script is now 12 modules under
`src/` assembled by the build; the remaining refinement is converting those shared-scope modules
to strict ES modules with imports (tracked in `src/README.md`), which is a maintainability
increment rather than a production blocker. Remaining ops step: confirm the production Sentry
DSN is set. The suite stands at 26 e2e tests (13 scenarios × desktop + mobile) plus the PHPUnit
bot-guard test.
