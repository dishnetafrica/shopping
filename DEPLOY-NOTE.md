# CloudBSS — Hero images just work (no composition rules)

Makes the collection hero robust to ANY uploaded photo, so there are no
image rules to follow. Single file: `resources/storefront/shop.html`.

## What changed
- **Automatic readability.** The hero now uses a stronger two-part scrim that
  darkens the left (where the desktop headline sits) AND the bottom (where the
  mobile headline sits), plus a subtle text shadow. The headline, bullets and
  button stay legible over any photo — bright or dark, subject anywhere.
- **Automatic sizing/cropping** (already the case): the uploader shrinks &
  compresses on upload; the hero fills with `cover` + centered crop and a dark
  fallback colour if a photo is missing.

Net effect: upload any decent landscape photo — the system handles size,
cropping and text contrast. No safe-zone / composition rules needed. (Putting the
main subject slightly to the right still looks nicest, but it's no longer
required for readability.)

Requires: deploy on top of the current storefront bundle (replaces its shop.html).
No backend/config change.

Verified: shop.html JS compiles; Playwright — hero renders over an uploaded
image with the two-gradient scrim and text shadow applied, headline legible.
