# Auto Parts vertical — enable the business type in Operator

This adds **"Auto Parts / EPC catalogue"** to the Business type dropdown in the
Operator/admin (mycloudbss.com/admin/tenants/…/edit).

## What changed (ONE file only)

`app/Support/Vertical.php` — a **complete drop-in replacement**. The change is
**additive and safe**: it only ADDS the new `auto_parts` type. Nothing is removed
or modified — Grocery, Restaurant, Snacks, Manufacturer and Solar behave exactly
as before, and no existing business is affected until an admin explicitly selects
the new type for a specific tenant.

Exactly 4 additions vs the old file:
- new constant  `AUTO_PARTS = 'auto_parts'`
- added to      `ALL`   (so it's a valid vertical)
- added to      `riders` and `pos` rows (auto-parts gets riders + POS like a shop)
- added to      `LABELS` → shows as "Auto Parts / EPC catalogue" in the dropdown

## Upload on GitHub

Option A — replace the file:
  In your repo, open `app/Support/Vertical.php` → Edit (pencil) → paste the new
  contents → Commit.

Option B — upload preserving the path:
  Repo home → Add file → Upload files → drag the `app` folder from this zip →
  GitHub will place it at `app/Support/Vertical.php` and offer to commit the
  replacement → Commit.

## Deploy on the server

    git pull
    php artisan optimize:clear
    # restart the app (however you restart it — supervisor/queue/octane/php-fpm)

## Use it

  Operator login → Businesses → Edit EA Auto Part (tenant 20) →
  Business type → select **Auto Parts / EPC catalogue** → Save.

Only that tenant becomes an auto-parts business. You can switch it back anytime.

## Note (storefront)

This step makes the OPTION selectable and turns on riders + POS. To make the
actual EPC parts storefront render at the tenant's web address, a second step is
needed (a `StorefrontController` branch + an `autoparts.html` template). Ask and
I'll prepare that as a matching drop-in once you're ready.
