# CloudBSS v4.8.1 — Storefront 2.0 Phase 2: DECIDE (+ first-load speed)

**Date:** 2026-08-02 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.8.1.zip (cumulative — supersedes v4.8 and everything before it) · **Migration:** same single guarded one (`php artisan migrate` — skip if already run).

Phase 2 of the approved Storefront 2.0 plan: everything a customer needs **at the moment of decision**, plus the performance work.

## Product page depth

- **Photo gallery with a packaging slot.** The page now shows thumbnails for the main photo + up to 3 gallery photos, tap-to-swap, tap-to-zoom. **Gallery slot 1 is labelled "PACKAGING"** — put the genuine-packet/hologram photo there (dealer trust, straight from the catalogue review). Photos come from the product's existing Gallery 1–3 fields (panel or CSV import).
- **Specifications table** — live for any product that has specs filled (the spec system you already have; e.g. chain Size/Links/Material). Renders only when specs exist.
- **Installation notes** — any spec whose name contains "install" is pulled out of the table into a highlighted amber note box (e.g. "Fit with 25–30 mm free play; replace with both sprockets").
- **"Goes well with" rail** — mechanic-logic companions: chain↔sprockets, brake shoe↔brake cable, piston↔gasket, clutch plate↔cable, throttle cable↔grip… Same-brand-family only, fitment-filtered, appears only when companions exist.
- **"Also available for TVS/Boxer" row** — when the same part exists for the other brand ("Clutch Cable (Boxer)" ↔ "Clutch Cable (TVS)"), one tap jumps across, with the price shown. Never confuses: it's a clearly-marked cross-brand link, not a fitment claim.
- The old "Commonly replaced together" rail is renamed **"More in this system."**

## First-load speed (the ~4 MB problem)

- The storefront HTML and the /catalogue feed are now **gzip-compressed when the browser accepts it** (~4 MB → ~1.1 MB on the wire) and carry a **short private cache** (5 min page / 2 min feed) instead of `no-store` — repeat visitors stop re-downloading the whole storefront on every visit. Proxies won't double-encode (Content-Encoding is set), and if compression ever fails the plain response goes out unchanged.

## Feed additions (server)

`/catalogue` now carries `Gallery` (array of photo URLs) and `Specs` (label→value object, built from your spec definitions in one query, cached with the feed). Both are empty today and light up as you add packet photos and specs — no further deploys needed.

## Deploy

GitHub upload → Rebuild → `php artisan migrate` (if not yet run). Nothing else. To light up the new sections: add packet photos to Gallery 1 and fill specs on your top parts (start with the 10 best-selling — chains, piston kits, cables).

## Files changed vs v4.8

`resources/storefront/autoparts.html` (+src mirrors 01/02/10) · `app/Http/Controllers/Storefront/StorefrontController.php` (Gallery+Specs in feed, `compressed()` helper on both endpoints).

## Verified before ship

- Product page: 3 thumbs with PACKAGING label, tap-swap; specs table renders 3 rows with Installation Notes extracted to its own box; "Goes well with" shows sprockets on the chain page; "Also available for TVS: Clutch Cable (TVS) — UGX 10,500 ›" on the Boxer cable page; big OEM block intact.
- Sections collapse cleanly to nothing on products without gallery/specs/companions (today's state) — zero layout debris.
- Full regression journeys as Boxer rider AND TVS rider: zero console errors, zero failed requests.
- `php -l`, `node --check` on all touched files; zip md5 vs repo.
