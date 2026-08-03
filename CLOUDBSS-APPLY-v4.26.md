# CloudBSS v4.26 — repaying the director loan now actually reduces the interest

**Date:** 2026-08-03 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.26.zip (cumulative — supersedes v4.25; 142 files) · **Migration:** none new · **Delta: 5 files.**

## What was wrong

Three things, found while checking the $2,482.93 owed to Bhavin.

**1. Repaying never reduced the interest.** Interest was recalculated on every page load from each ORIGINAL advance — `principal × 1.03^(days/30)` — forever. Repayments were only subtracted from the total owed; they never stopped the clock. Paying $200 lowered the balance by $200 and changed next month's interest by nothing.

**2. The loan terms were hardcoded.** `const RATE = 0.03`, `LENDER = /bhavin/i` and `STOCK_USD = 3798.33` were literals inside the Books report file. No owner could see or change their own interest rate. There *are* settings for these (`books.loan_rate`, `books.loan_holders`, `books.stock_usd`) but they feed `BooksCapability`, which is never registered in the capability registry — dead code, and why `/api/v1/workspace/books` returns 404.

**3. Repayments could go uncounted.** The page tested the payer against a `holder` field, but `/papi/trade-cashbook/all` returns the person under `person` — there is no `holder` key in that payload. So a repayment only counted if the *note* happened to contain the lender's name. Money paid back by Bhavin, recorded against Bhavin, with the note "loan repayment", was invisible to the loan balance.

## What v4.26 does

**Real amortisation.** Advances and repayments now go onto one timeline in date order, and the outstanding balance compounds between events. A repayment therefore stops interest on the money returned — which is the entire point of repaying. Each repayment clears accrued interest first, then principal (normal practice), so the note no longer has to say "interest" or "principal"; it only has to identify the payment as a loan repayment to the lender.

**Your balance does not move on deploy.** With no repayments recorded, the two models agree to the cent — every dollar still compounds from its own advance date. Verified against your real four advances: **$2,482.93 before, $2,482.93 after.** The day count keeps the old whole-calendar-day convention for the same reason.

**Settings → Currency & rates → Director loan.** Lender name(s) (comma-separated, so more than one person can lend), interest per month as a fraction, and the inventory value. The rate help spells out that `0.03` is 3% *per month* ≈ 42.6% a year, and that `0.0025` is 3% a year — the mistake worth catching. A tenant who has never set the stock value still sees the previous $3,798.33, so no balance sheet silently loses assets.

**The payer field is read properly**, so a repayment recorded against the lender counts even when the note omits their name.

## What this means for the $200

Balance $2,482.93 today. Repay $200 and it becomes $2,282.93 — and next month's interest falls from $74.49 to $68.49. Small, but it is now real, and it compounds: settling the $2,105.43 of company cash Bhavin already holds would take the balance to about $377 and next month's interest from **$74.49 to $11.33**.

Record it in Cashbook → Record activity → money out, USD, note **"Loan repayment to Bhavin"**. One entry is enough now; the split between interest and principal is worked out for you.

## Verified before ship

13-check model harness run against your four real advances (700 / 200 / 38 / 1,500, July 8–20): principal $2,438.00, interest $44.93, **owed $2,482.93 — identical to the old model and to your screen**; a $200 repayment allocates $44.93 to interest then $155.07 to principal and saves $6.00 of interest over the next 30 days; settling $2,105.43 cuts a month's interest from $74.49 to $11.33.

Two real bugs the harness caught and that are fixed here: fractional-day accrual would have nudged the balance to $2,485.31 on deploy (now whole days, so it lands exactly on the old figure), and an overpayment booked $2,482.93 of "principal repaid" against $2,438.00 ever borrowed, because the interest portion was not taken off the balance before the principal share was sized. Edge cases covered: overpayment cannot drive the balance negative, a repayment dated before any advance is harmless, no advances at all yields zero rather than NaN, and a repayment on the same day as an advance nets correctly. The shipped page code was extracted and executed directly against the fixture, not re-typed. Config fallbacks checked: no injection at all still renders, an out-of-range rate falls back to 3%/month, and multiple comma-separated lenders match correctly while non-lenders do not. `php -l` ×3 · page script parsed · zip md5-verified (142 files).
