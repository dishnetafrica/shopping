# CloudBSS v4.31.1 — the dry run lied to you, and the menu entry hid because of it

**Date:** 2026-08-04 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.31.1.zip (cumulative — supersedes v4.31; 163 files) · **Migration:** none · **Delta: 6 files.**

## What actually happened

Your deploy was fine. **Your dry run saved nothing**, so there were zero suggestions, so the menu entry correctly hid itself.

The problem is that the command told you otherwise:

```
Looked at 367 · filled 0 products · queued 835 for review
```

**It had queued nothing.** `$queued` was counting decisions, and only the database write was skipped in a dry run — so the summary reported work that never happened. `filled 0 products` had the same flaw and was only accurate by luck.

Then my "the entry only appears when something is waiting" design turned a confusing message into an apparently broken feature: with nothing queued, there was no menu entry, and **no way to tell "nothing to review yet" from "the update did not install".** That is a bad trade, and I made it. A menu entry reading (0) is a smaller sin than a feature you cannot find.

## What you can do right now, without deploying anything

```
php artisan products:research --tenant=17 --apply
```

That writes the 835 suggestions, and the entry appears. Better still:

```
php artisan products:research --tenant=17 --barcode-api --descriptions --apply
```

The products that carry a real barcode fill themselves in from fact rather than joining the queue, so there is less for you to read.

## What this drop changes

**The dry run says what it did.** *"Looked at 367 · WOULD fill 12 · WOULD queue 835 for review"*, and then, unmissably:

> **DRY RUN — nothing was saved. The Suggestions screen is still empty.**
> To actually create the 835 suggestions, run the same command with `--apply`

It also suggests `--barcode-api` when a run made no barcode calls and auto-applied nothing — which was exactly your situation.

**The Suggestions entry now shows once research is switched on, even at zero.** No badge when the count is zero; the badge appears with the number. A shop that has never turned research on still sees nothing, so this adds no clutter for anyone else.

**The empty screen explains itself.** Opening Suggestions with nothing in it now names the most likely cause — a dry run — and shows the exact command to run, rather than a bare "Nothing waiting".

## After you deploy

1. Reload the panel. With **Settings → Storefront → Research my products** ON, **Suggestions** appears under Inventory even at zero.
2. Open it — the empty state tells you what to run.
3. Run with `--apply` and reload; the count appears next to the entry.

If **Suggestions** still does not appear after this, then it is genuinely a deploy or permission problem, and the two things to check are that `php artisan migrate` has run (the `product_suggestions` table must exist) and that you are signed in as the owner.

## Verified before ship

**61 checks.**

Menu (26): research never switched on and nothing pending → hidden; 835 pending → shown; **research ON with 0 pending → shown**, which is the fix; research explicitly off with nothing pending → hidden, so shops not using it get no clutter; independent of business type. Solar Advisor still follows the vertical and still fails open; Settings, POS, AI Campaign, Orders, Products, P&L, Books and the storefront entries unchanged; Tenders still gated on its own flag; finance entries still need the finance capability; both copies of `shell.js` byte-identical.

Page (35): everything from v4.31 — the template extracts and its JavaScript parses under `node --check`, bulk actions resolve the same filter server-side, values are escaped, and every write reaches the still-empty check — plus the new empty state naming `--apply`, `--barcode-api`, and saying plainly that a dry run saves nothing.

`php -l` on 3 PHP files · both JS surfaces parsed · v4.30's 61, v4.29's 97, v4.28's 89 and v4.27.1's 36 all re-run and still green.
