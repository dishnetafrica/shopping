# Quotation observability + safety (v2)

Response to the CRUD review. You were right that "preparing… no PDF" doesn't prove the prompt failed —
so the fix isn't another prompt guess, it's instrumentation. Built the one evidence-justified thing
plus one cheap safety win; the lifecycle expansions are documented (ADR-0003), not built.

## Built
1. **Pipeline observability (`QuotationPipeline`).** Every stage of the quote→PDF→send path now emits a
   structured log line **and** a queryable `BotTrace` event:
   `marker_missing` · `pdf_unavailable` · `gateway_no_send` · `no_items_in_marker` · `no_match` ·
   `dedup_skip` · `pdf_generate` (ok/fail) · `wa_sent` (ok/fail). The next time a PDF doesn't arrive,
   the trace says exactly which stage failed — including the two prime suspects (the model not emitting
   `<<QUOTE>>`, and the PDF engine being unavailable). The send is wrapped, so a WhatsApp failure can't
   lose an already-generated quote. The panel edit path is instrumented too.
2. **Safety (from your #7).** `accepted` and `converted` quotes are **read-only** (edit → 422). Delete
   **archives by default** (record + PDF kept for reporting); hard delete only with `force=1`. Archived
   quotes drop out of the default list (`include_archived=1` to see them).

## Designed, not built (ADR-0003)
Revisions instead of overwrite (#2); full lifecycle states `viewed/delivered/accepted` + funnel
metrics (#1, #8); the quote timeline as an ADR-0001 projection (#4); a PDF retry queue (#6); and a
totals breakdown with discount/tax/shipping (#3). Each is reserved with the trigger that justifies it
— notably the **retry queue now has a real signal** (`pdf_generate FAIL` traces) that will tell us if
transient failures actually happen.

## Verification
`QuotationCrudTest`: show returns items; update recomputes (200→100 ⇒ 3800→1900) and replaces items;
delete archives by default (row kept), force=1 hard-deletes; accepted and converted are read-only;
cross-tenant read is 404. Run `php artisan test` to exercise DB + auth. Pipeline traces appear in logs
and BotTrace once a real quote runs.
