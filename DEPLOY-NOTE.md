# CloudBSS — Shop for Every Need (homepage merchandising)

Evolves the storefront home into an inclusive, collection-led merchandising
surface. A home customer buying one bottle of oil and a hotel placing a big
quotation both see themselves — no onboarding question, no Home/Business choice.
Additive, backward compatible, no engine/ERP/quotation changes. Reuses your
existing photo upload — no new storage.

> This supersedes the earlier "Business Banners" build. Same config key
> (`business_banners`) and endpoints — deploying this simply upgrades it. If you
> already saved a config, it keeps working; use **Load starter template** to get
> the new 7-collection set.

## What's new vs. the first version
- **"Shop for Every Need"** section title (configurable), with **premium
  collection cards** (photo/gradient, icon, title, one-line description, Browse →)
  instead of small icon pills — mobile-first (swipe) + desktop responsive (grid).
- A **Home Essentials** collection (plus Office Supplies, Retail Store Essentials,
  Wholesale Packs) so retail/home shoppers are first-class, not just B2B.
- **Bulk-aware matching**: a collection can include *all* carton/bulk items
  (pack size or MOQ > 1) — powers "Wholesale Packs" without hand-listing.
- **Gentle personalization**: the storefront counts which collection each shopper
  opens (on their device) and orders the cards most-used-first on their **next**
  visit — never mid-session (cards don't jump), never hiding anything, never a
  forced choice. New visitors see the configured default.
- Per-collection **card photo** upload (separate from the wide hero photo).

## The 7 starter collections (each with its hero banner)
Home Essentials — "Everything Your Home Needs" · Hotel Supplies — "Everything
Your Hotel Needs" · Restaurant Supplies — "Kitchen & Packaging Essentials" ·
Retail Store Essentials — "Stock Your Shelves Faster" · Wholesale Packs — "Bulk
Buying Made Easy" · Catering Supplies — "Everything for Catering & Events" ·
Office Supplies — "Keep Your Office Running Smoothly".

## Files (additive, repo-relative)
1. `app/Http/Controllers/Storefront/StorefrontController.php` — emits
   `business_banners` in the catalogue feed (unchanged from v1).
2. `app/Http/Controllers/Panel/PanelApiController.php` — `bannersSave` now also
   whitelists section `title`, per-collection `card_image`, and `match.bulk`.
3. `routes/web.php` — authed papi routes (unchanged from v1).
4. `resources/storefront/shop.html` — collection cards, "Shop for Every Need"
   title, bulk matching, device personalization. Renders **only when enabled**.
5. `resources/panel/seller.html` — **Shop for Every Need** admin page: section
   title, 7-collection starter, card-photo upload, bulk toggle, home/retail icons.

## Turn it on
1. Deploy the files.
2. Panel → **Shop for Every Need** → **Load starter template** (7 collections
   mapped to your categories) → adjust labels/keywords, upload a card photo and a
   hero photo per collection (optional — a themed gradient shows until you do) →
   **Save**. Tick **Show on storefront**.
3. The home shows it within ~5 min (catalogue feed cache).

## Matching & safety
- Collections match products by **category names**, **keywords**, and optionally
  **bulk** (pack/MOQ > 1). Keywords keep it robust even if live category names
  differ from the taxonomy.
- A best-seller rail shows only when ≥3 products match — a thin collection never
  renders a broken rail. Leave a collection **Active** off until it has stock.
- Renders only when enabled; untick "Show on storefront" to fully revert to the
  previous home. No migrations, no schema change (tenant settings).

Verified: `php -l` clean; shop.html + seller.html JS compile; Playwright —
storefront shows the titled card grid, default lands on Home Essentials, bulk
matching returns exactly the carton/MOQ>1 items, personalization surfaces the
most-opened collection first on reload, and the admin loads the 7-collection
starter and saves title + card_image + match.bulk correctly. No console errors.
