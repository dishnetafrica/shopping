# S2b · Serials in the sale — observe-first + reconciliation

Builds on S2a. This wires serialized inventory into delivery **without changing how delivery works**.

Important reality: orders don't capture *which* serials ship (there's no serial-picking step yet). So,
exactly per your observe-first rule, S2b **observes and reconciles** rather than auto-marking — and
gathers the evidence that tells us whether a picking step is worth building.

## What it does
- **Observe (evidence, never blocks):** when a serialized product is delivered without picked serials,
  a `SerialSaleUnpicked` event is logged. Added as a single guarded call at the end of the delivery
  stock-issue — it can never affect delivery or stock.
- **Reconcile (report, never blocks):** `GET /papi/products/{id}/serials/reconcile` compares in-stock
  serial count vs ledger on-hand and returns the drift, so you can see where serials and stock diverge.
- You can already mark a unit Sold against an order manually via S2a's status API.

## Deferred (evidence-gated)
Picking serials at delivery + auto-marking them Sold — build once the observations show it's needed.
That's the point of observing first.

## What to do
Upload these files (keep folders). Green ✓ "Money kernel (must pass)" confirms it.

## What's inside
- `SerialEvents.php` — adds the `SerialSaleUnpicked` observation.
- `SerialService.php` — `observeDelivery()` + `reconcile()`.
- `OrderStockIssuer.php` — one guarded observe call after the delivery stock-issue (nothing else changed).
- `SerialController.php` + route — the reconcile endpoint.
- `tests/Feature/Kernel/SerialSaleObserveTest.php`.

## Safety
The delivery path change is a single guarded, additive call that can't affect delivery. Rollback =
revert `OrderStockIssuer.php`.
