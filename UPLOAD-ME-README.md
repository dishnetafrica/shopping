# Solar Experience Center — v1: the public Assessment funnel

A customer-facing, no-login **Solar Experience Center** that turns first-time visitors into leads. Instead
of "Shop products," a visitor picks what they want to power, gets an instant system recommendation with a
plain-language explanation and a price range, and requests a proposal — all in a few minutes.

It's powered by the **same Advisor engine** you already have (deterministic sizing, packages), with one
new ingredient: an **appliance library** so customers choose "TV, fridge, AC" instead of kWh.

**This bundle includes the full Advisor backend**, so uploading just this makes the whole thing work.

## What a customer sees — `mycloudbss.com/<shop>/solar`

1. **Hero:** "Design the right solar system … in under 3 minutes" → Start.
2. **Goal cards:** Home · Business · School · Hospital · Farm · Water Pump · Street Lighting · Factory.
3. **Appliance picker:** tap the devices they have, with quantities (grouped: Essentials, Comfort, Work,
   Security, Water, Heavy). No kWh required.
4. **Backup slider:** how many hours of backup they need.
5. **Live recommendation:** an energy-flow diagram (Sun → Panels → Inverter → Battery → Home) plus panels,
   battery, inverter and daily use — updating as they change inputs — with a **"Why this system?"**
   explanation and a matching **solution package with a "from" price**.
6. **Convert:** "Get my free proposal" (captures name + phone) or "WhatsApp us". Every lead lands in your
   staff **V3 → Solar Advisor** as a *sized* project, with a pre-filled WhatsApp hand-off.

A **"Start my free assessment"** strip is added to the top of your storefront homepage for solar shops.

## Public, safe, shop-scoped

New public endpoints (no login), resolved by the shop slug and CSRF-exempt like your other storefront
POSTs. Nothing recomputes figures — the deterministic engine does the maths; the page only collects
answers and shows results.

- `GET  /<shop>/solar` — the page · `GET /<shop>/solar/config` — appliances/goals
- `POST /<shop>/solar/estimate` — recommendation · `POST /<shop>/solar/lead` — capture + WhatsApp link

## Files in this zip

**New**

- `resources/storefront/solar.html` — the Experience Center page.
- `app/Services/Advisor/SolarAppliances.php` — the appliance library.
- `app/Http/Controllers/Storefront/StorefrontSolarController.php` — the public API.
- `tests/Unit/Kernel/SolarAppliancesTest.php`, `tests/Feature/Kernel/StorefrontSolarTest.php`.

**Replace**

- `routes/web.php` — the public `/<shop>/solar*` routes.
- `bootstrap/app.php` — CSRF exemption for `*/solar/*` (matches your other public storefront POSTs).
- `app/Http/Controllers/Storefront/StorefrontController.php` — the homepage CTA strip for solar shops.

**Carried** — the complete Advisor backend + V3 screen from the earlier work, so this stands alone.

## How to upload (GitHub, branch `main`)

Drag the `app`, `bootstrap`, `database`, `resources`, `routes`, `tests` folders/files into your repo at the
same paths.

## How you know it worked

Green ✓ on **"Money kernel (must pass)"** (public config/estimate/lead tested with no auth). Then open
`mycloudbss.com/gsl/solar`: pick a goal, add appliances, watch the recommendation update, and submit a
test lead — it appears in V3 → Solar Advisor.

## Next (the rest of the Experience Center vision)

2. **Knowledge Center** (public, SEO) · 3. **Animated "How Solar Works" + comparisons** ·
4. **ROI / before-after / case studies** · 5. **Solar Academy + on-page AI assistant** ·
6. **Richer product pages.** Say the word and I'll build the next one.
