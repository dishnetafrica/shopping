# EA Auto Part — genuine motorcycle spares storefront

EA Auto Part is the storefront brand (the seller); the parts sold are **genuine
Bajaj Boxer spares**. This repo is the front-end for the CloudBSS **`auto_parts`
vertical** (see `platform-integration/`). The Boxer 100 is **fully catalogued**:
45 exploded-diagram plates and 543 unique part numbers (`parts-import.csv`).

## Quick start

```bash
python3 build/generate.py     # → dist/*.html   (no dependencies; pure stdlib)
python3 build/build_parts.py  # rebuild parts DB + parts-import.csv
open dist/index.html
```

The prebuilt pages in `dist/` reference images from `assets/`, so open them from
inside the repo. To regenerate fully self-contained pages instead, run
`EMBED_IMAGES=1 python3 build/generate.py`.

`data/tenant.json` holds the EA Auto Part brand (colour, currency, hero copy).
Swap it (see `data/tenant-speedparts.json`) to re-skin for another tenant.

---

**Data is separated from design.** Pages are *assembled* from one design system
plus structured content — never hand-drawn page by page. Change a token or a data
value and every page re-renders consistently.

```
design-system.json  ─┐
data/**/*.json       ─┼──►  build/generate.py  ──►  dist/index.html
assets/**            ─┘        (the assembler)        (self-contained preview)
```

This kit delivers the **foundation + seven live pages**, the full journey:

```
index.html            Homepage (7 models, systems, popular parts, AI band)
boxer100.html         Motorcycle overview (gallery, specs, systems, sidebar)
boxer100-parts.html   Parts catalogue — browse by system
boxer100-diagram.html Exploded diagram — real catalogue line-art + part list
boxer100-part.html    Part detail — gallery, price, compatibility, also-bought
boxer100-360.html     360° explorer — drag-to-rotate digital twin + hotspots
boxer100-ai.html      AI visual diagnosis — symptom → causes → part / diagram
```

The 360° explorer cycles the studio angle frames on drag with clickable system
hotspots (config in `data/explorer/boxer100.json`). The AI diagnosis page is
driven by `data/ai/troubleshooting.json` (symptom → ranked causes → linked part,
system and diagram plate).

Every screen is assembled from the same `design-system.json` + `/data`, and every
image is an **upload slot** the v3 portal can fill (see "Image uploads" below).
The exploded diagrams (`assets/diagrams/boxer100/*.png`) are the real BM100
catalogue line-art with numbered callouts that match the extracted part lists in
`data/diagrams/boxer100.json` (part numbers, per-variant qty, UGX prices).

---

## What's in here

```
design-system.json      Single source of truth: colour, type, spacing, radii,
                        shadows, buttons, icons, grid + component specs
                        (navbar, hero, motorcycle/system/product/compatibility
                        cards, spec table, part-list table, gallery, AI widget).

data/
  models/               One JSON per motorcycle — all models from your catalogues
    boxer100.json         ★ fully populated: specs, colours, gallery slots,
                            10 systems w/ part counts, all 45 catalogue plates
    boxer125hd.json         specs from the 125 HD brochure (real figures)
    boxer125x.json          identity + specs + system counts
    boxer150.json
    boxer150x.json
    v15.json
    v12.json
  systems/systems.json  Canonical 10-system taxonomy; each catalogue plate maps
                        to exactly one system (Browse-by-System reads this).
  products/
    clutch_plate.json     Sample product: part no, price (UGX), stock, 6 image
                          slots, compatible models, "also bought".
    _cylinder_head_plates.json  Verbatim part lists for plates 1 & 2, extracted
                          from the BM100 catalogue (real 8-digit OEM numbers).
  compatibility/compatibility.json   Fitment resolved by shared OEM number.
  specifications/  manuals/          (reserved for next phases)

assets/                 Folder tree for real photography/diagrams. Every asset
                        path referenced in the JSON already has a home here.
  branding/             logo-white.svg, logo-dark.svg
  motorcycles/<model>/  hero + studio angles + detail + 360 spin (paths defined)
  systems/ products/ diagrams/ manuals/ icons/ videos/ compatibility/ ui/

build/generate.py       The assembler. `python3 build/generate.py`
build/shot.mjs          Playwright screenshot helper (optional).
dist/index.html         The generated, self-contained homepage.
dist/preview-*.png      Rendered previews.
```

★ = the deep template. Every other model follows the same shape; they're
populated with real identity + specs and can be filled to Boxer-100 depth by
extracting the remaining plates from each catalogue PDF.

---

## Real data provenance

Everything substantive comes from your uploaded catalogues, not invented:

| Source PDF | Used for |
|---|---|
| `BM 100 ES & KS SPC` (Brand ID 001MC643) | Boxer 100 — 45 plates, real part numbers (JZ 5110 21 …) |
| `Bajaj Boxer 125 HD Brochure` | 125 HD full specs (124.45cc, 10 PS, 5-speed, 120 kg …) |
| `BOXER BM150 SPC` | Boxer 150 — 46-plate structure |
| `BOXER BM125X SPC` | Boxer BM125X — 45-plate structure |
| `Boxer BM150 X SPC` | Boxer 150 X (Off Roader) — 46-plate structure |
| `V15/V12 SPC` | V15 & V12 — 54-plate structure (disc brake, SAI, EVAP) |

---


## Image provenance

The photos under `assets/motorcycles/`, `assets/systems/` and `assets/products/`
were extracted at full resolution from your uploaded asset-library sheet (studio
angles, colour options, detail close-ups, part photos). Every image sits at the
exact path its data file references, so higher-res studio photography can replace
them in place with zero code changes.

## Regenerate

```bash
python3 build/generate.py          # → dist/index.html
```

No build dependencies for the site itself (pure Python stdlib). The homepage is
self-contained HTML+CSS with inline SVG, so `dist/index.html` opens directly in
any browser.

## Auto Parts vertical (CloudBSS tenant theme)

This storefront is a **CloudBSS vertical**: selecting *Auto Parts / EPC catalogue*
as a tenant's Business type serves this front UI at `/{shop}`, the same way the
`manufacturer` vertical serves the brand site. The whole UI is a pure function of
one per-tenant config — `data/tenant.json` (brand, theme accent, market/currency,
enabled models, hero copy) — so any auto-parts tenant gets it by setting their
brand + catalogue. Nothing is hardcoded to Bajaj.

```bash
python3 build/generate.py                                   # Bajaj tenant  → dist/
python3 build/generate.py data/tenant-speedparts.json dist-demo/speedparts   # rebrand demo
```

`data/tenant-speedparts.json` (green, Kenya/KES, "SPEEDPARTS") renders the same
engine as a completely different brand — proof the vertical is tenant-driven.

The `platform-integration/` folder wires this into the real app the same way the
manufacturer brand site is wired (config-driven, tenant `settings`, no schema
change):

```
platform-integration/
  AUTO-PARTS-VERTICAL-NOTES.md              deploy guide + tinker block
  Vertical.php.patch                        adds AUTO_PARTS to App\Support\Vertical
  StorefrontController-autoparts.snippet.php landing() branch + autoParts() + config
```

## Portability (Laravel-ready)

The design layer is data-driven, so the same JSON can feed Blade templates in
your CloudBSS `manufacturer-brandsite` module later — `generate.py` is a
reference renderer, not a lock-in. The tokens and component specs port directly
to CSS variables + Blade components.

## Image uploads from the v3 portal (Family Shoppers style)

Images are **not hardcoded**. Every image is an upload slot with a stable key,
resolved in this order:

```
data/media.json → uploads[slotKey]   (uploaded via v3 portal)   ← wins if set
        ↓ (empty)
data-file asset path                 (bundled default photo)
        ↓ (missing)
neutral placeholder
```

Slot keys:

```
motorcycle gallery   <modelId>:<imageId>        boxer100:studio_right
system cover         system:<systemId>          system:engine
product image        product:<productId>:<imageId>   product:clutch_plate:main
```

This mirrors how **Family Shoppers** stores `WwItem.image_url` from the seller/
admin panel: the portal writes an `image_url` (absolute CDN url *or* an app-
relative `/storage/...` path) into `data/media.json → uploads`, and it overrides
the bundled default with **zero code changes**. Empty/missing keys never wipe an
existing upload — same re-import safety rule as `ProductImporter`. Each rendered
`<img>` carries a `data-slot="..."` attribute so the portal can target it.

To wire the real portal: have the upload controller write the uploaded file's url
to `uploads[slotKey]` (DB column or JSON), then render through `img_slot()` (or
the Blade equivalent).

## Next screens (same pipeline)

Motorcycle overview · 360° explorer · exploded diagram · product detail ·
compatibility · search results · AI assistant · maintenance schedule · downloads
· mobile. Each is a new render function over the *same* design-system.json and
data files.
