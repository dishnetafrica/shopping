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

### P0 — before scaling traffic

**Self-host Font Awesome and fonts.** The storefront currently loads FA 4.7 from
`cdnjs.cloudflare.com` and Inter/JetBrains Mono from Google Fonts. On weak or filtered
connectivity (a real concern for South Sudan) a blocked CDN means broken icons and a font
flash. Vendor the FA CSS + webfont and the two font families into the app and reference them
locally, removing all three third-party origins. This also improves first paint and privacy.

**XSS audit of the SPA.** The storefront builds DOM via string concatenation. `esc()` is used
in 107 places, but catalogue fields (name, keywords, description) come from tenant-editable
data and must be escaped at *every* interpolation. Action: a scripted audit that flags any
`+p.name`/`+p.desc`/`+p.kw`/`+q` not wrapped in `esc()`, plus a test that injects
`<img onerror>` payloads into a mock catalogue and asserts no execution.

**Order-path bot protection.** `/event` is rate-limited; `/order` and `/quote` are not
obviously protected against automated spam. Add a honeypot field + per-IP throttle to the
order/quote submit, and consider a lightweight proof-of-work or hCaptcha only if abuse
appears (avoid adding friction pre-emptively for low-literacy customers).

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
- [ ] Zero third-party CDN dependencies (self-hosted FA + fonts)
- [ ] XSS audit passed with a regression test
- [ ] Order/quote bot protection
- [ ] Funnel/abandoned-cart visibility for the tenant
- [ ] Client-side error capture to Sentry
- [ ] Accessibility (keyboard + screen reader) baseline with axe-core in CI
- [ ] Storefront script split into modules behind the test suite

Items 1–3 are done. 4–10 are the remaining path; none is architecturally risky, and each is
independently shippable behind the test suite.
