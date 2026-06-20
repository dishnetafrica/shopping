# ShopBot Modifier Engine — Deploy Runbook

Restaurant accompaniment / add-on engine. Five stages. Deploy in numeric order.
Pipeline per house rules: **ZIP at root level → GitHub `dishnetafrica/shopping` → EasyPanel → restart container.**
`config:clear` does **not** flush opcache, so a **container restart** is required after every code push.

---

## 0. Prerequisites

- These incident hotfixes from this session must already be live (they were deployed during the outage): `thalimenu`, `serviceworker`, `stock`, `stock-nullable`. If any tenant's catalogue still 500s or import still throws a NOT-NULL on `stock`, deploy those first.
- Production DB is **Postgres**. All migrations run with `--force`.
- Get the Spicey Herbs tenant id once and keep it handy:
  ```
  php artisan tinker --execute="echo App\Models\Tenant::where('slug','spiceyherbs')->value('id');"
  ```
  Referred to below as `<SPICEY_ID>`.

---

## 1. Recommended: one combined deploy

All five zips touch mostly different files. The only overlap is `BotBrain.php`, where **Stage 5's copy is the final superset** and must win. If you unzip in order 1→5 into the repo, Stage 5 overwrites the Stage 2b `BotBrain.php` automatically — which is correct.

**Steps:**

1. Unzip stages **1, 2, 2b, 3, 4, 5 in that order** into the repo (root-level paths preserved). Confirm `app/Services/Bot/BotBrain.php` is the Stage 5 version (contains `lineLabel(`).
2. Commit + push to GitHub `dishnetafrica/shopping`. EasyPanel pulls.
3. Run the migration (creates the modifier tables + `order_items.modifiers`):
   ```
   php artisan migrate --force
   ```
4. **Restart the container** (opcache).
5. Targeted clears (never `cache:clear`):
   ```
   php artisan config:clear
   php artisan route:clear
   php artisan view:clear
   php artisan event:clear
   ```
6. Turn Spicey on as a restaurant + seed the accompaniment group (see §3).
7. `php artisan catalogue:flush` (storefront feed cache).
8. Smoke-test (see §4).

---

## 2. Cautious alternative: stage-by-stage

If you'd rather verify each layer:

| # | Zip | Files | After deploy |
|---|-----|-------|--------------|
| 1 | `modifier-engine-stage1.zip` | migration `..._000003_modifier_engine.php`, `ModifierGroup`, `ModifierOption`, `Product` (relation), `ModifierCalc`, `qa/modifier_calc.php` | `php artisan migrate --force` → restart |
| 2 | `modifier-engine-stage2.zip` | `ModifierFlow`, `SeedAccompanimentsCommand`, `qa/modifier_flow.php`, `qa/modifier_bot_sim.php` | restart |
| 2b | `modifier-engine-stage2b.zip` | `BotBrain.php`*, `OrderItem.php` (modifiers cast) | restart |
| 3 | `modifier-engine-stage3.zip` | `StorefrontController.php`, `shop.html` | restart → `catalogue:flush` |
| 4 | `modifier-engine-stage4.zip` | `ModifierGroupResource` + 3 Pages | restart |
| 5 | `modifier-engine-stage5.zip` | `BotBrain.php` (final, **supersedes 2b**), `KitchenBoard.php`, `TrackController.php`, `kitchen-board.blade.php` | restart |

*The Stage 2b `BotBrain.php` is replaced by the Stage 5 `BotBrain.php`. If deploying staged, you can skip the 2b `BotBrain.php` and take only its `OrderItem.php`, then deploy Stage 5's `BotBrain.php`. Simpler is to deploy 2b fully then let 5 overwrite it.

Stage 1 **must** be live before 2b/3/4/5 — they reference the modifier tables/models.

---

## 3. Turn Spicey on + seed accompaniments

Two settings must be true on the Spicey tenant:

```
php artisan tinker
>>> $t = App\Models\Tenant::find(<SPICEY_ID>);
>>> $t->putSetting('currency','USD')->save();        // USD pricing (2-dp)
>>> $t->putSetting('restaurant_mode',true)->save();  // item-drop fix + unlocks Item Options UI
```

Then create the required "Choice of accompaniment" group (Rice / Naan / Chapati, all free) and attach it to every Main Course dish — **either**:

- **CLI:** `php artisan shopbot:seed-accompaniments --tenant=<SPICEY_ID>`
- **Panel:** Seller panel → **Item Options** → **Add accompaniment group** (one click; idempotent).

Finally: `php artisan catalogue:flush`.

> The bot reads modifier groups live (no cache). The **storefront** reads them from the 5-minute catalogue cache, so any time you add/edit a group or attach dishes, run `catalogue:flush`.

---

## 4. Smoke test (Spicey only)

**WhatsApp (bot):**
1. Send `Butter Chicken` → bot asks "choose your accompaniment: 1. Rice 2. Naan 3. Chapati".
2. Reply `naan` → "Added Butter Chicken + Naan", basket shows `Butter Chicken + Naan`.
3. Try `Butter Chicken with rice` → no question, no phantom rice line; basket shows `+ Rice`.
4. `checkout` → place order → confirm the **owner alert** and the **Kitchen Board** ticket both show the accompaniment (ticket as a `↳` sub-line).

**Web:** open `mycloudbss.com/spiceyherbs`:
1. Tap a curry → required accompaniment popup; "Add" disabled until a choice is picked.
2. Pick Naan → cart line reads `Butter Chicken + Naan`.
3. Checkout → order appears on the Kitchen Board **with items** and the `↳` sub-line, and the customer track page shows the same.

**Grocery safety check:** open Family Shoppers / Pal's — confirm no "Item Options" menu, and ordering behaves exactly as before. (Expected: byte-identical — the engine is gated behind "tenant has active modifier groups".)

---

## 5. Rollback / kill switch

The engine is a **no-op for any tenant with no active modifier groups**. To disable it instantly for Spicey without redeploying code:

```
php artisan tinker
>>> App\Models\ModifierGroup::where('tenant_id',<SPICEY_ID>)->update(['active'=>false]);
>>> exit
php artisan catalogue:flush
```

With no active groups, the bot stops asking, the storefront stops sending modifiers, and ordering reverts to plain dish-by-name. The `order_items.modifiers` column and all code stay in place (harmless). Re-enable by setting `active=true` again.

---

## 6. Go-live gate (not code — your call)

Per your standing rule before a tenant goes truly live: seed/confirm the accompaniment group, run the smoke tests above, then **20–30 real orders + 2–3 days internal use** on Spicey before promoting it.
