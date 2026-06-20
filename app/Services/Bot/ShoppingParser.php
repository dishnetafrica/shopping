<?php
namespace App\Services\Bot;

/**
 * ShoppingParser — free-text shopping message -> structured items.
 * Ported from the n8n brain. Intent rule (production-faithful):
 *   - explicit add-verb OR a quantity  => ADD
 *   - question / "show me" / bare words => BROWSE (show, never auto-add)
 *   - an edit verb (remove/change/make/swap/...) => EDIT (deferred to Phase 2;
 *     flagged so the engine NEVER mistakes "remove sugar" for "add sugar").
 */
class ShoppingParser
{
    private const UNIT_ALT = 'kg|kgs|g|gm|gms|gram|grams|ml|cl|l|lt|ltr|litre|litres|liter|liters|pc|pcs|pk|pkt|pkts|pack|packs|packet|packets|piece|pieces|box|boxes|tin|tins|btl|bottle|bottles|jar|jars|bar|bars|sachet|sachets|roll|rolls|dozen|loaf|loaves';

    public function parse(string $text): array
    {
        $raw = trim($text);
        $low = mb_strtolower($raw);
        $low = self::preNormalize($low);   // repair glued tokens + fractions first

        // EDIT ops are Phase 2 — flag and defer (so we never wrongly add them)
        $edit = (bool) preg_match('/\b(remove|delete|change|make|set|swap|replace|instead|double|triple|halve|reduce|decrease|increase)\b/', $low)
              || (bool) preg_match('/\b(one|two|three|\d+)\s+more\b/', $low)
              || (bool) preg_match('/\b(first|second|third|last)\b/', $low);

        $browse = (bool) (
            str_contains($raw, '?') ||
            preg_match('/\b(do you have|do you stock|do you sell|do you carry|have you got|got any|is there|any\b)/', $low) ||
            preg_match('/\b(which|what|whats|options?|available)\b/', $low) ||
            preg_match('/^(show me|show|list|gimme a list)\b/', $low)
        );

        // strip leading intent/filler phrases
        $work = ' ' . $low . ' ';
        $leads = [
            'do you have','do you stock','do you sell','do you carry','have you got',
            'i want to buy','i would like',"i'd like",'i want','i need','can i get','can i have',
            'can i buy',"i'll take",'let me get','give me','gimme','get me','looking for',
            'show me','show','list','please send','please','pls','kindly','need','buy','order',
        ];
        foreach ($leads as $f) $work = preg_replace('/^\s+' . preg_quote($f, '/') . '\b\s*/', ' ', $work);
        $work = trim($work, " ?.!:");

        // multi-line lists -> treat each line as a separate item
        $work = preg_replace('/[\r\n]+/', ',', $work);
        // normalise connectors -> comma
        $work = preg_replace('/\s*(?:,|;|&|\+|\band\b|\bplus\b|\bane\b)\s*/i', ',', $work);
        $frags = array_filter(array_map(fn ($s) => trim($s, " \t.:;"), explode(',', $work)), fn ($s) => $s !== '');

        $expanded = [];
        foreach ($frags as $f) {
            $f = preg_replace('/^[^a-z0-9]+/i', '', $f);
            foreach ($this->splitRunOn($f) as $g) $expanded[] = $g;
        }

        $hasAddVerb = (bool) preg_match('/\b(add|buy|want|need|take|give|get|order|grab)\b/', $low);
        $items = []; $hasQty = false;
        foreach ($expanded as $f) {
            $it = $this->parseItem($f);
            if ($it['query'] === '') continue;
            if ($it['_explicit_qty']) $hasQty = true;
            unset($it['_explicit_qty']);
            $items[] = $it;
        }

        $addIntent = ($hasAddVerb || $hasQty) && !$browse;

        return ['add_intent' => $addIntent, 'browse' => $browse, 'edit' => $edit, 'items' => $items];
    }

    private function splitRunOn(string $s): array
    {
        $body = preg_replace('/^(?:add|buy|need|want|get|give me|i want|order)\s+/', '', $s);
        if (preg_match('/\b(remove|delete|change|swap|make|set|clear|cancel)\b/', $body)) return [$s];
        if (!preg_match('/^\d+\s+\S/', $body)) return [$s];
        $toks = preg_split('/\s+/', $body);
        $groups = 0;
        for ($i = 0; $i < count($toks); $i++) {
            if (preg_match('/^\d+$/', $toks[$i]) && !$this->isUnit($toks[$i + 1] ?? '')) $groups++;
        }
        if ($groups < 2) return [$s];
        $out = []; $cur = [];
        foreach ($toks as $i => $t) {
            if (preg_match('/^\d+$/', $t) && !$this->isUnit($toks[$i + 1] ?? '')) {
                if ($cur) $out[] = implode(' ', $cur);
                $cur = [$t];
            } else { $cur[] = $t; }
        }
        if ($cur) $out[] = implode(' ', $cur);
        return $out ?: [$s];
    }

    private function isUnit(string $t): bool
    {
        return (bool) preg_match('/^(?:' . self::UNIT_ALT . ')$/', $t);
    }

    private const SIZE_UNIT = 'kgs|kg|gms|gm|grams|gram|mg|ml|cl|ltrs|ltr|lt|litres|litre|liters|liter|g|l';

    /**
     * Repair Gujlish/handwritten order text BEFORE parsing:
     *   - un-glue tokens around fractions and units  ("pista1/2"->"pista 1/2",
     *     "1/2kaju"->"1/2 kaju", "250gm"->"250 gm") — narrow rules so "7up" stays "7up".
     *   - half / haf / paav  -> 1/2 , 1/4
     *   - run-on split: a 2nd size token after a sized item starts a new item
     *     ("250gm pista 1/2 kg khajoor" -> "250 gm pista , 1/2 kg khajoor").
     *   - fractions of a kg -> whole grams ("1/2 kg"->"500 gm"); a bare fraction
     *     before a product defaults to kg ("1/2 kaju"->"500 gm kaju").
     * Pure string->string so it is unit-testable without the framework.
     */
    public static function preNormalize(string $s): string
    {
        $su = self::SIZE_UNIT;
        $s = ' ' . mb_strtolower(trim($s)) . ' ';

        // 1) narrow un-glue
        $s = preg_replace('/(?<=[a-z])(?=\d+\/\d)/', ' ', $s);          // pista1/2 -> pista 1/2
        $s = preg_replace('/(?<=\d\/\d)(?=[a-z])/', ' ', $s);          // 1/2kaju  -> 1/2 kaju
        $s = preg_replace('/(?<=\d)(?=(?:' . $su . ')\b)/', ' ', $s);  // 250gm    -> 250 gm

        // 2) fraction words -> symbolic fractions
        $s = preg_replace('/\bhaf\b/', 'half', $s);
        $s = preg_replace('/\b(half|aadho|aadha|adho|adhu)\b/', '1/2', $s);
        $s = preg_replace('/\b(paav|pav|pao|quarter)\b/', '1/4', $s);

        // 3) run-on split: <num><unit> <word> … <new size token>  -> comma before the new size
        $s = preg_replace(
            '/(\b\d+(?:\.\d+)?\s*(?:' . $su . ')\s+[a-z][a-z ]*?)\s+(?=(?:1\/2|1\/4|3\/4|\d+(?:\.\d+)?\s*(?:' . $su . ')))/',
            '$1 , ',
            $s
        );

        // 4) fractions of a kg -> whole grams  (decimals break the count regex, so avoid them)
        $s = preg_replace('/\b1\/2\s*(?:kg|kgs)\b/i', '500 gm', $s);
        $s = preg_replace('/\b1\/4\s*(?:kg|kgs)\b/i', '250 gm', $s);
        $s = preg_replace('/\b3\/4\s*(?:kg|kgs)\b/i', '750 gm', $s);
        // bare fraction directly before a product word (no unit) -> kg default, in grams
        $s = preg_replace('/\b1\/2\s+(?!(?:' . $su . ')\b)(?=[a-z])/', '500 gm ', $s);
        $s = preg_replace('/\b1\/4\s+(?!(?:' . $su . ')\b)(?=[a-z])/', '250 gm ', $s);
        $s = preg_replace('/\b3\/4\s+(?!(?:' . $su . ')\b)(?=[a-z])/', '750 gm ', $s);

        return trim(preg_replace('/\s+/', ' ', $s));
    }

    private function parseItem(string $frag): array
    {
        $f = trim($frag);
        $f = preg_replace('/^(?:add|buy|want|need|take|get|give me|i want|i need|order)\s+/', '', $f);
        $f = trim($f);

        // size token (number + weight/volume unit) anywhere -> used to disambiguate variants
        $size = null;
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:' . self::SIZE_UNIT . ')\b/i', $f, $sm)) {
            $size = \App\Services\Bot\CatalogueMatcher::normSize($sm[0]);
        }

        $count = null;      // an explicit COUNT distinct from a size token
        $sizeNum = null;    // the number that belonged to a size token
        $explicit = false;

        // leading number (+optional unit)
        if (preg_match('/^(\d+)\s*(' . self::SIZE_UNIT . '|' . self::UNIT_ALT . ')?\b\s*(?:of\s+)?/', $f, $m)) {
            $isSizeUnit = isset($m[2]) && $m[2] !== '' && preg_match('/^(?:' . self::SIZE_UNIT . ')$/i', $m[2]);
            if ($isSizeUnit) { $sizeNum = (int) $m[1]; }
            else { $count = max(1, (int) $m[1]); }
            $explicit = true;
            $f = trim(substr($f, strlen($m[0])));
        } elseif (preg_match('/^(a|an)\s+/', $f, $m)) {
            $f = trim(substr($f, strlen($m[0])));
        }

        // trailing number (+optional unit) if we have not already taken a count
        if ($count === null && preg_match('/\s+x?(\d+)\s*(' . self::SIZE_UNIT . '|' . self::UNIT_ALT . ')?$/', $f, $m)) {
            $isSizeUnit = isset($m[2]) && $m[2] !== '' && preg_match('/^(?:' . self::SIZE_UNIT . ')$/i', $m[2]);
            if ($isSizeUnit && $sizeNum === null) { $sizeNum = (int) $m[1]; }
            else { $count = max(1, (int) $m[1]); }
            $explicit = true;
            $f = trim(preg_replace('/\s+x?\d+\s*(?:' . self::SIZE_UNIT . '|' . self::UNIT_ALT . ')?$/', '', $f));
        }

        $f = preg_replace('/^(?:' . self::SIZE_UNIT . '|' . self::UNIT_ALT . ')\b\s*/', '', $f);
        $f = preg_replace('/^of\s+/', '', $f);
        $f = trim($f);

        // qty = count interpretation (preserves "2kg sugar" => 2 when there is a single SKU)
        $qty = $count ?? $sizeNum ?? 1;

        return [
            'query' => $f,
            'qty' => max(1, (int) $qty),
            'count' => $count,          // explicit separate count (null if none)
            'size' => $size,            // normalised size token e.g. "2kg" (null if none)
            'unit' => null,
            '_explicit_qty' => $explicit,
        ];
    }
}
