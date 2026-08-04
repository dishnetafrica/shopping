# CloudBSS v4.31.3 — 64 quotations had no review link at all

**Date:** 2026-08-04 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.31.3.zip (cumulative — supersedes v4.31.2; 165 files) · **Migration: YES — `php artisan migrate`** · **Delta: 3 files.**

## Your audit found it

```
Quotations without a portal token: 64 of 216
```

**The cause is a missing line in a migration, not a mystery.** `public_token` was added to the quotations table on **16 July** as a nullable column. From that day on, the model generates a token whenever a quotation is created — so everything new is fine. **Nothing ever filled in the quotations that already existed.** Every quotation from before 16 July has no token and never could have had one.

I said in v4.31.2 that a raw insert was the likely cause. That was wrong — there is exactly one place quotations are created and it goes through the model properly. The migration simply never backfilled.

## Why this was worse than a broken link

A quotation with no token doesn't just have a dead review URL. The revision notifier reads:

```php
if ($phone !== '' && $q->public_token) {
```

**No token means no WhatsApp message is sent at all.** So when one of those 64 quotations was revised, the customer was never told it had changed — no message, no error, nothing in a log. Silent for three weeks.

## The fix

A migration fills in a token for every quotation that has none.

**It never rotates an existing token.** Those links are already out with customers and must keep working. Only empty ones are filled, so running it twice does nothing the second time.

There is also `php artisan quote:check --fix` if you ever need to run it again by hand.

## Your specific link is still unexplained

`ORD-Q260804-EE51` was created today, so it is **not** one of the 64 — it should have had a token from the moment it was made. Its 404 is a separate question, and the one thing that will settle it is:

```
php artisan quote:check jbe3kt5rebgtssrguh4frzpbeqxz2zetud6wqqoh
```

If that says **FOUND**, then v4.31.2's token-first resolution already fixed it and the link works now. If it says **NOT FOUND**, something is deleting or rewriting tokens after creation and I want to see that output.

## After you deploy

1. **`php artisan migrate`** — this backfills the 64.
2. `php artisan quote:check --audit` → should now read **0 of 216**.
3. `php artisan quote:check jbe3kt5rebgtssrguh4frzpbeqxz2zetud6wqqoh` → send me what it prints.
4. Re-send any of those older quotations that still matter; their links work now.

## Verified before ship

The backfill was **run against a real SQLite table built to your exact numbers** — 216 quotations, 64 without a token, with the same UNIQUE index the live column carries:

- missing before **64** → missing after **0**
- **216 distinct tokens across 216 rows** — no collision, and the unique index would have rejected one
- every generated token is exactly 40 characters of `[a-z0-9]`, matching the route pattern the link must satisfy
- a **second run filled 0** and left every row byte-identical — idempotent
- and the check that matters most: **every pre-existing token was preserved unchanged**, so no link already sent to a customer was invalidated

`php -l` on 2 files · v4.31.2's 21, v4.31.1's 35 and 26, v4.30's 61, v4.29's 97, v4.28's 89 and v4.27.1's 36 all re-run and still green.
