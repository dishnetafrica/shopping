<?php

namespace App\Services\Bot\Offers;

use App\Models\DailyOffer;
use App\Models\OfferEvent;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Status Intelligence v14 — Owner Intent Learning.
 *
 * Owners type short messages ("Fafda sold out", "Only 5 thali left", "Lunch ready"); these become
 * offer_events rows that capture live business state. Customers then ask ("Fafda che?",
 * "Thali baki che?", "Lunch ready?") and are answered from the latest events.
 */
class OfferUpdateService
{
    private function tz(Tenant $tenant): string
    {
        return (string) $tenant->setting('timezone', 'Africa/Kampala');
    }

    /** Start of the current local day — events older than this are stale. */
    private function dayStart(Tenant $tenant): Carbon
    {
        return Carbon::now($this->tz($tenant))->startOfDay();
    }

    // ------------------------------------------------------------------ owner in

    /** Parse an owner message; if it's an update, record the event (+ side effects) and confirm. */
    public function applyOwnerText(Tenant $tenant, string $text): ?string
    {
        $u = OwnerUpdateParser::parse($text);
        if ($u === null) return null;

        return $this->applyParsed($tenant, $u, $text);
    }

    /**
     * Record a parsed/scored event ([event,item,qty,price,display]) + side effects; return the
     * owner confirmation line. Shared by the structured parser and the activity scorer.
     */
    public function applyParsed(Tenant $tenant, array $u, string $raw = ''): string
    {
        $event   = (string) ($u['event'] ?? '');
        $item    = (string) ($u['item'] ?? '');
        $display = (string) ($u['display'] ?? ucwords($item));
        $qty     = $u['qty']   ?? null;
        $price   = $u['price'] ?? null;
        $cur     = (string) $tenant->setting('currency', 'UGX');

        $offerId = $this->sideEffects($tenant, $event, $item, $price);

        OfferEvent::create([
            'offer_id'   => $offerId,
            'item'       => $item !== '' ? mb_strtolower($item) : null,
            'event_type' => $event,
            'payload'    => array_filter([
                'qty'      => $qty,
                'price'    => $price,
                'currency' => $cur,
                'display'  => $display,
                'raw'      => mb_substr(trim($raw !== '' ? $raw : $display), 0, 160),
            ], fn ($v) => $v !== null && $v !== ''),
            'created_at' => Carbon::now($this->tz($tenant)),
        ]);

        return $this->ownerConfirm($event, $display, $qty, $price, $cur);
    }

    /** Best-effort side effects: activate a meal offer on "ready"; flag a product fresh on "available". */
    private function sideEffects(Tenant $tenant, string $event, string $item, ?int $price): ?int
    {
        try {
            if ($event === 'ready') {
                $off = DailyOffer::where('offer_type', OfferTypeClassifier::DAILY_THALI)->orderByDesc('id')->first();
                if ($off) {
                    if (! $off->is_active) { $off->is_active = true; $off->save(); }
                    return (int) $off->id;
                }
            }
            if ($event === 'low_stock') {
                $off = DailyOffer::where('is_active', true)->orderByDesc('id')->first();
                return $off ? (int) $off->id : null;
            }
            if ($event === 'available' && $item !== '') {
                $p = Product::where('active', true)->where('name', 'like', '%' . $item . '%')->first();
                if ($p && ! (bool) ($p->is_fresh_today ?? false)) { $p->is_fresh_today = true; $p->save(); }
            }
            if ($event === 'price_change' && $item !== '' && $price !== null) {
                $off = DailyOffer::where('is_active', true)
                    ->where('title', 'like', '%' . $item . '%')->orderByDesc('id')->first();
                if ($off) { $off->price = $price; $off->save(); return (int) $off->id; }
            }
        } catch (\Throwable $e) {
            // side effects are best-effort; the event row is the source of truth
        }
        return null;
    }

    private function ownerConfirm(string $event, string $display, ?int $qty, ?int $price, string $cur): string
    {
        return match ($event) {
            'sold_out'     => "✅ Noted — *{$display}* marked sold out for today. I'll tell customers it's finished.",
            'available'    => "✅ Got it — *{$display}* is now live as available/fresh for customers.",
            'ready'        => "✅ Done — *{$display}* marked ready. Customers asking will be told it's ready.",
            'low_stock'    => "✅ Noted — only *{$qty} {$display}* left. I'll let customers know stock is low.",
            'price_change' => "✅ Updated — *{$display}* price set to *{$cur} " . number_format((int) $price) . "*.",
            default        => "✅ Update noted.",
        };
    }

    // --------------------------------------------------------------- customer out

    /** Latest event today whose item matches the queried item (alias-aware). */
    private function latestEventForItem(Tenant $tenant, string $queryItem): ?OfferEvent
    {
        if (trim($queryItem) === '') return null;

        $events = OfferEvent::whereNotNull('item')
            ->where('created_at', '>=', $this->dayStart($tenant))
            ->orderByDesc('created_at')->limit(40)->get();

        foreach ($events as $ev) {
            if (OfferItemMatcher::find($queryItem, [(string) $ev->item]) !== null) {
                return $ev;
            }
        }
        return null;
    }

    /** Availability answer for "X che?" / "X available?" from the latest owner event, else null. */
    public function answerItemState(Tenant $tenant, string $queryItem): ?string
    {
        $ev = $this->latestEventForItem($tenant, $queryItem);
        if (! $ev) return null;

        $disp = (string) ($ev->payload['display'] ?? ucwords((string) $ev->item));
        $cur  = (string) ($ev->payload['currency'] ?? $tenant->setting('currency', 'UGX'));
        $qty  = $ev->payload['qty']   ?? null;
        $price = $ev->payload['price'] ?? null;

        return match ($ev->event_type) {
            'sold_out'     => "Sorry, *{$disp}* is sold out for today. 😔",
            'available'    => "Yes — fresh *{$disp}* is available right now! 🔥",
            'ready'        => "Yes, *{$disp}* is ready! 🍽️",
            'low_stock'    => $qty ? "Only *{$qty} {$disp}* left — want me to set one aside?" : "*{$disp}* is running low — better hurry!",
            'price_change' => $price ? "*{$disp}* is now *{$cur} " . number_format((int) $price) . "*." : null,
            default        => null,
        };
    }

    /** Answer "lunch ready?" from the latest 'ready' event today, else null (don't claim not-ready). */
    public function answerReady(Tenant $tenant, string $item): ?string
    {
        if (trim($item) !== '') {
            $byItem = $this->latestEventForItem($tenant, $item);
            if ($byItem && in_array($byItem->event_type, ['ready', 'available'], true)) {
                $disp = (string) ($byItem->payload['display'] ?? ucwords((string) $byItem->item));
                return "Yes, *{$disp}* is ready! 🍽️";
            }
            if ($byItem && $byItem->event_type === 'sold_out') {
                $disp = (string) ($byItem->payload['display'] ?? ucwords((string) $byItem->item));
                return "Sorry, *{$disp}* is finished for today. 😔";
            }
        }

        $ev = OfferEvent::where('event_type', 'ready')
            ->where('created_at', '>=', $this->dayStart($tenant))
            ->orderByDesc('created_at')->first();
        if (! $ev) return null;

        $disp = (string) ($ev->payload['display'] ?? 'Lunch');
        return "Yes, *{$disp}* is ready! 🍽️";
    }

    /** Answer "thali baki che?" from the latest 'low_stock' event today, else null. */
    public function answerRemaining(Tenant $tenant, string $item): ?string
    {
        $q = OfferEvent::where('event_type', 'low_stock')
            ->where('created_at', '>=', $this->dayStart($tenant))
            ->orderByDesc('created_at');

        if (trim($item) !== '') {
            $byItem = $this->latestEventForItem($tenant, $item);
            if ($byItem && $byItem->event_type === 'low_stock') {
                $n = $byItem->payload['qty'] ?? null;
                $disp = (string) ($byItem->payload['display'] ?? ucwords((string) $byItem->item));
                return $n ? "Only *{$n} {$disp}* left right now — shall I reserve one?" : "Limited *{$disp}* left.";
            }
        }

        $ev = $q->first();
        if (! $ev) return null;

        $n = $ev->payload['qty'] ?? null;
        $disp = (string) ($ev->payload['display'] ?? 'Thali');
        return $n ? "Only *{$n} {$disp}* left right now — shall I reserve one?" : "Limited *{$disp}* left.";
    }
}
