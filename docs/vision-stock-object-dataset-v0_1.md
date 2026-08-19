# ShopBot Stock Object Detector v0.1 — Bootstrap Dataset Strategy (research, no code)

**Revised thesis:** we do **not** need customer footage to train an initial model. Train a strong
**generic stock-object detector v0.1** from global public data + African-relevant bag/sack/godown data
now; use **customer/target-camera footage for domain adaptation and held-out validation** later.
Research/design only — no production code, no training run here.

**Critical rule (unchanged):** public/global/African web data is **BOOTSTRAP**. It does **not** prove
production accuracy. Final accuracy is measured on **target-camera footage from the real deployment
position** across the messy-warehouse condition list. This document assembles bootstrap data and says
plainly where its limits are.

## Two tracks in parallel (the point of this revision)

```
        GLOBAL public data  +  African-relevant data
                        ↓
                   MODEL v0.1  ── Track A: model development (starts NOW, no hardware needed)
                        ↓
              ┌─────────┴─────────┐
        existing CCTV        new sample camera   ── Track B: real-world validation (needs hardware/footage)
              └─────────┬─────────┘
                        ↓
                  REAL BENCHMARK  (bench/ harness, exactly-one-correct metric)
                        ↓
                   MODEL v0.2  (failure-driven: correct labels where v0.1 fails)
```

Track A is idle time we can reclaim; Track B remains the arbiter of truth.

## Dataset catalog (commercial-use screened)

Object-detection, **generic package/bag/carton/pallet** scope. "Commercial ✅" = usable in our product
path; verify each specific download at label time. Roboflow "Open Source" defaults to **CC BY 4.0**
(attribution) but is **per-dataset — verify each**, as user uploads sometimes carry other terms.

| Dataset | Source | Size (approx) | Classes | Characteristics | License | Commercial | African relevance | Annotation quality | Recommended use |
|---|---|---|---|---|---|---|---|---|---|
| **LOCO** (Logistics Objects in Context, TUM) | github.com/tum-fml/loco | 39,101 imgs / 5,593 annotated / 151k inst | pallet, small load carrier, stillage, forklift, pallet truck | **Real warehouse** scenes, COCO format | Public domain (CC0) | ✅ **no restriction** | Universal warehouse layout; SLC≈box-proxy | Good (curated) | **Core** warehouse domain + free negatives (forklift/pallet) |
| **Open Images V7 — "Box", "Luggage & bags", "Packaging"** | storage.googleapis.com/openimages | 1.9M imgs / 16M boxes (subset) | Box, Suitcase, Handbag, Backpack, … | Generic in-the-wild | **Annotations CC BY 4.0**; images CC BY 2.0 (per-image caveat) | ✅ annotations; ⚠️ verify images | Global incl. some Africa | Professional | **Core** generic box/bag class + negatives |
| **Roboflow — carton / cardboard box** (many) | universe.roboflow.com | 60 → ~9.8k each | carton, cardboard box (some open/closed/damaged) | Mixed domains (packaging, recycling) | mostly CC BY 4.0 | ✅ w/ attribution (verify each) | Some Asian/African uploads | Variable | Carton class bootstrap |
| **Roboflow — rice bags / sack counting / rice counting** | universe.roboflow.com/phong-cv/rice-bags, .../supersourcing-vhpkc/sack-counting | hundreds–thousands each | rice bag, sack | **Stacked sacks, godown-like** | "Open Source" (verify; typically CC BY 4.0) | ✅ (verify each) | **High** — sacks are core African/Asian FMCG | Variable (user-made) | **Bag/sack** class — the African-market-critical shape |
| **Roboflow — parcel / package / warehouse-detection** | universe.roboflow.com (e.g. work-gcmtc/warehouse-detection) | hundreds–thousands | parcel, package, box, warehouse objects | Loading/handling scenes | "Open Source" (verify) | ✅ (verify each) | Medium | Variable | Wrapped/parcel class + scene diversity |
| **Roboflow — pallet detection** (several) | universe.roboflow.com | hundreds–thousands | pallet | Warehouse/loading | "Open Source" (verify) | ✅ (verify each) | Medium | Variable | Pallet class |
| SKU110K | github (retail) | 11k imgs | densely-packed retail products | Shelf, not movement | research terms | ⚠️ | Low | Professional | **Not chosen** (shelf products ≠ dispatch packages) |
| **Amazon Bin Image Dataset** | registry.opendata.aws | 536k imgs | bin contents (packages) | Fulfilment bins | **CC BY-NC-SA 3.0** | 🚫 **NonCommercial — EXCLUDE** | — | Professional | **Excluded** from production path |
| TACO (litter incl. cardboard) | tacodataset.org | ~1.5k imgs | litter (carton, cardboard subset) | Outdoor litter | CC BY 4.0 | ✅ | Low | Good | Optional cardboard-texture aux only |
| TAMPAR (parcel logistics) | arxiv 2311.03124 | parcels | parcel (tampering) | Postal parcels | ⚠️ verify | ⚠️ verify | Low | Good | Verify license before use |

## Negatives (as important as positives)

The costly false positive is **"person carrying nothing" read as a package**. Sources: LOCO (workers,
forklifts, pallet trucks), Open Images (person, chair, backpack, trolley), and our own footage.
Explicitly include **people crossing empty-handed, forklifts, shelves, doors, walls, machinery**.

## Honest finding on African footage

Extensive searching turns up **abundant global** package/bag/carton/pallet data but **no substantial,
openly-licensed, African-warehouse-specific detection dataset**. So:
- The bootstrap corpus's "African relevance" realistically comes from **bag/sack/godown datasets of
  Asian/African origin** (rice bags, sacks — the FMCG shapes that dominate East African warehouses) plus
  global diversity — **not** from a ready-made African warehouse dataset.
- **Standard YouTube/Vimeo warehouse videos are NOT licensed for training** (all-rights-reserved by
  default); only explicitly **CC-BY** video may be used. Product-demo videos (e.g. the African godown
  counting demo) are evidence the market exists, **not** a usable training source.
- **True African-domain coverage therefore still requires our own footage** — which is exactly Track B.
  This *strengthens* the case for domain adaptation: the concept is global, the domain gap is real, and
  only customer footage closes it. Do not claim the bootstrap corpus covers the African domain.

## Assembling "ShopBot Stock Object Dataset v0.1"

1. **Taxonomy:** primary label **`stock_object`** (generic — what the pipeline needs), with **advisory
   subclasses** `carton | box | bag | sack | parcel | pallet | wrapped_package`. Every source's varied
   class names normalise into this scheme (a label map): e.g. LOCO `small load carrier`→box, Roboflow
   `rice bag`→bag/sack, Open Images `Box`→carton/box. **No SKU.**
2. **Screen licenses per source** (record in the model manifest / `bench/MODEL_LICENSES.md`); **exclude
   NC** (Amazon Bin) and unverified terms.
3. **Dedup + balance** across shapes (rigid carton vs flexible sack vs pallet) and add negatives.
4. **Splits:** train/val from public data; **hold out real footage entirely** for the honest test — never
   let bootstrap data stand in for the target-camera test set.
5. **Version it** (`dataset_version` in the model manifest) so v0.1 → v0.2 is traceable.

## Recommendations

- **A. Bootstrap datasets:** **LOCO (CC0)** + **Open Images Box/Luggage&bags (CC BY 4.0 annotations)** +
  **Roboflow carton/cardboard, rice-bag/sack, parcel/package, pallet (CC BY 4.0 — verify each)**. Sacks
  are the African-market-critical shape — weight them in. **Exclude Amazon Bin (NC)** and SKU110K.
- **B. Zero-shot pre-label** (unchanged): Grounding DINO multi-prompt to bootstrap-label any thin
  classes / our own first footage; human-verify.
- **C. Model:** train **v0.1 = generic `stock_object`** (advisory subclasses) with **YOLOX** (portable
  RK3588+Jetson), RF-DETR as the accuracy benchmark. Detector stays behind the `Detector` interface;
  pipeline/`stock.move` contract unchanged.
- **D. Then Track B:** run v0.1 on existing CCTV + the sample camera via `bench/`, do **failure
  analysis**, correct labels, produce **v0.2**. Production accuracy = held-out target-camera footage,
  not any public number.

**No production code, no training run, no engine/pipeline/contract change here.** This is the dataset
assembly plan + honest license/relevance screening that lets Track A start the moment we choose to,
without waiting on hardware — while Track B stays the source of truth.

_Dataset facts checked 2026-08; licenses/sizes change — re-verify each dataset at download time._
