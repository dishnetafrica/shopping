# Project Advisor — Branded PDF export of the proposal

The polish step: a salesperson can now download a professional, tenant-branded **PDF** of any proposal to
send on WhatsApp, email, or print.

**This bundle is the complete Project Advisor (Slices 1–5 + the V3 screen + the `status` fix + this PDF
export)** — upload just this; it supersedes every earlier Advisor zip.

## What this adds

- A **Download PDF** button on the proposal view in V3 (Sales → Solar Advisor → open a project → its
  proposal).
- A branded A4 PDF with your company name, logo and contact, laying out all eleven proposal sections
  (executive summary through terms & conditions), with the equipment table, commercial totals, technical
  specifications and warranty.

It uses your app's existing PDF engine (dompdf — the same one your invoices and quotations use), so
there's **no new dependency**. All figures come straight from the stored proposal; nothing is recomputed.

## API (added under `/papi/advisor`)

- `GET /papi/advisor/projects/{id}/proposal/pdf` — the branded PDF (inline), gated to the Sales menu.

## Files in this zip

**New**

- `app/Services/Advisor/ProposalPdfRenderer.php` — branded HTML + dompdf render.
- `tests/Unit/Kernel/ProposalPdfRendererTest.php` — the document is branded and complete.
- `tests/Feature/Kernel/AdvisorProposalPdfTest.php` — the endpoint returns real PDF bytes, 404s with no
  proposal, and is gated.

**Replace**

- `resources/views/shell.blade.php` — the Download PDF button.
- `app/Http/Controllers/Advisor/AdvisorController.php` — the PDF endpoint.
- `routes/web.php` — the PDF route.

**Carried** — the full Advisor backend, V3 screen, packages, knowledge library and earlier tests, so this
works standalone.

## How to upload (GitHub, branch `main`)

Drag the `app`, `database`, `resources`, `tests` folders and `routes/web.php` into your repo at the same
paths.

## How you know it worked

Green ✓ on **"Money kernel (must pass)"** (includes a test that the endpoint returns real `%PDF` bytes,
using the same dompdf your invoice PDFs already run on CI). Then in V3 → Sales → Solar Advisor, open a
project's proposal and click **Download PDF**.

## The Project Advisor is complete 🎉

Deterministic sizing engine · discovery wizard · eleven-section proposal generator · eight solution
packages · knowledge library · and now branded PDF export — all on screen in V3, all behind guarded APIs,
built as a generic framework you can extend to other industries. The remaining optional idea is a
**customer-facing proposal link** (reusing your quotation portal so clients can view/approve online) —
say the word whenever you'd like it.
