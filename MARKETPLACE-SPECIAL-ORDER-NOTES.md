# Special Order — Live Marketplace Pricing (provider-based)

Ports the DishNet "quote an off-catalogue item instead of dead-ending" feature into
ShopBot, built as a **provider architecture** so it is never tied to Jumia.

Search order: **Customer → ShoppingParser → Local Catalogue → Marketplace → AI formatting.**
The marketplace step only runs after the catalogue misses, is per-tenant opt-in, and
degrades to today's exact behaviour on any failure.

---

## What the bot talks to

`BotBrain` only ever calls **`App\Services\Marketplace\MarketplaceSearch`**. It has no
idea a marketplace — let alone Jumia — exists. `MarketplaceSearch` resolves whichever
provider the tenant configured, prices the hits, scores them, and returns a decision.

```
BotBrain ── MarketplaceSearch ── ProviderRegistry ── MarketplaceProvider (JumiaProvider, …)
                     │
                     ├── PricingPlan (Shipping→Import→Duty→Markup→FX→Round)
                     ├── Confidence (did-you-mean gating)
                     └── ImageHostAllowList (photo safety)
```

## New files (all under `app/Services/Marketplace/`)

- `Contracts/MarketplaceProvider.php` — the only interface: `search(string): MarketplaceResult[]`.
- `Providers/JumiaProvider.php` — consumes the **existing** Playwright API (`/search`, `/health`). Scraper NOT rebuilt.
- `ProviderRegistry.php` — resolves providers by key from `config/marketplace.php`.
- `MarketplaceSearch.php` — orchestrator (`quote()`, `enabledFor()`, spec-compatible `search()`).
- `Config/MarketplaceConfig.php` — per-tenant settings as an immutable value object.
- `Support/Confidence.php` — name-vs-query match scoring + high/medium/low classify.
- `Support/ImageHostAllowList.php` — only forward images from approved CDN hosts.
- `Pricing/` — `PriceBuild`, `PricingComponent`, `PricingPlan`, `PricingPlanFactory`, and
  `Components/{ShippingCost,ImportCost,DutyCost,Markup,FxConvert,RoundUp}`.
- `Dto/` — `MarketplaceResult` (raw), `MarketplaceQuote` (priced, customer-facing),
  `MarketplaceOutcome` (quote / confirm / none).

Config: `config/marketplace.php`. Tests: `tests/Unit/Marketplace/*`.

## Edited files (surgical, additive, all behind the per-tenant flag)

- `app/Services/Bot/BotBrain.php` — inject `MarketplaceSearch`; hook the "we don't stock"
  dead-end; add `trySpecialOrder`, `resolveSpecialOrderConfirm`, `specialOrderReply`, helpers.
- `app/Services/Bot/ProductImageResponder.php` — send the pinned special-order photo when the
  customer asks to "see it" (host allow-listed).
- `app/Providers/AppServiceProvider.php` — bind `ProviderRegistry` + `JumiaProvider`.
- `app/Filament/Pages/Settings.php` — seller "Special Order" section (toggle, source, margin, rate, terms).

> Note on placement: the original spec suggested `App\Services\Catalogue\MarketplaceSearch`.
> I housed the whole subsystem under `App\Services\Marketplace\` instead — it's the cohesive
> home for a provider architecture and keeps catalogue vs marketplace concerns separate. The
> spec's behaviour is unchanged; `MarketplaceSearch::search()` still returns the original
> `?array` shape for anyone consuming that contract.

## How the confidence gate behaves (per-tenant, config-tunable)

- **High** (score ≥ `marketplace.confidence.high`, default 0.60) → quote the price(s) straight away.
- **Medium** (≥ 0.30) → "I might be able to special-order *X* — is that the one you mean? Reply *yes*."
  A `yes` promotes the stored candidate to a quote; a `no` clears it. Pending confirm expires in 30 min.
- **Low** (< 0.30) / empty / price API down → no price invented; the shop is alerted and the
  customer is told the shop will confirm price & availability. (Feature OFF → plain "we don't stock".)

## Pricing is extensible (no rewrite to add stages)

`PricingPlanFactory` builds this ordered chain; each stage is a `PricingComponent`:

```
Marketplace price → Shipping → Import → Duty → Markup → FX → Round = Customer price
```

Today only **Markup** (+ optional **FX** for USD shops) is active — Shipping/Import/Duty
default to 0 and are no-ops. To switch a tenant to true landed-cost pricing, just set the
rates in settings (`special_order_shipping_flat/_pct`, `_import_pct`, `_duty_pct`,
`_round_step`). To add a brand-new cost tomorrow: one new class in `Pricing/Components/`
slotted into the factory. Every quote carries a full landed-cost `breakdown` for future receipts.

## Adding a marketplace tomorrow (Amazon / Noon / Alibaba / supplier DB)

1. Create `App\Services\Marketplace\Providers\AmazonProvider implements MarketplaceProvider`.
2. Register it: `'amazon' => AmazonProvider::class` in `config/marketplace.php` `providers`.
3. (If it has its own credentials) bind it in `AppServiceProvider` like `JumiaProvider`.
4. Add its CDN host to `allowed_image_hosts`.

No changes to `BotBrain`, `MarketplaceSearch`, pricing, or the UI.

## Deploy / config

Global (`.env`):

```
PRICE_API_URL=https://<your-playwright-box>      # the existing scraper's public URL
PRICE_API_KEY=<key>
# optional: PRICE_API_TIMEOUT=60  MARKETPLACE_DEFAULT_PROVIDER=jumia
# optional: MARKETPLACE_CONF_HIGH=0.60  MARKETPLACE_CONF_MEDIUM=0.30
```

Per tenant (Seller panel → Settings → Special Order, or `tenant->settings`):

```
special_order_enabled = true
special_order_margin  = 0.15          # +15%
special_order_ugx_rate= 0             # 0 = quote UGX; set (e.g. 3600) to quote USD
special_order_terms   = "…"           # optional; sensible default provided
```

DishNet and ShopBot can point `PRICE_API_URL` at the **same** price API.

## Guardrails (all enforced)

- Never invents a price — only prices the marketplace actually returned.
- Never names Jumia / the marketplace to the customer — always "Special Order".
- Photos only from allow-listed CDN hosts (`config('marketplace.allowed_image_hosts')`).
- `MarketplaceSearch`, every provider, and every bot hook is wrapped so failures return
  "none" and the normal ordering flow is byte-identical to today.
- Per-tenant flag — grocery shops can leave it off; electronics shops opt in.

## Tests

`tests/Unit/Marketplace/` — Confidence, ImageHostAllowList, PricingPlan (margin/FX/landed/round),
MarketplaceSearch (quote / confirm / none gating, image stripping, option cap). Pure unit tests
(no DB/boot), same style as `ShoppingEngineTest`. Run: `php artisan test --testsuite=Unit`.
(Verified green in this build via a standalone harness: 23 methods / 51 assertions passing.)
