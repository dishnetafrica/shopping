<?php

namespace App\Capability\Object;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ObjectCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Services\Sales\Brain\Knowledge;
use App\Services\Sales\SalesOwnership;
use App\Services\Sales\Training\Assessment;
use App\Services\Sales\Training\SalesTraining;

/**
 * One training module (Phase 5): its reading — the Sales Brain's PUBLISHED knowledge for the module's
 * topics, exactly as approved — and the person's last score in it. No other course text exists, so
 * training can never teach something management has not approved.
 */
final class TrainingModuleCapability implements ObjectCapability
{
    public function key(): string { return 'trainingModule'; }

    public function permission(): string { return 'menu:customers'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        return (new WorkspacePayload)->object(['type' => 'trainingModule'])->meta(['capability' => 'trainingModule']);
    }

    public function forId(CapabilityContext $ctx, string $id): WorkspacePayload
    {
        $m = Assessment::MODULES[$id] ?? null;
        if (! $m || ! SalesOwnership::enabled($ctx->tenant) || ! SalesTraining::ready()) {
            return (new WorkspacePayload)->object(['type' => 'trainingModule', 'title' => 'Not found'])->meta(['capability' => 'trainingModule', 'error' => 'not_found']);
        }
        $svc = app(SalesTraining::class);
        $reading = $svc->reading($ctx->tenant, $id);
        $last = $svc->status($ctx->tenant, (int) $ctx->user->id)['last'];
        $mine = $last ? ($last->module_scores[$id]['percent'] ?? null) : null;

        $answers = [];
        foreach ($reading as $p) {
            $label = (Knowledge::TOPICS[$p['topic']] ?? $p['topic']) . ' — ' . $p['title'];
            $text = $p['topic'] === 'terms' ? Knowledge::termsText((array) $p['data']) : Knowledge::toText($p['topic'], $p['body'], $p['data']);
            foreach (preg_split('/\R/', $text) ?: [] as $i => $line) {
                if (trim($line) !== '') {
                    $answers[] = ['q' => $i === 0 ? $p['title'] : '', 'value' => $line, 'sub' => $label];
                }
            }
        }
        if (! $answers) {
            $answers[] = ['q' => 'Reading', 'value' => 'Nothing is published for this module yet. Management publishes it in Sales Brain.', 'sub' => 'Reading', 'tone' => 'warn'];
        }

        return (new WorkspacePayload)
            ->object(['type' => 'trainingModule', 'id' => $id, 'title' => $m['label'], 'subtitle' => 'Weight ' . $m['weight'] . '% of the assessment · approved Sales Brain knowledge'])
            ->stats([
                ['key' => 'weight', 'label' => 'Weight', 'value' => $m['weight'] . '%', 'icon' => 'flag'],
                ['key' => 'items', 'label' => 'Reading', 'value' => count($reading) . ' item' . (count($reading) === 1 ? '' : 's'), 'icon' => 'doc'],
                ['key' => 'mine', 'label' => 'Your last score', 'value' => $mine === null ? '—' : rtrim(rtrim(number_format((float) $mine, 1), '0'), '.') . '%', 'icon' => 'flag',
                    'tone' => $mine === null ? 'default' : ($mine >= Assessment::PASS_MARK ? 'ok' : 'warn')],
            ])
            ->answers($answers)
            ->actions([['key' => 'back', 'label' => 'All modules', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/salesTraining', 'actor' => 'human']])
            ->meta(['capability' => 'trainingModule', 'ttl' => 60, 'permissions' => []]);
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
