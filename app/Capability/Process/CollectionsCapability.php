<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\ActionSpec;
use App\Capability\Dto\Related;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Support\Money;
use App\Models\Invoice;
use Illuminate\Support\Collection;

/** Collections process — the overdue queue, ranked by exposure. Reads invoice balances live. */
final class CollectionsCapability implements ProcessCapability
{
    public function key(): string { return 'collections'; }

    public function permission(): string { return 'menu:finance'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $rc = \App\Services\Money\ReportingCurrency::forContext($ctx);
        $native = $ctx->nativeCurrency();
        $tenant = $ctx->tenant;
        // The invoice `currency` tag is unreliable (issuer stamps the base); derive the real currency
        // from the linked order so a UGX overdue balance isn't consolidated as if it were USD.
        $icur = fn ($i) => \App\Services\Money\SalesBaseCurrency::txnCurrency($i, $tenant);
        $idate = fn ($i) => $i->due_at ? \Illuminate\Support\Carbon::parse($i->due_at)->toDateString() : null;

        $rows = $this->overdue($ctx);
        $exposureRows = $rows->map(fn ($i) => ['amount' => (float) $i->balance, 'currency' => $icur($i), 'date' => $idate($i)])->all();
        $exposure = (float) $rc->consolidate($exposureRows)['amount'];

        $related = $rows->take(20)->map(fn ($i) => (new Related(
            'overdue', 'invoice', (string) $i->id, (string) $i->customer_name,
            'due ' . optional($i->due_at)->format('d M Y') . ' · ' . $rc->line((float) $i->balance, $icur($i), null, $idate($i))['text'],
            null, '/api/v1/workspace/invoice/' . $i->id,
        ))->toArray())->values()->all();

        return (new WorkspacePayload)
            ->object(['type' => 'process:collections', 'title' => 'Collections', 'status' => $rows->count() . ' overdue'])
            ->headline($rc->fmtConsolidated($exposure), 'total overdue', $exposure > 0 ? 'danger' : 'ok')
            ->related($related)
            ->actions([new ActionSpec('bulk_remind', 'Send reminders', '/api/v1/workspace/collections/_/bulk_remind', 'POST', 'human', 'primary')])
            ->meta(['capability' => 'collections', 'ttl' => 30, 'permissions' => ['menu:finance']]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        $rc = \App\Services\Money\ReportingCurrency::forContext($ctx);
        $native = $ctx->nativeCurrency();
        $tenant = $ctx->tenant;
        $rows = $this->overdue($ctx);
        $exposure = $rc->consolidate($rows->map(fn ($i) => [
            'amount' => (float) $i->balance,
            'currency' => \App\Services\Money\SalesBaseCurrency::txnCurrency($i, $tenant),
            'date' => $i->due_at ? \Illuminate\Support\Carbon::parse($i->due_at)->toDateString() : null,
        ])->all())['amount'];

        return [
            'overdueCount' => $rows->count(),
            'exposure' => round((float) $exposure, 2),
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
