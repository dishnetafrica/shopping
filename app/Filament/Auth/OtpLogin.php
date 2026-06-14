<?php

namespace App\Filament\Auth;

use App\Models\User;
use App\Services\Auth\OtpService;
use Filament\Facades\Filament;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Validation\ValidationException;

/**
 * OTP-only seller login. Step 1: enter WhatsApp phone -> code sent via the
 * tenant's own instance. Step 2: enter code -> logged in. No password.
 */
class OtpLogin extends BaseLogin
{
    protected static string $view = 'filament.auth.otp-login';

    public ?string $phone = null;
    public ?string $code = null;
    public bool $sent = false;

    public function mount(): void
    {
        if (Filament::auth()->check()) {
            redirect()->intended(Filament::getUrl());
        }
    }

    /** Step 1 — send the code over the tenant's WhatsApp instance. */
    public function sendCode(OtpService $otp): void
    {
        $this->validate(
            ['phone' => 'required|string|min:6'],
            [],
            ['phone' => 'WhatsApp number']
        );

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

    /** Step 2 — verify the code and start the session. */
    public function confirm(OtpService $otp)
    {
        $this->validate(
            ['code' => 'required|string|min:4'],
            [],
            ['code' => 'code']
        );

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

        return redirect()->intended(Filament::getUrl());
    }

    public function startOver(): void
    {
        $this->sent = false;
        $this->code = null;
    }
}
