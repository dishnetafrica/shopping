<?php
namespace App\Support;

/**
 * Pure conversation helper for required modifier groups (e.g. "Choose your accompaniment").
 * Frameworks-free so the bot path is testable. Group shape:
 *   ['id','name','required'=>bool,'min_select'=>int,'max_select'=>int,
 *    'options'=>[['id','name','price_delta'=>float], ...]]
 */
class ModifierFlow
{
    /** First required group still missing a choice, or null when all satisfied. */
    public static function nextRequired(array $groups, array $chosenByGroup = []): ?array
    {
        foreach ($groups as $g) {
            if (! empty($g['required'])) {
                $have = count($chosenByGroup[$g['id']] ?? []);
                if ($have < max(1, (int) ($g['min_select'] ?? 1))) {
                    return $g;
                }
            }
        }
        return null;
    }

    /** Numbered prompt for one group: "Choose your accompaniment:\n1. Rice\n2. Naan ...". */
    public static function prompt(array $group, string $dishName = ''): string
    {
        $lines = [];
        $i = 1;
        foreach (($group['options'] ?? []) as $o) {
            $d = (float) ($o['price_delta'] ?? 0);
            $tag = $d > 0 ? ' (+' . self::money($d) . ')' : '';
            $lines[] = $i . '. ' . $o['name'] . $tag;
            $i++;
        }
        $name = $group['name'] ?? 'option';
        $head = $dishName !== '' ? "For your *{$dishName}* — choose your {$name}:" : "Choose your {$name}:";
        return $head . "\n" . implode("\n", $lines);
    }

    /** Map a reply (a number, or a dish-word like "naan"/"garlic naan") to one option, or null. */
    public static function resolve(string $reply, array $group): ?array
    {
        $opts = $group['options'] ?? [];
        $r = trim(mb_strtolower($reply));
        if ($r === '') return null;

        if (preg_match('/^\s*(\d+)\s*$/', $r, $m)) {       // "2"
            return $opts[((int) $m[1]) - 1] ?? null;
        }
        foreach ($opts as $o) {                            // exact name
            if (mb_strtolower((string) $o['name']) === $r) return $o;
        }
        foreach ($opts as $o) {                            // contains either way ("rice" ~ "Jeera Rice")
            $on = mb_strtolower((string) $o['name']);
            if (str_contains($on, $r) || str_contains($r, $on)) return $o;
        }
        foreach ($opts as $o) {                            // token overlap ("garlic naan" -> "Naan")
            foreach (preg_split('/\s+/', mb_strtolower((string) $o['name'])) as $tok) {
                if (mb_strlen($tok) >= 3 && str_contains($r, $tok)) return $o;
            }
        }
        return null;
    }

    private static function money(float $d): string
    {
        $s = number_format($d, 2);
        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}
