<?php

namespace App\Services\Sales\Brain;

/**
 * DishNet management's decisions of 16 Nov 2026 (given by the owner in the build conversation) on the
 * open questions of the imported drafts — the UISP template terms (TemplateTerms) and the n8n sales-bot
 * knowledge (DishNetBotImport). PURE: the exact wording each decision changes, and the answer recorded
 * against each open question.
 *
 * Safety: every edit names the exact text it replaces (an "anchor"); if a draft no longer contains it,
 * or an open question matches no decision (or more than one), that draft is left unapproved. Nothing is
 * guessed. "Questions customers ask" (faq/customer-questions) is NOT part of this — it stays a draft
 * until management writes the answers.
 */
final class DishNetDecisions
{
    public const DECIDED = '16 Nov 2026, by the owner';

    private const UPTIME = 'We target 99.9% uptime; it is not a guarantee. 24/7 support: +211 921 443 002.';
    private const FAIR_USE = 'Personal/business use. No illegal content. Reselling or sharing the connection for payment only through DishNet\'s hotspot or reseller programme.';
    private const TAX = 'All prices USD and include applicable taxes.';
    private const RELOCATION = 'Moving the service to a new address is quoted by Sales case by case.';

    /** Entries approved and published by the go-live (topic/key). */
    public const ENTRIES = [
        'terms/starlink', 'terms/fiber', 'terms/general',
        'company/dishnet', 'fiber/service', 'starlink/service', 'packages/which-package', 'coverage/fiber', 'qualification/questions',
        'faq/general', 'objections/common', 'payment/methods', 'followup/order-details', 'scripts/wording', 'technical/networking',
    ];

    /** Terms: label => new text (null removes the term). */
    public static function termsChanges(string $key): array
    {
        return match ($key) {
            'starlink' => [
                'Currency & Pricing' => self::TAX . ' Subject to change with 30 days notice.',
                'Installation' => 'Standard installation is free in Juba; roof or pole mounting is extra. Outside Juba the installation is free and the customer provides the technician\'s travel and accommodation. Clear sky needed. Typically 2–3 days after payment in Juba, about a week outside Juba.',
                'Equipment' => 'The Starlink kit is sold to the customer and belongs to the customer.',
                'Uptime' => self::UPTIME,
                'Fair Use' => self::FAIR_USE,
                'Refunds' => 'Install fees non-refundable once begun.',          // the kit is sold: no equipment deposit to return
                'Relocation' => self::RELOCATION,
            ],
            'fiber' => [
                'Currency & Pricing' => self::TAX,
                'Uptime' => self::UPTIME,
                'Fair Use' => self::FAIR_USE,
                'Relocation' => self::RELOCATION,
            ],
            'general' => [
                'Currency & Pricing' => self::TAX . ' Subject to change with 30 days notice.',
                'Equipment' => 'Starlink kits are sold to the customer. Routers and ONTs supplied with fibre remain DishNet property; return them in working condition within 7 days of termination.',
                'Uptime' => self::UPTIME,
                'Fair Use' => self::FAIR_USE,
            ],
            default => [],
        };
    }

    /** Knowledge text: [exact text in the draft => replacement]. */
    public static function bodyChanges(string $entry): array
    {
        return match ($entry) {
            'fiber/service' => [
                'Installation is one-time, the same for all three plans, and includes the Wi-Fi router.'
                    => 'Installation is one-time, the same for all three plans, and includes the Wi-Fi router; the router and ONT remain DishNet property and are returned in working condition within 7 days of termination.',
            ],
            'starlink/service' => [
                'Hardware: suggest the Starlink Mini first' => 'The Starlink kit is sold to the customer and is theirs. Hardware: suggest the Starlink Mini first',
                'Priority plans only for 50+ active users' => 'Priority plans (price confirmed by Sales) only for 50+ active users',
                'Installation: free in Juba. Outside Juba: free service installation; the customer provides the technician\'s round-trip travel and accommodation. On-site work takes 1–2 hours.'
                    => 'Installation: standard installation is free in Juba; roof or pole mounting is extra. Outside Juba: free service installation; the customer provides the technician\'s round-trip travel and accommodation. Typically 2–3 days after payment in Juba, about a week outside Juba. On-site work takes 1–2 hours.',
            ],
            'packages/which-package' => [
                'First find out: Juba or outside Juba?' => "All prices are in USD and include applicable taxes.\nFirst find out: Juba or outside Juba?",
                'In Juba: offer fibre first — cheaper, faster, more stable, unlimited. Fiber Standard 40 Mbps suits most homes and setups; Premium 60 Mbps for 30+ users. After one or two attempts, if they prefer Starlink, move to Starlink Mini + Residential.'
                    => 'In Juba: while stock lasts, recommend the Starlink Mini + Residential plan first. Offer fibre if the customer prefers it or asks — Fiber Standard 40 Mbps suits most homes and setups; Premium 60 Mbps for 30+ users.',
            ],
            'payment/methods' => [
                'Quote SSP only if the customer asks to pay in SSP.' => 'Customers may pay in SSP; the exchange rate is confirmed by Accounts at the time of payment — never state a rate in chat.',
            ],
            default => [],
        };
    }

    /** [words that identify the open question (all must appear, any case), the answer recorded]. */
    private const ANSWERS = [
        [['leased'], 'Decided: the Starlink kit is SOLD and belongs to the customer; the kit-transfer clause stays. Fibre routers/ONTs remain DishNet property.'],
        [['99.5%'], 'Decided: "We target 99.9% uptime; it is not a guarantee."'],
        [['excise'], 'Decided: "All prices are in USD and include applicable taxes" — no percentage printed.'],
        [['support number'], 'Confirmed: 24/7 support +211 921 443 002; Sales +211 923 400 000; Accounts +211 921 443 009.'],
        [['reselling'], 'Decided: personal/business use; no illegal content; reselling or sharing for payment only through DishNet\'s hotspot or reseller programme.'],
        [['installation time'], 'Decided: typically 2–3 days after payment in Juba, about a week outside Juba (fibre: same day once stock and coverage are confirmed).'],
        [['installation cost'], 'Decided: standard installation free in Juba; roof or pole mounting extra; outside Juba the customer covers technician travel and accommodation.'],
        [['priority plans have no price'], 'Decided: Sales quotes Priority plans until prices are supplied.'],
        [['juba recommendation'], 'Decided: Starlink first in Juba while stock lasts; fibre if the customer prefers it.'],
        [['moving the service'], 'Decided: relocation is quoted by Sales case by case (no fixed fee).'],
        [['exchange rate'], 'Decided: never state an exchange rate; Accounts confirms it at payment.'],
        [['coverage.html'], 'Confirmed: the 33 listed places are covered by fibre.'],
        [['handover promise'], 'Decided: no time promise — "our Sales team will contact you to confirm the installation".'],
        [['dn_products'], 'Decided: Sales quotes hEX, L009 and the Ruijie range until prices are supplied.'],
    ];

    /** The recorded answer for an open question, or null if no decision — or more than one — matches. */
    public static function answerFor(string $question): ?string
    {
        $q = mb_strtolower($question);
        $hits = array_values(array_filter(self::ANSWERS, fn ($a) => ! array_filter($a[0], fn ($w) => ! str_contains($q, mb_strtolower($w)))));

        return count($hits) === 1 ? $hits[0][1] . ' (' . self::DECIDED . ')' : null;
    }

    /** Apply the terms changes. @return array{ok:bool, data?:array, error?:string} */
    public static function applyTerms(string $key, array $data): array
    {
        $changes = self::termsChanges($key);
        $labels = array_column((array) ($data['items'] ?? []), 'label');
        foreach (array_keys($changes) as $label) {
            if (! in_array($label, $labels, true)) {
                return ['ok' => false, 'error' => "term \"{$label}\" not found"];
            }
        }
        $items = [];
        foreach ((array) $data['items'] as $it) {
            if (array_key_exists($it['label'], $changes)) {
                if ($changes[$it['label']] === null) {
                    continue;
                }
                $it['text'] = $changes[$it['label']];
            }
            $items[] = $it;
        }

        return ['ok' => true, 'data' => ['title' => (string) ($data['title'] ?? ''), 'items' => $items]];
    }

    /** Apply the knowledge-text changes. @return array{ok:bool, body?:string, error?:string} */
    public static function applyBody(string $entry, string $body): array
    {
        foreach (self::bodyChanges($entry) as $old => $new) {
            if (substr_count($body, $old) !== 1) {
                return ['ok' => false, 'error' => 'expected text not found: "' . mb_substr($old, 0, 60) . '…"'];
            }
            $body = str_replace($old, $new, $body);
        }

        return ['ok' => true, 'body' => $body];
    }
}
