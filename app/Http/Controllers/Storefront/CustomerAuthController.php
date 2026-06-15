<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;

/**
 * WhatsApp-OTP login for storefront customers. No passwords, no new tables:
 * a 6-digit code is sent through the shop's own WhatsApp instance, cached for a
 * few minutes, and on success the browser gets a short-lived encrypted token.
 * The token gates "my orders" so order history is never exposed by phone alone.
 */
class CustomerAuthController extends Controller
{
    private const RESERVED = [
        'app', 'admin', 'panel', 'papi', 'api', 'storage', 'livewire',
        'build', 'vendor', 'up', 'login', 'logout', 'register',
    ];

    private function tenant(string $shop): Tenant
    {
        $slug = strtolower(trim($shop));
        abort_if(in_array($slug, self::RESERVED, true), 404);
        $tenant = Tenant::where('slug', $slug)->first();
        abort_if(! $tenant, 404);
        abort_if(($tenant->status ?? 'active') === 'suspended', 404);
        app(TenantContext::class)->set($tenant->id);
        return $tenant;
    }

    private function normPhone(string $raw): string
    {
        return preg_replace('/[^0-9]/', '', $raw);
    }

    /** Send a login code to the customer's WhatsApp via the shop's instance. */
    public function request(string $shop, Request $r, WhatsAppManager $wa)
    {
        $tenant = $this->tenant($shop);
        $phone  = $this->normPhone((string) $r->input('phone', ''));
        if (strlen($phone) < 9) {
            return response()->json(['ok' => false, 'error' => 'Enter a valid WhatsApp number.'], 422);
        }
        if (! $tenant->whatsapp_instance) {
            return response()->json(['ok' => false, 'error' => 'Login by code is not available for this shop yet.'], 422);
        }

        // Rate limit: 3 code requests / 10 min per phone+shop, and 8 / hour per IP.
        $rk = 'custotp:' . $tenant->id . ':' . $phone;
        if (RateLimiter::tooManyAttempts($rk, 3)) {
            return response()->json(['ok' => false, 'error' => 'Too many requests — please wait a few minutes.'], 429);
        }
        RateLimiter::hit($rk, 600);
        $ipk = 'custotpip:' . $tenant->id . ':' . $r->ip();
        if (RateLimiter::tooManyAttempts($ipk, 8)) {
            return response()->json(['ok' => false, 'error' => 'Too many requests — please try later.'], 429);
        }
        RateLimiter::hit($ipk, 3600);

        $code = (string) random_int(100000, 999999);
        Cache::put($this->codeKey($tenant->id, $phone), ['code' => $code, 'tries' => 0], now()->addMinutes(5));

        $msg = "*{$code}* is your {$tenant->name} login code. It expires in 5 minutes. "
             . "If you didn't request it, you can ignore this message.";
        try {
            $wa->forTenant($tenant)->sendText($tenant->whatsapp_instance, $phone, $msg);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'Could not send the code right now. Please try again.'], 502);
        }
        return response()->json(['ok' => true, 'sent_to' => $this->maskPhone($phone)]);
    }

    /** Verify the code; on success issue a short-lived token + return the saved profile. */
    public function verify(string $shop, Request $r)
    {
        $tenant = $this->tenant($shop);
        $phone  = $this->normPhone((string) $r->input('phone', ''));
        $code   = preg_replace('/[^0-9]/', '', (string) $r->input('code', ''));

        $key = $this->codeKey($tenant->id, $phone);
        $rec = Cache::get($key);
        if (! is_array($rec)) {
            return response()->json(['ok' => false, 'error' => 'Code expired — please request a new one.'], 422);
        }
        if ((int) ($rec['tries'] ?? 0) >= 5) {
            Cache::forget($key);
            return response()->json(['ok' => false, 'error' => 'Too many wrong tries — request a new code.'], 429);
        }
        if (! hash_equals((string) $rec['code'], (string) $code)) {
            $rec['tries'] = (int) ($rec['tries'] ?? 0) + 1;
            Cache::put($key, $rec, now()->addMinutes(5));
            return response()->json(['ok' => false, 'error' => 'Wrong code — please try again.'], 422);
        }
        Cache::forget($key);

        $profile = $this->profileFor($tenant, $phone);
        $token   = $this->issueToken($tenant->id, $phone);

        return response()->json(['ok' => true, 'token' => $token, 'profile' => $profile]);
    }

    /** Recent orders for the logged-in customer (token-gated), newest first. */
    public function myOrders(string $shop, Request $r)
    {
        $tenant = $this->tenant($shop);
        $phone  = $this->phoneFromToken($tenant->id, (string) $r->input('token', ''));
        if ($phone === null) {
            return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        $orders = Order::where('customer_phone', $phone)
            ->orderByDesc('id')->limit(15)->get()
            ->map(fn (Order $o) => [
                'order_no' => (string) $o->order_no,
                'date'     => optional($o->created_at)->toIso8601String(),
                'status'   => (string) $o->status,
                'total'    => (float) $o->total,
                'items'    => is_array($o->items_json)
                    ? array_values(array_map(fn ($l) => [
                        'name'  => (string) ($l['name'] ?? ''),
                        'qty'   => (int) ($l['qty'] ?? 1),
                        'price' => (float) ($l['price'] ?? 0),
                    ], $o->items_json))
                    : [],
            ])->values();

        return response()->json([
            'ok'      => true,
            'profile' => $this->profileFor($tenant, $phone),
            'orders'  => $orders,
        ]);
    }

    /** Save / update the customer's own profile (token-gated). */
    public function saveProfile(string $shop, Request $r)
    {
        $tenant = $this->tenant($shop);
        $phone  = $this->phoneFromToken($tenant->id, (string) $r->input('token', ''));
        if ($phone === null) {
            return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        $c = CustomerProfile::firstOrNew(['phone' => $phone]);
        if ($r->filled('name'))    $c->name    = trim((string) $r->input('name'));
        if ($r->filled('address')) $c->address = trim((string) $r->input('address'));
        $c->save();
        return response()->json(['ok' => true, 'profile' => $this->profileFor($tenant, $phone)]);
    }

    // ---- helpers ----

    private function codeKey(int $tenantId, string $phone): string
    {
        return "custcode:{$tenantId}:{$phone}";
    }

    private function issueToken(int $tenantId, string $phone): string
    {
        return Crypt::encryptString(json_encode([
            't' => $tenantId, 'p' => $phone, 'exp' => now()->addDays(30)->timestamp,
        ]));
    }

    private function phoneFromToken(int $tenantId, string $token): ?string
    {
        if ($token === '') return null;
        try {
            $d = json_decode(Crypt::decryptString($token), true);
        } catch (\Throwable $e) {
            return null;
        }
        if (! is_array($d) || (int) ($d['t'] ?? 0) !== $tenantId) return null;
        if ((int) ($d['exp'] ?? 0) < now()->timestamp) return null;
        $p = $this->normPhone((string) ($d['p'] ?? ''));
        return $p !== '' ? $p : null;
    }

    private function profileFor(Tenant $tenant, string $phone): array
    {
        $c = CustomerProfile::where('phone', $phone)->first();
        // Fall back to the most recent order's name/location if no profile row yet.
        $last = Order::where('customer_phone', $phone)->orderByDesc('id')->first();
        return [
            'phone'   => $phone,
            'name'    => (string) ($c->name ?? ($last->customer_name ?? '')),
            'address' => (string) ($c->address ?? ''),
        ];
    }

    private function maskPhone(string $phone): string
    {
        $n = strlen($phone);
        return $n <= 4 ? $phone : (str_repeat('•', $n - 4) . substr($phone, -4));
    }
}
