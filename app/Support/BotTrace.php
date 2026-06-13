<?php
namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Records one step of the inbound-message pipeline so you can trace where a
 * message stopped — the equivalent of watching an n8n execution. Writes to the
 * bot_events table (shown on /panel/diagnostics) and to the app log. It is
 * wrapped in try/catch so tracing can never break message handling.
 */
class BotTrace
{
    public static function log(?int $tenantId, string $trace, ?string $from, string $stage, ?string $detail = null, ?int $ms = null): void
    {
        try {
            DB::table('bot_events')->insert([
                'tenant_id'  => $tenantId,
                'trace'      => substr($trace, 0, 64),
                'phone'      => $from ? substr($from, 0, 32) : null,
                'stage'      => $stage,
                'detail'     => $detail ? substr($detail, 0, 300) : null,
                'ms'         => $ms,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // tracing must never break the pipeline
        }
        Log::info('bot.trace', compact('tenantId', 'trace', 'from', 'stage', 'detail', 'ms'));
    }
}
