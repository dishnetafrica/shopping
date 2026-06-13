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

        $html = $this->brandize(file_get_contents($path), $user->tenant);

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    /** Swap the hardcoded "Family Shopper / FS" branding for the tenant's own. */
    protected function brandize(string $html, $tenant): string
    {
        $name = trim((string) ($tenant->name ?? 'Shop'));
        $name = preg_replace('/[<>"\']/', '', $name) ?: 'Shop';
        $initials = $this->initialsFor($name);
        $html = str_replace('Family Shopper', $name, $html);
        $html = str_replace('<div class="lg">FS</div>', '<div class="lg">' . $initials . '</div>', $html);
        $html = str_replace('content="Seller"', 'content="' . $name . '"', $html);
        return $html;
    }

    protected function initialsFor(string $name): string
    {
        $i = '';
        foreach (preg_split('/\s+/', trim($name)) as $p) {
            if ($p !== '') $i .= mb_strtoupper(mb_substr($p, 0, 1));
            if (mb_strlen($i) >= 2) break;
        }
        return $i !== '' ? $i : mb_strtoupper(mb_substr($name, 0, 2));
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
        return response($this->brandize(file_get_contents($path), $request->user()->tenant), 200)
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
