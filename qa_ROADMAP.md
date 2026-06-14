# CloudBSS — Roadmap (deferred, not built)

What's **built now** (Phase 2 friction reducer):
- Owner-set **default SKU per term** (explicit strategy, platform default).
- **Size-aware** resolution: a stated size wins; an unstocked/ambiguous size
  clarifies; single-SKU quantity behaviour preserved.
- **Size hint shown once** per conversation.
- Admin: Smart Defaults resource; `default_strategy` tenant setting.
- Guarantees proven by tests: fewer questions, **no wrong orders**.

Everything below is **not built** — parked here on purpose.

## A. Auto-pick ranking (strategy `explicit_then_auto`)
When no owner default exists and the owner chose "always pick". The setting and a
basic cheapest-then-smallest fallback exist, but the real ranking is future:
1. Best seller
2. Most recently ordered
3. Cheapest
4. Smallest

Needs order-history aggregation (see B). Until then, `explicit_then_auto` uses
cheapest → smallest only.

## B. Store Learning — suggest defaults from order history
Analyse the store's actual orders and **suggest** a default per ambiguous group
(e.g. "90% of your rice orders are 5kg → set as default?"). Owner accepts in one
click. Powers the auto-pick ranking in A. Read-only suggestions; never changes a
default without owner confirmation.

## C. Customer Learning — per-customer preferred variant
Remember what each customer usually buys and prefer it for *that* customer.
```
Customer (regular):  Rice
Bot:                 Added Rice 10kg   (because they normally buy 10kg)
```
Per-customer override sits **above** the store default in the precedence. Needs a
per-customer purchase profile + privacy/reset controls. Not built.

## D. Guided Smart Defaults page (admin UX)
Auto-detect ambiguous groups (≥2 SKUs sharing a term) and present a one-screen
"pick a default for each" table with status badges and one-click suggestions.
The current build ships the CRUD resource; this guided page is a UX upgrade.

## E. Other Phase 2 items still pending (from the gap analysis)
Cart-edit engine (remove / advanced edits / replacements), reorder, full checkout
flow (name + location), escalation. Indexed/vector search for very large
catalogues. Per-tenant AI metering + spend caps. Tenant-aware outbound queue.

---

**Decisions locked (this approval):**
- Platform default strategy = **Use My Defaults (explicit)**.
- Size hint = **once per conversation**.
- **No category defaults** (term-level only).
- Auto-pick + Store/Customer learning = **future** (A/B/C above).

---

## Order Notifications

**✅ COMPLETE — Order Notification Recipients MVP** (approved, implemented, verified)
- Store configures multiple WhatsApp recipients (name · number · active) via
  Settings → Order Notifications.
- New-order notification sent to every active recipient on **order placed**.
- Durable ledger `order_notification_sends` (unique `order_id,recipient_id,event_type`)
  → idempotent (no duplicate sends), failure releases the claim for safe retry,
  records `sent_at` + `message_id` for audit.
- `owner_alert_phone` backfilled into recipients on migration (no tenant loses alerts).
- Tenant-isolated; reuses existing WhatsApp infrastructure.
- Verified 24/24 (qa/order_notification_suite.php). Staging confirms: Filament CRUD
  + live WhatsApp delivery.

### F. Notification Templates  *(future — DO NOT implement now)*
Let the store owner choose the notification style per their preference:
- **Compact** — short one-liner (order #, total, items count).
- **Detailed** — the full current layout (customer, phone, itemised list, delivery, time).
Likely a `notification_template` tenant setting (or per-recipient), with the message
builder (`OrderNotificationMessage`) selecting the layout. Parked here on purpose.

### Other notification phases (future — not built)
Additional event types the ledger's `event_type` column already accommodates:
Order Confirmed, Order Cancelled, Delivery Assigned, Delivery Completed,
Scheduled Order Due. Plus recipient **types** (Owner/Manager/Kitchen/Dispatch/
Warehouse) and per-type event rules.

---

## Delivery

**Built: Delivery Management V2 — D1 + D2** (zones, fee + ETA, board, rider assignment,
rider notifications, status lifecycle). D3 (proof of delivery), D4 (COD
reconciliation), D5 (analytics) are next, in that order.

### Store Pickup Option  *(future — DO NOT implement now)*
Let the customer choose **pickup at the store** instead of delivery: no delivery fee,
a "ready for pickup" status + notification, and a pickup code/time. Likely a per-order
fulfilment type (`delivery` | `pickup`) chosen at checkout, with the delivery
fee/zone/ETA skipped for pickup. Parked here on purpose; not part of D1/D2.
