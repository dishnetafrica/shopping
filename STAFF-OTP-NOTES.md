# Staff edit + why WhatsApp OTP login wasn't arriving

## Why the code never came (this is the real cause, not a bug)
Login OTP is sent in `OtpService::start()` ONLY when BOTH are true:
1. a staff **User exists whose phone (digits) equals the number entered**, AND
2. that user's tenant has a connected WhatsApp (`whatsapp_instance` set).
Otherwise it deliberately returns "ok" and sends nothing (so outsiders can't probe which
numbers have accounts). That's why the screen advances to the 6-digit step but no code arrives.

The number tried — **211927797217** (South Sudan) — is on NO staff account:
- Family Shoppers Admin phone = `256700000000` (the placeholder), not 211…
- Savan Bhai = email only, no WhatsApp number.
So there was no user to send a code to. The code is also sent FROM the shop's own WhatsApp
(Evolution) TO the staff number, so the shop instance must be connected too.

## Fix paths
- **Get in now:** on the login screen tap *"Trouble with WhatsApp? Sign in with email"* and use the
  email + password set when the account was created.
- **Make WhatsApp code login work:** put the login number on the account (below), and make sure the
  shop's WhatsApp is connected (Panel → Setup / WhatsApp). Then "Send code" delivers over WhatsApp.

## New: edit an existing staff login (previously add/delete only)
Files:
- `app/Http/Controllers/Panel/PanelApiController.php` — adds `staffUpdate()` (name/email/phone/role,
  optional new password; tenant-scoped; email/phone uniqueness checked against OTHER users).
- `routes/web.php` — adds `POST /papi/staff/update` (and keeps the earlier `/panel/m` route).
- `resources/panel/staff.html` — each login now has an **Edit** button opening a modal to set the
  WhatsApp number / role / password. (You can edit your own login too, e.g. to add your number.)

Deploy: push files + `php artisan optimize:clear` (and `route:clear` if routes are cached). No migration.

## Do this to fix the login you were attempting
1. Open `/panel/staff` (already logged in as admin on the Mac).
2. Click **Edit** on the login you want to sign in as (e.g. Savan Bhai), set
   **WhatsApp number = 211927797217**, Save.
3. Confirm the shop WhatsApp is connected (Panel → Setup). 
4. On the phone, `/app/login` → enter 211927797217 → Send code → the 6-digit code arrives on WhatsApp.
   Also fix Family Shoppers Admin's placeholder `256700000000` to the real admin number the same way.
