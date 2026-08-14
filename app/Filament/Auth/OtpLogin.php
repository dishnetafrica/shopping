<?php

namespace App\Filament\Auth;

use App\Models\User;
use App\Services\Auth\OtpService;
use Filament\Facades\Filament;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Validation\ValidationException;

/**
 * Seller login with two methods:
 *  - WhatsApp OTP (default): phone -> 6-digit code sent via the tenant instance.
 *  - Email + password: standard credentials (password set under Settings -> Security).
 */
class OtpLogin extends BaseLogin
{
    protected static string $view = 'filament.auth.otp-login';

    public string $method = 'email';   // 'whatsapp' | 'email' — default to email+password (v3 sign-in design); WhatsApp is one click away

    // WhatsApp OTP
    public ?string $phone = null;
    public ?string $code = null;
    public bool $sent = false;

    // Email + password
    public ?string $email = null;
    public ?string $password = null;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return 'Welcome back';
    }

    public function getSubheading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        return 'Sign in to manage your shop';
    }

    public function mount(): void
    {
        if (Filament::auth()->check()) {
            redirect()->intended('/panel');
        }
    }

    public function useWhatsapp(): void
    {
        $this->method = 'whatsapp';
        $this->resetErrorBag();
    }

    public function useEmail(): void
    {
        $this->method = 'email';
        $this->sent = false;
        $this->resetErrorBag();
    }

    /** WhatsApp step 1 — send the code over the tenant's WhatsApp instance. */
    public function sendCode(OtpService $otp): void
    {
        $this->validate(['phone' => 'required|string|min:6'], [], ['phone' => 'WhatsApp number']);

        $res = $otp->start((string) $this->phone);
        if (! $res['ok']) {
            throw ValidationException::withMessages(['phone' => $res['error']]);
        }
        $this->sent = true;
        $this->code = null;
    }

    public function resend(OtpService $otp): void
    {
        $this->sent = false;
        $this->sendCode($otp);
    }

    /** WhatsApp step 2 — verify the code and start the session. */
    public function confirm(OtpService $otp)
    {
        $this->validate(['code' => 'required|string|min:4'], [], ['code' => 'code']);

        // v5.05 — the same throttle as the password path. OtpService has its own per-code attempt
        // limit; this bounds guessing across codes, which that limit does not see.
        $this->guardAttempts((string) $this->phone, 'code');

        $res = $otp->verify((string) $this->phone, (string) $this->code);
        if (! $res['ok']) {
            $this->recordFailure((string) $this->phone);
            throw ValidationException::withMessages(['code' => $res['error']]);
        }
        $this->clearAttempts((string) $this->phone);

        $user = User::find($res['user_id']);
        if (! $user || ! $user->canAccessPanel(Filament::getCurrentPanel())) {
            throw ValidationException::withMessages(['code' => 'This number is not allowed to sign in here.']);
        }

        Filament::auth()->login($user, remember: true);
        session()->regenerate();

        return redirect()->intended('/panel');
    }

    public function startOver(): void
    {
        $this->sent = false;
        $this->code = null;
    }

    /**
     * v5.05 (launch audit P1-4) — throttle credential attempts.
     *
     * This page replaces Filament's stock Login, whose authenticate() opens with $this->rateLimit(5).
     * Replacing the page dropped that call, so email+password sign-in to the tenant panel — the one
     * every shop owner and staff member uses — took unlimited guesses. /admin kept the stock login
     * and was throttled; this was the gap. Keyed on IP AND on the account being targeted, so one
     * attacker cannot lock every owner out by hammering a shared IP.
     */
    private function throttleKeys(string $id): array
    {
        return ['auth:ip:' . request()->ip(), 'auth:id:' . sha1(mb_strtolower(trim($id)))];
    }

    private function guardAttempts(string $id, string $field): void
    {
        foreach ($this->throttleKeys($id) as $key) {
            if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 5)) {
                $secs = \Illuminate\Support\Facades\RateLimiter::availableIn($key);
                throw ValidationException::withMessages([$field => 'Too many attempts. Try again in '
                    . max(1, (int) ceil($secs / 60)) . ' minute(s).']);
            }
        }
    }

    private function recordFailure(string $id): void
    {
        foreach ($this->throttleKeys($id) as $key) {
            \Illuminate\Support\Facades\RateLimiter::hit($key, 900);   // 15-minute decay
        }
    }

    private function clearAttempts(string $id): void
    {
        foreach ($this->throttleKeys($id) as $key) {
            \Illuminate\Support\Facades\RateLimiter::clear($key);
        }
    }

    /** Email + password sign-in. */
    public function loginWithEmail()
    {
        $this->validate(
            ['email' => 'required|email', 'password' => 'required|string'],
            [],
            ['email' => 'email', 'password' => 'password']
        );

        $this->guardAttempts((string) $this->email, 'email');

        if (! Filament::auth()->attempt(['email' => $this->email, 'password' => $this->password], true)) {
            $this->recordFailure((string) $this->email);
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        $this->clearAttempts((string) $this->email);

        $user = Filament::auth()->user();
        if (! $user || ! $user->canAccessPanel(Filament::getCurrentPanel())) {
            Filament::auth()->logout();
            throw ValidationException::withMessages(['email' => 'This account is not allowed to sign in here.']);
        }

        session()->regenerate();
        return redirect()->intended('/panel');
    }
}
