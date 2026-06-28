# Quotation CRUD + the "PDF didn't send" fix (Krishna/EuroPearl, 28 Jun)

Two issues from production. The USD pricing fix worked (bot quoted ~$3,805); these are what's left.

## A. Quotation CRUD in the panel
The list endpoint returned only totals, so you couldn't see what was inside a quote or edit it.
The `Quotation` + `QuotationItem` models and send/resend/status/convert already existed — the gap was
**read-with-items, update, delete**. Added (all tenant-scoped, under `/papi`):

- **`GET quotation-show?id=`** — returns the quote header **plus its line items** (name, qty, unit
  price, line total). This is "see what's inside".
- **`POST quotation-update`** — edit `items` (`[{name,qty,unit_price}]`), `name`, `phone`, `currency`.
  Recomputes the total, replaces the line items, and **rebuilds the PDF keeping the same quote
  number** (`generate()` gained an optional quote-number argument). Blocked once a quote is converted.
- **`POST quotation-delete`** — removes the quote, its items, and the stored PDF. Blocked once
  converted (it's an order then — delete the order instead).
- **Create** already exists (`quotation-send`); **resend / status / convert** unchanged.

> The panel is an SPA, so these are the API endpoints — the front-end needs buttons wired to
> `quotation-show` (open), `quotation-update` (save edits), `quotation-delete` (remove). The backend
> CRUD, validation, PDF rebuild, and tenant isolation are done here.

## B. Why the PDF didn't send
Most likely cause: the model **announced** the quote in prose ("preparing your quotation 📄") without
emitting the hidden `<<QUOTE>>` line — and the PDF is only generated when that line is present. It also
computed a USD figure itself in prose ("≈ $3,805") instead of letting the system price it. Both are
prompt-discipline failures, so the prompt now states, as a hard rule: when the customer confirms a
formal quotation you MUST emit `<<QUOTE>>` in that same reply (saying "preparing" does nothing), and
never compute currency yourself — emit `<<QUOTE>>` and the system prices it (USD when relevant).

CRUD is also the durable safety net: if the auto-send ever flakes, you open the quote in the panel,
check/fix the lines, and resend — no lost quotation.

> I can't fully confirm the exact cause without the bot logs for that turn (whether `<<QUOTE>>` was
> emitted, or `generate()`/dompdf failed on this tenant). The prompt rule addresses the most likely
> cause; if it recurs, the logs will show which.

## Verification
`QuotationCrudTest`: show returns items; update recomputes (200→100 qty ⇒ total 3800→1900) and
replaces items; delete removes quote+items; a converted quote rejects edit/delete (422); cross-tenant
read is 404. Run `php artisan test` in your environment to exercise the DB + auth.
