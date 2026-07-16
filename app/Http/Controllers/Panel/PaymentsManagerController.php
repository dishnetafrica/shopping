<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\Money\Fx;
use App\Services\Money\SalesBaseCurrency;
use Illuminate\Http\Request;

/**
 * Serves the self-contained UCRM-style Payments management screen (resources/panel/payments-manager.html).
 * The page talks to the existing payment CRUD endpoints under /papi (index/store/show/update/apply/delete);
 * this controller only injects the CSRF token, base currency and current FX rates so the form can default
 * the rate and label amounts. No payment logic lives here.
 */
final class PaymentsManagerController extends Controller
{
    public function page(Request $r)
    {
        $t = $r->user()?->tenant;
        $path = resource_path('panel/payments-manager.html');
        $html = is_file($path) ? (string) file_get_contents($path) : '<h1>Payments manager unavailable</h1>';

        $base   = $t ? SalesBaseCurrency::baseCurrency($t) : 'USD';
        $native = $t ? SalesBaseCurrency::nativeCurrency($t) : 'UGX';
        $fx     = $t ? Fx::forTenant($t) : null;

        $cfg = [
            'base'    => $base,
            'native'  => $native,
            'csrf'    => csrf_token(),
            'api'     => url('/papi'),
            'business'=> $t?->name ?? 'Family Shoppers',
            'rates'   => [
                'USD' => 1,
                'UGX' => $fx ? $fx->ratePerUsd('UGX') : 3655,
                'SSP' => $fx ? $fx->ratePerUsd('SSP') : 7000,
            ],
        ];
        $html = str_replace('window.PM_CONFIG || null', json_encode($cfg, JSON_UNESCAPED_SLASHES), $html);

        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
