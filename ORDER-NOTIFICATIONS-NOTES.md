# Order Notification Recipients (MVP — Order Placed)

Send a WhatsApp to every ACTIVE recipient the moment an order is placed. Idempotent
via a durable ledger; reuses the existing WhatsApp infrastructure; tenant-isolated.

## Files
- migrations: 000005 order_notification_recipients, 000006 order_notification_sends (ledger),
  000007 backfill owner_alert_phone -> recipients
- models: OrderNotificationRecipient, OrderNotificationSend (claim/markSent/releaseClaim)
- app/Support/OrderNotificationMessage.php (pure message builder — the approved layout)
- app/Jobs/NotifyOwnerNewOrder.php (rewritten: active recipients + ledger + format)
- Filament: OrderNotificationRecipientResource (+Pages) — Settings -> Order Notifications

## Behaviour
- Trigger: order PLACED (OrderObserver::created already dispatches the job; POS excluded).
- Recipients: active rows in order_notification_recipients (per tenant).
- Idempotency: claim (order_id, recipient_id, event_type) in the ledger BEFORE sending.
  Duplicate event / job retry -> claim fails -> skip. Send success -> sent_at + message_id
  recorded. Send failure -> claim released so a later retry can resend (never duplicates).
- Backward compatible: migration imports each tenant's owner_alert_phone numbers as
  "Owner" recipients, so no tenant loses alerts. (Tenant::ownerAlertNumbers() is left
  intact for payment receipts elsewhere.)

## Verified here (real SQLite + exact unique index) — 24/24, see qa/order_notification_suite.php
- multiple active recipients notified; inactive skipped
- duplicate job retry sends nothing extra; no duplicate ledger rows
- ledger records sent_at + message_id for every send
- tenant isolation (tenant A recipients never get tenant B orders)
- failed send releases claim -> retry resends exactly once
- owner_alert_phone backfill creates distinct recipient rows
- message matches the approved layout exactly

## Verify on staging (cannot run here — no Laravel/WhatsApp)
- Filament "Order Notifications" CRUD (add/edit/disable/delete).
- Place a test order -> all active recipients receive the message from the shop instance.
- Run migrate; confirm existing owner_alert_phone tenants got recipient rows.

## Out of scope (future phases, per your decision)
Order Confirmed / Cancelled / Delivery Assigned / Completed / Scheduled Due, and
recipient types (Owner/Manager/Kitchen/Dispatch/Warehouse). The ledger's event_type
column already leaves room for these.

Deploy: drop in, push, then: php artisan migrate --force && php artisan optimize:clear
