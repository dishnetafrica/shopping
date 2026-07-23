<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Relationship\GoalEngine;
use Illuminate\Console\Command;

/**
 * relationship:plan — the morning question: "Who needs me today?" (V4.1)
 * Fills the Proposed follow-ups inbox. Per-tenant flag `followups_ai` (default OFF).
 */
class RelationshipPlanCommand extends Command
{
    protected $signature   = 'relationship:plan {--tenant=0} {--cap=20 : Max proposals per tenant per day}';
    protected $description = 'V4.1 Goal Engine: generate today\'s follow-up proposals for human approval';

    public function handle(GoalEngine $engine): int
    {
        $tenants = Tenant::query()
            ->when((int) $this->option('tenant') > 0, fn ($q) => $q->whereKey((int) $this->option('tenant')))
            ->get();

        foreach ($tenants as $t) {
            app(\App\Support\TenantContext::class)->set((int) $t->id); // scope this tenant's models (incl. GoalEngine)
            if (! $engine->enabled($t)) continue;
            $n = $engine->plan($t, max(1, (int) $this->option('cap')));
            $this->info("tenant {$t->id} ({$t->name}): {$n} proposal(s) for today");
        }

        return self::SUCCESS;
    }
}
