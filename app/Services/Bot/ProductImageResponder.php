<?php

namespace App\Services\Bot;

use App\Models\Conversation;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Bot\Pricing\WeightPricer;
use App\Services\Catalogue\ProductSearch;
/**
 * Decides which product image(s) to send for a customer turn, and builds the caption
 * (variant sizes included). Pure of side effects: returns up to 5
 * ['media' => absoluteUrl, 'caption' => text]; the caller does gateway->sendImage(),
 * so the same output works for Evolution (/message/sendMedia) and Cloud (image message).
 *
 * Discovery turns only — a clear single-product ask (scored > 80) or a "show <category>"
 * browse. Quantity-led asks ("2 Kaju Katri", "500g kaju") DO get an image: the quantity is
 * stripped before matching. Cart/checkout/greeting and multi-item lists are skipped so it
 * never spams images mid-order.
 */
class ProductImageResponder
{
    /** Send the image only when intent confidence clears this bar. */
    public const SCORE_THRESHOLD = 80;

    public function __construct(private ProductSearch $search, private ?ComboEngine $combos = null) {}

    /** @return array<int,array{media:string,caption:string}> */
    public function imagesFor(Tenant $tenant, ?Conversation $convo, string $text): array
    {
        if (! (bool) $tenant->setting('send_product_images', true)) return [];

        $raw = trim($text);
        if ($raw === '') return [];
        $t = mb_strtolower($raw);

        // Control / cart turns — never attach images.
        if (preg_match('/\b(checkout|check out|cart|basket|total|pay|payment|confirm|cancel|remove|delete|order now|done|finish)\b/u', $t)) return [];
        // Greetings / yes-no / menu.
        if (preg_match('/^(hi+|hello|hey+|ok(ay)?|yes|no|y|n|thanks|thank you|thx|menu|help|start)\W*$/u', $t)) return [];

        $cur = (string) $tenant->setting('currency', 'UGX');

        // ---- "More photos" / "show packaging" / "other pictures": send the GALLERY of the
        // last product we showed this customer, without them re-typing its name. Max 3.
        if ($this->isGalleryIntent($t)) {
            $lastId = $convo ? (int) (($convo->state['last_image_product'] ?? 0)) : 0;
            if ($lastId <= 0) return [];
            $p = Product::find($lastId);
            if (! $p) return [];
            return $this->galleryFor($tenant, $p);     // [] when the product has no gallery images
        }

        // ---- CATEGORY browse: "show sweets" / a bare category name -> up to 5 short cards.
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
                $out[] = ['media' => $url, 'caption' => $i . '. ' . $this->card($p, $cur, true)];
            }
            return array_slice($out, 0, 5);
        }

        // ---- SINGLE product. Multi-item lists / multi-line are cart fills -> no image spam.
        if (substr_count($t, ',') >= 1) return [];
        if (str_contains($t, "\n") && count(array_filter(array_map('trim', preg_split('/\R+/u', $t)))) >= 2) return [];

        // Strip a leading/embedded quantity+unit so "2 Kaju Katri" / "500g kaju" still resolve.
        $clean = trim((string) preg_replace('/\b\d+(\.\d+)?\s*(kg|g|gm|gram|grams|pcs|pc|piece|pieces|packet|pkt|dozen)?\b/u', ' ', $raw));
        $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));
        if ($clean === '') $clean = $raw;

        $hits = $this->search->find($clean, 3);
        if ($hits->isEmpty()) {                                   // typo'd second word? fall back to first word
            $first = preg_split('/\s+/u', $clean)[0] ?? '';
            if (mb_strlen($first) >= 3) $hits = $this->search->find($first, 3);
        }
        if ($hits->isEmpty()) return [];

        if ($this->confidenceScore($t, $clean, $hits) <= self::SCORE_THRESHOLD) return [];

        $p   = $hits->first();
        $url = $this->absUrl($tenant, (string) $p->image_url);
        if ($url === '') return [];

        $this->remember($convo, (int) $p->id);     // so a follow-up "more photos" works

        $caption = $this->card($p, $cur, false);
        if ($this->combos && (bool) $tenant->setting('combo_recommendations', true)) {
            $pair = $this->combos->recommendForProduct((int) $tenant->id, (int) $p->id, 2);
            if ($pair) {
                $names = array_values(array_filter(array_map(fn ($c) => (string) ($c['name'] ?? ''), $pair)));
                if ($names) $caption .= "\nGoes well with: " . implode(', ', $names);
            }
        }
        return [['media' => $url, 'caption' => $caption]];
    }

    /** Detect a "more photos / other pictures / packaging / gallery" follow-up. */
    private function isGalleryIntent(string $t): bool
    {
        if (preg_match('/\b(more|other|another|additional|extra)\s+(photo|photos|pic|pics|picture|pictures|image|images|angle|angles|view|views|shot|shots)\b/u', $t)) return true;
        if (preg_match('/\b(packaging|gallery)\b/u', $t)) return true;
        return false;
    }

    /** Up to 3 gallery images for a product, as absolute URLs. */
    private function galleryFor(Tenant $tenant, Product $p): array
    {
        $out = [];
        foreach (['gallery_1', 'gallery_2', 'gallery_3'] as $col) {
            $u = $this->absUrl($tenant, (string) ($p->{$col} ?? ''));
            if ($u === '') continue;
            $out[] = ['media' => $u, 'caption' => empty($out) ? ('More photos — ' . $this->cleanName($p)) : ''];
        }
        return array_slice($out, 0, 3);
    }

    /** Persist which product was last shown, for a name-free "more photos" follow-up. */
    private function remember(?Conversation $convo, int $productId): void
    {
        if (! $convo || $productId <= 0) return;
        $st = is_array($convo->state) ? $convo->state : [];
        $st['last_image_product'] = $productId;
        $convo->state = $st;
    }

    /**
     * 0-100 confidence that this turn wants a product image.
     * Phrase intent (P) blended with match strength (M).
     */
    public function confidenceScore(string $t, string $clean, $hits): int
    {
        if ($hits === null || $hits->isEmpty()) return 0;

        if (preg_match('/\b(which|recommend|suggest)\b/u', $t) || preg_match('/\bbest\b/u', $t)) $P = 40;
        elseif (preg_match('/\b(what\s*is|what\'?s|whats|tell me about|about)\b/u', $t))        $P = 85;
        elseif (preg_match('/\b(do you have|you have|got|have you|available|in stock|stock)\b/u', $t)) $P = 90;
        elseif (preg_match('/\b(price|cost|how much|rate)\b/u', $t))                            $P = 95;
        else                                                                                   $P = 100;

        $name = mb_strtolower((string) $hits->first()->name);
        $ask  = trim((string) preg_replace('/\b(show me|show|send|see|need|want|i want|i need|price of|price|cost|how much|do you have|you have|got|have|tell me about|what is|what\'?s|whats|about|the|some|any|me|a|of)\b/u', ' ', $clean));
        $ask  = trim((string) preg_replace('/\s+/u', ' ', $ask));

        if ($hits->count() === 1) {
            $M = 100;
        } elseif ($ask !== '' && mb_strpos($name, $ask) !== false) {
            $M = 95;
        } else {
            $hit = false;
            foreach (preg_split('/\s+/u', $ask) as $w) {
                if (mb_strlen($w) >= 3 && mb_strpos($name, $w) !== false) { $hit = true; break; }
            }
            $M = $hit ? 85 : 60;
        }

        return (int) round(($P + $M) / 2);
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
            if ($cl === $body || rtrim($cl, 's') === rtrim($body, 's')) return (string) $c;
        }
        return null;
    }

    /**
     * Caption. $short=true -> single name+price line (for 5-up category lists).
     * $short=false -> full card with variant sizes + a "reply with size" prompt.
     */
    public function card(Product $p, string $cur, bool $short = false): string
    {
        $header = $this->cleanName($p);

        if ($short) {
            $price = (float) ($p->base_price ?? $p->price ?? 0);
            return $price > 0 ? $header . ' — ' . $cur . ' ' . number_format($price) : $header;
        }

        $sizes = $this->variantLines($p);
        if (! $sizes) {
            $price = (float) ($p->base_price ?? $p->price ?? 0);
            return $price > 0 ? $header . ' — ' . $cur . ' ' . number_format($price) : $header;
        }

        $out = $header . "\nAvailable:";
        $labels = [];
        foreach ($sizes as $s) {
            $out .= "\n• " . $s['label'] . ' - ' . $cur . ' ' . number_format($s['price']);
            $labels[] = $s['label'];
        }
        $out .= "\nReply with: " . implode(' / ', $labels);
        return $out;
    }

    /** Explicit weight variants if present, else synthesized 250g/500g/1kg for loose goods. */
    private function variantLines(Product $p): array
    {
        $vs = [];
        try {
            foreach ($p->weightVariants()->orderBy('weight_grams')->get() as $v) {
                $g = (int) $v->weight_grams; $pr = (float) $v->price;
                if ($g > 0 && $pr > 0) $vs[] = ['grams' => $g, 'label' => $this->gramsLabel($g), 'price' => $pr];
            }
        } catch (\Throwable $e) { $vs = []; }
        if ($vs) return $vs;

        if ((bool) ($p->sold_by_weight ?? false) && (float) ($p->reference_price ?? 0) > 0) {
            $refW = (int) ($p->reference_weight_grams ?: 1000);
            $refP = (float) $p->reference_price;
            foreach ([250, 500, 1000] as $g) {
                $r = WeightPricer::price($g, ['reference_price' => $refP, 'reference_weight_grams' => $refW]);
                if (! empty($r['ok'])) $vs[] = ['grams' => $g, 'label' => $this->gramsLabel($g), 'price' => (float) $r['price']];
            }
        }
        return $vs;
    }

    private function gramsLabel(int $g): string
    {
        if ($g % 1000 === 0) return ($g / 1000) . 'kg';
        if ($g > 1000)       return rtrim(rtrim(number_format($g / 1000, 2), '0'), '.') . 'kg';
        return $g . 'g';
    }

    /** Drop a trailing size suffix from loose-goods names ("Kaju Katli 1 Kg" -> "Kaju Katli"). */
    private function cleanName(Product $p): string
    {
        $n = (string) $p->name;
        if ((bool) ($p->sold_by_weight ?? false)) {
            $n = (string) preg_replace('/\s+\d+(\.\d+)?\s*(kg|kgs|g|gm|gms|gram|grams)\s*$/iu', '', $n);
        }
        $n = trim($n);
        return $n !== '' ? $n : (string) $p->name;
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
