# CloudBSS v4.36 — the Books report says what it means

**Date:** 2026-08-04 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.36.zip (cumulative — supersedes v4.35; 184 files) · **Migration:** none · **Delta: 2 files.**

Every sum on the Books screen added up. I checked all nine of them against your live figures and did not find one arithmetic error. What was wrong was what the numbers were *called*, and one tick mark that could never fail.

## The ✓ that could not fail

The caption read *"The two columns match — the books balance ✓"*. Owners' equity was not calculated; it was **assets minus liabilities**. So the check was `assets − (liabilities + (assets − liabilities)) < 0.5`, which is `0 < 0.5`. **True always, for any inputs, however wrong.** If stock had been out by ten thousand dollars the columns would still have matched and the tick would still have appeared.

Equity is now computed from what actually produced it — capital you put in, less drawings, plus every dollar of profit ever kept — and the balance sheet shows the two side by side:

> Capital put in · less drawings · profit kept in the business (all time) · **stock, money owed to us, bills not yet paid** · = Owners' equity

That fourth line is the part a cash-basis P&L has never seen: goods on the shelf and money customers owe you are not cash, and unpaid supplier bills are not yet a cost. It was previously absorbed into equity silently. Now it is named, and if it is larger than your profit figure the screen says so in as many words.

**And there is now a check that can actually fail.** It compares the cash on hand against the cash the profit statement accounts for — two figures produced by two different endpoints from two different code paths. When they disagree, a red banner says by how much and what it usually means. That is the check that catches an entry type nobody classified, or one leg of a currency conversion recorded without its pair. The server was already computing it and throwing it away.

## Profit now covers a period

There was no date filter anywhere in the report. "Net profit" meant *since the day you started* while sitting next to a point-in-time balance sheet, and nothing on the screen said so.

Pick **This month** (the new default), Last month, This quarter, This year, or Since inception. The header reads *"Profit for August 2026 · what we own and owe as at 04 Aug 2026"*, so the two halves of the page no longer pretend to cover the same span. Your choice is remembered.

Equity deliberately ignores the selector — retained profit is by definition all of it, so it stays inception-to-date whatever period you are looking at.

## The loan interest line was the wrong quantity

It showed **$2**: the interest accrued but not yet settled. The loan panel beside it said **interest to date $47.18**. Both were correct; only one of them is an expense, and it was the other one.

The P&L is now charged interest **incurred in the period**, and the loan panel gained a *"…of which August 2026"* row so the two tie together in front of you. Interest is attributed by the same compounding curve used for the balance, so the period figures sum exactly to the all-time figure and **nobody's loan balance moves by a cent**.

Repaying principal is also no longer treated as a cost. Loan repayments are classified as financing on the server and kept out of the expense buckets — previously a repayment recorded as ordinary cash-out was deducted from your profit as though the money had been spent.

## A real bug: rejected repayments counted

Every server-side total excludes rows marked `void` **or** `rejected`. The loan block runs in the browser, reads an endpoint that filters nothing, and excluded only `void`.

**A loan repayment you had rejected still reduced your loan balance**, still counted as interest and principal paid, and so overstated profit. Fixed, along with two neighbours: FX conversion and transfer legs are now excluded the way they are everywhere else, and the keyword match is word-bounded so "return" no longer matches inside an unrelated word.

## Labels

**"Transport & other" was not transport.** There is no transport test anywhere in that calculation — it is the entire other-cash-out bucket, every general payment you have ever made. It now says *Other cash out*.

**The unlabelled green sliver on the assets bar was Receivables** — $730 of real money customers owe you, drawn as a nameless strip of colour because the label only rendered on segments taller than 18 pixels and that one was 12. Thin segments now get their label beside the bar instead of inside it, and it reads *Owed to us*.

The loan heading followed a hardcoded "Bhavin" while the KPI beside it used your configured lender setting. Both use the setting now.

## What I did NOT change

**Cost of goods is still cash paid to suppliers, not the cost of what you sold**, and stock is still the manual figure. That means the 42.9% is a cash margin, not a trading margin, and the caption now says *"Cash basis: a sale counts when the money arrives, a cost when it is paid"* rather than leaving you to infer it.

This matters more than it sounds. You are carrying **$6,758.75 of unpaid supplier bills** — those goods are in neither cost of sales nor stock. That is 125% of your reported net profit. Fixing it properly means real inventory: stock tracking from purchases, cost matched to units actually sold. That is a project, not a patch, and it is the honest next thing to build.

## After you deploy

Nothing. Open Books and the period chips are there, defaulting to this month — so **expect the profit figure to drop**, because it is now August's profit rather than every August since you started.

## Verified before ship

**72 behaviour checks** on the shipped code — 39 executing the report's own script against your live 04 Aug figures with stubbed endpoints, 33 on the server's period and repayment classification — plus **33 source checks**, plus **four deliberately broken copies** to prove the tests bite: one that restores the equity plug, one that charges the unpaid balance as interest again, one that lets rejected repayments back in, and one that makes an unrecognised period silently mean all-time. Each was caught.

Confirmed still correct: revenue − cost of goods = gross profit (10,816), the full waterfall to net profit (5,396 → 5,394), gross margin 42.9%, assets = cash + stock + receivables (12,515.64), USD + UGX + SSP = the cash KPI (7,987.31), and the loan's principal + interest − paid = balance (2,281.10).

Also covered: the equity breakdown summing back to its total; the new tie-out check firing on a $250 cash gap and on an unclassified row, and staying quiet on clean data; period interest equalling all-time interest over "since inception" and being strictly smaller for one month; rejected, void, and Conversion legs each dropped from the loan timeline while a genuine repayment survives; an unrelated payment naming the lender not counting; "returned" still matching but "turnover" not; every period boundary including Q3, last-month without the 31st-of-the-month trap, a backwards range swapped rather than emptied, and a malformed or SQL-looking date falling back to this month rather than to everything.

`php -l` on 1 PHP file · `node --check` on the report's script block · v4.35's 46 and 31, v4.34's 39 and 25, v4.33's 134 and 46, v4.32's 65, v4.31.4's 22, v4.31.2's 21, v4.31.1's 35 and 26, v4.30's 61 and 26, v4.29's 97, v4.28's 89 and v4.27.1's 36 all re-run and still green.
