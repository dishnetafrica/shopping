<?php

namespace App\Services\Sales\Pipeline;

use App\Models\CustomerProfile;
use App\Models\Lead;
use App\Models\Quotation;
use App\Models\SalesPipelineEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\AuditLogger;
use App\Services\Field\WorkOrderService;
use App\Services\Sales\Brain\SalesBrain;
use App\Services\Sales\SalesOwnership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The standard sales pipeline (DishNet South Sudan plan, Phase 6) — the ONE writer of a lead's stage,
 * qualification, coverage result and next action. Rules are in Pipeline (pure).
 *
 * Who: the lead's salesperson (an unassigned lead is claimed by the first salesperson who works it,
 * through SalesOwnership) or an owner/manager. Every move is written to sales_pipeline_events and the
 * audit log. Reaching "installation" hands the sale to the field team: an installation job is created
 * through WorkOrderService (the one creator of jobs), linked to the accepted quotation.
 */
class PipelineService
{
    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasColumn('leads', 'stage') && Schema::hasTable('sales_pipeline_events');
    }

    public static function stage(Lead $lead): string
    {
        return Pipeline::stageOf($lead->stage, $lead->status);
    }

    /** What the gates look at, read from the records themselves. */
    public function facts(Tenant $tenant, Lead $lead): array
    {
        $phone = (string) $lead->customer_phone;
        $quotes = Quotation::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('customer_phone', $phone)->orderByDesc('id');
        $open = (clone $quotes)->whereNotIn('status', ['declined', 'expired', 'archived'])->first();
        $accepted = (clone $quotes)->whereIn('status', ['accepted', 'converted'])->first();
        $wo = $lead->work_order_id ? WorkOrder::withoutGlobalScopes()->where('tenant_id', $tenant->id)->find($lead->work_order_id) : null;

        return [
            'qualification' => (array) ($lead->qualification ?? []),
            'coverage_status' => (string) ($lead->coverage_status ?? ''),
            'open_quote' => $open?->only(['id', 'quote_no', 'status', 'total']),
            'accepted_quote' => $accepted?->only(['id', 'quote_no', 'status', 'total']),
            'work_order_status' => $wo?->status,
            'work_order' => $wo?->only(['id', 'number', 'status']),
        ];
    }

    /** null if this person may work the lead (claiming it if unassigned), else an error array. */
    public function denied(Tenant $tenant, User $user, Lead $lead): ?array
    {
        if (! SalesOwnership::enabled($tenant) || (int) $user->tenant_id !== (int) $tenant->id) {
            return ['ok' => false, 'error' => 'not_available'];
        }
        if (SalesOwnership::canManage($user)) {
            return null;
        }
        if ($lead->owner_user_id && (int) $lead->owner_user_id !== (int) $user->id) {
            return ['ok' => false, 'error' => 'owned_by_other', 'message' => 'This customer belongs to another salesperson.'];
        }
        $a = app(SalesOwnership::class)->checkAccess($tenant, $user, (string) $lead->customer_phone, true);
        if (! $a['allowed']) {
            return ['ok' => false, 'error' => 'owned_by_other', 'message' => 'This customer belongs to another salesperson.'];
        }

        return null;
    }

    public function move(Tenant $tenant, User $user, int $leadId, string $to, string $reason = ''): array
    {
        return DB::transaction(function () use ($tenant, $user, $leadId, $to, $reason) {
            $lead = Lead::withoutGlobalScopes()->where('tenant_id', $tenant->id)->lockForUpdate()->find($leadId);
            if (! $lead) {
                return ['ok' => false, 'error' => 'not_found'];
            }
            if ($deny = $this->denied($tenant, $user, $lead)) {
                return $deny;
            }
            $from = self::stage($lead);
            $facts = $this->facts($tenant, $lead);
            if ($err = Pipeline::check($from, $to, $facts, $reason, SalesOwnership::canManage($user))) {
                return ['ok' => false] + $err;
            }

            $upd = ['stage' => $to, 'stage_changed_at' => now(), 'status' => Pipeline::statusFor($to, (string) $lead->status)];
            $fu = Pipeline::followUp($to);
            $upd['next_followup_at'] = $fu ? now()->addDays($fu[0]) : null;
            $upd['next_action'] = $fu ? $fu[1] : null;
            if ($to === 'lost') {
                $upd['lost_reason'] = mb_substr(trim($reason), 0, 300);
            }
            if ($to === 'contacted') {
                $upd['last_contacted_at'] = now();
            }
            if (! $lead->owner_user_id && ! SalesOwnership::canManage($user)) {
                $upd['owner_user_id'] = $user->id;                    // the salesperson who works it owns it
            }
            if ($to === 'installation' && ! $lead->work_order_id) {
                $upd['work_order_id'] = $this->handOver($tenant, $user, $lead, $facts)->id;
            }
            $lead->update($upd);
            SalesPipelineEvent::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'from_stage' => $from, 'to_stage' => $to,
                'user_id' => $user->id, 'reason' => trim($reason) !== '' ? mb_substr(trim($reason), 0, 300) : null, 'created_at' => now()]);
            AuditLogger::log($tenant->id, 'lead', $lead->id, 'stage', $user, ['stage' => $from], ['stage' => $to, 'work_order_id' => $upd['work_order_id'] ?? $lead->work_order_id], trim($reason) ?: null);

            return ['ok' => true, 'stage' => $to, 'work_order_id' => $lead->work_order_id];
        });
    }

    /** Record what qualification found. */
    public function qualify(Tenant $tenant, User $user, int $leadId, array $in): array
    {
        $q = [
            'service_line' => strtolower(trim((string) ($in['service_line'] ?? ''))),
            'area' => mb_substr(trim((string) ($in['area'] ?? '')), 0, 160),
            'customer_type' => strtolower(trim((string) ($in['customer_type'] ?? ''))),
            'users' => max(0, (int) ($in['users'] ?? 0)),
            'use' => mb_substr(trim((string) ($in['use'] ?? '')), 0, 300),
        ];
        if ($q['service_line'] !== '' && ! in_array($q['service_line'], Pipeline::SERVICE_LINES, true)) {
            return ['ok' => false, 'error' => 'bad_service_line'];
        }
        if ($q['customer_type'] !== '' && ! in_array($q['customer_type'], Pipeline::CUSTOMER_TYPES, true)) {
            return ['ok' => false, 'error' => 'bad_customer_type'];
        }

        return $this->write($tenant, $user, $leadId, 'qualify', function (Lead $lead) use ($q) {
            $old = (array) ($lead->qualification ?? []);
            $lead->update(['qualification' => array_merge($old, array_filter($q, fn ($v) => $v !== '' && $v !== 0))]);

            return [$old, $lead->qualification];
        });
    }

    /** Check the PUBLISHED coverage for the qualified area and service; store the answer. */
    public function checkCoverage(Tenant $tenant, User $user, int $leadId): array
    {
        return $this->write($tenant, $user, $leadId, 'coverage', function (Lead $lead) use ($tenant) {
            $q = (array) ($lead->qualification ?? []);
            $area = (string) ($q['area'] ?? '');
            if ($area === '') {
                return ['error' => 'area_required'];
            }
            $lines = match ($q['service_line'] ?? '') {
                'starlink' => ['starlink'], 'fiber' => ['fiber'], default => ['fiber', 'starlink'],
            };
            $rank = ['covered' => 3, 'survey' => 2, 'none' => 1];
            $best = null;
            foreach ($lines as $l) {
                $r = app(SalesBrain::class)->coverage($tenant, $l, $area) + ['service_line' => $l];
                if (! $best || $rank[$r['status']] > $rank[$best['status']]) {
                    $best = $r;
                }
            }
            $old = $lead->coverage_status;
            $lead->update(['coverage_status' => $best['status']]);

            return [['coverage_status' => $old], ['coverage_status' => $best['status'], 'area' => $best['area'], 'service_line' => $best['service_line']], 'result' => $best];
        });
    }

    /** A site survey's outcome (needed where coverage is not published as covered). */
    public function recordSurvey(Tenant $tenant, User $user, int $leadId, bool $passed, string $note): array
    {
        return $this->write($tenant, $user, $leadId, 'survey', function (Lead $lead) use ($passed, $note) {
            $old = $lead->coverage_status;
            $lead->update(['coverage_status' => $passed ? 'survey_ok' : 'survey_failed',
                'notes' => trim(trim((string) $lead->notes) . "\n" . now()->format('j M') . ' site survey ' . ($passed ? 'passed' : 'failed') . ($note !== '' ? ': ' . $note : ''))]);

            return [['coverage_status' => $old], ['coverage_status' => $lead->coverage_status], 'reason' => $note];
        });
    }

    public function setFollowUp(Tenant $tenant, User $user, int $leadId, string $date, string $action): array
    {
        try {
            $at = \Illuminate\Support\Carbon::parse($date, (string) ($tenant->setting('timezone') ?: config('app.timezone')))->setTime(9, 0);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'bad_date'];
        }

        return $this->write($tenant, $user, $leadId, 'followup', function (Lead $lead) use ($at, $action) {
            $old = ['next_followup_at' => $lead->next_followup_at?->toIso8601String(), 'next_action' => $lead->next_action];
            $lead->update(['next_followup_at' => $at, 'next_action' => mb_substr(trim($action), 0, 200) ?: $lead->next_action]);

            return [$old, ['next_followup_at' => $at->toIso8601String(), 'next_action' => $lead->next_action]];
        });
    }

    /** Hand the sale to the field team: one installation job, linked to the accepted quotation. */
    private function handOver(Tenant $tenant, User $user, Lead $lead, array $facts): WorkOrder
    {
        $phone = (string) $lead->customer_phone;
        $profile = CustomerProfile::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('phone', $phone)->first();
        $q = (array) ($lead->qualification ?? []);

        return WorkOrderService::forTenant($tenant)->create([
            'type' => 'installation', 'customer_id' => $profile?->id, 'customer_name' => $lead->customer_name ?: $profile?->name,
            'customer_phone' => $phone, 'site_address' => $q['area'] ?? null, 'quotation_id' => $facts['accepted_quote']['id'] ?? null,
            'notes' => 'From sales lead #' . $lead->id . ' (' . ($q['service_line'] ?? 'service not recorded') . '), handed over by ' . $user->name
                . '. Activation and billing in UISP after installation.',
            'created_by' => (string) $user->name,
        ]);
    }

    /** Run a non-stage write under the lead lock with the same access rule, and audit it. */
    private function write(Tenant $tenant, User $user, int $leadId, string $action, callable $fn): array
    {
        return DB::transaction(function () use ($tenant, $user, $leadId, $action, $fn) {
            $lead = Lead::withoutGlobalScopes()->where('tenant_id', $tenant->id)->lockForUpdate()->find($leadId);
            if (! $lead) {
                return ['ok' => false, 'error' => 'not_found'];
            }
            if ($deny = $this->denied($tenant, $user, $lead)) {
                return $deny;
            }
            if (! $lead->owner_user_id && ! SalesOwnership::canManage($user)) {
                $lead->update(['owner_user_id' => $user->id]);
            }
            $r = $fn($lead);
            if (isset($r['error'])) {
                return ['ok' => false] + $r;
            }
            AuditLogger::log($tenant->id, 'lead', $lead->id, $action, $user, (array) ($r[0] ?? []), (array) ($r[1] ?? []), $r['reason'] ?? null);

            return ['ok' => true] + (isset($r['result']) ? ['result' => $r['result']] : []);
        });
    }
}
