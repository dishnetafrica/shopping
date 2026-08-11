"""Probe: can we tell a fully-stocked zone from an under-stocked one, at enrolment,
from a single frame, with no model and no ground truth?

The claim to test: an under-stocked shelf exposes a CONTIGUOUS patch of flat back
panel. A full shelf has narrow gaps between facings. So the discriminator is not how
much flat area there is, but how much of it is joined up.

Everything is measured INSIDE the polygon mask. The bounding-rect crop black-fills
outside a tilted polygon, and that black fill is the flattest region in the image —
measure it and every tilted zone would be refused as empty.
"""
import sys

import cv2
import numpy as np

sys.path.insert(0, "tests")
import frames as F  # noqa: E402

EDGE_T = 15.0  # same threshold detector.describe uses


def mask_and_crop(img, polygon):
    pts = np.array(polygon, dtype=np.int32)
    x, y, w, h = cv2.boundingRect(pts)
    mask = np.zeros(img.shape[:2], np.uint8)
    cv2.fillPoly(mask, [pts], 255)
    return img[y:y + h, x:x + w], mask[y:y + h, x:x + w]


def measure(img, polygon):
    crop, mask = mask_and_crop(img, polygon)
    gray = cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY)
    gray = cv2.GaussianBlur(gray, (3, 3), 0)
    lap = np.abs(cv2.Laplacian(gray, cv2.CV_64F))

    inside = mask > 0
    area = int(inside.sum())
    if area == 0:
        return None

    textured = (lap > EDGE_T) & inside
    edge_density = textured.sum() / area

    # Flat = inside the polygon and not textured. Dilate the textured map first so
    # single-pixel product edges close up and the gaps between facings do not read
    # as one continuous void running the length of the shelf.
    tex_u8 = (textured * 255).astype(np.uint8)
    tex_closed = cv2.dilate(tex_u8, np.ones((5, 5), np.uint8), iterations=1)
    flat = ((tex_closed == 0) & inside).astype(np.uint8)

    n, labels, stats, _ = cv2.connectedComponentsWithStats(np.ascontiguousarray(flat), 8)
    largest = 0
    for i in range(1, n):
        largest = max(largest, int(stats[i, cv2.CC_STAT_AREA]))

    return {
        "edge": round(float(edge_density), 4),
        "flat_frac": round(float(flat.sum()) / area, 4),
        "largest_blob": round(largest / area, 4),
    }


print("MIDDLE ROW ZONE — sweeping that row's facings from 6 (full) to 0 (empty)")
print(f"  {'facings':>8} {'edge':>8} {'flat':>8} {'blob':>8}   (through JPEG)")
for n in (6, 5, 4, 3, 2, 1, 0):
    img = F.aisle(facings=(F.FACINGS, n, F.FACINGS))
    img = F.as_jpeg(img)
    m = measure(img, F.ZONE)
    print(f"  {n:>8} {m['edge']:>8.4f} {m['flat_frac']:>8.4f} {m['largest_blob']:>8.4f}")

print("\nSAME, but under conditions a real store throws at enrolment")
cases = {
    "full, dim lights": F.aisle(facings=F.FULL, bright=0.6),
    "full, bright":     F.aisle(facings=F.FULL, bright=1.35),
    "full, other seed": F.aisle(facings=F.FULL, seed=7),
    "full, slight blur": F.aisle(facings=F.FULL, blur=1),
    "half, dim lights": F.aisle(facings=(F.FACINGS, 3, F.FACINGS), bright=0.6),
    "full + shopper":   F.aisle(facings=F.FULL, occluder=(240, 380, (60, 60, 70))),
}
for name, img in cases.items():
    img = F.as_jpeg(img)
    m = measure(img, F.ZONE)
    print(f"  {name:<20} edge {m['edge']:.4f}  flat {m['flat_frac']:.4f}  blob {m['largest_blob']:.4f}")

print("\nTILTED POLYGON — the black-fill trap. A tilted zone over a FULL shelf.")
tilt = [[20, 92 + F.ROW_H], [F.W - 20, 82 + F.ROW_H],
        [F.W - 20, 78 + 2 * F.ROW_H - 18], [20, 88 + 2 * F.ROW_H - 18]]
img = F.as_jpeg(F.aisle(facings=F.FULL))
m = measure(img, tilt)
print(f"  tilted, full shelf   edge {m['edge']:.4f}  flat {m['flat_frac']:.4f}  blob {m['largest_blob']:.4f}")

print("\nSILENT BLINDNESS — what the current code does with a half-stocked reference")
sys.path.insert(0, ".")
from app import detector  # noqa: E402

full = F.as_jpeg(F.aisle(facings=F.FULL))
half = F.as_jpeg(F.aisle(facings=(F.FACINGS, 3, F.FACINGS)))
empty = F.as_jpeg(F.aisle(facings=F.MIDDLE_EMPTY))

ref_good = detector.describe(full, F.ZONE)
ref_bad = detector.describe(half, F.ZONE)
for label, ref in (("enrolled FULL", ref_good), ("enrolled HALF", ref_bad)):
    scores = {n: detector.fill_score(detector.describe(i, F.ZONE), ref)
              for n, i in (("full", full), ("half", half), ("empty", empty))}
    print(f"  {label}: full={scores['full']:.3f} half={scores['half']:.3f} empty={scores['empty']:.3f}")
