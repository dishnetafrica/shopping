<?php
namespace App\Services\Knowledge;

use App\Models\KnowledgeFact;
use App\Services\Knowledge\Dto\Fact;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY writer of knowledge_facts. Enforces append-only versioning: a changed value
 * supersedes the prior version (is_current flips, supersedes_id chains) — facts are never
 * overwritten or deleted. Unchanged values write nothing. All in one transaction so two
 * is_current rows for a key can't exist.
 */
class BusinessMemory
{
    public function record(int $tenantId, Fact $fact, ?int $eventId = null): KnowledgeFact
    {
        return DB::transaction(function () use ($tenantId, $fact, $eventId) {
            $current = KnowledgeFact::where('tenant_id', $tenantId)
                ->where('capability', $fact->capability)
                ->where('fact_type', $fact->factType)
                ->where('key', $fact->key)
                ->where('is_current', true)
                ->lockForUpdate()
                ->first();

            // No change → no new version (idempotent).
            if ($current && ! FactVersioning::changed($current->value_json, $fact->value)) {
                return $current;
            }

            $version = FactVersioning::nextVersion($current?->version);

            if ($current) {
                $current->is_current = false;   // supersede, never delete/overwrite
                $current->save();
            }

            return KnowledgeFact::create($fact->toArray() + [
                'tenant_id'     => $tenantId,
                'version'       => $version,
                'is_current'    => true,
                'supersedes_id' => $current?->id,
                'event_id'      => $eventId,
            ]);
        });
    }

    public function current(int $tenantId, string $capability, string $factType, string $key): ?KnowledgeFact
    {
        return KnowledgeFact::where('tenant_id', $tenantId)->where('capability', $capability)
            ->where('fact_type', $factType)->where('key', $key)->where('is_current', true)->first();
    }

    /** Full version history (newest first) — feeds the Timeline and "what changed?". */
    public function history(int $tenantId, string $capability, string $factType, string $key): Collection
    {
        return KnowledgeFact::where('tenant_id', $tenantId)->where('capability', $capability)
            ->where('fact_type', $factType)->where('key', $key)->orderByDesc('version')->get();
    }
}
