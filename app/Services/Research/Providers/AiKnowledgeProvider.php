<?php

namespace App\Services\Research\Providers;

use App\Services\Research\Contracts\ResearchProvider;
use App\Services\Research\Dto\Finding;
use Illuminate\Support\Facades\Http;

/**
 * What the model already knows about a named product.
 *
 * This is the fallback for the ~28% of the catalogue with no usable barcode —
 * local repacks, loose goods, no-name imports. It is INFERENCE, and the whole
 * design of this class is about making the model admit that:
 *
 *   - it must return a per-field confidence, and is told plainly that omitting a
 *     field is the correct answer when unsure. Nothing rewards a complete object.
 *   - it is given a closed country list, so "made in" cannot come back as a region,
 *     a city, or an invention. Out-of-list values are capped by the validator.
 *   - temperature 0.
 *   - a bare code for a name ("30056640") is refused before the call is made —
 *     the model will confidently classify anything, including a serial number.
 *
 * Its ceiling is deliberately below the auto-apply threshold for origin, so an
 * inferred country ALWAYS goes to a human. Origin ends up on customs and tender
 * paperwork; a confident wrong answer there costs more than the review does.
 */
final class AiKnowledgeProvider implements ResearchProvider
{
    /** Nothing inferred may be written without a human seeing it. */
    private const CEILING = 0.80;

    /** Origin is the one that reaches customs paperwork — hold it below review, always. */
    private const CEILING_ORIGIN = 0.74;

    /** @var null|\Closure test seam: fn(string $system, string $user): ?array */
    private $llm = null;

    public function __construct(
        private string $apiKey = '',
        private string $model = '',
        private float $timeout = 20.0,
        ?\Closure $llm = null,
    ) {
        $this->apiKey = $this->apiKey ?: (string) (config('openai.api_key') ?: env('OPENAI_API_KEY', ''));
        $this->model = $this->model ?: (string) env('OPENAI_MODEL', 'gpt-4o-mini');
        $this->llm = $llm;
    }

    public function key(): string
    {
        return 'ai';
    }

    public function isEnabled(): bool
    {
        return $this->llm !== null || trim($this->apiKey) !== '';
    }

    public function research(array $product, array $wanted): array
    {
        $name = trim((string) ($product['name'] ?? ''));
        if ($name === '' || ! $this->isEnabled()) {
            return [];
        }
        // Reuse the existing guard: a name with no alphabetic word of 3+ letters carries
        // no signal, and the model will happily invent a brand and an origin for it.
        if (\App\Services\Enrichment\ProductEnrichmentService::looksMeaningless($name)) {
            return [];
        }

        $wanted = array_values(array_intersect($wanted, ['brand', 'unit_size', 'origin_country', 'category']));
        if (! $wanted) {
            return [];
        }

        $raw = $this->ask(self::systemPrompt(), self::userPrompt($product, $wanted));

        return $raw === null ? [] : self::parse($raw, $wanted);
    }

    // ------------------------------------------------------------ pure (unit-tested)

    public static function systemPrompt(): string
    {
        return implode("\n", [
            'You identify consumer goods sold in East Africa. You are given a product name as a',
            'shopkeeper typed it, and a list of fields they need.',
            '',
            'Return JSON only: {"fields":{"<field>":{"value":"...","confidence":0.0}}}',
            '',
            'Rules, in order of importance:',
            '1. OMIT any field you are not sure about. An object with two fields you are certain of',
            '   is a better answer than five with three guesses. There is no penalty for omitting.',
            '2. confidence is your honest probability that the value is correct for THIS exact',
            '   product, from 0 to 1. Do not report above 0.9 unless the brand is world-famous and',
            '   the name is unambiguous.',
            '3. origin_country must be the country of MANUFACTURE and must be chosen from the list',
            '   given in the user message. If the true origin is not in that list, or you are',
            '   inferring it from the brand rather than knowing it, omit the field.',
            '4. unit_size is the size of ONE piece as printed on the pack, formatted like "500ml",',
            '   "2kg", "400g". Never a pack count, never a range.',
            '5. brand is the manufacturer\'s brand only. Not the shop, not the category, not a',
            '   descriptive word from the name.',
            '6. Never invent a product. If the name does not correspond to a real product you know,',
            '   return {"fields":{}}.',
        ]);
    }

    public static function userPrompt(array $product, array $wanted): string
    {
        $lines = ['Product name: ' . trim((string) ($product['name'] ?? ''))];
        foreach (['brand' => 'Known brand', 'category' => 'Shop category', 'unit_size' => 'Known size'] as $k => $label) {
            $v = trim((string) ($product[$k] ?? ''));
            if ($v !== '') {
                $lines[] = $label . ': ' . $v;
            }
        }
        $lines[] = '';
        $lines[] = 'Fields needed: ' . implode(', ', $wanted);
        if (in_array('origin_country', $wanted, true)) {
            $lines[] = '';
            $lines[] = 'Allowed origin_country values (choose one or omit the field):';
            $lines[] = implode(', ', \App\Services\Research\ProductResearch::COUNTRIES);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  string[]  $wanted
     * @return Finding[]
     */
    public static function parse(array $raw, array $wanted): array
    {
        $fields = $raw['fields'] ?? null;
        if (! is_array($fields)) {
            return [];
        }
        $out = [];
        foreach ($fields as $field => $spec) {
            if (! is_string($field) || ! in_array($field, $wanted, true) || ! is_array($spec)) {
                continue;
            }
            $value = trim((string) ($spec['value'] ?? ''));
            $conf = is_numeric($spec['confidence'] ?? null) ? (float) $spec['confidence'] : 0.0;
            if ($value === '' || $conf <= 0) {
                continue;
            }
            $cap = $field === 'origin_country' ? self::CEILING_ORIGIN : self::CEILING;
            $out[] = new Finding(
                $field,
                mb_substr($value, 0, 120),
                max(0.0, min($cap, $conf)),
                'ai',
                'AI inference from the product name — not verified against a barcode',
            );
        }

        return $out;
    }

    // ------------------------------------------------------------ the call

    private function ask(string $system, string $user): ?array
    {
        if ($this->llm !== null) {
            return ($this->llm)($system, $user);
        }
        try {
            $resp = Http::withToken($this->apiKey)->timeout($this->timeout)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $this->model,
                    'temperature' => 0,
                    'max_tokens' => 400,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                ]);
            if (! $resp->ok()) {
                return null;
            }
            $txt = (string) ($resp->json()['choices'][0]['message']['content'] ?? '');
            $j = json_decode($txt, true);

            return is_array($j) ? $j : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
