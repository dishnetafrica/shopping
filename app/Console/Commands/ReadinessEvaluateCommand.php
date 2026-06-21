<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Bot\Readiness\ReadinessService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Produce a Go-Live Report from the latest Business Discovery.
 *   php artisan readiness:evaluate --tenant=2
 *   php artisan readiness:evaluate --tenant=2 --send
 */
class ReadinessEvaluateCommand extends Command
{
    protected $signature = 'readiness:evaluate {--tenant= : tenant id} {--send : WhatsApp the report to the owner}';
    protected $description = 'Assess whether a business is ready for autonomous AI operation (review-gated).';

    public function handle(TenantContext $ctx, ReadinessService $svc): int
    {
        $id = (int) $this->option('tenant');
        $tenant = Tenant::find($id);
        if (! $tenant) { $this->error("Tenant {$id} not found."); return self::FAILURE; }

        $ctx->set($tenant->id);

        $report = $svc->evaluate($tenant);
        if (! $report) {
            $this->error('No Business Discovery report found. Run discovery:scan first.');
            return self::FAILURE;
        }

        $cs = $report->category_scores;
        $this->info("Go-Live Report #{$report->id} — overall {$report->overall_score}% → {$report->classification}");
        $this->line("Recommended mode: {$report->recommended_mode}  (status: {$report->status}, AI stays off until owner approves)");
        $this->line(sprintf(
            'Products %d%%  FAQs %d%%  Delivery %d%%  Offers %d%%  Language %d%%  Confidence %d%%  Approval %d%%',
            $cs['products'], $cs['faqs'], $cs['delivery'], $cs['offers'], $cs['language'], $cs['confidence'], $cs['owner_approval']
        ));

        foreach (['missing' => '⚠ Missing', 'need_approval' => '✓ Needs approval', 'need_confirmation' => '? Confirm'] as $k => $head) {
            $items = $report->recommendations[$k] ?? [];
            if ($items) {
                $this->line("{$head}:");
                foreach ($items as $it) $this->line('   - ' . ($it['detail'] ?? ''));
            }
        }

        if ($this->option('send')) {
            $this->line($svc->send($tenant, $report) ? 'Report sent to owner.' : 'Not sent (no owner_alert_phone).');
        }

        return self::SUCCESS;
    }
}
