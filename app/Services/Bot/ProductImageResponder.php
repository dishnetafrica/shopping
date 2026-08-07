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
        // SERVICE / META turns — calls, location, delivery, complaints. These are never product
        // asks; fuzzy search on them produced absurd cards ("why are you not PICKING the call"
        // → Butter CHICKEN, real incident 31 Jul). Hard stop before any matching.
        if (preg_match('/\b(call|calling|called|phone|ring|pick(ing)?\s+(up|the)|location|address|where\s+(are|is)|deliver|delivery|deliveries|transport|shipping|complain|complaint|problem|wrong|refund|late|angry|manager|human|agent|person|speak|talk)\b/u', $t)) return [];
        // Frustration / rejection turns — "you don't have what I want", "never mind" — a product
        // card here reads as mockery (real incident: card attached right after that sentence).
        if (preg_match('/\b(don\'?t\s+have|not\s+what\s+i|never\s*mind|forget\s+it|no\s+thanks|cancel)\b/u', $t)) return [];

        $cur = (string) $tenant->setting('currency', 'UGX');

        // ---- Special Order photo: the customer asked to "see" an off-catalogue item we just
        // quoted. Send the pinned marketplace image(s) — host allow-listed so we never forward
        // an arbitrary URL — and NEVER reveal the source. Max 3.
        if (($so = $this->specialOrderPhotos($tenant, $convo, $t)) !== []) {
            return $so;
        }

        // ---- "More photos" / "show packaging" / "other pictures": send the GALLERY of the
        // last product we showed this customer, without them re-typing its name. Max 3.
        if ($this->isGalleryIntent($t)) {
            $lastId = $convo ? (int) (($convo->state['last_image_product'] ?? 0)) : 0;
            if ($lastId <= 0) return [];
            $p = Product::find($lastId);
            if (! $p) return [];
            $g = $this->galleryFor($tenant, $p);
            if ($g) \App\Support\ProductEvents::log((int) $tenant->id, (int) $p->id, 'gallery');
            return $g;                                 // [] when the product has no gallery images
        }

        // ---- CATEGORY browse: "show sweets" / a bare category name -> up to 5 short cards.
        $cat = $this->matchCategory($tenant, $t);
        if ($cat !== null) {
            // v4.54 — browsing a category never sends photos of a hidden line.
            $rows = \App\Services\Catalog\Visibility::scope(Product::where('active', true), $tenant)
                ->whereRaw('LOWER(TRIM(category)) = ?', [mb_strtolower($cat)])
                ->whereNotNull('image_url')->where('image_url', '<>', '')
                ->orderByDesc('display_order')->orderBy('name')
                ->limit(5)->get();
            $out = []; $i = 0; $viewed = [];
            foreach ($rows as $p) {
                $url = $this->absUrl($tenant, (string) $p->image_url);
                if ($url === '') continue;
                $i++;
                $out[] = ['media' => $url, 'caption' => $i . '. ' . $this->card($p, $cur, true)];
                $viewed[] = (int) $p->id;
                if ($i >= 5) break;
            }
            \App\Support\ProductEvents::logMany((int) $tenant->id, $viewed, 'view');
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
        \App\Support\ProductEvents::log((int) $tenant->id, (int) $p->id, 'view');

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

    /**
     * Photos for a pending Special Order the customer asked to see. Reads the marketplace
     * quote(s) pinned on the conversation by BotBrain, filters each image through the host
     * allow-list, and returns up to 3 ['media','caption'] entries. Never reveals the source.
     *
     * @return array<int,array{media:string,caption:string}>
     */
    private function specialOrderPhotos(Tenant $tenant, ?Conversation $convo, string $t): array
    {
        if ($convo === null) return [];

        // Only when the customer is actually asking to see it.
        if (! preg_match('/\b(photo|photos|pic|pics|picture|pictures|image|images|see it|show( me)?|send.*(photo|pic|image))\b/u', $t)) {
            return [];
        }

        $so = is_array($convo->state) ? ($convo->state['special_order'] ?? null) : null;
        if (! is_array($so)) return [];
        if (time() - (int) ($so['at'] ?? 0) > 1800) return [];        // stale after 30 min

        $hosts = (array) config('marketplace.allowed_image_hosts', []);
        $out = [];
        foreach ((array) ($so['quotes'] ?? []) as $q) {
            $img = trim((string) ($q['image_url'] ?? ''));
            if ($img === '') continue;
            if (! \App\Services\Marketplace\Support\ImageHostAllowList::allowed($img, $hosts)) continue;

            $name = trim((string) ($q['name'] ?? ''));
            $cur  = (string) ($q['currency'] ?? 'UGX');
            $price = isset($q['sell_price'])
                ? ' — ' . $cur . ' ' . number_format((float) $q['sell_price'], \App\Services\Pricing::decimalsForCurrency($cur))
                : '';
            $out[] = ['media' => $img, 'caption' => ($name !== '' ? ('Special Order: ' . $name . $price) : '')];
            if (count($out) >= 3) break;
        }
        return $out;
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
        $st['last_image_product'] = $productId;   // for "more photos"
        $st['last_image_at']      = time();        // freshness gate for a size reply
        $st['combo_focal']        = $productId;   // per-turn focal for the combo follow-up (job clears it)
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
        elseif (preg_match('/\b(i need|need|i want|want|looking for|buy|buying)\b/u', $t))      $P = 88;   // explicit purchase intent
        else                                                                                   $P = 70;   // a plain sentence is NOT automatic product intent (was 100 — "picking the call" scored max)

        $name = mb_strtolower((string) $hits->first()->name);
        $ask  = trim((string) preg_replace('/\b(show me|show|send|see|need|want|i want|i need|price of|price|cost|how much|do you have|you have|got|have|tell me about|what is|what\'?s|whats|about|the|some|any|me|a|of)\b/u', ' ', $clean));
        $ask  = trim((string) preg_replace('/\s+/u', ' ', $ask));

        // HARD GATE (31 Jul incidents): at least one REAL word the customer typed must appear in
        // the matched product's name. Fuzzy search alone is not evidence — "samples" is not
        // "Salt", "picking" is not "Chicken", and "BUT now you don't…" is not "BUTter" (second
        // incident, same evening: conversational filler matched as a 3-char substring). Overlap
        // words must be ≥4 chars AND not conversational filler. Without overlap: no image, ever.
        static $chatter = ['this','that','with','have','want','need','from','they','will','what','when','where','which','your','yours','just','told','tell','dont','cant','wont','didnt','about','them','then','than','some','very','much','here','there','also','only','still','wrong','good','okay','please','thanks','thank','hello','into','over','again','more','less','give','take','know','like','said','says','sorry','because','something','anything','everything','nothing'];
        $overlap = false;
        $askWords = array_values(array_filter(
            preg_split('/\s+/u', mb_strtolower($ask)),
            fn ($w) => mb_strlen($w) >= 3
        ));
        foreach ($askWords as $w) {
            if (mb_strlen($w) < 4) continue;
            if (in_array(str_replace(["'", '’'], '', $w), $chatter, true)) continue;
            if (mb_strpos($name, $w) !== false) { $overlap = true; break; }
        }
        if (! $overlap) return 0;

        // A long free sentence with no purchase-intent pattern is conversation, not a product name.
        if ($P === 70 && count($askWords) > 5) return 0;
        // A SHORT message that overlaps the product name and doesn't start with a preposition
        // ("butter chicken masala", "rock salt") IS the ask — treat it as real intent. Fragments
        // like "for fish" keep the low default (they refine the current topic; the model handles it).
        if ($P === 70 && count($askWords) <= 3 && ! preg_match('/^(for|with|and|but|to|in|on|at|from|about)\b/u', mb_strtolower($ask))) {
            $P = 85;
        }

        if ($ask !== '' && mb_strpos($name, $ask) !== false) {
            $M = 100;              // the whole ask is inside the product name — unambiguous
        } elseif ($hits->count() === 1) {
            $M = 90;               // single hit AND word overlap (the overlap is what earns trust —
        } else {                   // a lone fuzzy hit no longer auto-scores 100)
            $M = 85;
        }

        return (int) round(($P + $M) / 2);
    }

    /** Resolve a "show <category>" or bare category name against the tenant's categories. */
    private function matchCategory(Tenant $tenant, string $t): ?string
    {
        $body = trim((string) preg_replace('/^(show me|show|see|browse|view|list)\s+/u', '', $t));
        if ($body === '') return null;

        // v4.54 — a hidden department is not offered as something to browse.
        $cats = \App\Services\Catalog\Visibility::scope(Product::where('active', true), $tenant)->whereNotNull('category')
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
            $price = (float) ($p->price ?? $p->base_price ?? 0);   // price-drift fix: captions quote the PANEL price
            return $price > 0 ? $header . ' — ' . $cur . ' ' . number_format($price) : $header;
        }

        $sizes = $this->variantLines($p);
        if (! $sizes) {
            $price = (float) ($p->price ?? $p->base_price ?? 0);   // price-drift fix: captions quote the PANEL price
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
