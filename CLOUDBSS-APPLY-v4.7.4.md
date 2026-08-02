# CLOUDBSS-APPLY v4.7.4 — everything + email intent + one reply per burst
**Supersedes ALL earlier zips. One zip, upload everything to GitHub.**
Base: shoppingmain 41. Complete files, never diffs. Additive; php -l + node --check clean.

## Contents

**Trust v1.3 wiring** — routes/api.php · routes/web.php · routes/console.php
(nonce pages in the /task/{nonce} throttle group; schedules at 06:45 / 07:00 / Mon 07:15 EAT).

**Vision box claim backend (V4, CloudBSS half)** — vision_boxes migration · config/vision.php ·
VisionBox model · VisionBoxController (hello/poll) · VisionEventController (per-box tokens) ·
vision:claim + vision:boxes commands.

**NEW — Inventory Trust screen (backlog T6):**
- app/Http/Controllers/Panel/TrustPanelController.php  ← page + 7 papi endpoints
- resources/panel/trust.html                           ← the screen (house tokens.css/ui.js style)
- app/Services/Trust/TrustRuntime.php                  ← + resolveInvestigation() (additive method;
  same semantics as trust:resolve — clean ↑ reputation, confirmed_loss ↓ by value, error = no change,
  plus double-resolve protection)
- config/navigation.php                                ← menu entry: Stock → Inventory Trust
- routes/web.php                                       ← /panel/trust + papi routes (refreshed file)

## The screen — open /panel/trust after deploy (bookmark it)

Tiles: Verified stock (M9) · Shrink % (M1) · Attributed (M3) · Queue precision (M5) ·
Evidence coverage (M6) · Open queue. Shrink waterfall (7/14/28d). Today's queue with
one-tap resolve (clean / error / confirmed loss + value + notes — the label factory, no
artisan needed). Recent trust scores with decision filter. Vision: claim a box by typing
its on-screen code, fleet table with online/offline + revoke, live shelf-events feed.
It also appears in the left menu (Stock → Inventory Trust) where the nav config is wired.

## Deploy (same as before)

1. Upload extracted files to GitHub over the repo root (replaces 6, adds 9).
2. EasyPanel **Rebuild** → `php artisan migrate --force` → `php artisan storage:link` (once).
3. `.env`: `APP_TIMEZONE=Africa/Kampala` (+ optional legacy VISION_WEBHOOK_TOKEN/VISION_TENANT_ID).
4. Tenant 17 settings: `trust_digest=1` · `trust_staff_numbers` · optional `trust_digest_numbers`.
5. Open `/panel/trust` → tiles show "—" until the backtest/live scoring writes data:
   `php artisan trust:backtest --tenant=17 --months=12 --top=20 --write`

Actor data on the screen routes review only — never a ranking, never an accusation (Spec §5.2).

---

## NEW in v1.6 — Catalogue PDF v2 (bug fix + redesign)

`app/Services/Catalog/CatalogRenderer.php` (same public API; verified against dompdf 3 with
sample data, before/after screenshots in chat) + `CatalogueFlushCommand` now also clears the
PDF cache.

Bugs fixed (all reproduced from the old code):
1. Cover orphaned its contact line onto a near-blank page 2 → every index page number was wrong.
2. Every FULL category page (9 cards) overflowed A4 and dumped its footer on an orphan junk page.
3. Trailing blank page at the end of the document.
4. Broken-image ✕ boxes when a product image URL failed; wildly mixed photo sizes.
5. Product names glyph-sliced mid-line; price rows misaligned between cards.

Redesign: brand-band cover with accurate (auto two-column) index · dark category bands ·
equal-height cards on normalised 4:3 photo thumbnails (pre-fetched in parallel, disk-cached,
data-URI embedded → smaller PDFs, WhatsApp-safe) · deterministic two-line names with ellipsis ·
"How to order" back page · "Page N of T" numbering. Degrades gracefully without GD.

**After deploy:** `php artisan catalogue:flush --tenant=17` (old cached PDF lives up to 6h otherwise).
First render re-downloads photos once into `catalog-thumbs/` cache; after that it's fast.

## v1.7 — catalogue PDF size fix (33 MB → ~3-4 MB)

The old renderer embedded every product photo at ORIGINAL resolution — a 33 MB document is
just the sum of the original photos. v2 embeds normalised 440×330 q80 thumbnails instead,
plus font subsetting (isFontSubsettingEnabled). Measured on identical heavy-photo sample data:
**old 9.09 MB → new 0.24 MB (38× smaller)**; extrapolated to ~265 products ≈ **3-4 MB total**
(avg thumb 11.5 KB) — safe for WhatsApp and cheap on customer mobile data.

First render after deploy still takes 30-60 s (downloads each photo once, builds the thumb
cache in storage/catalog-thumbs/); every render after that reuses the cache and is fast.
Remember: `php artisan catalogue:flush --tenant=17` once after deploy.

## v1.8 — catalogue v3 "rich" (marketing-mock layout)

CatalogRenderer now renders the designed brochure: photographic cover (headline, audience
checklist, product-count stat box, hero photo, pinned QR/contact bar) · "Explore our categories"
overview page (photo cards ≤12 categories, compact 2-col index above that) · full intro page per
category with ≥7 items (hero photo + ★ top-sellers row + feature strip; smaller categories get a
slim hero band) · BEST SELLER / HOT DEAL badges on cards · WhatsApp QR (auto-generated from the
tenant's number via quickchart, cached; silently omitted offline) · "Need a product you don't
see?" promo band · richer How-to-Order page. Every element degrades gracefully — with zero setup
the v2 clean layout still renders. Verified end-to-end with dompdf 3 (9-page sample, no orphan
or blank pages; 246 KB).

**New tenant settings (all optional):**
- `catalog_cover_image`  — URL of the cover hero photo (portrait ~4:5; auto center-cropped)
- `catalog_promo_image`  — URL of the promo-band photo (landscape ~16:7)
- `catalog_qr_image`     — URL of a pre-made QR (else auto wa.me QR from the WhatsApp number)
- `catalog_best_sellers` — comma-separated SKUs or exact names → green BEST SELLER badge
- `catalog_hot_deals`    — comma-separated SKUs or exact names → orange HOT DEAL badge
- `catalog_audience`     — comma list for the cover checklist (default Hotels,Restaurants,Supermarkets,Retailers)

After deploy: `php artisan catalogue:flush --tenant=17` to rebuild.

## v1.9 — catalogue 500-error hardening (production fatals)

Fixes the `{"ok":false,...,"status":500}` on /api/v1/doc/catalog:
1. **Giant photos**: a 20-30 MP phone photo needs more GD decode memory than memory_limit —
   an UNCATCHABLE fatal. Dimensions are now checked BEFORE decoding (real headroom math);
   oversized photos get the placeholder. Verified: 31 MP photo under 128M limit → clean render, peak 30 MB.
2. **Timeouts**: first render of a big catalogue fetches every photo; set_time_limit(300) +
   a 120 s whole-fetch budget (stragglers become placeholders instead of killing the request).
3. Thumbnail + HTML build now run inside the try — any residual error returns the endpoint's
   clean 404 message, never a generic 500.

If a click still fails after this: EasyPanel console → `tail -n 80 storage/logs/laravel.log`
and search the request_id from the error JSON — paste those lines in chat.

## v2.0 — catalogue self-healing render ("PDF engine unavailable" fix)

The 404 "PDF engine unavailable" means pdf() returned null — either dompdf missing (now logged
explicitly) or an exception inside the rich photo pipeline (previously the 500; caught since v1.9).
pdf() is now TWO-STAGE: rich attempt → on any exception it logs the exact file:line
("CatalogRenderer rich path failed: ... — falling back to plain render") and retries in PLAIN
mode: no thumbnails, no cover art, no QR, no network/GD/Storage dependency at all — dompdf
embeds product image URLs directly (v1 behaviour). A catalogue is always delivered; the log
tells us which rich feature to fix. Both paths regression-tested.

Diagnosis after deploy, if needed (EasyPanel console):
  tail -n 100 storage/logs/laravel.log | grep -a CatalogRenderer

## v2.1 — three-stage render ladder + catalogue:doctor

36 MB output = the plain fallback fired (rich pipeline still throwing on the server). Changes:
1. Fallback is now a LADDER: rich → thumbs-only (keeps the small thumbnails, drops artwork/QR/
   badges) → plain (fat, last resort). Each stage logs its exact failure with file:line, and the
   thumbnail pipeline is probed separately from the layout — so a broken QR fetch can no longer
   drag the document back to 36 MB.
2. NEW `php artisan catalogue:doctor --tenant=17` — one command that checks dompdf, GD/JPEG,
   curl, storage write, fetches 3 real product image URLs (flags relative/broken URLs and
   megapixel sizes), probes the QR service, does a real 2-product mini render, and prints the
   renderer's recent log lines. Run it and paste the whole output when anything misbehaves.

## v2.2 — ROOT CAUSE FIXED: GD built without JPEG (catalogue:doctor diagnosis)

Doctor output showed it precisely: `Call to undefined function imagejpeg()` @ CatalogRenderer:358 —
the production PHP container compiles GD with PNG only (`docker-php-ext-install gd` without
`--with-jpeg`). Every catalogue failure this session traced back to that one missing function.

Two-level fix, both in this zip:
1. **Dockerfile** (root of repo — upload it too): adds libjpeg62-turbo-dev + libwebp-dev +
   libfreetype6-dev and `docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype`
   before the gd install. Next Rebuild gives PHP real JPEG support.
2. **CatalogRenderer**: encoder ladder JPEG → Imagick-JPEG → 8-bit palette PNG, mime-aware
   thumb/asset caching (.jpg/.png). The catalogue now renders small on ANY PHP build:
   measured — his scenario (1.9 MB PNG products, PNG-only GD): **399 KB** (was 36 MB);
   with JPEG enabled: smaller still. asset()/cover art got the same treatment (was the next
   landmine once catalog_cover_image is set). catalogue:doctor now probes PNG/WebP/Imagick
   and prints the docker-logs command when laravel.log is absent (LOG_CHANNEL=stderr).

Deploy: upload ALL files incl. Dockerfile → Rebuild (takes longer once — GD recompiles) →
`php artisan catalogue:flush --tenant=17` → generate. Also: tenant 17 has NO WhatsApp number
set (doctor warning) — set the `public_phone` setting to get the QR + footer number.

## v2.3 — upload images from Settings (no more URL pasting)

Settings → Business profile now has real **Upload image…** buttons:
- Branding → **Logo** (was "Logo URL" text-only)
- NEW **Catalogue** group → Cover photo · Promo photo · Best sellers · Hot deals · Cover checklist
  (the ChatGPT artwork + badge lists all live here now)

Flow: Upload image… → file stored under branding/{tenant}/ on the public disk → absolute URL
dropped into the field → **Save changes** persists it (normal settings save; owner-only).

Files: SettingsSchema.php ('image' field type + Catalogue group) · SettingsWriteService.php
(whitelists the 6 new keys) · BrandingUploadController.php (POST /papi/branding-upload,
owner-gated, 6 MB max, png/jpg/webp/gif) · settings-manager.html (image control + uploader) ·
routes/web.php (one route). After changing catalogue images: `php artisan catalogue:flush --tenant=17`.

## v2.4 — Website on the catalogue

New Settings field: Business profile → Contact → **Website** (`public_website`; falls back to the
bot's `bot_website_link` if that's already set). The catalogue shows it as a CLICKABLE link:
cover contact bar + cover footer, the right side of every page footer ("Order on WhatsApp: … ·
familyshoppers.net"), and an "OR ORDER ONLINE" block inside the WhatsApp card on the How-to-Order
page. Entered without https:// it still links correctly. After setting it:
`php artisan catalogue:flush --tenant=17`.

## v2.5 — fully-designed cover support (auto-detected)

The cover image now has two automatic modes, chosen by the image's shape:
- **Portrait image** (taller than wide, e.g. a complete AI-designed cover exported at 1024×1536
  with title/QR/contacts already in the picture) → becomes the ENTIRE first page, untouched.
- **Landscape/square image** → treated as a hero PHOTO and cover-cropped into the composed
  layout (headline, checklist, stat box, QR bar drawn by the system).

Upload flow reminder (why an uploaded image may not appear): Upload image… fills the field →
you MUST click **Save changes** → then `php artisan catalogue:flush --tenant=17` (the PDF is
cached 6 h) → regenerate.

## v2.6 — wrong-host upload URLs fixed (APP_URL misconfiguration)

Diagnosed live: uploads were saved as https://evo-shopbot.1gk84r.easypanel.host/storage/... —
APP_URL on the server points at the INTERNAL EasyPanel hostname, so Storage::url() built wrong
links and the renderer couldn't fetch the cover/logo. Two fixes:
1. BrandingUploadController now builds URLs from the host the owner is browsing on
   (request host = mycloudbss.com), never APP_URL.
2. CatalogRenderer::ownStorageBytes(): any URL whose path is /storage/... (REGARDLESS of
   hostname) is read directly from the public disk — no HTTP. Already-saved wrong-host URLs
   work without re-uploading, and all 351 product-photo fetches get faster too.
   Verified: wrong-host cover URL → full-page cover art renders.

**ALSO DO (server env, EasyPanel → app → Environment):** set `APP_URL=https://mycloudbss.com`
and redeploy. It affects more than the catalogue: trust:links receiving/count URLs, the vision
box claim webhook_url, and storefront links all come from APP_URL.

## v2.7 — bot sends CATEGORY-WISE catalogues

Real conversation showed the gap: "send me a product catalog for the exercise books" → bot sent
the full 68-page file. Now:
- `CatalogRenderer::forCategory()` — one category as its own compact catalogue, cached 6 h per
  category (catalogue:flush clears these too).
- `AiBrain::detectCategoryAsk()` — matches the tenant's OWN data two ways: the category name
  in the message ("spices price list"), or a product-word vote ("exercise books" → most matching
  products are Stationery → Stationery). Ambiguous asks stay conservative → full catalogue.
- Category sends have their own 10-min anti-spam key, so asking for a section minutes after
  receiving the full list still works (exactly the Chinese-agent conversation pattern).
- Caption: "Here's our *Stationery* catalogue 📄 Tell me the items and quantities…"

Files touched: CatalogRenderer.php, AiBrain.php, CatalogueFlushCommand.php. No new settings.

## v2.8 — bot delivery-policy fix ("prices do not include shipping" incident)

The bot improvised delivery/pricing statements because its prompt never stated the policy.
Now the system prompt carries an explicit DELIVERY & PRICING POLICY rule:
- Default (no setup needed): "listed prices ALREADY INCLUDE free delivery within Juba and our
  free-delivery areas (Juba, Konyo Konyo, Custom, Jebel, Munuki, Gudele, …). NEVER say prices
  exclude shipping. Outside those areas the team confirms transport separately — never invent
  a freight price."
- Owner override: Settings → Business profile → NEW **Delivery** group — Delivery policy
  (free text, exact wording the bot must use), Free-delivery areas, Delivering from.
- The catalogue's How-to-Order step 3 prints the same line, so chat and PDF always agree.

Files: AiBrain.php, SettingsSchema.php, SettingsWriteService.php, CatalogRenderer.php.
After deploy: `php artisan catalogue:flush --tenant=17` to refresh the printed line.

## v2.9 — stop unwanted catalogue sends (over-triggering fix)

Real incident: "How much does it cost to ship 20 boxes of A4 paper to Accra?" → bot attached a
(wrongly-matched) Bakery Product catalogue. Causes: generic price words ('cost', 'how much')
counted as document requests, and the v2.7 category path bypassed the old 10-min suppression.
Fixes in AiBrain (all dry-run tested against the real conversation):
1. Documents split into STRONG asks (catalog/catalogue/price list/product list/menu/brochure…)
   vs weak price words. Category documents fire ONLY on strong asks; weak asks keep the old
   full-list behaviour with its 10-min suppression.
2. Logistics questions (ship/freight/transport/deliver/cargo without a strong ask) NEVER get a
   document — the AI answers in text.
3. Global 3-min cooldown: max one document per customer per 3 minutes, across all types.
4. Category word-vote hardened: more stopwords (boxes, pages, carton, each, shipping, …) and
   the winning category now needs ≥3 matching products (tiny 2-item categories can no longer
   win by fluke; they can still be requested by NAME).

## v3.0 — quotation PDF not arriving (URL-first send) + delivery honesty

Incident: quotation ORD-Q260730-AEB1 announced in chat but no PDF arrived. The quote path sent
media by URL — built from APP_URL, which still points at the internal container host, so
WhatsApp's server-side fetch failed silently. Fix: quote PDFs now embed the file (b64) first,
URL only as fallback — identical to the price-list path that already works.

Also: the order-confirmation transport line no longer implies we deliver anywhere on earth —
"our team will confirm WHETHER we can deliver there and the transport cost" (Accra/Ghana incident).

⚠️ STILL REQUIRED (server env): APP_URL=https://mycloudbss.com in EasyPanel → Environment, then
redeploy. Broken APP_URL also affects order track links, trust receiving/count links, and the
vision claim flow. The code fixes reduce the blast radius; the env fix removes it.

RECOMMENDED SETTING (Settings → Business profile → Delivery → Delivery policy):
"All prices include free delivery within Juba. We deliver within South Sudan and Uganda only —
we do not ship internationally. For areas outside our free-delivery zone, our team confirms
transport cost when you order."

## v3.1 — quoted-reply context + one-order-one-quotation

Real conversation (B One Shop): customer replied to old messages ("when are you going to
deliver IT") and confirmed the same 630 USD order repeatedly — the bot minted FOUR identical
quotations over three days and never converted any to an order.

1. **Quoted-reply context**: WhatsApp reply-quotes were parsed but only used for Status replies.
   Now every quoted reply reaches the AI as explicit context ("[Customer is REPLYING to this
   earlier message from us: …]") — so "it", "this one", and "BUY" replies to product pushes
   resolve to the right product or quotation. (ProcessIncomingMessage + AiBrain.)
2. **Quotation dedupe**: if the same customer was quoted the same total within 3 days, a repeat
   "yes/proceed" is treated as CONFIRMATION: the quotation is flagged confirmed, the owner gets
   ONE alert ("✅ Customer confirmed quotation ORD-X — convert it to an order"), and the customer
   is told their order is with the team — no duplicate quotation is created.

Known remaining niggle (not in this drop): two rapid greetings still get two separate bot
replies (2-second debounce only) — a proper message-coalescing window is a future improvement.

## v3.2 — product-data freshness (answer + fixes)

How fresh is product data, per layer (as of this build):
- **Bot chat knowledge**: 60-second cache → new/edited products reach the AI within a minute,
  and now INSTANTLY (see below).
- **Quotation pricing**: always live from the database — never stale.
- **PDF catalogues** (full + per-category): were cached 6 h with manual catalogue:flush — now
  auto-refreshed on any product change.

Changes:
1. **Product model auto-flush**: any product save/delete instantly clears the bot's grounded
   list, the whole-shop PDF cache, and the affected category's PDF cache(s) (old + new category
   when a product moves). Add a product → the very next "price list" ask has it.
2. **Found: the AI only "knows" the first 200 products alphabetically** (ai_catalogue_limit
   default) — Family Shoppers has 351, so products later in the alphabet were invisible in chat.
   New Settings field: Business profile → AI knowledge → "Products the AI knows".
   **ACTION for tenant 17: set it to 400** (cap 600). Note: bigger list = slightly higher AI
   token cost per message.

## v3.3 — Delivery Note (print & sign)

New signature-ready DELIVERY NOTE per order — quantities only, never prices (the invoice
handles money). Header with logo/address/TIN, order + delivery dates, DELIVER TO block,
items table with a "Received ✓" tick column, "Above mentioned items received in good
condition.", optional order-notes band, and three signature boxes: RECEIVED BY (customer) ·
DELIVERED BY · AUTHORIZED SIGNATORY. Footer clarifies it is not a tax invoice.

Where it appears in V3: open any order → **⋯ more → Print delivery note** (also listed in the
order's Documents tab). Opens the PDF in a new tab → print → driver carries it → customer signs.
Endpoint: GET /api/v1/doc/delivery-note/{orderId} (tenant-scoped; falls back to legacy
items_json for old bot orders).

Files: app/Services/Sales/DeliveryNoteRenderer.php (new) · V3/DocumentController.php ·
CapabilityServiceProvider.php · Capability/Object/SalesOrderCapability.php.

## v3.4 — POS till (supermarket checkout)

The last missing piece for running a supermarket: a fast checkout screen at **/panel/pos**
(left menu: **Sales → POS Till**). Built for a touch screen or keyboard + USB barcode scanner.

**The flow** — scan or tap products → cart on the right → CASH / MOMO / CARD / CREDIT:
- **Scanner ready**: a USB scanner "types" the barcode + Enter — the product jumps straight
  into the cart and the box clears for the next scan. Typing anywhere refocuses the search box,
  so the scanner never misses. Search also matches name and SKU; category chips filter the grid.
- **Cash**: quick-tender note buttons (exact, 10, 20, 50…) + live change calculation, and the
  change is repeated on the success screen so the cashier can't forget it.
- **Credit (pay later)**: requires customer name or phone; saved as an UNPAID invoice to
  collect from the order/invoice screen later.
- **Receipt**: 80 mm thermal-style receipt (shop name, address, TIN, receipt no, operator,
  items, discount, paid/tendered/change) — prints via the browser print dialog, works with any
  thermal printer driver set to 80 mm.
- **Today at the till**: the header shows today's POS total; tap it for the drawer with every
  sale of the day (time, method, amount).

**Under the hood — zero new money/stock logic.** Checkout creates a real Order exactly like
the proven walk-in recipe (`status=Delivered`, `channel=pos`) so the EXISTING observer chain
does everything: OrderStockIssuer moves stock in the ledger (the Trust engine sees POS sales
too), AutoIssueInvoice numbers the invoice, CustomerMasterService files the customer. For
cash/momo/card the screen then calls the existing `/papi/record-payment` — cashbook, per-person
cash ledger and invoice paid-status all behave exactly like a payment recorded anywhere else.
Prices and totals are in the tenant's local operating currency end-to-end (USD for tenant 17).

Notes by design: out-of-stock products can still be sold (a till must never block on stock
drift — the ledger simply goes negative and the Trust queue surfaces it); the discount is a
whole-sale amount in currency, not per-line; any panel user whose role has the `orders` menu
sees POS Till (cashiers included).

Files: app/Http/Controllers/Panel/PosController.php (new) · resources/panel/pos.html (new) ·
routes/web.php (refreshed: /panel/pos + 3 papi routes) · config/navigation.php (refreshed).
No migration needed — upload + Rebuild is enough. Tested headless (Chromium): 27/27 checks
pass — scan-to-cart, qty edit, discount math, cash change, credit guard, receipt build,
payment recording (screenshots in chat).

## v3.5 — In-product Training Center + quick tours

Training lives INSIDE the system now (Bhavin's ask: "training docs and quick training on each
menu… inside system not separately").

**Training Center — /panel/training** (left menu: **Home → Training**, visible to every role):
one searchable page teaching every menu in simple staff English — how the system fits together,
a daily rhythm, POS till, Orders (incl. delivery notes + "convert confirmed quotations"),
Customers, Invoices/credit notes, the whole Purchases flow (+ why Cost Approvals exists),
Stock count honesty rules, Inventory Trust in plain words (tiles, resolve = clean/error/
confirmed loss, receiving links, camera claim, "a queue item is a question, never an
accusation"), Consignments/Deliveries/Transport, Payments & Cashbook ("received by = whoever
physically took the money", Staff Cash to zero), Reports, the WhatsApp assistant (what it does
alone, what it never does, how to teach it), Chats Take-over, Leads/Campaigns/Scheduled/Ads,
Email Center, Catalogue & branding how-to, Settings tour — plus a troubleshooting section built
from the real incidents of this deployment (image uploaded but not showing → Save changes;
bot doesn't know a product → active/price/AI-knowledge limit; bot "didn't send" a catalogue →
strong-ask + cooldown rules; negative stock meaning; credit collection; "I can't see a menu" →
roles). Ends with printable one-line cheat sheets per role (Print button top-right).
Filters: live search + role chips (Cashier / Sales & WhatsApp / Storekeeper / Purchasing /
Accounts / Owner). Deep links: /panel/training#pos #trust #settings #faq … (highlighted on arrival).

**Contextual help where confusion actually happens:**
- POS till: first visit auto-plays a 3-step spotlight tour (search → cart → payment) with a
  "📖 Open full training" link; the **?** button in the header replays it anytime. Seen-flag is
  per device (localStorage), so each new till/computer shows it once.
- Inventory Trust: **?** in the header → training #trust.
- Settings: "🎓 How settings work" link in the sidebar → training #settings.

Files: app/Http/Controllers/Panel/TrainingController.php (new) ·
resources/panel/help-center.html (new — NOT the pre-existing training.html, which belongs to
the Win World MES vertical and is untouched) · resources/panel/pos.html (tour + ? button) ·
resources/panel/trust.html + settings-manager.html (help links) · routes/web.php
(/panel/training) · config/navigation.php (Home → Training). The old thin /panel/guide page
stays as-is. No migration — upload + Rebuild. Tested headless: tour auto-open/replay/persist,
search + role filters, deep-link highlight (fresh load AND hash change), till regression
(barcode add still works) — all green.

Content update policy: the Training Center is one static HTML file
(resources/panel/help-center.html) — when a future drop changes how a screen works, update the
matching card in the same drop.

## v3.6 — POS lives in V3 now + photo tiles + V3-FIRST rule

Bhavin: POS button + menu placement like UltimatePOS, product photos on the till, everything in
V3, classic panel to be retired ("make rule so any future development come under v3").

**1. POS in V3 (resources/views/shell.blade.php):**
- Green **POS** button in the V3 top bar (UltimatePOS-style, next to the currency picker;
  label collapses to icon-only on small phones). Same visibility gate as the menu entry.
- **Sales → POS Till** menu entry (first item in the Sales group) with a till icon; `#/pos`
  deep link works.
- Both open the till EMBEDDED in the V3 shell (iframe `/panel/pos?embed=1`, edge-to-edge like
  Settings — the exact house pattern already used by settings/payments/pnl/books). In embed
  mode the till hides its own back button and shows **⛶ Open full screen** instead — for a
  dedicated till computer, ⛶ opens the naked till in its own tab.
- **Help & Training in V3** (`#/guide`) now opens the Training Center (`/panel/training`)
  instead of the old server guide workspace — one training surface everywhere.

**2. Photo-first till tiles (resources/panel/pos.html):** every product card now leads with an
86px photo (object-fit contain, lazy-loaded; soft 🧺 placeholder when a product has no photo,
and on broken URLs). Grid unchanged otherwise — verified in-browser: photos render, placeholders
render, embed mode hides back/shows ⛶, tap-to-cart still works, no JS errors.

**3. THE RULE — V3-FIRST.md (new, repo root):** classic panel is FROZEN for new work; every new
screen/button/action ships inside V3 — either as a native capability workspace or as a
full-screen page embedded via the house iframe pattern (the file documents the exact 6 edit
points in shell.blade.php with code). Full-screen operational pages (till, counts, training)
are allowed but V3 must be their front door and they must support `?embed=1`. Every drop that
changes a screen updates its Training Center card in the same drop. config/navigation.php now
carries a "⛔ FROZEN FOR NEW WORK" header pointing at V3-FIRST.md. Migration is opportunistic:
when a classic screen gets its next real feature, that's when it moves behind V3.

Files: resources/views/shell.blade.php (POS button/menu/route + guide→Training; JS
syntax-checked with blade stripped) · resources/panel/pos.html (photo tiles + embed mode) ·
config/navigation.php (frozen header) · V3-FIRST.md (new). No migration — upload + Rebuild.
After deploy: open /v3 → green POS button top bar, Sales → POS Till, Help & Training opens the
Training Center.

## v3.7 — shell.blade.php split into static assets (response to the code audit)

The audit was right that 4,313 lines in one blade is a growing risk. This drop does the SAFE
version of the fix — a mechanical, provable split with NO build tool and NO behaviour change:

- resources/views/shell.blade.php  → now ~78 lines: HTML skeleton + two asset links only.
- public/assets/v3/shell.css (NEW) → all V3 styles (≈89 KB), byte-identical to the old block.
- public/assets/v3/shell.js  (NEW) → all V3 JavaScript (≈262 KB), byte-identical except the one
  blade @json constant, now written literally. node --check clean.

Proof of equivalence: re-inlining the two files into the new blade reproduces the ORIGINAL
file byte-for-byte (verified by script). Then the split shell was BOOTED headless in Chromium
against a mocked workspace catalog: styles applied, boot() ran, sidebar + counts + the v3.6
POS button and Sales → POS Till entry rendered, clicking POS routed to #/pos and embedded the
till iframe — 8/8 checks green, no page errors.

Cache-busting is automatic (?v=filemtime of the asset) — no manual version bumps, browsers
pick up new JS/CSS on every deploy.

⚠️ DEPLOY WARNING — upload these THREE together, always: shell.blade.php +
public/assets/v3/shell.css + public/assets/v3/shell.js. The blade without its assets is a
blank V3. (This zip contains all three; Rebuild as usual.)

Why not the audit's full plan (Vite, Blade components, JS modules, @yield workspaces)?
- @yield-per-workspace would turn the client-side SPA into server-rendered pages — that is a
  rewrite of V3, not a refactor, with weeks of regression risk on a live business.
- Vite would introduce the project's FIRST build pipeline into a GitHub-upload + EasyPanel
  Rebuild workflow — a new class of deploy failure for a solo operator.
- Merge conflicts / unit-test arguments assume a multi-developer team.
The audit's valid core — stop the growth, separate concerns — is covered by V3-FIRST.md
(new screens live in their own files; the shell gains ~10 lines per screen) plus this split.
Next steps per V3-FIRST.md: when a module (customers, tasks, finance…) gets its next real
feature, its functions move from shell.js into their own static file — opportunistic
shrinking, never big-bang. V3-FIRST.md updated with the new file locations.

## v3.8 — POS speed pack + offline selling (response to the POS audit)

The POS audit's realistic asks, built and tested (29/29 browser assertions green):

**Keyboard-only checkout.** F5 cash · F6 mobile money · F7 card · F8 credit · F9 split ·
F4 hold · F3 discount · Enter confirms the payment AND starts the next sale · Esc cancels.
Key hints are printed on the buttons. A whole sale = scan, F5, Enter, Enter. Zero mouse.

**Hold / Recall (F4).** Customer forgot an item → hold the sale, serve the next customer,
tap ▤ Held to bring it back exactly as it was (survives browser restarts; per till).

**Split / partial payment (F9).** Cash + mobile money + card amounts in ONE sale with a live
"Remaining" line. Pays short → the balance automatically becomes customer credit (name/phone
required) and the invoice shows part-paid. Each tender is recorded separately in the cashbook
with its own method — end-of-day cash vs momo vs card totals stay truthful.
Server side: checkout accepts payments[] and method=split (whitelisted); short-paid split
without a customer name/phone is rejected exactly like a pure credit sale.

**OFFLINE SELLING.** The big one for Juba internet:
- The till downloads the whole active catalogue once (localStorage) — scanning and searching
  keep working with no connection.
- Checkout while offline: the sale saves into a local queue, the receipt still prints
  (numbered PENDING SYNC), change is still calculated, and an orange ⚡ OFFLINE chip shows the
  queue count. Selling never stops.
- When connection returns (or every 20s), queued sales sync automatically: order created,
  tenders recorded, receipt number assigned, toast confirms each one.
- SAFETY: every sale carries a client_ref; the server writes it into orders.idempotency_key
  (UNIQUE index — already in your schema). A replayed sale returns the EXISTING order with
  duplicate=true + how much is already paid, so retries can never create a second order or
  double-record a payment. Verified in-browser: replaying a synced sale returned
  duplicate=true, record_payment=false.

**Receipt reprint.** TODAY drawer → 🖨 next to any sale of the day → prints again marked
*** REPRINT *** (works because pos-today now returns items_json).

Also: split tenders itemised on the receipt with any credit balance; Training Center POS card
updated with all of this (per V3-FIRST rule).

Files: app/Http/Controllers/Panel/PosController.php (client_ref idempotency + duplicate
fast-path & race catch, payments[] validation, split short-pay guard, all=1 catalogue fetch,
items_json in pos-today) · resources/panel/pos.html (everything above) ·
resources/panel/help-center.html (POS card). No migration — upload + Rebuild.

NOT built (deliberately, discussed with Bhavin): shift open/close with denomination count
(next candidate — fits the Trust philosophy), returns/exchanges, manager-approval PINs,
promotions/loyalty, customer display, ESC/POS drawer kick (needs a local print agent),
scale barcodes. Roadmap-ordered in chat.

## v3.9 — Shift & control pack (17/17 browser checks green)

**⚠ THIS DROP HAS A MIGRATION:** after upload + Rebuild run `php artisan migrate --force`
(creates the `pos_shifts` table; hasTable-guarded, safe to re-run).

**1. Cashier shifts — float → sales → count → variance.**
- Till shows a soft banner until a shift is open: "▶ Open shift" asks for the opening float
  (count the drawer BEFORE the first sale). Selling is never blocked — the banner is a nudge.
- TODAY drawer now has a live shift box: opened-at, float, cash sales this shift, expected in
  drawer. One button: "⏹ Close shift & count cash".
- Closing = a DENOMINATION SHEET (notes for USD/SSP/UGX auto-picked by till currency, plus a
  coins/other line). The system totals it live, compares against expected
  (float + this operator's POS cash payments from the unified CustomerPayment ledger, joined
  through POS order numbers), shows the variance in green/amber/red, saves everything to
  `pos_shifts` (denominations JSON included, for audit), and prints an 80mm CASH-UP SLIP with
  cashier + supervisor signature lines.
- Shifts are PER OPERATOR (user id): two cashiers on two logins each balance their own drawer.

**2. Manager PIN for big discounts — enforced server-side.**
- Settings → Payments → **POS till**: "Discount limit without manager (%)" (default 5) and
  "Manager PIN". No PIN configured = no gate (zero-setup back-compat).
- Above the limit the till asks for the PIN (F-keys pause, Enter submits); the SERVER
  re-verifies with hash_equals — bypassing the UI cannot bypass the check. Wrong PIN re-asks.
  Offline sales carry the PIN and are verified at sync time.
- Replays of already-created sales resolve BEFORE the PIN check, so changing the PIN never
  strands the offline queue.

**3. Offline sync panel.** The ⚡ chip is now tappable: every queued sale listed (time, amount,
method, QUEUED status), failed ones marked FAILED with a discard button (after the owner has
seen them), and a RETRY NOW button for manual sync.

Files: PosController (shift/shiftOpen/shiftClose + shiftCash ledger query + PIN gate +
DISCLIMIT/PINSET placeholders) · PosShift model + pos_shifts migration · routes (3 papi) ·
SettingsSchema + SettingsWriteService (pos_discount_limit, pos_manager_pin) · pos.html ·
help-center.html (POS card teaches shifts, PIN, sync panel).

Honest notes: the PIN is stored as a tenant setting (owner-only screen) — fine for a till
PIN, not for real secrets. shiftCash counts POS cash payments where holder = the operator;
if a manager records a POS payment on the cashier's behalf under their own name, it lands in
the manager's shift, not the cashier's — train "whoever takes the cash records it".
PHP verified by lint + review; browser flows fully tested against a mock of the new contract.

## v3.9.1 — HOTFIX for the blank V3 (31 Jul, 08:54)

**What happened on your server:** the v3.7+ blade went live but `public/assets/v3/shell.css`
and `shell.js` never reached GitHub (the new `public/` folder was missed in the upload) →
both 404 → V3 rendered unstyled with no JavaScript ("toggleNav is not defined"). The `?v=1`
in your console is the blade's fallback confirming PHP couldn't see the files either.
The classic panel and /panel/pos were unaffected.

**The permanent fix (self-healing assets):**
- The SAME two files now also ship at `resources/v3/shell.css` + `shell.js`.
- A new public route `/assets/v3/{file}` (whitelisted filenames only) serves the public/ copy
  when present, else the resources/ copy — modeled on your own inline-manifest route, which
  exists for exactly this failure class. nginx still serves the static file directly whenever
  it exists, so there is zero performance change on healthy deploys.
- The blade cache-busts on whichever copy exists.
- Net effect: EITHER the public/ folder OR the resources/ folder arriving is enough — a
  half-upload can degrade to slightly-slower asset serving, never to a blank app.

**Deploy this zip normally (all folders), Rebuild, hard-refresh (Ctrl/Cmd+Shift+R).**
If you only want the 2-minute unblock first: upload the `public` folder from any v3.7+ zip
into the repo root (so `public/assets/v3/shell.css` exists on GitHub) and Rebuild — but do
apply this full zip after, for the self-healing.

(The icon-192.png 404 in your console is a separate, cosmetic, pre-existing gap — the PWA
manifest references an icon file that was never uploaded. Harmless; we can add icons later.)

Files changed vs v3.9: routes/web.php (+fallback route) · resources/views/shell.blade.php
(dual filemtime) · resources/v3/shell.css + shell.js (new copies) · V3-FIRST.md (sync duty).
Migration from v3.9 still applies if you haven not run it: php artisan migrate --force.

## v4.0 — POS Returns (roadmap #1) — 15/15 browser checks green

**⚠ MIGRATION in this drop too:** `php artisan migrate --force` after Rebuild (extends the
legacy order_returns table — additive, hasColumn-guarded, safe to re-run; also creates
pos_shifts if you skipped v3.9's migration).

**Investigation first (per the roadmap):** the repo already had the pieces — a legacy
ReturnRecord register (order_returns table + store-credit-per-phone map, but NO restock and
NO accounting reverse; it even rewrote order.total), a full CreditNoteService (numbered
CN-2026-XXXX with a return_id field built for exactly this, PDF, statement-visible), and
StockLedger::receive. v4.0 orchestrates them instead of inventing anything.

**The flow at the till:** ↩ button (top bar, or next to any sale in TODAY) → find the sale by
receipt number, phone, or id (last 60 days) → pick items with steppers that CAP at
sold-minus-already-returned (server re-validates) → refund method:
- 💵 CASH BACK / 📱 MOMO — money leaves the drawer; mirrored into the legacy cashbook
  (LedgerEntry out/refund, best-effort) and the cashier's SHIFT expected-cash adjusts
  automatically (shiftCash now subtracts their cash refunds).
- 🎫 STORE CREDIT — feeds the EXISTING store-credit-per-phone balance (legacy panel page
  keeps working); requires the customer phone.
→ manager PIN (REQUIRED for every return when a PIN is configured — returns are the classic
till-fraud vector) → then, in one transaction chain:
1. ReturnRecord with structured items_json + operator (extended columns),
2. numbered CREDIT NOTE via CreditNoteService (linked to return + the sale's invoice when one
   exists; reason 'return'; PDF stored) — the authoritative accounting reverse,
3. stock BACK via the same StockLedger the sale used (ref_type pos_return → Trust sees
   returns too) — symmetric: ledger off = no movement, same as the sale,
4. printable RETURN SLIP (negative lines, CN number, customer signature line).

Prices come from the ORIGINAL sale lines, never today's list. Unit prices capped at what was
paid. Returns are online-only by design (the sale must be verified server-side).
Exchanges = return + normal new sale (documented in Training; no special flow needed).

Files: PosController (findSale + processReturn + shiftCash refund deduction) ·
migration 2026_07_31_000002 (order_returns: items_json, refund_method, credit_note_no,
order_no, user_id, operator) · ReturnRecord model (new fillables/casts) · routes (2 papi) ·
pos.html (return modal, ↩ buttons, PIN modal generalised to askPin(reason, retry)) ·
help-center.html (returns card).

Honest notes: the credit note + cashbook mirror are best-effort inside the return (logged if
they hiccup; the ReturnRecord always stands). P&L treatment of refunds follows your credit
note conventions — if the accountant wants refunds ALSO reflected in OrderCost, that is a
small follow-up. PHP verified by lint + review; full flow browser-tested against a mock of
the exact contract.

## v4.1 — Customer display (roadmap #4) — 11/11 two-window browser checks green

A second, customer-facing screen for the till — the audit's "small feature, huge customer
confidence". Zero new infrastructure: it is a browser window on the SAME till machine.

**Setup (once per till):** tap the new 🖥 button on the POS → a window opens at
/panel/pos-display → drag it to the customer-facing monitor → press F11 for full screen. Done.

**What the customer sees, live:**
- Welcome screen with your logo + tagline (Settings → Business profile → tagline) while idle.
- Each item the moment it is scanned (last item highlighted), quantities, prices, and the
  running TOTAL in large type — price disputes end before they start.
- During payment: AMOUNT DUE, then CASH RECEIVED and their CHANGE the instant the cashier
  types the tendered amount.
- After the sale: a green "✓ Thank you" screen repeating the change, then back to welcome.

**How it works (why it needs nothing):** the till broadcasts every change into localStorage;
the display window listens to the browser's cross-window storage events. Same machine, same
origin, effectively zero latency, works even while the till is OFFLINE (the broadcast never
touches the network). No websockets, no polling, no backend.

Files: PosController::display (+placeholders TENANT/CURRENCY/LOGO/TAGLINE) ·
resources/panel/pos-display.html (new) · route /panel/pos-display · pos.html (🖥 button +
disp() broadcasts from cart/pay/change/done states) · help-center.html (POS card tip).
No migration. Bug found & fixed during testing: the pay panel used a stylesheet
display:none, so clearing the inline style re-hid it — explicit display:block now, with a
true visibility assertion in the test suite.

## v4.2 — Quotations at the counter + tabbed Recent panel (9/9 checks green)

Bhavin asked for UltimatePOS's Draft / Quotation / Recent Transactions. Delivered — mapped
onto CloudBSS concepts instead of copied:

**📝 Quote (cart → numbered quotation).** Build a cart, tap 📝 Quote, add name/phone → a real
quotation is created through the SAME QuotationService the WhatsApp bot uses: same numbering
(FS-Qyymmdd-XXXX), same PDF format, same customer-timeline event, listed in the same V3
Quotations screen. The PDF opens in a new tab for printing/handing over (popup-blocker-safe:
the tab opens on the click, then navigates). Cart clears afterwards.

**⤵ Quote → Sale.** TODAY → Quotations tab → ⤵ Load puts the quoted items back into the cart
AT THE QUOTED PRICES (lines resolved to products by exact name; unmatched lines are reported,
never silently dropped; a live cart is auto-held first, never destroyed). Then take payment
normally — the wholesale counter flow ("quote me 20 cartons" → comes back later → sell) is
now two taps.

**Recent panel with tabs (the "Recent Transactions" screen).** The TODAY drawer now has:
- Sales — today's sales with 🖨 reprint and ↩ return (as before),
- Quotations — latest 15 from ALL sources (pos + WhatsApp bot) with 🖨 print + ⤵ load,
- Drafts / Held — parked carts with recall/discard (same store as ⏸ Hold/F4).

DELIBERATE difference vs UltimatePOS: no Edit and no Delete on completed sales. A finished
sale is immutable — corrections go through ↩ Returns (credit note + stock + audit trail).
Edit/Delete on finals is an audit hole; CloudBSS does not copy it.

Draft vs Suspend: UltimatePOS has both; on this till they are ONE concept (⏸ Hold) shown in
the Drafts/Held tab — fewer buttons, same capability.

Files: PosController (quoteCreate via QuotationService generate+persist, quotes list,
quoteLoad with name→product resolution) · routes (3 papi) · pos.html (📝 Quote button+modal,
tabbed Recent panel, ⤵ load) · help-center.html (POS card). No migration.
Note: QuotationItem.qty is integer in the existing schema — decimal (weighed) quantities in a
quotation round to whole units; fine for carton/piece wholesale quoting.

## v4.3 — Customer on the sale (11/11 browser checks green)

Bhavin (UltimatePOS "Add a new contact" screenshot): "how we can add customer name and
details from pos screen?"

**The 👤 chip.** Above the cart there is now a customer chip — default "Walk-in customer".
Tap it:
- **Search** by phone or name (recent customers listed instantly, live search from
  CustomerProfile via new GET /papi/pos-customers).
- **＋ New customer**: name, phone, address on the spot — saved through the EXISTING
  /papi/customer-save (phone-keyed CustomerProfile upsert), then attached. Offline? The form
  still works: the customer rides on the queued sale and CustomerMasterService files them
  when it syncs. (No new "create contact" API was needed — attaching name+phone to the order
  IS how CloudBSS files customers.)

**Once attached, everything picks it up:**
- The sale itself — ANY payment method (a named cash sale updates their purchase history).
- The receipt prints a Customer line.
- CREDIT / short-paid SPLIT: no retyping — fields prefill from the chip (still editable),
  and the "needs name or phone" guard is satisfied by the chip.
- 📝 Quotations prefill name+phone.
- ⤵ Loading a quotation attaches its customer automatically (quote → sale keeps the person).
- ⏸ Hold/Recall keeps the customer with the parked cart.
- Every new sale resets to Walk-in (deliberate — no accidental cross-filing).

Files: PosController::customers (+route pos-customers) · pos.html (chip row, picker modal
with quick-create, prefill wiring, hold/receipt/quote/load integration) · help-center.html.
No migration. Note: unlike UltimatePOS's big contact form, details beyond name/phone/address
live in Customers 360 — the till stays a till.

## v4.4 — WhatsApp on the customer record + ✨ AI-personalised drafts (8/8 checks green)

Bhavin (customer 612 screenshot): "send individual customer whatsapp message and his whatsapp
messages we can see in one place… ai can create customer wise campaign so we can target
customer with what they are looking for."

**One place: the customer record.** Open any customer in V3 → a new **💬 WhatsApp card**:
- the conversation tail (last 12 messages, both directions, in bubbles),
- a compose box — Send posts through the PROVEN /papi/chats/send, which also flips
  agent_active so the bot yields the chat exactly like Take-over in Chats,
- **Open full chat** → jumps straight to that conversation in the Chats screen.

**✨ AI draft — targeting what THEY are looking for.** One tap asks what the message should
achieve (optional hint, e.g. "offer foil containers, 10% off this week"), then the AI writes
ONE personalised WhatsApp message using the relationship memory (stage, current goal, usual
products, language — the same ProfileContext the bot uses) plus the recent conversation.
The draft lands IN THE COMPOSE BOX for the owner to read, edit, and send. Guardrails:
- The AI NEVER sends by itself — a human always presses Send.
- It is instructed to never invent prices/discounts/stock; offers come only from the hint.
- Uses the tenant's own OpenAI key/model settings (same as the bot).

Files: app/Http/Controllers/Panel/CustomerWaController.php (NEW: GET /papi/wa-thread,
POST /papi/wa-ai-draft) · routes · public/assets/v3/shell.js + resources/v3/shell.js (BOTH
copies, per V3-FIRST: waCustCard injected on customer records; papi calls use getQuery/post —
NOT api(), which prefixes the workspace base) · help-center.html (Chats card tip).
No migration.

**Bulk "customer-wise campaign" (the second half of the ask) — designed, not yet built:**
the right shape is AI-generated per-customer drafts into a REVIEW QUEUE (audience = e.g.
stage INTERESTED, or "usual products contain X"; every message personalised from memory;
owner approves/edit/skips each or approves all; sending PACED over hours to protect the
WhatsApp number; customers with open confirmed quotes suppressed). Say "build the campaign
generator" and that becomes the next drop.

## v4.5 — AI Campaign Generator (10/10 checks green)

**⚠ MIGRATION in this drop:** `php artisan migrate --force` after Rebuild (adds
ai_campaign_drafts; also covers earlier migrations if not yet run).

**V3 → Marketing → ✨ AI Campaign** (owner-only). The flow:
1. **Audience** — relationship stage (INTERESTED, NEW, …), "looking for / usually buys
   contains …", active-in-last-N-days. Live count + sample; shows how many mid-deal
   customers will be auto-skipped.
2. **Message plan** — campaign name, what we're promoting, and an EXACT OFFER line that the
   AI must use word-for-word (it is forbidden from inventing prices/discounts/stock).
3. **✨ Generate** — the AI writes a DIFFERENT message for every customer from their
   relationship memory (goal, usual products, language, name), 10/20/40 per batch (re-run to
   extend the same campaign). Suppressed at generation: no phone · already in an active AI
   campaign · CONFIRMED-but-unconverted quotation (never market mid-deal).
4. **Review queue** — every draft is editable; Approve / Skip per draft or Approve-all.
   NOTHING sends without human approval. Empty drafts cannot be approved.
5. **▶ Start sending** — the new `aic:send-tick` scheduler (every minute) sends at most
   2 messages per tenant per minute with 3–11 s jitter, 08:00–20:00 tenant-time only.
   Idempotent through the SAME campaign_messages claims the classic campaign sender uses —
   a crashed/re-run tick can never message anyone twice. The page live-polls while sending;
   campaign completes to status 'sent' with stats.

Riding on existing rails: campaigns table row (type 'ai' — visible with the rest of
marketing), CampaignMessage::claim idempotency, ProfileContext memory, tenant OpenAI
key/model, WhatsAppManager gateway. Pacing doctrine copied from your own SendCampaign job:
"broadcasting fast is the quickest way to get a number banned."

Files: migration 2026_07_31_000003 · AiCampaignDraft model · Panel/AiCampaignController
(page + 6 papi, all owner-gated) · Console/AiCampaignSendCommand + routes/console.php
schedule · resources/panel/ai-campaign.html (wizard) · routes/web.php ·
public/assets/v3/shell.js + resources/v3/shell.js (Marketing → AI Campaign entry, BOTH
copies) · help-center.html (Campaigns card).

Honest notes: generation is synchronous (≤40/batch, ~1-2 s per draft — the button says "up
to a minute"); the daytime window uses tenant setting `timezone` (default Africa/Kampala);
sending requires the scheduler to be running (same cron that powers trust digests).

## v4.5.1 — bot hotfix: irrelevant product cards (real incident, 31 Jul 18:53)

Transcript findings (customer +211 922 206 556; human takeover at 19:01 rescued it):
1. "Send me your samples" → bot attached ROCK SALT (never mentioned). "samples" fuzzy-matched
   "Salt".
2. "But why are you not picking the call" → bot attached SHAN BUTTER CHICKEN. "picking"
   fuzzy-matched "Chicken".
3. "For fish 🐟" (while discussing takeaway containers) → bot pitched fish SPICES instead of
   fish-sized CONTAINERS — topic lost.
4. Same Rock Salt card sent twice within a minute.

Root cause (ProductImageResponder::confidenceScore): a plain sentence defaulted to MAX intent
(P=100) and a SINGLE fuzzy search hit auto-scored M=100 — so any conversational sentence
whose letters vaguely resembled one product name sailed past the threshold.

Fixes:
- **HARD WORD GATE**: an image requires at least one real word the customer typed to appear
  in the product name. "samples"≠Salt, "picking"≠Chicken → no image, ever. 
- **Single fuzzy hit no longer auto-max**: M=90 only WITH word overlap; plain sentences now
  P=70 (below threshold alone); explicit "I need/want/buy" = P=88 (so "i need take away
  silver plate" still gets the plates card). Long free sentences without intent words: no image.
- **SERVICE-TURN GUARD**: calls/phone/location/delivery/complaints/speak-to-human turns never
  get product images at all.
- **10-minute per-image cooldown** per customer (no more double Rock Salt).
- **Prompt (AiBrain)**: TOPIC CONTINUITY rule ("for fish" refines the current topic — while
  discussing containers it means containers FOR fish, never fish spices; ambiguous → ask ONE
  question) · "samples" = product photos, ask WHICH · call requests → apologise, give the
  BUSINESS DETAILS phone, no product attach.

Verified: 12/12 dry-run cases on the exact incident lines + legit asks (rock salt, foil
container, disposable plates all still send correctly).

**ACTION for Bhavin (data, 1 minute):** add to Settings → Business profile → Brand knowledge:
"In Juba, customers say 'silver plates' or 'silver dishes' meaning aluminium foil containers/
plates. For fish, recommend Aluminum Foil Container Big-83120." — that teaches the bot the
local trade slang the same way a new staff member would learn it.

Files: app/Services/Bot/ProductImageResponder.php (FIRST TIME in the zip — if your live copy
differs from shoppingmain41 base, review before overwrite) · app/Services/Bot/AiBrain.php.
No migration.

## v4.5.2 — second incident, 19:14 same evening (customer "Bul UNMISS GATE Alaak")

Three NEW failures found — one of which slipped past v4.5.1:

1. **Bot recommended COMPETITOR shops** ("check local shops in Konyo Konyo Market or Jebel
   Market") when asked "can you connect me with any shop". Unforgivable for a shop's own bot.
   → New prompt rule WE ARE THE SHOP: never refer customers elsewhere; offer the closest item
   we stock, a SPECIAL ORDER, or team follow-up — the business stays with us.
2. **Bot re-asked for the delivery location the customer already gave** ("Buy I just told you
   the location now"). Root cause: history window was 12 messages — this customer quote-echoes
   every reply (2 slots per exchange) and [photo] marker rows ate the rest, so facts from 4
   exchanges ago fell out. → History now 20 turns AND media-marker rows ([photo]/[file]/…)
   excluded from what the model reads. + prompt rule USE WHAT THEY ALREADY TOLD YOU (re-read
   before asking; repeat back to confirm; apologise if truly re-asking).
3. **"But now you don't have want I want" STILL attached the Butter Chicken card** — "BUT" is
   a 3-char substring of "BUTter", which passed v4.5.1's word gate. → Overlap words must now be
   ≥4 chars AND not conversational filler (50-word chatter list: have/want/just/told/still/…).
   Plus a frustration guard: "don't have / not what I / never mind / forget it / cancel" turns
   never get product cards. Bare product-name messages ("rock salt", "butter chicken masala")
   still work via a short-phrase intent bump that excludes preposition-led fragments ("for
   fish" stays suppressed — the topic-continuity rule handles those in text).

Verified: 14/14 dry-run — every line from BOTH incidents suppressed, every legitimate ask
(need/want/price/have/show/bare product name) still sends its photo.

Files vs v4.5.1: app/Services/Bot/AiBrain.php · app/Services/Bot/ProductImageResponder.php.
No migration.

## v4.5.3 — reorder follow-ups made human (real message, 31 Jul)

A customer received: "Hi Abdifatah Ahmed Shop No 48 👋 It's been 10.005576970544 days since
your last order (20x ALUMINIUM FOIL 45CM GOLDEN (CARTON OF 6PC, MIN 5 CARTON))…"

Three robot-tells fixed in SalesCadenceCommand (the automated sales-cadence sender):
1. **10.005576970544 days** — Carbon 3's diffInDays() returns a float; now floored to a whole
   number ("10 days").
2. **"Hi Abdifatah Ahmed Shop No 48"** — WhatsApp contact names carry shop labels. New
   firstName(): greets with the first name only, stops at shop/store/stall/duka/number tokens,
   softens ALL-CAPS ("ABDI" → "Abdi"). Applies to EVERY cadence template ({name} everywhere).
3. **Raw catalogue line as the item** — new humanItems(): strips parenthetical metadata
   ("(CARTON OF 6PC, MIN 5 CARTON)" gone), title-cases SHOUTING words, 60-char cap.
   Result: "(20x Aluminium Foil 45CM Golden)".

AFTER: "Hi Abdifatah 👋 It's been 10 days since your last order (20x Aluminium Foil 45CM
Golden). Ready for a fresh supply? I can send today's prices and arrange delivery."

Reminder: every cadence message is owner-customisable via settings (sales_msg_reorder,
sales_msg_day1, sales_msg_pricelist, sales_msg_day7) with {name} {days} {items} placeholders —
now filled with human values.

File: app/Console/Commands/SalesCadenceCommand.php (FIRST TIME in the zip — drift caveat if
your live copy diverged from the shoppingmain41 base). No migration.

## v4.5.4 — quotation revision messages show the items (real message, 01 Aug 12:01)

Customer got: "Your quotation ORD-Q260801-OEA9 has been updated (Revision 3). New total:
USD 120. Review & approve: <link>" — and NOT ONE line item. Bhavin: "if we are updating
quotation customer is not able to see anything from line". Compare the ORIGINAL quotation
message, which lists every product — the update looked like a different (worse) system.

Fixed — the revision WhatsApp message now carries the full picture:

```
📄 Your quotation ORD-Q260801-OEA9 has been updated (Revision 3).

• 2 x Aashirvaad Wheat Flour 5kg — 24
• 1 x Pavitra Wheat Atta (Indian) 5kg — 12
• 3 x Star Goldy Vegetable Oil 5L (Carton of 4pc) — 84

What changed: Star Goldy Vegetable Oil 5L (Carton of 4pc) price 32.50 → 28; removed Rock Salt 1kg

New total: USD 120
Review & approve: https://mycloudbss.com/q/…
```

What went in:

1. **Every line with its amount** (qty x name — line total), capped at 15 rows for huge
   wholesale quotes ("…and N more items (full list in the PDF)"), with line discounts shown
   ("(5% off)") and a Transport/delivery row when freight is set — so the rows visibly add up
   to the total.
2. **"What changed" diff line** — the revision engine already computed the diff for its audit
   ledger; now the customer sees it: added/removed lines, qty 3 → 2, price 32.50 → 28,
   discount and freight changes, plus any comment sales wrote. Capped at 5 (+N more).
3. **The updated PDF now actually arrives.** The old code sent the PDF via
   Storage::disk('public')->url() — the exact pattern the portal code itself documents as
   broken on this deploy (no storage symlink → 404; Cloud API refuses non-URL media). Now it
   uses the public streaming route /q/{token}/pdf, the same proven path the portal Share
   button uses. Caption says "Updated quotation … (Revision N)".
4. **Money never silently rounds.** number_format() with no decimals was ROUNDING totals —
   a 152.51 quote would have messaged "USD 153". New money(): cents kept when present
   (152.51), clean whole numbers otherwise (120, 1,450,000). Applied to the revision message,
   the portal Share summary, the Email Center total and the sales alert.
5. **The sales-side alert grew up too.** When the CUSTOMER edits on their portal link, your
   WhatsApp alert now says WHAT they changed — including their typed requests ("customer
   asked: 'Can you do a better price on the oil?'") — not just the new total.
6. Training Center → Orders card: new tip explaining both directions of the notification.

Where it fires: the "Revise quotation" action (/papi/quotation-revise — the path your
Revision 3 message came from) and customer portal edits. The old /papi/quotation-update
endpoint (V3 Quotations screen inline edit) stays silent by design — it regenerates the PDF
without messaging the customer; use Revise when the customer should be told.

Verified: 19/19 harness assertions against the REAL service file, including the exact
ORD-Q260801-OEA9 scenario, remarks-only revisions (no dangling "What changed"), 22-line
quotes, freight + discount math, request visibility rules and the 152.51-cents case.

Files vs v4.5.3: app/Services/Sales/QuotationReviseService.php ·
app/Http/Controllers/Panel/QuotationReviseController.php ·
app/Http/Controllers/Storefront/QuotationPortalController.php ·
resources/panel/help-center.html. (The three quotation files are in the zip for the FIRST
time — same drift caveat as before if your live copies diverged from the shoppingmain41
base.) No migration.

## v4.6 — MOQ: the AI now has a real enforcement switch (question, 01 Aug)

Bhavin: "in ai its giving reply for small qty while we maintain the MOQ in product — does ai
respect this or not?"

**Answer from the code: only half, and the enforcing half was switched off.**
- The AI DOES see every product's MOQ (its catalogue context lines read "… — 12 per carton,
  MOQ 5 [in stock]") and mentions minimums when asked.
- But real enforcement lives in OrderCalculator (the engine that prices every bot quote and
  order confirmation) behind the tenant setting `moq_mode` — default **off**. Off = a
  below-minimum request is quoted AT the small quantity with only a passive "(min order 5)"
  note. The switch existed ONLY in the one-time onboarding wizard, so it was never revisited.
- With `moq_mode=roundup`: the engine raises below-MOQ quantities to the MOQ and the line
  reads "min order 5 — set to 5"; AiBrain also injects a hard prompt rule ("MINIMUM ORDER
  QUANTITIES ARE ENFORCED — never confirm below MOQ"). All of that engine code already
  existed and is untouched by this drop.
- Enforcement reads the products.moq COLUMN. A minimum written only inside the product NAME
  ("… MIN 5 CARTON") enforces nothing.

Shipped:
1. **Settings → WhatsApp & bot → "Selling rules"** — new field: Minimum order quantities =
   Advisory (default) / **Enforced — round below-minimum requests up to the MOQ**.
   (SettingsSchema + SettingsWriteService ENUM allow-list.)
2. **`php artisan moq:backfill --tenant=17`** — copies name-embedded minimums into the moq
   column. Dry-run by default (prints every WOULD SET line for eyeballing), `--write` to
   apply. Fills ONLY empty columns — a deliberately-set MOQ is never overwritten (a
   disagreement between column and name is printed as KEPT for a human decision). Pattern
   engine app/Support/MoqPatterns.php: "MIN 5 CARTON", "MOQ 10", "MIN. 5", "MINIMUM 5 CTN",
   "MIN ORDER 20", "5 CTN MINIMUM"… while refusing sizes ("5kg", "45CM"), pack counts
   ("CARTON OF 4PC"), "MINT", MIN 1 and absurd numbers. 22/22 harness on real catalogue names.
3. Training Center bot card: the two-switch explanation (product MOQ field + Settings toggle),
   including that the POS till is deliberately never blocked (seller may sell below MOQ).

**Turn it on (2 steps + 1 optional):**
1. (optional, if minimums live in product names) `php artisan moq:backfill --tenant=17` →
   review → re-run with `--write`.
2. Check a few products: Products → edit → MOQ field filled (import column "min order" also works).
3. Settings → WhatsApp & bot → Selling rules → **Enforced** → Save.

Files vs v4.5.4: app/Support/SettingsSchema.php · app/Services/Settings/SettingsWriteService.php ·
app/Support/MoqPatterns.php (NEW) · app/Console/Commands/MoqBackfillCommand.php (NEW) ·
resources/panel/help-center.html. No migration (products.moq has existed since June).

## v4.6.1 — the "Review & approve" link goes nowhere (live incident #5, 01 Aug)

Bhavin clicked https://familyshoppers.net/q/… from the new revision message: nothing. Verified
from outside: familyshoppers.net/q/TOKEN → 404 AND mycloudbss.com/q/TOKEN → 404. **Every
quotation portal link ever sent was dead** — today was just the first time someone clicked one.

Two independent faults:
1. familyshoppers.net (tenants.custom_domain) is hosted ELSEWHERE — its root serves a separate
   shop site with no /q routes. Both link builders (Quotation::canonicalPortalUrl for quotation
   links + PDF QR codes, Tenant::publicUrl for order-tracking / task / digest links) trusted
   custom_domain blindly → dead links everywhere the column is filled.
2. Even the platform link was broken: the shopless /q/{token} route resolved the tenant ONLY by
   custom-domain host match (Tenant::byCustomDomain(host)). mycloudbss.com is nobody's custom
   domain → 404 for everyone, always.

Fixes:
1. **QuotationPortalController::resolve() token fallback** — when the host is not a known custom
   domain, resolve the quotation by its 40-char unguessable token alone (withoutGlobalScopes —
   this runs before TenantContext) and derive the tenant from it. The portal now opens on ANY
   host that serves the app. Fixes all 7 portal endpoints at once (page/data/pdf/share/approve/
   revise) — including the updated-PDF WhatsApp delivery from v4.5.4, which streams via /q/{token}/pdf.
   No security change: the token was always the only real credential.
2. **canonicalPortalUrl + publicUrl now gate the custom domain** behind a new owner setting:
   Settings → Storefront → "My own domain points to CloudBSS" (default OFF). While OFF, sent
   links use the platform address (APP_URL), which always works. Turn it ON only after
   familyshoppers.net is really connected to CloudBSS hosting.
3. Settings toggle wired end-to-end (SettingsSchema + SettingsWriteService BOOL allow-list).

After deploy:
- Future messages link https://mycloudbss.com/q/… → works immediately.
- The customer holding the dead familyshoppers.net link: send them the SAME token on the
  platform host — https://mycloudbss.com/q/ysatczxzsftxafw2iaiwfvqh6fyshqzvhkd1hvzq — it
  becomes valid the moment v4.6.1 is live (same token, working route).
- Order-tracking links in bot order confirmations, task-reminder links and pending-digest links
  were equally dead (all built by publicUrl) — all revive on the platform address.
- Requires APP_URL=https://mycloudbss.com in EasyPanel env (standing checklist item) so links
  built from queued jobs/commands use the right host.

Files vs v4.6: app/Http/Controllers/Storefront/QuotationPortalController.php ·
app/Models/Quotation.php (FIRST TIME in zip) · app/Models/Tenant.php (FIRST TIME in zip —
drift caveat if your live copies diverged from the shoppingmain41 base) ·
app/Support/SettingsSchema.php · app/Services/Settings/SettingsWriteService.php. No migration.

## v4.6.2 — "👨‍🍳 HOTEL, your order is being prepared" (live incident #6, 01 Aug)

A wholesale customer buying cartons was told a CHEF was preparing their order, and greeted as
"HOTEL" (raw ALL-CAPS contact name). Root cause: SendOrderStatusNotification (fires on every
status change) still carried the app's original RESTAURANT copy — "the kitchen is starting on
it", "👨‍🍳 being prepared", "🛵 our rider" — for every tenant, plus a hardcoded "left Kampala
… on its way to Juba" line for In transit, and a bare explode() for the first name.

Fixed:
1. **Vertical-aware wording.** Restaurant/snacks tenants keep the kitchen voice untouched;
   grocery / wholesale / manufacturer / solar / auto-parts / legacy tenants (= Family Shoppers)
   now get trade language: "✅ accepted — we're getting it ready" · "📦 being packed at our
   store" · "🚚 on the way to you" · generic in-transit line (no hardcoded route).
2. **Greeting fixed everywhere.** New shared App\Support\HumanText::firstName() (the v4.5.3
   cadence logic, extracted): "HOTEL DE JUBA" → "Hotel", "ABDIFATAH AHMED SHOP NO 48" →
   "Abdifatah". SalesCadenceCommand now delegates to it — one copy, no drift.
3. **Owner-overridable per status** (same pattern as sales_msg_*): tenant setting
   status_msg_<slug> (status_msg_preparing, status_msg_out_for_delivery, …) with {name}
   {order_no} {business} {status} placeholders — override any line without code.
4. Training Center orders card: note on status messages + templates.

Verified: 10/10 harness on the real HumanText file (HOTEL/Shop No 48/UNMISS-gate names,
placeholder fill, slug rule). php -l ×3 clean.

Files vs v4.6.1: app/Support/HumanText.php (NEW) · app/Jobs/SendOrderStatusNotification.php
(FIRST TIME in zip — drift caveat) · app/Console/Commands/SalesCadenceCommand.php ·
resources/panel/help-center.html. No migration.

## v4.6.3 — revised quotations could never become orders (live bug #7, 01 Aug)

Bhavin: "if we reveised quote then there is no way to convert that quotation into order".

**Verified — exactly right, and worse than reported.** The V3 quotation screen builds its
buttons in QuotationCapability, which used a private status whitelist
['sent','accepted','draft','pending'] written BEFORE the revision engine existed. Every
revision sets status 'revised' (sales edit) or 'customer_revised' (portal edit) — not on the
list — so on a revised quote:
- **Convert to order** disappeared (the reported bug),
- **Mark accepted / declined / expired** disappeared too,
- the pipeline regressed to "Sent: Not sent",
- the sidebar "Quotations" badge stopped counting it (NavCounts used the same style of list),
- the status badge printed raw "Customer_revised".

Meanwhile the convert ENDPOINT and QuotationConvertService never had any status gate — only
the buttons were missing. (The customer portal was immune: its approve uses the model's own
isOpen(), which is why customers could still approve a revised quote while your staff
couldn't convert it.)

**Fix — one source of truth:** all gates now derive from the Quotation model's lifecycle:
- Convert: shown for ANY open quote (isOpen(): not converted/declined/expired/archived) —
  so sent, pending, draft, accepted, revised, customer_revised all convert.
- Accept/Decline/Expire: any open quote not yet accepted (revised included).
- Revise: the model's isEditable() — matches what the revise service actually permits.
- Pipeline counts a revision as sent (a revision is always re-messaged to the customer).
- New record briefs: "Customer edited this quotation (Revision N) — check the changes, then
  convert or reply" / "Quotation revised (Revision N) … convert when they accept".
- List + sidebar: revised quotes back in the "awaiting" count and the nav badge; labels read
  "Revised" / "Customer revised" with the active-blue badge.

Conversion after revision carries the REVISED numbers: the revision engine rewrites the
quotation_items projection and total on every revision, and convert reads exactly those.
Closed-list logic means a future status can never silently fall out of the workflow again.

Verified: 19/19 decision-table harness (every status × convert/accept/revise, old-list
regression proof, labels, badge query). php -l ×3.

Files vs v4.6.2: app/Capability/Object/QuotationCapability.php ·
app/Capability/Process/QuotationListCapability.php ·
app/Capability/Shared/NavCountsCapability.php (all three FIRST TIME in zip — drift caveat) ·
resources/panel/help-center.html. No migration.

## v4.6.4 — "No line items recorded" on almost every order (live bug #8, 01 Aug)

Screenshot: salesOrder/211 (the converted FYZS quote, $31.77) shows "Line items — 0 products",
Cost of goods "—", Gross margin "—". Bhavin: "in almost all the places line items are missing".

**Root cause — two item stores, writers split between them.** Orders keep lines twice: a JSON
blob on the order row (items_json) and real order_items rows. Only POS checkout and the legacy
BotBrain wrote the ROWS. Quotation conversion (both converts), the panel's save-order path and
AI-captured orders wrote ONLY the blob. The V3 order screen, COGS/margin, dashboard analytics,
demand planning, recommendations and combos all read the ROWS → every non-POS order looked
empty and was invisible to the intelligence layer. (Customer-facing documents were never
affected: invoices, delivery notes and stock issuing read the blob.)

**Fix:**
1. **app/Services/Sales/OrderItemSync.php (NEW)** — one service that mirrors items_json into
   order_items: authoritative rebuild (delete + insert, idempotent, handles edits), keeps a
   blob-supplied product_id, else resolves by exact case-insensitive name against the catalogue
   in one query; unmatched names still insert (product_id null) so the screen shows every line.
   Never touches an order whose blob is empty (protects BotBrain's modifier rows).
2. Wired into all four blob-only writers, best-effort try/catch: QuotationConvertService,
   PanelApiController::quotationConvert, PanelApiController::saveOrder (create AND edit —
   edits rebuild rows), AiBrain order capture.
3. **php artisan orders:items-backfill --tenant=17 [--write] (NEW)** — repairs HISTORY: every
   order with a blob and zero rows gets its rows built (dry-run preview first; orders that
   already have rows — POS, BotBrain — are skipped by construction; idempotent). After
   --write, order 211 and all its siblings show their lines, margins compute, and analytics/
   demand planning finally count those sales.

Verified: 11/11 harness — order 211's exact blob → 7 rows re-summing to 31.77, product_id
preference + name-match + null fallback, unit_price key, string blobs, empty-blob guard,
backfill predicate skipping POS/BotBrain orders. php -l ×5 clean.

After deploy: run `php artisan orders:items-backfill --tenant=17` → review the list →
re-run with `--write`. Then open order 211 — the 7 lines are there.

Files vs v4.6.3: OrderItemSync.php (NEW) · OrderItemsBackfillCommand.php (NEW) ·
QuotationConvertService.php (FIRST TIME in zip — drift caveat) · PanelApiController.php ·
AiBrain.php. No migration.

## v4.7 — bus-packing pack (feature, 01 Aug)

Bhavin: "is there way we can create something by selecting what items we are sending in bus and
we can mentioned how that one is packed example in boxes we have packed in plastic which person
has which items advise how we can improve communication or system between juba and kampala" —
with a WhatsApp screenshot of the current improvisation ("1 box for gurpreet / 2box - rawu,
michel,pipe,posical stick" + box photos).

**Discovery: the Consignments module already existed** (Shipment/ShipmentBox/ShipmentOrder/
ConsignmentStock + state machine + custody ledger + box QR labels + no-login driver AND agent
token pages + missing-box exceptions + Juba→Kampala request lists — the code even names Jayesh
and Asish). What was missing was exactly the group-chat layer: per-box contents/packing, an
entry point from the orders list, and the agent page couldn't tick customers off. Built:

1. **Migration** extend shipment_boxes: packing (carton/plastic bag/sack/bale/drum/other),
   note, order_ids (which customer orders ride in the box). Guarded, nullable.
2. **"🚌 Send by bus"** bulk action on the Sales Orders selection bar (new shell "client"
   bulk-action kind) → Consignments screen shows a banner with the selected orders → create a
   new consignment with them in one tap, or open an open one and confirm loading (new
   /papi/consignment-add-many). V3 Consignments now embeds the FULL management page
   (/panel/consignment) instead of the thin list — create, load, pack, custody, market stock,
   requests all in one place.
3. **"How it is packed"** section on the consignment: add a box per carton/bag, pick the
   packing, tick whose goods go inside, note. Boxes auto-grow the custody box count (labels,
   scans, exceptions ride the same rows). New /papi/consignment-boxes, -box-save, -box-remove.
4. **Paper upgraded:** the printable manifest gains a Box column per order (order → "Box 2")
   and a "How it is packed" table; QR box labels now print whose goods + packing + note on
   each label.
5. **Dispatch → agent WhatsApp (automatic):** on Dispatch the Juba agent gets the box-by-box
   list (capped at 10 + "…and N more") and their no-login receive link /a/{token}. Uses
   destination_agent_phone (or setting consignment_agent_phone).
6. **Juba receive page (no login) grew up:** shows "What is in each box" + the customer
   manifest with box locations; after confirming arrival the agent ticks each customer —
   ✓ Given (marks the REAL order Delivered → invoice + customer WhatsApp ride the normal
   chain) or ↩ Back to Kampala (returned). New public POST /a/{token}/line, arrival-gated.
7. Training Center: the Consignments card is now a full step-by-step for the bus flow.

Verified: 11/11 Playwright browser assertions on the pack UI (banner → auto-load → pack box
with packing/customers/note → persistence → labels → add/remove box → pending cleanup, zero JS
errors) + 9/9 PHP harness on the agent message + sheet box map. php -l clean ×6; shell.js
copies md5-identical; node --check clean.

**MIGRATION REQUIRED:** php artisan migrate --force (adds the three shipment_boxes columns).

Files vs v4.6.4: NEW migration 2026_08_01_000001 · ShipmentBox.php · ConsignmentController.php ·
ShipmentController.php · ShipmentTrackController.php · SalesOrderListCapability.php ·
consignment.html (all FIRST TIME in zip — drift caveat) · shell.js ×2 · help-center.html ·
routes/web.php.

## v4.7.1 — Send-by-bus UX: no more guessing on arrival (feedback, 01 Aug 17:28)

Bhavin (with screenshots, minutes after deploying v4.7): "i am getting confuse here when i
selected order then i click send by bus and i land on this screen". Fair — the banner sat on
top of the NORMAL Consignments screen: a second near-identical green button ("＋ New
consignment") right under the banner's one, and the big "Request stock from Kampala" card
(the Juba side's tool) dominating the middle of a Kampala task.

Now arriving from Send-by-bus is a FOCUSED screen — one card, nothing else:
- "🚌 5 orders ready for the bus" with EXACTLY the real choices:
  · one-tap "📦 Load onto SH-0008 (3 orders on it)" for each OPEN consignment, or
  · "＋ New consignment with these 5", or
  · ✕ Cancel.
- The request-stock card, the generic New-consignment button and the history list are hidden
  until the task is done.
- No open consignment to choose? The create form OPENS ITSELF, with a line inside it:
  "🚌 The 5 selected orders will be loaded onto this consignment automatically. Then you can
  pack the boxes."
- One-tap load goes straight to the consignment detail with the packing section.

Verified: 8/8 Playwright (focused mode hides the noise, quick-load lands on packing, pending
cleared, modal note), zero JS errors.

Files vs v4.7: resources/panel/consignment.html only. No migration.

## v4.7.2 — pick Asish from a list; his WhatsApp fills itself (feedback, 01 Aug 17:39)

Bhavin (screenshot of the new-consignment form): "can you give option i can select person
example asish and his number goes there instantly and he received whatsapp message".

1. **Person picker in the New-consignment form.** "Receiver in Juba" is now a dropdown of your
   TEAM (name — number, from the same contacts the request card uses). Pick Asish → his name
   and WhatsApp number fill instantly, with a note confirming: "📲 Asish gets the box list + a
   no-login receive link on WhatsApp when you dispatch." The picker remembers the last-used
   receiver and preselects them next time ("(last used)"). "✏️ Type name & number…" stays for
   anyone not on the team.
2. **📨 "WhatsApp Asish the receive link" button** on every consignment (any stage, not only
   dispatch): sends the briefing immediately — before dispatch it reads "being loaded for
   you", after dispatch "dispatched", both with the box-by-box list and the /a/{token} no-login
   link. If the consignment has no number yet, it asks once and SAVES the number onto the
   consignment, so the automatic dispatch message later reaches the same person.
3. Plumbing: message composition moved to app/Services/Logistics/AgentNotify.php — ONE
   composer shared by the automatic dispatch hook and the manual button, so wording never
   forks. New POST /papi/consignment-agent-link (owner/manager).

Verified: 9/9 Playwright (picker lists team with numbers, instant fill, note, last-used
memory + preselect, send button posts), zero JS errors. php -l ×4; node --check clean.

Files vs v4.7.1: app/Services/Logistics/AgentNotify.php (NEW) · ShipmentController.php ·
ConsignmentController.php · routes/web.php · consignment.html. No migration.

## v4.7.3 — product import now reads a Description column (gap found 01 Aug evening)

While preparing the helmet-visor import pack for the bike/EPC tenant: the V3 products import
(ZIP with photos AND CSV-only, /papi/products-import) mapped name/category/brand/price/cost/
stock/keywords/sku/unit/pack/moq/sell-mode/galleries/specs — but NOT description, even though
products.description exists, is fillable, feeds the WhatsApp bot's item explanations (first
sentence) and the storefront product page.

Fix: both import branches accept a "Description" column (aliases: description/desc/details/
about; single-line text, capped 2000 chars). Missing column = unchanged behaviour. No
migration.

Files vs v4.7.2: PanelApiController.php only.

## v4.7.4 — "Pls share the details" incident + burst coalescing (live incident #9, 02 Aug 10:22)

RA International (B2B buyer) sent their email address, then "Pls share the details". The bot
(1) narrated the obvious ("It looks like you shared an email address"), then (2) REFUSED —
"I can't share any personal details" — misreading a request for OUR documents as a request
for someone's private data. It also answered each line separately (two replies to one thought).

Three fixes:

1. **Prompt rules (AiBrain):** an email address sent by a customer is being GIVEN to us —
   confirm it is noted, offer the catalogue on WhatsApp now, ask which products they need;
   never lecture about privacy, never narrate. New boundary definition: OUR business info
   (prices, catalogue, delivery, address, TIN, payment details, hours) is always shareable;
   other customers' info, staff numbers, costs/margins, internals never are.

2. **Deterministic email capture (ProcessIncomingMessage):** any inbound email is saved to
   the customer profile (empty field filled; a different address appended to notes), traced
   as email_captured. When a share/send verb + documents noun also appears (either message),
   an **AI Follow-up proposal** is raised — "Email our catalogue / price list to X" — deduped
   over 7 days, visible on the AI Follow-ups screen. The team actually sends the email;
   the bot only promises what will happen.

3. **Burst coalescing (WebhookController + job):** plain-text messages are stamped with an
   arrival sequence and processed after a short window (setting bot_coalesce_seconds,
   default 4 s, 0=off, cap 10, in Settings → WhatsApp & bot). If a newer text from the same
   customer arrived meanwhile, the older job stays silent (still logged + translated +
   read-receipted; trace `coalesced`) — the newest message answers the WHOLE burst once,
   with the earlier lines in its history. Media, location pins and owner messages are never
   delayed. This closes the long-standing "message-coalescing window" roadmap item.

Also in the zip: **BOT-STRESS-TEST.md** — a 20-message operator script (email/details, must-
share vs must-refuse, money discipline, frustration turns, burst behaviour) with expected
results and where to check traces. Run it from a test number after deploying.

Verified: 18/18 harness (real incident strings incl. "Pls share the details"; email regex
edge cases; coalesce decision table; window bounds). php -l ×5 clean. No migration.

Files vs v4.7.3: AiBrain.php · ProcessIncomingMessage.php · WebhookController.php (FIRST
TIME in zip — drift caveat) · SettingsSchema.php · SettingsWriteService.php · BOT-STRESS-TEST.md.
