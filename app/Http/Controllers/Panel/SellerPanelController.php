<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Serves the customer's familiar "Family Shopper — Seller Panel" UI verbatim.
 * The HTML/CSS/JS is unchanged; only its backend config was repointed to /papi/*.
 * We require a logged-in staff user (reusing the Filament /app web session) and
 * inject a token so the panel boots straight into the dashboard (no separate OTP).
 */
class SellerPanelController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) {
            // Not a tenant staff member — send to the business login.
            return redirect('/app/login');
        }

        $path = resource_path('panel/seller.html');
        if (! is_file($path)) {
            abort(500, 'Seller panel asset missing.');
        }

        $html = file_get_contents($path);

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    /** Self-serve onboarding: connect WhatsApp (QR) + generate the bot persona. */
    public function setup(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) {
            return redirect('/app/login');
        }
        $path = resource_path('panel/setup.html');
        if (! is_file($path)) {
            abort(500, 'Setup asset missing.');
        }
        return response(file_get_contents($path), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    /** The live Chats inbox (new screen the old panel never had). */
    public function chats(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) {
            return redirect('/app/login');
        }
        $path = resource_path('panel/chats.html');
        if (! is_file($path)) {
            abort(500, 'Chats asset missing.');
        }
        return response(file_get_contents($path), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }
}
