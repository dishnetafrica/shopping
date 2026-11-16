<?php

namespace App\Services\Sales\Training;

use App\Models\SalesKnowledgeEntry;
use App\Models\SalesKnowledgeVersion;
use App\Models\SalesTrainingAttempt;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Sales\Brain\Knowledge;
use App\Services\Sales\Brain\SalesBrain;
use App\Services\Sales\SalesOwnership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales training and certification (DishNet South Sudan plan, Phase 5) — the one writer of
 * assessment sittings. Rules are in Assessment (pure). Questions come only from PUBLISHED quiz entries
 * of the Sales Brain; reading material only from published knowledge.
 */
class SalesTraining
{
    public function __construct(private SalesBrain $brain) {}

    public static function ready(): bool
    {
        return SalesBrain::ready() && Schema::hasTable('sales_training_attempts');
    }

    /** module => published questions, plus the version ids they came from. */
    public function bank(Tenant $tenant): array
    {
        $bank = [];
        $versions = [];
        foreach ($this->brain->published($tenant, 'quiz') as $p) {
            $module = (string) ($p['data']['module'] ?? '');
            if (isset(Assessment::MODULES[$module])) {
                $bank[$module] = array_merge($bank[$module] ?? [], (array) ($p['data']['questions'] ?? []));
                $versions[$module] = $p['version'];
            }
        }

        return ['bank' => $bank, 'versions' => $versions];
    }

    /** Published reading for one module (quiz entries excluded). */
    public function reading(Tenant $tenant, string $module): array
    {
        $topics = Assessment::MODULES[$module]['topics'] ?? [];

        return array_values(array_filter($this->brain->published($tenant), fn ($p) => in_array($p['topic'], $topics, true)));
    }

    public function start(Tenant $tenant, User $user): array
    {
        if (! SalesOwnership::enabled($tenant) || (int) $user->tenant_id !== (int) $tenant->id) {
            return ['ok' => false, 'error' => 'not_available'];
        }
        $open = SalesTrainingAttempt::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('status', 'in_progress')->first();
        if ($open) {
            return ['ok' => true, 'id' => $open->id, 'resumed' => true];
        }
        ['bank' => $bank, 'versions' => $versions] = $this->bank($tenant);
        if ($missing = Assessment::missing($bank)) {
            return ['ok' => false, 'error' => 'not_ready', 'missing' => $missing,
                'message' => 'The assessment is not ready: no published questions yet for ' . implode(', ', array_map(fn ($m) => Assessment::MODULES[$m]['label'], $missing)) . '.'];
        }
        $a = SalesTrainingAttempt::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'status' => 'in_progress',
            'questions' => Assessment::pick($bank, random_int(1, PHP_INT_MAX)), 'quiz_versions' => $versions, 'started_at' => now(),
        ]);

        return ['ok' => true, 'id' => $a->id, 'resumed' => false];
    }

    /** @param array $answers question index => letter */
    public function submit(Tenant $tenant, User $user, int $attemptId, array $answers): array
    {
        return DB::transaction(function () use ($tenant, $user, $attemptId, $answers) {
            $a = SalesTrainingAttempt::withoutGlobalScopes()->where('tenant_id', $tenant->id)->lockForUpdate()->find($attemptId);
            if (! $a || (int) $a->user_id !== (int) $user->id) {
                return ['ok' => false, 'error' => 'not_found'];                 // only the person sitting it submits
            }
            if ($a->status !== 'in_progress') {
                return ['ok' => false, 'error' => 'already_submitted'];
            }
            $s = Assessment::score((array) $a->questions, $answers);
            $a->update(['status' => 'submitted', 'answers' => array_map(fn ($r) => $r['given'], $s['results']), 'module_scores' => $s['modules'],
                'score' => $s['score'], 'passed' => $s['passed'], 'submitted_at' => now()]);
            AuditLogger::log($tenant->id, 'sales_training', $a->id, 'submit', $user, null,
                ['score' => $s['score'], 'passed' => $s['passed'], 'modules' => array_map(fn ($m) => $m['percent'], $s['modules'])]);

            return ['ok' => true, 'score' => $s['score'], 'passed' => $s['passed']];
        });
    }

    /** A person's training position: certified (their last pass), last result, sitting in progress. */
    public function status(Tenant $tenant, int $userId): array
    {
        $q = SalesTrainingAttempt::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $userId);
        $last = (clone $q)->where('status', 'submitted')->orderByDesc('submitted_at')->orderByDesc('id')->first();
        $pass = (clone $q)->where('status', 'submitted')->where('passed', true)->orderByDesc('submitted_at')->orderByDesc('id')->first();

        return [
            'certified' => $pass !== null, 'certified_at' => $pass?->submitted_at, 'certified_score' => $pass?->score,
            'last' => $last, 'open_id' => (clone $q)->where('status', 'in_progress')->value('id'),
            'attempts' => (clone $q)->where('status', 'submitted')->count(),
        ];
    }

    /** The sales team (salespeople and managers) for management's view. */
    public function team(Tenant $tenant): array
    {
        return User::where('tenant_id', $tenant->id)->whereIn('role', array_merge(SalesOwnership::SELLING_ROLES, SalesOwnership::MANAGING_ROLES))
            ->orderBy('name')->get()->map(fn ($u) => ['user' => $u] + $this->status($tenant, (int) $u->id))->all();
    }

    /** Published quiz questions per module (counts), for the readiness card. */
    public function questionCounts(Tenant $tenant): array
    {
        $bank = $this->bank($tenant)['bank'];

        return array_map(fn ($m) => count($bank[$m] ?? []), array_combine(array_keys(Assessment::MODULES), array_keys(Assessment::MODULES)));
    }
}
