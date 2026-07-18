<?php

namespace App\Services\Relationship;

use App\Models\Message;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;

/**
 * The nightly study session (2026-07-18) — the employee version of "learning".
 *
 * Reads the DAY's conversations across ALL customers once and distills what a new
 * salesman absorbs in his first weeks at Konyo Konyo:
 *   · VOCABULARY — what customers actually call things ("the blue soap", "karton
 *     kabir", Juba Arabic terms, recurring misspellings) mapped to what they mean
 *   · GAPS — question patterns the bot handled poorly (report-only, for humans)
 *   · DEMAND — items asked for but not stocked (report-only, purchasing gold)
 *
 * THE LAW HOLDS: this never touches instructions. Vocabulary is DATA (like the
 * catalogue), stored as a capped, evidence-backed, human-deletable list in the
 * `shop_lexicon` setting and injected into the prompt as ground-truth glossary.
 * Gaps + demand go to the learning log and surface in relationship:review only.
 */
class LexiconLearner
{
    public const MAX_TERMS = 60;

    public function learn(Tenant $t, int $days = 1): array
    {
        $msgs = Message::withoutGlobalScope('tenant')
            ->where('tenant_id', $t->id)
            ->where('created_at', '>', now()->subDays(max(1, $days)))
            ->orderBy('id')->limit(1200)->get();
        if ($msgs->count() < 6) return ['terms' => 0, 'gaps' => 0, 'demand' => 0];

        $transcript = $msgs->map(fn ($m) => ($m->direction === 'in' ? 'CUSTOMER' : 'SHOP')
            .' ('.$m->customer_phone.'): '.mb_substr((string) $m->body, 0, 240))->implode("\n");

        $out = $this->callAi($transcript, $t);
        if (! is_array($out)) return ['terms' => 0, 'gaps' => 0, 'demand' => 0];

        // ---- vocabulary: merge, dedupe by term, cap, evidence required ----
        $lex = collect((array) $t->setting('shop_lexicon', []))->keyBy(fn ($e) => mb_strtolower((string) ($e['term'] ?? '')));
        $added = 0;
        foreach ((array) ($out['lexicon'] ?? []) as $e) {
            $term = trim((string) ($e['term'] ?? ''));
            $means = trim((string) ($e['means'] ?? ''));
            if ($term === '' || $means === '' || mb_strlen($term) > 60) continue;
            $k = mb_strtolower($term);
            if ($lex->has($k)) continue;   // first learning wins; humans edit via --forget
            $lex->put($k, ['term' => $term, 'means' => mb_substr($means, 0, 140),
                'evidence' => mb_substr(trim((string) ($e['evidence'] ?? '')), 0, 120),
                'learned_at' => now()->toDateString()]);
            $added++;
        }
        $t->putSetting('shop_lexicon', $lex->take(self::MAX_TERMS)->values()->all());

        // ---- gaps + demand: REPORT-ONLY (humans read these in relationship:review) ----
        $log = (array) $t->setting('learning_log', []);
        $log[now()->toDateString()] = [
            'gaps'   => array_slice(array_values(array_filter(array_map(fn ($g) => mb_substr(trim((string) $g), 0, 160), (array) ($out['gaps'] ?? [])))), 0, 8),
            'demand' => array_slice(array_values(array_filter(array_map(fn ($d) => mb_substr(trim((string) $d), 0, 100), (array) ($out['demand'] ?? [])))), 0, 8),
        ];
        $t->putSetting('learning_log', array_slice($log, -35, null, true));

        return ['terms' => $added, 'gaps' => count($log[now()->toDateString()]['gaps']),
                'demand' => count($log[now()->toDateString()]['demand'])];
    }

    /** Overridable in tests; null on any failure — a bad night teaches nothing, breaks nothing. */
    protected function callAi(string $transcript, Tenant $t): ?array
    {
        try {
            $key = (string) (config('openai.api_key') ?: env('OPENAI_API_KEY', ''));
            if ($key === '') return null;
            $resp = Http::withToken($key)->timeout(40)->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o-mini', 'temperature' => 0.1,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' =>
                            'You analyze ONE day of WhatsApp chats for a shop in Juba, South Sudan (customers write terse English, Juba Arabic, and mixes). Reply ONLY JSON: '
                            .'{"lexicon":[{"term":"exact word/phrase customers used","means":"what it refers to — ideally the exact catalogue product name","evidence":"short quote"}],'
                            .'"gaps":["question patterns the shop handled poorly or not at all — one line each"],'
                            .'"demand":["specific items customers asked for that the shop seems not to stock"]}. '
                            .'Lexicon: ONLY genuinely reusable vocabulary (local terms, nicknames, recurring misspellings, Arabic terms) — never one-off phrases, never customer names, max 10 per day. Empty arrays are fine and common.'],
                        ['role' => 'user', 'content' => mb_substr($transcript, 0, 24000)],
                    ],
                ]);
            $j = json_decode((string) $resp->json('choices.0.message.content'), true);

            return $resp->successful() && is_array($j) ? $j : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
