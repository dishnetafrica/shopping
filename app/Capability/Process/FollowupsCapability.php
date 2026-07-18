<?php

namespace App\Capability\Process;

use App\Capability\CapabilityContext;
use App\Capability\Dto\WorkspacePayload;
use App\Models\CustomerProfile;
use App\Models\FollowupProposal;
use Illuminate\Support\Carbon;

/**
 * V4.1 — the Proposed Follow-ups inbox: the AI's plan for today, awaiting human
 * judgment. Every proposal: WHO, WHY NOW (trigger + reason + evidence), the DRAFT,
 * and per-proposal actions Send / Edit & send / Snooze 3d / Skip (with reason —
 * every skip teaches the engine in V4.3). Send delegates to the proven chat path.
 */
class FollowupsCapability
{
    public function build(CapabilityContext $ctx): WorkspacePayload
    {
        $pending = FollowupProposal::where('tenant_id', $ctx->tenantId())
            ->where(function ($q) {
                $q->where('status', 'draft')
                  ->orWhere(fn ($q2) => $q2->where('status', 'snoozed')->where('snoozed_until', '<=', now()));
            })
            ->orderByRaw("CASE confidence WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END")
            ->orderBy('created_at')->limit(60)->get();

        $names = CustomerProfile::withoutGlobalScopes()->where('tenant_id', $ctx->tenantId())
            ->whereIn('phone', $pending->pluck('customer_phone')->unique())->pluck('name', 'phone');
        $who = fn ($p) => trim((string) ($names[$p->customer_phone] ?? '')) ?: $p->customer_phone;

        $sentToday = FollowupProposal::where('tenant_id', $ctx->tenantId())
            ->where('status', 'sent')->where('updated_at', '>=', now()->startOfDay())->count();

        // V4.3 measurement — the AI desk's own scoreboard (30 days, pure math)
        $sent30 = FollowupProposal::where('tenant_id', $ctx->tenantId())
            ->where('status', 'sent')->where('updated_at', '>', now()->subDays(30))->get();
        $conv30 = $sent30->whereNotNull('influenced_order_id');
        $rev30 = \App\Models\Order::withoutGlobalScopes()
            ->whereIn('id', $conv30->pluck('influenced_order_id'))->sum('total');
        $convPct = $sent30->count() ? round(100 * $conv30->count() / $sent30->count()) : 0;
        $byTrigger = $pending->groupBy('trigger')->map->count();

        $rows = $pending->map(fn ($p) => [
            'id' => 'fp'.$p->id,
            'cells' => [
                'customer'  => ['value' => $who($p), 'sub' => $p->customer_phone],
                'why'       => ['value' => (string) $p->trigger_reason,
                                'sub' => strtoupper(str_replace('_', ' ', (string) $p->trigger))
                                     .($p->evidence ? ' · '.$p->evidence : '')],
                'draft'     => ['value' => mb_substr((string) $p->draft, 0, 220)],
                'conf'      => ['value' => ucfirst((string) $p->confidence), 'sort' => (string) $p->confidence],
                'age'       => ['value' => Carbon::parse($p->created_at)->diffForHumans(null, true),
                                'sort' => (string) $p->created_at],
            ],
        ])->values()->all();

        $actions = [];
        foreach ($pending->take(15) as $p) {
            $tag = $who($p).' — '.strtolower(str_replace('_', ' ', (string) $p->trigger));
            $ep  = '/papi/followups/'.$p->id;
            $actions[] = ['key' => 'send_'.$p->id, 'label' => 'Send — '.$tag, 'method' => 'POST',
                'endpoint' => $ep.'/approve', 'actor' => 'human', 'tone' => 'primary',
                'body' => [], 'confirm' => "Send to {$who($p)}?\n\n".$p->draft];
            $actions[] = ['key' => 'edit_'.$p->id, 'label' => 'Edit & send — '.$tag, 'method' => 'POST',
                'endpoint' => $ep.'/approve', 'actor' => 'human', 'submitLabel' => 'Send',
                'schema' => ['draft' => 'textarea'], 'prefill' => ['draft' => (string) $p->draft]];
            $actions[] = ['key' => 'snooze_'.$p->id, 'label' => 'Snooze 3d — '.$tag, 'method' => 'POST',
                'endpoint' => $ep.'/snooze', 'actor' => 'human', 'body' => []];
            $actions[] = ['key' => 'skip_'.$p->id, 'label' => 'Skip — '.$tag, 'method' => 'POST',
                'endpoint' => $ep.'/skip', 'actor' => 'human', 'submitLabel' => 'Skip',
                'schema' => ['skip_reason' => 'text'],
                'labels' => ['skip_reason' => 'Why skip? (every reason teaches the engine)']];
        }

        return (new WorkspacePayload)
            ->object([
                'type' => 'process:followups', 'title' => "AI's plan for today",
                'subtitle' => count($rows).' proposal(s) awaiting your judgment',
            ])
            ->financials([
                ['key' => 'pending', 'label' => 'Awaiting you', 'value' => (string) count($rows),
                 'tone' => count($rows) > 0 ? 'warn' : 'ok', 'foot' => 'approve from Quick actions'],
                ['key' => 'sent', 'label' => 'Sent today', 'value' => (string) $sentToday, 'foot' => 'human-approved'],
                ['key' => 'quotes', 'label' => 'Quote chases', 'value' => (string) ($byTrigger['quote_chase'] ?? 0), 'foot' => 'open quotations'],
                ['key' => 'reorders', 'label' => 'Reorders + winback', 'value' => (string) (($byTrigger['reorder'] ?? 0) + ($byTrigger['winback'] ?? 0)), 'foot' => 'cycle + dormant'],
                ['key' => 'conv', 'label' => 'Converted (30d)', 'value' => $convPct.'%',
                 'tone' => $convPct >= 30 ? 'ok' : 'default', 'foot' => $conv30->count().' of '.$sent30->count().' sent led to an order'],
                ['key' => 'rev', 'label' => 'Influenced revenue (30d)', 'value' => number_format((float) $rev30, 0),
                 'tone' => $rev30 > 0 ? 'ok' : 'default', 'foot' => '7-day attribution window — direction, not gospel'],
            ])
            ->table(
                [
                    ['key' => 'customer', 'label' => 'Customer', 'type' => 'avatar', 'sortable' => true],
                    ['key' => 'why', 'label' => 'Why now'],
                    ['key' => 'draft', 'label' => 'Proposed message'],
                    ['key' => 'conf', 'label' => 'Confidence', 'type' => 'badge', 'sortable' => true],
                    ['key' => 'age', 'label' => 'Waiting', 'align' => 'right', 'sortable' => true],
                ],
                $rows,
                [['key' => 'conf', 'label' => 'Confidence']],
                ['pageSize' => 20])
            ->actions($actions)
            ->meta(['capability' => 'followups', 'ttl' => 30, 'permissions' => []]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        return ['pending' => FollowupProposal::where('tenant_id', $ctx->tenantId())
            ->where('status', 'draft')->count()];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
