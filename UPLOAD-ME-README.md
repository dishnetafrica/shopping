# Project Advisor — V3 Wizard + Proposal view (makes it clickable)

This adds the on-screen experience for the Project Advisor: a **Solar Advisor** item in your V3 sidebar
that runs the discovery wizard, shows a live sizing calculation, saves the project, and generates and
displays a full proposal — all inside your existing V3 app.

**This bundle includes the whole Advisor backend (Slices 1–3)**, so uploading just this makes everything
work. It supersedes the earlier Advisor zips.

## What you'll see

Open V3 → **Sales → Solar Advisor**:

1. **Projects list** with a **New project** button.
2. **Discovery wizard** — the questionnaire (customer, objective, location, existing power, consumption,
   an appliance/loads table, backup hours, expansion, budget). Press **Calculate** to see the recommended
   system (daily energy, PV array + panel count, battery bank, inverter) computed by the deterministic
   engine, then **Save project**.
3. **Project page** — the sizing summary, an **equipment editor** (name / qty / unit price), and
   **Generate proposal**.
4. **Proposal view** — the full eleven-section proposal rendered on screen (executive summary through
   terms & conditions), with the commercial offer totals and specs pulled through.

Every number comes from the server engine; the screen only collects answers and displays results.

## How it fits your app (safe & additive)

The Solar Advisor is a bespoke screen wired into the V3 shell exactly like your other custom screens
(Storefront pages, Payments): forced into the sidebar and handled by `route()`. It does **not** change
any existing screen or renderer — it only adds new functions and one nav entry.

## Files in this zip

**Replace**

- `resources/views/shell.blade.php` — adds the Solar Advisor nav item + wizard/proposal screens.
- `routes/web.php` — the `papi/advisor` API routes (from the backend slices).

**New (the Advisor backend — Slices 1–3, included so this works standalone)**

- `app/Services/Advisor/*.php` — sizing engine, discovery profile, advisor + proposal services, templates.
- `app/Models/AdvisorProject.php`, `app/Http/Controllers/Advisor/AdvisorController.php`
- `database/migrations/2026_07_26_000002_*`, `2026_07_26_000003_*`
- `tests/Unit/Kernel/*` and `tests/Feature/Kernel/Advisor*` — engine, mapper, proposal, and API tests.

## How to upload (GitHub, branch `main`)

Drag the `app`, `database`, `resources`, `tests` folders and `routes/web.php` into your repo at the same
paths. Migrations run automatically on deploy.

## How you know it worked

1. Green ✓ on **"Money kernel (must pass)"** (covers the backend + tests; the shell is a front-end file
   and can't affect that check).
2. By eye: open V3 → **Sales → Solar Advisor**, run **New project**, add a few appliances, **Calculate**,
   **Save**, add an equipment line, and **Generate proposal** — the eleven-section proposal renders.

Before shipping I syntax-checked the whole shell's JavaScript and ran the real proposal renderer against
a full proposal (all eleven sections, correct money formatting, specs and warranty shown) — all passed.

## What's next

Slice 4 — **Solution Packages** (Home Starter, Borehole, School, Hospital, Hotel, Farm, Street Lighting…)
so the equipment list fills itself instead of typing it; then Slice 5, the **Knowledge Library**. A
polished **PDF export** of the proposal is a later front-end step whenever you want it.
