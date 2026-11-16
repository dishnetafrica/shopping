<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Services\Sales\Insights\Metrics;
use App\Services\Sales\Insights\SalesInsights;
use App\Services\Sales\Pipeline\PipelineService;
use App\Services\Sales\SalesOwnership;

/**
 * Sales dashboard (DishNet South Sudan plan, Phase 7) — the last 30 days per salesperson: new leads,
 * first-reply time, open and overdue follow-ups, quotations, won/lost and conversion, coach use and
 * certification. Owners/managers see the whole team; a salesperson sees their own row.
 */
final class SalesDashboardCapability implements ProcessCapability
{
    public function key(): string { return 'salesDashboard'; }

    public function permission(): string { return 'menu:customers'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        if (! SalesOwnership::enabled($ctx->tenant) || ! PipelineService::ready()) {
            return (new WorkspacePayload)
                ->object(['type' => 'process:salesDashboard', 'title' => 'Sales dashboard', 'subtitle' => 'Available for Internet providers'])
                ->table([['key' => 'person', 'label' => 'Salesperson']], [], [], ['pageSize' => 20])
                ->meta(['capability' => 'salesDashboard', 'ttl' => 300, 'permissions' => []]);
        }
        $manage = SalesOwnership::canManage($ctx->user);
        $team = app(SalesInsights::class)->team($ctx->tenant, $manage ? null : (int) $ctx->user->id);

        $rows = [];
        foreach ($team as $r) {
            $conv = $r['conversion'];
            $rows[] = [
                'person'    => ['value' => (string) $r['user']->name, 'sub' => ucfirst((string) $r['user']->role), 'avatar' => true],
                'new'       => ['value' => (string) $r['new_leads'], 'sort' => (string) $r['new_leads']],
                'reply'     => ['value' => Metrics::duration($r['first_reply_median']), 'sub' => $r['unanswered'] ? $r['unanswered'] . ' not answered by a person yet' : '',
                    'badge' => $r['first_reply_median'] === null ? 'default' : ($r['first_reply_median'] <= 15 ? 'ok' : ($r['first_reply_median'] <= 120 ? 'warn' : 'danger')),
                    'sort' => (string) ($r['first_reply_median'] ?? 999999)],
                'open'      => ['value' => (string) $r['open'], 'sub' => $r['overdue'] ? $r['overdue'] . ' follow-up' . ($r['overdue'] === 1 ? '' : 's') . ' overdue' : '',
                    'badge' => $r['overdue'] ? 'danger' : 'default', 'sort' => (string) $r['open']],
                'quotes'    => ['value' => (string) $r['quotes'], 'sort' => (string) $r['quotes']],
                'won'       => ['value' => $r['won'] . ' won · ' . $r['lost'] . ' lost', 'sort' => (string) $r['won']],
                'conv'      => ['value' => $conv === null ? '—' : $conv . '%', 'badge' => $conv === null ? 'default' : ($conv >= 50 ? 'ok' : 'warn'), 'sort' => (string) ($conv ?? -1)],
                'coach'     => ['value' => (string) $r['coach'], 'sort' => (string) $r['coach']],
                'cert'      => $r['certified'] === null ? ['value' => '—', 'sort' => '0']
                    : ['value' => $r['certified'] ? 'Certified' : 'Not yet', 'badge' => $r['certified'] ? 'ok' : 'warn', 'sub' => $r['last_score'] !== null ? 'last ' . rtrim(rtrim(number_format((float) $r['last_score'], 1), '0'), '.') . '%' : '', 'sort' => $r['certified'] ? '1' : '0'],
            ];
        }

        $sum = fn ($k) => array_sum(array_column($team, $k));
        $won = $sum('won');
        $lost = $sum('lost');
        $med = Metrics::median(array_column($team, 'first_reply_median'));
        $conv = Metrics::conversion($won, $lost);
        $actions = [
            ['key' => 'pipeline', 'label' => 'Pipeline', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/salesPipeline', 'actor' => 'human'],
        ];
        if ($manage) {
            $actions[] = ['key' => 'quality', 'label' => 'Quality monitoring', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/salesQuality', 'actor' => 'human', 'tone' => 'primary'];
            $actions[] = ['key' => 'training', 'label' => 'Training results', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/trainingResults', 'actor' => 'human'];
        }

        return (new WorkspacePayload)
            ->object(['type' => 'process:salesDashboard', 'title' => 'Sales dashboard', 'subtitle' => 'Last ' . Metrics::DAYS . ' days' . ($manage ? ' · whole team' : ' · your results')])
            ->financials([
                ['key' => 'new', 'label' => 'New leads', 'value' => (string) $sum('new_leads')],
                ['key' => 'reply', 'label' => 'First reply (median)', 'value' => Metrics::duration($med), 'tone' => $med === null ? 'default' : ($med <= 15 ? 'ok' : 'warn'), 'foot' => 'from enquiry to a person\'s first reply'],
                ['key' => 'overdue', 'label' => 'Follow-ups overdue', 'value' => (string) $sum('overdue'), 'tone' => $sum('overdue') ? 'danger' : 'ok', 'foot' => $sum('open') . ' open leads'],
                ['key' => 'quotes', 'label' => 'Quotations', 'value' => (string) $sum('quotes')],
                ['key' => 'conv', 'label' => 'Conversion', 'value' => $conv === null ? '—' : $conv . '%', 'foot' => $won . ' won · ' . $lost . ' lost'],
                ['key' => 'coach', 'label' => 'Coach suggestions', 'value' => (string) $sum('coach')],
            ])
            ->table([
                ['key' => 'person', 'label' => 'Salesperson', 'type' => 'avatar', 'sortable' => true],
                ['key' => 'new', 'label' => 'New leads', 'sortable' => true],
                ['key' => 'reply', 'label' => 'First reply', 'type' => 'badge', 'sortable' => true],
                ['key' => 'open', 'label' => 'Open', 'type' => 'badge', 'sortable' => true],
                ['key' => 'quotes', 'label' => 'Quotations', 'sortable' => true],
                ['key' => 'won', 'label' => 'Won / lost', 'sortable' => true],
                ['key' => 'conv', 'label' => 'Conversion', 'type' => 'badge', 'sortable' => true],
                ['key' => 'coach', 'label' => 'Coach used', 'sortable' => true],
                ['key' => 'cert', 'label' => 'Training', 'type' => 'badge', 'sortable' => true],
            ], $rows, [], ['pageSize' => 30])
            ->actions($actions)
            ->meta(['capability' => 'salesDashboard', 'ttl' => 60, 'permissions' => []]);
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
