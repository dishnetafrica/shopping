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
 *   - a large quantity is a custom job for the salesperson, not an automatic quotation.
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
     * @param array<string,array{billing:?string,service_line:?string}> $catalogue  visible priced products by exact name
     * @return array{ok:bool,reason:string,lines:list<array{name:string,qty:int}>,unknown:string[],added:string[]}
     *         reason: '' | no_items | unknown | too_many
     */
    public static function plan(array $items, array $catalogue): array
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

    private static function norm(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? $s;

        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }
}
