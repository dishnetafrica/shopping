# Staff: add a WhatsApp number (easy login for staff too)

The "Add a staff login" form now has an optional **WhatsApp number** field.

## How staff sign in
- **WhatsApp number set** → they sign in at /app/login by entering that number and the
  6-digit code (the easy path; no password needed — we store a random one internally).
- **No WhatsApp number** → email + password (as before).
- You may set both; either works.

## Rules
- Name + email are still required (email is the account identifier).
- A login method is required: a WhatsApp number OR a password of 6+ characters.
- Duplicate email → "email already in use"; duplicate WhatsApp number → "number already in use".

## Files
- resources/panel/staff.html — adds the WhatsApp field + shows the number in the list.
- app/Http/Controllers/Panel/PanelApiController.php — staffAdd accepts/saves phone
  (password optional when a number is given); staffList returns the phone.

## Important caveat (WhatsApp OTP)
The login code is sent from the SHOP's WhatsApp instance to the staff member's number,
so the staff number must be DIFFERENT from the shop's bot WhatsApp number (a WhatsApp
number can't message itself). If the shop's instance is offline, staff can't get a code —
that's when email + password is the fallback.

## Verified here
PHP lints; staff.html JS passes node --check; the new field + list display are wired.
Deploy (push both files) then test on /panel/staff.
