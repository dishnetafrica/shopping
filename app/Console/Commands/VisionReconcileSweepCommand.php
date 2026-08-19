<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\VisionEvent;
use App\Services\Vision\Reconciliation\VisionReconciler;
use Illuminate\Console\Command;

/**
 * Re-evaluate PENDING movement events (Phase-0 Stock & Loss). A crossing that had no transaction at
 * arrival sits PENDING; this sweep re-runs it: it becomes MATCH once the paperwork lands, or escalates
 * to UNRECONCILED once the grace window has passed with nothing recorded. No-op when reconciliation is
 * off. This is the temporal half of the engine — the arrival path can only ever produce MATCH/PENDING.
 */
class VisionReconcileSweepCommand extends Command
{
    protected $signature = 'vision:reconcile-sweep {--tenant=0}';

    protected $description = 'Resolve PENDING vision movement events into MATCH or UNRECONCILED';

    public function handle(VisionReconciler $reconciler): int
    {
        if (! VisionReconciler::enabled()) {
            $this->info('vision reconciliation is disabled (vision.reconcile.enabled=false)');

            return self::SUCCESS;
        }

        $only = (int) $this->option('tenant');
        $rows = VisionEvent::withoutGlobalScopes()
            ->where('recon_result', 'PENDING')
            ->when($only > 0, fn ($q) => $q->where('tenant_id', $only))
            ->orderBy('id')->limit(500)->get();

        $resolved = 0; $escalated = 0;
        foreach ($rows as $row) {
            $payload = is_array($row->payload) ? $row->payload : [];
            try {
                $d = $reconciler->reconcile((int) $row->tenant_id, $payload, $row);
                if (($d['result'] ?? '') === 'MATCH') { $resolved++; }
                if (($d['result'] ?? '') === 'UNRECONCILED') { $escalated++; }
            } catch (\Throwable $e) {
                $this->warn("event {$row->id}: {$e->getMessage()}");
            }
        }

        $this->info("swept {$rows->count()} pending · {$resolved} matched · {$escalated} escalated to review");

        return self::SUCCESS;
    }
}
