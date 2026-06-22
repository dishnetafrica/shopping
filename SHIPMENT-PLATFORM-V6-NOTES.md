# Shipment Platform v6 — Box-level custody (labels + QR scanning)

Moves custody from shipment-level counts to **per-box scanning**, DHL/FedEx style. No transport pricing.

## What's built

**Box records** — when a shipment is dispatched with a box count, one `shipment_boxes` row per box is
generated automatically: `SH-0001-B1 … SH-0001-BN`.

**Printable labels** — `🏷 Print box labels` on the shipment detail opens a print-ready sheet: one
label per box with shipment number, **BOX n / N**, route (cities only), customer name + phone, and a
**QR code** (the box code). 2-up, print-CSS, ready for a label printer or A4.

**Scan workflow (transport leg)** on the existing token pages — phone browser, no app:
- Transporter `/t/{token}` → **Scan boxes** → records each box → **Confirm receipt** (`received_by_transport`).
- Destination agent `/a/{token}` → **Scan boxes** → **Confirm arrival** (`arrived`).
- Uses the browser **BarcodeDetector** (Chrome/Android) for camera QR scanning, with a **type-the-code**
  fallback everywhere. Live `n / N boxes scanned` progress + per-box chips, haptic tick on each scan.

**Reconciliation — no manual counts.** The scanned count per stage feeds the existing reconciliation
engine, so missing/extra boxes are flagged on the **exact leg** automatically. Scan 4 of 5 at transport
→ "1 box missing between packed and received_by_transport". A box lost in transit stays flagged even
though the rest is delivered.

**Custody ledger.** Every scan is a row in `shipment_box_scans` (one per box per stage, idempotent —
re-scanning is safe). Each stage confirmation appends the custody event carrying the scanned count, so
the timeline and box ledger stay in sync.

## Files

**New**
- `database/migrations/2026_06_22_000006_create_shipment_boxes_table.php`
- `database/migrations/2026_06_22_000007_create_shipment_box_scans_table.php`
- `app/Models/ShipmentBox.php`, `app/Models/ShipmentBoxScan.php`
- `app/Services/Logistics/BoxCustodyService.php` — generate / scan / scannedCount / finalize.
- `qa/box_custody.php` — **14/14 green** (codes, idempotency, stage→action, scanned-count reconciliation).

**Edited**
- `app/Services/Logistics/ShipmentService.php` — generate boxes on dispatch + on creation.
- `app/Http/Controllers/Panel/ShipmentController.php` — `labels()` print sheet; box summary in the detail payload.
- `app/Http/Controllers/Panel/ShipmentTrackController.php` — scan + scan-confirm endpoints; scan card on the token pages (camera + manual); page JS moved to a nowdoc (`pageJs()`) with a scan mode and the original manual mode.
- `routes/web.php` — `/t|/a/{token}/scan` + `/scan-confirm` (CSRF already excludes `t/* a/*`); `papi/shipment-labels`.
- `resources/panel/seller.html` — Boxes card on the shipment detail (print labels + per-stage scan progress).

**Preview (not deployed)**
- `v6-box-labels-preview.html` — sample 3-box label sheet (QR via cdnjs), to eyeball before printing.

## Deploy

```bash
# GitHub → EasyPanel pull → restart, then:
php artisan migrate          # shipment_boxes + shipment_box_scans
php artisan optimize:clear   # new routes
```

The label page loads the QR library from cdnjs in the browser (no server dependency).

## QA

```
box_custody 14/14 · plus state-machine 26 · custody 21 · dashboard 21 · bridge 19 · journey 15 · track-ui 17 — all green
```

## Scope note — what's done vs next

- **Done now: the transport leg** (transporter + destination agent), which is where boxes actually go
  missing (shop → transport → agent). Labels, scanning, and box-level reconciliation are complete there.
- **Next (v6.1): last-mile scanning** — rider "collected" and "delivered" scans. The service already
  supports those stages (`collected_by_rider`, `delivered` in `BoxCustodyService::STAGES`, resolvable by
  the delivery token); only the rider-page (`/r/{token}`) scan UI + its two routes remain. Small, clean
  follow-on — say the word.
- BarcodeDetector covers Chrome/Android (what transporters use). On a browser without it, the manual
  code entry is the fallback; if you want iOS Safari camera scanning too, we can add a JS QR-decoder lib.
