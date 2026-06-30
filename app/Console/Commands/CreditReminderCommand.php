<?php
namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderNotificationSend;
use App\Models\Tenant;
use App\Services\Pricing;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\MessageLog;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Credit-sale reminders.
 *
 * For tenants that sell on credit (credit_reminder_enabled), this nudges the customer
 * a configurable number of days after an invoice/order was created while it still has
 * an unpaid balance. Multiple offsets are allowed — credit_reminder_days = "3,7,14"
 * sends a reminder at 3, 7 and 14 days. Each offset fires once per order (durable
 * idempotency via order_notification_sends, event "credit_reminder_d{N}"), so retries
 * or a double daily run can never double-message anyone.
 *
 * Scheduled daily (routes/console.php). Targets orders whose created date is exactly
 * (today - N): a missed day is simply skipped rather than caught up later, which keeps
 * the cadence predictable and avoids a burst of back-dated nudges when first enabled.
 */
class CreditReminderCommand extends Command
{
    protected $signature = 'credit:reminders
        {--tenant= : limit to one tenant id or slug}
        {--dry : list who would be reminded without sending}';

    protected $description = 'Remind customers about unpaid credit invoices N days after creation.';

    /** Never remind on these (no live debt). */
    private const SKIP_STATUS = ['Cancelled', 'Rejected'];

    public function handle(TenantContext $ctx, WhatsAppManager $wa): int
    {
        $only = (string) $this->option('tenant');
        $dry  = (bool) $this->option('dry');
        $sent = 0; $seen = 0;

        $q = Tenant::query()->whereNotNull('whatsapp_instance')->orderBy('id');
        if ($only !== '') {
            $q->where(fn ($w) => $w->where('id', ctype_digit($only) ? (int) $only : 0)->orWhere('slug', $only));
        }

        foreach ($q->get() as $t) {
            if (! $t->setting('credit_reminder_enabled', false)) continue;
            $offsets = $this->offsets($t);
            if (! $offsets) continue;

            foreach ($offsets as $d) {
                $orders = Order::withoutGlobalScopes()
                    ->where('tenant_id', $t->id)
                    ->whereNotIn('status', self::SKIP_STATUS)
                    ->whereNotNull('customer_phone')
                    ->whereRaw('CAST(total AS DECIMAL(14,2)) - CAST(COALESCE(amount_paid,0) AS DECIMAL(14,2)) > 0.01')
                    ->whereDate('created_at', now()->subDays($d)->toDateString())
                    ->limit(1000)->get();

                foreach ($orders as $o) {
                    if ($o->balanceDue() <= 0) continue;
                    $seen++;
                    $event = 'credit_reminder_d' . $d;

                    if ($dry) {
                        $this->line(sprintf('[dry] %s #%s %s — %s owes %s (day %d)',
                            $t->name, $o->order_no, $o->customer_phone, $o->customer_name ?: '—',
                            Pricing::money($t, $o->balanceDue()), $d));
                        continue;
                    }

                    // Claim once per (order, offset). Already claimed -> skip.
                    if (! OrderNotificationSend::claim($t->id, $o->id, 0, $event)) continue;

                    $ctx->set($t->id);
                    $msg = $this->message($t, $o, $d);
                    try {
                        $res = $wa->forTenant($t)->sendText($t->whatsapp_instance, $o->customer_phone, $msg);
                        $mid = is_array($res) ? ($res['key']['id'] ?? ($res['id'] ?? null)) : null;
                        OrderNotificationSend::markSent($o->id, 0, $event, $mid);
                        MessageLog::record($t->id, $o->customer_phone, $t->whatsapp_instance, 'out', 'system', $msg);
                        $sent++;
                    } catch (\Throwable $e) {
                        OrderNotificationSend::releaseClaim($o->id, 0, $event);
                        Log::warning('credit_reminder.send_failed', ['tenant' => $t->id, 'order' => $o->id, 'err' => $e->getMessage()]);
                    }
                }
            }
        }

        $this->info("credit:reminders — {$seen} due, {$sent} sent" . ($dry ? ' (dry run)' : '') . '.');
        return self::SUCCESS;
    }

    /** Parse credit_reminder_days into a sorted unique list of day offsets (1..365). */
    private function offsets(Tenant $t): array
    {
        $raw = (string) $t->setting('credit_reminder_days', '');
        $out = [];
        foreach (preg_split('/[,\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $p) {
            $n = (int) $p;
            if ($n >= 1 && $n <= 365) $out[$n] = $n;
        }
        ksort($out);
        return array_values($out);
    }

    /** Reminder text — tenant template override, else a polite default. */
    private function message(Tenant $t, Order $o, int $days): string
    {
        $first   = trim((string) strtok((string) $o->customer_name, ' '));
        $hi      = $first !== '' ? "Hi {$first}" : 'Hello';
        $balance = Pricing::money($t, $o->balanceDue());

        $tpl = trim((string) $t->setting('credit_reminder_text', ''));
        if ($tpl !== '') {
            return strtr($tpl, [
                '{name}'    => $first !== '' ? $first : 'there',
                '{order}'   => (string) $o->order_no,
                '{balance}' => $balance,
                '{days}'    => (string) $days,
            ]);
        }

        return "{$hi} \u{1F64F} A friendly reminder from " . $t->name . ": invoice *{$o->order_no}* "
             . "has an outstanding balance of *{$balance}*. If you've already paid, please ignore this — "
             . "otherwise reply here and we'll help you settle it. Thank you!";
    }
}
