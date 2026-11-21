<?php

namespace App\Services\Isp;

/**
 * PURE. Decides what goes on a quotation the bot sends an Internet-provider customer (DishNet,
 * 17 Nov 2026: "send the customer an automatic quotation, Fiber or Starlink, so they feel comfortable").
 *
 * The model names catalogue products in a hidden <<QUOTE {"items":[{"name":…,"qty":…}]}>> line. This
 * class turns that into a list of REAL products the customer may see, or says why it cannot:
 *   - every name must resolve to exactly one visible, priced catalogue product (no guessing between two);
 *   - a Fiber monthly plan is always quoted with the Fiber one-time installation, as the published
 *     plans page shows it ("installation one-time + plan per month"), when there is exactly one such
 *     product — never a different charge;
 *   - a large quantity is a custom job for the salesperson, not an automatic quotation;
 *   - a Starlink monthly plan is never quoted on its own: every plan goes with a Starlink kit (DishNet,
 *     21 Nov 2026: "we have to submit the quotation with hardware, Standard kit or Mini kit — we don't
 *     offer subscription only"). Without one the bot asks which kit; it never picks one for them.
 * It never sets a price: prices come from the catalogue when the quotation is priced.
 */
final class IspQuotePlan
{
    /** More than this many of one product is a custom quotation for the salesperson. */
    public const MAX_QTY = 5;

    /**
     * Items from the hidden line. @return list<array{name:string,qty:int}>
     */
    public static function items(string $block): array
    {
        $out = [];
        $a = strpos($block, '{');
        $b = strrpos($block, '}');
        if ($a === false || $b === false || $b <= $a) {
            return [];
        }
        $data = json_decode(substr($block, $a, $b - $a + 1), true);
        foreach ((array) (is_array($data) ? ($data['items'] ?? []) : []) as $it) {
            if (! is_array($it)) {
                continue;
            }
            $name = trim((string) ($it['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $qty = $it['qty'] ?? (is_array($it['quantity'] ?? null) ? ($it['quantity']['value'] ?? 1) : ($it['quantity'] ?? 1));
            $out[] = ['name' => $name, 'qty' => max(1, (int) $qty)];
        }

        return $out;
    }

    /**
     * One catalogue name for what the model wrote, or null. Exact name (any case) first; otherwise the
     * single product whose name holds every word written. Two candidates = null: never pick one.
     *
     * @param string[] $names visible, priced product names
     */
    public static function resolve(string $query, array $names): ?string
    {
        $q = self::norm($query);
        if ($q === '') {
            return null;
        }
        foreach ($names as $n) {
            if (self::norm($n) === $q) {
                return $n;
            }
        }
        $words = array_values(array_filter(explode(' ', $q), fn ($w) => mb_strlen($w) > 1));
        if (! $words) {
            return null;
        }
        $hits = [];
        foreach ($names as $n) {
            $hay = ' ' . self::norm($n) . ' ';
            $all = true;
            foreach ($words as $w) {
                if (! str_contains($hay, ' ' . $w . ' ') && ! str_contains($hay, ' ' . $w)) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                $hits[] = $n;
            }
        }

        return count($hits) === 1 ? $hits[0] : null;
    }

    /**
     * @param list<array{name:string,qty:int}> $items  from items()
     * @param array<string,array{billing:?string,service_line:?string,category?:?string}> $catalogue  visible priced products by exact name
     * @param bool $planNeedsKit  a Starlink plan only with a kit (tenant setting isp_starlink_plan_needs_kit, default on)
     * @return array{ok:bool,reason:string,lines:list<array{name:string,qty:int}>,unknown:string[],added:string[]}
     *         reason: '' | no_items | unknown | too_many | no_kit
     */
    public static function plan(array $items, array $catalogue, bool $planNeedsKit = true): array
    {
        $names = array_keys($catalogue);
        $lines = [];
        $unknown = [];
        foreach ($items as $it) {
            $name = self::resolve($it['name'], $names);
            if ($name === null) {
                $unknown[] = $it['name'];
                continue;
            }
            foreach ($lines as $i => $l) {
                if ($l['name'] === $name) {
                    $lines[$i]['qty'] += $it['qty'];        // the same product named twice: one line
                    continue 2;
                }
            }
            $lines[] = ['name' => $name, 'qty' => $it['qty']];
        }
        // by reference: the installation added below must be in what is returned
        $out = function (bool $ok, string $reason, array $added = []) use (&$lines, $unknown): array {
            return ['ok' => $ok, 'reason' => $reason, 'lines' => $lines, 'unknown' => $unknown, 'added' => $added];
        };
        if (! $items) {
            return $out(false, 'no_items');
        }
        if ($unknown) {
            return $out(false, 'unknown');
        }
        foreach ($lines as $l) {
            if ($l['qty'] > self::MAX_QTY) {
                return $out(false, 'too_many');
            }
        }

        // Starlink: no subscription alone. As many kits as plans (each kit has its own plan); never a kit
        // chosen for the customer here — Mini or Standard is their choice, so the bot asks.
        if ($planNeedsKit) {
            $plans = $kits = 0;
            foreach ($lines as $l) {
                $p = $catalogue[$l['name']] ?? [];
                if (($p['service_line'] ?? null) === 'starlink' && ($p['billing'] ?? null) === 'monthly') {
                    $plans += $l['qty'];
                } elseif (self::isKit($l['name'], $p)) {
                    $kits += $l['qty'];
                }
            }
            if ($plans > 0 && $kits < $plans) {
                return $out(false, 'no_kit');
            }
        }

        // Fiber: the monthly plan comes with the one-time installation, as on the published plans page.
        // A fiber PLAN is a monthly fiber product named as one ("Fiber Basic 20 Mbps"), so a monthly
        // add-on such as a public IP address never brings an installation with it.
        $is = fn (string $name, string $billing) => ($catalogue[$name]['service_line'] ?? null) === 'fiber' && ($catalogue[$name]['billing'] ?? null) === $billing;
        $plan = (bool) array_filter($lines, fn ($l) => $is($l['name'], 'monthly') && preg_match('/\b(fib(?:er|re)|mbps)\b/i', $l['name']));
        $installed = (bool) array_filter($lines, fn ($l) => $is($l['name'], 'one_off'));
        $added = [];
        if ($plan && ! $installed) {
            $install = array_keys(array_filter($catalogue, fn ($p) => ($p['service_line'] ?? null) === 'fiber' && ($p['billing'] ?? null) === 'one_off'));
            if (count($install) === 1) {
                $lines[] = ['name' => $install[0], 'qty' => 1];
                $added[] = $install[0];
            }
        }

        return $out(true, '', $added);
    }

    /**
     * PURE. Is this catalogue product a Starlink KIT (the dish: Mini, Standard…), not an accessory sold for
     * one (car adaptor, power bank, cable, mount)? A one-time Starlink product in a "hardware"/"kit"
     * category, or named as a kit, Mini or Standard.
     *
     * @param array{billing:?string,service_line:?string,category?:?string} $p
     */
    public static function isKit(string $name, array $p): bool
    {
        if (($p['service_line'] ?? null) !== 'starlink' || ($p['billing'] ?? null) !== 'one_off') {
            return false;
        }
        $category = (string) ($p['category'] ?? '');
        if (preg_match('/accessor/i', $category)
            || preg_match('/\b(adapt\w*|power|bank|batter\w*|cables?|mounts?|pipe|brackets?|routers?|mesh|bags?|case|install\w*|extension)\b/i', $name)) {
            return false;
        }

        return (bool) preg_match('/hardware|kits?\b/i', $category) || (bool) preg_match('/\b(kits?|mini|standard)\b/i', $name);
    }

    /**
     * PURE. The kits the customer can choose from, by exact name.
     *
     * @return string[]
     */
    public static function kits(array $catalogue): array
    {
        return array_values(array_filter(array_keys($catalogue), fn ($n) => self::isKit((string) $n, $catalogue[$n])));
    }

    /** PURE. What the customer reads when they asked for a Starlink plan without a kit. */
    public static function kitQuestion(array $kits): string
    {
        $k = array_map(fn ($n) => '*' . $n . '*', $kits);
        $choice = count($k) > 1 ? implode(', ', array_slice($k, 0, -1)) . ' or ' . end($k) : ($k[0] ?? '');

        return 'Our Starlink plans come with a Starlink kit: we do not offer the subscription on its own. '
            . (count($k) > 1 ? "Which kit would you like on your quotation, the {$choice}?" : "Shall I put the {$choice} on your quotation with the plan?");
    }

    /**
     * PURE. Text as plain letters and digits. Customers write in decorative Unicode letters
     * (live, 17 Nov 2026: "𝙽𝚘𝚝 𝚕𝚒𝚔𝚎 250 𝚙𝚎𝚘𝚙𝚕𝚎"), which no word match can read. NFKC turns
     * those into ordinary letters; without the intl extension the mathematical letter and digit
     * blocks are mapped by hand.
     */
    public static function plain(string $s): string
    {
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($s, \Normalizer::FORM_KC);
            if (is_string($n)) {
                return $n;
            }
        }

        return self::plainByHand($s);
    }

    /** PURE. plain() without the intl extension: the mathematical letter and digit blocks only. */
    public static function plainByHand(string $s): string
    {
        return (string) preg_replace_callback('/[\x{1D400}-\x{1D6A3}\x{1D7CE}-\x{1D7FF}]/u', function ($m) {
            $cp = mb_ord($m[0], 'UTF-8');
            if ($cp >= 0x1D7CE) {
                return (string) (($cp - 0x1D7CE) % 10);
            }
            $i = ($cp - 0x1D400) % 52;

            return $i < 26 ? chr(65 + $i) : chr(97 + $i - 26);
        }, $s);
    }

    /**
     * PURE. The largest number of users the customer has mentioned ("250 people", "about 300 users",
     * "for 100 students"), or 0. A setup this size is a custom (Priority) job for the salesperson,
     * not an automatic Residential quotation.
     *
     * @param string[] $texts the customer's own messages
     */
    public static function users(array $texts): int
    {
        $max = 0;
        $who = 'users?|people|persons?|pple|ppl|devices?|clients?|customers?|connections?|staff|employees?|students?|rooms?|members?|guests?|subscribers?';
        foreach ($texts as $t) {
            $t = mb_strtolower(self::plain((string) $t));
            $t = preg_replace('/(?<=\d)o|o(?=\d)/u', '0', $t) ?? $t;   // "3o users" typed with a letter o
            $t = self::wordsToDigits($t);                                // "fifty people", "two hundred users"
            preg_match_all('/(\d[\d,]*)\s*\+?\s*(?:\w+\s+){0,2}?(?:' . $who . ')\b/u', $t, $a);
            preg_match_all('/\b(?:' . $who . ')\s*(?:of|about|around|:|=|is|are)?\s*(\d[\d,]*)/u', $t, $b);
            foreach (array_merge($a[1], $b[1]) as $n) {
                $max = max($max, (int) str_replace(',', '', $n));
            }
        }

        return $max;
    }

    private const UNITS = ['zero' => 0, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9,
        'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18, 'nineteen' => 19,
        'twenty' => 20, 'thirty' => 30, 'forty' => 40, 'fourty' => 40, 'fifty' => 50, 'sixty' => 60, 'seventy' => 70, 'eighty' => 80, 'ninety' => 90];

    /**
     * PURE. Spelled-out numbers as digits, so "fifty people" counts like "50 people" (Codex review on
     * #163): "two hundred and fifty" → 250, "a hundred" → 100, "1 thousand" → 1000, "a few" stays.
     */
    public static function wordsToDigits(string $t): string
    {
        $word = '(?:' . implode('|', array_keys(self::UNITS)) . '|hundred|thousand|a|and|\\d+)';

        return (string) preg_replace_callback('/\\b' . $word . '(?:[\\s-]+' . $word . ')*\\b/u', function ($m) {
            $tokens = preg_split('/[\\s-]+/u', trim($m[0])) ?: [];
            $total = 0;
            $cur = 0;
            $seen = false;                                  // a real number word, not only "a" / "and"
            foreach ($tokens as $w) {
                if (isset(self::UNITS[$w])) {
                    $cur += self::UNITS[$w];
                    $seen = true;
                } elseif (ctype_digit($w)) {
                    $cur += (int) $w;                       // "1 thousand"; digits alone are left as they are
                } elseif ($w === 'hundred') {
                    $cur = max(1, $cur) * 100;
                    $seen = true;
                } elseif ($w === 'thousand') {
                    $total += max(1, $cur) * 1000;
                    $cur = 0;
                    $seen = true;
                }
            }

            return $seen ? (string) ($total + $cur) : $m[0];
        }, $t);
    }

    /** PURE. The customer's name the model put in the hidden line, if any (letters only, short). */
    public static function customerName(string $block): string
    {
        $a = strpos($block, '{');
        $b = strrpos($block, '}');
        $data = ($a !== false && $b !== false && $b > $a) ? json_decode(substr($block, $a, $b - $a + 1), true) : null;
        $name = trim(self::plain((string) (is_array($data) ? ($data['customer_name'] ?? '') : '')));
        $name = preg_replace('/\s+/u', ' ', $name) ?? '';

        return preg_match('/^[\p{L}][\p{L}\'\. -]{0,58}$/u', $name) ? mb_convert_case($name, MB_CASE_TITLE) : '';
    }

    private static function norm(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? $s;

        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }
}
