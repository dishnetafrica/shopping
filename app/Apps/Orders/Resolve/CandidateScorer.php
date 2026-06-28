<?php
namespace App\Apps\Orders\Resolve;

use App\Apps\Orders\Dto\CartLine;

/**
 * Multi-signal ranking — NOT a single fuzzy match. Each candidate is scored on independent signals
 * (exact alias, name similarity, popularity, availability gate, category context, conversation
 * context); the decision is auto-resolve only when the top score is high AND clears the runner-up
 * by a margin, otherwise clarify with the close candidates. Pure & data-driven: candidates are
 * plain arrays loaded from the real Product Master, so it learns the catalogue's vocabulary.
 *
 * Weights/thresholds are defaults to be TUNED from production (see ResolutionClarification log).
 */
class CandidateScorer
{
    public function __construct(
        private float $auto = 0.80,      // min top score to auto-resolve
        private float $margin = 0.15,    // min lead over runner-up to auto-resolve
        private float $floor = 0.40,     // min score to be worth clarifying
        private array $w = ['alias' => 0.5, 'name' => 0.3, 'pop' => 0.1, 'category' => 0.05, 'conversation' => 0.05],
    ) {}

    /**
     * @param array<int,array> $products each: id,name,aliases[],tokens[],misspellings[],gujarati[],short,
     *                                          default_aliases[],popularity,available,category
     * @param array{category?:string,recent?:string[]} $context
     */
    public function rank(string $query, array $products, array $context = []): ProductResolution
    {
        $q = $this->norm($query);
        $maxPop = max(1, max(array_map(fn ($p) => (int) ($p['popularity'] ?? 0), $products ?: [[]])));
        $scored = [];

        foreach ($products as $p) {
            $available = (bool) ($p['available'] ?? true);
            $names = array_map([$this, 'norm'], array_merge(
                [$p['name'] ?? ''], $p['aliases'] ?? [], $p['misspellings'] ?? [],
                $p['gujarati'] ?? [], $p['tokens'] ?? [], $p['default_aliases'] ?? [],
                isset($p['short']) ? [$p['short']] : [],
            ));
            $defaults = array_map([$this, 'norm'], $p['default_aliases'] ?? []);

            $aliasExact = in_array($q, $names, true) ? 1.0 : 0.0;
            $isDefault  = in_array($q, $defaults, true);
            $nameSim    = 0.0;
            foreach ($names as $n) { if ($n !== '') $nameSim = max($nameSim, $this->sim($q, $n)); }
            $pop        = ((int) ($p['popularity'] ?? 0)) / $maxPop;
            $cat        = (isset($context['category']) && ($p['category'] ?? null) === $context['category']) ? 1.0 : 0.0;
            $conv       = (! empty($context['recent']) && in_array($this->norm($p['name'] ?? ''), array_map([$this, 'norm'], $context['recent']), true)) ? 1.0 : 0.0;

            $raw = $this->w['alias'] * $aliasExact + $this->w['name'] * $nameSim
                 + $this->w['pop'] * $pop + $this->w['category'] * $cat + $this->w['conversation'] * $conv;
            if ($isDefault) $raw = min(1.0, $raw + 0.12);    // a default alias outranks mere token matches
            $score = $available ? round($raw, 4) : 0.0;       // availability is a gate

            $scored[] = ['product_id' => $p['id'] ?? null, 'name' => $p['name'] ?? '', 'score' => $score];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        $top = $scored[0] ?? null; $second = $scored[1] ?? null;

        $decision = ProductResolution::NONE;
        $line = null;
        if ($top && $top['score'] >= $this->auto && (! $second || ($top['score'] - $second['score']) >= $this->margin)) {
            $decision = ProductResolution::AUTO;
            $line = new CartLine(name: $top['name'], productId: $top['product_id'], confidence: $top['score']);
        } elseif ($top && $top['score'] >= $this->floor) {
            $decision = ProductResolution::CLARIFY;
            $scored = array_values(array_filter($scored, fn ($c) => $c['score'] >= $this->floor));
            $scored = array_slice($scored, 0, 3);
        }

        return new ProductResolution($query, $scored, $decision, $line);
    }

    private function norm(string $s): string { return trim(preg_replace('/\s+/', ' ', mb_strtolower($s)) ?? ''); }

    /** token Jaccard blended with normalized edit distance — 0..1. */
    private function sim(string $a, string $b): float
    {
        if ($a === '' || $b === '') return 0.0;
        if ($a === $b) return 1.0;
        $ta = array_filter(explode(' ', $a)); $tb = array_filter(explode(' ', $b));
        $inter = count(array_intersect($ta, $tb));
        $union = count(array_unique(array_merge($ta, $tb)));
        $jac = $union ? $inter / $union : 0.0;
        $lev = levenshtein(mb_substr($a, 0, 255), mb_substr($b, 0, 255));
        $edit = 1 - $lev / max(mb_strlen($a), mb_strlen($b));
        $sub  = (str_contains($b, $a) || str_contains($a, $b)) ? 0.6 : 0.0;
        return round(max($jac, $edit, $sub), 4);
    }
}
