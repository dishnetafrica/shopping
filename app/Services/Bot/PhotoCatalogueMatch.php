<?php

namespace App\Services\Bot;

use App\Models\Tenant;
use App\Support\BotTrace;
use Illuminate\Support\Facades\DB;

/**
 * v4.24 — "a customer sent a photo of what they want; do we sell it?"
 *
 * The rules brain (bot_mode auto/inbuilt) has done this since v4.4: vision turns the photo into a
 * search phrase, the phrase is checked against the REAL catalogue, and only real products reach the
 * customer. Tenants on the AI brain (bot_mode = ai — Family Shoppers, East Africa) never got that:
 * their photos went straight to the vision model as a chat image, so the AI could describe the photo
 * beautifully and invent a price for something the shop does not stock.
 *
 * This service is that missing middle step, packaged so BOTH brains can use it. It returns FACTS —
 * what the photo probably is, and which of OUR products actually match, with our prices and stock —
 * which AiBrain then injects as ground truth. The AI writes the reply; it never chooses the product.
 *
 * Three honest outcomes, never a guess:
 *   matched     → real products (id, name, price, stock) the AI may quote from
 *   no_match    → we identified the item but do not stock it → say so, offer to source it
 *   unreadable  → could not tell what it is → ask the customer to type the name
 */
class PhotoCatalogueMatch
{
    /** How many candidates the AI is shown. More than this reads as a catalogue dump. */
    private const MAX_PRODUCTS = 5;

    public function __construct(
        private ?VisionProductFinder $finder = null,
        private ?BotBrain $brain = null,
    ) {}

    public function enabled(Tenant $tenant): bool
    {
        return (bool) $tenant->setting('feature_image_search', true);
    }

    /**
     * @return array{
     *   outcome:string, query:string, confidence:int, known:bool,
     *   products:array<int,array{id:mixed,name:string,price:float,stock:mixed}>,
     *   candidate_ids:array<int,string>, hash:string, note:string
     * }
     */
    public function match(Tenant $tenant, string $imageB64, string $caption = '', ?string $trace = null, ?string $from = null): array
    {
        $out = ['outcome' => 'unreadable', 'query' => '', 'confidence' => 0, 'known' => false,
            'products' => [], 'candidate_ids' => [], 'hash' => '', 'note' => ''];

        try {
            if (! $this->enabled($tenant) || trim($imageB64) === '') {
                $out['note'] = 'photo matching is switched off';

                return $out;
            }
            $out['hash'] = sha1($imageB64);

            // 1 ── Have customers already told us what this exact photo is? Then no AI call at all.
            $known = $this->knownProduct($tenant, $out['hash']);
            if ($known) {
                $out['outcome'] = 'matched';
                $out['known'] = true;
                $out['query'] = $known['name'];
                $out['confidence'] = 100;
                $out['products'] = $this->hydrate($tenant, [$known['name']]);
                $out['candidate_ids'] = [(string) $known['product_id']];
                $out['note'] = 'recognised from previous customers who chose this product for the same photo';
                $this->trace($tenant, $trace, $from, 'photo_known', $known['name'] . ' (' . $known['votes'] . '/' . $known['total'] . ')');

                return $out;
            }

            // 2 ── Ask vision what it is (cached per image, so a resent photo is free)
            $finder = $this->finder ?: app(VisionProductFinder::class);
            if (! $finder->enabled()) {
                $out['note'] = 'no AI key configured on this server';

                return $out;
            }
            $res = $finder->identify($imageB64, $caption);
            $query = trim((string) ($res['query'] ?? ''));
            $conf = (int) ($res['confidence'] ?? 0);
            $minConf = (int) $tenant->setting('image_search_min_confidence', 50);

            if ($query === '' || $conf < $minConf) {
                $out['query'] = $query;
                $out['confidence'] = $conf;
                $out['note'] = $query === ''
                    ? 'the photo could not be read'
                    : 'not confident enough (' . $conf . '% < ' . $minConf . '%)';
                $this->trace($tenant, $trace, $from, 'photo_unidentified', $out['note']);

                return $out;
            }
            $out['query'] = $query;
            $out['confidence'] = $conf;

            // 3 ── The hallucination guard: the phrase must hit REAL products in THIS shop.
            $brain = $this->brain ?: app(BotBrain::class);
            $cands = $brain->searchCatalogue($tenant, $query);
            if (! $cands) {
                $out['outcome'] = 'no_match';
                $out['note'] = 'identified as "' . $query . '" but nothing in this catalogue matches';
                $this->trace($tenant, $trace, $from, 'photo_search_miss', $query);

                return $out;
            }

            $out['outcome'] = 'matched';
            $out['products'] = $this->shape(array_slice($cands, 0, self::MAX_PRODUCTS));
            $out['candidate_ids'] = array_values(array_filter(array_map(
                fn ($p) => (string) ($p['id'] ?? ''), array_slice($cands, 0, 8)
            )));
            $this->trace($tenant, $trace, $from, 'photo_matched', $query . ' (' . $conf . '%) → ' . count($out['products']) . ' product(s)');

            return $out;
        } catch (\Throwable $e) {
            \Log::warning('PhotoCatalogueMatch failed: ' . $e->getMessage());
            $out['note'] = 'photo matching failed';

            return $out;
        }
    }

    /**
     * The ground-truth block handed to the AI. Deliberately imperative: the model's job is to write
     * the sentence, NOT to decide which product the customer gets or what it costs.
     */
    public function promptBlock(Tenant $tenant, array $m): string
    {
        $cur = (string) ($tenant->setting('currency', 'UGX') ?: 'UGX');

        if (($m['outcome'] ?? '') === 'matched' && ! empty($m['products'])) {
            $lines = [];
            foreach ($m['products'] as $p) {
                $stock = $p['stock'];
                $avail = $stock === null ? '' : ($stock > 5 ? ' · in stock' : ($stock > 0 ? ' · low stock' : ' · to order'));
                $lines[] = '• ' . $p['name'] . ' — ' . $cur . ' ' . rtrim(rtrim(number_format((float) $p['price'], 2), '0'), '.') . $avail;
            }

            return "\n\n=== THE CUSTOMER'S PHOTO ===\n"
                . 'It looks like: ' . $m['query'] . ($m['known'] ? ' (customers have identified this exact photo before)' : ' (' . $m['confidence'] . '% confident)') . ".\n"
                . "These are the REAL products in OUR catalogue that match:\n" . implode("\n", $lines) . "\n"
                . "RULES: quote ONLY from this list, at exactly these prices. Name the best match first and ask them to confirm it is the right one. "
                . "Never describe the photo back to them in detail, never invent a product, a brand or a price that is not in the list above, "
                . "and never claim we stock something just because it is in the photo.";
        }

        if (($m['outcome'] ?? '') === 'no_match') {
            return "\n\n=== THE CUSTOMER'S PHOTO ===\n"
                . 'It looks like: ' . $m['query'] . ". We do NOT have anything matching it in our catalogue.\n"
                . 'RULES: say honestly that we do not currently stock it, offer to check with our supplier and come back with a price, '
                . 'and invite them to tell you the exact name or brand in case we list it differently. Never invent a price or a substitute we do not sell.';
        }

        return "\n\n=== THE CUSTOMER'S PHOTO ===\n"
            . "We could not work out what the product is from the photo (" . ($m['note'] ?: 'unreadable') . ").\n"
            . 'RULES: apologise briefly, ask them to type the product name or send a clearer photo of the label. Do not guess.';
    }

    /**
     * Record which product the customer actually chose after sending a photo. That vote is what makes
     * the same photo free (and instant) next time — the shop teaches itself.
     */
    public function remember(Tenant $tenant, string $hash, string $customerPhone, $productId, string $productName, string $query = '', int $confidence = 0): void
    {
        // Column names mirror the 2026-06-18 migration exactly — `vision_query` is NOT NULL and there
        // is no updated_at. Getting either wrong throws into the catch below and the shop would never
        // learn anything, silently.
        if ($hash === '' || ! $productId) {
            return;
        }
        try {
            DB::table('image_search_feedback')->insert([
                'tenant_id' => $tenant->id,
                'customer_phone' => $customerPhone !== '' ? substr($customerPhone, 0, 32) : null,
                'image_hash' => substr($hash, 0, 64),
                'vision_query' => mb_substr($query !== '' ? $query : $productName, 0, 160),
                'product_id' => $productId,
                'product_name' => mb_substr($productName, 0, 200),
                'confidence' => $confidence > 0 ? min(100, $confidence) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // the feedback table is an optimisation, never a requirement
        }
    }

    /** Consensus lookup over what previous customers picked for this exact image. */
    private function knownProduct(Tenant $tenant, string $hash): ?array
    {
        if ($hash === '') {
            return null;
        }
        $minVotes = max(2, (int) $tenant->setting('image_shortcut_min_votes', 3));
        $minShare = (float) $tenant->setting('image_shortcut_min_share', 0.8);
        $minCustomers = max(1, (int) $tenant->setting('image_shortcut_min_customers', 2));

        try {
            $rows = DB::table('image_search_feedback')
                ->where('tenant_id', $tenant->id)
                ->where('image_hash', $hash)
                ->whereNotNull('product_id')
                ->selectRaw('product_id, MAX(product_name) as name, count(*) as votes, count(distinct customer_phone) as uniq')
                ->groupBy('product_id')
                ->orderByDesc('votes')
                ->get();
        } catch (\Throwable $e) {
            return null;
        }
        if ($rows->isEmpty()) {
            return null;
        }
        $total = (int) $rows->sum('votes');
        $top = $rows->first();
        if ($total >= $minVotes && ((int) $top->votes / max(1, $total)) > $minShare && (int) $top->uniq >= $minCustomers) {
            return ['product_id' => $top->product_id, 'name' => (string) $top->name,
                'votes' => (int) $top->votes, 'total' => $total];
        }

        return null;
    }

    /** Re-read a known product name through the live catalogue so the price is today's. */
    private function hydrate(Tenant $tenant, array $names): array
    {
        $brain = $this->brain ?: app(BotBrain::class);
        $out = [];
        foreach ($names as $n) {
            $hits = $brain->searchCatalogue($tenant, (string) $n);
            if ($hits) {
                $out = array_merge($out, $this->shape(array_slice($hits, 0, 1)));
            }
        }

        return $out;
    }

    /** @return array<int,array{id:mixed,name:string,price:float,stock:mixed}> */
    private function shape(array $rows): array
    {
        $out = [];
        foreach ($rows as $p) {
            $name = trim((string) ($p['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $out[] = [
                'id' => $p['id'] ?? null,
                'name' => $name,
                'price' => (float) ($p['price'] ?? 0),
                'stock' => array_key_exists('stock', $p) ? $p['stock'] : null,
            ];
        }

        return $out;
    }

    private function trace(Tenant $tenant, ?string $trace, ?string $from, string $stage, string $detail): void
    {
        if ($trace === null) {
            return;
        }
        try {
            BotTrace::log($tenant->id, $trace, $from, $stage, $detail);
        } catch (\Throwable $e) {
        }
    }
}
