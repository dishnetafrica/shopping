# S2c · Serials on the product page — upload to GitHub

Builds on S2a/S2b. The V3 product detail page now shows a serialized product's units by status
(Tracked units · In stock · Sold · …) and offers an **Add serials** action to register units right
there — paste serial numbers separated by commas or new lines. All through the capability payload, so
there is **no change to the V3 front-end**.

## What to do
Upload these two files, keeping the folders. (S2a + S2b already uploaded.)

## How you'll know it worked
- Green ✓ "Money kernel (must pass)" — the S2c test runs inside that check.
- In the app: open a serialized product → you'll see its unit counts, and **Add serials** lets you
  register more. A product with no serials looks exactly as before.

## What's inside / safety
- Only `ProductCapability::forId()` (adds serial 'stats' + an Add-serials action) and `command()`
  (adds `add_serials`) are extended — additive. Non-serialized products are unaffected.
- `tests/Feature/Kernel/SerialProductViewTest.php`.
- Rollback = revert `ProductCapability.php`.

## Deferred: QR codes
There is no QR library server-side yet, so rendering the units' QR codes is a small follow-on
(the `qr_token` is already stored on every unit, so it's ready whenever we add a QR renderer).
