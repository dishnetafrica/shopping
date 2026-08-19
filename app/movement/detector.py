"""
Detector layer — turns a frame into `sv.Detections` (boxes + class_id + confidence).

Two implementations:
  • Detector           — the Protocol every real model (YOLOX, RF-DETR, …) will satisfy later.
  • ScriptedDetector   — replays PRE-AUTHORED detections keyed by frame index. This is what makes
                         Layer-1 deterministic: no model weights, no camera, no randomness. The nine
                         acceptance scenarios feed the pipeline exactly the boxes they intend to test.

Nothing here imports a real model or cv2. `supervision` is imported lazily inside the scripted
detector so that pure-Python callers (the state-machine harness) never need it.
"""
from __future__ import annotations

from typing import Protocol, runtime_checkable


@runtime_checkable
class Detector(Protocol):
    """A model that detects people and cartons in one frame.

    Returns an ``sv.Detections`` with ``xyxy`` boxes, ``class_id`` and ``confidence`` populated.
    ``tracker_id`` is NOT set here — tracking is a separate, swappable layer in the pipeline.
    """

    def detect(self, frame, ts: float):
        ...


class ScriptedDetection:
    """One pre-authored box for the scripted detector. Boxes are (x0, y0, x1, y1)."""

    __slots__ = ("box", "class_id", "confidence")

    def __init__(self, box, class_id: int, confidence: float = 0.9):
        self.box = tuple(float(v) for v in box)
        self.class_id = int(class_id)
        self.confidence = float(confidence)


class ScriptedDetector:
    """Replays a script: frame index -> list[ScriptedDetection].

    The pipeline calls ``detect(frame, ts)`` once per frame; ``frame`` here is just the integer
    frame index the source hands us (scripted sources carry no pixels). Missing indices yield an
    empty detection set — this is how "temporary occlusion" (scenario 5) and "tracking loss"
    (scenario 6) are scripted: simply omit the box for those frames.
    """

    def __init__(self, script: dict):
        # script: {frame_index: [ScriptedDetection, ...]}
        self._script = {int(k): list(v) for k, v in script.items()}

    def detect(self, frame, ts: float):
        import numpy as np
        import supervision as sv

        dets = self._script.get(int(frame), [])
        if not dets:
            return sv.Detections.empty()
        xyxy = np.array([d.box for d in dets], dtype=float)
        class_id = np.array([d.class_id for d in dets], dtype=int)
        confidence = np.array([d.confidence for d in dets], dtype=float)
        return sv.Detections(xyxy=xyxy, confidence=confidence, class_id=class_id)
