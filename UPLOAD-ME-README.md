# Project Advisor — Slice 2: Discovery Wizard (backend)

The second slice of the AI-powered Project Advisor. Slice 1 was the deterministic calculation engine;
this adds the **discovery layer** that captures a customer's needs and feeds them into that engine.

**This bundle includes Slice 1**, so it's the complete Advisor backend so far — upload just this; it
supersedes the Slice 1 zip.

## What this slice adds

- **A data-driven questionnaire** (the "discovery schema"): customer type, objective, location, existing
  power, monthly consumption/bill, appliances & loads, backup hours, budget, and expansion plans. It's
  *data*, so the wizard UI is generated from it and a future industry ships a different schema, not new
  code.
- **A deterministic mapper** that turns the salesperson's answers into the neutral "brief" the sizing
  engine consumes (loads normalised, region → local sun hours, expansion choice → allowance). Pure and
  tested.
- **Compute + capture** — an API that runs the engine on the answers (live, no save) and one that saves
  a **captured project** storing both the answers and the sizing snapshot for the proposal stage.

The AI's boundary is preserved: the numbers always come from the deterministic engine, never the caller.

## Architecture (why it's generic)

`DiscoveryProfile` (questions + mapping) and `SizingEngine` (maths) are both **contracts**. A profile is
registered against an engine in `ProjectAdvisorService`. Solar is the first pairing; generators / HVAC /
water treatment add a new profile + engine and reuse the whole framework — the API, persistence and
(next) the wizard UI don't change.

## API (all under `/papi/advisor`, gated to the Sales menu)

- `GET  /papi/advisor/schema?profile=solar` — the questionnaire to render.
- `POST /papi/advisor/compute` — answers → deterministic sizing (no save; for live preview).
- `POST /papi/advisor/projects` — save a captured project (answers + sizing snapshot).
- `GET  /papi/advisor/projects` and `GET /papi/advisor/projects/{id}` — list / read.

## Files in this zip

**New**

- `app/Services/Advisor/DiscoveryProfile.php` — discovery contract.
- `app/Services/Advisor/SolarDiscoveryProfile.php` — solar questionnaire + mapper.
- `app/Services/Advisor/ProjectAdvisorService.php` — profile↔engine registry + compute/save.
- `app/Services/Advisor/SizingEngine.php`, `SolarSizingService.php` — the Slice 1 engine (included).
- `app/Models/AdvisorProject.php` — captured project.
- `app/Http/Controllers/Advisor/AdvisorController.php` — the API.
- `database/migrations/2026_07_26_000002_create_advisor_projects_table.php` — storage.
- `tests/Unit/Kernel/SolarSizingTest.php`, `SolarDiscoveryProfileTest.php` — engine + schema/mapper.
- `tests/Feature/Kernel/AdvisorProjectTest.php` — the API end-to-end (schema, compute, save, gating).

**Replace**

- `routes/web.php` — adds the `papi/advisor` routes (nothing else changed).

## How to upload (GitHub, branch `main`)

Drag the `app`, `database`, `tests` folders and `routes/web.php` into your repo at the same paths. The
new migration runs automatically on deploy.

## How you know it worked

Green ✓ on **"Money kernel (must pass)"** — the unit and feature tests run there. (Before shipping I ran
the real mapper + engine against a full sample questionnaire: a Northern-region home with fridge/TV/
lights → 300 W load, 1.83 kWh/day, 600 W peak with motor surge, a 750 W / 938 VA inverter — all correct.)

## What's next

Slice 3 — the **Proposal Generator**: assemble a professional proposal from a captured project (its
answers + sizing + your Product Specifications), rendered through your existing quotation + PDF pipeline,
with a template per customer segment. Then solution packages and the knowledge library. (The wizard's V3
screen — the visual form over this schema — is a small front-end follow-up whenever you want it.)
