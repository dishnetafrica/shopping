<?php
namespace App\Services\Knowledge;

use App\Models\OperationalState;
use Illuminate\Support\Facades\DB;

/**
 * The single, generic store for short-lived operational toggles (today / dated) — kept strictly
 * separate from durable knowledge_facts. This is the long-term engine concept; the legacy
 * DailyState becomes a thin facade over this (Drop 2b). Capabilities MUST write operational
 * state only through here, never directly to DailyState.
 *
 * "today" rows are scoped by date and simply ignored on read once the date passes (no cron) —
 * a stale row for yesterday is never returned for today.
 */
class OperationalStateStore
{
    public function set(int $tenantId, string $capability, string $key, array $value, string $scope = 'today', ?string $date = null): void
    {
        $date = $this->resolveDate($scope, $date);
        DB::transaction(function () use ($tenantId, $capability, $key, $value, $scope, $date) {
            OperationalState::where('tenant_id', $tenantId)->where('capability', $capability)
                ->where('key', $key)->where('effective_date', $date)->delete();
            OperationalState::create([
                'tenant_id' => $tenantId, 'capability' => $capability, 'key' => $key,
                'value_json' => $value, 'scope' => $scope, 'effective_date' => $date,
            ]);
        });
    }

    public function get(int $tenantId, string $capability, string $key, ?string $date = null): ?array
    {
        $date ??= date('Y-m-d');
        $row = OperationalState::where('tenant_id', $tenantId)->where('capability', $capability)
            ->where('key', $key)->where('effective_date', $date)->first();
        return $row?->value_json;
    }

    public function forget(int $tenantId, string $capability, string $key, ?string $date = null): void
    {
        $date ??= date('Y-m-d');
        OperationalState::where('tenant_id', $tenantId)->where('capability', $capability)
            ->where('key', $key)->where('effective_date', $date)->delete();
    }

    /** All keys for a capability on a date → [key => value]. */
    public function snapshot(int $tenantId, string $capability, ?string $date = null): array
    {
        $date ??= date('Y-m-d');
        return OperationalState::where('tenant_id', $tenantId)->where('capability', $capability)
            ->where('effective_date', $date)->get()->mapWithKeys(fn ($r) => [$r->key => $r->value_json])->all();
    }

    private function resolveDate(string $scope, ?string $date): string
    {
        if ($date) return $date;
        return $scope === 'today' ? date('Y-m-d') : date('Y-m-d');
    }
}
