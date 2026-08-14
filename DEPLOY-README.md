# Deploy this — one upload, everything outstanding

Upload the folders in this zip to GitHub (they keep their paths, so they overwrite in place), then
deploy on EasyPanel. **This supersedes every zip I sent you earlier today** — `shop.html` in here is
newer than the one in `family-shoppers-v5.05.1.zip`, so deploying the old one now would undo the
fix that makes your solar products clickable.

---

## What is in it

### 1. The launch-audit fixes (v5.05) — Family Shoppers

| File | What it fixes |
|---|---|
| `app/Http/Controllers/Storefront/StorefrontController.php` | **The customer can no longer set the price.** Every cart line is re-read from `products`; the posted `price` and `total` decide nothing. Also: one order per submission (idempotency), order + line items in one transaction, MOQ enforced server-side, availability no longer published from `products.stock`, branches read from the `branches` table |
| `app/Http/Controllers/Storefront/StorefrontProductController.php` | `/product/{id}` no longer serves another shop's product; price precedence matches the catalogue |
| `app/Services/WhatsApp/EvolutionGateway.php`, `CloudApiGateway.php` | A send the API **rejects** is no longer recorded as delivered |
| `app/Jobs/NotifyOwnerNewOrder.php`, `NotifyCustomerOrderReceived.php` | …and a failed send keeps its ledger row instead of deleting the evidence |
| `app/Models/OrderNotificationSend.php` | `markFailed()`; `claim()` can re-take a stale unsent row so retry still works |
| `app/Filament/Auth/OtpLogin.php` | Login throttled — 5 attempts per IP **and** per account |
| `app/Http/Middleware/RequireSameOrigin.php` | Cross-site writes to `papi/*` refused. **Corrected** since the first version, which broke the CI suite |
| `app/Services/Storefront/WholesaleHome.php`, `app/Services/Bot/AiBrain.php` | No more unconditional "FREE DELIVERY" the shop never configured |
| `routes/web.php` | `throttle:3,60` on the quotation share endpoint; the same-origin gate on `papi` |
| `database/migrations/2026_08_05_000001_unique_quote_no_on_quotations.php` | `HAVING "n"` → `HAVING COUNT(*)`; the de-duplication silently did nothing on PostgreSQL |
| `app/Http/Controllers/Panel/AccountingControlsController.php` | Same bug, worse: **"Ledger journals balanced" was reporting a pass without ever running** |
| `tests/Feature/Security/RequireSameOriginTest.php` | 9 tests pinning the middleware's decisions |

### 2. The GSL solar fixes

| File | What it fixes |
|---|---|
| `resources/storefront/shop.html` | **Quote-only products open their page.** `poaCard` built a bare `<div>` where every other card is a real `<a href>` — so with every GSL price blank, all 32 descriptions and photo galleries were unreachable. Plus the v5.05 cart fixes (`+` no longer deletes a line, Add opens at MOQ) |
| `app/Services/Catalogue/ProductImporter.php` | Truncates `unit_size`/`origin_country` — one 46-character Size cell was killing the whole import with a 500. Also fills `gallery_1..3`, which no importer could ever do |


> **The 72 GSL product photos are NOT in this zip** — they are 5.7 MB of JPEG and this is a code
> deploy. They ship separately as `gsl-photos-only.zip` (`public/img/gsl/`). Until you upload those,
> the solar product images stay 404 and every card shows its category icon instead. Everything else
> in here works without them.

### 3. New — record a physical count

`app/Console/Commands/StockCountCommand.php`

---

## After deploying

**1. Re-import the GSL catalogue** (admin → Businesses → GSL → Import products), with *Replace the
whole catalogue* **OFF**. Use `gsl-catalogue-WITH-PHOTOS.csv` — it carries the image paths that
match the photos now in `public/img/gsl/`.

**2. Record Ashish's count.** Dry run first — it writes nothing without `--write`:

```bash
php artisan stock:count --tenant=17 --by="Ashish Kareliya" \
  "cling film=115" "aluminium foil=53" "food container=34" "scrub sponge=15" "wheat flour=3"
```

It prints purchased, sold, what the documents say, the count and the variance. Two lines will come
back **refused rather than guessed**: "scrub sponge" matches no product, and "wheat flour" matches
three. The output gives you the exact `"#id=qty"` form to re-run with once Ashish says which.

When the numbers look right:

```bash
php artisan stock:count --tenant=17 --by="Ashish Kareliya" "cling film=115" ... --write
```

The count lands as a `trust_count` movement plus `products.stock` stamped with today's date and
"count" as its source. **No purchase, invoice or order is touched**, so Juba Stock goes on reporting
Purchased − Sold. When the two disagree that is the finding, not something to smooth over.

**3. Run the nine checks** (`ops/runtime-checks.php` in this zip, so no pasting):

```bash
php artisan tinker --execute='require base_path("ops/runtime-checks.php");'
```

**Check 5 is the one that can still stop the launch**: if no queue worker is running, no WhatsApp
notification has ever been sent.

---

## What is NOT tested

There is no PHPUnit in my environment — Packagist is blocked — so `php -l` and reading are the
ceiling for the PHP. What *is* tested: the storefront JavaScript, by harnesses that cut each
function out of the shipped `shop.html` at run time and exercise it (31 assertions across the
customer journey), and the order-pricing rules (28 assertions). `stock:count` has **not been run**
anywhere. Its dry run is the safety: it writes nothing until you add `--write`.
