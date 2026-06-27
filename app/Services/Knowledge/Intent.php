<?php
namespace App\Services\Knowledge;

/** Routing aid today; first-class analytics data later. Capabilities declare which intents they own. */
final class Intent
{
    public const PRICE            = 'price';
    public const MENU             = 'menu';
    public const AVAILABILITY     = 'availability';
    public const SPECIAL          = 'special';
    public const SCHEDULE         = 'schedule';       // hours / holiday / closure
    public const FACILITY         = 'facility';       // parking, seating, wifi
    public const POLICY           = 'policy';         // delivery, payment, min-order
    public const REPEAT_PREVIOUS  = 'repeat_previous';// "same as yesterday" / "repeat" / "no changes"
    public const NOTE             = 'note';           // free note
    public const UNKNOWN          = 'unknown';        // logged, not routed (Phase 3 AI may classify)
}

/** The "why" behind an owner change — first-class so automation/analytics can use it later. */
final class Reason
{
    public const HOLIDAY        = 'holiday';
    public const PROMOTION      = 'promotion';
    public const HIGH_DEMAND    = 'high_demand';
    public const SUPPLIER_ISSUE = 'supplier_issue';
    public const NONE           = 'none';
}
