<?php

namespace App\Services\Storefront;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Shared\CustomerInsight;
use App\Models\CustomerProfile;
use App\Models\Tenant;
use App\Models\User;

/**
 * Segment-aware storefront foundation (Phase 1 of the Customer-Type-Driven Storefront).
 *
 * Resolves WHO is shopping into a lightweight "buyer context" the storefront renders around:
 *   - mode      : retail | wholesale | institutional   (derived from CustomerProfile.segment)
 *   - segment   : the raw CRM segment string (for display/analytics)
 *   - credit    : a read-only credit snapshot (limit / available / exposure / canSell)
 *
 * This is READ-ONLY and ADDITIVE. It writes nothing and touches no frozen service. The credit
 * snapshot reuses the SAME computation the panel's Customer 360 shows (CustomerInsight), so the
 * storefront and the back office can never disagree about a customer's credit. The credit read
 * fails SOFT: on any error the snapshot is simply null and the storefront degrades to a plain
 * (cash) experience rather than ever blocking a customer because of an internal hiccup.
 */
final class BuyerContext
{
    /** Segments that shop the wholesale (carton / MOQ) face. */
    private const WHOLESALE = ['wholesale', 'distributor'];

    /** Segments that shop the institutional (quote / contract) face. */
    private const INSTITUTIONAL = ['institutional'];

    /** Map a raw CRM segment string to a storefront mode. Unknown / retail / walk-in → retail. */
    public static function modeForSegment(?string $segment): string
    {
        $s = strtolower(trim((string) $segment));
        if (in_array($s, self::WHOLESALE, true)) {
            return 'wholesale';
        }
        if (in_array($s, self::INSTITUTIONAL, true)) {
            return 'institutional';
        }

        return 'retail';
    }

    /**
     * Resolve the buyer context for a tenant + phone. Anonymous / unknown phones get the retail
     * default with no credit. Tenant scoping relies on the caller having set the tenant context
     * (StorefrontController/CustomerAuthController both do), and is re-pinned explicitly here.
     *
     * @return array{mode:string,segment:?string,credit:?array<string,mixed>}
     */
    public function for(Tenant $tenant, ?string $phone): array
    {
        $default = ['mode' => 'retail', 'segment' => null, 'credit' => null];

        $digits = preg_replace('/[^0-9]/', '', (string) $phone);
        if ($digits === '') {
            return $default;
        }

        $customer = CustomerProfile::where('tenant_id', $tenant->id)
            ->where('phone', $phone)
            ->first();
        if (! $customer) {
            return $default;
        }

        $mode = self::modeForSegment($customer->segment);

        return [
            'mode'    => $mode,
            'segment' => $customer->segment ? (string) $customer->segment : null,
            'credit'  => $this->creditSnapshot($tenant, $customer),
        ];
    }

    /**
     * A read-only credit snapshot from CustomerInsight, computed under the tenant owner's identity
     * (the same lens the 360 uses). Returns null when there is no credit limit set, no owner user to
     * build a context from, or anything throws — so credit never becomes a reason checkout breaks.
     *
     * @return array{limit:?float,available:?float,exposure:float,canSell:bool,currency:string}|null
     */
    private function creditSnapshot(Tenant $tenant, CustomerProfile $customer): ?array
    {
        try {
            // Only customers with a credit limit have a credit face; everyone else is cash.
            if ($customer->credit_limit === null) {
                return null;
            }

            $owner = User::where('tenant_id', $tenant->id)
                ->orderByRaw("case when lower(coalesce(role,'')) = 'owner' then 0 else 1 end")
                ->orderBy('id')
                ->first();
            if (! $owner) {
                return null;
            }

            $ins = app(CustomerInsight::class)->for(CapabilityContext::fromUser($owner), $customer);

            return [
                'limit'     => $ins['credit'] ?? null,
                'available' => $ins['creditAvail'] ?? null,
                'exposure'  => (float) ($ins['exposure'] ?? 0),
                'canSell'   => (bool) ($ins['canSell'] ?? true),
                'currency'  => (string) ($ins['currency'] ?? 'UGX'),
            ];
        } catch (\Throwable $e) {
            return null; // fail soft — never block a customer on an internal error
        }
    }
}
