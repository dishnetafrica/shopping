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

    public string $method = 'whatsapp';   // 'whatsapp' | 'email'

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
            redirect()->intended('/panel/m');
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

        $res = $otp->verify((string) $this->phone, (string) $this->code);
        if (! $res['ok']) {
            throw ValidationException::withMessages(['code' => $res['error']]);
        }

        $user = User::find($res['user_id']);
        if (! $user || ! $user->canAccessPanel(Filament::getCurrentPanel())) {
            throw ValidationException::withMessages(['code' => 'This number is not allowed to sign in here.']);
        }

        Filament::auth()->login($user, remember: true);
        session()->regenerate();

        return redirect()->intended('/panel/m');
    }

    public function startOver(): void
    {
        $this->sent = false;
        $this->code = null;
    }

    /** Email + password sign-in. */
    public function loginWithEmail()
    {
        $this->validate(
            ['email' => 'required|email', 'password' => 'required|string'],
            [],
            ['email' => 'email', 'password' => 'password']
        );

        if (! Filament::auth()->attempt(['email' => $this->email, 'password' => $this->password], true)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        $user = Filament::auth()->user();
        if (! $user || ! $user->canAccessPanel(Filament::getCurrentPanel())) {
            Filament::auth()->logout();
            throw ValidationException::withMessages(['email' => 'This account is not allowed to sign in here.']);
        }

        session()->regenerate();
        return redirect()->intended('/panel/m');
    }
}
