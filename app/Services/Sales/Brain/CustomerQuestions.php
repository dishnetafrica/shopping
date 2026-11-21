<?php

namespace App\Services\Sales\Brain;

/**
 * Questions DishNet's customers are likely to ask on WhatsApp (South Sudan, Starlink and Fiber),
 * checked against what DishNet has supplied so far (the n8n sales-bot text and the UISP quote
 * template). Written from the customer's side; NO answers are written here.
 *
 *   covered — the supplied material answers it (where = the Sales Brain entry)
 *   partly  — something is said, but the customer's real question is left open
 *   missing — nothing supplied answers it
 *
 * The "partly" and "missing" ones become ONE Sales Brain draft (faq/customer-questions) with
 * "[TO ANSWER]" after each question. A draft containing "[TO ANSWER]" cannot be approved
 * (SalesBrain::approve), so nothing reaches the bot until management has written every answer or
 * deleted the questions it does not want answered in chat.
 */
final class CustomerQuestions
{
    public const SOURCE = 'Customer questions anticipated for DishNet South Sudan (16 Nov 2026) — answers to be written by management';

    /** [theme, question, status, where-covered / what is open] */
    public static function all(): array
    {
        return [
            // Price and what is paid
            ['Price and payment', 'How much is Starlink — the kit and the monthly plan?', 'covered', 'catalogue + Starlink service'],
            ['Price and payment', 'How much is fibre per month, and the installation?', 'covered', 'catalogue + Fiber service'],
            ['Price and payment', 'What exactly do I pay on the first day — kit or installation, plus the first month in advance?', 'partly', 'prices exist, but not whether the first month is paid up front'],
            ['Price and payment', 'Is there a cheaper, weekly or daily plan?', 'missing', ''],
            ['Price and payment', 'Can I pay in instalments or on credit?', 'missing', ''],
            ['Price and payment', 'Can I pay with mobile money (e.g. m-Gurush, MoMo)?', 'missing', 'payment methods list cash, bank transfer and card only'],
            ['Price and payment', 'Can I pay in SSP, and at what rate?', 'partly', 'SSP accepted; who keeps the rate current is an open question'],
            ['Price and payment', 'Is tax included in the price?', 'partly', 'open question on the packages draft (26% excise)'],
            ['Price and payment', 'Is there a discount for NGOs, embassies, several sites or paying a year in advance?', 'covered', 'Common questions (same price for all; annual prepay → Sales)'],
            ['Price and payment', 'Our company/NGO pays by purchase order (LPO) and invoice — is that possible, and what do you need from us?', 'missing', ''],
            ['Price and payment', 'What happens if I pay late? Is there a penalty or a reconnection fee?', 'partly', 'only in the UISP terms draft (5%, 7 days, $25), not yet approved'],
            ['Price and payment', 'Can I pause my subscription while I travel or during school holidays?', 'missing', ''],

            // Speed, usage, power, weather
            ['Speed and use', 'What speed will I really get on Starlink here?', 'partly', '"up to 220 Mbps, varies by location" only'],
            ['Speed and use', 'Is it really unlimited? What is the fair-use limit?', 'partly', 'fair use mentioned, the limit is not'],
            ['Speed and use', 'How many people or devices can use it at the same time?', 'covered', 'Which package suits whom (about 50 active users on Residential)'],
            ['Speed and use', 'Can we do video calls, streaming and online classes?', 'covered', 'latency in Fiber/Starlink service'],
            ['Speed and use', 'How much power does the Mini or the Standard use? Can it run on solar, an inverter or a generator?', 'missing', 'only the Anker power bank for the Mini is mentioned'],
            ['Speed and use', 'Does it work in heavy rain and storms?', 'missing', ''],
            ['Speed and use', 'Does fibre still work when the power is off at my house?', 'missing', ''],

            // Coverage and installation
            ['Coverage and installation', 'Do you cover my area?', 'covered', 'Juba fibre coverage (33 places); Starlink: clear sky'],
            ['Coverage and installation', 'Do you install in Wau, Malakal, Bor, Rumbek, Yei, Torit, Aweil…? What does it cost me there?', 'covered', 'Starlink service (free installation; customer pays technician travel and accommodation)'],
            ['Coverage and installation', 'How much is the technician\'s travel to my town, roughly?', 'missing', 'only "customer provides ticket and accommodation"'],
            ['Coverage and installation', 'How soon can you install after I pay?', 'partly', 'open question (2–3 days vs 2–5 days)'],
            ['Coverage and installation', 'Do I need a pole or a roof mount, and how much is it?', 'partly', 'open question (free installation vs roof/pole extra)'],
            ['Coverage and installation', 'What must I prepare before the technician comes?', 'partly', 'clear sky and ONT location only'],
            ['Coverage and installation', 'Can I install the kit myself?', 'missing', ''],
            ['Coverage and installation', 'Can I see it working before I pay — a demo at your office?', 'missing', ''],

            // Equipment
            ['Equipment', 'Do I own the kit, or is it rented from DishNet?', 'partly', 'open question (sold vs leased)'],
            ['Equipment', 'What warranty do I get on the kit and the router, and for how long?', 'missing', ''],
            ['Equipment', 'What if the dish is damaged, struck by lightning or stolen?', 'missing', ''],
            ['Equipment', 'I bought a Starlink kit abroad — can you activate it or give me service on it?', 'missing', ''],
            ['Equipment', 'Is a Wi-Fi router included with Starlink, and does the Wi-Fi reach my whole house or compound?', 'partly', 'fibre includes a router; Starlink and range are not stated'],
            ['Equipment', 'Can I take the kit with me when I travel or move town?', 'covered', 'Residential address can be updated'],

            // Contract, documents, cancelling
            ['Contract and sign-up', 'Is there a contract or a minimum period?', 'partly', 'UISP terms draft: 6 months Starlink, 3 months fibre — not yet approved'],
            ['Contract and sign-up', 'What documents do I need to sign up (ID, passport, company papers)?', 'partly', '"Sales handles it at delivery" only'],
            ['Contract and sign-up', 'How do I cancel, and do I get anything back?', 'covered', 'Common questions → Accounts'],
            ['Contract and sign-up', 'Can I upgrade or downgrade my plan later?', 'partly', 'mid-cycle change → Accounts; whether it is possible is not stated'],

            // Support
            ['Support', 'If the internet stops, how fast will someone help me, and is there a charge for a technician visit?', 'partly', 'support hours known; response time and visit fee are not'],
            ['Support', 'Do you have a technician in my town, or does one come from Juba?', 'covered', 'company (technicians travel from Juba)'],

            // Business use
            ['Business use', 'Can I share the connection with neighbours or sell Wi-Fi vouchers?', 'partly', 'hotspot programme says yes, but the UISP terms say "no reselling" — open question'],
            ['Business use', 'Can you set up Wi-Fi for my whole hotel, school or compound?', 'covered', 'Routers and networking hardware (Ruijie, quoted by Sales)'],
            ['Business use', 'Can I have a backup line so we never go offline (fibre + Starlink)?', 'covered', 'Routers and networking hardware (L009 failover)'],

            // Trust
            ['Trust', 'Are you an official Starlink reseller? Why not buy directly from Starlink?', 'covered', 'company + Objections'],
            ['Trust', 'Can I speak to a customer who already uses your service?', 'missing', ''],
        ];
    }

    /** The draft for management: every partly/missing question followed by "[TO ANSWER]", by theme. */
    public static function draftBody(): string
    {
        $out = Knowledge::PLACEHOLDER . " — delete this line. Write each answer in place of its placeholder. Delete any question that should not be answered in chat; the team will handle it. This draft cannot be approved while any placeholder remains.]\n";
        $theme = '';
        foreach (self::all() as [$t, $q, $status, $note]) {
            if ($status === 'covered') {
                continue;
            }
            if ($t !== $theme) {
                $out .= "\n" . mb_strtoupper($t) . "\n";
                $theme = $t;
            }
            $out .= "Q: {$q}\nA: " . Knowledge::PLACEHOLDER . ($note !== '' ? " — so far: {$note}" : '') . "]\n";
        }

        return rtrim($out);
    }

    public static function counts(): array
    {
        return array_count_values(array_column(self::all(), 2));
    }
}
