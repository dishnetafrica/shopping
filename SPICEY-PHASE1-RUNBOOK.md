# Spicey Herbs — Phase 1 Deploy Runbook

Flat-menu restaurant pilot. ZIP extracts at repo root (paths preserved), then your normal GitHub → EasyPanel deploy.

## 1. Apply the code
Extract `spicey-phase1.zip` at the repo root (overwrites the listed files), commit, push to `dishnetafrica/shopping`, deploy in EasyPanel.

## 2. Migrate
```
php artisan migrate
```
Adds: `orders.notes`, `orders.accepted_at`, `orders.ready_at`, `orders.rejected_reason`, `order_items.notes`, `products.description`. (All `hasColumn`-guarded — safe to re-run.)

## 3. Set Spicey Herbs to USD + restaurant mode
Panel → **Settings** → Currency = `USD`.
Then enable restaurant ordering (auto-add best match, never drop a line):
```
$t = Tenant::find(<id>); $t->putSetting('currency','USD'); $t->putSetting('restaurant_mode', true); $t->save();
```
USD now shows cents everywhere; `restaurant_mode` makes the bot add the best dish match immediately instead of asking the customer to pick a number (which is what was dropping items). Grocery tenants leave `restaurant_mode` off — their behaviour is unchanged.

## 4. Import the menu
Panel → **Products** → **Import CSV** → upload `spicey-herbs-menu.csv`, **Replace** on.
122 dishes load with descriptions. Dual-price items are pre-split (e.g. *Tandoori Chicken (Half)* / *(Full)*).
Then refresh the cached catalogue:
```
php artisan catalogue:flush
```

## 5. Before you go live (menu gaps to close)
- **Beverages**: the printed menu has none. The CSV has 4 placeholders (`active=FALSE`, price 0). Set real prices and activate, or customers asking for "Coke" get a miss.
- **Abbreviations** (optional): add keywords like `ctm` to *Chicken Tikka Masala* so shorthand matches.

## 6. Smoke test (this is the real integration test)
1. Open the **Kitchen** page (sidebar shows a red badge with the New count).
2. From a test WhatsApp number, send: `Chicken Biryani extra spicy`, then `2 Garlic Naan`, then `checkout`, then a delivery area.
3. The ticket should appear in **New** with the `extra spicy` note under the biryani line.
4. Tap **Accepted → Preparing → Ready → Dispatched → Delivered**. The customer should get a WhatsApp message at each stage.
5. Try **Reject** on a New ticket → customer gets the rejection message.

## 7. Real match-rate number (optional)
```
php artisan bot:replay qa/spicey-replay-tests.csv --tenant=<spicey_id>
```
Gives the true end-to-end miss-rate on the 7 conversation tests (the 95% figure in the report is the matcher layer measured offline).

## Rollback
`php artisan migrate:rollback` drops the new columns; revert the commit to restore prior files. No data loss for existing tenants (all changes are additive / currency-defaulted to UGX).
