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
    public static function decimals(): int
    {
        return \App\Services\Pricing::decimalsForCurrency(self::code());
    }

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

    /**
     * The currencies the custom HTML panel can convert between (UGX base + SSP/USD
     * display, the Uganda/South-Sudan model). Anything else (RWF, KES, …) is a
     * "plain" single currency: shown as-is, no conversion, no display switcher.
     */
    private const CONVERTIBLE = ['AUTO', 'UGX', 'SSP', 'USD'];

    /** Client-side currency config for the HTML panel pages. */
    public static function payload(Tenant $tenant): array
    {
        $raw = strtoupper(trim((string) $tenant->setting('currency', 'UGX')));
        if ($raw === '' || $raw === 'AUTO') $raw = 'UGX';
        $plain = ! in_array($raw, self::CONVERTIBLE, true);
        return [
            'code'  => $raw,
            'dec'   => \App\Services\Pricing::decimalsForCurrency($raw),
            'plain' => $plain,
        ];
    }

    /** Inject window.SHOP_CUR into a panel HTML string, before </head>. */
    public static function inject(string $html, Tenant $tenant): string
    {
        $json = json_encode(self::payload($tenant), JSON_UNESCAPED_SLASHES);
        return preg_replace('#</head>#', '<script>window.SHOP_CUR=' . $json . ';</script></head>', $html, 1) ?: $html;
    }
}
