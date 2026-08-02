# CloudBSS v4.10 — Search-first homepage (the approved reference design)

**Date:** 2026-08-03 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.10.zip (cumulative — supersedes v4.9.2 and everything before it) · **Migration:** same two guarded ones (`php artisan migrate` — skip if already run). **No backend changes in this drop — one file: the storefront.**

## What changed (per the senior-UX brief + reference image; improved, not copied)

**The search bar is the hero.** The homepage now opens with the headline, a 60px search bar with the OEM/part/SKU placeholder, and — inside the bar — **photo search** (camera icon → the native part-ID from v4.8.2) and **voice search** (mic → speak the part name, it types and suggests). Trust strip (Genuine Parts · Best Prices · Fast Delivery · Easy Returns) sits under it in Lucide icons.

**Sticky search (your reviewer's extra suggestion).** Scroll past the hero and the dark top bar grows a pinned white search bar — same live suggestions — so a mechanic can fire the next search without scrolling back up. On the Categories/system/search-results pages it's pinned from the start. The bottom-nav Search sheet got the same live suggestions.

**Richer live suggestions.** Every row now carries: search icon · part name · **OEM in JetBrains Mono** · brand · price · a motorcycle-model badge (falls back to brand when fitment isn't computable). Wrong-bike parts are still never suggested; the "Search everything → N PARTS" row stays.

**Bike selector with photos.** "Select your motorcycle" is now a horizontal rail of photo cards (image, name, red ring + check when selected — tap again to unselect). Selecting shows the green *"Showing parts for X · Change · Show all parts · Model page ›"* line. "View all ›" opens the full picker.

**Two large quick actions** exactly per the mock: 🩷 **Photo of old part / We'll identify it for you** (native part-ID, not just WhatsApp) and 💚 **WhatsApp a mechanic / Get expert help now**.

**New homepage order:** hero → bike selector → quick actions → popular-search chips → **Shop by Category** (white photo cards with live counts, horizontal rail on mobile) → **Popular Parts** with a red *Best sellers* tag → Common replacements (when a bike is chosen) → **Recently viewed** (new — last parts they opened, per device) → Recently added → *"Can't find the part you need?"* strip wired to **both** WhatsApp and the v4.9.2 **Ask my colleague** form.

**Bottom nav (as you approved):** Home · **Categories** · Search · Diagrams · **Assistant**, with active-tab highlighting; the cart moved to the top-right as an icon with a badge (like the reference header) plus a WhatsApp button beside it. All icons are Lucide throughout the new chrome.

**Spec items already true, no change needed:** Inter + JetBrains Mono were already vendored in the file (self-hosted, no Google Fonts request); mono OEM everywhere; 8px grid / 16 radius / 56px buttons applied to the new components; sticky buy bar was already product-pages-only.

**One deliberate deviation, recorded:** the spec's **Account** tab was replaced by **Assistant** (your call) — this storefront has no customer accounts, and an empty Account tab erodes trust; dealer sign-in stays in the footer.

## Deploy

GitHub upload → Rebuild. Nothing to configure — hero title/text, models, best sellers and accent colour all still come from your Settings.

## Verified before ship

- Playwright at 390px on the exact SPA: hero search + camera/mic present · suggestions show mono OEM + model badge + price · bike card select → green line + ring · **sticky bar appears on scroll, suggests, and searches** · recently-viewed records and renders · Categories tab routes + highlights · search sheet suggests · help strip opens the part-request form. Zero console errors, zero failed requests.
- Desktop (1366px) journey: zero errors. Full Boxer + TVS persona regressions (picker, part pages, cart, dealer, AI): zero errors, zero step failures.
- Two layout bugs caught and fixed before ship: the hero grid needed `min-width:0` (same class of bug as v4.8's hero blowout), and the WhatsApp quick-action collided with the floating `.wa` button class (renamed).
- node --check on all changed modules + the built bundle · zip md5-verified against the repo.
