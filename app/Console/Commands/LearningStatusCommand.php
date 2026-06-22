<?php

namespace App\Console\Commands;

use App\Models\BusinessDiscovery;
use App\Models\GoLiveReport;
use App\Models\Message;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Read-only overview of what the Business Brain has learned per tenant — at a glance, which tenants
 * are current and which need data. Touches nothing; safe to run any time.
 *
 *   php artisan learning:status
 *   php artisan learning:status --stale-days=14   # flag discoveries older than N days as stale
 */
class LearningStatusCommand extends Command
{
    protected $signature = 'learning:status {--stale-days=14 : Discovery older than this many days counts as stale}';
    protected $description = 'Show per-tenant learning status: messages, catalogue, last learned, products, readiness';

    public function handle(): int
    {
        $staleDays = max(1, (int) $this->option('stale-days'));
        $rows = [];
        $needAttention = 0;

        foreach (Tenant::orderBy('id')->get() as $t) {
            $msgs    = Message::withoutGlobalScopes()->where('tenant_id', $t->id)->count();
            $lastMsg = Message::withoutGlobalScopes()->where('tenant_id', $t->id)->max('created_at');
            $cat     = Product::withoutGlobalScopes()->where('tenant_id', $t->id)->where('active', true)->count();
            $disc    = BusinessDiscovery::withoutGlobalScopes()->where('tenant_id', $t->id)->orderByDesc('id')->first();
            $report  = GoLiveReport::withoutGlobalScopes()->where('tenant_id', $t->id)->orderByDesc('id')->first();

            $learned     = $disc && $disc->created_at ? $disc->created_at : null;
            $prodLearned = $disc && is_array($disc->report) ? count($disc->report['sections']['top_products'] ?? []) : 0;
            $readiness   = $report ? ($report->overall_score . '% ' . $report->recommended_mode) : '—';

            // status verdict
            if ($msgs === 0) {
                $status = 'no chats';
            } elseif (! $disc) {
                $status = 'NEVER learned';
            } elseif ($learned && $learned->lt(now()->subDays($staleDays))) {
                $status = 'stale (' . $learned->diffForHumans(null, true) . ' old)';
            } elseif ($cat === 0) {
                $status = 'no catalogue';
            } else {
                $status = 'ok';
            }
            if (! in_array($status, ['ok'], true)) $needAttention++;

            $rows[] = [
                $t->id,
                \Illuminate\Support\Str::limit($t->name, 22),
                number_format($msgs),
                $cat ?: '—',
                $learned ? $learned->diffForHumans(null, true) . ' ago' : 'never',
                $prodLearned ?: '—',
                $readiness,
                $status,
            ];
        }

        $this->table(
            ['ID', 'Tenant', 'Msgs', 'Catalogue', 'Last learned', 'Products', 'Readiness', 'Status'],
            $rows
        );
        $this->newLine();
        $this->line(count($rows) . ' tenant(s) · ' . $needAttention . ' need attention (anything not "ok").');
        $this->line('Tips: "no chats" = import history · "no catalogue" = add products · "stale"/"NEVER" = run  php artisan discovery:auto --sync');

        return self::SUCCESS;
    }
}
