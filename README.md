# CloudBSS — Phase 2: Default Product Strategy (deploy bundle)

Reduces clarification friction: an owner-set **default SKU per term** so a generic
"Rice" adds the default instead of asking — while preserving order accuracy.

## Deploy (drop into repo at these paths)
```
app/Services/Bot/CatalogueMatcher.php     EDIT  + normSize/skuSize, fuzzy first-char guard, token cache
app/Services/Bot/ShoppingParser.php       EDIT  + size vs count parsing, newline lists
app/Services/Bot/ShoppingEngine.php       EDIT  + size/default/auto resolution, size-hint-once
app/Services/Bot/ClarificationFlow.php    (unchanged from Phase 1)
app/Services/Bot/BotBrain.php             EDIT  loads tenant defaults + strategy into the engine
app/Models/ProductDefault.php             NEW
app/Filament/Resources/ProductDefaultResource.php (+ Pages/)   NEW  "Smart Defaults" admin
database/migrations/2026_06_14_000001_create_product_defaults_table.php  NEW
```
Run after deploy: `php artisan migrate` then `php artisan test`.

## Behaviour (resolution precedence, multiple SKUs)
1. stated size matches one SKU  -> that SKU (size wins)
2. stated size matches 0 / >1   -> CLARIFY (size conflict)
3. no size + valid owner default -> add default
4. no size + strategy=explicit_then_auto -> auto-pick (cheapest/smallest for now)
5. otherwise                    -> CLARIFY
Single SKU always resolves directly ("2kg sugar" with a 1kg SKU = qty 2).
"show me / which" always lists all variants (browse overrides default).

## Settings
- `tenants.settings.default_strategy` = off | explicit | explicit_then_auto  (platform default: **explicit**)
- Admin → **Smart Defaults**: set a default SKU per customer word.

## Tests (all green here)
- `qa/default_strategy_suite.php` — 18/18 (defaults, size, conflict, hint-once, accuracy)
- `qa/conversational_commerce_suite.php` — Phase-1 63/63 (no regression)
- `qa/performance_scale_suite.php` — 18/18
- `tests/Unit/ProductDefaultStrategyTest.php` — 11 tests (php artisan test)
- `tests/Unit/ShoppingEngineTest.php` — 11 tests

## Notes
- Filament resource lints clean but couldn't be run here (no app runtime) —
  verify against your Filament v3 on staging.
- `qa/ROADMAP.md` lists deferred items: auto-pick ranking, Store Learning,
  Customer Learning (per-customer variant), guided defaults page.
- `qa/Default-Product-Strategy-Design.md` is the approved design.
