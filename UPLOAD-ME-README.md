# S1b · Specifications on the product page — upload to GitHub

Builds on S1a. Your V3 product detail page now shows a product's specifications, and offers an
"Edit specifications" action to set them right there — all through the product capability's payload,
so there is **no change to the V3 front-end**.

## What to do
Upload these two files, keeping the folders:
- `app/Http/…` → actually `app/Capability/Object/ProductCapability.php` (the product detail capability)
- `tests/Feature/Kernel/ProductSpecWorkspaceTest.php` (the test)

Nothing else changes. (S1a must already be uploaded — it is, since it went green.)

## How you'll know it worked
- **Green ✓ "Money kernel (must pass)"** — the new S1b tests run inside that same check.
- In the app: open any product in V3. If it has specs, you'll see them listed; use
  **Edit specifications** to set/change them. Products with no specs look exactly as before.

## What's inside / safety
- Only `ProductCapability::forId()` and `command()` are extended (additive): specs render via the
  existing generic "answers" section and the edit uses the same command pattern as Edit/Archive.
- No front-end file, no other capability, no document touched. Tenants without a spec catalogue are
  unaffected. Rollback = revert this one file.

## Next (S1c)
Specs on quotations & invoices, product-list filter UI, and export.
