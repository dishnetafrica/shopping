# INVOICE-BULK-DEPLOY.md — UCRM-style bulk select + export on Invoices

## What you get (V3 → Invoices)
- **Checkbox on every row** + a header checkbox for select-all-on-page; selection
  survives search/filter/sort/paging on the screen
- When anything is selected, a **bulk bar** appears:
  **[Export CSV] [Export PDF] [Send on WhatsApp] [Clear]** with the live count
- **Export CSV** — 12 columns, UTF-8 BOM (Arabic customer names open correctly in
  Excel); no selection needed logic: exports exactly the checked rows
- **Export PDF** — landscape A4 list with totals row, branded header
- **Send on WhatsApp** — reuses the proven single-invoice send per id (invoice PDF
  to the customer's number), capped at 25/run, paced 0.4s apart, reports
  "Sent N of M" with per-invoice failures named

## The infrastructure gift
Selection + bulk bar is a GENERIC shell feature: any capability can now opt in with
`'selectable' => true, 'bulk' => [...]` in its table opts — Orders, Quotations, and
Payments get this for one line each whenever you want.

## Bonus: a pre-existing bug found and fixed
The import audit caught `use App\Models\MessageLog` in InvoiceController — a class
that doesn't exist (the real one is App\Support\MessageLog), and it's USED twice in
send(). Single invoice-send has been fataling at the transcript-logging step all
along. Fixed — so this zip doesn't just add bulk send, it makes SINGLE send work.

## Files (4) · Deploy
shell.blade.php (generic multi-select — supersedes previous shell) ·
InvoiceListCapability · InvoiceController (+export/+bulkSend) · routes —
upload → Rebuild → `php artisan deploy:check` → hard-refresh /v3 → Invoices →
tick 3 rows → Export CSV downloads instantly → Export PDF → then tick one test
invoice → Send on WhatsApp.
