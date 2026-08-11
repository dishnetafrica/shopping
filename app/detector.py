"""Zone detector v0 — reference-based fill scoring.

Honest baseline, deliberately not a neural net: for a FIXED camera, a zone that
was enrolled while fully stocked has a characteristic texture (edge density)
and color distribution. An emptying shelf exposes the flat back panel: edges
collapse and the histogram drifts. fill_score in [0,1] combines both.

This is Milestone 1. It answers 'empty vs not empty' reliably on fixed views
and is replaced (behind the same interface) by a learned detector in M2.
State changes require N consecutive confirmations (hysteresis) — alert
precision is the product; the model is plumbing.
"""
from __future__ import annotations

import json
import os
from dataclasses import dataclass

import cv2
import numpy as np

HIST_BINS = (8, 8, 4)   # H, S, V
EDGE_EPS = 1e-6
CONFIRMATIONS = 2       # consecutive evaluations required to switch state

# ── reference sanity (P0-4) ───────────────────────────────────────────────────────
# A zone enrolled on an under-stocked shelf is the worst failure in this system,
# because it is silent AND permanent: edge_ratio below saturates at 1.0, so the zone
# reports STOCKED for ever and nothing ever contradicts it.
#
# It cannot be caught at enrolment. "Is this shelf full?" has no answer from a single
# frame with nothing to compare against — which is exactly what enrolment is creating.
# Measured on the aisle fixtures: a full shelf reads 82% flat area and an empty one
# 97%, while the SAME full shelf under dim lighting reads 84%. Lighting moves every
# single-frame stock proxy further than stock does. Anything built on one would refuse
# honest enrolments in dim aisles and accept bad ones in bright aisles.
#
# But a bad reference betrays itself in OPERATION, and fill_score already computes the
# evidence before throwing it away. Measured, through JPEG, on gate-passing frames:
#
#   correctly enrolled, worst legitimate ratio    1.115   (lights 15% up; beyond
#                                                          that the gate refuses first)
#   enrolled at 5 of 6 facings, shelf now full    1.174
#   enrolled at 4 of 6 facings                    1.423
#   enrolled at 3 of 6 facings                    1.791
#   enrolled at 2 of 6 facings                    2.359
#
# 1.25 sits above every legitimate reading and below every four-facing-or-worse error.
#
# The 5-of-6 case at 1.174 is NOT caught, deliberately. It falls inside the lighting
# band, and a threshold low enough to catch it would fire on ordinary lighting drift —
# trading a silent failure for a nagging one. A zone desensitised by one facing in six
# still reports empty when the shelf empties, which is the promise being made.
SUSPECT_ABOVE = float(os.environ.get("D_SUSPECT_ABOVE", "1.25"))
SUSPECT_CONFIRMATIONS = int(os.environ.get("D_SUSPECT_CONFIRMATIONS", "3"))


@dataclass
class ZoneDescriptor:
    edge_density: float
    hist: list

    def to_json(self) -> str:
        return json.dumps({"edge_density": self.edge_density, "hist": self.hist})

    @staticmethod
    def from_json(s: str) -> "ZoneDescriptor":
        d = json.loads(s)
        return ZoneDescriptor(edge_density=d["edge_density"], hist=d["hist"])


def _crop_zone(img: np.ndarray, polygon: list[list[int]]) -> np.ndarray:
    pts = np.array(polygon, dtype=np.int32)
    x, y, w, h = cv2.boundingRect(pts)
    x, y = max(x, 0), max(y, 0)
    w = min(w, img.shape[1] - x)
    h = min(h, img.shape[0] - y)
    if w < 4 or h < 4:
        raise ValueError("zone polygon too small or outside image")
    mask = np.zeros(img.shape[:2], np.uint8)
    cv2.fillPoly(mask, [pts], 255)
    crop = cv2.bitwise_and(img, img, mask=mask)[y:y + h, x:x + w]
    return crop


def describe(img: np.ndarray, polygon: list[list[int]]) -> ZoneDescriptor:
    crop = _crop_zone(img, polygon)
    gray = cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY)
    gray = cv2.GaussianBlur(gray, (3, 3), 0)  # suppress sensor noise before edge measure
    lap = cv2.Laplacian(gray, cv2.CV_64F)
    # strong-edge FRACTION, not mean: robust to zone size — product borders and
    # label print exceed the threshold, blurred sensor noise does not, and the
    # fraction shrinks as facings disappear (a direct fill proxy)
    edge_density = float(np.mean(np.abs(lap) > 15.0))
    hsv = cv2.cvtColor(crop, cv2.COLOR_BGR2HSV)
    hist = cv2.calcHist([hsv], [0, 1, 2], None, list(HIST_BINS),
                        [0, 180, 0, 256, 0, 256])
    cv2.normalize(hist, hist)
    return ZoneDescriptor(edge_density=edge_density, hist=hist.flatten().tolist())


def fill_score(current: ZoneDescriptor, reference: ZoneDescriptor) -> float:
    """1.0 ≈ looks like the enrolled fully-stocked reference; 0.0 ≈ nothing there."""
    edge_ratio = min(current.edge_density / (reference.edge_density + EDGE_EPS), 1.0)
    h1 = np.array(reference.hist, dtype=np.float32)
    h2 = np.array(current.hist, dtype=np.float32)
    corr = float(cv2.compareHist(h1, h2, cv2.HISTCMP_CORREL))
    corr = max(0.0, min(1.0, corr))
    # Texture is the primary signal (empty back panels are flat); color similarity
    # modulates it so a *different* product blocking the zone can't score full.
    return round(edge_ratio * (0.5 + 0.5 * corr), 4)


def edge_ratio_raw(current: ZoneDescriptor, reference: ZoneDescriptor) -> float:
    """The number fill_score computes and then discards on `min(..., 1.0)`.

    Above 1.0 means the zone currently looks *fuller* than the view it was enrolled
    against. Sustained, that is not good news about the shelf — it is evidence the
    reference was captured on a shelf that was not full.
    """
    return current.edge_density / (reference.edge_density + EDGE_EPS)


def references_from_json(primary: str | None, many: str | None) -> list[ZoneDescriptor]:
    """Every enrolled view of this zone.

    `many` is the P0-4 JSON array; `primary` is the single pre-P0-4 column. A box
    upgraded in place has only the latter and must keep working untouched, so the
    array is preferred and the single column is the fallback — never both, or the
    first enrolment would be counted twice and drag the best-match down.
    """
    out: list[ZoneDescriptor] = []
    if many:
        try:
            for d in json.loads(many):
                out.append(ZoneDescriptor(edge_density=float(d["edge_density"]),
                                          hist=d["hist"]))
        except (ValueError, KeyError, TypeError):
            out = []          # corrupt array: fall back rather than blind the zone
    if not out and primary:
        try:
            out = [ZoneDescriptor.from_json(primary)]
        except (ValueError, KeyError, TypeError):
            out = []
    return out


def fill_score_multi(current: ZoneDescriptor, references: list[ZoneDescriptor]) -> float:
    """Best match across every enrolled view.

    Store lighting changes through the day and a single reference makes that read as
    stock movement. With a morning and an evening reference the zone is scored against
    whichever it resembles most, so the answer is about the shelf rather than the hour.

    Max, not mean: a zone that matches ANY known-full view is stocked. Averaging would
    let one unrepresentative reference drag every honest reading down.
    """
    if not references:
        raise ValueError("zone has no reference")
    return max(fill_score(current, r) for r in references)


def suspect_ratio(current: ZoneDescriptor, references: list[ZoneDescriptor]) -> float:
    """How much fuller this frame looks than the FULLEST view the zone knows.

    Minimum across references, deliberately. If any enrolled view already explains a
    frame this full, the references are fine and there is nothing to report — only a
    frame fuller than every one of them is evidence of a bad enrolment.
    """
    if not references:
        return 0.0
    return min(edge_ratio_raw(current, r) for r in references)


def step_suspect(count: int, flagged: bool, ratio: float) -> tuple[int, bool, bool]:
    """Hysteresis for the reference-suspect flag.

    Returns (new_count, new_flagged, changed). Same shape as step_state and for the
    same reason: one frame is never enough. A shopper's bright coat filling the zone
    raises the ratio for a single capture, and firing on that would teach the operator
    to ignore the one alert that means their shelf is not being watched.
    """
    if ratio > SUSPECT_ABOVE:
        count += 1
    else:
        count = 0
    should = count >= SUSPECT_CONFIRMATIONS
    if should and not flagged:
        return count, True, True
    if flagged and count == 0:
        return 0, False, True          # re-enrolled, or the shelf settled back
    return count, flagged, False


def classify(fill: float, empty_below: float, low_below: float) -> str:
    if fill < empty_below:
        return "empty"
    if fill < low_below:
        return "low"
    return "stocked"


def step_state(stored_state: str, pending_state: str, pending_count: int,
               observed: str) -> tuple[str, str, int, bool]:
    """Hysteresis state machine.

    Returns (new_state, new_pending, new_pending_count, changed).
    A change is committed only after CONFIRMATIONS consecutive observations.
    """
    if observed == stored_state:
        return stored_state, "", 0, False
    if observed == pending_state:
        pending_count += 1
    else:
        pending_state, pending_count = observed, 1
    if pending_count >= CONFIRMATIONS or stored_state == "unknown":
        return observed, "", 0, True
    return stored_state, pending_state, pending_count, False
