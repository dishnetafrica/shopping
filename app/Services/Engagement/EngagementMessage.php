<?php declare(strict_types=1);

namespace App\Services\Engagement;

/**
 * Phase 2b — the outbound re-engagement message. PURE, template-structured, NOT AI-generated.
 *
 * One fixed, human-approved structure per campaign type, personalised only with the customer's first
 * name, the shop name, and (when we have one) the suggested product. No free-form AI copy on the send
 * path — the AI's job was choosing WHO and WHAT; the words are fixed and reviewable here.
 *
 * Every message carries an explicit opt-out line, because these go to opted-in customers on an
 * unofficial (Evolution) number and honouring STOP is non-negotiable.
 */
final class EngagementMessage
{
    /** @param array<string,mixed> $c  name, shop, product(nullable), campaign_type */
    public static function compose(array $c): string
    {
        $name = self::firstName((string) ($c['name'] ?? ''));
        $shop = trim((string) ($c['shop'] ?? '')) ?: 'us';
        $prod = trim((string) ($c['product'] ?? ''));
        $type = (string) ($c['campaign_type'] ?? CampaignType::NEW_STOCK);

        $hi   = $name !== '' ? "Hi {$name} 👋" : 'Hello 👋';
        $body = self::body($type, $shop, $prod);

        return $hi . ' ' . $body . "\n\nReply to order or ask a question. Reply STOP to unsubscribe.";
    }

    private static function body(string $type, string $shop, string $prod): string
    {
        $withProd = $prod !== '';

        return match ($type) {
            CampaignType::DORMANT_WINBACK => $withProd
                ? "we've missed you at {$shop}. {$prod} is back in stock — want us to set some aside for you?"
                : "we've missed you at {$shop}! Can we help you restock this week?",
            CampaignType::REPLENISHMENT => $withProd
                ? "time for a refill? {$prod} — we can reserve your usual quantity at {$shop}."
                : "it's about time for your usual order from {$shop}. Want us to prepare it?",
            CampaignType::VIP_OFFER => $withProd
                ? "a priority offer for you at {$shop}: {$prod}. Reply and we'll sort out the best price for you."
                : "as one of our best customers, we've got priority offers at {$shop} this week — interested?",
            CampaignType::RECOMMENDATION => $withProd
                ? "based on what you usually buy, you might like {$prod} from {$shop}. Want to add it to your next order?"
                : "we've got a few things at {$shop} we think you'll like — want a quick list?",
            default => $withProd
                ? "fresh stock just arrived at {$shop}, including {$prod}. Reply to see prices."
                : "new stock just arrived at {$shop}. Reply to see what's available.",
        };
    }

    private static function firstName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || preg_match('/^\+?\d[\d\s\-]*$/', $name)) {
            return '';                                   // a phone number is not a name
        }
        $first = preg_split('/\s+/', $name)[0] ?? $name;

        return mb_substr(ucfirst(mb_strtolower($first)), 0, 24);
    }
}
