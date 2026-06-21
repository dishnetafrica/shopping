<?php

namespace App\Services\Bot;

use App\Models\Conversation;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Catalogue\ProductSearch;

/**
 * Decides which product image(s) to send for a customer turn.
 *
 * Pure of side effects: it returns up to 5 ['media' => absoluteUrl, 'caption' => text]
 * and the caller (ProcessIncomingMessage) does the actual gateway->sendImage(), so the
 * same output works for BOTH transports (Evolution /message/sendMedia and Cloud image
 * messages) — the gateway interface already implements sendImage() for each.
 *
 * Conservative by design: it only fires for discovery turns (a clear single-product ask
 * or a "show <category>" browse), never for cart/checkout/quantity/greeting turns, so it
 * doesn't spam images while a customer is building an order.
 */
class ProductImageResponder
{
    public function __construct(private ProductSearch $search) {}

    /** @return array<int,array{media:string,caption:string}> */
    public function imagesFor(Tenant $tenant, ?Conversation $convo, string $text): array
    {
        if (! (bool) $tenant->setting('send_product_images', true)) return [];

        $raw = trim($text);
        if ($raw === '') return [];
        $t = mb_strtolower($raw);

        // Control / cart turns — never attach images.
        if (preg_match('/\b(checkout|check out|cart|basket|total|pay|payment|confirm|cancel|remove|delete|order now|done|finish)\b/u', $t)) return [];
        // Greetings / yes-no / menu — not a product ask.
        if (preg_match('/^(hi+|hello|hey+|ok(ay)?|yes|no|y|n|thanks|thank you|thx|menu|help|start)\W*$/u', $t)) return [];

        $cur = (string) $tenant->setting('currency', 'UGX');

        // ---- CATEGORY browse: "show sweets" / "show me namkeen" / a bare category name.
        $cat = $this->matchCategory($tenant, $t);
        if ($cat !== null) {
            $rows = Product::where('active', true)
                ->whereRaw('LOWER(TRIM(category)) = ?', [mb_strtolower($cat)])
                ->whereNotNull('image_url')->where('image_url', '<>', '')
                ->orderByDesc('display_order')->orderBy('name')
                ->limit(5)->get();
            $out = []; $i = 0;
            foreach ($rows as $p) {
                $url = $this->absUrl($tenant, (string) $p->image_url);
                if ($url === '') continue;
                $i++;
                $out[] = ['media' => $url, 'caption' => $i . '. ' . $this->card($p, $cur)];
            }
            return array_slice($out, 0, 5);
        }

        // ---- SINGLE product ask only. Skip order-shaped input (lists, quantities).
        if (substr_count($t, ',') >= 1) return [];                                   // multi-item list → cart fill
        if (preg_match('/\b\d+\s*(kg|g|gm|gram|grams|pcs|pc|piece|pieces|packet|pkt|dozen)\b/u', $t)) return [];
        if (preg_match('/^\d+\s+\S/u', $t)) return [];                               // "2 thali ..." order line

        $hits = $this->search->find($raw, 3);
        if ($hits->isEmpty()) return [];
        $p = $hits->first();

        // Only when the match is unambiguous, or the product name clearly contains the ask.
        $name = mb_strtolower((string) $p->name);
        $ask  = trim((string) preg_replace('/\b(show me|show|need|want|i want|i need|price of|price|get me|do you have|have|the|some|any)\b/u', '', $t));
        $confident = $hits->count() === 1 || ($ask !== '' && mb_strpos($name, $ask) !== false);
        if (! $confident) return [];

        $url = $this->absUrl($tenant, (string) $p->image_url);
        if ($url === '') return [];

        return [['media' => $url, 'caption' => $this->card($p, $cur)]];
    }

    /** Resolve a "show <category>" or bare category name against the tenant's categories. */
    private function matchCategory(Tenant $tenant, string $t): ?string
    {
        $body = trim((string) preg_replace('/^(show me|show|see|browse|view|list)\s+/u', '', $t));
        if ($body === '') return null;

        $cats = Product::where('active', true)->whereNotNull('category')
            ->distinct()->pluck('category')->filter()->values()->all();
        $extra = $tenant->setting('category_extra', []);
        if (is_array($extra)) $cats = array_merge($cats, $extra);

        foreach ($cats as $c) {
            $cl = mb_strtolower(trim((string) $c));
            if ($cl === '') continue;
            if ($cl === $body || rtrim($cl, 's') === rtrim($body, 's')) return (string) $c; // singular/plural tolerant
        }
        return null;
    }

    private function card(Product $p, string $cur): string
    {
        $price = (float) ($p->base_price ?? $p->price ?? 0);
        $unit  = ((bool) ($p->sold_by_weight ?? false)) ? ('/' . ($p->weight_unit ?: 'kg')) : '';
        $line  = (string) $p->name;
        if ($price > 0) $line .= ' — ' . $cur . ' ' . number_format($price) . $unit;
        return $line;
    }

    /** WhatsApp must fetch a public absolute URL; stored paths are root-relative ("/storage/.."). */
    private function absUrl(Tenant $tenant, string $path): string
    {
        $path = trim($path);
        if ($path === '') return '';
        if (preg_match('#^https?://#i', $path)) return $path;
        $dom  = trim((string) ($tenant->custom_domain ?? ''));
        $base = $dom !== '' ? 'https://' . $dom : 'https://mycloudbss.com';
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}
