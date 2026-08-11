# CloudBSS — WhatsApp watchdog, verifier, and the order-ack fix

Three files. Your Dockerfile does `COPY app/` and `COPY database/`, so all three ship with a normal rebuild.

```
app/Console/Commands/WaWatchdogCommand.php   ← REPLACES the existing file
app/Console/Commands/WaVerifyCommand.php     ← new
database/migrations/2026_08_11_000001_fix_order_notification_sends_recipient_fk.php   ← new
```

Upload keeping the paths, commit, press **Deploy**. The migration runs automatically (your entrypoint does `php artisan migrate --force`).

---

## 1 & 2 — the watchdog and the verifier

```bash
php artisan wa:verify                        # is this shop delivering right now?
php artisan wa:verify --send=2119xxxxxxxx    # LIVE test, waits for WhatsApp's receipt
php artisan wa:watchdog                      # runs every 5 min anyway
```

Exit code 0 = delivering, 1 = not. Neither fixes the outage; they make the next one visible in six hours instead of twenty-four days.

The watchdog now also alarms when **rejections are arriving AND nothing has delivered for 6 hours**. The AND matters — a quiet shop has a stale timestamp and nothing wrong with it. Alerts go via `PLATFORM_WA_INSTANCE`, never the shop's own number, because that is the one that cannot deliver. **If that env var is unset the alert cannot be sent** — it logs `wa.no_ops_instance` and gives up.

---

## 3 — the order-acknowledgement fix (separate bug, found today)

`order_notification_sends.recipient_id` was a strict foreign key into `order_notification_recipients`. But two callers pass **0** as a sentinel meaning "this went to the customer, not to a staff member":

- `NotifyCustomerOrderReceived` — the "we've received your order" WhatsApp
- `CreditReminderCommand` — the reminders that chase money owed

`order_notification_recipients.id` is a bigserial starting at 1, so **no row can ever have id 0**. Every claim violated the constraint and threw. `insertOrIgnore` does not help: on Postgres it is `ON CONFLICT DO NOTHING`, which swallows unique conflicts, not foreign-key violations.

**Neither feature has ever worked.** Not intermittently — structurally impossible since June. Ten failures are still in `failed_jobs` from July; the rest aged out.

Confirm it on your own data before and after:

```bash
php artisan tinker --execute="
  echo DB::table('order_notification_sends')->selectRaw('event_type, count(*) n')
        ->groupBy('event_type')->get()->toJson(JSON_PRETTY_PRINT).\"\n\";
  echo 'customer-sentinel rows: '.DB::table('order_notification_sends')->where('recipient_id',0)->count().\"\n\";
"
```

Before the fix that count is **0** and no `web_customer_ack` or `credit_reminder_*` rows exist at all. After the fix, place a website order and it becomes 1.

The migration drops the constraint, because the column is not a pure foreign key — it holds "a recipient id, OR 0 meaning the customer", which a FK cannot express. Idempotency is untouched: the unique index on `(order_id, recipient_id, event_type)` still does the deduplication.

**Rejected alternative:** inserting a fake recipient row with id 0. It would satisfy the constraint, but that row needs a `tenant_id` and the FK carries `cascadeOnDelete` — so deleting whichever tenant happened to own the fake row would cascade away every customer acknowledgement ever recorded, for every shop. A landmine in exchange for tidiness.

**Deliberate side effect:** deleting a staff recipient no longer erases the record that they were notified. The send ledger is history; that it outlives the person is correct.

---

## Priority

The outage is still the headline. This migration does not affect it — it is safe to ship in the same deploy, and it will not change delivery one way or the other. Do the Evolution image upgrade regardless.
