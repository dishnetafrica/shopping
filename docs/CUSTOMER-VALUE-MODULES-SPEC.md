# CloudBSS — Customer-Value Modules: Feature Specification

Specs for the five roadmap modules. Each gives database schema, APIs, admin pages,
customer experience, WhatsApp flow, and reporting. Grounded in the existing
Laravel 11 / Filament / Postgres / Redis system — each spec marks what **exists**
vs what's **new**, and an **MVP** line to fight scope creep.

A note on the goal you set (revenue, retention, efficiency — not technical
completeness): I've re-ordered your list by **value ÷ effort** and **dependencies**.
The recommendation and rationale are in §0. The numbering in §1–§5 follows that
recommendation, not the original list.

---

## 0. Prioritization by business value

| Module | Primary lever | Direct $ | Effort | Depends on | What already exists |
|---|---|---|---|---|---|
| **Customer Reorder** | retention + revenue | **High** | **Low** | order history (have it) | nothing — greenfield, but data is there |
| **Customer CRM** | enabler (retention) | Indirect | Med | — | `CustomerProfile`, `AudienceResolver` segments |
| **Marketing V2** | revenue | **High** | Med | CRM segments | `Campaign`, `SendCampaign`, `CampaignMessage`, audiences |
| **Delivery Mgmt V2** | efficiency + retention | Med | Med-High | Riders/Dispatch (have) | `Rider`, `Branch`, Dispatch, `track_token`, `TrackController`, `delivered_at` |
| **Loyalty & Rewards** | retention capstone | Med | **High** | CRM + reorder + marketing | nothing — greenfield |

**Recommended sequence (and why it differs from your order):**

1. **Customer Reorder first.** Grocery is repeat-purchase by nature. The data
   already exists in `orders`; the build is small; it adds revenue and retention
   immediately and reduces bot friction. Highest value-per-effort — ship it first
   as a quick win during/after the pilot.
2. **Customer CRM (lite) second.** It's the foundation the next three lean on
   (segments, lifetime value, last-order date drive reorder targeting, marketing
   audiences, and loyalty tiers). Build only what those need — not a full CRM.
3. **Marketing Campaigns V2 third.** Turns CRM segments into money: abandoned-cart
   nudges and win-back are the two highest-ROI flows in commerce. Needs CRM segments.
4. **Delivery Management V2 fourth.** Mostly an enhancement of the working Dispatch
   flow (zones, fees, ETA, proof-of-delivery, rider cash reconciliation). Big
   efficiency win, but the base already functions, so it's less urgent than new
   revenue.
5. **Loyalty & Rewards last.** Highest effort, easiest to get wrong (liability,
   gaming), and most valuable *after* you can see — via CRM + reorder + marketing —
   who your repeat customers actually are.

If you'd rather keep delivery earlier (e.g. delivery quality is hurting churn in the
pilot data), that's a reasonable swap — the pilot report should settle it.

---

## 1. Customer Reorder  *(greenfield; data exists)*
**Value:** one-tap repeat purchase → more orders, higher retention, less bot work.

### Database schema
Mostly reuses `orders` / `order_items`. New, small:
```
reorder_subscriptions            -- optional "auto-reorder every N days" (MVP can skip)
  id, tenant_id, customer_phone, source_order_id,
  cadence_days (int), next_run_at (timestamp), active (bool),
  last_order_id (nullable), created_at, updated_at
  index (tenant_id, next_run_at, active)
```
Add to `orders`: `reordered_from_id` (nullable FK) — provenance/analytics.

### APIs
- `GET  /panel/reorder/suggestions?phone=` — last order + most-frequent basket.
- `POST /panel/reorder/place` — build a cart from a prior order, re-price against
  **current** prices/stock, create a new order (reuses checkout + order idempotency).
- `POST /panel/reorder/subscription` — create/update/cancel a cadence (later phase).
Bot side: a `reorder` intent in `BotBrain` (keyword/NLU) → suggestion → confirm.

### Admin pages
- Order view: **"Reorder for customer"** button (owner re-places on a customer's behalf).
- (Phase 2) **Subscriptions** tab: list active auto-reorders, next run, pause/cancel.

### Customer experience
Customer types "reorder" (or taps a button in the post-delivery message). Bot shows
the last order, re-priced to today, and asks to confirm. Out-of-stock items are
flagged and skipped, not silently dropped.

### WhatsApp flow
```
Customer: reorder
Bot:      Your last order (12 Jun): 2x Sugar 1kg, 1x Cooking Oil 1L, 3x Bread = UGX 14,200
          (Cooking Oil 1L is out of stock — I'll skip it: new total UGX 11,700)
          Reply *yes* to place it, or tell me what to change.
Customer: yes
Bot:      📍 Send your delivery location (or *same as last time*).
Customer: same as last time
Bot:      ✅ Order FS-48 placed. We'll confirm and dispatch shortly.
```
Re-pricing + stock check + idempotent order creation reuse existing checkout.

### Reporting
Reorder rate (% of orders that are reorders), reorder revenue, repeat-customer
count, avg days between orders, top reordered baskets, subscription churn.

**MVP:** "reorder" intent + re-price + confirm + place (no subscriptions). Subscriptions are phase 2.

---

## 2. Customer CRM (lite)  *(extends `CustomerProfile`)*
**Value:** the segmentation + lifetime-value foundation every other module needs.
Build only what reorder/marketing/loyalty consume — not a full CRM.

### Database schema
Extend `customer_profiles` (exists: phone, name, alt_phone, email, address, lang,
greeting, notes). Add:
```
customer_profiles  + tags (jsonb)            -- ['vip','wholesale','no-promo']
                   + first_order_at, last_order_at (timestamp)
                   + orders_count (int), total_spent (bigint)   -- rolled up
                   + avg_basket (int), preferred_branch_id (nullable)
                   + marketing_opt_out (bool, default false)
                   + rfm_score (string, nullable)               -- e.g. '545'
customer_events                              -- lightweight timeline
  id, tenant_id, customer_phone, type (order|message|note|status|campaign),
  ref_id, summary, created_at
  index (tenant_id, customer_phone, created_at)
```
Rollups (`orders_count`, `total_spent`, `last_order_at`, RFM) recomputed by a
scheduled job nightly + on order create (cheap, via `OrderObserver`).

### APIs
- `GET  /panel/customers?segment=&search=&tag=` — list with rollups + filters.
- `GET  /panel/customers/{phone}` — profile + timeline + orders + LTV.
- `POST /panel/customers/{phone}` — edit name/tags/notes/opt-out.
- `GET  /panel/segments` — counts per segment (reuses `AudienceResolver`,
  extended with RFM/tag-based segments).

### Admin pages
- **Customers** tab (exists) → upgrade to: searchable list with LTV, last order,
  tags, segment badges; **Customer 360** drawer (profile, timeline, orders, spend,
  reorder button, opt-out toggle).
- **Segments** view: VIP / recent / inactive / lapsed / category buyers + custom
  tag segments, with live counts and "send campaign to this segment".

### Customer experience
Mostly invisible — drives better targeting and personalization (greeting by name,
preferred branch, language). Customers can opt out of promos ("stop"/"unsubscribe").

### WhatsApp flow
```
Customer: stop
Bot:      Done — you won't get promotions from <Shop>. You'll still get order updates.
          Reply *start* anytime to opt back in.
```
("stop"/"unsubscribe" sets `marketing_opt_out`; honored by Marketing V2.)

### Reporting
LTV distribution, new vs returning, segment sizes over time, churn (lapsed count),
top customers, opt-out rate.

**MVP:** rollup fields + tags + opt-out + Customer 360 + segment counts. Skip full
event timeline first if time-boxed (keep order events only).

---

## 3. Marketing Campaigns V2  *(extends `Campaign` / `SendCampaign`)*
**Value:** direct revenue — abandoned-cart and win-back are the two highest-ROI flows.

### Database schema
Extend `campaigns` (exists) + reuse `campaign_messages` (exists, idempotent). Add:
```
campaigns          + segment_json (jsonb)        -- richer than single 'audience'
                   + variant_json (jsonb)         -- A/B copy/image variants
                   + trigger (string, nullable)   -- manual|abandoned_cart|winback|post_delivery
                   + throttle_min, throttle_max (int)   -- per-tenant send spacing
campaign_messages  + variant (string), delivered_at, read_at, clicked_at, converted_order_id
                   (extends the existing per-recipient table; keep the unique index)
automations                                   -- trigger rules
  id, tenant_id, name, trigger, segment_json, template_json,
  delay_minutes (int), active (bool), created_at, updated_at
```

### APIs
- `GET/POST /panel/campaigns` — CRUD (exists) + segment + variants + schedule.
- `POST /panel/campaigns/{id}/test` — send to the owner only.
- `GET  /panel/campaigns/{id}/stats` — sent/delivered/read/clicked/converted + revenue.
- `GET/POST /panel/automations` — create/toggle abandoned-cart / win-back / post-delivery rules.
Hooks: `BotBrain` emits "cart abandoned" (no checkout after N min) and "order
delivered" events that automations subscribe to.

### Admin pages
- **Marketing** tab (exists) → add: segment picker (from CRM), A/B variants, test
  send, scheduling, and a **per-campaign analytics** view (funnel + attributed
  revenue).
- **Automations** sub-page: toggle prebuilt flows (Abandoned Cart, Win-back 30/60
  days, Post-delivery thank-you + reorder nudge) with editable copy + delay.

### Customer experience
Relevant, throttled messages: a nudge if they left a cart, a win-back offer if
lapsed, a thank-you + reorder link after delivery. Always honors `marketing_opt_out`
and the ban-safety throttle.

### WhatsApp flow (abandoned cart automation)
```
[customer added items but didn't checkout for 30 min]
Bot:  You left 2x Sugar and 1x Oil in your basket 🛒 — still want them?
      Reply *checkout* to finish, or *clear* to drop it.
```
(Win-back) `We miss you! 10% off your next order this week — reply *order* to start.`

### Reporting
Per campaign + automation: sent, delivered, read, click-through, **conversions +
attributed revenue**, opt-outs caused, best variant. Channel health (ban-risk
signals): send rate, failure rate.

**MVP:** segment-targeted broadcast + test send + delivered/sent stats + the
**abandoned-cart** automation (single highest-ROI trigger). A/B + full funnel later.

---

## 4. Delivery Management V2  *(extends Dispatch / `Rider` / `Branch`)*
**Value:** operational efficiency + delivery-quality retention. Base Dispatch,
riders, tracking link, and `delivered_at` already work — this is enhancement.

### Database schema
```
delivery_zones
  id, tenant_id, name, polygon_json (or center_lat/lng + radius_m),
  base_fee, per_km_fee, min_fee, free_over, eta_minutes, active
  index (tenant_id, active)
deliveries                                  -- one per dispatched order (1:1)
  id, tenant_id, order_id, rider_id, zone_id (nullable),
  status (assigned|picked|out|delivered|failed),
  assigned_at, picked_at, delivered_at, failed_reason,
  distance_km, fee, proof_photo_url, recipient_name, cod_amount, cod_collected (bool)
  index (tenant_id, rider_id, status)
rider_ledger                                -- COD reconciliation
  id, tenant_id, rider_id, delivery_id, amount, type (cod_in|settled), created_at
```
Add to `orders`: `delivery_fee`, `delivery_zone_id`, `eta_at`.

### APIs
- `GET  /panel/delivery/board` — kanban of deliveries by status.
- `POST /panel/delivery/assign` — assign rider (auto-suggest nearest active rider).
- `POST /panel/delivery/{id}/status` — picked/out/delivered/failed (+ proof photo, COD).
- `GET/POST /panel/delivery/zones` — zone CRUD + fee rules.
- `GET  /panel/riders/{id}/ledger` — COD owed vs settled.
Customer-facing: `TrackController` (exists) extended with live status + ETA + rider.

### Admin pages
- **Dispatch** tab (exists) → **Delivery board** (kanban), zone manager, auto-assign,
  proof-of-delivery capture, **rider COD reconciliation** ledger.
- **Riders** tab (exists) → add today's load, COD owed, performance (on-time %).

### Customer experience
Accurate delivery fee shown at checkout (from zone + distance), an ETA, the live
tracking link (exists), rider name/number, and a delivery confirmation.

### WhatsApp flow
```
Bot:  📍 Got it. Delivery to Kisaasi (Zone B): fee UGX 3,000, ETA ~35 min.
      Total with delivery: UGX 14,700. Reply *yes* to confirm.
...
Bot:  🛵 <Rider> is on the way! Track live: <link>  ·  Rider: +256…
Bot:  ✅ Delivered. Thanks! Reply *reorder* anytime to repeat this order.
```

### Reporting
On-time delivery %, avg delivery time per zone, failed-delivery rate + reasons,
delivery cost vs fee collected (margin), rider productivity + COD outstanding.

**MVP:** zones + delivery fee at checkout + delivery board + proof-of-delivery.
COD reconciliation and auto-assign-nearest are phase 2.

---

## 5. Loyalty & Rewards  *(greenfield; build last)*
**Value:** retention capstone. Highest effort + risk; most useful once CRM + reorder
+ marketing data exist. Keep the first version dead simple (points or stamps).

### Database schema
```
loyalty_programs
  id, tenant_id, type (points|stamps), active,
  earn_rate (points per UGX) | stamps_target (e.g. 10),
  reward_json (e.g. {type:'discount', value:5000} or {type:'free_item', product_id}),
  expiry_days (nullable), created_at, updated_at
loyalty_accounts
  id, tenant_id, customer_phone, balance (int), lifetime_earned (int),
  tier (nullable), updated_at
  unique (tenant_id, customer_phone)
loyalty_transactions                         -- audit, idempotent
  id, tenant_id, customer_phone, order_id (nullable), delta (int),
  reason (earn|redeem|expire|adjust), idempotency_key (unique), created_at
```

### APIs
- `GET  /panel/loyalty/program` / `POST` — configure program + rewards + tiers.
- `GET  /panel/loyalty/{phone}` — balance + history.
- `POST /panel/loyalty/adjust` — manual credit/debit (audited).
Hooks: on order **Delivered**, an idempotent `earn` transaction (uses an
idempotency_key like the order key); redemption applied at checkout.

### Admin pages
- **Loyalty** tab: program setup (points vs stamps, earn rate, rewards, tiers,
  expiry), member list with balances, manual adjustments (audited).

### Customer experience
Earn automatically on completed orders; see balance on request; redeem at checkout.
Stamp cards ("buy 10 get 1") are simplest and very effective for grocery.

### WhatsApp flow
```
Customer: points
Bot:      You have 1,250 points (≈ UGX 1,250 off). Reply *redeem* at checkout to use them.
...
[at checkout] Bot: You have 1,250 points. Reply *redeem* to take UGX 1,250 off, or *no*.
Customer: redeem
Bot:      Applied! New total UGX 10,450.
```

### Reporting
Active members, points outstanding (**liability**), redemption rate, repeat-rate of
members vs non-members, reward cost vs incremental revenue, tier distribution.

**MVP:** one program type (stamps OR points), auto-earn on delivery, redeem at
checkout, balance on request. Tiers + expiry + multiple rewards later.

---

## Cross-cutting (applies to all modules)
- **Multi-tenant + plan gating:** every table carries `tenant_id`; gate features by
  plan (`config/plans.php`) — e.g. loyalty/automations = Pro.
- **Idempotency:** any money/points/order side-effect uses the existing idempotency
  pattern (`app/Support/Idempotency.php`) — no double-earn, double-charge, double-send.
- **Opt-out + ban-safety:** all outbound marketing honors `marketing_opt_out` and the
  send throttle.
- **Reuse, don't rebuild:** extend `CustomerProfile`, `Campaign`, `Rider`, `Order`,
  `AudienceResolver`, `TrackController` rather than new parallel systems.
- **Frozen until you say go:** Cart Edit Engine is still the other open item; none of
  these specs assume it. Pilot data should confirm whether reorder or cart-edit is
  the bigger pain.

## Suggested phasing
- **Phase A (quick revenue):** Reorder MVP.
- **Phase B (foundation):** CRM lite rollups + segments + opt-out.
- **Phase C (revenue engine):** Marketing V2 segment broadcast + abandoned-cart automation.
- **Phase D (efficiency):** Delivery V2 zones + fees + board + proof-of-delivery.
- **Phase E (retention capstone):** Loyalty stamps/points MVP.

Each phase is independently shippable and independently measurable against
revenue / retention / efficiency — so we can stop or re-order after any phase based
on what the numbers say.
