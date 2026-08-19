# ShopBot Vision — Mixed-Stock Environment Requirements (v0.1)

**First-class requirement:** a real warehouse door moves cartons, rice bags, sugar/flour sacks, parcels,
wrapped goods, pallets and bottles through the *same* boundary, mixed and often simultaneously, with
workers who may carry nothing. The detector must **not** assume a single package type, and the movement
engine must **not** depend on the type.

## Two different problems (only the first is ours in Phase-1)

- **Detection** — "I see 5 stock objects cross." ← ShopBot Vision does this.
- **Identification** — "those 5 are 3× Royal Rice 25kg + 2× Kakira Sugar 50kg." ← the **ERP already
  knows this**; we do not need SKU recognition.

That split is what makes the problem achievable: the camera counts *physical stock objects*; the ledger
says *what they were*; reconciliation compares the two.

## The engine is type-agnostic (verified)

```
carton ─┐
box ────┤
bag ────┤
sack ───┤──▶ STOCK OBJECT ──▶ tracking ──▶ line crossing ──▶ one movement event
pallet ─┤
parcel ─┘
person ─▶ (not a stock object — never a movement)
```

`MovementStateMachine` counts a **stock-object crossing**, whatever its type. Advisory subclass
(`carton`/`bag`/`sack`/`pallet`/…) may ride along as metadata but is **never required** for a movement.
Primary operational metric, unchanged: **one physical stock object crossing → exactly one movement
event.**

### Benchmark scenarios added (tests, no production change)
- **Mixed types crossing simultaneously** (carton + bag + sack OUT at once) → exactly 3 movements.
- **Person carrying nothing** → 0 movements (person is not stock).
- **One worker with a pallet + one empty-handed** → exactly 1 movement.
These join the existing 9 acceptance scenarios in `tests/test_movement.py` + `tests/movement_harness.py`.

## Single-camera visibility limitations (documented honestly)

We must **not** promise "one camera counts every object under every circumstance." A single view cannot
reliably resolve:
- **fully overlapping / stacked** objects that project as one blob (3 stacked sacks ≈ one shape),
- objects **occluded behind the body** as a worker passes,
- dense simultaneous crossings where tracks merge.

These are **physics/visibility limits, not model bugs.** Product remediation (Phase-2+, not now):
better camera **placement/angle**, **multiple cameras** per door, a defined **counting zone/chokepoint**,
and — for hard environments — **auxiliary sensors** (weight/barcode/beam-break). The benchmark should
quantify where a single camera's count degrades so placement vs multi-camera is a *measured* decision,
not a promise.

## DEFECT found by the mixed scenario (flagged, not yet fixed)

The simultaneous-crossing test surfaced a real bug in the **frozen** `carton.move` contract:

- **Symptom:** three objects crossing the same direction at one timestamp produce only **2 distinct
  `uid`s** (one collision).
- **Root cause:** `carton_move()` derives the `uid` from `track_id=track` — the **associated *person*
  track** (or `"?"` when unassociated) — not the stock object's own track. `event_uid` therefore
  collides whenever multiple objects share one worker (or none is attributed).
- **Impact:** ShopBot ingest has `unique(tenant_id, event_uid)`; colliding events are **dropped as
  duplicates**, so a genuine multi-object crossing is **undercounted** — directly breaking the primary
  metric at the ingest layer.
- **Fix (small, surgical, awaiting approval — touches the frozen contract + `MovementStateMachine`):**
  key the `uid` on the **stock-object track** (`c.track_id`), and keep the associated person only in
  `assoc.track_id` (attribution is a separate concern from identity). One extra argument to
  `carton_move()` and one line in `_handle_crossing`.
- **Status:** documented as an `xfail` test (`test_mixed_crossings_should_have_distinct_uids`) and a
  harness KNOWN-ISSUE line so the suite stays green while the defect is on record. **Not fixed here** —
  the contract is frozen; this needs an explicit go.

## Scope

No reconciliation / production movement-code change in this task — only additive tests + this doc. The
detector remains behind the `Detector` interface; the fix above is recommended but deferred to approval.
