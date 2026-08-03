# CloudBSS v4.25 — the customer finally hears from you about money

**Date:** 2026-08-03 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.25.zip (cumulative — supersedes v4.24; 141 files) · **Migration:** none new · **Delta: 7 files.**

## What was missing

Your side of billing was complete: numbered invoices, `amount_paid` and `balance` kept in step with the payment ledger, a receipt PDF for every payment. The **customer's** side was silent. You invoiced them and nothing was sent. They paid you and nothing was sent. Every "how much do I still owe?" phone call came from that gap.

## Two moments, two messages — both with the PDF *and* the numbers in the text

**1. You issue an invoice → they get it immediately.** Every route that issues a numbered invoice (panel, order button, auto-issue on delivery) now WhatsApps the customer the invoice PDF with a plain summary:

> 🧾 *Invoice INV-2026-0042* — Family Shoppers
>
> Hello Grace,
> Here is your invoice for Monthly grocery supply.
>
> 📦 Items: 12
> 💵 Invoice total: *UGX 1,250,000*
> 📅 Due by: 15 Aug 2026
>
> 💳 How to pay:
> Mobile Money: 0770 123 456 (Family Shoppers)
>
> The full invoice is attached. Any question, just reply to this message 🙏

Anything already paid appears as **Already paid** and **Balance to pay**. "How to pay" comes from your existing bank-details setting. Sent once per invoice — re-saving never spams them.

**2. You receive money → they get a receipt with the running balance.** This is the part that answers *"what did I pay for, and what is still open?"*:

> ✅ *Payment received* — thank you!
>
> Hello Grace,
> We have received *UGX 500,000* by Mobile Money.
> 🧾 Receipt no: RCP-00871
> 🔖 Reference: MP2608.1234
>
> *Against invoice INV-2026-0042*
> • Invoice total: UGX 1,250,000
> • Paid so far: UGX 800,000
> • ⏳ Balance still due: *UGX 450,000*
> • 📅 Due by: 15 Aug 2026
>
> Your receipt is attached.

Three numbers, deliberately: **what they just paid**, **what they have paid in total**, **what is still due** — so nobody has to add up old receipts. When the last payment lands it says *"🎉 This invoice is now fully paid. Nothing more is due."* instead. An advance taken before any invoice says so honestly and explains it will be set against the invoice later, rather than inventing a balance.

The full text goes as a WhatsApp message and the PDF follows as an attachment, so the customer reads the numbers even without opening the file — which is how most people read WhatsApp on a phone.

## Switches

**Settings → Notifications → Customer billing messages**: *Send the invoice when it is issued* and *Send a receipt when payment is received*, both ON by default. Messages go to the phone number on the invoice (falling back to the order's) or on the payment. No phone, no message — and the reason is traced rather than silently swallowed.

## Safety

Both messages are queued jobs dispatched **after** the money is recorded and the balance reconciled. An invoice still issues and a payment still records if WhatsApp is unreachable — you lose the courtesy message, never the record. A duplicate payment replay (the offline-queue case) sends nothing a second time.

## Verified before ship

38-check harness on the exact wording: greets by first name only; UGX prints with no decimals and USD with two; an unpaid invoice shows no "already paid" line while a part-paid one shows both paid and balance in bold; the due date, item count and how-to-pay all appear; **the part-payment receipt carries all three numbers — paid now, paid so far, balance still due — and never claims the invoice is settled**; the final payment switches to "fully paid" and drops the balance line; a null reference prints no empty line; payment methods read as words ("Mobile Money", "cash") not codes; an advance with no invoice references the order and invents no balance. Guards: both switches, a void invoice, a second send of the same invoice, and a missing phone on either side each return a clear reason without throwing. `php -l` ×6 · v4.24's 33-check harness re-run clean · zip md5-verified (141 files).
