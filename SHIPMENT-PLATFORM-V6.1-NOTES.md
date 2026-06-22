# Shipment Platform v6.1 — Box-scan operational usability

Polishes the v6 transport-leg scanning so loading/unloading is harder to get wrong. No notifications,
no messaging — purely the scanning UX + paper backup.

## What's new

**1. Batch scan summary (big tiles)** — on the scan page and the panel: **Expected · Scanned · Missing**
(and **Extra** if it ever occurs), large and colour-coded. The operator sees at a glance whether the
load is complete.

**2. Big scan-progress UI** — a **`3 / 5`** headline with a **progress bar**, mobile-sized, updating live
as each box is scanned (camera or typed). Haptic tick per scan stays.

**3. Missing box view** — the exact codes still to scan, e.g. **`SH-0007-B4, SH-0007-B5`**, shown right on
the scan card and the panel. No guessing which box is off the truck.

**4. Printable manifest** — `📋 Print manifest`: an A4 tick-sheet with shipment number, origin,
destination, customer, a **numbered box list with check-boxes**, a "Boxes received ___ / N" line, and
loaded-by / received-by signature lines. This is the paper fallback that rides with the shipment —
separate from the stick-on QR labels.

## How "Missing" / "Extra" are computed (honest)

At a scanning stage: **Missing = Expected − Scanned**, and the missing **codes** are the boxes with no
scan yet — fully actionable. **Extra** is structurally 0 while scanning (unknown/foreign codes are
rejected, a box can't scan twice); it only ever shows if reconciliation flags a downstream leg holding
*more* than an upstream one. So in normal scanning you'll see Expected/Scanned/Missing move and Extra
stay hidden — that's correct, not a gap.

## Files

**Edited**
- `app/Services/Logistics/BoxCustodyService.php` — `missingBoxes()` + `summary()` (expected/scanned/missing/extra + missing codes).
- `app/Http/Controllers/Panel/ShipmentTrackController.php` — scan card now has the big progress bar, tiles and missing-codes list; scan/confirm responses carry the summary; JS updates it live.
- `app/Http/Controllers/Panel/ShipmentController.php` — `manifest()` print sheet; batch summary (furthest scanned stage) added to the detail payload.
- `routes/web.php` — `papi/shipment-manifest`.
- `resources/panel/seller.html` — Boxes card now shows the summary tiles, missing codes, and a Print-manifest button.

**New**
- `qa/box_summary.php` — **13/13 green** (counts, missing codes incl. the B4/B7 shape, dedupe, progress %, furthest-stage pick).

**Previews (not deployed)**
- `v6.1-scan-card-preview.html` — the upgraded mobile scan card.
- `v6.1-manifest-preview.html` — the printable manifest.

## Deploy

```bash
# GitHub → EasyPanel pull → restart, then:
php artisan optimize:clear   # new manifest route
```

No migration (reuses the v6 tables).

## QA

```
box_summary 13/13 · box_custody 14/14 · plus state-machine 26 · custody 21 · dashboard 21 · bridge 19 · journey 15 · track-ui 17 — all green
```

## Still open (unchanged from v6)

Last-mile box scanning (rider **collected** / **delivered**) remains the one functional gap — the service
supports those stages; only the rider-page scan UI + two routes remain. Say the word for that v6.2.
