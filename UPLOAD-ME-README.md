# S8a — Project Quotes (engine), built from kits

The final Phase 2 piece. This lets you turn a **kit** into a full **project quote** — a sectioned
quotation for a whole solar system — reusing everything you already have.

## How it works

- Pick one or more **kits** (which you build in S4). Each kit becomes a **priced section** of the quote,
  its components listed with prices and a section subtotal (e.g. a "3kW Solar Kit" section = 1 inverter
  + 8 panels).
- Add optional **extras** (installation labour, delivery) as their own section.
- The quote itself is created by your **existing quotation engine** — same pricing, numbering, PDF and
  WhatsApp send as every other quote. Nothing in quoting or accounting changes.

The clever bit: the section grouping is stored on the quotation's `meta` (a spare data field), so **no
database table changed and the frozen quote engine wasn't touched at all**. The customer's PDF is the
normal branded quote for now; showing section headers on the PDF is a later add-on if you want it.

---

## Files in this zip (5)

**New**

- `app/Services/Sales/ProjectQuoteService.php` — builds sectioned quotes from kits
- `app/Http/Controllers/Panel/ProjectQuoteController.php` — the API
- `tests/Feature/Kernel/ProjectQuoteTest.php` — proves section math + quote creation

**Replace**

- `routes/web.php` — adds `/papi/project-quotes/from-kits`
- `docs/v3-migration/MIGRATION-TRACKER.md`

> **Depends on S4 (kits)** and your existing quotation engine — both already in your repo.

---

## How to upload (GitHub, branch `main`)

Drag the folders (`app`, `routes`, `tests`, `docs`) into your repo at the same paths. If a new file
doesn't appear, use these links (paste contents, commit):

- `github.com/dishnetafrica/shopping/new/main?filename=app/Services/Sales/ProjectQuoteService.php`
- `github.com/dishnetafrica/shopping/new/main?filename=app/Http/Controllers/Panel/ProjectQuoteController.php`
- `github.com/dishnetafrica/shopping/new/main?filename=tests/Feature/Kernel/ProjectQuoteTest.php`

## How you know it worked

Green ✓ on **"Money kernel (must pass)"** — it runs the new project-quote test (kits explode into priced
sections; building a quote records the section grouping) with everything before it.

---

## What's next

- **S8b** — the V3 **Project Quote builder**: a "New project quote" action (pick kit + customer) and the
  sectioned view on the quotation screen. This drop is the engine + API underneath it.
- That completes **Phase 2** (Jobs, Service Visits, Warranty Claims, Project Quotes).
