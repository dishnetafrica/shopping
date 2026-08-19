"""
Scripted trajectory generator for the self-test (research/testing only, never production).

Builds a smooth, monotonic carton path across a horizontal line, plus a person moving alongside for
association — the same shape as the app.movement integration tests. Returns both the detector script
(for app.movement.detector.ScriptedDetector) and the per-frame carton ground-truth boxes, so the
self-test can score detection/tracking against a KNOWN answer.
"""
from __future__ import annotations

from app.movement.detector import ScriptedDetection


def scripted_trajectory(y_start, y_end, frames=14, x=300, line_y=300):
    script = {}
    gt_boxes = []
    for i in range(frames):
        t = i / (frames - 1)
        yc = y_start + (y_end - y_start) * t
        cbox = (x - 20, yc - 20, x + 20, yc + 20)
        pbox = (x + 40, yc - 40, x + 80, yc + 40)
        script[i] = [
            ScriptedDetection(pbox, class_id=0, confidence=0.9),   # person
            ScriptedDetection(cbox, class_id=1, confidence=0.9),   # carton
        ]
        gt_boxes.append(cbox)
    return script, gt_boxes
