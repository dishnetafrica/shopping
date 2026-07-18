# STOREFRONT-ARABIC-DEPLOY.md — familyshoppers.net speaks Arabic

## Tier 1 — Arabic UI mode
A **ع button** in the search bar (next to 🎤 📷). Tap → the storefront flips:
- Full **RTL layout** (flex/grid mirror automatically; inputs right-aligned)
- ~30 interface strings in Arabic: search placeholder, nav (الرئيسية/الفئات/السلة),
  cart sheet, **أضف إلى السلة** (Add to cart), **الدفع** (Checkout), **إتمام الطلب**
  (Place order), delivery bar, footer
- **Every new visitor lands in ENGLISH** (no browser auto-detect); Arabic is opt-in via ع, and the choice is remembered on that device
- Product names stay English on purpose (brands/SKUs — customers know them)
- Exact-match dictionary only: anything not translated stays English rather than
  guessing — long dynamic sentences (delivery fee lines) are English in v1

## Tier 2 — Arabic search
`php artisan products:arabic-keywords 17` — AI appends 3–6 Arabic search terms
(MSA + Juba colloquial) to each product's keywords. The search already matches
keywords, so afterwards **زيت finds your oils, سكر finds sugar** — exactly what the
customer in your chat screenshot was typing. Idempotent (Arabic-bearing products
skipped), `--dry` previews, `--limit=50` for batches, clears the catalogue caches.
~265 products ≈ a few cents, 2–4 minutes.

## Files (4)
resources/storefront/shop.html (I18N runtime + toggle + RTL CSS + T() wraps — BOTH
script blocks node-validated) · ChatTranslator (+arabicKeywords) ·
ArabicKeywordsCommand (NEW) · ArabicStorefrontTest (2 tests)

## Deploy + the click-test (do this — it's your selling page)
1. Upload → Rebuild → `php artisan test --filter=ArabicStorefront`
2. `php artisan products:arabic-keywords 17 --dry` → then without --dry
3. familyshoppers.net → hard refresh → **English first**: search, add to cart,
   checkout to the name/phone form (don't submit) — everything as before
4. Tap **ع** → page reloads RTL Arabic → same walk: search **زيت** (your oils must
   appear), add to cart, open cart (سلة المشتريات), checkout button reads الدفع
5. Tap **EN** → back to English

Anything odd in step 3 (English regression) → tell me immediately — that's the one
thing this build must never break.
