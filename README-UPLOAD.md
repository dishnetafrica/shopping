# v4.90 — owner drawings, loans, cashbook descriptions, and customer advances

**Four files. Every change is additive or an in-place field change — no migration, no
composer change, no settings change, nothing removed.**

```
app/Http/Controllers/Panel/TradeCashbookController.php   +20 lines  (drawing + loan stages)
app/Capability/Process/CashbookCapability.php            +24 lines  (2 tiles, + received_by)
app/Services/Finance/CashbookExport.php                  Description column, PDF + CSV
app/Capability/Object/CustomerCapability.php             +6 lines   (Record payment always shown)
```

Upload to GitHub, then deploy on EasyPanel.

---

## ⚠ READ THIS BEFORE UPLOADING

Built from the **v4.58** tree you sent. Your production is newer. If any of these four files
has changed since 7 August, uploading my copy would **revert those changes** — which is how
V3 got blanked on 31 July.

**Verify first.** Open each on GitHub and confirm the anchor line is there:

**1. `TradeCashbookController.php`** (~209):
```php
        $payCats = ['supplier payment','supplier payment (on account)','on account','account payment'];
```

**2. `CashbookCapability.php`** (~385):
```php
        $tiles[] = ['icon' => 'send', 'title' => 'Paid an expense / someone', 'sub' => 'Transport, salary, rent — to a person or shop', 'tone' => 'out', 'action' => $evExpense];
```

**3. `CashbookExport.php`** (~233):
```php
            $line(['Date', 'Ref', 'Category', 'Held by', 'In', 'Out', 'Balance']);
```

**4. `CustomerCapability.php`** (~142):
```php
        $primary = $balance > 0 ? $payAction : $newQuote;
```

All present → upload. Any file different → **stop and send me the current version.**

---

## 1 · Owner drawing and Borrowed a loan

`$expCats` already offered a **`Drawing`** category, but `store()` never mapped it to a
stage, so it posted a plain `cash_out` — bucketed into `other_out` and **subtracted from
operating profit**. Owner pay was booked as an expense: **$18,000/year** understated across
three owners. The category existed; the mapping never did.

Two new tiles in Record activity: **Owner drawing** (cash −, profit unchanged, equity −) and
**Borrowed a loan** (booked as `funding`). Neither asks for a category — it rides in the body.

- The drawing tile writes only the `order_costs` row, so **Partners capital accounts do not
  move.** Use Finance → Partners → − Drawing when the capital account matters; the tile when
  the **payer** matters.
- The loan tile needs `books.loan_holders` to name the lender, or the money reads as equity
  rather than debt.

## 2 · Description column on cashbook exports

A single-currency export showed `DATE · REF · CATEGORY · HELD BY · IN · OUT · BALANCE` — no
description, so a row read "PUR-0043 · Purchase · 258,000 UGX" and you could not tell what
was bought. "Held by" printed the same name on every row, because the export was already
filtered to one person.

The note was already loaded (line 103) and the **company** view already printed it; only the
per-currency view didn't. Now **Description replaces Held by when scoped to one person**, and
is *added* alongside it when exporting everybody — so the single-person column count and
`colspan` are unchanged. **PDF and CSV** both.

## 3 · Whose cash a customer payment lands in

The Cashbook's **Customer paid** tile asked only order, amount and note. So a payment taken
there silently landed in whoever was logged in — while the customer screen has asked
"Received by (whose cash)" all along.

`PanelApiController::recordPayment()` has always accepted `received_by` (and `holder`); the
form never asked. It does now, prefilled to you.

## 4 · Record payment is always reachable on a customer

```php
$primary = $balance > 0 ? $payAction : $newQuote;
```

A customer who owes **nothing** is exactly the one who pays in advance — and that was the one
case where Record payment was demoted out of the header into the rail. It is now always in
the header: leading when there is a balance, beside New quotation when there is not.

**Advances need no special handling.** `CustomerCapability::recordPayment()` passes
`invoice_no => null`, and per the v4.53 credit-on-account work a customer with nothing open
is recorded as paying onto their account — held as `kind = 'advance'`, `applied = false`.
Spend it later from the invoice's **Apply credit** action (oldest first, writes no cash row).

Note the deliberate limit: **credit is held and spent in the currency it was received in.**

## 5 · Cancelled orders were counted as money customers owe

```php
Order::where('tenant_id', $t)->whereColumn('amount_paid', '<', 'total')->limit(200)
```

**No status filter.** `Order::STATUSES` includes `Cancelled` and `Rejected`, and both were
counted as receivables — which feed the **Working capital** headline on the Cashbook.

Measured on tenant 17: of $42,863 counted as owed, **$13,882 (32%) was Cancelled orders**
across 23 of them. Working capital read $39,366 against $4,017 of actual cash.

Now excludes `Cancelled` and `Rejected`. The bare `limit(200)` also became
`MAX_OPEN_ORDERS = 2000` — at 200 the total silently stopped growing once a tenant passed
that many unpaid orders, with nothing on screen to say so. (104 today, so it was not yet
biting.)

**Still open, and not fixed here:** 63 orders marked **Delivered** carry $20,912 unpaid.
Goods went out, so either that is real debt or the payments never attached — see the note
below about the v4.53 payment-linking bug. Do not treat the corrected working-capital figure
as clean until that is resolved.

## Honest limit

**I could not run your test suite.** Packagist is blocked from my environment — no `vendor/`,
no PHPUnit, not one line executed. `php -l` passes on all four files and every diff was
checked line by line. That is not the same as tested.

## Test after deploying

1. Record activity → **Owner drawing** → $1. Books: cash −$1, **operating profit unchanged.**
2. Record activity → **Borrowed a loan** → $1 under a name in `books.loan_holders`. Loan
   panel rises $1, equity does not.
3. Cash by person → ⤓ → a person's cash book PDF: notes present, "Held by" gone.
4. Export the **all-people** book: "Held by" must still be there.
5. Record activity → **Customer paid**: a "Received by (whose cash)" picker appears.
6. Open a customer with a **zero** balance: **Record payment** sits beside New quotation.
7. Record an advance from them, then check Customers → their statement shows the credit.

## Rollback

Re-upload the previous version of any file. No schema, no data, no settings touched.
