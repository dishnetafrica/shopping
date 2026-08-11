"""Probe 2. Enrolment cannot be judged at enrolment time — but a bad reference
betrays itself in OPERATION, and the current code throws that evidence away.

fill_score clips edge_ratio at 1.0. So a zone enrolled on a half-empty shelf, later
looking at a genuinely full one, computes ratio 1.79 and reports 1.0 — "perfectly
stocked". The number that would have told us the reference is wrong is discarded on
the line that computes it.

Keep it, require persistence, and permanent silent blindness becomes an alert.

The threshold has to clear the widest ratio a CORRECTLY enrolled zone can produce on a
frame the quality gate would actually let through. Frames the gate rejects never reach
the detector, so they must not be used to set it.
"""
import sys

import cv2
import numpy as np

sys.path.insert(0, "tests")
sys.path.insert(0, ".")
import frames as F  # noqa: E402
from app import detector, quality  # noqa: E402


def ratio(cur_img, ref_desc):
    cur = detector.describe(cur_img, F.ZONE)
    return cur.edge_density / (ref_desc.edge_density + detector.EDGE_EPS)


full = F.as_jpeg(F.aisle(facings=F.FULL))
ref_full = detector.describe(full, F.ZONE)
base = quality.baseline_signature(full)

print("CORRECTLY ENROLLED ZONE (ref = full shelf).")
print("Worst legitimate ratio sets the floor for any alarm threshold.\n")
print(f"  {'condition':<26} {'ratio':>7}  {'gate verdict':<12}")
legit = {
    "identical":                 F.aisle(facings=F.FULL),
    "different noise seed":      F.aisle(facings=F.FULL, seed=7),
    "seed 13":                   F.aisle(facings=F.FULL, seed=13),
    "lights 10% up":             F.aisle(facings=F.FULL, bright=1.10),
    "lights 15% up":             F.aisle(facings=F.FULL, bright=1.15),
    "lights 35% up":             F.aisle(facings=F.FULL, bright=1.35),
    "lights 20% down":           F.aisle(facings=F.FULL, bright=0.80),
    "restocked deeper (seed 3)": F.aisle(facings=F.FULL, seed=3),
}
worst_passing = 0.0
for name, img in legit.items():
    img = F.as_jpeg(img)
    v = quality.assess_frame(img, base)
    r = ratio(img, ref_full)
    passed = v.ok
    if passed:
        worst_passing = max(worst_passing, r)
    print(f"  {name:<26} {r:>7.3f}  {('OK' if passed else 'REFUSED: ' + v.reason):<12}")

print(f"\n  worst ratio on a GATE-PASSING frame: {worst_passing:.3f}")

print("\n\nBADLY ENROLLED ZONE — reference captured at N facings, shelf later genuinely full.")
print("This is the silent-blindness case the audit found.\n")
print(f"  {'enrolled at':<14} {'ratio when full':>16} {'fill_score':>12}  {'reported':<10}")
for n in (5, 4, 3, 2, 1):
    bad_img = F.as_jpeg(F.aisle(facings=(F.FACINGS, n, F.FACINGS)))
    ref_bad = detector.describe(bad_img, F.ZONE)
    r = ratio(full, ref_bad)
    fs = detector.fill_score(detector.describe(full, F.ZONE), ref_bad)
    print(f"  {n} facings{'':<5} {r:>16.3f} {fs:>12.3f}  {detector.classify(fs, 0.35, 0.6):<10}")

print("\n\nAND THE CASE THAT MUST NOT FIRE: correctly enrolled, shelf simply restocked well.")
print("A shelf can be fuller than enrolment without the reference being wrong.\n")
for label, facings in (("enrolled 6, sees 6", F.FULL),):
    img = F.as_jpeg(F.aisle(facings=facings, seed=21))
    print(f"  {label:<26} ratio {ratio(img, ref_full):.3f}")

print("\n\nQUALITY GATE AT ENROLMENT — would it have refused these frames?")
print("Enrolling from a frame we would refuse to SCORE is indefensible.\n")
for name, img in (("full, sharp",       F.aisle(facings=F.FULL)),
                  ("full, out of focus", F.aisle(facings=F.FULL, blur=6)),
                  ("full, very dark",    F.aisle(facings=F.FULL, bright=0.22)),
                  ("full, shopper in shot", F.aisle(facings=F.FULL, occluder=(200, 430, (60, 60, 70)))),
                  ("all rows empty",     F.aisle(facings=F.ALL_EMPTY))):
    v = quality.assess_frame(F.as_jpeg(img), None)
    print(f"  {name:<24} {'OK' if v.ok else 'REFUSED: ' + v.reason}")
