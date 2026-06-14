# CloudBSS — Delivery Management V2: Specification & Implementation Plan

Goal: improve daily store operations and make CloudBSS easier to sell to
supermarkets, pharmacies, wholesalers, and retailers. Plan for approval — **no code
until you sign off.** Each section marks **exists** vs **new**, with an **MVP** line.

---

## 0. What already exists (V2 extends, doesn't rebuild)
- **Rider** model (name, phone, active, city, address, docs; commission fields
  `comm_pct/comm_min/comm_max` already live in `rider.profile`).
- **Branch** model (name, phone, address, **lat/lng**).
- **Dispatch** (`PanelApiController::dispatch`): assign rider (find-or-create), set
  status "Out for delivery", mint `track_token`, return a tracking URL.
- **Tracking page** (`TrackController`, public, token-gated) — shows status (basic).
- **Distance fee** — tenant-level base/per-km/min/round/free-over from the store pin;
  currently computed **client-side** and folded into the order total (not persisted
  as its own field).
- `OrderObserver` stamps `delivered_at` on the Delivered transition. Dispatch/tracking
  are **Pro** plan features.

**The gaps V2 fills:** no zones, fee isn't server-authoritative or persisted, no
delivery lifecycle record, no board, no proof of delivery, no COD reconciliation, no
delivery analytics.

---

## 1. Decisions needed before coding (recommendations in **bold**)
1. **Zone matching:** **center + radius (for map pins) plus area-name keywords (for
   text locations like "Kisaasi"), with the existing tenant distance-rule as
   fallback.** Skip true GeoJSON polygons for MVP (heavier, rarely needed in Kampala).
2. **Fee model per zone:** **flat fee per zone + optional per-km on top + free-over +
   ETA.** Most supermarkets/pharmacies use flat zone fees; keep distance-based as the
   fallback when no zone matches.
3. **Rider assignment (MVP):** **manual assign + "suggest least-loaded active rider"
   (and/or the rider tied to the matched zone).** True GPS "nearest rider" is **not
   possible without rider location** — that needs a rider app and is a later phase.
4. **Proof of delivery capture:** **staff capture in the delivery board (photo +
   recipient + COD) AND a tokenized rider link** (no login, like the customer track
   link) so a rider can mark picked/delivered + upload a photo from their phone.
5. **Checkout fee display (MVP):** **compute the fee + ETA server-side and show them
   in the order-confirmation message + owner/recipient notification** — without adding
   a new "confirm the fee" step to the deterministic brain (keeps the brain frozen-safe).
   A fee-confirmation step can come later.

If you just say "go", I'll proceed with all five bolded defaults.

---

## 2. Database schema (new)
```
delivery_zones
  id, tenant_id, name, active,
  match_keywords (jsonb)        -- ['kisaasi','kyanja','ntinda']  (text-location match)
  center_lat, center_lng, radius_m (nullable)   -- pin match
  flat_fee (int), per_km_fee (int, nullable), min_fee (int), free_over (int, nullable),
  eta_minutes (int),
  default_rider_id (nullable FK riders),
  created_at, updated_at
  index (tenant_id, active)

deliveries                      -- one per dispatched order (1:1)
  id, tenant_id, order_id (unique FK), rider_id (nullable FK), zone_id (nullable FK),
  status (assigned|picked|out|delivered|failed),
  fee (int), distance_km (numeric, nullable), eta_at (timestamp, nullable),
  assigned_at, picked_at, out_at, delivered_at, failed_at, failed_reason,
  proof_photo_url (nullable), recipient_name (nullable),
  cod_amount (int, default 0), cod_collected (bool, default false),
  rider_token (string)          -- tokenized rider action link
  created_at, updated_at
  index (tenant_id, status), index (tenant_id, rider_id, status)

rider_ledger                    -- COD reconciliation + (later) commission
  id, tenant_id, rider_id, delivery_id (nullable FK), type (cod_in|settled|commission|adjust),
  amount (int), note (nullable), created_at
  index (tenant_id, rider_id, created_at)
```
Add to `orders`: `delivery_fee` (int), `delivery_zone_id` (nullable FK), `eta_at`
(nullable). (Persist the fee that today is only client-side.)

Tenant isolation via `BelongsToTenant` on every model. Idempotency for any
money/COD/notification side-effect reuses `app/Support/Idempotency.php` + the ledger
pattern proven in Order Notifications.

---

## 3. APIs (panel JSON, same style as PanelApiController)
**Zones & fees**
- `GET/POST /panel/delivery/zones` — zone CRUD (fees, ETA, keywords, radius, default rider).
- `GET  /panel/delivery/quote?location=&lat=&lng=` — resolve zone + return fee + ETA
  (server-authoritative; used at checkout and in the panel).

**Board & assignment**
- `GET  /panel/delivery/board` — deliveries grouped by status (kanban feed).
- `POST /panel/delivery/assign` — assign/replace rider (suggests least-loaded).
- `POST /panel/delivery/{id}/status` — picked | out | delivered | failed (+ reason).
- `POST /panel/delivery/{id}/proof` — upload photo + recipient name.

**Rider link (tokenized, no login)**
- `GET  /papi/rider?d={id}&t={rider_token}` — rider's delivery card (address, items,
  COD, customer).
- `POST /papi/rider/status` — rider marks picked/out/delivered + photo + COD collected.

**COD reconciliation**
- `GET  /panel/riders/{id}/ledger` — COD owed vs settled, running balance.
- `POST /panel/riders/{id}/settle` — record a cash settlement (writes `settled`).

Existing `dispatch` is refactored to create the `deliveries` row + compute fee/ETA +
notify the rider; existing `riders`/`riderSave` stay.

---

## 4. Admin pages (seller panel + Filament)
- **Dispatch → Delivery Board** (seller.html): kanban columns Assigned · Picked · Out ·
  Delivered · Failed; cards show order, customer, zone, fee, ETA, rider; actions:
  assign/replace rider, advance status, capture proof, mark failed (reason).
- **Setup → Delivery Zones** (Filament resource, Settings group): zone CRUD — name,
  area keywords, center+radius, fees, ETA, default rider, active. (Pattern = the
  Order Notifications / Smart Defaults resources.)
- **Riders** (exists) → add: today's load, on-time %, **COD outstanding**, settle button
  + ledger view.
- **Reports → Delivery** (analytics, §6).

All Pro-gated (matches existing dispatch/tracking gating).

---

## 5. WhatsApp flows
**Checkout (MVP — no new brain step):** after the customer sends location, the order
confirmation shows the resolved fee + ETA:
```
✅ Order FS-1048 received!
2 × Sugar 1KG, 1 × Rice 5KG
📍 Kisaasi (Zone B) · Delivery UGX 3,000 · ETA ~35 min
Total: UGX 14,700
We'll confirm and dispatch shortly.
```
**Rider on assignment (new):** the rider's own WhatsApp gets the job:
```
🛵 New delivery — FS-1048
Pick up: <store/branch>
Drop: Kisaasi — <customer>, +2567…
Items: 2 × Sugar 1KG, 1 × Rice 5KG
Collect (COD): UGX 14,700
Open: <rider link>
```
**Customer on dispatch (extends existing):** "on the way" + rider name/number + live
track link + ETA. **On delivered:** confirmation. (Reorder nudge stays frozen.)

---

## 6. Reporting
- **On-time delivery %** (delivered_at vs eta_at) overall + per zone.
- **Avg delivery time** (assigned→delivered) per zone/rider.
- **Failed-delivery rate** + top reasons.
- **Delivery margin** — fees collected vs delivery cost (rider commission/COD).
- **Rider productivity** — deliveries/day, on-time %, COD outstanding, settlement history.
- **Zone demand** — orders + revenue by zone (where to add riders/coverage).

---

## 7. Phased implementation (each independently shippable + measurable)
- **D1 — Zones + authoritative fee** *(highest daily value; sales hook)*
  `delivery_zones` + `orders.delivery_fee/zone/eta` + `/delivery/quote` + zone resolver
  + show fee/ETA in confirmation & notifications. Unit-testable here: zone match
  (keyword + radius), fee calc (flat + per-km + free-over + min), fallback to tenant rule.
- **D2 — Deliveries lifecycle + board + rider assign + rider notify**
  `deliveries` table, refactor dispatch, board feed, status transitions, least-loaded
  suggest, rider WhatsApp. Testable: status machine, assignment suggestion, idempotent dispatch.
- **D3 — Proof of delivery + rider tokenized link**
  photo/recipient capture (staff + rider link). Testable: token auth logic, state guard.
- **D4 — COD reconciliation**
  `rider_ledger`, COD collected on delivery, settlement, outstanding balance. Testable:
  ledger math (owed/settled/balance), idempotent settlement. (Commission payout optional sub-phase.)
- **D5 — Delivery analytics**
  the §6 metrics + Reports → Delivery page. Testable: metric calculations on a fixture set.

**Recommended first cut to ship + sell:** **D1 + D2** (accurate zone fees + a real
delivery board with rider assignment) — that's the demo that wins supermarkets/
pharmacies. D3–D5 follow.

---

## 8. What I can prove in the sandbox vs staging
- **Provable here (real, no fabrication):** zone matching (keyword + point-in-radius via
  haversine), fee calculation, status-machine transitions, assignment suggestion logic,
  COD ledger math, rider-token auth, all against pure functions / real SQLite with the
  exact indexes — same approach as Order Notifications (24/24).
- **Staging-only (no Laravel/WhatsApp/maps here):** the Filament zone resource, the
  board UI, live WhatsApp to riders/customers, actual photo upload, and map-pin capture.
  I'll deliver those lint-clean with a staging checklist.

---

## 9. Frozen (untouched — per your freeze)
Auto-pick ranking, store learning, customer learning, guided defaults page, cart edit
engine, reorder, vector search, AI metering, notification templates. None are touched
by this work.

---

### Approve to proceed
Say **"go"** for the five bolded defaults in §1 and I'll start with **D1** (zones +
authoritative fee), delivered + tested the same way as Order Notifications, then stop
for your review before D2.
