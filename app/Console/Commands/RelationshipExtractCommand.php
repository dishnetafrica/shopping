<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Tenant;
use App\Services\Relationship\RelationshipExtractor;
use Illuminate\Console\Command;

/**
 * relationship:extract — the Memory Loop cron (V4.0). Every run: find conversations
 * idle >30 min with unprocessed inbound activity, run the ONE-call analysis
 * (facts + stage + Guardian review), stamp them. Batch-capped; fail-soft; per-tenant
 * flag `relationship_memory` (default OFF).
 */
class RelationshipExtractCommand extends Command
{
    protected $signature   = 'relationship:extract {--tenant=0 : Only this tenant} {--batch=25 : Max conversations per run}';
    protected $description = 'V4.0 memory loop: settled conversations → profile facts, stage, summary, Guardian review';

    public function handle(RelationshipExtractor $x): int
    {
        $tenants = Tenant::query()
            ->when((int) $this->option('tenant') > 0, fn ($q) => $q->whereKey((int) $this->option('tenant')))
            ->get();

        $done = 0;
        $batch = max(1, (int) $this->option('batch'));
        foreach ($tenants as $t) {
            if (! $x->enabled($t)) continue;

            $ready = Conversation::withoutGlobalScope('tenant')
                ->where('tenant_id', $t->id)
                ->whereNotNull('last_message_at')
                ->where('last_message_at', '<', now()->subMinutes(30))
                ->where(function ($q) {
                    $q->whereNull('summarized_at')->orWhereColumn('last_message_at', '>', 'summarized_at');
                })
                ->orderBy('last_message_at')
                ->limit($batch)
                ->get();

            foreach ($ready as $c) {
                if ($x->process($t, $c)) $done++;
                if ($done >= $batch) break 2;
            }
        }

        $this->info("relationship:extract — {$done} conversation(s) processed.");

        return self::SUCCESS;
    }
}
