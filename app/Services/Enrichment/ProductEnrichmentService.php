<?php

namespace App\Services\Enrichment;

use Illuminate\Support\Facades\Log;

/**
 * ProductEnrichmentService
 * ------------------------
 * Classifies each product into a CONTROLLED VOCABULARY of product_type values
 * (cooking_oil, skincare_oil, rice, snack, spice_mix, …) so the catalogue matcher's
 * +250 product_type tier can rank "real" grocery items above look-alikes.
 *
 * Design rules (per the operations spec):
 *   - Controlled vocabulary ONLY — the model can never invent a type; anything outside
 *     VOCAB is coerced to 'other' with capped confidence.
 *   - Every classification carries a confidence in [0,1].
 *   - High confidence  (>= AUTO_APPROVE) -> applied automatically (status 'approved').
 *   - Medium confidence ([REVIEW_FLOOR, AUTO_APPROVE)) -> queued for admin review.
 *   - Low confidence / 'other' -> left untouched (skip).
 *   - Feature-flagged: disabled unless an API key is configured.
 *   - Dry-run first: plan() never writes; the console command persists only with --apply.
 *
 * The pure pieces (validate / decision / plan-with-injected-classifier) are unit-tested
 * in qa/enrichment.php. The live model call (classifyOne) is isolated and fails safe to
 * null, and must be verified on staging with a real key (like the other LLM layers).
 */
class ProductEnrichmentService
{
    /** Closed grocery taxonomy. The model MUST choose from this list. */
    public const VOCAB = [
        // edible oils vs non-edible oils (the headline ambiguity)
        'cooking_oil', 'skincare_oil', 'cosmetic_oil', 'essential_oil', 'hair_oil', 'massage_oil',
        // staples
        'rice', 'flour', 'pulses', 'sugar', 'salt', 'cereal', 'noodles_pasta',
        // flavour
        'spice', 'spice_mix', 'sauce_condiment', 'pickle',
        // packaged food
        'snack', 'biscuit', 'confectionery', 'bakery', 'canned', 'frozen',
        // drinks
        'beverage', 'tea_coffee', 'water', 'dairy',
        // non-food grocery aisles
        'cleaning', 'personal_care', 'household', 'baby', 'health', 'stationery',
        // fallback
        'other',
    ];

    /** >= this confidence is applied automatically. */
    public const AUTO_APPROVE = 0.85;

    /** [REVIEW_FLOOR, AUTO_APPROVE) is queued for a human; below this is skipped. */
    public const REVIEW_FLOOR = 0.55;

    public function __construct(
        private string $apiKey = '',
        private string $model = 'gpt-4o-mini',
        private float $timeout = 20.0,
    ) {
    }

    /** Build from the SAME key/model source the rest of the bot uses (BotNlu, MarketingBrain). */
    public static function fromConfig(): self
    {
        return new self(
            (string) (config('openai.api_key') ?: env('OPENAI_API_KEY', '')),
            (string) env('OPENAI_MODEL', 'gpt-4o-mini'),
        );
    }

    /** Feature flag: enrichment is OFF unless a key is configured AND not explicitly disabled. */
    public function isEnabled(): bool
    {
        if (trim($this->apiKey) === '') return false;
        $flag = config('shopbot.enrichment.enabled');
        return $flag === null ? true : (bool) $flag;
    }

    // ---------------------------------------------------------------- pure logic (tested)

    /**
     * Coerce a raw model result into a safe, in-vocabulary classification.
     * Out-of-vocab types are forced to 'other' and their confidence is capped, so a confident
     * hallucination ("artisan_oil", 0.97) can never be auto-applied.
     */
    public static function validate(mixed $raw): array
    {
        $type = '';
        $conf = 0.0;
        if (is_array($raw)) {
            $type = is_string($raw['product_type'] ?? null) ? $raw['product_type'] : '';
            $conf = is_numeric($raw['confidence'] ?? null) ? (float) $raw['confidence'] : 0.0;
        }
        $type = str_replace([' ', '-'], '_', mb_strtolower(trim($type)));
        $conf = max(0.0, min(1.0, $conf));

        if (! in_array($type, self::VOCAB, true)) {
            return ['product_type' => 'other', 'confidence' => min($conf, 0.40), 'in_vocab' => false];
        }
        return ['product_type' => $type, 'confidence' => round($conf, 3), 'in_vocab' => true];
    }

    /** Decide what to do with a validated classification: apply | review | skip. */
    public static function decision(array $validated): string
    {
        $type = (string) ($validated['product_type'] ?? 'other');
        $conf = (float) ($validated['confidence'] ?? 0.0);
        if ($type === '' || $type === 'other') return 'skip';
        if ($conf >= self::AUTO_APPROVE) return 'apply';
        if ($conf >= self::REVIEW_FLOOR) return 'review';
        return 'skip';
    }

    /** Vocabulary-constrained, JSON-only instruction for the model. */
    public static function systemPrompt(): string
    {
        $vocab = implode(', ', self::VOCAB);
        return implode("\n", [
            'You classify a single grocery-shop product into ONE product_type.',
            'You MUST choose exactly one value from this controlled vocabulary and nothing else:',
            $vocab . '.',
            'Rules:',
            '- Edible cooking oils (sunflower, vegetable, mustard, olive, palm, ghee-like) => cooking_oil.',
            '- Skin/body oils (bio-oil, baby oil) => skincare_oil. Beauty/perfume oils => cosmetic_oil.',
            '- Aromatherapy oils (clove, eucalyptus, tea-tree) => essential_oil. Hair oils => hair_oil.',
            '- Plain grain rice => rice. Rice-based crisps/puffs/snacks => snack.',
            '- Single ground spice => spice. Branded masala/seasoning blends (e.g. Chicken 65) => spice_mix.',
            '- If genuinely unsure, use other with low confidence. Never invent a type.',
            'Respond with STRICT JSON only, no prose, no markdown:',
            '{"product_type":"<one_vocab_value>","confidence":<0..1>,"reason":"<short>"}',
        ]);
    }

    // ---------------------------------------------------------------- live model call (staging-verified)

    /**
     * Classify one product via the model. Isolated and fails safe: any error/parse problem
     * returns null so a batch never half-writes. Returns a validated array on success.
     */
    public function classifyOne(string $name, string $category = ''): ?array
    {
        if (! $this->isEnabled() || trim($name) === '') return null;

        $user = trim($name) . ($category !== '' ? " (aisle: {$category})" : '');
        try {
            // Build the client with the key we resolved ourselves (env-backed), so this works
            // even when config/openai.php isn't published and config('openai.api_key') is empty.
            $resp = \OpenAI::client($this->apiKey)->chat()->create([
                'model' => $this->model,
                'temperature' => 0,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => self::systemPrompt()],
                    ['role' => 'user', 'content' => $user],
                ],
            ]);

            $content = trim((string) ($resp->choices[0]->message->content ?? ''));
            $content = trim(preg_replace('/^```(?:json)?|```$/m', '', $content));
            $decoded = json_decode($content, true);
            if (! is_array($decoded)) return null;

            return self::validate($decoded);
        } catch (\Throwable $e) {
            Log::warning('ProductEnrichment classifyOne failed', ['name' => $name, 'error' => $e->getMessage()]);
            return null;
        }
    }

    // ---------------------------------------------------------------- batch classification (scale)

    /** Vocabulary-constrained, JSON-only instruction for classifying a NUMBERED LIST at once. */
    public static function systemPromptBatch(): string
    {
        $vocab = implode(', ', self::VOCAB);
        return implode("\n", [
            'You classify grocery-shop products into product_type values.',
            'For EACH numbered product, choose exactly ONE value from this controlled vocabulary:',
            $vocab . '.',
            'Rules:',
            '- Edible cooking oils (sunflower, vegetable, mustard, olive, palm) => cooking_oil.',
            '- Skin/body oils (bio-oil, baby oil) => skincare_oil. Perfume/beauty oils => cosmetic_oil.',
            '- Aromatherapy (clove, eucalyptus, tea-tree) => essential_oil. Hair oils => hair_oil.',
            '- Plain grain rice => rice. Rice crisps/puffs/snacks => snack.',
            '- Single ground spice => spice. Branded masala/seasoning blends => spice_mix.',
            '- Insecticide/cleaners => cleaning. Razors/blades/soap/shampoo => personal_care.',
            '- If genuinely unsure, use other with low confidence. Never invent a type.',
            'Respond with STRICT JSON only, no prose, no markdown, exactly one entry per input number:',
            '{"results":[{"i":1,"product_type":"<vocab>","confidence":<0..1>}, ...]}',
        ]);
    }

    /**
     * Classify a batch in ONE API call. $items is a list of ['id','name','category'].
     * Returns [productId => validated|null]; ids the model omitted/garbled stay null (unclassified).
     * Fails safe: any error returns all-null so a batch never half-writes.
     */
    public function classifyMany(array $items): array
    {
        $items = array_values($items);
        $out = [];
        foreach ($items as $it) $out[$it['id']] = null;
        if (! $this->isEnabled() || ! $items) return $out;

        $lines = [];
        foreach ($items as $k => $it) {
            $nm  = trim((string) ($it['name'] ?? ''));
            $cat = trim((string) ($it['category'] ?? ''));
            $lines[] = ($k + 1) . '. ' . $nm . ($cat !== '' ? " (aisle: {$cat})" : '');
        }

        try {
            $resp = \OpenAI::client($this->apiKey)->chat()->create([
                'model' => $this->model,
                'temperature' => 0,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => self::systemPromptBatch()],
                    ['role' => 'user', 'content' => 'Classify these ' . count($items) . " products:\n" . implode("\n", $lines)],
                ],
            ]);
            $content = (string) ($resp->choices[0]->message->content ?? '');
            return self::parseBatch($content, $items);
        } catch (\Throwable $e) {
            Log::warning('ProductEnrichment classifyMany failed', ['count' => count($items), 'error' => $e->getMessage()]);
            return $out;
        }
    }

    /**
     * Pure: map a batch model response back to product ids and validate each. The prompt numbered
     * items 1..N in $items order; results carry that "i". Missing/garbled entries stay null.
     * @param array $items list of ['id'=>..]; @return array [id => validated|null]
     */
    public static function parseBatch(string $content, array $items): array
    {
        $items = array_values($items);
        $out = [];
        foreach ($items as $it) $out[$it['id']] = null;

        $content = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($content)));
        $decoded = json_decode($content, true);
        if (! is_array($decoded)) return $out;

        $results = $decoded['results'] ?? (array_is_list($decoded) ? $decoded : null);
        if (! is_array($results)) return $out;

        foreach ($results as $r) {
            if (! is_array($r)) continue;
            $i = (int) ($r['i'] ?? $r['index'] ?? 0);
            if ($i < 1 || $i > count($items)) continue;
            $out[$items[$i - 1]['id']] = self::validate($r);
        }
        return $out;
    }

    // ---------------------------------------------------------------- planning (dry-run safe)

    /**
     * Build an enrichment plan for a set of products WITHOUT writing anything.
     *
     * @param array         $products    rows with at least ['id','name','category','product_type'?]
     * @param callable|null $classifier  fn(string $name, string $category): ?array  (validated result)
     *                                   defaults to the live model; tests inject a fake.
     * @return array{rows: array<int,array>, summary: array<string,int>}
     */
    public function plan(array $products, ?callable $classifier = null): array
    {
        $classifier ??= fn (string $n, string $c) => $this->classifyOne($n, $c);

        $rows = [];
        $summary = ['apply' => 0, 'review' => 0, 'skip' => 0, 'unclassified' => 0];

        foreach ($products as $p) {
            $name = (string) ($p['name'] ?? '');
            $cat  = (string) ($p['category'] ?? '');
            $current = (string) ($p['product_type'] ?? '');

            $raw = $classifier($name, $cat);
            if ($raw === null) {
                $summary['unclassified']++;
                $rows[] = [
                    'id' => $p['id'] ?? null, 'name' => $name, 'current' => $current,
                    'product_type' => null, 'confidence' => null, 'decision' => 'unclassified',
                ];
                continue;
            }

            $v = self::validate($raw);                 // defence in depth even if classifier pre-validated
            $decision = self::decision($v);
            $summary[$decision] = ($summary[$decision] ?? 0) + 1;

            $rows[] = [
                'id' => $p['id'] ?? null,
                'name' => $name,
                'current' => $current,
                'product_type' => $v['product_type'],
                'confidence' => $v['confidence'],
                'decision' => $decision,                // apply | review | skip
            ];
        }

        return ['rows' => $rows, 'summary' => $summary];
    }
}
