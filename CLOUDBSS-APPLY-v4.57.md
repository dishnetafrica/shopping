# v4.57 — Quote to cash

**No migration.** Unzip, `php artisan optimize:clear`. Needs v4.55/v4.56 applied first (shared
`Growth` namespace).

---

## You were right

The Quotations screen has always shown a **Conversion rate**. It counts quotations that became
**orders**, and it stops there. Nothing in the system asked the next question.

So a quote that became an order nobody ever invoiced counts as a win. So does one that was invoiced
and never collected. Both read as success on that screen and as nothing at all in the bank.

The chain to answer it properly already existed — `quotations.order_id` → `orders` →
`invoices.order_id` → `amount_paid`. Nothing had ever walked it.

---

## What the screen shows now

Beside the old number, unchanged in meaning and honestly relabelled **"Turned into orders"**:

- **Actually paid** — of everything you quoted, what share was collected. Measured in **value, not
  count**: winning nine quotes of USD 50 and losing one of USD 5,000 is a 90% win rate and a bad
  month, and only one of those two numbers says so.
- **Won but not collected** — agreed business still sitting somewhere, in money, with how many
  quotations and how many of those were **never invoiced**.

And every row gains an **Outcome** column reading what actually happened to the money: *Sent, no
reply · Opened, no reply · Agreed, no order yet · Ordered, never invoiced · Invoiced, unpaid · Part
paid · Paid · Declined · Expired*.

**"Ordered, never invoiced" is the one worth looking at first.** Nothing else in the system can show
it. Collections reads invoices, and for these there is no invoice to read — so nobody is chasing
them, and on every other screen they already look finished.

---

## Two decisions worth knowing

**The money overrules the status field.** Once an order exists, a quotation's own `status` stops
being maintained — nobody goes back to mark it paid. So past the order line the stage is read from
the invoice and the payments, not from the quote. The exception is a customer saying no: declined
and expired are final whatever else happened afterwards.

**A rounding shortfall counts as paid.** Thirty cents short is paid. Nobody chases thirty cents, and
a list that says otherwise gets ignored within a week. A real shortfall still shows as part paid.

---

## Files

| File | |
|---|---|
| `app/Services/Growth/QuoteToCash.php` | **new** — the staging and the roll-up. Pure |
| `app/Capability/Process/QuotationListCapability.php` | reads the invoices, adds the column and the two numbers |

The old conversion figure was **relabelled, not redefined**. Quietly changing what a number means
under someone who has been reading it for months is worse than leaving it alone, so it still counts
orders and now says so.

---

## Checks that ran

```
t457/quotecash.php   31 passed, 0 failed
t457/mutants.sh      17 killed, 0 survived
t457/contracts.sh    19 passed, 0 failed
t451–t456            all still green
```

---

## What to expect when you open it

With 120 quotations you should see the funnel spread across every stage. **The number I would look
at first is "never invoiced"** — if that is anything other than zero, it is money you have already
won and nobody is collecting, and it has been invisible until tonight.

If the *Actually paid* figure looks far lower than you expect, check a few rows before believing it:
a quote whose order was invoiced under a different order number would show as never invoiced, and
that would be a linking problem rather than a collection one. Send me two or three quote numbers and
I will trace them.
