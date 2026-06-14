<?php

namespace App\Services\Bot;

/**
 * Answers the everyday questions a customer would normally ask a shopkeeper on WhatsApp —
 * the kind a human shop attendant used to reply to. Pure + deterministic: match($text, $ctx)
 * returns a ready answer string, or null when nothing fits (so BotBrain falls back).
 *
 * $ctx lets the tenant's real settings flow in (hours, payment methods, min order, etc.);
 * every value has a sensible default so the FAQ still works before anything is configured.
 *
 * Ordering matters: the FIRST topic whose pattern matches wins, so more specific topics
 * (payment, delivery time) are listed before broad ones (how to order, generic help).
 */
class FaqDictionary
{
    /**
     * @param array $ctx keys (all optional): currency, payments[], hours, deliver_areas,
     *                   min_order, delivery_note, pay_on_delivery(bool), phone
     */
    public static function match(string $text, array $ctx = []): ?string
    {
        $lc = self::norm($text);
        if ($lc === '') return null;

        foreach (self::topics() as [$patterns, $builder]) {
            foreach ($patterns as $re) {
                if (preg_match($re, $lc)) {
                    return $builder($ctx);
                }
            }
        }
        return null;
    }

    /** Does this message look like a question the FAQ could answer? (cheap pre-check) */
    public static function looksLikeFaq(string $text): bool
    {
        return self::match($text, []) !== null;
    }

    private static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[^\p{L}\p{N}\s\?]/u', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    private static function cur(array $ctx): string
    {
        return $ctx['currency'] ?? 'UGX';
    }

    /** @return array<int,array{0:string[],1:callable}> */
    private static function topics(): array
    {
        return [
            // --- Payment methods -------------------------------------------------
            [[
                '/\b(how|what|which).{0,20}\b(pay|payment|paid)\b/',
                '/\b(do you|can i|accept).{0,12}\b(mtn|airtel|momo|mobile money|cash|card|visa)\b/',
                '/\b(pay|payment) (methods?|options?)\b/',
                '/\bmode of payment\b/',
            ], function ($ctx) {
                $pays = $ctx['payments'] ?? ['MTN MoMo', 'Airtel MoMo', 'Cash on delivery'];
                $list = self::humanList($pays);
                return "\u{1F4B3} You can pay by {$list}. After you check out we'll confirm the total and how to pay. \u{1F642}";
            }],

            // --- Pay on delivery / cash -----------------------------------------
            [[
                '/\b(pay|cash) (on|at|upon) delivery\b/',
                '/\bcash when (it|goods?|order) (arrive|come|deliver)/',
                '/\bpay (after|when) (i )?(receive|get)\b/',
            ], function ($ctx) {
                $cod = $ctx['pay_on_delivery'] ?? true;
                return $cod
                    ? "\u{1F4B5} Yes — you can pay cash when your order is delivered. Mobile money is welcome too."
                    : "We take payment when you check out (mobile money). I can connect you to the shop if you'd like to arrange something else.";
            }],

            // --- Delivery time / how fast ---------------------------------------
            [[
                '/\bhow (long|fast|soon)\b.{0,20}\b(deliver|delivery|arrive|get|receive|come)\b/',
                '/\b(delivery|deliver) (time|takes?|duration|eta)\b/',
                '/\bwhen will (i|it|my order)\b.{0,20}\b(arrive|come|reach|delivered)\b/',
                '/\btime.{0,10}\bdelivery\b/',
            ], function ($ctx) {
                $note = $ctx['delivery_note'] ?? null;
                if ($note) return "\u{1F6F5} {$note}";
                return "\u{1F6F5} Most deliveries arrive the same day, usually within a couple of hours after you check out — it depends on your area and how busy we are. We'll message you the moment it's on the way, with the rider's number.";
            }],

            // --- Delivery areas / do you deliver to X ---------------------------
            [[
                '/\b(do you|can you|you).{0,12}\bdeliver\b/',
                '/\bdelivery (areas?|zones?|coverage)\b/',
                '/\bwhere do you deliver\b/',
            ], function ($ctx) {
                $areas = $ctx['deliver_areas'] ?? null;
                if (! empty($areas)) {
                    $list = self::humanList(is_array($areas) ? $areas : [$areas]);
                    return "\u{1F6F5} Yes, we deliver — including {$list}. Share your *location pin* (tap \u{1F4CE} \u{2192} Location) and I'll confirm the fee for your spot.";
                }
                return "\u{1F6F5} Yes, we deliver across town. Share your *location pin* (tap \u{1F4CE} \u{2192} Location) and I'll work out the delivery fee for you.";
            }],

            // --- Opening hours / are you open -----------------------------------
            [[
                '/\b(open|opening|closing|business|working) (hours?|time|times)\b/',
                '/\bwhat time.{0,15}\b(open|close)\b/',
                '/\b(are|r) (you|u) open\b/',
                '/\bare you (closed|working|available)\b/',
            ], function ($ctx) {
                $hours = $ctx['hours'] ?? null;
                return $hours
                    ? "\u{1F551} Our hours: {$hours}. You can place an order here any time and we'll handle it when we're open."
                    : "\u{1F551} You can place your order here any time — we'll prepare and deliver it during shop hours. Want to start your order?";
            }],

            // --- How to order / how does this work ------------------------------
            [[
                '/\bhow (do|can) i (order|buy|shop|use this)\b/',
                '/\bhow does (this|it) work\b/',
                '/\bhow to (order|buy|place an order)\b/',
                '/\bhow do i (start|begin)\b/',
            ], function ($ctx) {
                return "\u{1F6D2} It's easy — just type what you'd like (e.g. *2 sugar, 1 cooking oil*). I'll add it to your basket, you say *checkout*, share your location, and we deliver. Try it — what do you need?";
            }],

            // --- Minimum order ---------------------------------------------------
            [[
                '/\bminimum (order|purchase|amount|spend)\b/',
                '/\bmin(imum)? (to )?order\b/',
                '/\bhow much.{0,15}\b(minimum|at least)\b/',
            ], function ($ctx) {
                $min = $ctx['min_order'] ?? null;
                $cur = self::cur($ctx);
                return $min
                    ? "\u{1F6D2} The minimum order is {$cur} " . number_format((int) $min) . ". Add what you need and I'll keep the running total."
                    : "\u{1F6D2} There's no fixed minimum — order what you need. Delivery has a small fee depending on your area.";
            }],

            // --- Discounts / negotiate / wholesale ------------------------------
            [[
                '/\b(discount|cheaper|reduce|negotiat|bargain|lower the price|best price)\b/',
                '/\b(whole ?sale|bulk) (price|order|rate)\b/',
            ], function ($ctx) {
                return "\u{1F4B0} Our prices are as listed, but for big or regular orders I can ask the shop about a better rate — tell me what you're planning and I'll connect you.";
            }],

            // --- Freshness / quality / expiry -----------------------------------
            [[
                '/\b(is|are|how)\b.{0,24}\bfresh\b/',
                '/\bfresh\s*\?/',
                '/\b(freshness|good quality|the quality|quality of|expiry|expir|expired|good condition|not expired|stale|rotten)\b/',
            ], function ($ctx) {
                return "\u{1F33F} We pick fresh, good-quality stock for every order and check dates before sending. If anything isn't right, tell us and we'll make it good.";
            }],

            // --- Wrong / missing / damaged item, returns ------------------------
            [[
                '/\b(wrong|missing|damaged|broken|return|refund|replace) (item|product|order|goods)?\b/',
                '/\b(i got|received) (the )?wrong\b/',
            ], function ($ctx) {
                return "\u{1F64F} Sorry about that — if something arrives wrong, missing or damaged, reply here and we'll sort a replacement or refund right away. I can connect you to the shop now if you like.";
            }],

            // --- Is this real / can I trust / scam ------------------------------
            [[
                '/\b(real shop|legit|genuine|trust|scam|fraud|safe to|are you a robot|are you human|is this a bot)\b/',
            ], function ($ctx) {
                return "\u{1F642} This is the official ordering line for the shop — a real business with real people. You can pay on delivery, and you can always ask to speak to the team here.";
            }],

            // --- Talk to a person -----------------------------------------------
            [[
                '/\b(talk|speak|chat|connect).{0,12}\b(human|person|someone|agent|staff|attendant|real)\b/',
                '/\b(call|phone) (the )?(shop|store|you)\b/',
            ], function ($ctx) {
                return "\u{1F642} Sure — I'm letting the shop know so someone can reply here shortly. You can keep typing your order in the meantime.";
            }],
        ];
    }

    /** "a, b and c" */
    private static function humanList(array $items): string
    {
        $items = array_values(array_filter(array_map('trim', $items)));
        if (! $items) return '';
        if (count($items) === 1) return $items[0];
        $last = array_pop($items);
        return implode(', ', $items) . ' and ' . $last;
    }
}
