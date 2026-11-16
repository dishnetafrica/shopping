<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Services\Sales\SalesOwnership;
use App\Services\Sales\Training\Assessment;
use App\Services\Sales\Training\SalesTraining;

/**
 * Sales Training (DishNet South Sudan plan, Phase 5) — the five modules, the person's own
 * certification and last result, and the assessment. Internet-provider businesses only.
 * Managers also get the team's results (Training results).
 */
final class SalesTrainingCapability implements ProcessCapability
{
    public function key(): string { return 'salesTraining'; }

    public function permission(): string { return 'menu:customers'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        if (! SalesOwnership::enabled($ctx->tenant) || ! SalesTraining::ready()) {
            return (new WorkspacePayload)
                ->object(['type' => 'process:salesTraining', 'title' => 'Sales Training', 'subtitle' => 'Available for Internet providers'])
                ->table([['key' => 'module', 'label' => 'Module']], [], [], ['pageSize' => 10])
                ->meta(['capability' => 'salesTraining', 'ttl' => 300, 'permissions' => []]);
        }
        $svc = app(SalesTraining::class);
        $me = $svc->status($ctx->tenant, (int) $ctx->user->id);
        $counts = $svc->questionCounts($ctx->tenant);
        $missing = array_keys(array_filter($counts, fn ($n) => $n === 0));
        $lastMods = (array) ($me['last']?->module_scores ?? []);

        $rows = [];
        foreach (Assessment::MODULES as $key => $m) {
            $reading = count($svc->reading($ctx->tenant, $key));
            $mine = $lastMods[$key]['percent'] ?? null;
            $rows[] = [
                'module'  => ['value' => $m['label'], 'sub' => $reading . ' published reading item' . ($reading === 1 ? '' : 's'), 'avatar' => true],
                'weight'  => ['value' => $m['weight'] . '%', 'sort' => (string) $m['weight']],
                'mine'    => $mine === null ? ['value' => '—', 'sort' => '-1']
                    : ['value' => rtrim(rtrim(number_format((float) $mine, 1), '0'), '.') . '%', 'badge' => $mine >= Assessment::PASS_MARK ? 'ok' : 'warn', 'sort' => (string) $mine],
                'questions' => ['value' => $counts[$key] > 0 ? $counts[$key] . ' published' : 'None yet', 'badge' => $counts[$key] > 0 ? 'ok' : 'danger', 'sort' => (string) $counts[$key]],
                'link'    => '/api/v1/workspace/trainingModule/' . $key,
            ];
        }

        $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.') . '%';
        $cards = [
            ['key' => 'certified', 'label' => 'Your certification', 'value' => $me['certified'] ? 'Certified' : 'Not yet',
                'tone' => $me['certified'] ? 'ok' : 'warn', 'foot' => $me['certified'] ? 'passed ' . $me['certified_at']?->format('j M Y') . ' with ' . $pct($me['certified_score']) : 'pass mark ' . $pct(Assessment::PASS_MARK)],
            ['key' => 'last', 'label' => 'Your last result', 'value' => $me['last'] ? $pct($me['last']->score) : '—',
                'tone' => $me['last'] ? ($me['last']->passed ? 'ok' : 'danger') : 'default', 'foot' => $me['attempts'] . ' assessment' . ($me['attempts'] === 1 ? '' : 's') . ' taken'],
            ['key' => 'ready', 'label' => 'Assessment', 'value' => $missing ? 'Not ready' : 'Ready', 'tone' => $missing ? 'danger' : 'ok',
                'foot' => $missing ? 'no published questions for ' . implode(', ', array_map(fn ($k) => Assessment::MODULES[$k]['label'], $missing)) : 'up to ' . Assessment::PER_MODULE . ' questions per module'],
        ];
        $manage = SalesOwnership::canManage($ctx->user);
        if ($manage) {
            $team = $svc->team($ctx->tenant);
            $certified = count(array_filter($team, fn ($t) => $t['certified']));
            $cards[] = ['key' => 'team', 'label' => 'Team certified', 'value' => $certified . ' of ' . count($team), 'tone' => count($team) && $certified === count($team) ? 'ok' : 'warn', 'foot' => 'salespeople and managers'];
        }

        $actions = [];
        if ($me['open_id']) {
            $actions[] = ['key' => 'continue', 'label' => 'Continue your assessment', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/trainingAttempt/' . $me['open_id'], 'actor' => 'human', 'tone' => 'primary'];
        } elseif (! $missing) {
            $actions[] = ['key' => 'start_assessment', 'label' => $me['attempts'] ? 'Take the assessment again' : 'Start the assessment', 'method' => 'POST',
                'endpoint' => '/api/v1/workspace/salesTraining/_/start_assessment', 'actor' => 'human', 'tone' => 'primary',
                'confirm' => 'Start the assessment now? Read the five modules first. When it starts, "Continue your assessment" opens it.'];
        }
        if ($me['last']) {
            $actions[] = ['key' => 'last_result', 'label' => 'Your last result', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/trainingAttempt/' . $me['last']->id, 'actor' => 'human'];
        }
        if ($manage) {
            $actions[] = ['key' => 'team_results', 'label' => 'Team results', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/trainingResults', 'actor' => 'human'];
        }

        return (new WorkspacePayload)
            ->object(['type' => 'process:salesTraining', 'title' => 'Sales Training',
                'subtitle' => 'Read the five modules, then take the assessment. Pass mark ' . $pct(Assessment::PASS_MARK) . ' overall, weighted 25/20/25/20/10.'])
            ->financials($cards)
            ->table([
                ['key' => 'module', 'label' => 'Module', 'type' => 'avatar'],
                ['key' => 'weight', 'label' => 'Weight', 'sortable' => true],
                ['key' => 'mine', 'label' => 'Your last score', 'type' => 'badge', 'sortable' => true],
                ['key' => 'questions', 'label' => 'Questions', 'type' => 'badge', 'sortable' => true],
            ], $rows, [], ['pageSize' => 10])
            ->actions($actions)
            ->meta(['capability' => 'salesTraining', 'ttl' => 20, 'permissions' => []]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        return [];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        if ($action !== 'start_assessment') {
            return ['ok' => false, 'error' => 'unknown_action'];
        }
        $r = app(SalesTraining::class)->start($ctx->tenant, $ctx->user);

        return $r['ok']
            ? $r + ['message' => $r['resumed'] ? 'You already have an assessment open — press "Continue your assessment".' : 'Assessment started — press "Continue your assessment" to answer it.']
            : $r + ['message' => $r['message'] ?? 'The assessment is not available.'];
    }
}
