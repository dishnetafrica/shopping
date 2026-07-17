<?php declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\Tenant;

/**
 * Ensures every order has a Customer master (CustomerProfile), created/refreshed at the moment the
 * order is placed — the ERP-standard "promote to customer on Sales Order confirmation" step. Orders
 * are only ever created once an order is actually confirmed (a bot/web quotation stays a Quotation),
 * so order creation IS the confirmation moment.
 *
 * The master is keyed by the SAME normalised phone (raw digits) the rest of the platform joins on
 * (CustomerListCapability, the AR receivables, statements, Customer 360), so a newly-created master
 * lines up with its orders everywhere instead of becoming an orphan.
 *
 * Deliberately minimal and NON-destructive: it only fills BLANK fields (never overwrites a curated
 * name/city/country) and, unlike CustomerWriteService::create, it does NOT rename existing orders.
 * Idempotent — safe to call on every save and to replay across the whole order history (backfill).
 */
final class CustomerMasterService
{
    /** Create or refresh the customer master behind an order. Returns null when the order has no phone. */
    public function ensureFromOrder(Tenant $t, Order $order): ?CustomerProfile
    {
        // Canonical phone (strip leading 0, ensure country code) so "0211…"/"211…"/bare-local variants
        // resolve to ONE master instead of duplicates like Asish/Ashish.
        $phone = \App\Support\PhoneNormalizer::canonical((string) $order->customer_phone);
        if ($phone === '') {
            return null;
        }

        $c = CustomerProfile::firstOrNew(['tenant_id' => (int) $t->id, 'phone' => $phone]);
        $isNew = ! $c->exists;

        // Name: fill only when the master has none — never clobber a name a human curated.
        $orderName = trim((string) $order->customer_name);
        if ($orderName !== '' && trim((string) $c->name) === '') {
            $c->name = $orderName;
        }

        // Light location inference from the order's free-text location — only fills blanks.
        [$city, $country] = $this->splitLocation((string) $order->location);
        if ($city !== '' && trim((string) $c->city) === '') {
            $c->city = $city;
        }
        if ($country !== '' && trim((string) $c->country) === '') {
            $c->country = $country;
        }

        if ($isNew || $c->isDirty()) {
            $c->save();
        }

        return $c;
    }

    /**
     * Best-effort "City, Country" split with a small East-Africa gazetteer (Kampala ↔ Juba corridor).
     * Returns ['city', 'country']; empty strings when it can't tell — the caller only fills blanks.
     */
    private function splitLocation(string $loc): array
    {
        $loc = trim($loc);
        if ($loc === '') {
            return ['', ''];
        }

        $l = mb_strtolower($loc);
        $ssTowns = ['south sudan', 'juba', 'wau', 'malakal', 'bor', 'yei', 'aweil', 'bentiu',
            'rumbek', 'torit', 'kuajok', 'renk', 'nimule', 'nasir', 'maridi', 'yambio'];
        $ugTowns = ['uganda', 'kampala', 'entebbe', 'jinja', 'mbarara', 'gulu', 'arua', 'mbale'];

        $country = '';
        foreach ($ssTowns as $w) {
            if (str_contains($l, $w)) { $country = 'South Sudan'; break; }
        }
        if ($country === '') {
            foreach ($ugTowns as $w) {
                if (str_contains($l, $w)) { $country = 'Uganda'; break; }
            }
        }

        $city = '';
        if (str_contains($loc, ',')) {
            $city = trim(explode(',', $loc)[0]);
        } elseif ($country !== '' && str_word_count($loc) <= 2) {
            $city = ucwords($loc);   // a single-token location that is itself the town
        }
        // Don't let the city just echo the country word.
        if ($city !== '' && mb_strtolower($city) === mb_strtolower($country)) {
            $city = '';
        }

        return [$city, $country];
    }
}
