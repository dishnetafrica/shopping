# CloudBSS — Default Product Strategy (DESIGN, not implemented)

Goal: cut clarification friction. When a customer sends a generic word ("Rice")
and the store stocks several SKUs, use the owner's chosen **default SKU** instead
of asking every time. Clarify only when there is no default, a stated size
conflicts, or matches are genuinely tied.

Status: **design proposal for Phase 2.** Nothing here is built yet.

---

## 1. Core concept — the "group term"

The thing a default attaches to is not a category and not a single SKU — it's the
**canonical word the customer typed**, after the matcher's normalisation
(lowercased, synonym-mapped, stop/units removed).

- "Rice", "rice", "chawal" → canonical term **`rice`**
- "Sugar", "Sakar", "Khand" → **`sugar`**
- "Tel", "Cooking Oil", "oil" → **`oil`**

So a default is a mapping **(tenant, term) → product**. Because the term is the
*post-synonym* canonical, one default for `sugar` automatically covers "sakar"
and "khand" too. Setting it at the term level (not the SKU level) means adding a
new SKU later doesn't disturb the default.

A "generic request" = the customer named the group but **not** a size/variant.
A default only ever applies to a generic request.

---

## 2. Database design

### 2.1 New table: `product_defaults`

One row per (tenant, term). The authoritative source of truth.

```
product_defaults
------------------------------------------------------------------
id              bigint  PK
tenant_id       bigint  FK -> tenants(id)  [indexed, cascade on delete]
term            varchar(64)   -- canonical, lowercased, synonym-mapped (e.g. 'rice')
product_id      bigint  FK -> products(id) [the default SKU]
active          boolean default true
source          varchar(16)   -- 'owner' | 'auto'  (who set it; for analytics)
created_by      bigint  null FK -> users(id)
created_at, updated_at  timestamps

UNIQUE (tenant_id, term)            -- one default per term per store
INDEX  (tenant_id, active)
```

Notes:
- `term` is stored **already canonicalised** so bot lookup is a direct key hit
  (no per-request normalisation mismatch). The admin UI canonicalises on save
  using the same `CatalogueMatcher::tokens()` rule.
- `ON DELETE` of the product: set the row inactive (soft) rather than hard-delete,
  so the UI can surface "broken default" instead of silently reverting to asking.

### 2.2 Optional column on `products` (denormalised convenience, not required)

```
products.default_rank   smallint null
```
Used only by the optional **auto-pick** mode (§5) to rank SKUs within a group
when the owner hasn't set an explicit default (e.g. lowest rank = preferred).
Pure convenience; `product_defaults` remains authoritative for explicit choices.

### 2.3 Tenant setting (existing settings JSON or a column)

```
tenants.settings->'default_strategy'   enum: 'off' | 'explicit' | 'explicit_then_auto'
                                       default 'explicit'
```
- `off` — never auto-resolve; always clarify (today's behaviour).
- `explicit` — use owner-set defaults; clarify when none. **(recommended default)**
- `explicit_then_auto` — use owner default; if none, auto-pick by `default_rank`
  (or cheapest/smallest) instead of clarifying.

### 2.4 Migration sketch (design only — do not run yet)

```php
Schema::create('product_defaults', function (Blueprint $t) {
    $t->id();
    $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $t->string('term', 64);
    $t->foreignId('product_id')->constrained()->cascadeOnDelete();
    $t->boolean('active')->default(true);
    $t->string('source', 16)->default('owner');
    $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
    $t->timestamps();
    $t->unique(['tenant_id', 'term']);
    $t->index(['tenant_id', 'active']);
});
// products.default_rank added in a separate, optional migration if auto-pick ships.
```

### 2.5 Model relationships

- `Tenant hasMany ProductDefault`
- `ProductDefault belongsTo Product`, `belongsTo Tenant` (uses `BelongsToTenant`)
- `Product hasMany ProductDefault` (a SKU can be the default for several terms,
  e.g. Sunseed Oil is default for both `oil` and `cooking oil`).

---

## 3. Resolution algorithm (where it plugs into the brain)

One new lookup inside the existing `ShoppingEngine::resolveItem()` /
`CatalogueMatcher`. The parser already gives us `{query, qty}`; we add lightweight
**size/variant detection** to the parser.

For each parsed item with candidates from `CatalogueMatcher::search()`:

```
candidates = search(query)
if candidates.count == 1            -> use it                      (unchanged)
if candidates.count == 0            -> not found / defer           (unchanged)

# multiple candidates:
size = detectSize(query)            # kg/g/ml/l number+unit, or small/medium/large/family/sachet
if size present:
    matches = candidates where SKU size == requested size
    if matches.count == 1           -> use it                      (size wins)
    else                            -> CLARIFY  (rule: "size conflict")   # 0 or >1
else:                                # generic request
    default = product_defaults[(tenant, canonical(term))]
    if default && default.active && default.product in candidates && inStock(default):
                                     -> use default                (rule: "use default")
    else if strategy == explicit_then_auto:
        pick = candidates ranked by (default_rank ?? smallest size ?? cheapest)
                                     -> use pick
    else                            -> CLARIFY  (rule: "no default / tied")
```

This satisfies the three required clarify conditions exactly:
1. **No default exists** → falls through to CLARIFY.
2. **Customer specified size conflicts** → size branch, 0-or-many matches → CLARIFY.
3. **Multiple equally likely matches** → no size, no default → CLARIFY.

Touch points (Phase 2 implementation, not now):
- `ShoppingParser`: add `detectSize()` → `{query, qty, size}`.
- `BotBrain::tenantCatalogue()`: also load the tenant's `product_defaults` as a
  `term => product_id` map (one cached query per request, alongside the catalogue
  cache already recommended).
- `ShoppingEngine::resolveItem(query, qty, products, defaults, strategy)`: the
  branch above. Pure + unit-testable with a defaults fixture, same as today.

**Browse always overrides default.** "show me rice" / "which rice" / "rice
options" must still list all SKUs — a default never suppresses an explicit browse.

---

## 4. Edge cases & safeguards

| Case | Behaviour |
|---|---|
| Default SKU out of stock | ignore default → CLARIFY (and flag to owner); never add an out-of-stock default |
| Default SKU deactivated/deleted | row set inactive → behaves as "no default" → CLARIFY; UI shows "broken default" |
| Customer gives quantity only ("5 rice") | qty applies to the default SKU → "Added 5 × Rice 5kg" |
| Customer gives a size we stock ("rice 2kg") | exact size wins over default |
| Customer gives a size we don't stock ("rice 3kg") | CLARIFY with available sizes (don't silently use default) |
| Term maps to unrelated groups ("oil" → cooking + engine oil) | owner's default for `oil` decides; if it's wrong they re-point it |
| Default exists but customer is browsing | default ignored; show all options |
| Multiple SKUs same size (1kg brand A vs 1kg brand B) | size doesn't disambiguate → default if set, else CLARIFY |

---

## 5. Strategy modes (owner choice)

- **Ask (off):** legacy — always clarify. Safe, higher friction.
- **Use my defaults (explicit) — recommended:** owner sets defaults for the
  groups they care about; everything else still asks.
- **Always pick (explicit_then_auto):** for owners who never want to ask — uses
  the default, else auto-picks (smallest/cheapest or `default_rank`). Lowest
  friction, small risk of picking a size the customer didn't want (mitigated by
  the "reply for another size" hint in §7).

---

## 6. Admin UI proposal (Filament v3)

### 6.1 New page: **Products → Smart Defaults**

A guided table that auto-detects ambiguous groups and lets the owner set a default
per group in one place. This is the primary surface.

```
┌─ Smart Defaults ──────────────────────────────────────────────────────────┐
│  Strategy:  ( ) Always ask   (•) Use my defaults   ( ) Always pick          │
│                                                                            │
│  Term        SKUs  Default SKU                         Status              │
│  ─────────────────────────────────────────────────────────────────────    │
│  rice         3    [ Rice 5kg ▾ ]                       ✅ set              │
│  sugar        2    [ Kinyara Sugar 1kg ▾ ]              ✅ set              │
│  oil          4    [ — choose — ▾ ]                     ⚠️ will ask         │
│  milk         2    [ Fresh Dairy 1L ▾ ]                 ✅ set              │
│  flour        2    [ Pearl Atta 2kg ▾ ]  (out of stock) ⛔ broken — fix     │
│  ─────────────────────────────────────────────────────────────────────    │
│  Showing groups with 2+ SKUs.  [ + Add term ]   [ Save ]                   │
└────────────────────────────────────────────────────────────────────────────┘
```

Behaviour:
- Rows are **auto-derived**: scan active products, group by canonical term
  (`CatalogueMatcher::tokens`), list groups with ≥2 SKUs (the ones that cause
  clarification today). Owner only sees groups that actually need a decision.
- Each default is a `Select` filtered to that group's SKUs.
- Status badges: ✅ set · ⚠️ will ask (no default) · ⛔ broken (default
  inactive/out of stock) with an inline fix.
- Strategy radio at the top writes `tenants.settings.default_strategy`.
- `+ Add term` lets the owner create a default for a phrase the auto-scan didn't
  surface (e.g. a marketing alias).

### 6.2 Product edit form — inline toggle

On each Product's edit page, a small section:

```
Smart default
  [✓] Use this SKU as the default for:  [ rice ▾ ]
       (when a customer just says "rice", we'll add this one)
```
Ticking writes/updates `product_defaults(tenant, term=rice) = this product` and
unticks any previous default for that term (enforced by the unique constraint).
The term `Select` is prefilled from the product's canonical head term.

### 6.3 Filament building blocks (for implementation later)

- A custom Page `SmartDefaultsPage` (or a `ProductDefaultResource` table) with an
  editable column `Select::make('product_id')` per row + a header `Radio` for
  strategy.
- Table scoped by tenant (`BelongsToTenant`), so each store sees only its own.
- Validation: chosen product must belong to the same tenant and be active.
- A `Tables\Columns\TextColumn` status badge computed from stock/active.

---

## 7. Bot reply UX

When a default is used, make it transparent and reversible:

```
Customer:  rice
Bot:       Added *Rice 5kg* — UGX 38,000.
           (Want a different size? reply "rice 1kg" or "rice 2kg".)
```

The hint only needs to show the first time per conversation (or when the group has
several sizes), to avoid clutter. This keeps the friction reduction without
locking customers into the default.

---

## 8. Analytics / observability (lightweight)

Log a structured event per resolution: `default_used | size_match | clarified |
auto_picked`, with tenant, term, chosen product. Lets the owner (and us) see:
- which groups still clarify most (candidates for setting a default),
- how often a default is later overridden (signal the default is wrong size),
- default coverage (% of ambiguous groups with a default set).

Surface a one-line nudge on the dashboard: "3 product groups still ask customers —
set defaults to speed up checkout → Smart Defaults."

---

## 9. Rollout plan

1. Ship `explicit` strategy as the default; existing stores keep clarifying until
   they set defaults (zero behaviour change on day one).
2. On first visit to Smart Defaults, **suggest** a default per group (smallest or
   cheapest SKU) for the owner to accept/override in one click — fast onboarding.
3. Wire `detectSize()` + the resolver branch behind the strategy flag.
4. Add the dashboard nudge + analytics once data exists.

Auto-pick (`explicit_then_auto`) and `default_rank` are a later, optional
increment — not needed for the core friction win.

---

## 10. Open questions for you

1. Default the strategy to **"Use my defaults"** (clarify when unset) for all
   tenants? (recommended) — or start everyone on "Always ask"?
2. For **auto-pick** when no default exists: prefer **smallest size**, **cheapest**,
   or **best-seller**? (affects `default_rank` seeding)
3. Should the "reply for another size" hint show **every time** or **once per
   conversation**?
4. Is "default per canonical term" the right grain, or do you also want a
   **per-category** fallback (e.g. a default for the whole "Drinks" category)?
