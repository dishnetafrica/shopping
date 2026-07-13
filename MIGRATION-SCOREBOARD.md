# CloudBSS v3 — Migration Scoreboard

The single source of truth for migration progress. **Every implementation updates this table.**
Architecture is frozen (Capability Platform v1.1). We replace the UI one workspace at a time against the existing engine, keeping legacy screens until each can be safely retired.

Legend: ✅ done · ⏳ in progress · ❌ not started · n/a not applicable

| Workspace | Backend (engine) | Capability | Shell (UI) | Legacy removed | Notes |
|---|---|---|---|---|---|
| **Customer** | ✅ | ✅ `CustomerCapability` | ❌ | ❌ | read via Statement/Health/Timeline/Assistant; `record_payment` → PaymentService |
| **Collections** | ✅ | ✅ `CollectionsCapability` | ❌ | ❌ | overdue queue + `summary()` for briefing |
| **Statement** | ✅ | ✅ `StatementCapability` | ❌ | ❌ | shared; wraps StatementService |
| **Invoice** | ✅ | ✅ `InvoiceCapability` | ❌ | ❌ | totals + payment timeline + PDF path |
| **Payment** | ✅ | ✅ `PaymentCapability` | ❌ | ❌ | receipt + allocation view |
| **Finance** | ✅ | ✅ `FinanceCapability` | ❌ | ❌ | cash/receivable/payable/runway via CashService |
| **Supplier** | ✅ | ✅ `SupplierCapability` | ❌ | ❌ | payables from OrderCost + recent POs |
| **Sales Order** | ✅ | ✅ `SalesOrderCapability` | ❌ | ❌ | Order header; **OrderCost adapter seam noted (M4)** |
| **Purchase Order** | ✅ | ✅ `PurchaseOrderCapability` | ❌ | ❌ | OrderCost (stage=purchase) |
| **Product** | ✅ | ✅ `ProductCapability` | ❌ | ❌ | stock/margin + ProductRecommendations |
| **Warehouse** | ✅ | ✅ `WarehouseCapability` | ❌ | ❌ | low-stock/reorder queue |
| **Dispatch** | ✅ | ✅ `DispatchCapability` | ❌ | ❌ | Delivery board |
| **Sales pipeline** | ✅ | ✅ `SalesProcessCapability` | ❌ | ❌ | to-invoice + open orders |
| **Purchasing** | ✅ | ✅ `PurchaseProcessCapability` | ❌ | ❌ | payables pipeline |
| **Work Queue** | ✅ | ✅ `WorklistCapability` | ❌ | ❌ | role-scoped, composes process summaries |
| **Morning Briefing** | ✅ | ✅ `MorningBriefingCapability` | ❌ | ❌ | composes process summaries only — a plan, not a dashboard |

**Capability Layer: 16 capabilities registered, graph closed, HTTP layer clean.**
**Shell: one generic renderer live at `/v3`** (served under `web/auth/SetTenantFromUser`), builds nav from the catalog, renders every workspace from the envelope, and posts commands with CSRF. Remaining: point each legacy screen's users at `/v3` and then remove legacy.

**UI polish (prototype parity):** the `/v3` renderer now carries the prototype's design language — record 360 with avatar + financial rail, KPI cards for process metrics, assistant briefing (stats + recommendation + AI actions), timeline nodes, badges, skeleton loading, empty states, animated command modal and toast stack. Still one envelope-driven renderer; no per-workspace code.

**Currency:** headlines/labels now render **native currency** via `App\Capability\Support\Money` (USD → `$`, everything else suffixed, e.g. `745,000 UGX`) instead of a hard-coded `$`. Object records use their own currency; cross-currency process aggregates use `CapabilityContext::baseCurrency()` (tenant `settings.currency`, default `UGX`). Cash-on-hand stays USD (the frozen `CashService::balance` returns the USD holder balance).

## Milestones

- **M1 — Walking Skeleton** ⏳ *(this commit)* — Contracts, Registry, CommandBus, DTOs, one `CustomerCapability`, one `CollectionsCapability`, `/api/v1/workspace/*`, architecture test. **DoD:** Customer Workspace resolves against real data.
- **M2 — Invoice + Payment + Statement page.**
- **M3 — Finance** (merge the four cash screens).
- **M4 — Sales** (resolve `Order`/`OrderCost` adapter).
- **M5 — Purchasing** (Supplier, PO, receiving).
- **M6 — Warehouse / Delivery / Dispatch.**
- **M7 — Morning Briefing** (only after everything else is on the Capability Layer).

## Definition of Done (per workspace)

1. Capability compiles (`php -l`) and passes the architecture test.
2. Read path returns the envelope from **real** services (no fake data).
3. Write path (`command()`) delegates to a frozen service — no duplicated business logic.
4. Backward compatible: the legacy screen still works until the shell reaches parity.
5. Scoreboard row updated.
