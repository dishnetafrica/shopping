# Quotation detail + actions — panel UI (seller.html)

The first Tier-1 screen from the UX plan, and the one you reported directly: you can now **open a
quotation, see what's inside, edit it, and act on it** — wired into the existing vanilla-JS panel, no
framework added, matching its conventions (`?token=` auth, the shared `#modal`, `esc`/`toast`/`$`).

## What changed (one file: `resources/panel/seller.html`)
- Each row on the Quotations page gets a **👁 View** button (active and converted rows).
- View opens the shared modal and calls `/papi/quotation-show`, rendering:
  - header: customer, status badge, PDF link;
  - an **editable line-item table** (name / qty / unit price) with live line + grand-total recompute;
  - a currency selector (UGX/USD);
  - actions: **💾 Save changes** (→ `/papi/quotation-update`, rebuilds the PDF keeping the same
    number), **↻ Resend**, **✓ Convert to order**, **🗄 Archive** (→ `/papi/quotation-delete`,
    archives by default), **Close**.
- **Locks respected:** `accepted` and `converted` quotations render read-only (no edit/save/archive),
  with a note explaining why — matching the backend guards (422 on edit/delete).
- Three endpoints registered in the panel's `EP` map: `quoteShow`, `quoteUpdate`, `quoteDelete`.

All endpoints already existed and are tenant-scoped; this is the front-end that was missing.

## How to verify in the browser
1. Deploy `seller.html`, open the panel → **Quotations**.
2. Click **👁 View** on any quote → the items appear (this is "see what's inside").
3. Change a qty or price → the line + total update live → **Save changes** → toast "Saved ✓ — PDF
   rebuilt"; re-download the PDF to confirm the new totals under the same quote number.
4. **Archive** a test quote → it leaves the active list (still in reports).
5. Open an **accepted/converted** quote → it's read-only with the explanation.

Automated: the API behind it is covered by `QuotationCrudTest`, and the Chrome script
(`tests/e2e/panel-smoke.mjs`) exercises show/update/delete.

## Note
This updates the quotation row in the verification matrix from "API only" to "has UI". The other
Tier-1 item (the "Today / Needs attention" home screen) and Tier-2 (inventory count screen, chat
takeover control) are still to do — one screen at a time, as agreed.
