<?php

namespace App\Capability\Object;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ObjectCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\SalesTrainingAttempt;
use App\Models\User;
use App\Services\Sales\SalesOwnership;
use App\Services\Sales\Training\Assessment;
use App\Services\Sales\Training\SalesTraining;

/**
 * One assessment sitting (Phase 5). While open, only the person sitting it sees it: the questions and
 * a form to submit their answers — the answer key is never sent before submission. Once submitted:
 * the overall and per-module scores, pass or not, and for each question the answer given, the correct
 * one and why. Owners and managers can open anyone's submitted result.
 */
final class TrainingAttemptCapability implements ObjectCapability
{
    public function key(): string { return 'trainingAttempt'; }

    public function permission(): string { return 'menu:customers'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        return (new WorkspacePayload)->object(['type' => 'trainingAttempt'])->meta(['capability' => 'trainingAttempt']);
    }

    private function find(CapabilityContext $ctx, int $id): ?SalesTrainingAttempt
    {
        if (! SalesOwnership::enabled($ctx->tenant) || ! SalesTraining::ready()) {
            return null;
        }
        $a = SalesTrainingAttempt::withoutGlobalScopes()->where('tenant_id', $ctx->tenantId())->find($id);
        if (! $a) {
            return null;
        }
        $mine = (int) $a->user_id === (int) $ctx->user->id;
        // Someone else's sitting: managers only, and only once it is submitted.
        return $mine || (SalesOwnership::canManage($ctx->user) && $a->status === 'submitted') ? $a : null;
    }

    public function forId(CapabilityContext $ctx, string $id): WorkspacePayload
    {
        $a = $this->find($ctx, (int) $id);
        if (! $a) {
            return (new WorkspacePayload)->object(['type' => 'trainingAttempt', 'title' => 'Not found'])->meta(['capability' => 'trainingAttempt', 'error' => 'not_found']);
        }
        $who = (string) (User::where('tenant_id', $ctx->tenantId())->whereKey($a->user_id)->value('name') ?? 'Staff');
        $qs = array_values((array) $a->questions);
        $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.') . '%';

        if ($a->status === 'in_progress') {
            $answers = [];
            $schema = [];
            $labels = [];
            foreach ($qs as $i => $q) {
                $section = Assessment::MODULES[$q['module']]['label'];
                $answers[] = ['q' => 'Question ' . ($i + 1), 'value' => $q['q'], 'sub' => $section];
                foreach ($q['options'] as $j => $o) {
                    $answers[] = ['q' => '', 'value' => Assessment::LETTERS[$j] . ') ' . $o, 'sub' => $section];
                }
                $schema['q' . ($i + 1)] = 'enum:—,' . implode(',', array_slice(Assessment::LETTERS, 0, count($q['options'])));
                $labels['q' . ($i + 1)] = ($i + 1) . '. ' . $q['q'] . ' — ' . Assessment::optionsLine($q['options']);
            }

            return (new WorkspacePayload)
                ->object(['type' => 'trainingAttempt', 'id' => (string) $a->id, 'title' => 'Sales assessment', 'subtitle' => count($qs) . ' questions · started ' . $a->started_at?->format('j M Y, H:i')])
                ->headline('In progress', 'status', 'warn')
                ->answers($answers)
                ->actions([['key' => 'submit', 'label' => 'Answer and submit', 'method' => 'POST', 'endpoint' => '/api/v1/workspace/trainingAttempt/' . $a->id . '/submit',
                    'actor' => 'human', 'tone' => 'primary', 'submitLabel' => 'Submit answers', 'schema' => $schema, 'labels' => $labels]])
                ->meta(['capability' => 'trainingAttempt', 'ttl' => 0, 'permissions' => []]);
        }

        $stats = [['key' => 'score', 'label' => 'Overall', 'value' => $pct($a->score), 'icon' => 'flag', 'tone' => $a->passed ? 'ok' : 'danger']];
        foreach ((array) $a->module_scores as $k => $m) {
            $stats[] = ['key' => $k, 'label' => $m['label'] . ' (' . $m['weight'] . '%)', 'value' => $pct($m['percent']) . ' · ' . $m['correct'] . '/' . $m['total'], 'icon' => 'doc',
                'tone' => $m['percent'] >= Assessment::PASS_MARK ? 'ok' : 'warn'];
        }
        $given = array_values((array) $a->answers);
        $answers = [];
        foreach ($qs as $i => $q) {
            $right = Assessment::LETTERS[$q['answer']];
            $g = $given[$i] ?? null;
            $ok = $g === $right;
            $answers[] = ['q' => 'Question ' . ($i + 1), 'value' => $q['q'], 'sub' => Assessment::MODULES[$q['module']]['label'], 'tone' => $ok ? 'ok' : 'danger'];
            $answers[] = ['q' => '', 'sub' => Assessment::MODULES[$q['module']]['label'], 'tone' => $ok ? 'ok' : 'danger',
                'value' => ($ok ? 'Correct: ' : 'Answered ' . ($g ? $g . ') ' . ($q['options'][array_search($g, Assessment::LETTERS, true)] ?? '') : 'nothing') . ' — correct: ')
                    . $right . ') ' . $q['options'][$q['answer']] . (! $ok && ($q['why'] ?? '') !== '' ? ' (' . $q['why'] . ')' : '')];
        }

        return (new WorkspacePayload)
            ->object(['type' => 'trainingAttempt', 'id' => (string) $a->id, 'title' => 'Sales assessment — ' . $who,
                'subtitle' => 'Submitted ' . $a->submitted_at?->format('j M Y, H:i') . ' · pass mark ' . $pct(Assessment::PASS_MARK)])
            ->headline($a->passed ? 'Passed — certified' : 'Not passed', 'result', $a->passed ? 'ok' : 'danger')
            ->stats($stats)
            ->answers($answers)
            ->actions([['key' => 'back', 'label' => 'Sales Training', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/salesTraining', 'actor' => 'human']])
            ->meta(['capability' => 'trainingAttempt', 'ttl' => 60, 'permissions' => []]);
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        if ($action !== 'submit') {
            return ['ok' => false, 'error' => 'unknown_action'];
        }
        $a = $this->find($ctx, (int) ($input['id'] ?? 0));
        if (! $a) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $answers = [];
        foreach (array_keys((array) $a->questions) as $i) {
            $answers[$i] = (string) ($input['q' . ($i + 1)] ?? '');
        }
        $r = app(SalesTraining::class)->submit($ctx->tenant, $ctx->user, (int) $a->id, $answers);
        if (! $r['ok']) {
            return $r + ['message' => $r['error'] === 'already_submitted' ? 'This assessment was already submitted.' : 'Not found.'];
        }
        $p = rtrim(rtrim(number_format((float) $r['score'], 1), '0'), '.');

        return $r + ['message' => $r['passed'] ? "Passed with {$p}% — you are certified." : "{$p}% — not passed (pass mark 80%). Read the modules again and retake it."];
    }
}
