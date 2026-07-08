<?php declare(strict_types=1);

namespace App\Observers;

use App\Models\OrderCost;
use App\Services\LedgerService;

/**
 * Phase 2 — live subledger→GL posting. Central hook so no controller needs changing.
 * DORMANT unless the tenant setting `ledger_live_posting` is on. Best-effort: a GL hiccup
 * must NEVER block an operational cash entry (the backfill command reconciles). The GL is
 * append-only: edits/deletes post REVERSING journals, they never delete ledger history.
 */
class OrderCostObserver
{
    private function svc(): LedgerService { return app(LedgerService::class); }

    private function live(OrderCost $c): bool
    {
        try { return $this->svc()->livePosting((int) $c->tenant_id); }
        catch (\Throwable $e) { return false; }
    }

    public function created(OrderCost $c): void
    {
        if (! $this->live($c)) return;
        try { $this->svc()->postForOrderCost($c); } catch (\Throwable $e) { /* reconciled by backfill */ }
    }

    public function updated(OrderCost $c): void
    {
        if (! $this->live($c)) return;
        try {
            $this->svc()->reverseActiveFor((int) $c->tenant_id, 'order_cost', (int) $c->id); // reverse old
            $this->svc()->postForOrderCost($c);                                              // post new
        } catch (\Throwable $e) { /* reconciled by backfill */ }
    }

    public function deleted(OrderCost $c): void
    {
        if (! $this->live($c)) return;
        try { $this->svc()->reverseActiveFor((int) $c->tenant_id, 'order_cost', (int) $c->id); }
        catch (\Throwable $e) { /* reconciled by backfill */ }
    }
}
