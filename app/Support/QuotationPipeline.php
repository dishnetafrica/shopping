<?php
namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Records each stage of the quote → PDF → store → send pipeline so a failure is *diagnosable* rather
 * than guessed. When a customer hears "preparing your quotation…" but no PDF arrives, the trace shows
 * exactly which stage failed (marker missing / pdf engine down / generate / send). Writes both a
 * structured log line and a BotTrace event (queryable in the activity trail). Never throws.
 */
class QuotationPipeline
{
    public const STAGES = ['marker_missing', 'marker_recovered', 'pdf_unavailable', 'gateway_no_send', 'no_items_in_marker',
        'no_match', 'dedup_skip', 'pdf_generate', 'pdf_store', 'wa_sent'];

    public static function stage(int $tenantId, string $phone, string $stage, bool $ok = true, string $quoteNo = '', string $detail = ''): void
    {
        $msg = 'quote.pipeline ' . $stage . ' ' . ($ok ? 'ok' : 'FAIL');
        try {
            Log::{$ok ? 'info' : 'warning'}($msg, ['tenant' => $tenantId, 'quote' => $quoteNo, 'phone' => $phone, 'detail' => $detail]);
        } catch (\Throwable $e) { /* logging must never break the pipeline */ }
        try {
            BotTrace::log($tenantId, 'quote_pipeline', $phone, $stage . ($ok ? '' : ' FAIL'), trim($quoteNo . ' ' . $detail));
        } catch (\Throwable $e) { /* tracing must never break the pipeline */ }
    }
}
