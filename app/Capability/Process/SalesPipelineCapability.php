<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\Lead;
use App\Models\SalesPipelineEvent;
use App\Models\User;
use App\Services\Sales\Pipeline\Pipeline;
use App\Services\Sales\Pipeline\PipelineService;
use App\Services\Sales\SalesOwnership;

/**
 * Pipeline (DishNet South Sudan plan, Phase 6) — every sales lead with its stage, salesperson and next
 * action. Internet-provider businesses only. Salespeople see their own and unassigned leads; owners and
 * managers see everyone. Rows open the lead, where it is moved through the gated stages.
 */
final class SalesPipelineCapability implements ProcessCapability
{
    public function key(): string { return 'salesPipeline'; }

    public function permission(): string { return 'menu:customers'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        if (! SalesOwnership::enabled($ctx->tenant) || ! PipelineService::ready()) {
            return (new WorkspacePayload)
                ->object(['type' => 'process:salesPipeline', 'title' => 'Pipeline', 'subtitle' => 'Available for Internet providers'])
                ->table([['key' => 'customer', 'label' => 'Customer']], [], [], ['pageSize' => 20])
                ->meta(['capability' => 'salesPipeline', 'ttl' => 300, 'permissions' => []]);
        }
        $tz = (string) ($ctx->tenant->setting('timezone') ?: config('app.timezone'));
        $q = Lead::withoutGlobalScopes()->where('tenant_id', $ctx->tenantId())->where('intent', 'lead');
        SalesOwnership::scopeVisible($q, $ctx);
        $leads = $q->orderByDesc('updated_at')->limit(500)->get();
        $names = User::where('tenant_id', $ctx->tenantId())->pluck('name', 'id')->all();
        $now = now();

        $rows = [];
        $count = array_fill_keys(array_keys(Pipeline::STAGES), 0);
        $due = 0;
        foreach ($leads as $l) {
            $stage = PipelineService::stage($l);
            $count[$stage]++;
            $q = (array) ($l->qualification ?? []);
            $overdue = $l->next_followup_at && $l->next_followup_at->lte($now) && ! in_array($stage, ['active', 'lost'], true);
            $due += $overdue ? 1 : 0;
            $rows[] = [
                'customer' => ['value' => trim((string) $l->customer_name) ?: '+' . $l->customer_phone, 'sub' => trim(($q['area'] ?? '') . (isset($q['service_line']) ? ' · ' . ucfirst($q['service_line']) : '')), 'avatar' => true],
                'stage'    => ['value' => Pipeline::label($stage), 'badge' => Pipeline::tone($stage), 'sort' => (string) array_search($stage, array_keys(Pipeline::STAGES), true), 'raw' => $stage],
                'owner'    => ['value' => $l->owner_user_id ? ($names[$l->owner_user_id] ?? '—') : 'Unassigned', 'sort' => (string) ($names[$l->owner_user_id] ?? '')],
                'next'     => in_array($stage, ['active', 'lost'], true) ? ['value' => '—', 'sort' => '9'] : ['value' => (string) ($l->next_action ?? 'Reply to the customer'),
                    'sub' => $l->next_followup_at ? $l->next_followup_at->copy()->setTimezone($tz)->format('j M') : '', 'badge' => $overdue ? 'danger' : 'default',
                    'sort' => (string) ($l->next_followup_at?->getTimestamp() ?? 0)],
                'updated'  => ['value' => $l->updated_at ? $l->updated_at->diffForHumans() : '—', 'sort' => (string) ($l->updated_at?->getTimestamp() ?? 0)],
                'link'     => '/api/v1/workspace/pipelineLead/' . $l->id,
            ];
        }

        // Won vs lost over 90 days, from the stage history.
        $since = now()->subDays(90);
        $ids = $leads->pluck('id')->all();
        $ev = $ids ? SalesPipelineEvent::withoutGlobalScopes()->where('tenant_id', $ctx->tenantId())->whereIn('lead_id', $ids)->where('created_at', '>=', $since)
            ->whereIn('to_stage', ['won', 'lost'])->get()->groupBy('to_stage') : collect();
        $won = isset($ev['won']) ? $ev['won']->pluck('lead_id')->unique()->count() : 0;
        $lost = isset($ev['lost']) ? $ev['lost']->pluck('lead_id')->unique()->count() : 0;
        $open = array_sum(array_intersect_key($count, array_flip(Pipeline::OPEN)));

        return (new WorkspacePayload)
            ->object(['type' => 'process:salesPipeline', 'title' => 'Pipeline',
                'subtitle' => $open . ' open · ' . $count['quoted'] . ' quoted · ' . $count['won'] . ' won awaiting installation · ' . $count['installation'] . ' installing'])
            ->financials([
                ['key' => 'due', 'label' => 'Follow-ups due', 'value' => (string) $due, 'tone' => $due ? 'danger' : 'ok', 'foot' => 'next action date reached'],
                ['key' => 'open', 'label' => 'Open', 'value' => (string) $open, 'foot' => $count['new'] . ' new · ' . $count['qualified'] . ' qualified · ' . $count['site_check'] . ' site check'],
                ['key' => 'quoted', 'label' => 'Quoted / negotiating', 'value' => (string) ($count['quoted'] + $count['negotiating']), 'tone' => 'warn'],
                ['key' => 'handover', 'label' => 'Won → installation', 'value' => $count['won'] . ' / ' . $count['installation'], 'foot' => 'won, not yet booked / job open'],
                ['key' => 'conversion', 'label' => 'Won vs lost (90 days)', 'value' => $won + $lost ? round(100 * $won / ($won + $lost)) . '%' : '—', 'foot' => $won . ' won · ' . $lost . ' lost'],
            ])
            ->table([
                ['key' => 'customer', 'label' => 'Customer', 'type' => 'avatar', 'sortable' => true],
                ['key' => 'stage', 'label' => 'Stage', 'type' => 'badge', 'sortable' => true],
                ['key' => 'next', 'label' => 'Next action', 'type' => 'badge', 'sortable' => true],
                ['key' => 'owner', 'label' => 'Salesperson', 'sortable' => true],
                ['key' => 'updated', 'label' => 'Updated', 'sortable' => true],
            ], $rows, [['key' => 'stage', 'label' => 'Stage'], ['key' => 'owner', 'label' => 'Salesperson']], ['pageSize' => 30])
            ->actions([['key' => 'leads', 'label' => 'All leads', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/leads', 'actor' => 'human']])
            ->meta(['capability' => 'salesPipeline', 'ttl' => 20, 'permissions' => []]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        return [];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
