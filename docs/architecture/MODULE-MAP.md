# MODULE-MAP.md — CloudBSS module ownership & dependency rules

This is the living answer to: *which module owns this class, what may it depend on, and
what is its public API?* Modules are defined **by namespace, not by folder** — the tree
stays where it is; the rules travel with the namespaces. Enforced by
`tests/Feature/ArchitectureBoundariesTest.php`.

## Layer 0 — Kernel (pure)
| | |
|---|---|
| Owns | `CloudBSS\Kernel\*` (`cloudbss-kernel/src`), `CloudBSS\App\*`, `CloudBSS\Infra\*`, `CloudBSS\Modules\*` |
| May depend on | PHP + itself. **Never `App\*`, never Laravel facades.** (Measured today: 0 violations across 68 files.) |
| Public API | `Kernel`, `Money\*`, `Ledger\*` (policies, journal, projection), `Event\*`, `Decision\*` |
| Consumers | `app/` ledger stack: `App\Services\Ledger`, `App\Reports\Definitions`, `App\Providers\LedgerServiceProvider`, the six root policies (F4), Filament Books — 26 files measured. Direction: app → kernel only. |

## Layer 1 — Platform services (any module may depend on these)
| Module | Namespaces / files | Public API | Private internals |
|---|---|---|---|
| **Communication** | `App\Services\Comms\*`, models `Comm*`, `App\Http\Controllers\Comms\*`, `Panel\EmailCenterController`, jobs `SendComm*`, commands `Comms*`, `App\Support\Comms` | `App\Support\Comms` (facade), `CommCenter::publish()`, `EmailService`, `WhatsAppService`, `CampaignService` | `Providers\*`, `Contracts\*`, `EmailDispatcher`, `WhatsAppDispatcher`, `TemplateRenderer`, `ProviderResult` — **no reference from outside the module** (test-enforced) |
| **WhatsApp gateway** | `App\Services\WhatsApp\*`, `App\Contracts\WhatsAppGateway` | `WhatsAppManager::forTenant()` + the gateway contract | gateway implementations |
| **Money / FX** | `App\Services\Money\*` | `Fx::forTenant()`, rate helpers | — (must depend on NO domain; the one existing `Money → Invoicing` edge is baselined for inversion, F5) |
| **Tenancy & identity core** | `App\Models\Tenant`, `User`, `App\Support\TenantContext`, middleware | model APIs, `TenantContext` | — |
| **Support** | `App\Support\*` | all | — |

## Layer 2 — Domain modules (namespace = module)
| Module | Namespaces (App\Services\… unless noted) | Notes |
|---|---|---|
| Sales & Storefront | `Sales`, `Storefront`, `Invoicing`, `Pricing`, `App\Apps\Orders`, `App\Apps\DailyMenu` | |
| Bot / Conversational | `Bot`, `Knowledge`, `Enrichment` | Sales→Bot edges are baselined (sales flows through chat) |
| Catalog | `Catalog`, `Catalogue` | duplicate-name pair; merge candidate, Phase 3 |
| Purchasing & Procurement | `Purchasing`, `Procurement` | |
| Inventory | `Inventory` | |
| Finance & Ledger | `Finance`, `Ledger`, `Billing` | bridge to Layer 0 |
| Marketplace | `Marketplace` | stays OUTSIDE Sales; zero inbound Sales edges today — keep it so |
| Logistics | `Delivery`, `Logistics` | |
| Operations | `Ops` (TaskEngine, escalations, digests) | the reviewer's "control tower" already exists here; grow it, don't invent a new home |
| CRM & Marketing | `Crm`, `Marketing`, `Analytics` | |
| Settings / Auth | `Settings`, `Auth` | |
| WinWorld | `Winworld`, `routes/winworld.php` | separate vertical |

## Dependency rules (the ones the test enforces)
1. **Kernel purity** — no file under `cloudbss-kernel/` may `use App\` or reference `App\` types.
2. **Single home** — root `Capability/ Console/ Http/ Jobs/ Services/` may contain only the 7 pinned legacy files (until archived; then nothing). Root `src/` & `modules/`, while they still exist, must stay byte-identical to `cloudbss-kernel/{src,modules}`.
3. **Communication encapsulation** — outside the Communication module's own files, only its public API may be referenced; the private list above must not appear.
4. **Cross-domain edges are closed-world** — the 17 measured edges below are the baseline. A new `Services\X → Services\Y` import fails the suite until it is either routed through Y's public API or *deliberately* added here (one line, with a reason in the commit).

### Baseline edge list (measured 2026-07-18; shrink it, don't grow it)
```
Sales→Bot(4)  Finance→Money(3)  Bot→Catalogue(3)  Sales→Storefront(2)  Bot→WhatsApp(2)
Comms→WhatsApp(1)  Purchasing→Money(1)  Sales→WhatsApp(1)  Sales→Inventory(1)  Sales→Money(1)
Money→Invoicing(1 · REVERSED, queued for inversion)  Auth→WhatsApp(1)  Ledger→WhatsApp(1)
Settings→Money(1)  Analytics→Bot(1)  Procurement→Inventory(1)  Delivery→WhatsApp(1)
```
(Edges to Layer-1 platform services — WhatsApp, Money, Comms — are legitimate by rule
and listed only for completeness; the closed world applies to domain↔domain edges.)

## Architectural decisions (system-level, enforced by ArchitecturalDecisionsTest)
| # | Decision | Baseline (measured 2026-07-18) |
|---|---|---|
| D1 | The domain kernel is framework-free; Laravel appears only in `cloudbss-kernel/infra/` | 0 violations |
| D2 | No business module sends mail directly — email goes through the Communication module | 3 pinned legacy sites (BotReplayCommand, CashbookDigestCommand legacy branch, QuotationPortalController legacy branch); shrink-only |
| D3 | WhatsApp gateway implementations stay behind `WhatsAppManager` | 2 pinned admin-plane sites (WaWatchdogCommand, PanelApiController); shrink-only |
| D4 | Marketplace ⟂ ERP, both directions; the bridge is a future Settlement seam | 0 edges |
| D5 | Module public APIs are closed-world — only the published surface below may be referenced from outside | see registry |

### Published API registry (D5 — first segment after the module namespace)
| Module | Published surface |
|---|---|
| Marketplace | ProviderRegistry, MarketplaceSearch, Providers\*, Dto\*, Support\* |
| Ops | **TaskEngine only** — Operations coordinates workflows; it does not own business data |
| Delivery | DeliveryService, RiderAssigner, ZoneResolver, DeliveryStatus |
| Logistics | ShipmentService, LastMileBridge, ShipmentStateMachine, BoxCustodyService |
| Ledger | StatementMessenger, OrderLedgerService |
| Sales | QuotationWriteService, QuotationConvertService, QuotationIntelligence, QuotationReviseService |
| Inventory | StockLedger, InventoryState, OrderStockIssuer, StockBackfill |
| Bot | 32 classes (wide legacy surface, frozen as-is in the test; every retirement is progress) |

Publishing a new class = one line in the registry above AND in the test, in the same
commit, with the reason. Unpublishing (shrinking) needs no ceremony — delete the line
once external callers are gone.
