# Shipment Platform v5 — Customer tracking experience

Polishes the customer page (`/papi/track?o=&t=`) into something that feels like a real logistics
tracker — all on the existing link, still leaking zero internal ops data.

## What's new

1. **Rail timeline** — ✓ completed · ● current · ○ upcoming, on a connected green rail:
   ✓ Packed → ✓ In transit → ● Arrived in Juba → ○ Out for delivery → ○ Delivered.
2. **Estimated delivery** — a clean ETA line, labelled **Estimated arrival** before arrival and
   **Expected delivery** after. Shown **only when the shop has actually set an ETA** (delivery/order
   `eta_at`) — never a fabricated date. Hidden once delivered.
3. **Route** — `🚌 Kampala → Juba` as its own pill. Cities only; transporter/bus/driver stay hidden.
4. **Rider info gated** — name, photo, phone (+ live location) appear **only after the rider has
   collected** (delivery `picked`/`out`), not while merely assigned, and disappear once delivered.
5. **Delivery proof** — once delivered: a **"✓ Delivered successfully"** card with the delivery time
   and proof photo (if the rider captured one).
6. **Mobile-first** — tighter padding/margins and tap targets under 480px (these links open from
   WhatsApp on phones).

## Customer-safe (unchanged principle)

No box counts, no exceptions, no transporter/driver detail, no internal statuses — the customer sees
friendly stages only. Exactly the "professional platform without exposing operational data" goal.

## Files

**Edited**
- `app/Http/Controllers/Panel/TrackController.php` — fetches the delivery once; computes `collected` /
  `delivered` flags; rail timeline; route + ETA blocks; gated rider card; delivery-proof card; mobile CSS.

**New**
- `qa/customer_track_ui.php` — proves rider gating, proof visibility, ETA label/visibility, timeline
  classes/icons. **17/17 green.**

**Preview (not deployed)**
- `v5-customer-track-preview.html` — the three states rendered with the shipped CSS, for eyeballing.

## Deploy

```bash
# GitHub → EasyPanel pull → restart.
```

No migration, no new route — same customer track link.

## Honest note on ETA

The ETA line shows whatever `eta_at` the shop already has (set at quote/dispatch). There is **no stored
transport-arrival ETA**, so for the pure transit leg it stays hidden rather than guessing. If you want a
guaranteed "estimated arrival" date for cross-city shipments, the clean add is a shop-settable
`eta_at` on the shipment (entered at dispatch) — say the word and I'll wire it in.
