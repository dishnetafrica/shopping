# Vision Reconciliation — Staging Verification Runbook

Prove the **real** Phase-0 Stock & Loss chain end-to-end on staging:

```
REAL HTTP → X-Vision-Token auth → VisionEventController → vision_events
          → VisionReconciler → real stock_movements → reconciliation result → persisted decision
```

The pure engine is proven (`php tests/Vision/reconciliation_harness.php`, 17/17). This runbook proves the
production Laravel path around it, which unit tests cannot.

> ⚠️ **Run this ONLY against a disposable / staging tenant. NEVER against a production tenant.**
> The harness writes synthetic ledger + event rows and (by default) deletes them. Pointing it at a real
> tenant is unnecessary and unsafe. Use a throwaway tenant id you are comfortable writing test rows into.

---

## 1. Prerequisites

- Staging app deployed with these merged (they are, as of the vision PRs) and the DB migrated:
  ```bash
  php artisan migrate --force        # adds recon_result/severity/review_required/reconciled_at + event_uid to vision_events
  ```
- A **disposable staging tenant id** (call it `<TID>`), and optionally a **second** tenant id
  (`<OTHER>`) to prove tenant isolation.
- The staging base URL (`https://<staging-host>`) reachable from the artisan host.
- No queue worker needed — reconciliation runs **synchronously** inside the webhook.

## 2. Enable reconciliation

Reconciliation is **OFF by default**. Turn it on in the staging environment:

```bash
# .env on the staging app
VISION_RECONCILE=true
```

The command refuses to run if this is off (it will not fabricate a pass).

## 3. Clear / reload config

`config/vision.php` is read through the config cache, so after changing `.env`:

```bash
php artisan config:clear      # or: php artisan config:cache
```

## 4. Tenant configuration

**The verification command sets test-only values on `<TID>` itself and cleans them up**, so you do NOT
need to configure the tenant by hand for the run. For reference, these are the per-tenant settings that
drive a real deployment (set via tinker for production):

| Setting | Example | Meaning |
|---|---|---|
| `vision_zone_location_map` | `{"D->EXIT":"juba"}` | instrumented boundary → `stock_movements.location` (**mandatory**; unmapped ⇒ `UNMAPPED_ZONE`, never red) |
| `vision_controlled_zones` | `["D->EXIT","X"]` | boundaries where an unreconciled crossing is a 🔴 |
| `vision_qty_tolerance` | `0` exact · omit for presence-based | how far vision qty may differ from the ledger before it's a discrepancy |

```php
// production example (NOT needed for the verify run)
$t = App\Models\Tenant::find(<TID>);
$s = $t->settings ?? [];
$s['vision_zone_location_map'] = ['D->EXIT' => 'juba'];
$s['vision_controlled_zones']  = ['D->EXIT'];
$t->settings = $s; $t->save();
```

## 5. Run the verification

```bash
php artisan vision:verify-reconcile --tenant=<TID> --url=https://<staging-host> --other-tenant=<OTHER>
```

- `--other-tenant` is optional; omit it and the tenant-isolation case reports **SKIP**.
- `--keep` leaves the synthetic rows in place for inspection (default is to clean up).

## 6. Expected PASS table

Every row should read `PASS` (the isolation row is `SKIP` if you omitted `--other-tenant`):

| Scenario | Expected result | Review |
|---|---|---|
| A · legitimate dispatch | `MATCH` | false |
| B1 · late paperwork — at crossing | `PENDING` | false |
| B2 · late paperwork +10 min → matches | `MATCH` | false |
| C1 · missing txn — at crossing | `PENDING` | false |
| C2 · grace expired | `UNRECONCILED` | **true** |
| D · camera offline | `COVERAGE_GAP` | false |
| E · duplicate delivery | one row, `duplicate=true` | — |
| X1 · unmapped zone | `UNMAPPED_ZONE` (**never red**) | false |
| X2 · inbound txn vs outbound crossing | `UNRECONCILED` (no false match) | true |
| X3 · two workers | `UNRECONCILED`, `attribution=undetermined` | true |
| X4 · quantity discrepancy (6 vs 4) | `QTY_DISCREPANCY` | true |
| X5 · shelf event | `recon_result = null` (untouched) | false |
| X6 · tenant isolation | `PENDING` (other tenant's txn not matched) | false |

Bottom line: `N passed, 0 failed`.

## 7. Interpreting a failure

| Failing case | What it means |
|---|---|
| A | The happy path is broken — matching txn at the location/window not being found (check `vision_zone_location_map`, the seeded location, or the match window). |
| B2 | **Regression of the 6–14 min bug** — a late-but-in-grace txn is not matching. The match window is not asymmetric; do not deploy. |
| C2 | The sweep is not escalating PENDING → UNRECONCILED after grace (check `vision:reconcile-sweep` schedule/logic). |
| D | **Serious** — a camera/health event produced something other than `COVERAGE_GAP`. Absence of observation must never be a movement/exception. |
| E | Idempotency broken — a re-delivered event created a second row/decision (check the `(tenant_id, event_uid)` unique index). |
| X1 | **Serious** — an unmapped zone became a red. Mapping is mandatory; a misconfig must never manufacture an exception. |
| X2 | Direction handling wrong — an inbound ledger row satisfied an outbound crossing. |
| X3 | Attribution is gating the decision — a low-confidence association must not blame a worker; movement must still be detected. |
| X4 | Quantity reconciliation not firing (did you set `vision_qty_tolerance=0`? the command does this for `<TID>`). |
| X5 | **Serious** — a shelf event entered the movement path. Shelf behaviour must be untouched. |
| X6 | **Serious** — cross-tenant leak; a different tenant's transaction was matched. |

Any `FAIL` is a real defect caught before a camera exists. Do not proceed to the review screen until the
table is green.

## 8. Cleaning up the synthetic data

The command cleans up automatically on exit (unless `--keep`). To verify nothing is left, or to clean up
after a `--keep` run:

```php
// tinker — synthetic ledger rows (tagged) and verify events
App\Models\Tenant::find(<TID>);
DB::table('stock_movements')->where('tenant_id', <TID>)->where('note', 'vision-verify')->delete();
App\Models\VisionEvent::withoutGlobalScopes()->where('tenant_id', <TID>)->where('event_uid', 'like', 'verify-%')->delete();
App\Models\VisionBox::where('box_uid', 'verify-box-'.<TID>)->delete();
```

All synthetic ledger rows use `location='VERIFY-DOCK'`, `sku='VERIFY-SKU'`, `product_id=NULL` — they are
attached to **no real product**, so real on-hand is never affected even if cleanup is skipped.

## 9. Confirming shelf events were not affected

Two checks:

1. **Case X5** in the table asserts a `shelf.out_of_stock` event persists with `recon_result = null` — i.e.
   shelf events never enter the movement path even with reconciliation ON.
2. **Flag-off no-op** (manual): set `VISION_RECONCILE=false`, `php artisan config:clear`, POST a real shelf
   event as your gateway normally does, and confirm it is stored exactly as before (no `recon_*` fields).
   Optionally re-run the verify command — it will refuse to run, confirming the gate is off.

## 10. Scope reminder

This gate proves the **ShopBot-side** chain only. The edge CV pipeline (detector → Supervision → ByteTrack
→ LineZone → `carton.move`) is deliberately **not** built yet and must live in a **separate Python service**,
never in this Laravel repo. Progression: **engine → real DB (this gate) → review screen → camera → Supervision
→ real warehouse.**
