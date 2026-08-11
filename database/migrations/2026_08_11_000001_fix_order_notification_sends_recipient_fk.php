<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The schema and the code disagreed, and the code lost — silently, since June.
 *
 * `order_notification_sends.recipient_id` was declared a strict foreign key into
 * `order_notification_recipients`. But two callers deliberately pass 0 as a SENTINEL meaning
 * "this went to the customer, not to a staff recipient":
 *
 *     NotifyCustomerOrderReceived.php:41   the "we've received your order" WhatsApp
 *     CreditReminderCommand.php:79         the credit reminders that chase money owed
 *
 * `order_notification_recipients.id` is a bigserial starting at 1, so **no row can ever have
 * id 0**. Every one of those claims violated the constraint and threw. And insertOrIgnore does
 * not help: on Postgres it becomes ON CONFLICT DO NOTHING, which swallows UNIQUE conflicts, not
 * foreign-key violations.
 *
 * So neither feature ever worked once. Not intermittently — structurally impossible. Ten of them
 * are still sitting in failed_jobs from July; the rest aged out. Customers placed website orders
 * and were never acknowledged, and nobody was ever reminded about money they owed.
 *
 * THE FIX: drop the constraint, because the column is not a pure foreign key. It holds "a
 * recipient id, OR 0 meaning the customer", and a FK cannot express that. This makes the schema
 * describe what the column actually is.
 *
 * Considered and rejected: inserting a sentinel row with id 0. It would satisfy the constraint,
 * but that row needs a tenant_id, and the FK carries cascadeOnDelete — so deleting whichever
 * tenant happened to own the fake row would cascade away every customer acknowledgement ever
 * recorded, for every shop. A landmine in exchange for tidiness.
 *
 * Idempotency is untouched: the unique index on (order_id, recipient_id, event_type) still does
 * the deduplication, and 0 compares perfectly well.
 *
 * SIDE EFFECT, deliberate: deleting a staff recipient no longer cascades away the record that
 * they were notified. The send ledger is history — that it survives the person leaving is
 * correct, not a regression.
 *
 * The proper long-term shape is a nullable recipient_id plus a recipient_kind column, with an
 * expression index to keep dedup working across NULLs. That is a bigger change than a shop with
 * a live outage should take today.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('order_notification_sends')) return;

        Schema::table('order_notification_sends', function (Blueprint $t) {
            try {
                $t->dropForeign(['recipient_id']);
            } catch (\Throwable $e) {
                // Already dropped, or a driver that never created it. Not worth failing a deploy.
            }
        });
    }

    public function down(): void
    {
        // Deliberately NOT restoring the constraint. Putting it back would immediately re-break
        // both features, and any row already written with recipient_id 0 would make the migration
        // itself fail. Rolling this back is not a thing anyone should want.
    }
};
