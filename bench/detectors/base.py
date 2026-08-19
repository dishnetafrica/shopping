"""
The benchmark detector seam.

Every detector returns an ``sv.Detections`` (xyxy + class_id + confidence) — the SAME interface the
production ``app.movement.detector.Detector`` protocol defines, so a benchmark detector drops straight
into ``MovementPipeline`` unchanged. Class ids follow the pipeline's ``MovementConfig`` convention:
person = 0, carton = 1.

``CallableDetector`` is the one adapter that matters for the production path: give it any function
``infer(frame) -> list[(box_xyxy, class_id, confidence)]`` and it becomes a pipeline-ready detector.
That is how a fine-tuned YOLOX or RF-DETR gets benchmarked — you write ~15 lines wrapping the model's
predict call (see bench/README.md), and nothing else in the harness changes.
"""
from __future__ import annotations

from typing import Callable

PERSON_CLASS_ID = 0
CARTON_CLASS_ID = 1


class CallableDetector:
    """Wrap any inference callable as a pipeline detector.

    ``infer(frame) -> iterable of (box, class_id, confidence)`` where box is (x0,y0,x1,y1) pixels.
    ``frame`` is whatever the source yields (a BGR ndarray from VideoFileSource for real models).
    numpy + supervision are imported lazily so importing this module never requires them.
    """

    def __init__(self, infer: Callable, name: str = "callable"):
        self._infer = infer
        self.name = name

    def detect(self, frame, ts: float):
        import numpy as np
        import supervision as sv

        rows = list(self._infer(frame) or [])
        if not rows:
            return sv.Detections.empty()
        xyxy = np.array([[float(v) for v in r[0]] for r in rows], dtype=float)
        class_id = np.array([int(r[1]) for r in rows], dtype=int)
        confidence = np.array([float(r[2]) if len(r) > 2 else 0.9 for r in rows], dtype=float)
        return sv.Detections(xyxy=xyxy, confidence=confidence, class_id=class_id)
