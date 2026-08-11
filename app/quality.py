"""Can this frame be trusted? Asked before anything asks what it shows.

The audit measured what happens without this. Every row below is a COMPLETELY FULL
shelf that the detector called EMPTY, because everything it measures — edge density
against an enrolled reference — collapses for reasons that have nothing to do with
stock:

    light 40% dimmer   -> EMPTY        dusty lens    -> EMPTY
    light 55% dimmer   -> EMPTY        out of focus  -> EMPTY
    shopper in grey    -> EMPTY        camera nudged -> STOCKED (silently blind)

A shelf sensor that says "I cannot see" is worth more to a shopkeeper than one that
guesses. So this module refuses frames, and the scheduler does not classify a refused
frame at all: no state change, no confirmation counted, no alert.

THE RULE THAT SHAPED EVERY THRESHOLD HERE: a gate that suppresses real stockouts is
worse than no gate. An empty shelf is legitimately dark, flat and textureless, so any
check calibrated on "the frame looks featureless" refuses exactly the thing we are paid
to detect. Every threshold below was measured against frames where a whole gondola is
empty, and every one of them has to pass. tests/test_quality.py holds that line.

MEASURED, not chosen (tests/test_quality.py::test_thresholds_*, calibration in the P0-3
report). The two columns that matter are "what a genuinely empty shelf does" and "what
the detector's first wrong answer does":

    check      empty gondola   first wrong answer      threshold
    lighting        1.2%            20.4%                18%
    sharpness       0.53            0.18 (dusty)         0.35
    movement        0.0px           6.0px (8px nudge)    4.0px
    obstruction     0.4%            30%   (red coat)     5%

Thresholds are env-overridable because a 4MP dome and a D1 analog channel on a tribrid
DVR do not produce the same numbers. Setting them per site properly is what the labelled
evaluation set in P0-9 is for; these are defensible defaults, not final answers.
"""
from __future__ import annotations

import os
from dataclasses import dataclass, field

import cv2
import numpy as np

# Frames are measured at a fixed width, so the same view from a 4MP dome and a D1
# channel produces comparable numbers and one threshold can serve both.
WORK_W = int(os.environ.get("Q_WORK_WIDTH", "480"))

# ── absolute backstops: a frame this bad is unusable whatever it is pointed at ─────
# A genuinely empty gondola measures std 36 / mean 90, so these sit far below it.
FLAT_BELOW = float(os.environ.get("Q_FLAT_BELOW", "8"))        # std of luma; black/white/lens cap = 0
DARK_BELOW = float(os.environ.get("Q_DARK_BELOW", "35"))       # mean luma; covered lens = 30
BRIGHT_ABOVE = float(os.environ.get("Q_BRIGHT_ABOVE", "225"))  # mean luma; white-out = 255
CLIPPED_ABOVE = float(os.environ.get("Q_CLIPPED_ABOVE", "0.35"))
BLUR_ABS_BELOW = float(os.environ.get("Q_BLUR_ABS_BELOW", "40"))  # used only with no baseline

# ── relative to enrolment: has the world changed so much the reference is void? ────
LIGHT_CHANGE = float(os.environ.get("Q_LIGHT_CHANGE", "0.18"))
BLUR_RATIO_BELOW = float(os.environ.get("Q_BLUR_RATIO", "0.35"))
MOVE_PX = float(os.environ.get("Q_MOVE_PX", "4.0"))            # at WORK_W
NOVEL_ABOVE = float(os.environ.get("Q_NOVEL_ABOVE", "0.05"))   # largest novel BLOB, not scattered

REASONS = ("moved", "black", "dark", "bright", "lighting", "blurred", "obstructed")


@dataclass
class Verdict:
    ok: bool
    reason: str = ""
    detail: str = ""
    metrics: dict = field(default_factory=dict)


def _work(img: np.ndarray) -> np.ndarray:
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY) if img.ndim == 3 else img
    if gray.shape[1] != WORK_W:
        h = max(1, int(gray.shape[0] * WORK_W / gray.shape[1]))
        gray = cv2.resize(gray, (WORK_W, h), interpolation=cv2.INTER_AREA)
    return gray


def frame_metrics(img: np.ndarray) -> dict:
    g = _work(img)
    return {
        "mean": round(float(np.mean(g)), 2),
        "std": round(float(np.std(g)), 2),
        "clipped": round(float(np.mean(g > 250)), 4),
        "blur": round(float(np.var(cv2.Laplacian(g, cv2.CV_64F))), 2),
    }


def baseline_signature(img: np.ndarray) -> bytes:
    """A small grayscale PNG kept per camera, captured at enrolment.

    Movement and lighting are judged against ENROLMENT, never against the previous
    frame. A camera that drifts a pixel a day never trips a frame-to-frame check and is
    still pointing somewhere else by Christmas, with a reference describing a shelf it
    can no longer see. Same for light: a shop that dims by 2% a week never trips, and
    the reference quietly stops applying.
    """
    ok, buf = cv2.imencode(".png", _work(img))
    return buf.tobytes() if ok else b""


def _decode(blob: bytes | None) -> np.ndarray | None:
    if not blob:
        return None
    return cv2.imdecode(np.frombuffer(blob, np.uint8), cv2.IMREAD_GRAYSCALE)


def light_gain(cur_gray: np.ndarray, base_gray: np.ndarray) -> float:
    """Median per-pixel brightness ratio — how much the LIGHT changed, not the stock.

    Comparing mean brightness does not work: emptying a whole gondola drops the frame
    mean 17%, which is the same order as the dimming we need to catch, so a mean-based
    threshold either misses real dimming or refuses real stockouts. A lighting change
    scales every pixel; a stock change scales only the pixels where the stock was. The
    median ratio therefore ignores the stock and reports the light.

    Measured: emptying every shelf moves this 1.2%. Dimming 20% moves it 20.4%.
    """
    m = base_gray > 12                      # ratios are meaningless in near-black pixels
    if not m.any():
        return 1.0
    return float(np.median(cur_gray[m].astype(np.float32) / base_gray[m].astype(np.float32)))


def frame_shift(cur_gray: np.ndarray, base_gray: np.ndarray) -> float | None:
    """Translation in pixels since enrolment, or None if it cannot be measured.

    Measured range on a 640px frame: linear and accurate from 8px to at least 300px
    (8 -> 6.0, 100 -> 74.9, 300 -> 225.0 at the 480px working width), with nothing below
    4px so ordinary vibration does not trip it.

    Nothing else moves it. Emptying one shelf, emptying the whole gondola, a shopper
    standing in the aisle, 40% dimming and a defocused lens all measure 0.0-0.1px, so
    this cannot be fooled into refusing a real stockout — the failure that would matter
    most for a check that blocks classification.

    (An earlier draft of this docstring claimed a periodic-aliasing blind spot at large
    shifts. That was an artefact of a test fixture which slid the PRODUCTS along the
    shelf instead of moving the camera; once the whole scene moves, as it does when a
    camera is actually knocked, there is no such blind spot in the measured range.)
    """
    if base_gray is None or base_gray.shape != cur_gray.shape:
        return None
    try:
        (dx, dy), _ = cv2.phaseCorrelate(np.float32(base_gray), np.float32(cur_gray))
    except cv2.error:
        return None
    return float(np.hypot(dx, dy))


def assess_frame(img: np.ndarray, baseline_blob: bytes | None = None) -> Verdict:
    """Whole-frame trust. Runs once per snapshot, before any zone is looked at.

    Order is deliberate: a camera that has moved makes every later measurement
    meaningless, and a lighting change explains a drop in sharpness, so lighting is
    reported before blur or the message blames the lens for the light switch.
    """
    m = frame_metrics(img)
    cur = _work(img)
    base = _decode(baseline_blob)
    m["baseline"] = base is not None

    if base is not None and base.shape == cur.shape:
        shift = frame_shift(cur, base)
        if shift is not None:
            m["shift_px"] = round(shift, 2)
            if shift > MOVE_PX:
                return Verdict(False, "moved",
                               f"the camera has moved about {shift:.0f}px since this view was "
                               f"enrolled — every zone on it now describes the wrong place", m)

    # Brightness before flatness: a blown-out frame is ALSO perfectly flat, and telling
    # an installer his picture is "black" when it is white sends him to the wrong wall.
    if m["mean"] > BRIGHT_ABOVE or m["clipped"] > CLIPPED_ABOVE:
        return Verdict(False, "bright",
                       f"washed out (brightness {m['mean']:.0f}, "
                       f"{m['clipped'] * 100:.0f}% of the picture pure white)", m)
    if m["std"] < FLAT_BELOW:
        return Verdict(False, "black",
                       f"almost no detail in the picture (spread {m['std']:.0f}) — a covered "
                       f"lens, a dead channel, or a blank frame", m)
    if m["mean"] < DARK_BELOW:
        return Verdict(False, "dark",
                       f"too dark to read the shelf (brightness {m['mean']:.0f} of 255)", m)

    if base is not None and base.shape == cur.shape:
        gain = light_gain(cur, base)
        m["light_gain"] = round(gain, 3)
        if abs(gain - 1.0) > LIGHT_CHANGE:
            way = "dimmer" if gain < 1 else "brighter"
            return Verdict(False, "lighting",
                           f"the light is {abs(1 - gain) * 100:.0f}% {way} than when this view "
                           f"was enrolled — the shelf cannot be judged against that reference", m)
        base_blur = float(np.var(cv2.Laplacian(base, cv2.CV_64F)))
        ratio = m["blur"] / max(base_blur, 1e-6)
        m["blur_ratio"] = round(ratio, 3)
        if ratio < BLUR_RATIO_BELOW:
            return Verdict(False, "blurred",
                           f"much softer than when enrolled ({ratio * 100:.0f}% of the original "
                           f"sharpness) — dirt, rain on the dome, or lost focus", m)
    elif m["blur"] < BLUR_ABS_BELOW:
        return Verdict(False, "blurred",
                       f"out of focus or dirty (sharpness {m['blur']:.0f}) — a soft picture "
                       f"looks exactly like an empty shelf", m)

    return Verdict(True, "", "", m)


# ── obstruction ───────────────────────────────────────────────────────────────────

def novel_colour_fraction(crop_hsv: np.ndarray, ref_hist: list, bins) -> tuple[float, float]:
    """(scattered, largest_blob) — how much of this zone is a colour the reference
    never contained, and how much of that is in ONE connected region.

    THE MEASUREMENT THAT DECIDED THE SHAPE OF THIS. The scattered fraction alone does
    not work, and it took a JPEG round-trip to find out. Compression fringes every sharp
    product edge with colours that were not in the reference, so a busy full shelf
    scores 8.5% while a shelf with a BLUE COAT across it scores 5.8% — the signal points
    the wrong way. Measured on raw arrays it looked fine; the real pipeline only ever
    sees encoded frames, and the first live walkthrough reported a shopper standing in
    front of an ordinary, unobstructed, fully stocked shelf.

    A coat is one solid region. JPEG fringing is thin and scattered along every edge. So
    the decision is made on the largest connected component after an opening, which
    separates cleanly:

        every shelf state (full / half / empty / empty gondola)   0.0% – 0.4%
        red coat 30%   ·   yellow hi-vis 37%   ·   dark coat 37%

    AND WHAT IT DOES NOT DO, measured on the same frames: a blue coat, a grey coat, a
    brown trolley and a white shirt all score 0.3%, because blue, grey, brown and white
    are already on that shelf as products, back panel and price-rail tickets. A colour
    test cannot see an obstruction the colour of the thing it is covering.

    So this catches roughly half of the obstructions in the fixture set, with no false
    positives on any shelf state. It is a partial mitigation and is described as one
    everywhere it is reported. Occlusion done properly needs person detection (a model,
    deliberately deferred) or the temporal layer in P0-5 — a shopper is transient, a gap
    persists, and that difference is far more separable than colour.
    """
    if crop_hsv is None or crop_hsv.size == 0 or not ref_hist:
        return 0.0, 0.0
    ref = np.array(ref_hist, dtype=np.float32).reshape(bins)
    present = ref > (ref.max() * 0.02 if ref.max() > 0 else 0)

    h = np.clip(crop_hsv[..., 0].astype(np.int32) * bins[0] // 180, 0, bins[0] - 1)
    s = np.clip(crop_hsv[..., 1].astype(np.int32) * bins[1] // 256, 0, bins[1] - 1)
    v = np.clip(crop_hsv[..., 2].astype(np.int32) * bins[2] // 256, 0, bins[2] - 1)

    # The crop is black outside the polygon; those pixels are not shelf and must count
    # as neither familiar nor novel. Skipping this dilutes the measurement in proportion
    # to how tilted the zone is — a marginal case in a steeply drawn zone reads 22.5%
    # masked and 6.4% unmasked.
    inside = ~((crop_hsv[..., 2] == 0) & (crop_hsv[..., 1] == 0))
    area = float(inside.sum())
    if area < 1:
        return 0.0, 0.0

    novel = (~present[h, s, v]) & inside
    scattered = float(novel.sum()) / area

    # ascontiguousarray is not decoration: connectedComponentsWithStats reads the buffer
    # directly and segfaulted on a non-contiguous one during mutation testing. A crash
    # in a shop is a dead box, which is worse than any wrong answer it could have given.
    mask = np.ascontiguousarray(novel.astype(np.uint8))
    opened = cv2.morphologyEx(mask, cv2.MORPH_OPEN, np.ones((5, 5), np.uint8))
    count, _, stats, _ = cv2.connectedComponentsWithStats(np.ascontiguousarray(opened), 8)
    blob = float(stats[1:, cv2.CC_STAT_AREA].max()) / area if count > 1 else 0.0
    return scattered, blob


def assess_zone(crop_hsv: np.ndarray, ref_hist: list, bins) -> Verdict:
    scattered, blob = novel_colour_fraction(crop_hsv, ref_hist, bins)
    m = {"novel_scattered": round(scattered, 4), "novel_blob": round(blob, 4)}
    if blob > NOVEL_ABOVE:
        return Verdict(False, "obstructed",
                       f"{blob * 100:.0f}% of this zone is one solid patch of a colour that was "
                       f"not there when it was enrolled — something is in front of it, or the "
                       f"products have changed and it needs enrolling again", m)
    return Verdict(True, "", "", m)
