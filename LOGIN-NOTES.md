# Login — dead simple, WhatsApp-first

`/app/login` is the single real login (every /panel route is behind `auth`, so users
land here first). Designed for "number → code → in".

## Files
- app/Filament/Auth/OtpLogin.php  (WhatsApp OTP + optional email fallback)
- resources/views/filament/auth/otp-login.blade.php  (the simple UI)

## The everyday path (no choices)
1. Open /app/login → WhatsApp number field is focused, pre-filled with the last number used.
2. Tap "Send code".
3. The 6-digit field auto-focuses; typing the 6th digit signs you in automatically
   (the "Sign in" button is still there as a fallback).

## Email is hidden by default
A small "Trouble with WhatsApp? Sign in with email" link sits under the form — it's
only a lockout fallback (used if the tenant's WhatsApp instance is offline). Email
needs a password (set under Settings → Security). New users never touch it.

## Look
Branded green gradient, rounded inputs with focus ring, light/dark aware, big centered
code box. Styling is scoped + additive (no Filament internals overridden).

## Verified here
- PHP lints; blade balanced (@if/@else/@endif, one page wrapper, 3 matched forms);
  every wire: method/prop the blade uses exists in OtpLogin.

## Staging check (no Livewire/Filament/JS runtime here)
1. Number pre-fills from last time; field is focused on load.
2. Code field auto-focuses after "Send code"; typing 6 digits auto-submits.
3. Email link reveals the email form; "← Back to WhatsApp" returns.
4. Looks right on phone + desktop, light + dark.

## Note
The old in-panel login screen in seller.html is dead (the panel sets fs_token='session'
and boots straight in; it's never shown). Left as-is — harmless. Can be deleted later.
