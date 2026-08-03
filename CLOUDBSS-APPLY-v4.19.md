# CloudBSS v4.19 — TenderBot: your AI Procurement & Bid Manager (drop 1)

**Date:** 2026-08-03 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.19.zip (cumulative — supersedes v4.18; 129 files — **the Dockerfile now ships in the zip**) · **Migration: NEW — run `php artisan migrate`** (creates the `tenders` table; guarded, safe to re-run — the two older guarded ones ride along).

## What this is

Your TenderBot spec, drop 1. A Senior Procurement & Bid Manager AI that reads tender documents end-to-end and tells management what to do next:

- **Reads everything:** PDF (needs the new Dockerfile line), Word, Excel/BOQ, ZIP bundles, CSV — attachments that can't be parsed are declared honestly and flagged "open manually". Never invents information.
- **Produces your exact outputs:** the 🚨 ≤250-word WhatsApp executive summary (organization, division, opportunity %, recommendation, deadline countdown, products, quotations needed today, missing documents, risks, AI verdict) **sent to your owner WhatsApp**, plus the full structured JSON report stored per tender (extraction, classification with reason, compliance check against your documents list, BOQ market-intelligence table, prioritised action plan, risk analysis, confidence score).
- **Watches the forum for you** ("browse daily instead of manually upload"): the South Sudan NGO Forum tenders board is Discourse with a clean JSON API — TenderBot sweeps it **twice a day (07:00 & 13:00 EAT)**, spots new posts, downloads their attachments, analyses up to 3 new tenders per run, and WhatsApps you each summary. Already-seen topics are never re-analysed.

## Where to use it

- **`mycloudbss.com/papi/tenders`** (owner sign-in): drag all files of a tender → "Analyse tender" (30–90s) → summary on screen + WhatsApp. Also: **"Scan forum now"** button, and the recent-tenders list with score/recommendation/deadline chips (tap a row for its full summary).
- **Settings → Tenders (TenderBot):** *Watch for tenders* (turn ON for the automatic twice-daily sweep) · *forum URL* (any Discourse category works) · *business profile* (blank = your ISP + General Trading spec built in) · *company documents on file* (one per line — compliance gets checked against this) · *WhatsApp me each analysis*.

## Setup for DishNet (one-time)

1. Create the DishNet tenant in the platform as usual (needs a connected WhatsApp for summaries; set `openai_api_key` per-tenant or rely on the global key).
2. Settings → Tenders → turn **Watch for tenders ON**; paste your company documents list.
3. Open `/papi/tenders` → **Scan forum now** → the current IRSS/Johanniter tenders should analyse and hit your WhatsApp.

## Deploy

**The zip now contains the Dockerfile** — with BOTH the `COPY public/` line (Update 70 fix) and the new optional `smalot/pdfparser` line for PDF reading. Upload the delta (or cumulative in ≤100-file drags), make sure the Dockerfile lands too, Rebuild (a long build — composer layer changed), then `php artisan migrate`.

## Honest notes

- PDF text extraction needs that Dockerfile line deployed; until then PDFs are stored and flagged for manual opening (Word/Excel/ZIP/CSV parse regardless). Scanned-image PDFs have no text layer — they're flagged, not guessed at.
- Analysis costs one AI call per tender (~30–90s). The forum sweep caps at 3 new tenders per run as a cost guard.
- Drop 2 candidates when you're ready: deadline reminders (7-day/48-hour), WhatsApp document forwarding, the historical-intelligence memory, per-org win/loss dashboard.

## Verified before ship

Extractor harness on real fixtures: DOCX (strip_tags-eats-document bug found & fixed), XLSX BOQ with shared-string resolution ("Mikrotik Router CCR2004" style rows), ZIP recursion with junk skipped, CSV, and PDF via a live pdfparser checkout · analyzer harness with fake LLM: prompt carries profile + WhatsApp format + attachment text + unparsed-file declarations; row stored with division/score/deadline/recommendation; failure path stores status=failed instead of losing the tender · scout attachment-link parsing against the real forum markup (2/2) · live forum probed: category JSON (30 topics) + topic JSON + attachment URLs confirmed · php -l ×11 · zip md5-verified (130 files).
