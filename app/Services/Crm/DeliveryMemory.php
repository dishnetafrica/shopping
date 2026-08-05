<?php

namespace App\Services\Crm;

use App\Models\CustomerProfile;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * v4.39 — remember where we delivered, so we stop asking people who have already told us.
 *
 * A customer who names their area during an order has just given us the most reliable delivery
 * fact we will ever get about them — and until now it was written into conversation state and
 * then destroyed by `$convo->state = []` at the end of checkout. The one moment the address is
 * confirmed was the moment it was thrown away.
 *
 * Fill-blank-only, matching `RelationshipExtractor`: whatever a human typed on the Customers
 * screen outranks anything a bot infers from chat, permanently.
 */
class DeliveryMemory
{
    private const MAX = 200;

    /** Words that answer a question without naming a place. */
    private const NOT_A_PLACE = [
        'yes', 'yeah', 'yep', 'ok', 'okay', 'sure', 'fine', 'no', 'nope', 'same', 'same place',
        'here', 'there', 'thanks', 'thank you', 'please', 'confirm', 'confirmed', 'done', 'good',
        'n/a', 'na', 'none', 'nil', 'asap', 'today', 'tomorrow', 'now',
    ];

    /**
     * Store a destination against a customer, if it looks like one and we have nothing better.
     *
     * Never throws: a bot reply must not fail because a CRM write did.
     */
    public static function remember(Tenant $tenant, string $phone, string $place): bool
    {
        $place = self::clean($place);
        $phone = trim($phone);
        if ($place === '' || $phone === '') {
            return false;
        }

        try {
            $p = CustomerProfile::where('tenant_id', $tenant->id)->where('phone', $phone)->first();
            if (! $p) {
                return false;   // ProcessIncomingMessage creates the row; we only ever fill a blank
            }
            if (trim((string) $p->address) !== '' || trim((string) $p->city) !== '') {
                return false;   // a human, or an earlier delivery, already answered this
            }
            $p->address = $place;
            $p->save();

            return true;
        } catch (\Throwable $e) {
            Log::warning('DeliveryMemory: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * '' when the text is not usable as a destination.
     *
     * A shop name IS a destination — "Zyad Shop" is exactly what a rider needs — so the test is
     * not "is this a town", it is "did they tell us anything at all".
     */
    public static function clean(string $place): string
    {
        $p = trim(preg_replace('/\s+/u', ' ', $place) ?? '');
        if ($p === '' || mb_strlen($p) < 3) {
            return '';
        }
        if (! preg_match('/\p{L}/u', $p)) {
            return '';                                   // digits or emoji alone
        }
        if (in_array(mb_strtolower(rtrim($p, " .!?,")), self::NOT_A_PLACE, true)) {
            return '';
        }

        return mb_substr($p, 0, self::MAX);
    }
}
