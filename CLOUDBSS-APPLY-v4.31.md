# CloudBSS v4.31 — the Suggestions screen, in v3, with bulk actions

**Date:** 2026-08-04 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.31.zip (cumulative — supersedes v4.30; 163 files) · **Migration:** none · **Delta: 7 files.**

## Your dry run, read back

```
367 products · queued 835 for review · apply 0 · skipped 46
```

**Why nothing was applied:** you ran without `--barcode-api`, so only the AI provider looked at your catalogue. AI inference is capped at 0.80 — and 0.74 for country of origin — deliberately below the 0.85 auto-apply line. An AI guess *always* goes to a human. Barcode facts carry 0.95 and would have been written straight in.

So the result is correct, but it left 835 decisions with nowhere good to make them.

**And you were right about the button.** I built the review into the *legacy* panel's Products page. You use v3. That is the same mistake I made with TenderBot in v4.21, and I should have caught it.

## What this adds

**A Suggestions entry in the v3 left menu, under Inventory, next to Products.** It carries the waiting count, and **it only appears while something is waiting** — when the queue empties, the entry goes away. A menu item that permanently reads "(0)" teaches people to stop looking at it.

**Grouped by product, not by row.** One card per product showing all its pending fields together, because "brand, size and origin for Shan Mango Pickle" is one decision, not three. **Use all** / **Skip** per product.

**Filters that mean something.** By field (Brand 312, Size 190, Origin 240…) and by source — **Barcode only** is the important one: those are facts read off the EAN, not guesses, so you can approve them in bulk with a clear conscience and then read the AI guesses properly.

**Bulk, honestly implemented.** "Use all 835" works, in batches of 300, showing progress as it goes rather than pretending it is instant. The filter used for the button is resolved **on the server from the same filter the list used** — so "Use all 46 showing" applies 46, never 835.

**Approving in bulk still cannot overwrite you.** Every single write — one row, one product, or all 835 — goes through the same check: the field must *still* be empty. A suggestion raised in a dry run last Tuesday will not overwrite something you typed on Wednesday; it is dropped and reported as "already filled in". This matters far more in bulk than singly, because nobody reads 835 rows carefully.

## A suggested order of work

1. Filter to **Barcode only** → *Use all*. Those are facts.
2. Filter by field to **Size**, then **Brand** → skim and bulk-approve. They are short and wrong ones are obvious at a glance.
3. **Origin** last and slowest — it goes on customs and tender paperwork.
4. **Description** is worth reading properly. It is the piece that affects Google.

Or run `php artisan products:research --tenant=17 --barcode-api --apply` first: the ~72% of your catalogue with a real barcode will fill itself in from fact, and the queue you have to read shrinks to the products that genuinely need a human.

## After you deploy

1. Reload the panel. **Suggestions** should be in the left menu under Inventory with **835** next to it.
2. Open it. Filter **Barcode only** — if that shows 0, it is because your dry run made no barcode calls.
3. Try one product's **Use all** before doing anything in bulk.

No migration.

## Verified before ship

**55 checks.** The review page is built inside a PHP heredoc, where a stray quote ships as a blank screen with no error anywhere, so the harness lifts the template out of the shipped controller, resolves the placeholders and **parses the JavaScript with `node --check`** rather than trusting it by eye.

Page (31): the template extracts with no unresolved PHP variable left in it; exactly one script block; div tags balanced; the JS parses; the screen posts to the right endpoints with a CSRF token; bulk actions confirm first; **bulk-all resolves `scope=filtered` server-side and passes the same field and source the list used**; the paging loop has a hard stop and follows the server's remaining count; buttons disable mid-run; product names, suggested values and notes are all escaped; `filtered()` uses `input()` and never `query()`, so a POST body resolves identically to a GET query string; field and source are whitelisted rather than trusted; and every write path reaches `SuggestionReview`, which re-checks that the target field is still empty, that the field is writable, and that the column exists.

Menu (24): the Suggestions entry is absent at 0 pending, present at 835, and **disappears again once the count returns to 0**; it is independent of business type; Solar Advisor still follows the vertical and still fails open; Settings, POS, AI Campaign, Orders, Products, P&L, Books and the storefront entries unchanged; Tenders still gated on its own flag; finance entries still need the finance capability; and both copies of `shell.js` byte-identical, since the browser loads the `public/` one.

`php -l` on 4 PHP files · both JS surfaces parsed · v4.30's 61-check settings harness, v4.29's 97, v4.28's 89 and v4.27.1's 36 all re-run and still green.
