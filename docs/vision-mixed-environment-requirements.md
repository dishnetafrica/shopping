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

## DEFECT found by the mixed scenario — FIXED

The simultaneous-crossing test surfaced (and the approved surgical fix corrected) a real bug in the
`carton.move` contract:

- **Was:** `carton_move()` derived the `uid` from the **associated *person* track** (or `"?"` when
  unassociated), so three objects crossing the same direction at one timestamp produced only **2
  distinct `uid`s**. ShopBot ingest has `unique(tenant_id, event_uid)`, so the colliding events would be
  **dropped as duplicates** and a real multi-object crossing **undercounted**.
- **Fix (applied):** the `uid` is now keyed on the **stock object's own track** (`object_track_id`); the
  associated person is retained **only** in `assoc.track_id`. **WHO carried it ≠ WHAT moved** — the
  worker is attribution, the stock object is the movement-event identity. Changed:
  `app/movement/contract.py` (`carton_move` signature + uid) and `app/movement/state_machine.py`
  (passes `object_track_id=c.track_id`). No reconciliation/PHP change — ShopBot reads the uid from the
  payload; its 32-char format is unchanged.
- **Regression tests (now passing, xfail removed):** 3 objects + 1 worker → 3 unique uids; 3 objects +
  no attribution → 3 unique; same object retransmitted → 1 event; two objects simultaneously → 2 events;
  uid stable for the same object regardless of attribution.

**Permanent principle:** movement-event identity = the stock object's track; the person is attribution
only. This holds for one worker + 5 bags, two workers + 8 cartons, and pallet + cartons + bags through
one boundary.

## Scope

Movement contract + state machine changed **only** for this approved correctness fix (the freeze was
overridden for it). No reconciliation semantics, ERP architecture, detector architecture, or shelf
vision changed. The detector remains behind the `Detector` interface.
