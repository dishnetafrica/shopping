# Storefront app modules (`src/`)

The storefront's application JavaScript, split into cohesive modules. `build_storefront.py`
concatenates these in **filename order** into a single inline bundle, injects it into
`autoparts_spa.html` at `__APP_BUNDLE__`, then bakes the catalogue data and ships one
self-contained `resources/storefront/autoparts.html`.

## Modules

| File | Responsibility |
|---|---|
| `01-core.js` | `STATE`, DOM helper `$`, `money`/`esc`/`sid`, `partNo`, shop links |
| `02-catalogue.js` | `normalize`, `loadCatalogue`, category/part-no/system indexes |
| `03-garage.js` | saved bike ("My Garage") + fitment |
| `04-vehicle.js` | vehicle taxonomy (scales to cars) |
| `05-whatsapp.js` | single-part WhatsApp order link |
| `06-cart.js` | native cart (localStorage) + WhatsApp checkout |
| `07-analytics.js` | fire-and-forget event beacons to `/event` |
| `08-errors.js` | client-side error capture + small helpers |
| `09-shell.js` | nav, bike bar, product cards, shell chrome |
| `10-views.js` | every route view (`vHome`, `vPart`, `vCart`, …) |
| `11-router.js` | `render`, `go`, search helpers |
| `12-a11y-boot.js` | accessibility enhancement + boot (**must sort last**) |

## Design note: shared runtime scope (intentional, for now)

These files currently share one runtime scope — they are concatenated, not ESM-imported.
That was a deliberate first step: it separates the code into maintainable, reviewable files
**without changing a single byte of behavior** (the build's concatenation reproduces the
original script exactly, and the Playwright suite — including a "no runtime errors on every
route" smoke test — proves it).

The catalogue data (`CFG`, `IMG`, `TB`, `MODELS`, `DIAG`, `GAL`, `SYSCOVER`) and inline
`onclick` handlers in the HTML both rely on these being globals, so the seam to strict ES
modules (explicit `import`/`export`, a `window`-exposed public API for the inline handlers,
esbuild `--bundle`) is the next increment — now safe to do file-by-file behind the tests.

## Build

```bash
python3 build_storefront.py            # concat modules + bake data -> autoparts.html
python3 build_storefront.py --minify   # optional: esbuild-minify the bundle if esbuild is installed
python3 build_storefront.py --check    # verify it resolves without writing
```
