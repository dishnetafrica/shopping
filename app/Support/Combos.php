<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Combo offers (curated bundles) live in tenant settings — no schema change, same pattern as
 * faq/brands. Owner sets each combo's name, who it's for, the items, the combo price and an
 * optional original price; the saving is derived. Shared by the storefront, the bot and the
 * panel so the shape never drifts.
 */
class Combos
{
    /** Normalise raw combos (from settings or the panel) into a clean, validated list. */
    public static function normalize(array $raw): array
    {
        return collect($raw)->map(function ($c) {
            $items = [];
            foreach ((array) ($c['items'] ?? []) as $it) {
                if (is_array($it)) {
                    $name = trim((string) ($it['name'] ?? ''));
                    $qty  = max(1, (int) ($it['qty'] ?? 1));
                } else {
                    // "2 x EuroPearl Toilet Paper" or "2 EuroPearl Toilet Paper"
                    $line = trim((string) $it);
                    if ($line === '') continue;
                    if (preg_match('/^(\d+)\s*[x×*]?\s*(.+)$/u', $line, $m)) {
                        $qty = max(1, (int) $m[1]); $name = trim($m[2]);
                    } else {
                        $qty = 1; $name = $line;
                    }
                }
                if ($name !== '') $items[] = ['name' => $name, 'qty' => $qty];
            }
            $price = (float) ($c['price'] ?? 0);
            $was   = isset($c['was']) && $c['was'] !== '' && $c['was'] !== null ? (float) $c['was'] : null;
            $save  = ($was !== null && $was > $price) ? $was - $price : null;
            return [
                'name'   => trim((string) ($c['name'] ?? '')),
                'who'    => trim((string) ($c['who'] ?? '')),
                'items'  => $items,
                'price'  => $price,
                'was'    => $was,
                'save'   => $save,
                'active' => ! isset($c['active']) || $c['active'],
            ];
        })->filter(fn ($c) => $c['name'] !== '' && $c['price'] > 0 && ! empty($c['items']))->values()->all();
    }

    /** Active combos for a tenant, with currency, ready for display or the bot. */
    public static function forTenant(Tenant $tenant): array
    {
        $cur = (string) ($tenant->setting('currency', 'UGX'));
        return collect(self::normalize((array) $tenant->setting('combos', [])))
            ->filter(fn ($c) => $c['active'])
            ->map(fn ($c) => $c + ['currency' => $cur])
            ->values()->all();
    }

    /** Plain-text combo list for the bot prompt (prices are owner-set, so safe to quote verbatim). */
    public static function promptBlock(Tenant $tenant): string
    {
        $combos = self::forTenant($tenant);
        if (! $combos) return '';
        $cur = (string) ($tenant->setting('currency', 'UGX'));
        $lines = [];
        foreach ($combos as $c) {
            $items = implode(', ', array_map(fn ($i) => "{$i['qty']}× {$i['name']}", $c['items']));
            $price = "{$cur} " . number_format($c['price']);
            $save  = $c['save'] ? " (save {$cur} " . number_format($c['save']) . ")" : '';
            $who   = $c['who'] ? " — for {$c['who']}" : '';
            $lines[] = "• {$c['name']}{$who}: {$items}. Combo price {$price}{$save}.";
        }
        return implode("\n", $lines);
    }
}
