<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Weekly storefront digest — WhatsApps the owner two things that are otherwise buried in the panel:
 *   1) the funnel (views → add-to-cart → orders, with buy rate), and
 *   2) the highest-ROI report: searches that returned NOTHING — unmet demand to stock or keyword.
 *
 * Read-only over product_events + search_events (the same logs the Storefront Insights screen reads).
 * Writes nothing, touches no frozen service. Opt-in per tenant via the `storefront_gaps_digest`
 * setting (blank/false = off); WhatsApp goes to the existing owner-alert numbers.
 *
 * Scheduled Mondays 08:00 Africa/Kampala (see routes/console.php). Test now with:
 *   php artisan storefront:gaps-digest --tenant=1 --force
 */
final class StorefrontGapsDigestCommand extends Command
{
    protected $signature = 'storefront:gaps-digest
        {--tenant= : only this tenant id (default: all opted-in)}
        {--days=7 : reporting window in days}
        {--force : ignore the opt-in gate (for testing)}';

    protected $description = 'WhatsApp the owner a weekly storefront digest: funnel + searches that found nothing.';

    public function handle(WhatsAppManager $wa): int
    {
        $tenants = $this->option('tenant')
            ? Tenant::where('id', (int) $this->option('tenant'))->get()
            : Tenant::all();

        $days = max(1, (int) ($this->option('days') ?: 7));
        $since = now()->subDays($days);
        $sent = 0;

        foreach ($tenants as $t) {
            $optedIn = filter_var($t->setting('storefront_gaps_digest', false), FILTER_VALIDATE_BOOLEAN);
            if (! $optedIn && ! $this->option('force')) {
                continue; // not opted in
            }

            $instance = $t->whatsapp_instance ?? null;
            $numbers  = method_exists($t, 'ownerAlertNumbers') ? $t->ownerAlertNumbers() : [];
            if (! $instance || ! $numbers) {
                continue; // no way to reach the owner
            }

            try {
                $text = $this->buildText($t, $since, $days);
            } catch (\Throwable $e) {
                Log::warning('storefront:gaps-digest build failed', ['tenant' => $t->id, 'err' => $e->getMessage()]);
                continue;
            }
            if ($text === null) {
                continue; // no activity worth a message this week
            }

            $gw = $wa->forTenant($t);
            foreach ($numbers as $num) {
                try {
                    $gw->sendText($instance, $num, $text);
                    $sent++;
                } catch (\Throwable $e) {
                    Log::warning('storefront:gaps-digest send failed', ['tenant' => $t->id, 'err' => $e->getMessage()]);
                }
            }
        }

        $this->info("storefront:gaps-digest sent {$sent} message(s).");

        return self::SUCCESS;
    }

    /** Build the digest text for a tenant, or null when there's nothing worth sending. */
    private function buildText(Tenant $t, $since, int $days): ?string
    {
        $tid = (int) $t->id;

        $f = DB::table('product_events')->where('tenant_id', $tid)->where('created_at', '>=', $since)
            ->selectRaw("sum(case when event = 'view' then 1 else 0 end) as v")
            ->selectRaw("sum(case when event = 'add' then 1 else 0 end) as a")
            ->selectRaw("sum(case when event = 'checkout' then 1 else 0 end) as c")
            ->first();
        $v = (int) ($f->v ?? 0); $a = (int) ($f->a ?? 0); $c = (int) ($f->c ?? 0);
        $conv = $v > 0 ? (int) round($c / $v * 100) : 0;

        $gaps = DB::table('search_events')->where('tenant_id', $tid)->where('created_at', '>=', $since)
            ->where(fn ($q) => $q->where('type', 'zero_result')->orWhere('results', 0))
            ->whereRaw("coalesce(trim(query), '') <> ''")
            ->select(DB::raw('lower(trim(query)) as q'), DB::raw('count(*) as times'))
            ->groupBy(DB::raw('lower(trim(query))'))
            ->orderByDesc(DB::raw('count(*)'))
            ->limit(10)->get();

        // Don't spam an empty digest — skip a tenant with zero storefront activity this week.
        if ($v === 0 && $a === 0 && $c === 0 && $gaps->isEmpty()) {
            return null;
        }

        $name = (string) ($t->name ?: 'Your shop');
        $lines = [];
        $lines[] = "🛍️ *{$name} — storefront ({$days}d)*";
        $lines[] = "👁 {$v} views · 🛒 {$a} added · ✅ {$c} orders" . ($v > 0 ? "  ({$conv}% buy)" : '');
        $lines[] = '';
        if ($gaps->isEmpty()) {
            $lines[] = '🔍 No failed searches — every search found something 👍';
        } else {
            $lines[] = '🔍 *Searched but NOT found* — stock these or add keywords:';
            $i = 1;
            foreach ($gaps as $g) {
                $lines[] = "{$i}. \"{$g->q}\" ×{$g->times}";
                $i++;
            }
        }
        $lines[] = '';
        $lines[] = 'More in v3 → Storefront Insights.';

        return implode("\n", $lines);
    }
}
