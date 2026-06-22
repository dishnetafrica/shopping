# Shipment Platform v1 — Phase 1 (data model + state machine + custody + reconciliation)

Reusable, platform-wide logistics layer. **Order → Shipment → Delivery** as three separate state
machines. This phase ships the data model and the pure logic only — **no UI, no tokenized pages, no
WhatsApp notifications yet** (those are Phase 2/3). All new files; nothing existing was edited.

## The three machines (do not merge them)

- **Order.status** — shop lifecycle (unchanged: New/Accepted/…/Delivered).
- **Shipment.status** — transport leg: `packed → sent_to_transporter → transport_confirmed → in_transit → arrived` (+ `cancelled`). New.
- **Delivery.status** — last mile (already exists: assigned/picked/out/delivered/failed). Reused; not rebuilt.

## Files (all new)

**Migrations**
- `database/migrations/2026_06_22_000002_create_shipments_table.php`
- `database/migrations/2026_06_22_000003_create_shipment_events_table.php`  ← append-only custody ledger
- `database/migrations/2026_06_22_000004_create_shipment_exceptions_table.php`

**Models** — `App\Models\Shipment`, `ShipmentEvent`, `ShipmentException` (all `BelongsToTenant`).

**Pure logic** (`app/Services/Logistics/`)
- `ShipmentStateMachine.php` — transitions, actors, the custody event each action emits. Never throws.
- `CustodyReconciler.php` — compares each counted handoff to the previous one and **localises which leg lost/gained boxes** (not just vs the original total).

**Orchestration**
- `ShipmentService.php` — `createFromOrder()/create()` (seeds `packed` event), `recordAction()` (advance + append event + reconcile), `recordCustody()` (last-mile events without touching the transport machine), `reportDamage()`, `reconcile()` (re-derives missing/extra; preserves resolved + manual damage).

**QA** (framework-free) — `qa/shipment_state_machine.php` (26), `qa/shipment_custody.php` (21). Both green. Plus an ad-hoc Kampala→Juba flow sim (9) proving state-machine + reconcile interplay.

## Chain of custody (the crown jewel)

`shipment_events` is append-only and spans **both** legs (transport + last mile), so every box movement
is auditable:

```
packed(5) → received_by_transport(5) → bus_departed(—) → arrived(5) → collected_by_rider(5) → delivered(5)
```

Each row: `event, actor, actor_name, box_count, photo_url, note, occurred_at`. Stages without a recount
(e.g. `bus_departed`) store `box_count = null` and are skipped by reconciliation.

## Reconciliation → exceptions

After every counted handoff, `reconcile()` compares consecutive counts and writes `shipment_exceptions`
rows: `missing_boxes` / `extra_boxes` (auto) and `damaged_boxes` (manual via `reportDamage()`), each
**localised to the leg** (`from_stage → to_stage`, `expected`, `got`, `delta`). Auto rows are re-derived
idempotently; resolved + damage rows are preserved.

Example: shop packs 5, agent receives 4 → one `missing_boxes`, `received_by_transport → arrived`, delta 1.

## Deploy

```bash
# GitHub → EasyPanel pull → restart, then:
php artisan migrate        # adds shipments, shipment_events, shipment_exceptions
# no optimize:clear needed (no new routes/commands yet)
```

## Sandbox QA

```bash
php qa/shipment_state_machine.php   # 26/26
php qa/shipment_custody.php         # 21/21
```

## Outstanding (next phases)

- **Phase 2** — tokenized external pages `/t/{token}` for transporter + destination agent (confirm receipt / bus departed / arrived, with box count + photo). NOTE: token pages have **no tenant auth context** — resolve the shipment by token, then set `TenantContext` from `shipment.tenant_id` before using tenant-scoped models.
- **Phase 3** — wire `Delivery` last-mile events into `recordCustody()` (rider collected / delivered) so the ledger is complete; extend `SendOrderStatusNotification` for shipment stages — **scoped/throttled**: customer gets journey updates, transporter + agent get ONE secure link each, shop uses the dashboard. Do **not** broadcast every stage to 5 parties (ban risk — see the FS number incident).
- **Phase 4** — Shop "Transport/Tracking" dashboard view + token-scoped Transport/Destination dashboards.
- Shipment-create hook from a packed Order (button in the panel), shipment_number sequence hardening under concurrency.
