# STOREFRONT-ROLLBACK.md — shop.html without any Arabic UI

## What this is
Your storefront file with EVERY Arabic-UI edit reversed — verified **byte-identical
to the original (267,164 bytes)**, both script blocks node-validated. No toggle, no
RTL, no dictionary. Deploy this and familyshoppers.net is exactly the pre-Arabic file.

## What is deliberately KEPT
**Arabic SEARCH (Tier 2) stays** — it never touched the design:
- If you ran `products:arabic-keywords 17`, the Arabic keywords remain in the
  database and searching زيت still finds oils. Zero visual impact.
- The command + translator files stay deployed (harmless, additive PHP).
- This zip's test file keeps the keywords test but drops the UI assertions —
  IMPORTANT: without this test file, your test gate would fail after rollback.

## Deploy
Upload BOTH files → Rebuild → hard-refresh familyshoppers.net.
If the design STILL isn't what you expect after this byte-exact rollback, then the
design change came from something other than the Arabic zip (an earlier deploy from
my snapshot?) — in that case: GitHub → shop.html → History → restore the version
whose date matches when the design last looked right, and send me THAT file so all
my future storefront work starts from your real one.

## Files (2)
resources/storefront/shop.html (byte-exact original) ·
tests/Feature/ArabicStorefrontTest.php (UI assertions removed)
