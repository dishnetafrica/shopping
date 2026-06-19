<?php
namespace App\Support;

use App\Models\BotMiss as Row;

/**
 * Record a term the bot failed to match. Upserts + increments a per-tenant counter so the
 * top misses surface the real vocabulary gaps. Never throws — a logging failure must never
 * break the customer reply.
 */
class BotMiss
{
    public static function record(int $tenantId, string $term, ?string $sample = null): void
    {
        try {
            $t = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $term)));
            if ($t === '' || mb_strlen($t) > 120) return;

            $row = Row::firstOrNew(['tenant_id' => $tenantId, 'term' => $t]);
            $row->count = (int) ($row->count ?? 0) + 1;
            $row->last_seen_at = now();
            if ($sample) $row->sample = mb_substr($sample, 0, 200);
            if (! $row->exists) $row->resolved = false;
            $row->save();
        } catch (\Throwable $e) {
            // swallow — never break the reply over a log write
        }
    }
}
