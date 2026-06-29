<?php
namespace App\Services\Bot\Opportunity;

/**
 * The Business Opportunity taxonomy: which conversation types exist, how we detect
 * each from free text, and the ORDER in which we qualify them.
 *
 * Question order is load-bearing: it walks Understand -> Qualify -> Value before
 * ever touching price. That is what stops the bot negotiating in the first two
 * messages (the original bug). Price is always the last thing we ask, and it always
 * carries the buying-vs-selling disambiguation.
 *
 * Everything here is deterministic config — no AI, no state. Tenants enable a subset
 * of types via settings.opportunity_types (array of type keys).
 */
final class OpportunityTypes
{
    /** Human label per type, for sales cards / Filament. */
    public const LABELS = [
        'distributor' => 'Distributor',
        'dealer'      => 'Dealer',
        'wholesale'   => 'Wholesale',
        'export'      => 'Export',
        'corporate'   => 'Corporate supply',
        'government'  => 'Government',
        'ngo'         => 'NGO',
        'partnership' => 'Partnership',
        'franchise'   => 'Franchise',
        'tender'      => 'Tender',
    ];

    /**
     * Trigger phrases per type. First match (longest-first within a type) wins.
     * Detection only considers types the tenant has enabled.
     */
    public const TRIGGERS = [
        'distributor' => ['become distributor', 'become a distributor', 'distributor price', 'distributorship', 'distributor', 'distribution rights'],
        'dealer'      => ['become dealer', 'become a dealer', 'dealership', 'dealer price', 'dealer'],
        'wholesale'   => ['wholesale price', 'wholesale rate', 'wholesale', 'bulk price', 'bulk order', 'buy in bulk', 'cartons', 'pallet', 'container load', 'container'],
        'export'      => ['export', 'import to', 'ship to', 'overseas', 'outside country', 'cross border', 'cross-border'],
        'corporate'   => ['supply to our', 'supply for our', 'corporate supply', 'office supply', 'hotel supply', 'restaurant supply', 'supermarket supply', 'we are a hotel', 'we run a', 'for our canteen', 'staff canteen'],
        'government'  => ['government supply', 'ministry of', 'county government', 'public sector', 'lpo', 'local purchase order'],
        'ngo'         => ['ngo', 'humanitarian', 'relief supply', 'un agency', 'unicef', 'wfp', 'iom', 'donor funded'],
        'partnership' => ['partnership', 'partner with you', 'collaborate', 'joint venture', 'work together', 'business partner'],
        'franchise'   => ['franchise', 'open a branch', 'use your brand', 'brand license', 'licensing'],
        'tender'      => ['tender', 'rfq', 'request for quotation', 'bid', 'prequalification', 'pre-qualification'],
    ];

    /**
     * The qualification script. Each step targets one profile slot and supplies the
     * question to ask if that slot is still empty. Walked top-to-bottom; the first
     * empty slot is asked next. Price/value steps deliberately sit at the bottom.
     *
     * 'slot'      key inside the profile array
     * 'q'         question text (kept simple, B2B-exec tone, no emojis-as-pressure)
     * 'core'      counts toward the handoff threshold
     * 'any_of'    slot is satisfied if ANY of these sibling slots is filled
     */
    public const SCRIPT = [
        ['slot' => 'business_name', 'core' => true,
         'q'    => "That's great to hear — we're always looking for serious partners. To recommend the right pricing I'd like to understand your business first. What is the name of your shop or company?"],

        ['slot' => 'area', 'core' => true,
         'q'    => "Thank you. Which area or district are you based in?"],

        ['slot' => 'trade_level', 'core' => false,
         'q'    => "Do you sell mostly wholesale or retail?"],

        ['slot' => 'interested_products', 'core' => false,
         'q'    => "Which of our products are you most interested in?"],

        ['slot' => 'monthly_volume', 'core' => true,
         'q'    => "Roughly how many cartons do you sell or move per month? (a rough number is fine)"],

        ['slot' => 'order_frequency', 'core' => false,
         'q'    => "And how often do you usually order — weekly, twice a month, or monthly?"],

        ['slot' => 'current_brand', 'core' => true, 'any_of' => ['current_brand', 'buying_price'],
         'q'    => "Which brand are you currently buying? Knowing this helps us compare products accurately — a photo or invoice is welcome but not required."],

        ['slot' => 'buying_price', 'core' => false,
         'q'    => "What price are you getting right now?"],

        ['slot' => 'decision_maker', 'core' => false,
         'q'    => "And who should our sales manager speak with — are you the owner or the purchasing contact?"],
    ];

    /** Disambiguation we MUST ask the first time a bare price appears (buying vs selling). */
    public const PRICE_BASIS_Q =
        "Just to be clear — is that your buying price from your supplier, or your selling price to your customers?";

    public static function label(string $type): string
    {
        return self::LABELS[$type] ?? ucfirst($type);
    }

    /** Types this tenant supports, intersected with the known taxonomy. Defaults to all. */
    public static function enabledFor(array $tenantTypes): array
    {
        $tenantTypes = array_values(array_filter(array_map('strval', $tenantTypes)));
        if (! $tenantTypes) return array_keys(self::LABELS);
        return array_values(array_intersect(array_keys(self::LABELS), $tenantTypes));
    }
}
