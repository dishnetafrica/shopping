# Seller OTP login (OTP-only, tenant-instance sender)

## What changed
- `/app` seller login is now **passwordless**: enter WhatsApp number → 6-digit code
  sent via the **tenant's own WhatsApp instance** → enter code → signed in.
- Files: `app/Services/Auth/OtpService.php`, `app/Filament/Auth/OtpLogin.php`,
  `resources/views/filament/auth/otp-login.blade.php`,
  `AppPanelProvider.php` (`->login(OtpLogin::class)`), seller.html (link renamed to "Account").

## Security
5-min code TTL, single-use, hashed at rest (sha256), max 5 wrong attempts then the
code dies, issuance rate-limited to 3 / 10 min per phone, format-insensitive compare,
no user enumeration. Needs Redis (Cache + RateLimiter) — already required by prod safety.

## ⚠️ Read this — the trade-off you chose
**OTP-only + tenant-instance sender has one hard failure mode:** if a tenant's WhatsApp
instance is disconnected/offline, that tenant's sellers **cannot receive a code and
cannot log in** (there is no password fallback). Recovery paths:
- The **platform operator** signs into **/admin (password)** — that panel is unchanged —
  and can help (reconnect the instance, or assist the seller).
- Recommended safeguard (not built, your call): allow a **platform-sender fallback**
  when the tenant instance is offline, OR an operator-issued one-time code from /admin.

**Same-number caveat:** the seller's login phone (`users.phone`) must be a number the
**business instance can message** — i.e. generally NOT the same number as the instance
itself (an instance can't reliably WhatsApp itself). Set each seller's `phone` to their
personal WhatsApp, distinct from the shop's bot number.

**Passwords are now vestigial on /app:** existing password hashes are ignored for seller
login. `/admin` (operator) still uses password.

## Verified here
- OTP verification logic (`OtpService::evaluate`): **11/11** (`php qa/otp_logic_suite.php`,
  run in the full repo) — correct/wrong/expired/locked/format/empty/enumeration + phone norm.

## Verify on staging (cannot run in the build sandbox — needs Laravel/Livewire/WhatsApp)
1. Go to `/app/login` → enter a seller's WhatsApp number → confirm the code arrives on
   that number **from the shop's own instance**.
2. Enter the code → lands in the panel. Wrong code 5× → "request a new code". Wait >5 min
   → "expired". Resend respects the 3/10-min limit.
3. Confirm an offline-instance tenant indeed cannot log in (expected) and the operator
   recovery path works.

Deploy: drop these files in, push, `php artisan optimize:clear`. No migration.
