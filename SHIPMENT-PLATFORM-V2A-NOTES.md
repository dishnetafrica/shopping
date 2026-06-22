# Shipment Platform v2A — Seller-Panel management

Internal workflow only: the shop can now **create and run a shipment end-to-end from the panel** —
before any transporter, agent, or notification touches it. Builds on Phase 1 (engine).

**Requires Phase 1 deployed first** (the `shipments` / `shipment_events` / `shipment_exceptions`
migrations + models + `app/Services/Logistics/*`). This delta assumes those are already in the repo.

## What's in 2A

**New** — `app/Http/Controllers/Panel/ShipmentController.php`
- `index`   GET `/papi/shipments?filter=all|packed|in_transit|arrived|exception` — dashboard list.
- `show`    GET `/papi/shipment?id=` — detail: status + flow + custody timeline + exceptions + next action.
- `store`   GET `/papi/shipment-create?order_id=&...` — create from an order (idempotent per order; starts `packed`).
- `action`  GET `/papi/shipment-action?id=&action=dispatch|transport_confirm|depart|arrive|cancel&box_count=&...` — advance + append custody event + reconcile.
- `resolveException` GET `/papi/shipment-exception-resolve?id=` — mark a discrepancy resolved.
- `forOrder` GET `/papi/order-shipment?order_id=` — does this order already have a shipment? (button gating)

**Edited**
- `app/Services/Logistics/ShipmentService.php` — `create()` now persists transport_company / bus_number / driver_phone supplied at creation.
- `routes/web.php` — 6 GET routes in the papi group.
- `resources/panel/seller.html`:
  - **🚚 Shipments** nav item + `#pageShipments` (filter tabs · list · detail) + `showPage` wiring.
  - Shipments dashboard with **All / Packed / In Transit / Arrived / ⚠ Exception** filters.
  - Shipment detail: status flow bar, **next-action form** (dispatch captures transporter+boxes; confirm/arrive capture a box count; depart is one tap; cancel), **custody timeline** (every `shipment_event`), and a red **box-discrepancy panel** with one-tap *Mark resolved*.
  - Order detail toolbar: **🚚 Shipment** button — shows *Create shipment* on a confirmed/paid order with no shipment, or *🚚 SH-xxxx* (jump to it) once one exists.

## The full internal loop you can now drive

1. Open a confirmed/paid order → **🚚 Create shipment** → fill transporter + origin/dest + boxes_sent → **Packed**.
2. Shipment page → **Dispatch to transporter** (boxes) → **Confirm transport receipt** (boxes) → **Bus departed** → **Mark arrived** (boxes).
3. Enter a smaller box count at any handoff → an exception is flagged **on that exact leg** (e.g. `received_by_transport → arrived, 1 missing`), visible in the detail panel and the **Exception** filter.

## Deploy

```bash
# GitHub → EasyPanel pull → restart, then:
php artisan migrate         # only if Phase 1 migrations not yet run
php artisan optimize:clear  # new routes
```

No new migrations in 2A itself (Phase 1 owns the schema).

## Deliberately NOT in 2A (next phases, unchanged plan)

- **2B** — `/t/{token}` transporter page (confirm receipt / bus departed, box count + photo). Token pages have no tenant-auth context → resolve by token, then set `TenantContext` from `shipment.tenant_id`.
- **2C** — `/agent/{token}` destination-agent page (arrived, box count + photo).
- **3** — scoped notifications (customer journey; transporter + agent get ONE link each; shop = dashboard). No 5-party per-stage blast — ban risk.
- **4** — customer-facing shipment tracking.
- Photo capture on actions (the fields exist: `photo_url` on events; wire the uploader next).
