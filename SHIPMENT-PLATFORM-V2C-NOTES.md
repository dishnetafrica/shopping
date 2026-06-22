# Shipment Platform v2C — Logistics dashboard (operational visibility)

The Shipments page is now a **manager dashboard**: live metrics, five operational views, search, and a
dedicated exception surface. Read-only monitoring — no customer-facing tracking yet (that's Phase 4).
The **Shipment Timeline** requirement is met by the existing detail view (full custody chain + photos).

## Endpoint

`GET /papi/shipments-dashboard?view=&q=&transport=&origin=&destination=`
returns `{ metrics, facets, breakdown, items, delay_hours }` — one call powers the whole page.

## Metrics (computed over the whole fleet, not the filtered view)

- **Active** — `packed` + the transport pipeline (`sent_to_transporter`, `transport_confirmed`, `in_transit`).
- **In transit** — the pipeline only.
- **Delayed** — in transit AND no movement (updated_at) for ≥ **24h** (`delay_hours`, tunable).
- **Exceptions** — shipments with ≥ 1 open discrepancy.
- **Completed today** — shipments whose `arrived_at` is today.

## Views

| View | Maps to |
|---|---|
| Awaiting Dispatch | status `packed` |
| In Transit | `sent_to_transporter` / `transport_confirmed` / `in_transit` |
| Arrived | status `arrived` **and** order not yet Delivered |
| Delivered | linked order status = `Delivered` |
| ⚠ Exception | open discrepancies (+ transporter / origin / destination filters) |

**Delivered** is grounded in the order's status — the shipment FSM tops out at `arrived` (destination
handoff); customer-delivered is the order/last-mile signal. When the Delivery (last-mile) layer is wired
into the custody ledger (Phase 3/4), this can switch to the Delivery's delivered flag with no UI change.

## Search & exception dashboard

- **Search** (debounced) matches shipment #, order #, customer name, phone — case-insensitive, and respects the active view.
- **Exception view** adds: a **type breakdown** (Missing / Extra / Damaged box counts) and **filters** by Transport company / Origin / Destination (populated from live facets). Breakdown recomputes against the filtered set.

## Files

**Edited**
- `app/Http/Controllers/Panel/ShipmentController.php` — new `dashboard()` method (metrics + view filter + search + facets + breakdown). 2A `index` kept for backward-compat.
- `routes/web.php` — `GET /papi/shipments-dashboard`.
- `resources/panel/seller.html` — nav/title renamed **Logistics**; page now has a metrics row, the five view tabs, a search row, and an exception-breakdown/filter block; the list JS now drives off the dashboard endpoint with richer cards (customer · phone, Delayed / Delivered tags). The shipment **detail view (custody timeline + share links) is unchanged** — that's the "Shipment Timeline".

**New**
- `qa/shipment_dashboard.php` — framework-free proof of every rule above (metrics, view membership, facet filters, breakdown, search). **21/21 green.**

## Deploy

```bash
# GitHub → EasyPanel pull → restart, then:
php artisan optimize:clear   # new route
```

No new migrations. Assumes Phase 1 + 2A + 2B already deployed.

## QA

```
shipment_state_machine   26/26  ALL GREEN
shipment_custody         21/21  ALL GREEN
shipment_dashboard       21/21  ALL GREEN
```

## Still deliberately out

No notifications (Phase 3), no customer-facing tracking (Phase 4). The dashboard caps at 500 shipments
per load — fine for now; add pagination when a tenant outgrows it.
