# S4b — Selling a kit draws down its parts

This makes kits real at the point of sale. When a delivered order contains a **kit**, the system now
takes the **parts** out of stock (kit quantity × each part's quantity) instead of the kit itself. The
customer's invoice/quote is unchanged — the kit still shows as one line at the kit price. Only your
stock is affected, behind the scenes.

**Example:** sell **2 × "3kW Solar Kit"** (each = 1 inverter + 8 panels + 4 batteries) → inverter −2,
panels −16, batteries −8; the kit's own stock never moves. Cancel or un-deliver the order → all of it
is put back automatically.

How it's built (important, so you can trust it): there is exactly **one** place in your system that
turns a delivered order into stock movements. S4b changes only that one place to list a kit's parts
instead of the kit. Because issuing stock, reversing it, and the safeguard against double-deducting all
read from that one place, they all become kit-correct together — and the **invoicing, pricing and
accounting engines are never touched**. If anything ever went wrong exploding a kit, it safely falls
back to the old behaviour and the sale still completes.

> **When it takes effect:** this runs wherever your **"reduce stock on delivery"** setting is on. If GSL
> isn't using that yet, kit sales won't visibly move stock until it's enabled (exactly like normal
> products today) — but the engine is correct the moment it's turned on. Tell me if you'd like me to
> check/enable that for GSL as a small next step.

---

## ⚠️ Upload S4a first

S4b builds directly on **S4a** (the kit engine). Please upload S4a and see it go green **before** this.
As a safety net, this zip also re-includes S4a's runtime files (byte-identical) so nothing breaks if
part of S4a didn't upload cleanly.

## Files in this zip

| File | What it is |
|------|------------|
| `app/Services/Inventory/OrderStockIssuer.php` | **REPLACE** — the S4b change: explode a kit into its parts at stock issue |
| `tests/Feature/Kernel/KitStockIssueTest.php` | **NEW** — proves it (part of the green gate) |
| `app/Services/Catalog/KitService.php` | *safety net* — same as S4a |
| `app/Models/KitComponent.php` | *safety net* — same as S4a |
| `app/Kernel/Events/KitEvents.php` | *safety net* — same as S4a |
| `app/Models/Product.php` | *safety net* — same as S4a |
| `database/migrations/2026_07_25_000008_create_kit_components_table.php` | *safety net* — same as S4a |
| `database/migrations/2026_07_25_000009_add_is_kit_to_products.php` | *safety net* — same as S4a |

---

## How to upload (GitHub, branch `main`)

Drag the folders (`app`, `database`, `tests`) into your repo so they land at the same paths. If the new
test doesn't appear, use this direct link (paste its contents from the zip, commit):

- `github.com/dishnetafrica/shopping/new/main?filename=tests/Feature/Kernel/KitStockIssueTest.php`

## How you know it worked

Watch the **Actions** tab. A **green ✓** on **"Money kernel (must pass)"** means S4b is live.

---

## What's next (not in this drop)

- **Preassembly** — build a batch of kits ahead of time (parts down, kit stock up), for shops that
  stock finished bundles rather than exploding on each sale.
- Optional: show the parts on the invoice PDF (itemized) — a separate, larger change we discussed.
- That completes **Phase 1** (specs, serials, warranty, kits). Phase 2 is Installation Work Orders,
  Service Visits, Warranty Claims, and Project Quotes.
