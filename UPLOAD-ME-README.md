# Project Advisor — Slice 4: Solution Packages

Ready-made solution packages so a salesperson can go from nothing to a sized project **and** a full
proposal in one click, then tailor it.

**This bundle is the whole Project Advisor so far (Slices 1–4 + the V3 screen)** — upload just this; it
supersedes the earlier Advisor zips.

## What this slice adds

Eight predefined packages, each carrying typical loads, a recommended equipment list, and marketing/sales
copy:

- **Home Starter** (Residential) · **Small Business** (Commercial) · **Solar Borehole Pump** (Water Pump)
- **School Power** (School) · **Health Centre** (Government) · **Hotel Backup** (Hotel)
- **Farm Power** (Farm) · **Solar Street Lighting** (Street Lighting)

In V3 → **Sales → Solar Advisor → Start from a package**: pick a package and it (1) creates a project
sized by the deterministic engine from that package's typical loads, and (2) generates the full
eleven-section proposal from its equipment — instantly. You then edit anything (loads, equipment, prices)
and regenerate.

**Catalogue-aware pricing:** each equipment line has a SKU. If a product with that SKU exists in your
catalogue, the proposal uses its **real price and Product Specifications**; otherwise the package's
indicative price is used. So packages get more accurate as you load your catalogue — no re-typing.

## Architecture

Packages are data (`SolarPackages`) served through a generic `PackageService` (profile registry), reusing
the existing sizing engine, discovery mapper, and proposal builder. Another industry adds its own package
set and reuses everything else.

## API (added under `/papi/advisor`)

- `GET  /papi/advisor/packages?profile=solar` — the packages (with marketing + equipment).
- `POST /papi/advisor/packages/{key}/start` — create a sized project + proposal from a package.

## Files in this zip

**New (Slice 4)**

- `app/Services/Advisor/SolarPackages.php` — the eight packages (loads, equipment, marketing).
- `app/Services/Advisor/PackageService.php` — list/get/start + SKU→catalogue resolution.
- `tests/Unit/Kernel/SolarPackagesTest.php`, `tests/Feature/Kernel/AdvisorPackageTest.php`.

**Replace**

- `resources/views/shell.blade.php` — adds the "Start from a package" picker.
- `app/Http/Controllers/Advisor/AdvisorController.php` — packages + start endpoints.
- `routes/web.php` — the two package routes.

**Carried (Slices 1–3 + earlier tests)** — the full advisor backend and V3 screen, so this works standalone.

## How to upload (GitHub, branch `main`)

Drag the `app`, `database`, `resources`, `tests` folders and `routes/web.php` into your repo at the same
paths.

## How you know it worked

Green ✓ on **"Money kernel (must pass)"**. Then in V3 → Sales → Solar Advisor → **Start from a package**,
pick e.g. **Home Starter** — it opens a sized project with a full proposal. (Before shipping I sized all
eight packages through the real engine and syntax-checked the shell's JavaScript — all passed.)

## What's next

Slice 5 — the **Knowledge Library** (FAQs, objection handling, WhatsApp-ready copy). A branded **PDF
export** of the proposal remains a nice later add.
