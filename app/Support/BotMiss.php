<?php
namespace App\Support;

use App\Models\BotMiss as Row;

/**
 * Record a term the bot failed to match. Upserts + increments a per-tenant counter so the
 * top misses surface the real vocabulary gaps. Never throws — a logging failure must never
 * break the customer reply.
 *
 * Capture mode (for bot:replay):
 *   When capture is started, record() pushes misses into an in-memory buffer INSTEAD of the
 *   DB. This lets the replay harness score historical messages without polluting the live
 *   bot_misses table and without the records being lost on the replay's transaction rollback.
 *   Default behaviour (capture off) is unchanged: misses are written to the DB as before.
 */
class BotMiss
{
    /** null = off (write to DB as normal). array = capturing into memory. */
    protected static ?array $capture = null;

    public static function startCapture(): void { self::$capture = []; }
    public static function stopCapture(): void  { self::$capture = null; }
    public static function isCapturing(): bool  { return self::$capture !== null; }

    /** All captured misses since startCapture(): list of ['tenant_id','term','sample']. */
    public static function captured(): array    { return self::$capture ?? []; }
    public static function capturedCount(): int { return self::$capture === null ? 0 : count(self::$capture); }

    public static function record(int $tenantId, string $term, ?string $sample = null): void
    {
        try {
            $t = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $term)));
            if ($t === '' || mb_strlen($t) > 120) return;

            // Replay / dry-run: capture in memory, never touch the DB.
            if (self::$capture !== null) {
                self::$capture[] = [
                    'tenant_id' => $tenantId,
                    'term'      => $t,
                    'sample'    => $sample !== null ? mb_substr($sample, 0, 200) : null,
                ];
                return;
            }

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
