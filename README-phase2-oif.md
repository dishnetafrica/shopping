# Win World MES — Phase 2, Batch 3: OIF document + digital QC sign-offs

**Requires Phase 1 + dashboard + notify batches.** This completes Phase 2.

## What's in it
- **Order Indent Form** `/panel/oif?id=<indent>` (`resources/panel/oif.html` + `WinworldOifController`):
  - A print-perfect document matching **WIL/MKT/OIF/001** — header, blending table (with totals/total kgs),
    and the Extruder / Printing / Cutting spec blocks (only the processes the indent uses).
  - **Print / Save PDF** button — the browser produces an A4 PDF from the print CSS (no server PDF dependency).
  - **Digital QC sign-offs** per process: Supervisor / QC / Section-Head each tap **Sign** (records their name + time);
    Pass / Reject result per process. A header badge shows "QC signed 2/3" and turns green when complete.
- **QcStatus** pure helper (`qa/ww_qc_status.php`, 12 assertions) — per-process completeness, all-signed (release-ready), any-reject.
- The **Indent screen** now shows an **"Open / print OIF"** link after saving (changed `indent.html` — replaces Batch 3 copy).

## Install
1. Deploy files. 2. Append routes from `winworld-routes-append-oif.txt`. 3. `php artisan config:cache`.
4. From an indent (after Save) click **Open / print OIF**, or go to `/panel/oif?id=<id>`.

## QA
`php qa/ww_qc_status.php` → 12. Full module sweep: **150 assertions green across 10 suites.**

## Notes
- The sign-offs write to the `ww_indent_qc` table (created back in Phase 1) — no new migration.
- "Save as PDF" via the browser reproduces the exact layout. If you later want the OIF auto-attached to
  WhatsApp/email, add `barryvdh/laravel-dompdf` and the same markup renders server-side — say the word.
- Reminder: the one external input that sharpens the OEE/slow-run numbers is still a real **daily production report** to validate the output-rate (design Q2).
