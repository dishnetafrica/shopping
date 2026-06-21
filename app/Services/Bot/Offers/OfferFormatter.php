<?php

namespace App\Services\Bot\Offers;

/**
 * Status Intelligence — WhatsApp text formatting. Pure logic, no framework deps.
 * Money is formatted here with number_format so it is testable; callers pass the integer
 * price and a currency code.
 */
class OfferFormatter
{
    private const LABEL = [
        OfferTypeClassifier::DAILY_THALI => "today's thali",
        OfferTypeClassifier::FRESH       => 'fresh today',
        OfferTypeClassifier::SPECIAL     => 'special offer',
        OfferTypeClassifier::WEEKEND     => 'weekend special',
        OfferTypeClassifier::FESTIVAL    => 'festival special',
    ];

    public static function money(?int $price, string $cur): string
    {
        if ($price === null || $price <= 0) return '';
        return $cur . ' ' . number_format($price);
    }

    /** One offer as a customer-facing card. */
    public static function card(array $o, string $defaultCur): string
    {
        $cur   = (string) ($o['currency'] ?: $defaultCur);
        $title = (string) ($o['title'] ?? 'Today’s offer');
        $price = $o['price'] !== null ? (int) $o['price'] : null;

        $head = '*' . $title . '*';
        if ($price) $head .= ' — ' . self::money($price, $cur);

        $lines = [$head];
        $items = array_values(array_filter((array) ($o['items'] ?? [])));
        if ($items) $lines[] = implode(', ', array_slice($items, 0, 12));
        if (! empty($o['description'])) $lines[] = (string) $o['description'];

        return implode("\n", $lines);
    }

    /** Customer reply: the day's active offers, best first, with a gentle add prompt. */
    public static function customerReply(array $offers, string $defaultCur): string
    {
        if (! $offers) return '';
        $top  = $offers[0];
        $type = (string) ($top['type'] ?? OfferTypeClassifier::SPECIAL);
        $label = self::LABEL[$type] ?? 'today';

        $intro = match ($type) {
            OfferTypeClassifier::DAILY_THALI => "🍽️ Here's *" . $label . "*:",
            OfferTypeClassifier::FRESH       => "🔥 *Fresh today:*",
            OfferTypeClassifier::FESTIVAL    => "🎉 *Festival special:*",
            OfferTypeClassifier::WEEKEND     => "🌟 *Weekend special:*",
            default                          => "✨ *Today's special:*",
        };

        $parts = [$intro];
        foreach (array_slice($offers, 0, 3) as $o) {
            $parts[] = self::card($o, $defaultCur);
        }
        $name = (string) ($top['title'] ?? '');
        $parts[] = $name !== ''
            ? "Reply *" . $name . "* to add it, or *menu* to see more."
            : "Reply with the name to add it, or *menu* to see more.";

        return implode("\n\n", $parts);
    }

    /** Owner confirmation after a poster/status is captured. */
    public static function ownerConfirm(array $o, string $defaultCur): string
    {
        $cur   = (string) ($o['currency'] ?: $defaultCur);
        $type  = (string) ($o['type'] ?? OfferTypeClassifier::SPECIAL);
        $label = self::LABEL[$type] ?? 'offer';
        $title = (string) ($o['title'] ?? 'Offer');
        $price = $o['price'] !== null ? (int) $o['price'] : null;

        $head = "✅ Saved as *" . $label . "*: *" . $title . "*";
        if ($price) $head .= ' — ' . self::money($price, $cur);

        $lines = [$head];
        $items = array_values(array_filter((array) ($o['items'] ?? [])));
        if ($items) $lines[] = 'Items: ' . implode(', ', array_slice($items, 0, 12));
        $lines[] = "Customers asking for the menu/thali/special will now get this. Send a new poster anytime to replace it.";

        return implode("\n", $lines);
    }
}
