# ShopBot Vision — Stock-Object Taxonomy (research, no code)

**Architecture correction:** the target is not carton-only. Warehouses/supermarkets move cartons, rice
/ sugar / flour **bags & sacks**, oil containers, **wrapped packages**, boxes, **pallets**, buckets,
crates. So the detector must learn **"is this a physical stock package?"**, not "is this a carton?".

**Design/research only. No production code change. `carton.move` stays frozen.**

## Updated division of labour

```
Object detector (neural)  →  ByteTrack → LineZone → MovementStateMachine  →  stock.move  →  ShopBot ledger
"is this a stock package?"        └──── "did it cross, which way, once?" ────┘     "does it match the expected movement?"
```

The neural model answers **only** "is this a physical stock/package object?". It does **not** identify
SKUs ("25 kg Royal Rice") — that is a separate, later capability. The **ledger** says *what* should
have moved; the camera says *something physically moved*; reconciliation matches the two. This is
exactly why it works without OCR/SKU: 5 packages cross OUT + ledger says 5 rice bags → MATCH.

---

## The core question: A) one generic class vs B) a small class set

| | **A. single `stock_object` class** | **B. small set** (`carton`,`bag`,`pallet`,`wrapped`,`container`) |
|---|---|---|
| Recall of "something moved" (the operational event) | **Highest** — one target, most examples/class | High, but split across classes |
| Training data needed | **Least** — all boxes are one label | More — each class needs enough examples |
| Label noise | **Low** — annotators just box any package | **High** — carton vs box vs wrapped is genuinely ambiguous |
| Robust to novel package types | **Yes** — a new shape is still `stock_object` | New type = new class = retrain |
| `object_type` in the event | absent (or "stock_object") | present (richer reconciliation signal) |
| False positives (backpack, chair, trolley) | needs strong negatives | classes help disambiguate a little |
| Per-type shape specialisation | model learns broad "package objectness" | each class visually more coherent |

**Recommendation — "detect coarse, decide generic, enrich later" (A first, hierarchical B later).**
This matches the standard coarse-to-fine detection pattern (detect a general class first; add fine
sub-classes as a second stage) and the project's reconciliation-first thesis:

1. **Phase-0: a single generic `stock_object` (a.k.a. `package`) class.** Fastest path to a working
   "a package crossed" detector, maximum recall of the operational event, least data-hungry, and it
   maps directly onto reconciliation (ledger owns *what*, camera confirms *something*).
2. **Later enrichment: an optional small class set (B)** produced by a coarse→fine head, emitting
   `object_type` (carton/bag/pallet/…) as **advisory metadata** with a confidence — never a hard gate.
3. **The crossing decision stays class-agnostic in both cases** — `MovementStateMachine` counts a
   *stock object* crossing, regardless of its type. Class granularity is a detector/labelling choice,
   not a pipeline change.

**Reconciliation must treat `object_type` as a SOFT signal.** A bag misread as a carton must not create
a false discrepancy — the ledger is authoritative on what moved; the type hint only strengthens or
weakens a match. This keeps the "physical observation → ledger reconciliation → human review" stance
intact and avoids the detector's mistakes becoming accusations.

## Size vs shape (the real challenge)

You're right: **size** is not the problem — a detector handles small/medium/large fine *if the training
data spans those sizes at realistic camera distances*. **Shape variation is the real spread:**

```
carton / oil carton ≈ rigid rectangular    │    rice / sugar / flour ≈ flexible deforming sack
pallet ≈ large multi-object structure       │    wrapped/shrink ≈ specular, semi-transparent
```

A rigid carton and a slumped sack look nothing alike. This is actually an argument **for generic-first
(A)**: forcing early fine-grained classes (carton vs box vs wrapped) fights visual ambiguity and adds
label noise, while a single "stock package" class lets the model learn shared objectness across shapes.
Either way, the fix for shape variation is **diverse training data covering every shape**, not the class
scheme.

## Detector strategy — can one detector cover all of it?

Yes. One detector can cover box/carton, bag/sack, wrapped package, pallet, container — as a single
generic class (A) or a small set (B). Feasibility is grounded:
- **Bootstrap data exists for the whole taxonomy** (all CC BY 4.0 unless noted): Roboflow Universe
  **carton/cardboard**, **bag/sack**, **parcel/package** (e.g. Real Package, Bags Detection, Packaging
  Detection); **Open Images** "Box" + "Luggage & bags" (annotations CC BY 4.0); **LOCO** pallets +
  small-load-carriers + free warehouse **negatives** (CC0). Verify each set; treat all as bootstrap.
- **Zero-shot pre-label unchanged:** Grounding DINO with a multi-term prompt ("cardboard box . carton .
  sack . bag of rice . wrapped package . pallet . plastic-wrapped goods") proposes all types for human
  correction — no commitment to a class scheme at label time.
- **No SKU recognition in Phase-0.** SKU/OCR is a separate future capability, not this detector.

Production candidates are unchanged (`docs/vision-carton-detector-data-strategy.md`): **YOLOX** primary
(CNN, exports to RK3588 + Jetson), **RF-DETR** accuracy/Jetson alternative — both handle a generic or a
small-set class scheme identically. Broadening the class does not change the model choice or the export
path.

## The event contract: `carton.move` → `stock.move` (research only)

**Key finding: the frozen contract already generalises — the migration is mostly a rename.** Every field
you listed for `stock.move` already exists in `carton.move` (`app/movement/contract.py`):

| Proposed `stock.move` field | Already in `carton.move` today |
|---|---|
| object_type | `object.class` (a free string; defaults to `"carton"`, accepts any value) |
| quantity_observed | `object.qty` (+ `object.qty_conf`) |
| direction | `direction` |
| track_id / attribution | `assoc.track_id` / `assoc.candidates` / `assoc.assoc_conf` |
| confidence | `confidence` |
| zone | `zone_uid` |
| timestamp | `timestamp` |

So emitting a bag today is already a one-argument change (`carton_move(..., object_class="bag")`) — **no
schema change**. The only true breaking change is the **event name** (`"carton.move"` → `"stock.move"`),
which ShopBot's `VisionReconciler` keys on. Recommended eventual path (NOT now):
- Rename the canonical event to **`stock.move`**; set `object.class` from the generic taxonomy
  (`stock_object` in Phase-0, or `carton`/`bag`/`pallet`/… when the coarse→fine head exists).
- ShopBot accepts **both** `carton.move` and `stock.move` during a transition window; reconciliation
  reads `object.class` as a soft signal.
- **Do not change the production contract, the MovementStateMachine, reconciliation, or the shelf
  detector now.** This section is the design target, not a change request.

## Data implications

The corpus and the condition list broaden beyond cardboard: collect + verify **bags, sacks, wrapped
goods, pallets, containers** alongside cartons, plus **people crossing without a package** (the critical
negative). The footage guide's condition list should be extended accordingly when real capture starts.
Production accuracy is still measured only on **real warehouse footage from the target camera position**
across this broader set — public data remains bootstrap.

## Recommendations

- **Taxonomy:** **Phase-0 = one generic `stock_object` class** (fastest, highest operational recall,
  least data, matches reconciliation). **Add an optional coarse→fine class set later** emitting
  `object_type` as advisory metadata. Pipeline crossing decision stays class-agnostic; reconciliation
  treats type as a soft signal, never a hard gate.
- **Detector:** one detector covers all package types; bootstrap datasets exist for cartons + bags +
  parcels + pallets (CC BY 4.0 / CC0); Grounding DINO multi-prompt pre-labels all; YOLOX/RF-DETR
  unchanged. No SKU.
- **Contract:** the design target is **`stock.move`**, but the current `carton.move` shape already
  carries every field — migration is a rename + generalised `object.class`. **Freeze production as-is.**
- **Shape not size** is the real challenge → solve with diverse training data, which also favours the
  generic-first class scheme.

**The bigger vision this unlocks:** *ShopBot Vision detects physical stock movement at controlled
warehouse boundaries and reconciles it against the inventory ledger* — packages of any shape, not a
carton-counter. That is a materially larger and more useful product, and the existing pipeline +
contract already support it with essentially no structural change.

_Sources checked 2026-08; re-verify dataset licenses at download time._
