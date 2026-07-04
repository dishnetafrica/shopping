# CloudBSS — POS custom pricing + quotation USD fix

Two changed files. Upload both over your repo, commit, EasyPanel → Rebuild.
Rollback = revert the commit.

## Files
1. `resources/panel/seller.html`
   - **Custom price is now editable inline on BOTH surfaces:**
     - **POS cart** — each line has an editable price box ("<CUR> [ 33.00 ] each"). Type
       a custom price before placing the sale or sending the quote.
     - **Order / invoice editor** — the price column is now an input, next to quantity.
   - The box edits **that line only, for that customer/sale**. Your product price list
     (master price) is never touched. Leave it as-is to use the catalogue price.
   - Values use the panel's own currency helpers, so the number you type is the number
     stored and shown (no double-conversion) when the shop runs in its native currency.

2. `app/Http/Controllers/Panel/PanelApiController.php`
   - `quotationConvert()` now respects **native** currency basis, so converting a USD
     quotation to an order no longer multiplies (inflates) or — via the old divide — zeroes
     the value. No change for UGX-base (converted) shops.

## Required setting (per USD shop) — do this once
Your prices (33, 98 …) are in USD. Put the shop on native basis so nothing gets converted:

    php artisan tinker
    >>> $t = App\Models\Tenant::find(17);
    >>> $t->putSetting('currency_basis','native'); $t->save();

This also flips the panel to native display (`SHOP_CUR.plain=true`) → prices show as USD
as-entered, and the custom-price boxes store exactly what you type.

## POS note (important)
In the POS page, leave the **Zone** selector on the default (not "South Sudan · Juba (USD)").
That zone toggle is the old divide-by-rate path and will fight native USD pricing. With the
shop on native USD, the panel currency (USD) already shows and stores everything correctly,
and the custom-price box is exact.

## How to give a customer a different price
- **From POS:** add the item → change the price box on that line → Place sale (or Send quote).
- **On an existing order:** open it → edit the price box on the line → save.
- **On a quotation:** open it in Quotations → edit the line price → it recalculates and saves
  (this already worked; the boxes above just let you do it up-front too).

## Verify after deploy
1. POS: add ALUMINIUM FOIL → shows USD 33 each → change to 30 → line + total follow.
2. Place sale / convert quote → order shows the custom total, not 0.00.
3. Product list still shows 33 (master price untouched).
