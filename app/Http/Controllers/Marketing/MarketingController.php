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
    public function home()
    {
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
