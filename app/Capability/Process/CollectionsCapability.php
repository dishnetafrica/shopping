<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\ActionSpec;
use App\Capability\Dto\Related;
use App\Capability\Dto\WorkspacePayload;
use App\Models\Invoice;
use Illuminate\Support\Collection;

/** Collections process — the overdue queue, ranked by exposure. Reads invoice balances live. */
final class CollectionsCapability implements ProcessCapability
{
    public function key(): string { return 'collections'; }

    public function permission(): string { return 'menu:finance'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $rows = $this->overdue($ctx);
        $exposure = (float) $rows->sum(fn ($i) => (float) $i->balance);

        $related = $rows->take(20)->map(fn ($i) => (new Related(
            'overdue', 'invoice', (string) $i->id, (string) $i->customer_name,
            'due ' . optional($i->due_at)->format('d M Y') . ' · $' . number_format((float) $i->balance),
            null, '/api/v1/workspace/invoice/' . $i->id,
        ))->toArray())->values()->all();

        return (new WorkspacePayload)
            ->object(['type' => 'process:collections', 'title' => 'Collections', 'status' => $rows->count() . ' overdue'])
            ->headline('$' . number_format($exposure), 'total overdue', $exposure > 0 ? 'danger' : 'ok')
            ->related($related)
            ->actions([new ActionSpec('bulk_remind', 'Send reminders', '/api/v1/workspace/collections/_/bulk_remind', 'POST', 'human', 'primary')])
            ->meta(['capability' => 'collections', 'ttl' => 30, 'permissions' => ['menu:finance']]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        $rows = $this->overdue($ctx);

        return [
            'overdueCount' => $rows->count(),
            'exposure' => round((float) $rows->sum(fn ($i) => (float) $i->balance), 2),
        ];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return match ($action) {
            'bulk_remind' => ['ok' => true, 'reminded' => $this->overdue($ctx)->count()],
            default => ['ok' => false, 'error' => 'unknown_action'],
        };
    }

    private function overdue(CapabilityContext $ctx): Collection
    {
        return Invoice::where('tenant_id', $ctx->tenantId())
            ->where('status', '!=', 'void')
            ->whereNotNull('due_at')->where('due_at', '<', now())
            ->orderByDesc('total')->limit(200)->get()
            ->filter(fn ($i) => (float) $i->balance > 0)
            ->values();
    }
}
