<?php
namespace App\Support;

use App\Models\Tenant;

/**
 * The currency code for the panel that's currently being rendered.
 *
 * The seller panel is scoped to the logged-in user's tenant (SetTenantFromUser),
 * so money columns should show THAT tenant's currency (e.g. USD for Spicey Herbs)
 * rather than the hardcoded 'UGX'. Falls back to UGX so existing grocery tenants
 * are unaffected.
 */
class PanelCurrency
{
    public static function code(): string
    {
        try {
            $tid = app(TenantContext::class)->id() ?? auth()->user()?->tenant_id;
            if (! $tid) return 'UGX';
            $cur = (string) (Tenant::find($tid)?->setting('currency', 'UGX') ?? 'UGX');
            return $cur !== '' ? $cur : 'UGX';
        } catch (\Throwable $e) {
            return 'UGX';
        }
    }
}
