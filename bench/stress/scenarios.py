"""
Stress scenarios: each yields a frame STREAM (list of (ts, [ScriptedDetection])) plus the GROUND-TRUTH
crossing times, so a perturbed run can be scored against what physically happened. Objects move across a
horizontal line at y=300; stock objects are class 1, people class 0. Lanes are well separated so real
ByteTrack keeps distinct tracks — the point is to stress the STREAM, not to defeat the tracker with
overlapping boxes (that is the documented single-camera visibility limit, not this test).
"""
from __future__ import annotations

from app.movement.detector import ScriptedDetection

LINE_Y = 300.0
FRAMES = 16


def _track_boxes(x, y0, y1, frames=FRAMES):
    out = []
    for i in range(frames):
        t = i / (frames - 1)
        yc = y0 + (y1 - y0) * t
        out.append((x - 20, yc - 20, x + 20, yc + 20))
    return out


def _compose(objects, people, frames=FRAMES):
    """objects/people: list of (x, y0, y1). Returns (stream, gt_cross_times)."""
    obj_boxes = [_track_boxes(x, y0, y1, frames) for (x, y0, y1) in objects]
    ppl_boxes = [_track_boxes(x, y0, y1, frames) for (x, y0, y1) in people]
    stream = []
    for i in range(frames):
        dets = []
        for b in ppl_boxes:
            dets.append(ScriptedDetection(b[i], class_id=0, confidence=0.9))
        for b in obj_boxes:
            dets.append(ScriptedDetection(b[i], class_id=1, confidence=0.9))
        stream.append((float(i), dets))
    # a GT crossing exists for each object whose bottom-centre crosses the line
    gt = []
    for (x, y0, y1) in objects:
        b0, b1 = y0 + 20, y1 + 20                      # bottom edge (anchor is BOTTOM_CENTER)
        if (b0 - LINE_Y) * (b1 - LINE_Y) < 0:
            gt.append(FRAMES / 2.0)                    # crosses mid-clip
    return stream, gt


def single_crossing():
    return _compose(objects=[(300, 250, 350)], people=[(360, 250, 350)])


def mixed_simultaneous():
    # carton, bag, sack in three separated lanes, all crossing OUT, one worker present
    return _compose(objects=[(150, 250, 350), (330, 250, 350), (510, 250, 350)],
                    people=[(600, 250, 350)])


def multi_worker():
    return _compose(objects=[(180, 250, 350), (460, 250, 350)],
                    people=[(120, 250, 350), (520, 250, 350)])


def empty_worker():
    # a worker walks through carrying nothing → ZERO crossings expected
    return _compose(objects=[], people=[(300, 250, 350)])


def stationary_near_line():
    # an object sits just above the line and never crosses → ZERO crossings
    s, _ = _compose(objects=[(300, 250, 258)], people=[])
    return s, []


def long_running(n=20):
    # n objects cross one after another over time → n crossings (throughput + no drift)
    stream = []
    gt = []
    t = 0
    for k in range(n):
        boxes = _track_boxes(200 + (k % 5) * 80, 250, 350, FRAMES)
        for i in range(FRAMES):
            stream.append((float(t), [ScriptedDetection(boxes[i], class_id=1, confidence=0.9)]))
            t += 1
        gt.append(t - FRAMES / 2.0)
    return stream, gt


SCENARIOS = {
    "single_crossing":     (single_crossing, 1),
    "mixed_simultaneous":  (mixed_simultaneous, 3),
    "multi_worker":        (multi_worker, 2),
    "empty_worker":        (empty_worker, 0),
    "stationary_near_line": (stationary_near_line, 0),
    "long_running_20":     (lambda: long_running(20), 20),
}
