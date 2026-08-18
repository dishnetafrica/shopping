<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\EngagementCandidate;
use App\Models\Tenant;
use App\Services\Engagement\EngagementPlanner;
use Illuminate\Console\Command;

/**
 * reengage:plan — Phase 1 of the Customer Re-engagement Engine.
 *
 * Scores every past buyer, applies the strict consent/cooldown/suppression gate, and writes today's
 * engagement_candidates snapshot (the top `engagement_daily_target` marked chosen). It then PRINTS the
 * plan so the owner can review exactly who ShopBot would contact and why.
 *
 * PHASE 1 SENDS NOTHING. There is no message path here by design — the `--dry` flag is accepted and is
 * the only mode. A specific `--tenant` runs regardless of the enable flag (so a shop can preview before
 * turning anything on); the unattended all-tenant run is gated on `engagement_enabled` (default OFF).
 */
class ReengagePlanCommand extends Command
{
    protected $signature = 'reengage:plan {--tenant=0} {--dry : Phase 1 — plan only, never sends (default and only mode)} {--show=15 : How many chosen candidates to print}';

    protected $description = 'Phase 1: build & review the daily customer re-engagement plan (no sending)';

    public function handle(EngagementPlanner $planner): int
    {
        $only = (int) $this->option('tenant');
        $show = max(0, (int) $this->option('show'));

        $tenants = Tenant::query()
            ->when($only > 0, fn ($q) => $q->whereKey($only))
            ->get();

        foreach ($tenants as $t) {
            app(\App\Support\TenantContext::class)->set((int) $t->id);

            // Unattended (all-tenant) runs honour the enable flag; an explicit --tenant is a preview.
            if ($only === 0 && ! (bool) $t->setting('engagement_enabled', false)) {
                continue;
            }

            $r = $planner->plan($t);
            $c = $r['counts'];

            $this->line('');
            $this->line("╭─ Re-engagement plan · tenant {$t->id} ({$t->name}) · {$r['plan_date']}");
            $this->line("│  PHASE 1 — planning only, NO messages sent.");
            $this->line("│  target {$r['target']}/day · cooldown {$r['cooldown_days']}d");
            $this->line("│  past buyers {$c['past_buyers']}  →  opted-in {$c['opted_in']}  →  eligible {$c['eligible']}  →  chosen {$c['chosen']}");
            if ($r['blocked']) {
                $parts = [];
                foreach ($r['blocked'] as $reason => $n) { $parts[] = "{$reason}: {$n}"; }
                $this->line('│  not eligible — ' . implode(' · ', $parts));
            }
            $this->line('╰─');

            if ($show > 0) {
                $rows = EngagementCandidate::withoutGlobalScopes()
                    ->where('tenant_id', $t->id)->where('plan_date', $r['plan_date'])
                    ->where('chosen', true)->orderByDesc('score')->limit($show)
                    ->get(['name', 'phone', 'score', 'campaign_type', 'product_name', 'reason']);

                if ($rows->isNotEmpty()) {
                    $this->table(
                        ['#', 'Score', 'Customer', 'Campaign', 'Suggested product', 'Why'],
                        $rows->values()->map(fn ($x, $i) => [
                            $i + 1,
                            $x->score,
                            mb_substr((string) ($x->name ?: $x->phone), 0, 24),
                            \App\Services\Engagement\CampaignType::LABEL[$x->campaign_type] ?? $x->campaign_type,
                            mb_substr((string) ($x->product_name ?: '—'), 0, 22),
                            mb_substr((string) ($x->reason ?: ''), 0, 46),
                        ])->all()
                    );
                }
            }

            if ($c['opted_in'] === 0 && $c['past_buyers'] > 0) {
                $this->warn("  ⚠  0 of {$c['past_buyers']} past buyers have opted in to marketing — nothing is eligible to contact yet (consent cold-start). The plan is correct; the audience is empty until opt-in is collected.");
            }
        }

        return self::SUCCESS;
    }
}
