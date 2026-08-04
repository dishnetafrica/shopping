<?php

namespace App\Services\Research;

use App\Services\Research\Dto\Finding;
use Illuminate\Support\Facades\Http;

/**
 * Writes the product description.
 *
 * This is the piece that decides whether the catalogue helps or hurts in search, so
 * the design is driven by what Google actually rewards rather than by what is easiest:
 *
 *   - It NEVER reproduces the manufacturer's marketing text. Identical copy across a
 *     thousand retailers is not penalised as such, but Google indexes one version and
 *     the rest are invisible — which is the same outcome as a penalty, arrived at
 *     quietly. Nothing is fetched from a brand site and rewritten; the model is given
 *     verified facts and told to compose.
 *   - It writes from what only THIS shop can say: the pack structure, the minimum
 *     order, the city, who buys it here. That is genuinely differentiated content and
 *     it is also the information the buyer wants.
 *   - It may only use facts it is handed. No nutrition claims, no health claims, no
 *     superlatives, no invented awards or heritage.
 *
 * Output is one paragraph. Short, factual and specific beats long and floral, both for
 * the reader and for an AI answer engine trying to cite something concrete.
 */
final class ProductCopywriter
{
    /** Prose is a judgement call, so it stays under auto-apply unless the facts are barcode-verified. */
    private const CONF_VERIFIED = 0.88;

    private const CONF_INFERRED = 0.62;

    /** @var null|\Closure test seam: fn(string $system, string $user): ?array */
    private $llm = null;

    public function __construct(
        private string $apiKey = '',
        private string $model = '',
        private float $timeout = 25.0,
        ?\Closure $llm = null,
    ) {
        $this->apiKey = $this->apiKey ?: (string) (config('openai.api_key') ?: env('OPENAI_API_KEY', ''));
        $this->model = $this->model ?: (string) env('OPENAI_MODEL', 'gpt-4o-mini');
        $this->llm = $llm;
    }

    public function isEnabled(): bool
    {
        return $this->llm !== null || trim($this->apiKey) !== '';
    }

    /**
     * @param  array  $facts  name, brand, unit_size, pack_size, unit_label, moq, origin_country,
     *                        category, price, currency, city, shop
     * @param  bool  $verified  true when the facts came from a barcode rather than inference
     */
    public function write(array $facts, bool $verified = false): ?Finding
    {
        if (! $this->isEnabled() || trim((string) ($facts['name'] ?? '')) === '') {
            return null;
        }
        $raw = $this->ask(self::systemPrompt(), self::userPrompt($facts));
        if (! is_array($raw)) {
            return null;
        }

        return self::parse($raw, $verified);
    }

    // ------------------------------------------------------------ pure (unit-tested)

    public static function systemPrompt(): string
    {
        return implode("\n", [
            'You write product descriptions for a wholesale shop\'s own website.',
            '',
            'Return JSON only: {"description":"...","confidence":0.0}',
            '',
            'What to write:',
            '- ONE paragraph, 40 to 80 words. No headings, no bullet points, no line breaks.',
            '- Say what the product is and what it is used for, in plain words.',
            '- Say how it is packed and sold — the case size and the minimum order are the most',
            '  useful facts on the page for a trade buyer, so do not leave them out.',
            '- Where a city is given, write for someone buying in that city.',
            '',
            'What you must not do:',
            '- Do not reproduce or paraphrase the manufacturer\'s marketing copy. Compose your own',
            '  sentences from the facts below. This text must be unlike any other shop\'s.',
            '- Do not state any fact you were not given. No ingredients, no nutrition, no health or',
            '  medical benefit, no certifications, no history, no awards, no country of origin unless',
            '  it appears in the facts.',
            '- No superlatives you cannot support: not "the best", "premium quality", "world famous",',
            '  "finest". Describe, do not sell.',
            '- Do not open by restating the full product name — the name is already on the page.',
            '- Do not mention the price. Prices change and the page shows it already.',
            '',
            'confidence is your honest 0-1 estimate that everything you wrote is accurate and',
            'supported by the facts given. If the facts are too thin to write 40 useful words,',
            'return {"description":"","confidence":0}.',
        ]);
    }

    public static function userPrompt(array $f): string
    {
        $L = [];
        $add = function (string $label, $v) use (&$L) {
            $v = trim((string) $v);
            if ($v !== '') {
                $L[] = $label . ': ' . $v;
            }
        };
        $add('Product', $f['name'] ?? '');
        $add('Brand', $f['brand'] ?? '');
        $add('Category', $f['category'] ?? '');
        $add('Size of one piece', $f['unit_size'] ?? '');

        $pack = (int) ($f['pack_size'] ?? 0);
        $unit = trim((string) ($f['unit_label'] ?? ''));
        if ($pack > 1) {
            $add('Packed as', $pack . ' pieces per ' . ($unit !== '' ? $unit : 'case')
                . (trim((string) ($f['unit_size'] ?? '')) !== '' ? ' (' . $pack . ' × ' . $f['unit_size'] . ')' : ''));
        } elseif ($unit !== '') {
            $add('Sold by', $unit);
        }
        $moq = (int) ($f['moq'] ?? 0);
        if ($moq > 1) {
            $add('Minimum order', $moq . ' ' . ($unit !== '' ? $unit . 's' : 'units'));
        }
        $add('Country of origin', $f['origin_country'] ?? '');
        $add('Shop', $f['shop'] ?? '');
        $add('City', $f['city'] ?? '');

        return implode("\n", $L);
    }

    public static function parse(array $raw, bool $verified): ?Finding
    {
        $d = trim((string) ($raw['description'] ?? ''));
        $conf = is_numeric($raw['confidence'] ?? null) ? (float) $raw['confidence'] : 0.0;
        if ($d === '' || $conf <= 0) {
            return null;
        }
        $d = trim(preg_replace('/\s+/', ' ', $d) ?? '');
        if (mb_strlen($d) < 40) {
            return null;
        }
        $ceiling = $verified ? self::CONF_VERIFIED : self::CONF_INFERRED;

        return new Finding(
            'description',
            mb_substr($d, 0, 900),
            max(0.0, min($ceiling, $conf)),
            'written',
            $verified
                ? 'Written from barcode-verified facts — original copy, not the manufacturer\'s'
                : 'Written from unverified facts — check it before it goes live',
        );
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
                    'temperature' => 0.4,             // prose, not extraction — a little variety is correct here
                    'max_tokens' => 320,
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
