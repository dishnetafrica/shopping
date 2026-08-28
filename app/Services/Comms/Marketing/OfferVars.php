<?php declare(strict_types=1);

namespace App\Services\Comms\Marketing;

use App\Models\Product;
use App\Models\Tenant;

/**
 * Turns a campaign's `offer` (the thing being promoted) into template variables for the existing
 * TemplateRenderer — {{product_name}}, {{price}}, {{category}}, {{product_link}}. Campaign-level (same
 * for every recipient), so it is resolved once per batch, not per message.
 *
 * Deterministic and non-secret; the renderer escapes values, so nothing here needs its own escaping.
 * Adds NO WhatsApp template capability — it only fills placeholders in the freeform text the send path
 * already supports.
 */
final class OfferVars
{
    /** @param array<string,mixed> $offer  {product_id?, product_ids?, category?, promo?, link?} */
    public static function build(Tenant $tenant, ?array $offer): array
    {
        $offer = $offer ?: [];
        $vars = [];

        $ids = array_values(array_filter(array_map('intval',
            (array) ($offer['product_ids'] ?? array_filter([$offer['product_id'] ?? null])))));

        if ($ids) {
            $products = Product::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)->whereIn('id', $ids)
                ->get(['name', 'price', 'category']);
            if ($products->isNotEmpty()) {
                $names = $products->pluck('name')->filter()->map(fn ($n) => (string) $n)->all();
                $vars['product_name'] = self::joinNames($names);
                $first = $products->first();
                if ($first->price !== null && (float) $first->price > 0) {
                    $vars['price'] = self::money($tenant, (float) $first->price);
                }
                if (empty($offer['category']) && $first->category) {
                    $vars['category'] = (string) $first->category;
                }
            }
        }

        if (! empty($offer['category'])) {
            $vars['category'] = (string) $offer['category'];
        }
        if (! empty($offer['promo'])) {
            $vars['promo'] = (string) $offer['promo'];
        }
        $link = self::link($tenant, $offer);
        if ($link !== null) {
            $vars['product_link'] = $link;
        }

        return $vars;
    }

    private static function joinNames(array $names): string
    {
        if (count($names) <= 1) {
            return $names[0] ?? '';
        }
        $head = array_slice($names, 0, 2);
        return implode(', ', $head) . (count($names) > 2 ? ' and more' : '');
    }

    private static function money(Tenant $tenant, float $price): string
    {
        $cur = strtoupper((string) ($tenant->setting('currency', $tenant->setting('base_currency', 'UGX')) ?: 'UGX'));
        $decimals = in_array($cur, ['UGX', 'SSP', 'KES', 'TZS', 'RWF'], true) ? 0 : 2;

        return $cur . ' ' . number_format($price, $decimals);
    }

    /** Best-effort catalogue/product link from the tenant's storefront setting; null when none set. */
    private static function link(Tenant $tenant, array $offer): ?string
    {
        if (! empty($offer['link'])) {
            return (string) $offer['link'];
        }
        $base = trim((string) $tenant->setting('storefront_url', ''));
        if ($base === '') {
            return null;
        }

        return rtrim($base, '/');
    }
}
