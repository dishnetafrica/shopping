# Project Advisor — Slice 5: Knowledge Library (completes the roadmap)

A reusable library of sales knowledge, searchable inside the advisor: customer **FAQs**, short
**educational** explainers, **objection-handling** responses, and ready-to-send **WhatsApp** replies.

**This bundle is the complete Project Advisor (Slices 1–5 + the V3 screen), and it includes the earlier
`status` bug fix** — upload just this; it supersedes every earlier Advisor zip.

## What this slice adds

In V3 → **Sales → Solar Advisor → Knowledge**: a searchable library with type tabs (All · FAQs · Learn ·
Objections · WhatsApp). Every objection and message carries a **ready-to-send WhatsApp reply with a
one-tap Copy button**, so a salesperson can answer confidently and paste a polished response.

Content included (24 entries): battery life, cloudy-day output, savings, appliances/surge, install time,
maintenance, warranty, off-grid vs hybrid; explainers on how solar works, battery types, sizing and
backup; objection handling for "too expensive", "wait for prices", "sun isn't always out", "batteries
fail", "generator is cheaper", "no roof space"; and WhatsApp templates for intro, quote follow-up, site
visit, payment options, after-sale and referrals.

## Architecture

Content is data (`SolarKnowledge`) served through a generic `KnowledgeLibrary` with pure, tested search
(type + category + free-text). Another industry ships its own content set and reuses the service and UI.

## API (added under `/papi/advisor`)

- `GET /papi/advisor/knowledge?profile=solar&type=&category=&q=` — filtered entries, plus the available
  types and categories.

## Files in this zip

**New (Slice 5)**

- `app/Services/Advisor/SolarKnowledge.php` — the content.
- `app/Services/Advisor/KnowledgeLibrary.php` — the search/filter service.
- `tests/Unit/Kernel/KnowledgeLibraryTest.php`, `tests/Feature/Kernel/AdvisorKnowledgeTest.php`.

**Replace**

- `resources/views/shell.blade.php` — the Knowledge browser (search, tabs, copy-to-WhatsApp).
- `app/Http/Controllers/Advisor/AdvisorController.php` — the knowledge endpoint.
- `routes/web.php` — the knowledge route.
- `app/Services/Advisor/ProjectAdvisorService.php` — **carries the `status` fix** (Undefined array key).

**Carried** — the full advisor backend + V3 screen from Slices 1–4, so this works standalone.

## How to upload (GitHub, branch `main`)

Drag the `app`, `database`, `resources`, `tests` folders and `routes/web.php` into your repo at the same
paths.

## How you know it worked

Green ✓ on **"Money kernel (must pass)"** (all advisor tests, including the ones the `status` fix
repaired). Then V3 → Sales → Solar Advisor → **Knowledge**: search, switch tabs, and copy a WhatsApp
reply.

## Roadmap — complete 🎉

1. ✅ Calculation Engine · 2. ✅ Discovery Wizard · 3. ✅ Proposal Generator · 4. ✅ Solution Packages ·
5. ✅ Knowledge Library — all on screen in V3.

Optional later polish: a **branded PDF export** of the proposal, and a **customer-facing** proposal link
(reusing your quotation portal). Say the word whenever you want either.
