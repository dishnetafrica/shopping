# v4.53 — Credit on account

**No migration.** Unzip over the repo, `php artisan optimize:clear`, done. It uses the
`kind` / `applied` columns the payments table has always had.

> Ships on top of v4.52. Apply that one first — this zip contains the v4.52 versions of
> `StatementService`, `InvoiceRenderer`, `CoreCapabilityProvider` and `help-center.html` with the
> v4.53 changes on top, so applying both in order is safe either way.

---

## The case

A customer with a USD 170 bill hands over USD 200 and says keep the rest.

**Before:** the USD 200 went into the cash book correctly, the invoice closed as paid, and the USD 30
stopped existing. Invoice balances are clamped at zero — `max(0, total - paid)` — so it could not
show as negative, and nothing else held it. Two of your own screens then disagreed: the on-screen
Account statement netted it to −30, while the PDF the customer actually receives printed *"Total
outstanding: Nil"*.

**Now:** the receipt is recorded exactly as handed over. USD 170 settles the invoice, USD 30 is held
as credit, and it appears on the customer's record, on both statements, and on their WhatsApp
receipt. Next time they have an invoice with a balance, one button puts it against that invoice.

---

## What changed

**The overpayment is split in the one recorder**, so the till, the invoice screen, Customer 360,
staff cash and the bot all get it without knowing about it. The settlement leg is capped at what is
actually due; the remainder is written as a held advance in the same transaction. No payment is ever
refused for being too large — money that arrived must be recorded, and a customer standing at the
counter is not the moment for a validation error.

**Rule R3 is finally true.** It has said *"any excess must become an advance/credit, never a negative
balance"* since it was written, and was never enforced. It used to be called with no balance at all,
so `amount <= balance` was `amount <= 0` and every ordinary payment logged a violation nobody read.
It is now satisfied by construction rather than by rejection, and it gets a real number to record.

**A settlement that named nothing now gets something to settle.** Record payment on a *customer*
sends no invoice number and no order number — nobody has picked one at that moment. That row used to
land attached to nothing: it reduced no balance and counted as no credit. It now goes against the
oldest open invoice, and a customer with nothing open is recorded as paying money onto their account.
This was a pre-existing hole, and it is the more common way your team takes money.

**One handover of money is still one cash entry.** A split writes two payment rows against one
`OrderCost`. Applying credit later writes *no* cash row at all — it re-tags an existing payment from
held to settled. That is the single most important property in the drop: a version that wrote a cash
row on apply would inflate the cash book by the applied amount, every time.

**The customer is told the truth.** A split receipt states the full USD 200 they handed over — not
the USD 170 leg — then says the USD 30 is held and will come off their next invoice.

---

## Files

| File | |
|---|---|
| `app/Services/Money/CreditOnAccount.php` | **new** — what credit is, and the arithmetic that creates and spends it |
| `app/Services/Money/PaymentService.php` | splits an overpayment; aims an untargeted settlement; feeds R3 a real balance |
| `app/Services/Invoicing/StatementService.php` | held credit as its own statement line |
| `app/Services/Invoicing/InvoiceRenderer.php` | prints it as *Credit on account*, not *Credit note* |
| `app/Services/Finance/CustomerBillingNotifier.php` | the receipt states the full handover and the held part |
| `app/Capability/Object/CustomerCapability.php` | *Credit we hold* tile, a card showing where it came from, and a better next action |
| `app/Capability/Object/InvoiceCapability.php` | the **Use credit** button and its handler |
| `app/Capability/Registry/CoreCapabilityProvider.php` | declares `apply_credit` |
| `resources/panel/help-center.html` | a card explaining it to the team |

---

## Deliberate limits

**Credit is held in the currency it was received in and only spent on invoices in that currency.**
Spending UGX credit on a USD invoice is an exchange decision with a rate and a date and somebody's
judgement in it. The button will not make one silently; it says what is held and why it cannot be
used here.

**Applying credit is a human action, never automatic.** Held credit does not silently attach itself
to the next invoice raised. Somebody presses the button.

**Oldest credit is spent first**, and a partly-spent row splits so the remainder stays held.

---

## Checks that ran

```
t453/credit.php      37 passed, 0 failed
t453/mutants.sh      16 killed, 0 survived
t453/contracts.sh    63 passed, 0 failed
t452/*               all green, unchanged
t451/detail.php      19 passed, 0 failed
```

Three mutants in `plan()` came back **equivalent** — they changed no behaviour, because the guards
they targeted overlap with `min($amt, $due)` and with the negative-due clamp. That is a fine thing
for money code to be and a useless thing to mutate, so the behaviour is asserted directly instead.
It is written down in `mutants.sh` rather than quietly dropped.

---

## What is NOT proven

**Nothing here has touched a database.** The arithmetic is tested hard and the wiring is asserted by
reading the source. The parts that need a real row — the two-legged receipt, the re-tagging on apply,
the partial split — are proven by construction and review, not by execution. The verification steps
for this drop matter more than usual, and the cash-book reconciliation is the one that would catch
the worst possible bug.

**dompdf still has never run here**, so the new *Credit on account* line on the printed statement is
markup I am confident in rather than a page I have seen.
