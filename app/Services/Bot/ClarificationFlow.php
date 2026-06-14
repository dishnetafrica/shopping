<?php
namespace App\Services\Bot;

/**
 * ClarificationFlow — renders numbered product options when a request is
 * ambiguous, and resolves the customer's reply ("1", "1,3", "pakistan").
 *
 * Ported from the n8n brain's clarify rendering + numeric-reply selection.
 * Pure PHP. State (the pending option list) is held by the caller in the
 * conversation; this class only builds and resolves.
 */
class ClarificationFlow
{
    /**
     * Build a flat, globally-numbered option list from grouped candidates.
     * @param array $groups list of ['label'=>string,'qty'=>int,'products'=>[rows]]
     * @return array ['flat'=>[['n','product_id','name','price','qty'],...], 'text'=>string]
     */
    public function buildOptions(array $groups, callable $money): array
    {
        $flat = []; $blocks = []; $n = 0;
        foreach ($groups as $g) {
            $lines = [];
            foreach ($g['products'] as $p) {
                $n++;
                $flat[] = [
                    'n' => $n,
                    'product_id' => $p['id'] ?? null,
                    'name' => $p['name'],
                    'price' => (float) ($p['price'] ?? 0),
                    'qty' => $g['qty'] ?? 1,
                ];
                $lines[] = "  {$n}. {$p['name']} — " . $money((float) ($p['price'] ?? 0));
            }
            $label = ucfirst($g['label']);
            $blocks[] = "*{$label}:*\n" . implode("\n", $lines);
        }
        $text = implode("\n", $blocks);
        return ['flat' => $flat, 'text' => $text];
    }

    /**
     * Resolve a customer reply against pending options.
     * Accepts numbers ("1", "1,3", "1 and 3", "1 3") or a product-name substring.
     * @return array picked option rows (subset of $flat)
     */
    public function resolveSelection(string $reply, array $flat): array
    {
        $low = mb_strtolower(trim($reply));
        $picked = [];

        // SIZE / VARIANT reply (e.g. "6kg", "500 ml", "the 2 litre one") after showing variants:
        // prefer the option whose NAME carries that size over treating the digits as a row number.
        if (preg_match('/\b(\d+(?:\.\d+)?)\s*(kgs?|kg|gms?|grams?|g|mg|ml|cl|ltrs?|litres?|liters?|lt|l|pcs?|packs?|pkts?|dozen|btls?|bottles?|tins?)\b/', $low, $sm)) {
            $num  = $sm[1];
            $unit = rtrim($sm[2], 's');
            foreach ($flat as $opt) {
                $n = mb_strtolower($opt['name']);
                if (preg_match('/\b' . preg_quote($num, '/') . '\s*' . preg_quote($unit, '/') . 's?\b/', $n)) {
                    $picked[] = $opt;
                }
            }
            if ($picked) return $picked;
        }

        if (preg_match_all('/\d+/', $low, $m)) {
            // A digit only counts as a row pick when the message is SELECTION-SHAPED — nothing
            // but numbers and connectors. "5 coke 10 rice" is a NEW order (quantities + product
            // words), never a pick of rows 5 and 10 from a previously shown list. Without this
            // guard a fresh order's quantities get applied to a stale option list (state
            // contamination), silently committing the wrong products.
            $residue = preg_replace('/\d+/', ' ', $low);
            $residue = preg_replace('/\b(and|&|or|plus|n|no|nos|number|numbers|option|options|item|items|the|please|pls|add|take|get|i|want|buy)\b/', ' ', $residue);
            $residue = preg_replace('/[^a-z]+/', '', $residue);
            if ($residue === '') {
                $nums = array_map('intval', $m[0]);
                foreach ($flat as $opt) {
                    if (in_array($opt['n'], $nums, true)) $picked[] = $opt;
                }
                if ($picked) return $picked;
            }
        }

        // name-substring fallback ("pakistan", "add local rice")
        $q = trim(preg_replace('/^(add|i want|buy|take|get)\s+/', '', $low));
        if ($q !== '') {
            foreach ($flat as $opt) {
                if (str_contains(mb_strtolower($opt['name']), $q)) $picked[] = $opt;
            }
        }
        return $picked;
    }

    /** Does this reply look like a selection against the given options? */
    public function looksLikeSelection(string $reply, array $flat): bool
    {
        return count($this->resolveSelection($reply, $flat)) > 0;
    }
}
