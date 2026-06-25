<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;

/**
 * Serves the public CloudBSS marketing landing page at "/".
 * The HTML lives in resources/marketing/index.html (raw, not Blade — its CSS/JS
 * braces must not be parsed). Contact points (WhatsApp / phone / email) are
 * injected from config/marketing.php so the operator can swap the live number
 * without touching the file.
 */
class MarketingController extends Controller
{
    public function home(\Illuminate\Http\Request $request)
    {
        // If this request arrived on a shop's own domain, serve that shop at the
        // root instead of the marketing page. The slug URL (mycloudbss.com/{slug})
        // keeps working independently as the ordering storefront.
        $host = strtolower((string) $request->getHost());
        $host = preg_replace('/^www\./', '', $host);
        $tenant = \App\Models\Tenant::whereRaw('lower(custom_domain) = ?', [$host])->first();
        if ($tenant) {
            // A shop can ship a bespoke landing page at public/landing/{slug}.html.
            // If present, it is served at the domain ROOT (e.g. thegreatindiandhabaa.com/),
            // while the ordering storefront stays at /{slug}. Slugs are validated on
            // creation, but we still constrain the charset to be safe against traversal.
            $slug = (string) $tenant->slug;
            if (preg_match('/^[A-Za-z0-9_-]+$/', $slug)) {
                $landing = public_path('landing/' . $slug . '.html');
                if (is_file($landing)) {
                    return response()->file($landing, ['Content-Type' => 'text/html; charset=UTF-8']);
                }
            }
            // No bespoke landing page: fall back to landing() — manufacturers/brand-site
            // shops get their brand site, everyone else gets the shop storefront.
            return app(\App\Http\Controllers\Storefront\StorefrontController::class)->landing($tenant->slug);
        }

        $path = resource_path('marketing/index.html');
        if (! is_file($path)) {
            abort(500, 'Marketing page asset missing.');
        }

        $html = file_get_contents($path);

        $wa    = preg_replace('/[^0-9]/', '', (string) config('marketing.whatsapp', '256700000000'));
        $tel   = trim((string) config('marketing.phone', '+256700000000'));
        $email = trim((string) config('marketing.email', 'hello@mycloudbss.com'));

        // Targeted swaps only — leave brand text / og:url ("mycloudbss.com") intact.
        if ($wa !== '' && $wa !== '256700000000') {
            $html = str_replace('wa.me/256700000000', 'wa.me/' . $wa, $html);
        }
        if ($tel !== '' && $tel !== '+256700000000') {
            $html = str_replace('tel:+256700000000', 'tel:' . $tel, $html);
        }
        if ($email !== '' && $email !== 'hello@mycloudbss.com') {
            $html = str_replace('hello@mycloudbss.com', $email, $html);
        }

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=300');
    }
}
