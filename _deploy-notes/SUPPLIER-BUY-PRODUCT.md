# Supplier screen — "Buy product" (product-linked purchase)

Adds a **Buy product** button on each supplier's page. Instead of only recording a money bill with a
free-text description, you can now **pick a product from your catalogue**, enter the **quantity** and
**cost per unit**, choose **credit or cash**, and save. In one step it:

- books the cost to **this supplier** (updates their balance owed, on credit or cash),
- **links the purchase to the product** (so purchases are no longer free text — the root fix for stock),
- **updates the product's cost price** automatically.

**Multiple items in one go:** the form has slots for **up to 4 products** (Product 2/3/4 are optional).
Fill in as many as you bought from that supplier and hit Save once — each becomes its own product-linked
line on the same purchase. Leave the extra slots blank to buy just one.

The old **Record purchase** (money-only bill) button stays for odd costs you don't want in the catalogue
(packaging charge, a transport fee, etc.).

## Files in this package
- `app/Capability/Object/SupplierCapability.php` — adds the **Buy product** action (product pick-list →
  maps the chosen name to its product id; supplier is pre-filled from the page).
- `app/Http/Controllers/Panel/OrderCostController.php` — the existing `POST /papi/purchases` endpoint now
  also accepts a **single flat line** (`product_id` + `qty` + `unit_cost`), so the one-product form works.
  Existing multi-line callers are unchanged.

## Deploy
```
php artisan config:clear && php artisan route:clear && php artisan view:clear
php artisan queue:restart
```
No migration (uses existing `order_costs.product_id / qty / unit_cost` columns).

## Test once
1. Open a supplier → click **Buy product**.
2. Pick a real product, enter qty (e.g. 100) and cost per unit, choose **credit**, save.
3. Check: the supplier's **balance owed** goes up by qty × cost; the purchase appears under the
   supplier's **Purchases** tab; and the product's cost updates. On **cash**, it comes out of the
   chosen person's float instead of adding to the balance.

## Note on stock
This makes purchases **product-linked** (fixing the naming/reporting gap — "purchased vs sold" now has
clean product data). Juba **on-hand stock** still comes through the receive step in Juba Stock /
Consignments, because buying in Uganda isn't the same as goods landing in Juba. If you later want a
"buy → auto-add to Juba Stock on receive" shortcut from here too, say the word.

## Rollback
Remove the `$buyProduct` action from `$header`/`$quick` in `SupplierCapability.php` (or restore the two
files). Read-through change; nothing else is affected.
