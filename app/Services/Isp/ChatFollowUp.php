<?php

namespace App\Services\Isp;

/**
 * PURE. The AI relationship manager's follow-up when an Internet-provider enquiry goes quiet (DishNet,
 * 20 Nov 2026: "it's still not following up with customers automatically").
 *
 * Until now only a quotation nobody answered was chased (QuoteFollowUp), and it stopped for good the moment
 * the customer wrote anything — so a customer who asked "what's this kit for $650?", got an answer and went
 * silent was never contacted again. This covers every enquiry where WE answered last and the customer went
 * quiet: touch 1 a day after their last message, touch 2 on day 4, then never again for that quiet spell
 * (a new message from them starts a new one). Never after 14 days.
 *
 * The words: in the salesperson's name, about what the customer asked (Starlink / Fiber / their quotation),
 * one easy question — and never an amount, a discount or a date we cannot keep.
 */
final class ChatFollowUp
{
    /** touch => days since the customer's last message */
    public const DAYS = [1 => 1, 2 => 4];

    public const MAX_DAYS = 14;

    /** Vendors, partners, advertisers and job seekers write to the sales line too: they are not chased as buyers. */
    private const NOT_A_CUSTOMER = '/\b(partner(?:ship)?|advertis\w*|billboards?|sponsor\w*|supplier|vendor|we offer|our company (?:offers|provides)|collaborat\w*|vacanc\w*|job (?:offer|application|opportunit\w*)|internship|cv|resume)\b/u';

    /** Signals (Winback::classify) that the customer asked about buying internet. */
    private const SALES_SIGNALS = ['starlink', 'fiber', 'price', 'buy', 'callback', 'visit', 'old_price', 'cheaper'];

    /** PURE. Did they write to us as a vendor, partner, advertiser or job seeker? */
    public static function notACustomer(array $customerTexts): bool
    {
        return (bool) preg_match(self::NOT_A_CUSTOMER, mb_strtolower(implode(' ', $customerTexts)));
    }

    /** PURE. A sales enquiry worth following up: they asked about the service, a price, buying — or only said hello. */
    public static function salesEnquiry(?string $segment, array $signals): bool
    {
        return $segment === 'cold' || array_intersect($signals, self::SALES_SIGNALS) !== [];
    }

    /** PURE. Who goes first when more are due than the daily cap: ready to buy, then a price question, then a hello. */
    public static function rank(?string $segment): int
    {
        return match ($segment) {
            'hot'                             => 0,
            'price_changed', 'wanted_cheaper' => 1,
            'warm'                            => 2,
            default                           => 3,
        };
    }

    /** Which touch is due, or null. $done = touches already sent since their last message. */
    public static function touch(int $daysSinceTheirLast, int $done): ?int
    {
        if ($daysSinceTheirLast > self::MAX_DAYS || $done >= count(self::DAYS)) {
            return null;
        }
        $next = $done + 1;

        return $daysSinceTheirLast >= self::DAYS[$next] ? $next : null;
    }

    /**
     * @param string $segment Winback segment of their messages (hot, warm, cold, price_changed, wanted_cheaper)
     * @param string $service starlink | fiber | both | unsure
     */
    public static function message(int $touch, string $segment, string $service, ?string $customerName, ?string $salesName, string $company, ?string $quoteNo = null): string
    {
        $first = trim((string) preg_replace('/\s.*/u', '', trim((string) $customerName)));
        $name = preg_match('/^\p{L}[\p{L}\'-]{1,30}$/u', $first) ? ' ' . $first : '';
        $me = trim((string) $salesName) !== '' ? trim((string) $salesName) . ' from ' . trim($company) : trim($company);
        $what = match ($service) {
            'starlink' => 'Starlink',
            'fiber'    => 'Fiber internet',
            'both'     => 'Starlink and Fiber',
            default    => 'internet',
        };

        if ($touch >= 2) {
            return "Hello{$name}, it's {$me} again. If you are still looking for {$what}, I am here to help: just reply to this message. "
                . 'If not, no problem, I will not disturb you again. Thank you!';
        }
        if ($quoteNo !== null && $quoteNo !== '') {
            return "Hello{$name}, this is {$me}. I hope you are well! Do you have any other questions about your quotation {$quoteNo}? "
                . 'When you are ready, I can arrange the site survey and the installation for you.';
        }

        return "Hello{$name}, this is {$me}. " . match ($segment) {
            'hot'            => "I am following up on the {$what} you wanted. Shall I prepare your quotation and arrange the site survey?",
            'price_changed', 'wanted_cheaper' => "I am following up on your question about {$what}. Would you like me to send you our current options in a quotation so you can compare?",
            'cold'           => 'Thank you for contacting us! Are you looking for internet for your home or for your business? I can help you choose between Starlink and Fiber.',
            default          => "I am following up on the {$what} you asked about. Do you have any other questions, or would you like me to prepare a quotation for you?",
        };
    }
}
