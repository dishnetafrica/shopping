# CloudBSS v4.22 — TenderBot does the back-office work

**Date:** 2026-08-03 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.22.zip (cumulative — supersedes v4.21; 136 files) · **⚠ RUN `php artisan migrate`** (new `tender_items` table) · **Delta: 13 files.**

## Why this drop exists

Your live Johanniter analysis came back with **"Deadline: (N days remaining)"**, no date, and no product list. Three separate causes, all fixed here.

**1. The forum's PDFs are SCANS.** The Johanniter audit notice is a 3.3 MB PDF with no text in it at all — just photographs of pages. Nothing in the pipeline could read a single word of it, so the AI was analysing the forum post's two-sentence blurb and nothing else.

**2. A bug hid that from us.** `pdftotext` prints one form-feed character per page, and PHP's `trim()` does not strip form feeds — so a fully scanned document came back as "one character of text" and counted as *successfully read*. Found by the new harness on a real scanned fixture; without it the OCR below would never have fired.

**3. The prompt leaked its own template.** The summary shape contained the literal words "(N days remaining)" and the model copied them instead of substituting. It now must fill in real values and write "not stated in the documents" when a fact genuinely is not there.

## What's new

**📄 OCR for scanned documents.** When a PDF has no text layer, TenderBot renders its pages to images and has the AI read them (first 15 pages per document, in batches). Every run is traced with the page count so the spend is visible, and it only ever runs on files that could not be read normally. Switch: **Settings → Storefront → Tenders → "Read scanned documents (OCR)"** (default ON). `pdftotext`/`pdftoppm` are now installed in the image — `pdftotext -layout` also became the *primary* PDF reader, which is what keeps a BOQ table's quantities attached to their items.

**📎 The documents are kept, and you can open them.** Every file downloaded from the forum (or dropped in the panel) was already being stored; now each tender lists them — real filename, size, and whether it was **read** or **not read**, with the reason ("scanned — more than 15 pages, open it manually"). Click any one to open it. Tenders analysed before this drop still list their files correctly.

**🧾 "Items to price" — the back-office board.** Every product line the AI finds in a BOQ becomes a row on one board across all open tenders: item, quantity, unit, lot, specification, brand, which tender, and how many days are left. This is the list you asked for: *what do we need to get prices for.*

**💰 Prices and the bid sheet.** Type what a supplier charges you and the bid price is worked out from your margin (**Settings → Tenders → Bid margin**, default 20%; any single line can override it). Each tender shows a running bid sheet — lines, how many still need a price, total cost, total bid, profit and margin %. The line moves itself to "priced" when you enter a cost.

**💡 Price memory.** The next tender that asks for the same item shows *"last paid USD 2.35 — Nile Traders (12 Jul 2026)"* with a **use** link. It matches loosely, so "Cooking Oil" and "cooking oil" are the same item.

**📥 CSV export** of the whole board (Excel-safe UTF-8) for sending to suppliers or attaching to the bid file.

Re-analysing a tender never destroys work: lines you have already priced or skipped are left exactly as they are.

## After you deploy

1. Upload the delta (13 files) → **Rebuild** (long — the image installs poppler) → **`php artisan migrate`**.
2. **Set the FS business profile.** Right now it is blank, so the AI is using the built-in ISP + General Trading profile — which is why an *external audit service* came back as "Division: Both, 85%, BID" for a food and household supplier. Paste the Division A/B/C profile from CLOUDBSS-APPLY-v4.20.1.md and that tender will correctly score low and classify Ignore.
3. Set **Bid margin** to whatever you actually work on.
4. Open **Tenders → Scan forum now**. This time the scanned notices get read, so expect real deadlines, and BOQ lines appearing on the **Items to price** tab.

## Honest limits

OCR is capped at 15 pages per document — a long annex will still say "open it manually". Reading a photographed page is not perfect; treat an OCR'd figure as a strong lead, not a signed contract, and open the original (one click) before submitting a bid. A tender with no product list in its documents (a service contract, like the audit one) correctly produces no items — that is not a failure.

## Verified before ship

43-check harness on real PDF fixtures: text-layer PDF read with its table layout intact · **scanned PDF correctly detected and flagged for OCR (this is the check that caught the form-feed bug)** · poppler rasterises to real JPEGs · OCR batching, page count, trace and the off-switch · items sync from a report (quantity parsing "40 MT"→40 / "8,000 Litres"→8000, lots, bare-string and alternate-key tolerance, junk rows dropped) · pricing maths (20% default, tenant margin, per-line override, explicit bid price, bad input rejected) · line/total/profit/margin arithmetic checked against hand-computed figures · price memory fuzzy match · re-sync does not duplicate or destroy priced lines. Panel rendered in a real browser: tabs, documents list (new AND old storage shapes), price-memory "use", bid line display, zero JS errors · `php -l` ×11 · zip md5-verified (136 files).
