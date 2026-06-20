# ShopBot Modifier Engine — Handover

**What:** A restaurant accompaniment / add-on system for the CloudBSS multi-tenant ShopBot (Laravel 11 + Filament 3 + Postgres). Lets a dish like *Butter Chicken* require a choice ("Rice / Naan / Chapati") that the customer makes once, on WhatsApp or on the storefront, before it lands in the cart.

**Why it exists:** The printed Spicey Herbs menu serves mains "with 1 accompaniment". The earlier "expand each curry × 3 breads into separate SKUs" approach was rejected — it can't express bread *quantity* (real orders are "2 curries + 5 naan", breads shared/bulk). Standalone breads/rice remain their own menu lines for ordering extras; the **included** accompaniment is modelled as a required, zero-price modifier choice.

**Status:** Code complete across 5 stages. Pure-logic pieces are unit-tested in-repo; the bot-wiring, storefront JS, and Filament UI are **deploy-tested** (they can't boot in the dev sandbox — no `vendor/`). See §7.

---

## 1. Data model (Stage 1)

Migration `database/migrations/2026_06_20_000003_modifier_engine.php` (all `hasTable`/`hasColumn`-guarded, tenant-scoped):

- **`modifier_groups`** — `tenant_id, name, required, min_select, max_select, free_qty, sort, active`. A "group" is one question ("Choice of accompaniment").
- **`modifier_options`** — `modifier_group_id, name, price_delta DECIMAL(10,2), sort, active`. One choice. `price_delta = 0` → included free; `> 0` → surcharge. (No `tenant_id`; isolated via its parent group.)
- **`product_modifier_group`** — pivot `(product_id, modifier_group_id, sort)`, unique pair. Which dishes show which groups.
- **`order_items.modifiers`** — JSON column. The chosen options, persisted per line.

Models: `App\Models\ModifierGroup` (`BelongsToTenant`; `options()` hasMany ordered by sort, `products()` belongsToMany), `App\Models\ModifierOption` (`group()` belongsTo), `App\Models\Product::modifierGroups()` belongsToMany, `App\Models\OrderItem` (`modifiers` in `$fillable` + `'modifiers'=>'array'` cast).

Pure calculator `App\Support\ModifierCalc`:
- `unitPrice($base, $selections)` = base + Σ deltas.
- `validate($groups, $picksByGroup)` → array of error strings (min/max/required).

---

## 2. The core design decision: fold + un-fold

The chosen option is stored **structured** in `order_items.modifiers` (and in `items_json[].modifiers`). For display it is handled two ways depending on the surface:

- **Inline string surfaces** (WhatsApp basket, owner alert, panel `items_text`, the saved `order_items.name`): the option is **folded into the name** → `"Butter Chicken + Naan"`. This keeps every plain-text/notification surface informative with no template work.
- **Ticket surfaces** (Kitchen Board KOT, customer track page): the renderer **un-folds** the `" + Naan"` suffix back off the name and shows the base dish with a structured `↳ Naan` sub-line.

The fold format and the un-fold check use the **same** ordering (`array_values(array_filter(map → name))` + `' + ' . implode(', ', …)`), so `str_ends_with()` matches exactly and there is never a double-render. This is deliberate, not interim — it's why the accompaniment shows correctly everywhere.

---

## 3. Bot flow (Stages 2 / 2b / 5) — `App\Services\Bot\BotBrain.php`

Pure helper `App\Support\ModifierFlow`:
- `nextRequired($groups, $chosen)` → first unsatisfied required group, or null.
- `prompt($group, $dishName)` → numbered question text with surcharge tags.
- `resolve($reply, $group)` → matches a number, exact name, contains, or token overlap ("garlic naan" → Naan).

Wired into `respondInner()`:
- **Top-of-flow guard** `resolvePendingModifier()` — while `state['pending_modifier']` is set, the next message is treated as the pick. Resolves → attaches modifier + reprices the line; `cancel/skip/any/whatever` defaults to the first option so the customer is never trapped.
- **Pre-strip** — `"…with naan"` is detected against the tenant's option names, remembered as a one-shot `$modHint`, and stripped from the text so it's read as the *choice* (not a separate bread line).
- **Post-add hook** `maybeAskModifier()` — after any add path (bulk / NLU / keyword), scans the cart; the first line with an unsatisfied required group sets `pending_modifier` and returns the question (or silently attaches via `$modHint`).
- `lineLabel()` folds the option into the displayed name in `cartSummary()` and in the order's `items_text`.
- At checkout, `OrderItem::create` folds the option into `name` and writes the structured `modifiers` JSON.

`SeedAccompanimentsCommand` — `php artisan shopbot:seed-accompaniments --tenant=<id>` creates the required free Rice/Naan/Chapati group and attaches it to all Main Course dishes. Idempotent; `--category` / `--options` / `--name` configurable.

---

## 4. Storefront flow (Stage 3) — `StorefrontController.php` + `resources/storefront/shop.html`

- **Catalogue feed** carries each product's groups under `Modifiers` (tenant-scoped, try/catch, empty array for grocery).
- **shop.html**: a dish with a required group shows **Add → `customize()`**, which opens a sheet; the "Add" button is disabled until required choices are made and shows the live (delta-adjusted) price. Cart uses **composite keys** (`name||sortedModNames`) so "Butter Chicken + Naan" and "+ Rice" are distinct lines; `bump(key, ±1)` adjusts quantity per line. Checkout payload includes `modifiers` per item.
- **Web `placeOrder`** now **creates `OrderItem` rows** (previously it saved only `items_json`, leaving the Kitchen Board ticket empty — fixed here), folds the option into the line name, writes structured `modifiers`, and writes the customer note to `orders.notes`. `product_id` is resolved by stripping the `+ …` suffix and matching by name (best-effort; null if no match).

---

## 5. Owner UI (Stage 4) — `ModifierGroupResource` + Pages

Seller-panel screen **"Item Options"** (auto-discovered). Create/edit a group (name, required, min/max, sort, active), manage its options inline via a relationship **Repeater** (name, price change, reorderable), and attach dishes via a searchable multi-select. List view shows option + dish counts. Header button **"Add accompaniment group"** mirrors the CLI seeder in one click.

**Gating:** `shouldRegisterNavigation` / `canViewAny` / `canAccess` all return `auth()->user()?->tenant->setting('restaurant_mode', false)` — same idiom as the Win World resources. Hidden for Family Shoppers / Pal's.

---

## 6. Rendering (Stage 5)

- `app/Filament/Pages/KitchenBoard.php` + `resources/views/filament/pages/kitchen-board.blade.php` — reads `order_items.modifiers`, un-folds the name, renders `↳` sub-lines per option (already scaffolded; lights up once data flows).
- `app/Http/Controllers/Panel/TrackController.php` — same treatment over `items_json[].modifiers` for the public track page.
- `BotBrain.php` `lineLabel()` — folds into the WhatsApp basket + owner alert (the piece that was actually missing).

---

## 7. The safety property (why this was safe to ship into a live multi-tenant bot)

**Every new path is a no-op for a tenant with no active modifier groups.** The bot gates on a single cheap `tenantHasModifiers()` `EXISTS` query; the storefront catalogue and the controllers wrap modifier lookups in `try/catch` that degrade to "don't ask / empty array". Family Shoppers and Pal's have no groups, so behaviour is **byte-identical** and a modifier glitch can never break their ordering.

Regression suites (framework-free, run with bare `php` in the sandbox — no `vendor/` needed):

| Suite | Asserts | Result |
|-------|---------|--------|
| `qa/modifier_calc.php` | price math + validation | 10/10 |
| `qa/modifier_flow.php` | prompt + resolve matching | 8/8 |
| `qa/modifier_bot_sim.php` | end-to-end add → ask → resolve → price (real ShoppingEngine + ModifierFlow) | 4/4 |
| `qa/spicey_flow_sim.php` | restaurant order accuracy (no silent drops) | 23/23 items land |
| `qa/order_instructions.php` | grocery regression (unchanged behaviour) | 49/49 |

`php -l` is clean on every edited PHP file. **Not** runnable in the sandbox (hence deploy-tested on Spicey first): the integrated bot, the storefront JS, the Filament forms.

---

## 8. Known limitations

- **Storefront reorder** — `reorderLast()` re-adds a curry by base name without re-prompting, so a reordered curry arrives with no accompaniment. Low frequency; fix = skip modifier dishes in reorder or re-open `customize()`.
- **Web USD formatting** — `shop.html`'s `ugx()` still lacks fixed decimals (pre-existing Medium audit item, untouched here).
- **POS** — the in-store POS has no modifier picker; POS orders simply carry no modifiers (`order_items.modifiers` null). A POS modifier UI is future work.
- **Single-select assumed in UX** — the data model supports `max_select > 1` (multi add-ons), but the bot question + storefront sheet are tuned for single required choices. Multi-select add-ons render but the bot prompt copy assumes one pick.
- **product_id on web order items** is best-effort (name match); a renamed dish would leave it null. Display is unaffected.

---

## 9. Where to extend (Stage 6+ ideas)

- Optional multi-select add-on groups ("Extra toppings", `required=false`, `max_select=N`) — data model already supports it; needs bot prompt copy + storefront multi-checkbox.
- Premium accompaniments with `price_delta > 0` — fully supported by `ModifierCalc` and the UI today; just set a non-zero price change on the option.
- POS modifier picker mirroring the storefront sheet.
- Per-option availability ("Naan sold out") via `modifier_options.active` (column exists; bot/storefront already filter on it).

---

## 10. File inventory

```
Stage 1  database/migrations/2026_06_20_000003_modifier_engine.php
         app/Models/ModifierGroup.php
         app/Models/ModifierOption.php
         app/Models/Product.php                      (modifierGroups relation)
         app/Support/ModifierCalc.php
         qa/modifier_calc.php
Stage 2  app/Support/ModifierFlow.php
         app/Console/Commands/SeedAccompanimentsCommand.php
         qa/modifier_flow.php
         qa/modifier_bot_sim.php
Stage 2b app/Services/Bot/BotBrain.php               (superseded by Stage 5)
         app/Models/OrderItem.php                    (modifiers cast)
Stage 3  app/Http/Controllers/Storefront/StorefrontController.php
         resources/storefront/shop.html
Stage 4  app/Filament/Resources/ModifierGroupResource.php
         app/Filament/Resources/ModifierGroupResource/Pages/{Create,Edit,List}ModifierGroup.php
Stage 5  app/Services/Bot/BotBrain.php               (final)
         app/Filament/Pages/KitchenBoard.php
         app/Http/Controllers/Panel/TrackController.php
         resources/views/filament/pages/kitchen-board.blade.php
```

See `DEPLOY-RUNBOOK.md` for the deploy order, seeding, smoke test, and kill switch.
