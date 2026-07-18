<?php

namespace App\Console\Commands;

use App\Models\FollowupProposal;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * relationship:attribute — V4.3 honest attribution, daily:
 * a SENT follow-up gets linked to the FIRST order from that customer placed AFTER
 * the send, within `followup_attribution_days` (default 7). Simple, disputable,
 * direction-over-precision — like all attribution. Idempotent; pure data, no AI.
 */
class RelationshipAttributeCommand extends Command
{
    protected $signature   = 'relationship:attribute {--tenant=0}';
    protected $description = 'V4.3: link sent follow-ups to the orders they influenced (N-day window)';

    public function handle(): int
    {
        $tenants = Tenant::query()
            ->when((int) $this->option('tenant') > 0, fn ($q) => $q->whereKey((int) $this->option('tenant')))
            ->get();

        $linked = 0;
        foreach ($tenants as $t) {
            if (! (bool) $t->setting('followups_ai', false)) continue;
            $window = max(1, (int) $t->setting('followup_attribution_days', 7));

            $sent = FollowupProposal::where('tenant_id', $t->id)
                ->where('status', 'sent')->whereNull('influenced_order_id')
                ->where('updated_at', '>', now()->subDays($window + 14))
                ->get();

            foreach ($sent as $p) {
                $order = Order::withoutGlobalScopes()->where('tenant_id', $t->id)
                    ->where('customer_phone', $p->customer_phone)
                    ->where('created_at', '>', $p->updated_at)
                    ->where('created_at', '<=', $p->updated_at->copy()->addDays($window))
                    ->orderBy('created_at')->first();
                if ($order) {
                    $p->influenced_order_id = $order->id;
                    $p->save();
                    $linked++;
                }
            }
        }
        $this->info("relationship:attribute — {$linked} follow-up(s) linked to orders.");

        return self::SUCCESS;
    }
}
