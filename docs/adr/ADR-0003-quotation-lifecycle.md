# ADR-0003 — Quotation lifecycle & observability

**Status:** Partially built (observability + safety), rest design-only. Respects the freeze: only a
correctness/observability change and a cheap safety change are built; the lifecycle expansions are
documented, not implemented.

## Context
A real failure ("preparing your quotation…" then no PDF) could not be diagnosed, because the quote →
PDF → store → send path was silent. Separately, quotations are edited and deleted with no history,
which is risky for a sales document. The right direction is to make quotations **observable** and
**safe to work on**, not to bolt on features.

## Built now
1. **Pipeline observability (`App\Support\QuotationPipeline`).** Every stage of the bot's quote path
   emits a structured log line + a queryable `BotTrace` event: `marker_missing`, `pdf_unavailable`,
   `gateway_no_send`, `no_items_in_marker`, `no_match`, `dedup_skip`, `pdf_generate` (ok/fail),
   `wa_sent` (ok/fail). The next failure names its stage instead of being guessed. The send is now
   wrapped so a WhatsApp failure can't lose an already-generated quotation. The panel edit path logs
   `pdf_generate`/`pdf_unavailable` too.
2. **Safety (cheap, from the CRUD review):** edits are blocked on `accepted` and `converted`
   quotations (read-only); **delete archives by default** (`status = archived`, record + PDF kept for
   reporting), with hard delete only on `force=1`. Archived quotes are hidden from the default list.

## Designed, NOT built (each needs its own evidence)
- **Revisions instead of overwrite (#2).** Today an edit regenerates the PDF under the same number.
  The durable answer is `revision` numbers (`Q-…-0015` rev 1→2→3) with the customer always seeing the
  latest and the diff retained for disputes. Deferred — the pipeline trace + archive give us audit
  safety now; full versioning is a model change to make when edit-after-send is actually common.
- **Lifecycle states (#1, #8).** `draft → sent → viewed → delivered → accepted → rejected → expired →
  converted`, with `viewed`/`delivered` driven by WhatsApp receipts (`MessageReceipt`) and timestamps
  (`viewed_at`, `accepted_at`). These become sales metrics (sent/delivered/viewed/accepted funnel).
  Deferred — needs delivery-receipt wiring and is a capability, not a fix.
- **Quote timeline (#4).** "What happened to this quotation?" is exactly an Operational Timeline
  (ADR-0001) projection with `entity_type = quotation` and `correlation_id = quote_no` — created,
  edited, generated, sent, viewed, accepted, converted. Build it when the timeline graduates from ADR
  to implementation; the pipeline events above are already shaped to feed it.
- **Retry queue (#6).** If `pdf_generate` failures turn out to be transient (memory/fonts), a retry
  queue keeps the quote and re-attempts. Build it **only if the new `pdf_generate FAIL` traces show
  transient failures are real** — not preemptively.
- **Totals breakdown (#3).** subtotal / discount / tax / shipping / grand total. Discount and tax are
  business rules not yet defined; add when the rules exist, not as free-form fields.

## Why this split
The one thing with production evidence (an undiagnosable failure) is built. One cheap safety win
(archive + lock) is built. Everything else is reserved with the trigger that would justify it — the
retry queue, notably, now has a concrete data source (`pdf_generate FAIL` traces) that will say
whether it's needed.
