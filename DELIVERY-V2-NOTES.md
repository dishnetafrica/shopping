# Delivery Management V2 — D1 + D2 (shippable operational workflow)

Zones + fee + ETA (D1) and the deliveries lifecycle: board, rider assignment, rider
notifications, status machine (D2). Decisions implemented as approved.

## Files
- migrations: 000008 delivery_zones, 000009 deliveries, 000010 orders.+delivery_fee/zone/eta
- models: DeliveryZone, Delivery (status consts + relations), Order (+delivery fields)
- services/Delivery: ZoneResolver (match/fee/ETA — pure core), DeliveryStatus (state
  machine + order-status mapping), RiderAssigner (least-loaded suggestion),
  DeliveryService (assign/advance + rider WhatsApp)
- Http/Panel/DeliveryController + routes (papi): delivery/quote, board, suggest-rider, assign, status
- Filament: DeliveryZoneResource (Settings -> Delivery Zones), DeliveryResource (Delivery Board)
- BotBrain::placeOrder — computes zone fee + ETA at checkout, persists them, shows fee+ETA
  in the confirmation (NO extra confirmation step, as decided)

## Behaviour
- Zone match: area keyword in location text -> pin in center+radius -> tenant distance
  fallback. Fee: flat + optional per-km + min + free-over. ETA per zone (default 45m).
- Checkout shows: "Zone B · delivery UGX 3,000 · ETA ~35 min" + grand total.
- Assign a rider (manual; suggest-rider returns least-loaded active rider / zone default)
  -> creates the delivery (status assigned), order -> Confirmed, **rider gets a WhatsApp**
  with drop, customer, items, and COD to collect.
- Lifecycle: assigned -> picked -> out -> delivered/failed (guarded). "out" sets the
  order to Out for delivery (existing customer "on the way" + tracking link); "delivered"
  sets Delivered (existing delivered_at stamp + customer notification).
- COD amount captured per delivery (full reconciliation = D4). Proof columns
  (photo/recipient) exist on the table for D3.

## Verified here — 31/31 (qa/delivery_v2_suite.php)
zone match (keyword/radius/fallback), fee calc (flat/min/free-over/per-km/fallback), ETA,
status machine (valid + blocked transitions + order-status mapping), least-loaded rider
suggestion, and assign/advance against real SQLite (unique order_id -> reassign updates
the same delivery; lifecycle guard holds). No regression: brain 63/63, final 25/25, intent 47/47.

## Staging-verify (cannot run here — no Laravel/WhatsApp/maps)
- Filament Delivery Zones CRUD + Delivery Board (assign/advance actions).
- Live checkout shows the right fee/ETA; rider receives the WhatsApp on assignment;
  "out"/"delivered" fire the existing customer notifications.
- run migrate; set per-tenant fallback settings (delivery_base/per_km/min/free_over) if used.

## Board surface note
The board ships as the Filament "Delivery Board" (/app). The same data is exposed via
papi/delivery/board so it can be embedded into the seller.html Dispatch tab as a fast
follow (best done on staging to test the JS). Say if you want it in seller.html now.

## Not in D1/D2 (next / parked)
D3 proof of delivery (photo + recipient — columns ready), D4 COD reconciliation
(rider_ledger + settlement), D5 analytics. Store Pickup added to ROADMAP (future).

Deploy: php artisan migrate --force && php artisan optimize:clear
