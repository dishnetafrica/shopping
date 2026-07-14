<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\Lead;
use App\Services\Bot\LeadScorer;
use Illuminate\Support\Carbon;

/**
 * Leads (CRM) — the v3 face of the panel Leads screen. Reads the Lead model directly (allowed for a
 * Process capability) for the list + pipeline KPIs; every WRITE goes through the proven panel endpoints
 * /papi/lead-save and /papi/lead-action via the shell's queryWrite path, so there is no duplicated
 * business logic and nothing in the engine changes.
 */
final class LeadListCapability implements ProcessCapability
{
    private const MAX_ROWS = 300;
    private const OPEN = ['new', 'assigned', 'contacted', 'qualified'];
    private const SOURCES = 'whatsapp,website,facebook,instagram,referral,walk_in,phone_call,email,manual';
    private const STATUSES = 'new,assigned,contacted,qualified,won,lost';

    public function key(): string { return 'leads'; }

    public function permission(): string { return 'menu:customers'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenantId();
        $canEdit = $ctx->can('menu:customers');
        $scorer = new LeadScorer();

        // recipients → assignee pick-list + phone/name maps for assign writes and display
        $recips = method_exists($ctx->tenant, 'leadRecipients') ? $ctx->tenant->leadRecipients() : [];
        $nameByPhone = []; $phoneByName = []; $recipNames = [];
        foreach ($recips as $r) {
            $ph = (string) ($r['phone'] ?? ''); if ($ph === '') { continue; }
            $nm = trim((string) ($r['name'] ?? '')) ?: $ph;
            $nameByPhone[$ph] = $nm;
            $disp = isset($phoneByName[$nm]) ? ($nm . ' (' . $ph . ')') : $nm;
            $phoneByName[$disp] = $ph;
            $recipNames[] = $disp;
        }
        $comboAssignee = 'combo:' . (count($recipNames) ? implode('|', $recipNames) : '');

        $base = fn () => Lead::where('tenant_id', $t)->where('intent', 'lead');

        $rows = [];
        $leads = $base()->latest('id')->limit(self::MAX_ROWS)->get();
        foreach ($leads as $l) {
            $status = (string) $l->status;
            $score = (int) $l->lead_score;
            $name = (string) ($l->customer_name ?: '(no name)');
            $phone = (string) $l->customer_phone;
            $assignedName = $l->assigned_to ? ($nameByPhone[$l->assigned_to] ?? $l->assigned_to) : '';
            $fu = $l->next_followup_at ? Carbon::parse($l->next_followup_at) : null;
            $fuOverdue = $fu && $fu->isPast() && ! in_array($status, ['won', 'lost'], true);

            $statusBadge = ['new' => 'info', 'assigned' => 'accent', 'contacted' => 'warn', 'qualified' => 'ok', 'won' => 'ok', 'lost' => 'danger'][$status] ?? 'default';
            $bandTxt = $scorer->band($score);
            $bandTone = $score >= 70 ? 'danger' : ($score >= 45 ? 'warn' : 'default');

            $btns = [];
            if ($canEdit) {
                $btns[] = ['icon' => 'edit', 'title' => 'Edit lead', 'action' => $this->editForm($l, $comboAssignee, $phoneByName, $nameByPhone)];
                if (! in_array($status, ['won', 'lost'], true)) {
                    $btns[] = ['icon' => 'check', 'title' => 'Mark won', 'action' => $this->quick($l->id, 'won', 'Mark ' . $name . ' as WON?')];
                    $btns[] = ['icon' => 'trash', 'title' => 'Mark lost', 'tone' => 'danger', 'action' => $this->quick($l->id, 'lost', 'Mark ' . $name . ' as lost?')];
                }
            }

            $rows[] = [
                'lead'     => ['value' => $name, 'sub' => $phone . ($l->company ? ' · ' . $l->company : ''), 'avatar' => true],
                'interest' => (string) ($l->interest ?: '—'),
                'source'   => (string) ($l->source ?: '—'),
                'status'   => ['value' => ucfirst($status), 'badge' => $statusBadge, 'sort' => $status],
                'score'    => ['value' => $bandTxt . ' · ' . $score, 'tone' => $bandTone, 'sort' => $score],
                'followup' => $fu
                    ? ['value' => ($fuOverdue ? '⚠ ' : '') . $fu->format('d M'), 'tone' => $fuOverdue ? 'danger' : '', 'sort' => $fu->timestamp]
                    : ['value' => '—', 'sort' => 0],
                'assigned' => $assignedName ?: '—',
                'act'      => ['buttons' => $btns],
                // hidden filter fields
                'tag'      => (string) ($l->tag ?: ''),
            ];
        }

        // pipeline KPIs over the whole table (independent of any client filter)
        $stats = [
            'new' => (clone $base())->where('status', 'new')->count(),
            'hot' => (clone $base())->whereIn('status', self::OPEN)->where('lead_score', '>=', 70)->count(),
            'overdue' => (clone $base())->whereNotNull('next_followup_at')->where('next_followup_at', '<', now())->whereNotIn('status', ['won', 'lost'])->count(),
            'won' => (clone $base())->where('status', 'won')->where('updated_at', '>=', now()->subDays(30))->count(),
            'total' => (clone $base())->count(),
            'optin' => (clone $base())->where('marketing_opt_in', true)->count(),
        ];

        $columns = [
            ['key' => 'lead', 'label' => 'Lead', 'type' => 'avatar', 'sortable' => true],
            ['key' => 'interest', 'label' => 'Interest', 'sortable' => true],
            ['key' => 'source', 'label' => 'Source', 'sortable' => true],
            ['key' => 'status', 'label' => 'Status', 'type' => 'badge', 'sortable' => true],
            ['key' => 'score', 'label' => 'Score', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'followup', 'label' => 'Follow-up', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'assigned', 'label' => 'Assigned', 'sortable' => true],
            ['key' => 'act', 'label' => '', 'type' => 'action', 'align' => 'right'],
        ];

        $addLead = ['key' => 'add_lead', 'label' => 'Add lead', 'queryWrite' => true, 'method' => 'GET', 'endpoint' => '/papi/lead-save', 'actor' => 'human', 'tone' => 'primary',
            'schema' => ['name' => 'text', 'phone' => 'text', 'company' => 'text', 'interest' => 'text', 'source' => 'enum:' . self::SOURCES, 'status' => 'enum:' . self::STATUSES, 'lead_score' => 'number', 'tag' => 'text', 'next_followup' => 'text', 'notes' => 'text'],
            'labels' => ['name' => 'Name', 'phone' => 'Phone (required)', 'interest' => 'Interest / what they want', 'lead_score' => 'Score (0–100)', 'next_followup' => 'Next follow-up (e.g. 2026-07-20)', 'notes' => 'Notes'],
            'prefill' => ['source' => 'manual', 'status' => 'new', 'lead_score' => 50]];

        return (new WorkspacePayload)
            ->object([
                'type' => 'process:leads', 'title' => 'Leads',
                'subtitle' => number_format($stats['total']) . ' leads · ' . $stats['new'] . ' new · ' . $stats['hot'] . ' hot',
            ])
            ->financials([
                ['key' => 'new', 'label' => 'New', 'value' => (string) $stats['new'], 'tone' => $stats['new'] > 0 ? 'info' : 'default', 'foot' => 'awaiting first touch'],
                ['key' => 'hot', 'label' => 'Hot', 'value' => (string) $stats['hot'], 'tone' => $stats['hot'] > 0 ? 'warn' : 'default', 'foot' => 'score ≥ 70, open'],
                ['key' => 'overdue', 'label' => 'Follow-up overdue', 'value' => (string) $stats['overdue'], 'tone' => $stats['overdue'] > 0 ? 'danger' : 'ok', 'foot' => 'past due date'],
                ['key' => 'won', 'label' => 'Won (30d)', 'value' => (string) $stats['won'], 'tone' => 'ok', 'foot' => 'last 30 days'],
                ['key' => 'optin', 'label' => 'Marketing opt-in', 'value' => (string) $stats['optin'], 'foot' => 'of ' . number_format($stats['total']) . ' total'],
            ])
            ->table($columns, $rows,
                [
                    ['key' => 'status', 'label' => 'Status'],
                    ['key' => 'source', 'label' => 'Source'],
                    ['key' => 'assigned', 'label' => 'Assigned'],
                ],
                ['pageSize' => 25])
            ->actions($canEdit ? [$addLead] : [])
            ->meta(['capability' => 'leads', 'ttl' => 30, 'permissions' => ['menu:customers']]);
    }

    private function editForm(Lead $l, string $comboAssignee, array $phoneByName, array $nameByPhone): array
    {
        $fu = $l->next_followup_at ? Carbon::parse($l->next_followup_at)->format('Y-m-d') : '';

        return ['key' => 'edit_lead', 'label' => 'Edit lead — ' . ($l->customer_name ?: $l->customer_phone), 'queryWrite' => true, 'method' => 'GET', 'endpoint' => '/papi/lead-save', 'actor' => 'human', 'tone' => 'primary',
            'schema' => ['name' => 'text', 'phone' => 'text', 'company' => 'text', 'interest' => 'text', 'source' => 'enum:' . self::SOURCES, 'status' => 'enum:' . self::STATUSES, 'lead_score' => 'number', 'tag' => 'text', 'next_followup' => 'text', 'notes' => 'text'],
            'labels' => ['name' => 'Name', 'phone' => 'Phone', 'interest' => 'Interest', 'lead_score' => 'Score (0–100)', 'next_followup' => 'Next follow-up (YYYY-MM-DD, blank to clear)', 'notes' => 'Notes'],
            'prefill' => [
                'name' => (string) $l->customer_name, 'phone' => (string) $l->customer_phone, 'company' => (string) $l->company,
                'interest' => (string) $l->interest, 'source' => (string) ($l->source ?: 'manual'), 'status' => (string) ($l->status ?: 'new'),
                'lead_score' => (int) $l->lead_score, 'tag' => (string) $l->tag, 'next_followup' => $fu, 'notes' => (string) $l->notes,
            ],
            'body' => ['id' => $l->id]];
    }

    private function quick(int $id, string $action, string $confirm): array
    {
        return ['key' => 'lead_' . $action, 'label' => ucfirst($action), 'queryWrite' => true, 'method' => 'GET', 'endpoint' => '/papi/lead-action', 'actor' => 'human',
            'body' => ['id' => $id, 'action' => $action], 'confirm' => $confirm];
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        $t = $ctx->tenantId();
        $base = fn () => Lead::where('tenant_id', $t)->where('intent', 'lead');

        return [
            'total' => (clone $base())->count(),
            'new' => (clone $base())->where('status', 'new')->count(),
            'hot' => (clone $base())->whereIn('status', self::OPEN)->where('lead_score', '>=', 70)->count(),
            'overdue' => (clone $base())->whereNotNull('next_followup_at')->where('next_followup_at', '<', now())->whereNotIn('status', ['won', 'lost'])->count(),
        ];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
