<?php
namespace App\Services\Bot\Merchant;

use App\Models\Tenant;
use App\Models\User;

/**
 * Authorization for Merchant Mode. Only owners/managers of THIS tenant (plus the
 * tenant's owner_alert_phone) may use the merchant lane. Phone matching is pure and
 * unit-tested; the DB lookup is a thin wrapper (deploy-tested).
 */
class MerchantDirectory
{
    /** Digits-only, last-12 normalization so +256 7…, 0256 7…, spaces all compare equal. */
    public static function normalize(string $phone): string
    {
        $d = preg_replace('/\D/', '', $phone);
        return strlen($d) > 12 ? substr($d, -12) : $d;
    }

    /** Pure: is $phone in the authorized set? (both sides normalized) */
    public static function matches(string $phone, array $authorized): bool
    {
        $p = self::normalize($phone);
        if ($p === '') return false;
        foreach ($authorized as $a) {
            if (self::normalize((string) $a) === $p) return true;
        }
        return false;
    }

    /** Framework: the authorized phone set for a tenant (owner/manager users + owner_alert_phone + setting). */
    public static function authorizedPhones(Tenant $tenant): array
    {
        $phones = User::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('role', ['owner', 'manager'])
            ->pluck('phone')->filter()->all();

        foreach ((array) $tenant->setting('owner_alert_phone', []) as $p) $phones[] = $p;
        if (is_string($tenant->setting('owner_alert_phone'))) $phones[] = $tenant->setting('owner_alert_phone');
        foreach ((array) $tenant->setting('merchant_admins', []) as $p) $phones[] = $p;

        return array_values(array_unique(array_filter($phones)));
    }

    public static function isAuthorized(Tenant $tenant, string $phone): bool
    {
        return self::matches($phone, self::authorizedPhones($tenant));
    }
}
