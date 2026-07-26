# Project Advisor — Slice 3: Proposal Generator (backend)

The third slice of the AI-powered Project Advisor. Slice 1 was the calculation engine, Slice 2 the
discovery wizard; this turns a captured project into a **complete, professional proposal**.

**This bundle includes Slices 1 & 2**, so it's the whole Advisor backend so far — upload just this; it
supersedes the earlier Advisor zips.

## What this slice adds

From a captured project (discovery answers + deterministic sizing) plus a chosen equipment list, it
assembles a proposal with the eleven standard sections:

1. Executive summary · 2. Customer requirements · 3. Recommended solution · 4. Equipment list ·
5. Technical specifications · 6. Estimated system performance · 7. Commercial offer · 8. Warranty ·
9. Assumptions · 10. Exclusions · 11. Terms & conditions.

Key behaviours:

- **Reuses Product Specifications** — the technical section is populated from the specs on the equipment
  you select (no re-typing), exactly as you asked.
- **Reuses the quotation engine** — equipment maps to quotation line items, and the commercial maths
  (subtotal → installation → VAT → total) is computed the same way the quote engine does, so figures
  stay consistent when the offer is sent as a quotation.
- **Segment templates** — Residential, Commercial, Industrial, Government, NGO, Hotel, School, Farm,
  Water Pump, Street Lighting each get the right executive lead, assumptions, exclusions and terms.
- **Deterministic** — every number comes from the sizing engine and your catalogue prices; the AI may
  later rewrite the prose, never the figures.

## API (added under `/papi/advisor`, gated to the Sales menu)

- `POST /papi/advisor/projects/{id}/proposal` — build & store the proposal (body: `equipment` list of
  `{product_id, qty}` or explicit line items; `opts` for currency / install fee / VAT).
- `GET  /papi/advisor/projects/{id}/proposal` — the stored proposal.

## Files in this zip

**New (Slice 3)**

- `app/Services/Advisor/ProposalTemplate.php` — per-segment boilerplate.
- `app/Services/Advisor/ProposalBuilder.php` — pure assembly of the eleven sections + quotation-line mapping.
- `app/Services/Advisor/ProposalService.php` — resolves equipment (prices, warranty, specs) and persists.
- `database/migrations/2026_07_26_000003_add_proposal_to_advisor_projects.php` — stores the proposal.
- `tests/Unit/Kernel/ProposalBuilderTest.php`, `tests/Feature/Kernel/AdvisorProposalTest.php`.

**Carried (Slices 1 & 2)**

- `app/Services/Advisor/{SizingEngine,SolarSizingService,DiscoveryProfile,SolarDiscoveryProfile,ProjectAdvisorService}.php`
- `app/Models/AdvisorProject.php`, `app/Http/Controllers/Advisor/AdvisorController.php`
- `database/migrations/2026_07_26_000002_create_advisor_projects_table.php`
- the Slice 1 & 2 tests.

**Replace**

- `routes/web.php` — adds the two proposal routes (nothing else changed).

## How to upload (GitHub, branch `main`)

Drag the `app`, `database`, `tests` folders and `routes/web.php` into your repo at the same paths.

## How you know it worked

Green ✓ on **"Money kernel (must pass)"**. (Before shipping, I ran the real builder against a full
example — a Residential Kampala project with a 300 W station + panels, install + 18% VAT → all eleven
sections in order, UGX 1,640,000 equipment subtotal, UGX 2,171,200 total, specs and warranty pulled
through — all correct.)

## What's next

Slice 4 — **Solution Packages** (Home Starter, Borehole, School, Hospital, Hotel, Farm, Street Lighting…)
as ready equipment + content that drop straight into a proposal; then Slice 5, the **Knowledge Library**
(FAQs, objection handling, WhatsApp-ready copy). The proposal's polished **PDF/portal rendering** and the
wizard's **V3 screen** are the front-end follow-ups whenever you want them.
