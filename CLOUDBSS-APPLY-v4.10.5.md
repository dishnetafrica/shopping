# CloudBSS v4.10.5 — Diagrams hidden everywhere (parts-first, like boraboda)

**Date:** 2026-08-03 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.10.5.zip (cumulative — supersedes v4.10.4 and everything before it) · **Migration:** same two guarded ones (skip if already run).

Your call: boraboda is parts-first with no exploded-diagram plates, so the diagram surfaces mostly showed empty frames and "Ask us" rows — confusing, not helpful. This drop removes Diagrams from the whole experience.

## What changed

- **Bottom nav:** the Diagrams tab is gone — **Cart is back in the bottom bar** (with its badge), so the nav is now Home · Categories · Search · Cart · Assistant. One-thumb path to checkout restored.
- **Search:** no Diagrams tab, no "Exploded diagrams" strip in results. A query that used to match only a diagram slot (your "air filter") now goes straight to the honest miss + **Ask my colleague** form — the capture funnel is unchanged.
- **Product page:** "Show on diagram" button removed. **Categories page:** "View exploded diagrams ›" link removed. **Model page:** Diagrams tab, "Part Compatibility" quick action, and every "diagrams" mention in copy removed ("Explore Parts ›").
- **AI mechanic:** greeting and diagnosis copy no longer promise to "show it on the exploded diagram."
- **Old links don't break:** anyone opening a saved #/diagrams or #/plate link lands on the Categories page instead of an empty diagram.

## It's a switch, not an amputation

Everything is gated on a new setting: **Settings → Storefront → Availability → "Show exploded diagrams" (default OFF)**. If you ever get real plate data for your catalogue, flip it ON and every surface returns — contextual links, search tab, model tab — without a deploy. The bottom nav stays Cart either way (diagrams would come back as in-page links only).

## Deploy

GitHub upload → Rebuild. Nothing to configure — hidden is the default.

## Verified before ship

Every surface checked hidden (nav tabs, search tabs + strip, product button, categories link, model tabs/copy/quick actions, AI copy, route redirect) · cart tab routes with live badge + active state · "air filter" lands on the mechanic capture · **overflow matrix still perfect (16 routes × 9 widths, all 0px)** · all six feature journeys green, zero console errors · php -l ×3 · node --check + bundle · zip 91 files md5-verified.
