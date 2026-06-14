<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Passwordless (OTP-only) seller login. A 6-digit code is delivered over the
 * tenant's OWN WhatsApp instance to the seller's login phone, then verified.
 *
 * Security: codes are stored hashed with a 5-min TTL, single-use, capped at
 * MAX_ATTEMPTS wrong tries, and issuance is rate-limited per phone.
 *
 * NOTE (operational): because the code is sent via the tenant's own WhatsApp
 * instance and there is NO password fallback, a tenant whose instance is
 * disconnected cannot receive a code. The platform operator (/admin, password)
 * is the recovery path. See OTP-LOGIN-NOTES in the bundle.
 */
class OtpService
{
    public const TTL = 300;          // code lifetime, seconds (5 min)
    public const MAX_ATTEMPTS = 5;   // wrong tries before the code is killed
    public const RESEND_LIMIT = 3;   // max issues per phone per window
    public const RESEND_WINDOW = 600;// seconds (10 min)

    public function __construct(protected WhatsAppManager $wa) {}

    public static function norm(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    protected function key(string $normPhone): string
    {
        return 'otp:login:' . $normPhone;
    }

    /** Resolve a seller by login phone (digits compared, format-insensitive). */
    public function userByPhone(string $phone): ?User
    {
        $norm = self::norm($phone);
        if ($norm === '') return null;

        return User::query()
            ->whereRaw("regexp_replace(coalesce(phone,''), '\\D', '', 'g') = ?", [$norm])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Step 1: issue + send a code. Returns ['ok'=>bool, 'error'=>?string].
     * Generic on unknown numbers (no enumeration); only a genuine send failure
     * surfaces an error (we can't pretend a code went out when it didn't).
     */
    public function start(string $phone): array
    {
        $norm = self::norm($phone);
        if (strlen($norm) < 6) {
            return ['ok' => false, 'error' => 'Enter a valid WhatsApp phone number.'];
        }
        if (RateLimiter::tooManyAttempts('otp-send:' . $norm, self::RESEND_LIMIT)) {
            return ['ok' => false, 'error' => 'Too many code requests. Please wait a few minutes and try again.'];
        }

        $user = $this->userByPhone($norm);

        if ($user && $user->tenant && $user->tenant->whatsapp_instance) {
            $code = (string) random_int(100000, 999999);
            Cache::put($this->key($norm), [
                'hash' => hash('sha256', $code),
                'user_id' => $user->id,
                'attempts' => 0,
                'at' => time(),
            ], self::TTL);
            RateLimiter::hit('otp-send:' . $norm, self::RESEND_WINDOW);

            try {
                $this->send($user, $code);
            } catch (\Throwable $e) {
                Log::warning('otp.send_failed', ['phone' => $norm, 'tenant' => $user->tenant_id, 'err' => $e->getMessage()]);
                Cache::forget($this->key($norm));
                return ['ok' => false, 'error' => 'We could not send your code over WhatsApp. Your shop number may be offline — contact support.'];
            }
        }

        // Unknown number: behave as success but nothing was sent (no enumeration).
        return ['ok' => true, 'error' => null];
    }

    /** Step 2: verify. Returns ['ok'=>bool, 'user_id'=>?int, 'error'=>?string]. */
    public function verify(string $phone, string $code): array
    {
        $norm = self::norm($phone);
        $rec  = Cache::get($this->key($norm));
        $res  = self::evaluate($rec, $code, time());

        if ($res['ok']) {
            Cache::forget($this->key($norm));
            return ['ok' => true, 'user_id' => $rec['user_id'], 'error' => null];
        }

        if ($rec && $res['reason'] === 'wrong') {
            $rec['attempts'] = (int) ($rec['attempts'] ?? 0) + 1;
            if ($rec['attempts'] >= self::MAX_ATTEMPTS) {
                Cache::forget($this->key($norm));
            } else {
                $remain = max(1, self::TTL - (time() - (int) $rec['at']));
                Cache::put($this->key($norm), $rec, $remain);
            }
        }

        return ['ok' => false, 'user_id' => null, 'error' => $res['message']];
    }

    /**
     * PURE verification decision (no I/O) — unit-tested.
     * @param array|null $rec stored record ['hash','user_id','attempts','at']
     */
    public static function evaluate(?array $rec, string $input, int $now, int $ttl = self::TTL, int $max = self::MAX_ATTEMPTS): array
    {
        if (!$rec) {
            return ['ok' => false, 'reason' => 'none', 'message' => 'Code not found or already used. Request a new one.'];
        }
        if ($now - (int) ($rec['at'] ?? 0) > $ttl) {
            return ['ok' => false, 'reason' => 'expired', 'message' => 'That code has expired. Request a new one.'];
        }
        if ((int) ($rec['attempts'] ?? 0) >= $max) {
            return ['ok' => false, 'reason' => 'locked', 'message' => 'Too many attempts. Request a new code.'];
        }
        $clean = preg_replace('/\D+/', '', $input) ?? '';
        if ($clean !== '' && hash_equals((string) $rec['hash'], hash('sha256', $clean))) {
            return ['ok' => true, 'reason' => 'match', 'message' => ''];
        }
        return ['ok' => false, 'reason' => 'wrong', 'message' => 'Incorrect code. Please try again.'];
    }

    protected function send(User $user, string $code): void
    {
        $tenant  = $user->tenant;
        $gateway = $this->wa->forTenant($tenant);
        $app     = config('app.name', 'ShopBot');
        $msg     = "{$app} login code: {$code}\nIt expires in 5 minutes. Do not share this code with anyone.";
        $gateway->sendText($tenant->whatsapp_instance, (string) $user->phone, $msg);
    }
}
