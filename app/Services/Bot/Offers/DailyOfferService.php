<?php

namespace App\Services\Bot\Offers;

use App\Models\DailyOffer;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Status Intelligence — orchestration over the pure rules + the model. Owner posters become
 * Active Daily Offers; customer menu/thali/special queries are served from them (priority:
 * Active Daily Offers > Fresh Today products > catalogue).
 */
class DailyOfferService
{
    public function __construct(protected OfferVision $vision) {}

    /**
     * Ingest an owner's image (menu poster / status): OCR + structure via vision, fall back to
     * the caption text, store as the active offer. Returns the stored canonical offer or null.
     */
    public function ingestImage(Tenant $tenant, string $b64, string $caption, string $imageUrl = '', string $source = 'image'): ?array
    {
        $e = $b64 !== '' ? $this->vision->extract($b64, $caption) : null;
        if ((! $e || empty($e['found'])) && trim($caption) !== '') {
            $e = OfferExtractor::fromText($caption);   // no AI / unreadable image: try the caption
        }
        if (! $e || empty($e['found'])) return null;

        return $this->toCanonical($this->store($tenant, $e, $imageUrl, $source));
    }

    /** Store an extracted offer, auto-replacing any active offer of the same type. */
    public function store(Tenant $tenant, array $e, string $imageUrl = '', string $source = 'image'): DailyOffer
    {
        $tz   = (string) $tenant->setting('timezone', 'Africa/Kampala');
        $now  = Carbon::now($tz);
        $type = in_array($e['type'] ?? '', OfferTypeClassifier::TYPES, true) ? $e['type'] : OfferTypeClassifier::SPECIAL;

        $sunday = $now->isSunday() ? $now->copy() : $now->copy()->next(Carbon::SUNDAY);
        [$from, $until] = OfferRules::defaultWindow(
            $type,
            $now->timestamp,
            $now->copy()->endOfDay()->timestamp,
            $sunday->endOfDay()->timestamp
        );

        // Auto-replace: deactivate active offers of the same type (one live per type per tenant).
        DailyOffer::where('offer_type', $type)->where('is_active', true)->update(['is_active' => false]);

        return DailyOffer::create([
            'offer_type'      => $type,
            'title'           => $e['title'] ?? null,
            'description'     => $e['description'] ?? null,
            'price'           => $e['price'] ?? null,
            'currency'        => $e['currency'] ?: (string) $tenant->setting('currency', 'UGX'),
            'image_url'       => $imageUrl ?: null,
            'structured_data' => [
                'items'      => array_values((array) ($e['items'] ?? [])),
                'day'        => $e['day'] ?? null,
                'confidence' => $e['confidence'] ?? null,
            ],
            'source'          => $source,
            'valid_from'      => Carbon::createFromTimestamp($from, $tz),
            'valid_until'     => $until !== null ? Carbon::createFromTimestamp($until, $tz) : null,
            'is_active'       => true,
        ]);
    }

    /** Active offers (canonical arrays), ranked — restricted/ordered by a query kind if given. */
    public function activeFor(Tenant $tenant, ?string $kind = null): array
    {
        $rows = DailyOffer::where('is_active', true)->get()
            ->map(fn (DailyOffer $o) => $this->toCanonical($o))->all();
        $prefer = $kind ? OfferQueryMatcher::typesForKind($kind) : [];

        return OfferRules::activeSorted($rows, time(), $prefer);
    }

    /**
     * Customer-facing reply for an offer query. Priority: Active Daily Offers, then Fresh Today
     * products, then null (so the normal thali/catalogue flow handles it).
     */
    public function serveCustomer(Tenant $tenant, string $kind): ?string
    {
        $cur = (string) $tenant->setting('currency', 'UGX');

        $offers = $this->activeFor($tenant, $kind);
        if ($offers) {
            return OfferFormatter::customerReply($offers, $cur);
        }

        // Priority 2 — Fresh Today products (no live poster, but items flagged fresh).
        $fresh = Product::where('active', true)->where('is_fresh_today', true)->orderBy('name')->limit(8)->get();
        if ($fresh->isNotEmpty()) {
            $lines = ['🔥 *Fresh today:*'];
            foreach ($fresh as $p) {
                $price = (float) ($p->base_price ?? $p->price ?? 0);
                $lines[] = '• ' . $p->name . ($price > 0 ? ' — ' . $cur . ' ' . number_format($price) : '');
            }
            $lines[] = 'Reply with a name to add it.';
            return implode("\n", $lines);
        }

        return null;   // Priority 3 — catalogue / static thali handled downstream
    }

    /**
     * Resolve the offer a conversation is talking about. Priority 1: the pinned offer if it's
     * still active (conversation context). Priority 2: the top active offer. Null if none active.
     */
    public function resolveContextOffer(Tenant $tenant, ?int $pinnedId = null): ?array
    {
        return OfferRules::pickContext($this->activeFor($tenant), $pinnedId);
    }

    /**
     * Answer a "is X in today's menu?" question from a resolved context offer's items.
     * Returns null (fall through) when the offer has no items, the item isn't named, or the
     * phrase isn't a recognised food (so greetings like "kem che" are not answered as menus).
     *
     * @param array $ctx  a canonical offer array from resolveContextOffer()
     * @param array{type:string,item:string} $iq  from ItemQueryParser::detect()
     */
    public function answerItemFrom(array $ctx, array $iq): ?string
    {
        $items = (array) ($ctx['items'] ?? []);
        if (! $items) return null;

        $item = (string) ($iq['item'] ?? '');
        if ($item === '') return null;

        $title = trim((string) ($ctx['title'] ?? '')) ?: 'today’s offer';

        $m = OfferItemMatcher::find($item, $items);
        if ($m !== null) {
            if (($iq['type'] ?? '') === 'count' && $m['count'] !== null) {
                return "Yes — today's *{$title}* includes *{$m['display']}* ({$m['count']}).";
            }
            return "Yes, today's *{$title}* includes *{$m['display']}*.";
        }

        if (! ItemAliases::isKnownFood($item)) return null;

        $list = implode("\n", array_map(fn ($i) => '• ' . trim((string) $i), $items));
        return "No, today's *{$title}* contains:\n{$list}\n\n(Want it added separately? Just tell me.)";
    }

    /** Answer a whole-offer price question from a resolved context offer. Null if no price set. */
    public function answerPriceFrom(array $ctx, string $currency): ?string
    {
        $price = $ctx['price'] ?? null;
        if ($price === null || (int) $price <= 0) return null;     // no price -> fall through

        $title = trim((string) ($ctx['title'] ?? '')) ?: 'today’s offer';
        return "Today's *{$title}* is *{$currency} " . number_format((int) $price) . "*.";
    }

    /**
     * Answer a "is X in today's menu?" question. Convenience wrapper resolving the context offer
     * (pinned-then-active) then delegating to answerItemFrom().
     *
     * @param array{type:string,item:string} $iq
     */
    public function answerItem(Tenant $tenant, array $iq, ?int $pinnedId = null): ?string
    {
        $ctx = $this->resolveContextOffer($tenant, $pinnedId);
        return $ctx ? $this->answerItemFrom($ctx, $iq) : null;
    }

    private function toCanonical(DailyOffer $o): array
    {
        $sd = is_array($o->structured_data) ? $o->structured_data : [];

        return [
            'id'          => $o->id,
            'type'        => $o->offer_type,
            'title'       => $o->title,
            'price'       => $o->price !== null ? (int) $o->price : null,
            'currency'    => $o->currency,
            'description' => $o->description,
            'items'       => array_values((array) ($sd['items'] ?? [])),
            'day'         => $sd['day'] ?? null,
            'image_url'   => $o->image_url,
            'is_active'   => (bool) $o->is_active,
            'valid_from'  => $o->valid_from?->timestamp,
            'valid_until' => $o->valid_until?->timestamp,
        ];
    }
}
