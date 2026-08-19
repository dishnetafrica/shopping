# ShopBot Vision — ERP Integration Architecture (research/design, no code)

**Positioning:** *ShopBot Vision connects your CCTV to your inventory system.* Keep Odoo / Tally / SAP /
QuickBooks / whatever the customer already trusts; ShopBot is the **physical-verification layer**, not a
replacement system of record. **Design only — no production code, reconciliation engine untouched.**

```
CUSTOMER ERP (Odoo/Tally/SAP/…) ──ERP Connector──▶ Normalized ExpectedMovement ──▶ ShopBot Reconciliation ◀── stock.move ── Edge AI ◀─ CCTV
                                                                    (the two sides meet ONLY here)
```

## Grounded finding: the engine already consumes a normalized external ledger — **no engine change**

This is not aspirational; it is how the code is already built:

- **The pure engine** `ReconciliationEngine::reconcile($event, $ledger, $prior, $cfg, $asOf)` takes the
  **ledger as a parameter** — an array of normalized transactions
  `['type','location','direction','qty','ts']`. It has **zero knowledge of where the ledger came from**
  (unit-tested, framework-free).
- **Only one method binds it to ShopBot:** `VisionReconciler::loadLedger()` queries
  `DB::table('stock_movements')` and projects each row into that 5-field shape. That is the *sole*
  ShopBot-specific coupling in the whole reconciliation path.

**Answer to "engine vs adapter":** the **engine needs no change**; an **adapter layer is required**, and
it is small and localised — replace the single `loadLedger()` with a pluggable `LedgerSource`, and feed
it from a normalized store that ERP connectors populate. Persistence, sweep, verify command, and the
review screen are unaffected (they read the decision on `vision_events`).

## The normalized model (superset of the engine's existing ledger contract)

The engine already defines the minimal transaction shape. The **ExpectedMovement** is that shape plus the
advisory fields a human review and future exact-qty mode want:

```
ExpectedMovement
  tenant_id
  source_system      shopbot | tally | odoo | api
  source_record_id   ERP reference / GUID (SO-10482, voucher no, picking name)
  location           normalized location key (what a zone maps to)
  direction          in | out            ← the engine matches on this
  type               dispatch | receipt | transfer | adjustment | sale | purchase
  qty                integer             ← engine matches within qty_tolerance (wide/presence today)
  expected_at        unix ts             ← engine matches within the asymmetric window
  sku                advisory (product code)      ─┐ NOT used for the basic match — the camera
  product_name       advisory                      ├─ counts packages, not SKUs; ledger owns "what".
  unit               advisory                     ─┘ Surfaced in review + future exact mode.
  raw                original ERP record (json, audit)
```

`loadLedger` projects `ExpectedMovement → ['type','location','direction','qty','ts']` for the engine —
exactly what `stock_movements` is projected into today. **The reconciliation matches on
location + direction + time-window + qty-tolerance, NOT on SKU** — which is precisely why "the camera
doesn't need to know it's Royal Rice 25kg" works, and why `sku`/`object_type` both stay advisory.

## The adapter: a `LedgerSource` seam + a sync store

```
LedgerSource (interface)
  candidates(tenantId, location, fromTs, toTs): array<normalized txn>

  ├─ ShopBotLedgerSource   → today's stock_movements query (unchanged behaviour)
  ├─ TallyLedgerSource     ─┐
  ├─ OdooLedgerSource       ├─ read the normalized `expected_movements` store the connector fills
  └─ GenericApiLedgerSource ┘
```

Two operating modes (the connector decides; the engine never sees the difference):
- **Synced (recommended for external ERPs):** the connector **polls or receives webhooks** from the ERP
  and writes normalized `ExpectedMovement` rows into a ShopBot-local table; `LedgerSource` reads that.
  Resilient to ERP downtime/rate-limits, fast at reconcile time, fully auditable (`raw` kept).
- **Live-query (optional):** `LedgerSource` calls the ERP per event — only sensible for a fast local ERP
  (e.g. Tally on the LAN). Latency/rate-limits make this a fallback, not the default.

`VisionReconciler` chooses the source by tenant config. The **zone→location map extends** minimally to
carry `{source_system, external_location_id}` so a door maps to an Odoo `stock.location` / Tally godown.

## ERP research — capabilities & how to obtain each field

### 1. TallyPrime (high value for Uganda / India / East Africa)
Integrates via **XML, JSON and ODBC**, bidirectional; Tally can act as an **HTTP server** (default port
9000) answering XML/JSON requests, and exports masters + vouchers programmatically.

| Our need | Tally source |
|---|---|
| products / SKUs | **Stock Item** masters (name, alias, part no) |
| warehouses / locations | **Godown** masters |
| stock levels | **Stock Summary** / closing balance |
| purchase receipts (IN) | **Receipt Note** / **Purchase** voucher |
| sales orders / dispatches (OUT) | **Delivery Note** / **Sales** voucher |
| stock transfers (MOVE) | **Stock Journal** / material transfer voucher |
| inventory adjustments (ADJUST) | **Physical Stock** voucher |
| timestamps | voucher **DATE** |
| transaction / reference IDs | voucher **number + GUID** |

### 2. Odoo (high value for larger/modern warehouses)
External API (XML-RPC / JSON-RPC, moving to the **External JSON-2 API**) exposes the inventory models
directly; explicit product-movement + location concepts and a Moves History view.

| Our need | Odoo model / field |
|---|---|
| products / SKUs | `product.product` (`default_code`=SKU, `name`) |
| warehouses / locations | `stock.warehouse`, `stock.location` (`complete_name`, usage internal/transit) |
| stock levels | `stock.quant` (on-hand qty per location) |
| purchase receipts (IN) | `stock.picking` (incoming) + `stock.move`/`stock.move.line` (`qty_done`, `date`) |
| sales orders / dispatches (OUT) | `stock.picking` (outgoing) + `stock.move` |
| stock transfers (MOVE) | internal `stock.picking` (source→dest location) |
| inventory adjustments (ADJUST) | inventory-adjustment `stock.move.line` / `stock.quant` |
| timestamps | `date_done` / `date` |
| transaction / reference IDs | `picking.name`, `origin` (e.g. SO/PO), move id |

### 3. Generic REST/API ERP (everything else)
Publish the **minimal contract** we ask a customer's integrator to satisfy — either they push webhooks or
we poll:
```
GET  /movements?since=<ts>&location=<id>   → [{direction,type,qty,sku?,location,expected_at,reference}]
GET  /locations   /products   /stock_levels          (optional enrichment)
POST (webhook) → same movement shape on each ERP event
```
This IS the `ExpectedMovement` shape — the generic connector is the identity adapter.

## What changes, what doesn't (and none of it now)

- **No change:** `ReconciliationEngine` (already ledger-agnostic), the decision/severity logic, the
  sweep + verify commands, the review screen data contract, the Vision/edge side, the `stock.move`
  contract.
- **Adapter work (design target, NOT built here):**
  1. extract `loadLedger()` → a `LedgerSource` interface (`ShopBotLedgerSource` = today's behaviour),
  2. add a normalized `expected_movements` store,
  3. per-ERP connectors (poll/webhook) that populate it (Tally, Odoo, Generic),
  4. extend the zone→location map with `{source_system, external_location_id}`,
  5. select the source per tenant.
- **Minor additive nicety later:** show `source_system` + `reference` on the review screen.

**Do not modify the reconciliation engine.** It is already able to consume a normalized external ledger;
the ERP-specificity belongs entirely in the adapter/connector layer.

## Phasing (business-led, not 20 ERPs at once)

1. **Connector 1 — ShopBot** (exists: `stock_movements`). Baseline `ShopBotLedgerSource`.
2. **Connector 2 — Tally** (target-market fit; HTTP XML/JSON, synced mode).
3. **Connector 3 — Odoo** (modern warehouses; External API, synced mode).
4. **Connector 4 — Generic API** (webhook/poll contract for the long tail).

## Why this matters

- ShopBot becomes the **reconciliation layer**, not the customer's accounting system — far easier to
  sell, and it stops assuming "ShopBot is always the ledger."
- The vision side stays **fully independent**: `CCTV → Edge AI → stock.move → ShopBot`. The two halves
  meet **only** at the normalized movement/reconciliation layer — exactly the seam the engine already
  exposes.
- It strengthens the $49/month proposition: the customer keeps their ERP and adds a physical
  verification layer over the system they already trust.

_ERP API facts checked 2026-08 (Odoo External/JSON-2 API + stock models; TallyPrime XML/JSON/ODBC + HTTP
server). Re-verify per ERP version at connector-build time._
