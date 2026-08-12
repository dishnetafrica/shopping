"""P0-8 — measuring whether any of this is actually right.

Every threshold in P0-3 to P0-7 was chosen by measuring synthetic aisles. P0-5's
confidence is documented in its own docstring as "not a calibrated probability". Nothing
in the system has ever been compared against reality, because until P0-7 there was no
reality to compare against. There is now: acknowledged events, and — because
prune_snapshots protects them — the frames behind them.

THE THING THIS MODULE MUST NOT DO is produce a confident-looking number from six labels.
That would be the same failure as the fabricated confidence the audit found: a figure
that reads as knowledge and is not. So every rate here travels with a Wilson interval
and a sample size, and below MIN_LABELS the framework refuses to report a rate at all
rather than printing "precision 100%" from three answers.

WHAT CANNOT BE MEASURED THIS WAY, and it is half of what people will ask for:

    Acknowledgements are answers to alerts WE SENT. They can tell us how often we were
    right to shout. They can never tell us how often a shelf sat empty and we said
    nothing, because nobody is asked about an alert that never arrived.

    So this module reports PRECISION and refuses to report RECALL. Recall needs
    independent evidence — a person reporting a stockout the box missed — which is what
    `missed_reports` collects. Until that table has entries, any recall figure would be
    invented, and inventing one is how a demo becomes a lie.
"""
from __future__ import annotations

import math
import os

import cv2

from . import detector

# Below this many labels, report counts and refuse to report a rate. Twenty is not a
# statistical threshold — no small number is — it is the point below which a Wilson
# interval is so wide that quoting the point estimate misleads more than it informs.
MIN_LABELS = int(os.environ.get("E_MIN_LABELS", "20"))

# 95%. The exact value, not the 1.96 that textbooks round to — tables computed with
# 1.96 differ from this in the fourth decimal, which is worth knowing before someone
# "corrects" a test that compares against a published figure.
Z_95 = 1.959964


def wilson_interval(successes: int, n: int, z: float = Z_95) -> tuple[float, float]:
    """A confidence interval that behaves at small n and at the edges.

    The normal approximation — p ± z·sqrt(p(1-p)/n) — gives an interval of ZERO WIDTH
    when every label agrees, so eight-for-eight would report "precision 100%, ±0". That
    is exactly the failure this module exists to avoid, and it is why Wilson is used
    instead of the formula everyone remembers.
    """
    if n <= 0:
        return (0.0, 1.0)
    p = successes / n
    z2 = z * z
    denom = 1.0 + z2 / n
    centre = (p + z2 / (2 * n)) / denom
    margin = (z / denom) * math.sqrt(p * (1 - p) / n + z2 / (4 * n * n))
    return (max(0.0, centre - margin), min(1.0, centre + margin))


def precision(labels: list[dict]) -> dict:
    """How often an alert we sent was right — and how sure we are of that.

    Only alertable events count. Including suppressed ones would measure something
    nobody experiences: the shopkeeper's impression of this system is formed entirely
    by the alerts that reached them.
    """
    alerted = [x for x in labels if x.get("alertable") and x.get("ack_verdict")]
    tp = sum(1 for x in alerted if x["ack_verdict"] == "true")
    fp = sum(1 for x in alerted if x["ack_verdict"] == "false")
    n = tp + fp
    lo, hi = wilson_interval(tp, n)
    out = {
        "alerts_judged": n,
        "true_positives": tp,
        "false_positives": fp,
        "recall": None,
        "recall_unavailable_because":
            "acknowledgements only answer alerts we sent, so they cannot reveal a "
            "stockout we stayed silent about. Recall needs missed_reports.",
    }
    if n < MIN_LABELS:
        out["precision"] = None
        out["enough_data"] = False
        out["note"] = (f"{n} judged alerts is too few to quote a rate — "
                       f"{MIN_LABELS} is the floor. Counts above are still real.")
    else:
        out["precision"] = round(tp / n, 4)
        out["precision_95_low"] = round(lo, 4)
        out["precision_95_high"] = round(hi, 4)
        out["enough_data"] = True
    return out


def rescore(frame_path: str, polygon: list, references: list,
            empty_below: float, low_below: float) -> dict | None:
    """Re-run the detector over a stored frame.

    This is the engine of the whole milestone. It is what lets a question like "what
    would empty_below = 0.30 have done?" be answered with the shop's own shelves
    instead of with another synthetic aisle.

    Returns None when the frame is gone — which must be reported, not silently skipped:
    a sweep computed over the half of the labels whose images survived, presented as if
    it covered all of them, is a lie of omission.
    """
    img = cv2.imread(frame_path) if frame_path else None
    if img is None:
        return None
    try:
        cur = detector.describe(img, polygon)
    except (ValueError, cv2.error):
        return None
    fill = detector.fill_score_multi(cur, references)
    state = detector.classify(fill, empty_below, low_below)
    return {"fill": fill, "state": state,
            "confidence": detector.confidence(fill, state, empty_below, low_below)}


def sweep(scored: list[dict], thresholds: list[float], low_below: float) -> list[dict]:
    """What each candidate empty_below would have done to the labelled set.

    `scored` carries the re-computed fill and the human verdict; changing a threshold
    cannot change the fill, so the frames are re-read once and the thresholds are
    applied to the numbers. A sweep that re-decoded every JPEG per threshold would be
    slower and identical.
    """
    out = []
    for t in thresholds:
        tp = fp = fn = 0
        for s in scored:
            called_empty = s["fill"] < t
            really_empty = s["verdict"] == "true"
            if called_empty and really_empty:
                tp += 1
            elif called_empty and not really_empty:
                fp += 1
            elif not called_empty and really_empty:
                fn += 1
        n = tp + fp
        lo, hi = wilson_interval(tp, n)
        out.append({
            "empty_below": round(t, 4),
            "low_below": low_below,
            "would_alert": n,
            "true_positives": tp,
            "false_positives": fp,
            # Not "recall". This counts labelled events the threshold would have STOPPED
            # alerting on — a real loss, but measured only among alerts we already sent.
            "labelled_misses": fn,
            "precision": None if n < MIN_LABELS else round(tp / n, 4),
            "precision_95_low": None if n < MIN_LABELS else round(lo, 4),
            "precision_95_high": None if n < MIN_LABELS else round(hi, 4),
        })
    return out


def calibration(scored: list[dict], bins: int = 5) -> list[dict]:
    """Is a confidence of 0.9 right about nine times in ten?

    P0-5 says in its own docstring that its confidence is margin-from-threshold and not
    a probability. This is the check that turns that caveat into a number. If the
    observed rate tracks the bin, the caveat can be softened; if it does not, the
    docstring stays and nobody should read the figure as a likelihood.
    """
    edges = [i / bins for i in range(bins + 1)]
    out = []
    for i in range(bins):
        lo_edge, hi_edge = edges[i], edges[i + 1]
        inside = [s for s in scored
                  if s.get("confidence") is not None
                  and (lo_edge <= s["confidence"] < hi_edge
                       or (i == bins - 1 and s["confidence"] == 1.0))]
        n = len(inside)
        correct = sum(1 for s in inside if s["verdict"] == "true")
        lo, hi = wilson_interval(correct, n)
        out.append({
            "confidence_from": round(lo_edge, 2),
            "confidence_to": round(hi_edge, 2),
            "n": n,
            "observed_correct": None if n == 0 else round(correct / n, 4),
            "observed_95_low": None if n == 0 else round(lo, 4),
            "observed_95_high": None if n == 0 else round(hi, 4),
            "enough_data": n >= MIN_LABELS,
        })
    return out
