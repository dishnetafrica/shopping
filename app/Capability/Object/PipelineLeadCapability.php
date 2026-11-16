<?php

namespace App\Capability\Object;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ObjectCapability;
use App\Capability\Dto\TimelineEvent;
use App\Capability\Dto\WorkspacePayload;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Lead;
use App\Models\SalesPipelineEvent;
use App\Models\User;
use App\Services\Sales\Pipeline\Pipeline;
use App\Services\Sales\Pipeline\PipelineService;
use App\Services\Sales\SalesOwnership;

/**
 * One lead in the pipeline (Phase 6): stage, what qualification found, coverage, quotation, the
 * installation job, the next action and the stage history; and the steps — record qualification,
 * check coverage, record a site survey, move stage (gated), set the next follow-up. Every write goes
 * through PipelineService. A salesperson never opens a colleague's lead.
 */
final class PipelineLeadCapability implements ObjectCapability
{
    public function key(): string { return 'pipelineLead'; }

    public function permission(): string { return 'menu:customers'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        return (new WorkspacePayload)->object(['type' => 'pipelineLead'])->meta(['capability' => 'pipelineLead']);
    }

    private function find(CapabilityContext $ctx, int $id): ?Lead
    {
        if (! SalesOwnership::enabled($ctx->tenant) || ! PipelineService::ready()) {
            return null;
        }
        $l = Lead::withoutGlobalScopes()->where('tenant_id', $ctx->tenantId())->where('intent', 'lead')->find($id);
        if ($l && ! SalesOwnership::canManage($ctx->user) && $l->owner_user_id && (int) $l->owner_user_id !== (int) $ctx->user->id) {
            return null;
        }

        return $l;
    }

    public function forId(CapabilityContext $ctx, string $id): WorkspacePayload
    {
        $l = $this->find($ctx, (int) $id);
        if (! $l) {
            return (new WorkspacePayload)->object(['type' => 'pipelineLead', 'title' => 'Not found'])->meta(['capability' => 'pipelineLead', 'error' => 'not_found']);
        }
        $svc = app(PipelineService::class);
        $stage = PipelineService::stage($l);
        $f = $svc->facts($ctx->tenant, $l);
        $q = $f['qualification'];
        $names = User::where('tenant_id', $ctx->tenantId())->pluck('name', 'id')->all();
        $tz = (string) ($ctx->tenant->setting('timezone') ?: config('app.timezone'));
        $who = trim((string) $l->customer_name) ?: '+' . $l->customer_phone;
        $manage = SalesOwnership::canManage($ctx->user);
        $covLabel = ['covered' => 'Covered (published)', 'survey' => 'Site survey needed', 'none' => 'Not served there', 'survey_ok' => 'Site survey passed', 'survey_failed' => 'Site survey failed'];

        $stats = [
            ['key' => 'stage', 'label' => 'Stage', 'value' => Pipeline::label($stage), 'icon' => 'flag', 'tone' => Pipeline::tone($stage)],
            ['key' => 'owner', 'label' => 'Salesperson', 'value' => $l->owner_user_id ? ($names[$l->owner_user_id] ?? '—') : 'Unassigned', 'icon' => 'user', 'tone' => $l->owner_user_id ? 'default' : 'warn'],
            ['key' => 'next', 'label' => 'Next action', 'icon' => 'clock',
                'value' => in_array($stage, ['active', 'lost'], true) ? '—' : (($l->next_action ?: 'Reply to the customer') . ($l->next_followup_at ? ' · ' . $l->next_followup_at->copy()->setTimezone($tz)->format('j M') : '')),
                'tone' => $l->next_followup_at && $l->next_followup_at->isPast() && ! in_array($stage, ['active', 'lost'], true) ? 'danger' : 'default'],
            ['key' => 'coverage', 'label' => 'Coverage', 'value' => $covLabel[$f['coverage_status']] ?? 'Not checked', 'icon' => 'flag',
                'tone' => in_array($f['coverage_status'], ['covered', 'survey_ok'], true) ? 'ok' : ($f['coverage_status'] === '' ? 'default' : 'warn')],
        ];

        $answers = [
            ['q' => 'Customer', 'value' => $who . ' · +' . $l->customer_phone, 'sub' => 'Customer'],
            ['q' => 'Asked about', 'value' => (string) ($l->interest ?: $l->message ?: '—'), 'sub' => 'Customer'],
            ['q' => 'Service', 'value' => isset($q['service_line']) ? ucfirst($q['service_line']) : 'Not recorded', 'sub' => 'Qualification'],
            ['q' => 'Area / landmark', 'value' => (string) ($q['area'] ?? 'Not recorded'), 'sub' => 'Qualification'],
            ['q' => 'Home or business', 'value' => isset($q['customer_type']) ? ucfirst($q['customer_type']) : 'Not recorded', 'sub' => 'Qualification'],
            ['q' => 'People / devices', 'value' => isset($q['users']) ? (string) $q['users'] : 'Not recorded', 'sub' => 'Qualification'],
            ['q' => 'Use', 'value' => (string) ($q['use'] ?? '—'), 'sub' => 'Qualification'],
            ['q' => 'Quotation', 'sub' => 'Sale', 'value' => $f['accepted_quote'] ? $f['accepted_quote']['quote_no'] . ' — accepted' : ($f['open_quote'] ? $f['open_quote']['quote_no'] . ' — ' . $f['open_quote']['status'] : 'None yet')],
            ['q' => 'Installation job', 'sub' => 'Sale', 'value' => $f['work_order'] ? $f['work_order']['number'] . ' — ' . $f['work_order']['status'] : 'Not handed over yet'],
        ];
        if ($stage === 'active') {
            $answers[] = ['q' => 'Next', 'value' => 'Installed. Activation and billing are done in UISP.', 'sub' => 'Sale', 'tone' => 'ok'];
        }
        if ($stage === 'lost') {
            $answers[] = ['q' => 'Lost because', 'value' => (string) ($l->lost_reason ?: '—'), 'sub' => 'Sale', 'tone' => 'danger'];
        }
        // What the next stage still needs — so nobody has to guess why a move is refused.
        $order = array_keys(Pipeline::STAGES);
        $nextStage = $order[array_search($stage, $order, true) + 1] ?? null;
        if ($nextStage && ! in_array($stage, ['lost', 'active'], true) && $nextStage !== 'lost') {
            foreach (Pipeline::requirements($nextStage, $f) as $m) {
                $answers[] = ['q' => 'Needed for "' . Pipeline::label($nextStage) . '"', 'value' => $m, 'sub' => 'To move on', 'tone' => 'warn'];
            }
        }

        $timeline = [];
        foreach (SalesPipelineEvent::withoutGlobalScopes()->where('tenant_id', $ctx->tenantId())->where('lead_id', $l->id)->orderByDesc('id')->limit(50)->get() as $e) {
            $timeline[] = (new TimelineEvent(at: $e->created_at?->toIso8601String() ?? '', type: 'stage', title: 'Stage: ' . Pipeline::label((string) $e->to_stage),
                detail: trim('from ' . Pipeline::label((string) $e->from_stage) . ($e->reason ? ' — ' . $e->reason : '')), actor: $e->user_id ? ($names[$e->user_id] ?? 'Staff') : 'System'))->toArray();
        }

        $base = '/api/v1/workspace/pipelineLead/' . $l->id;
        $actions = [];
        $convId = $l->conversation_id ?: Conversation::withoutGlobalScopes()->where('tenant_id', $ctx->tenantId())->where('customer_phone', $l->customer_phone)->value('id');
        if ($convId) {
            $actions[] = ['key' => 'open_chat', 'label' => 'Open chat', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/chat/' . $convId, 'actor' => 'human', 'tone' => 'primary'];
        }
        $profileId = CustomerProfile::withoutGlobalScopes()->where('tenant_id', $ctx->tenantId())->where('phone', $l->customer_phone)->value('id');
        if ($profileId) {
            $actions[] = ['key' => 'open_customer', 'label' => 'Customer (quotations)', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/customer/' . $profileId, 'actor' => 'human'];
        }
        if ($f['work_order']) {
            $actions[] = ['key' => 'open_job', 'label' => 'Installation job', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/workOrder/' . $f['work_order']['id'], 'actor' => 'human'];
        }
        if (! in_array($stage, ['active'], true)) {
            $actions[] = ['key' => 'qualify', 'label' => 'Record qualification', 'method' => 'POST', 'endpoint' => $base . '/qualify', 'actor' => 'human', 'submitLabel' => 'Save',
                'schema' => ['service_line' => 'enum:' . implode(',', Pipeline::SERVICE_LINES), 'area' => 'text', 'customer_type' => 'enum:' . implode(',', Pipeline::CUSTOMER_TYPES), 'users' => 'number', 'use' => 'text'],
                'labels' => ['service_line' => 'Service', 'area' => 'Exact area and a landmark', 'customer_type' => 'Home, business or organisation', 'users' => 'How many people or devices', 'use' => 'Main use (optional)'],
                'prefill' => array_intersect_key($q, array_flip(['service_line', 'area', 'customer_type', 'users', 'use']))];
            $actions[] = ['key' => 'check_coverage', 'label' => 'Check coverage', 'method' => 'POST', 'endpoint' => $base . '/check_coverage', 'actor' => 'human',
                'confirm' => 'Check the published coverage for "' . ($q['area'] ?? '—') . '"?'];
            $actions[] = ['key' => 'record_survey', 'label' => 'Record site survey', 'method' => 'POST', 'endpoint' => $base . '/record_survey', 'actor' => 'human', 'submitLabel' => 'Save',
                'schema' => ['result' => 'enum:passed,failed', 'note' => 'text'], 'labels' => ['result' => 'Survey result', 'note' => 'What was found']];
            $choices = Pipeline::choices($stage, $manage);
            if ($choices) {
                $actions[] = ['key' => 'move', 'label' => 'Move stage', 'method' => 'POST', 'endpoint' => $base . '/move', 'actor' => 'human', 'tone' => 'primary', 'submitLabel' => 'Move',
                    'schema' => ['stage' => 'enum:' . implode(',', $choices), 'reason' => 'text'],
                    'labels' => ['stage' => 'Move to (' . implode(' · ', array_map(fn ($s) => $s . ' = ' . Pipeline::label($s), $choices)) . ')', 'reason' => 'Reason (required for lost or going back)']];
            }
            if ($stage !== 'lost') {
                $actions[] = ['key' => 'follow_up', 'label' => 'Set next follow-up', 'method' => 'POST', 'endpoint' => $base . '/follow_up', 'actor' => 'human', 'submitLabel' => 'Save',
                    'schema' => ['date' => 'date', 'action' => 'text'], 'labels' => ['date' => 'When', 'action' => 'What to do'],
                    'prefill' => ['action' => (string) ($l->next_action ?? '')]];
            }
        }

        return (new WorkspacePayload)
            ->object(['type' => 'pipelineLead', 'id' => (string) $l->id, 'title' => $who, 'subtitle' => 'Sales lead #' . $l->id . ' · ' . Pipeline::label($stage), 'status' => $stage])
            ->headline(Pipeline::label($stage), 'stage', Pipeline::tone($stage))
            ->stats($stats)
            ->answers($answers)
            ->timeline($timeline)
            ->actions($actions)
            ->meta(['capability' => 'pipelineLead', 'ttl' => 15, 'permissions' => []]);
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        $l = $this->find($ctx, (int) ($input['id'] ?? 0));
        if (! $l) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $svc = app(PipelineService::class);
        $r = match ($action) {
            'move' => $svc->move($ctx->tenant, $ctx->user, (int) $l->id, (string) ($input['stage'] ?? ''), (string) ($input['reason'] ?? '')),
            'qualify' => $svc->qualify($ctx->tenant, $ctx->user, (int) $l->id, $input),
            'check_coverage' => $svc->checkCoverage($ctx->tenant, $ctx->user, (int) $l->id),
            'record_survey' => $svc->recordSurvey($ctx->tenant, $ctx->user, (int) $l->id, ($input['result'] ?? '') === 'passed', trim((string) ($input['note'] ?? ''))),
            'follow_up' => $svc->setFollowUp($ctx->tenant, $ctx->user, (int) $l->id, (string) ($input['date'] ?? ''), (string) ($input['action'] ?? '')),
            default => ['ok' => false, 'error' => 'unknown_action'],
        };
        if (! ($r['ok'] ?? false)) {
            return $r + ['message' => match ($r['error'] ?? '') {
                'gate' => 'Not yet — still needed: ' . implode('; ', $r['missing'] ?? []) . '.',
                'reason_required' => 'Please give a reason.',
                'manager_only' => 'Only an owner or a manager can reopen a lost lead.',
                'final' => 'This customer is installed and handed to UISP; the lead is closed.',
                'area_required' => 'Record the area first (Record qualification).',
                'owned_by_other' => 'This customer belongs to another salesperson.',
                'bad_date' => 'That date was not understood.',
                default => 'That step is not possible.',
            }];
        }

        return $r + ['message' => match ($action) {
            'move' => 'Moved to ' . Pipeline::label((string) $r['stage']) . ($r['stage'] === 'installation' ? ' — installation job created for the field team.' : '.'),
            'check_coverage' => 'Coverage: ' . ['covered' => 'covered', 'survey' => 'site survey needed', 'none' => 'not served there'][$r['result']['status']]
                . ($r['result']['area'] ? ' (' . $r['result']['area'] . ', ' . $r['result']['service_line'] . ')' : ' (place not in the published coverage list)') . '.',
            default => 'Saved.',
        }];
    }
}
