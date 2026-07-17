<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Public, no-login self-serve signup. A stranger provisions their own shop with zero human in
 * the loop: this creates the Tenant (unique slug, 30-day trial via Tenant::booted) + the owner
 * login, signs them straight in, and hands off to the first-run wizard (/panel/onboard).
 *
 * Why auto-login instead of OTP for the first session: the normal OTP flow sends the code over
 * the tenant's OWN connected WhatsApp — which a brand-new shop doesn't have yet. So the first
 * session is a direct login (standard SaaS); they connect WhatsApp in the wizard, and every login
 * after that uses OTP over their now-connected number.
 */
class StartController extends Controller
{
    /** Reserved first-path segments that can never be a shop slug (mirror of routes/web.php). */
    private const RESERVED = [
        'app', 'admin', 'panel', 'papi', 'api', 'storage', 'livewire', 'build', 'vendor', 'up',
        'login', 'logout', 'register', 'start', 'healthz', 'sw.js', 'manifest', 'favicon.ico',
        'robots.txt', 'apple-touch-icon.png', 'r', 't', 'a', 'ops',
    ];

    private const KINDS = ['grocery', 'manufacturer', 'restaurant', 'pharmacy', 'electronics', 'other'];

    /** The public signup page. Already-logged-in owners skip straight to their panel. */
    public function page(Request $request)
    {
        if ($request->user()) {
            return redirect('/panel');   // LandingController routes owners to v3, staff to the app home
        }
        $path = resource_path('panel/start.html');
        if (! is_file($path)) {
            abort(500, 'Signup asset missing.');
        }
        return response(file_get_contents($path), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    /** Provision a brand-new shop and sign the owner in. */
    public function provision(Request $request)
    {
        // Abuse guard: cap signups per IP (the AI bot stays behind the trial, so tokens aren't at risk,
        // but this stops junk tenants flooding the table).
        $ipKey = 'signup:' . $request->ip();
        if (RateLimiter::tooManyAttempts($ipKey, 8)) {
            return response()->json(['ok' => false, 'error' => 'rate_limited',
                'detail' => 'Too many signups from this connection. Please try again later.'], 429);
        }

        $name      = trim((string) $request->input('name', ''));
        $kind      = strtolower(trim((string) $request->input('kind', 'other')));
        $ownerName = trim((string) $request->input('owner_name', ''));
        $whatsapp  = trim((string) $request->input('whatsapp', ''));
        $email     = strtolower(trim((string) $request->input('email', '')));

        if (! in_array($kind, self::KINDS, true)) $kind = 'other';

        // Validate.
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            return $this->fail('bad_input', 'Enter your business name (2–80 characters).');
        }
        if ($ownerName === '' || mb_strlen($ownerName) > 80) {
            return $this->fail('bad_input', 'Enter your name.');
        }
        $phone = OtpService::norm($whatsapp);
        if (strlen($phone) < 8) {
            return $this->fail('bad_input', 'Enter a valid WhatsApp number with country code (e.g. +211… or +256…).');
        }
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('bad_input', 'That email address looks invalid — leave it blank or fix it.');
        }

        // Already registered? Send them to login instead of making a duplicate. These checks must
        // span ALL tenants, so bypass the per-tenant global scope (there is no tenant context here).
        $phoneTaken = User::withoutGlobalScopes()->where('phone', $phone)->exists();
        $emailTaken = $email !== '' && User::withoutGlobalScopes()->where('email', $email)->exists();
        if ($phoneTaken || $emailTaken) {
            return response()->json([
                'ok' => false, 'error' => 'exists', 'redirect' => '/app/login',
                'detail' => 'This number or email already has a shop. Please sign in instead.',
            ], 409);
        }

        RateLimiter::hit($ipKey, 3600); // 1-hour window

        try {
            $user = DB::transaction(function () use ($name, $kind, $ownerName, $phone, $email) {
                $tenant = Tenant::create([
                    'name'            => $name,
                    'slug'            => $this->uniqueSlug($name),
                    'status'          => 'active',
                    'whatsapp_driver' => 'evolution',           // QR-pair path (waConnect uses shopbot_t{id})
                    'whatsapp_number' => $phone,
                    'order_prefix'    => $this->prefixFrom($name),
                    // plan=free + 30-day trial are auto-set in Tenant::booted().
                    'settings'        => [
                        'bot_kind'             => $kind,
                        'onboarded'            => false,        // → first-run wizard runs
                        'order_system_enabled' => true,         // new shops land on v3 (Get-started checklist)
                        'signup_source'        => 'self',
                        'signup_at'            => now()->toIso8601String(),
                    ],
                ]);

                return User::create([
                    'tenant_id'      => $tenant->id,
                    'name'           => $ownerName,
                    'phone'          => $phone,
                    'email'          => $email !== '' ? $email : null,
                    'password'       => Str::random(40),        // OTP-login shop; password is just a fallback
                    'role'           => 'owner',
                    'is_super_admin' => false,
                ]);
            });
        } catch (\Throwable $e) {
            report($e);
            return $this->fail('server', 'Something went wrong creating your shop. Please try again.', 500);
        }

        // First session: sign them straight in, then the wizard takes over.
        Auth::guard('web')->login($user, true);
        $request->session()->regenerate();

        return response()->json(['ok' => true, 'redirect' => '/v3']);   // single-view: land straight on v3 (Get-started checklist)
    }

    private function fail(string $error, string $detail, int $status = 422)
    {
        return response()->json(['ok' => false, 'error' => $error, 'detail' => $detail], $status);
    }

    /** slugify → guard against reserved words → ensure unique with a numeric suffix. */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        if ($base === '' || in_array($base, self::RESERVED, true)) {
            $base = 'shop';
        }
        $base = substr($base, 0, 40);

        $slug = $base;
        $n = 1;
        while (Tenant::where('slug', $slug)->exists() || in_array($slug, self::RESERVED, true)) {
            $n++;
            $slug = $base . '-' . $n;
        }
        return $slug;
    }

    /** A short order-number prefix from the business name (e.g. "Juba Fresh Mart" → "JFM"). */
    private function prefixFrom(string $name): string
    {
        $words = preg_split('/\s+/', trim($name)) ?: [];
        $ini = '';
        foreach ($words as $w) {
            if ($w !== '' && ctype_alnum($w[0])) $ini .= strtoupper($w[0]);
            if (strlen($ini) >= 3) break;
        }
        if ($ini === '') $ini = 'CB';
        return $ini;
    }
}
