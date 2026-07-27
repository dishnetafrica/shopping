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

### P1 — observability of the funnel

**Checkout-drop-off + abandoned-cart view.** The events now exist; build the read side: a
tenant BI panel showing view → add → checkout → order conversion over time, and a daily
"carts started but not ordered" figure. No new writes — aggregate `product_events` (mirrors
the existing `trendingIds()` pattern).

**Confirm Sentry DSN in production.** The hooks are in `bootstrap/app.php`; verify the DSN
env var is set for the live tenant so uncaught storefront JS *and* server exceptions surface
in one place. Add a client-side `window.onerror`/`unhandledrejection` beacon so front-end
crashes are captured too (currently only server-side is).

### P2 — accessibility & mobile

**Accessibility pass.** The SPA uses `onclick` on `<a>`/`<span>` with no `href`/`role`/`tabindex`,
so keyboard and screen-reader users can't operate it. Action: give interactive elements
proper roles + `tabindex`, visible focus states, `aria-label`s on icon-only buttons, and
`aria-live` on the cart badge and toasts. Add an axe-core check to the Playwright suite.

**Mobile breakpoint matrix.** The suite already runs at 360 px. Extend to 320/390/412 px and
assert no horizontal scroll and that primary CTAs (Add to cart, Place order) are within
thumb reach and never clipped by the bottom bar.

### P3 — full storefront modularization (staged, not big-bang)

The review's headline recommendation. Do it incrementally so the live store never regresses:

1. **(done)** Extract data from template; single deployable file is a build artifact.
2. Split the ~800-line inline `<script>` into ES modules by concern
   (`state.js`, `catalogue.js`, `cart.js`, `views/*.js`, `analytics.js`, `vehicle.js`), keeping
   behavior identical — the Playwright suite is the safety net for each extraction.
3. Introduce a bundler (esbuild) that emits the same single self-contained file the deploy
   path expects, so nothing about hosting changes.
4. Add per-module unit tests alongside the e2e suite.

Each step ships behind the green test suite; no step requires a freeze longer than a day.

---

## 4. Definition of "production ready" for the storefront

- [x] Analytics events fire for view/add/checkout/search/whatsapp and reach the backend
- [x] Reproducible build with placeholder validation
- [x] Automated e2e tests on desktop + mobile, all green
- [x] Zero third-party CDN dependencies (self-hosted FA + fonts)
- [x] XSS audit passed with a regression test
- [x] Order/quote bot protection
- [ ] Funnel/abandoned-cart visibility for the tenant
- [ ] Client-side error capture to Sentry
- [ ] Accessibility (keyboard + screen reader) baseline with axe-core in CI
- [ ] Storefront script split into modules behind the test suite

Items 1–6 are done (Milestones 1–2). 7–10 remain; none is architecturally risky, and each is
independently shippable behind the test suite. The next milestone is the P1/P2 work: funnel
visibility, client-side error capture, and the accessibility baseline.
