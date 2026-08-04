# CloudBSS v4.33 — a receipt behind every payment

**Date:** 2026-08-04 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.33.zip (cumulative — supersedes v4.32; 176 files) · **Migration: YES — `php artisan migrate`** · **Delta: 10 files.**

## Where it is

**Finance → Evidence.** New entry in the v3 menu, next to the Cashbook. It shows every payment your business has made or received and, beside each one, the documents behind it.

Tap **Attach** on any line, pick photos or a PDF from the phone, tap what kind of document it is — receipt, supplier invoice, delivery note, payment voucher, contract — and it is filed against that transaction forever. Several documents on one payment is normal and expected: the invoice, the delivery note and the payment voucher for one purchase all sit together on the same line.

Nothing is ever blocked for want of a document. You asked for report-and-warn, not enforcement, and that is exactly what this is — you can still book any payment with no paperwork at all. The difference is that afterwards you can see it.

## The four numbers at the top

- **without a document** — how many payments carry no evidence
- **value with no evidence** — what those payments are worth, in USD
- **documented** — how many are covered
- **documents in the archive** — the size of the archive itself

These are counted across **every** transaction in the range you have selected, not across the rows shown below them. The list underneath is capped at 300 and sorted biggest-value-first, because that is the order an auditor works in. When the list is a subset, the screen says so in as many words. A summary that quietly described only the first page while calling itself the total would be worse than no summary — you would think you were nearly done when you were not.

Filters: missing-only or everything, a minimum USD value, and a date range. Voided entries never appear at all: a voided payment did not happen, so it needs no receipt.

## What was actually there before

Two of your tables — `order_costs` and `customer_payments` — have had a `receipt_path` column since July. The Trade Cashbook never once wrote to it. Where files *were* accepted, in three other upload endpoints, there was **no size limit, no file-type check, and no record of who uploaded what**. And every uploaded file was served from `/storage/…`, which is raw web-server file serving with **no tenant check at all** — a path from one business's receipt would have opened just as happily for another's.

So this is not a switch that was off. It is the mechanism, built once, properly, and used everywhere from here on.

## Why an auditor can trust it

**Every file is fingerprinted on the way in.** A SHA-256 of the exact bytes is recorded with the row. `php artisan evidence:verify --tenant=17` re-reads every document and re-checks it against that fingerprint, and names any file whose contents no longer match, or that has gone missing from storage. That is the difference between an archive you can stand behind and a folder of images you hope are the right ones.

**The path is derived from the bytes, never from the filename.** A file lands at `evidence/{tenant}/{sha256}.jpg`. Photograph the same receipt twice and it occupies one file and two rows. A file cannot be swapped for different content at the same path, because different content produces a different path.

**Documents are never served from `/storage/`.** They stream through an authenticated route that looks the document up *inside your own tenant's rows* — no path is ever taken from the request, so there is no id to guess that reaches another business's receipts. It also survives the storage symlink this host drops on redeploy, which is the recurring cause of "my images disappeared".

**Removing a document does not delete it.** It records who removed it, when, and why, and keeps the row and the file. The trail still shows that a document was once attached and then taken off — which is the point of a trail. Attach and detach are both written to the audit log, same as every void in the system.

**Only photos and PDFs, maximum 20 MB.** A `.exe`, a `.zip`, an `.svg`, a `shell.jpg.php` — each refused by name, and nothing lands on disk when one is refused.

## Your receipts are now backed up

They were not. `db:backup` dumped Postgres and nothing else, so every uploaded image lived in exactly one place. Losing that volume — which is how the images vanished before — would have taken the entire evidence archive with it, and an archive that exists in one copy is not an archive.

```
php artisan db:backup --files --disk=s3
```

`--files` archives `storage/app/public` to `files-{date}.tar.gz` beside the database dump, and uploads it offsite too when `--disk` is set. It runs in its own method and never fails the backup: a good database dump with no file archive is far better than no backup because tar hit one unreadable file.

**Add `--files` to whatever schedule already runs your backup.**

## The receipts you already uploaded

```
php artisan evidence:verify --tenant=17 --import-legacy
```

Adopts every existing `receipt_path` file into the archive as proper evidence, with a note saying it predates this feature. Without it those payments would show as "no evidence" forever, and the gap report — the whole point — would be wrong from its first day. It also tells you how many rows point at a file that is no longer in storage; those were lost before today.

## From the terminal

```
php artisan evidence:verify --tenant=17                  every file still hashes correctly?
php artisan evidence:verify --tenant=17 --gaps           what has no document, biggest first
php artisan evidence:verify --tenant=17 --import-legacy  adopt the old receipts
```

## After you deploy

1. **`php artisan migrate`** — creates the `attachments` table. Until you run it the screen says so plainly rather than erroring.
2. Open **Finance → Evidence** and look at the four numbers.
3. Run `evidence:verify --tenant=17 --import-legacy` to pull in the old receipts.
4. Add `--files` to your backup schedule.

## Worth knowing

`papi/*` is CSRF-exempt platform-wide — it has been since long before this drop, and every panel screen is in the same position. For this screen it means someone who already had you signed in could, in principle, cause a document to be *added*. They cannot read one: reads are gated on an owner session and scoped to your tenant. Changing that exemption touches every panel endpoint at once and does not belong in a receipts drop, but you should know it is there.

## Verified before ship

**134 behaviour checks** against the real classes, plus **46 checks on the shipped source**, plus **five deliberately broken copies** to prove the tests would actually catch a regression.

The five sabotaged builds: one filing files under the client's filename instead of the hash (5 failures), one accepting any file type (13), one that really deletes on detach (1), one that forgets to exclude voided entries from the gap report (12), and one that computes the headline totals from the visible page instead of the whole set (2). Every sabotage was caught. A test suite that stays green when you break the code is decoration.

Covered: the fingerprint matches the bytes; a swapped file, a lost file and a size that disagrees with its row are each caught by name; the same receipt twice writes one file and two rows; three documents of three kinds on one purchase; another tenant sees none of them; eleven kinds of refusal each with a plain-language reason and **nothing written to disk**; a file of exactly 20 MB is accepted and 20 MB + 1 byte is refused without claiming to be exactly the limit; `../../../../etc/passwd.jpg`, a quote and a newline in a filename cannot escape the path or the download header; an unknown document kind becomes "Other" rather than free text; all seven allowed extensions in upper and lower case; detach keeps the file, the row and the audit entry; and the gap report proven to exclude voided entries, exclude money-in, exclude other tenants, total correctly across five undocumented payments, flag the ones with a pre-v4.33 receipt file, read `customer_payments.amount` (the column that exists, not the one that does not), and keep its totals honest when the list is capped.

The screen itself was rendered and its JavaScript syntax-checked — a PHP heredoc typo here ships as a blank page — then driven in a real browser with no console errors.

`php -l` on 8 PHP files · `node --check` on shell.js and on the page · both copies of shell.js byte-identical (`9d5c613…`) · v4.32's 65, v4.31.4's 22, v4.31.2's 21, v4.31.1's 35 and 26, v4.30's 61 and 26, v4.29's 97, v4.28's 89 and v4.27.1's 36 all re-run and still green.
