<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Services\Sales\SalesOwnership;
use App\Services\Sales\Training\Assessment;
use App\Services\Sales\Training\SalesTraining;

/** Training results (Phase 5) — management's view of the team: certified or not, scores per module. */
final class TrainingResultsCapability implements ProcessCapability
{
    public function key(): string { return 'trainingResults'; }

    public function permission(): string { return 'menu:customers'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $empty = fn (string $why) => (new WorkspacePayload)
            ->object(['type' => 'process:trainingResults', 'title' => 'Training results', 'subtitle' => $why])
            ->table([['key' => 'person', 'label' => 'Person']], [], [], ['pageSize' => 20])
            ->meta(['capability' => 'trainingResults', 'ttl' => 300, 'permissions' => []]);
        if (! SalesOwnership::enabled($ctx->tenant) || ! SalesTraining::ready()) {
            return $empty('Available for Internet providers');
        }
        if (! SalesOwnership::canManage($ctx->user)) {
            return $empty('For owners and managers');
        }
        $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.') . '%';
        $rows = [];
        foreach (app(SalesTraining::class)->team($ctx->tenant) as $t) {
            $last = $t['last'];
            $row = [
                'person' => ['value' => (string) $t['user']->name, 'sub' => ucfirst((string) $t['user']->role), 'avatar' => true],
                'status' => $t['certified'] ? ['value' => 'Certified ' . $t['certified_at']?->format('j M Y'), 'badge' => 'ok', 'sort' => '2']
                    : ($t['open_id'] ? ['value' => 'Assessment in progress', 'badge' => 'warn', 'sort' => '1'] : ['value' => 'Not certified', 'badge' => 'danger', 'sort' => '0']),
                'last'   => $last ? ['value' => $pct($last->score), 'badge' => $last->passed ? 'ok' : 'danger', 'sort' => (string) $last->score] : ['value' => '—', 'sort' => '-1'],
                'attempts' => ['value' => (string) $t['attempts'], 'sort' => (string) $t['attempts']],
            ];
            foreach (Assessment::MODULES as $k => $m) {
                $v = $last ? ($last->module_scores[$k]['percent'] ?? null) : null;
                $row['m_' . $k] = $v === null ? ['value' => '—', 'sort' => '-1'] : ['value' => $pct($v), 'badge' => $v >= Assessment::PASS_MARK ? 'ok' : 'warn', 'sort' => (string) $v];
            }
            if ($last) {
                $row['link'] = '/api/v1/workspace/trainingAttempt/' . $last->id;
            }
            $rows[] = $row;
        }
        $cols = [
            ['key' => 'person', 'label' => 'Person', 'type' => 'avatar', 'sortable' => true],
            ['key' => 'status', 'label' => 'Certification', 'type' => 'badge', 'sortable' => true],
            ['key' => 'last', 'label' => 'Last score', 'type' => 'badge', 'sortable' => true],
        ];
        foreach (Assessment::MODULES as $k => $m) {
            $cols[] = ['key' => 'm_' . $k, 'label' => $m['label'] . ' (' . $m['weight'] . '%)', 'type' => 'badge', 'sortable' => true];
        }
        $cols[] = ['key' => 'attempts', 'label' => 'Attempts', 'sortable' => true];
        $certified = count(array_filter($rows, fn ($r) => $r['status']['sort'] === '2'));

        return (new WorkspacePayload)
            ->object(['type' => 'process:trainingResults', 'title' => 'Training results', 'subtitle' => $certified . ' of ' . count($rows) . ' certified · pass mark ' . $pct(Assessment::PASS_MARK)])
            ->table($cols, $rows, [], ['pageSize' => 30])
            ->actions([['key' => 'back', 'label' => 'Sales Training', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/salesTraining', 'actor' => 'human']])
            ->meta(['capability' => 'trainingResults', 'ttl' => 30, 'permissions' => []]);
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
