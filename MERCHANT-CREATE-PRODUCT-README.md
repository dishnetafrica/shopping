# Merchant WhatsApp — Create Product (owner texts the bot, no panel login)

Owner sends a normal WhatsApp message to the shop's AI bot number. If a price line
names something **not** in the catalogue, the bot now proposes **creating** it (instead
of dropping it). Owner replies **YES** → product is created and remembered. Existing
behaviour (price updates, sold-out, specials, menu, hours, notices, undo) is unchanged.

## What changed
- **NEW** `app/Services/Bot/Merchant/CategoryInferer.php` — guesses a category for a new
  product from its name, reusing the tenant's own category spelling when possible.
- **NEW** `app/Services/Bot/Merchant/MerchantProductMatcher.php` — decides "typo of an
  existing product (→ UPDATE)" vs "genuinely new (→ CREATE)" via normalized Levenshtein.
- **EDIT** `MerchantAssistant.php` — a price line with no exact match now routes to
  UPDATE-typo or CREATE-new instead of "couldn't find".
- **EDIT** `MerchantChangeApplier.php` — applies `create_product` (Product::create, tenant
  stamped, weight/flat pricing) and, on undo, deletes the rows it created.
- **EDIT** `MerchantSummary.php` — renders a "🆕 NEW: …" confirmation line.
- **NEW** `tests/Unit/CreateProductMerchantTest.php` — pure coverage (12 assertions).

## Decision logic (as agreed)
- Exact/ILIKE match  → UPDATE existing price.
- Close fuzzy match (edit distance ≤ 2, ≤ 1/3 of chars) → UPDATE that product, flagged
  in the summary as a typo ("matched existing 'Fafda' (you wrote 'Fafada')").
- No close match → CREATE new product, category auto-inferred and shown.
- Owner always confirms with YES; nothing is silent. `undo last change` reverses the
  whole set, including deleting any product it created.

## Owner messages (examples)
- `Banana Crisps Salted 1kg 35000`  → 🆕 NEW (Snacks & Crisps), YES creates it.
- `Tam Tam 1kg 30000`               → 🆕 NEW.
- `Fafada 1kg 35000`                → matches existing **Fafda**, proposes UPDATE.
- `fafda sold out` / `today special jalebi` / `open 10 close 19` → unchanged.
- `undo last change`                → reverts the last confirmed set (deletes new products).

## One-time setup per shop (Pal's)
The owner's WhatsApp number must be authorized for the merchant lane. Any one of:
- a User with role `owner`/`manager` and that `phone`, **or**
- tenant setting `owner_alert_phone`, **or**
- tenant setting `merchant_admins` (array).
Numbers compare on the last 12 digits, so +256.../0256.../spaces all match.

## Deploy
No migration, no config, no new dependencies (reuses `merchant_change_requests.payload_json`).
1. Unzip into the repo root (overwrites the 3 edited files, adds 2 services + 1 test).
2. `php -l` is clean on all files; `php artisan test --filter=CreateProductMerchantTest`.
3. Commit & push → EasyPanel (project **evo**, service **shopping**) redeploys.

## Notes / future
- New products are created **without an image** — they flow into the same image pipeline
  we've been filling (brand zips / Jumia search-zips).
- Category is a best-guess shown in the summary; owner can reply NO and re-send, or fix it
  later in the panel. A "move <product> to <category>" command can be added later if wanted.
