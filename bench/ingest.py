"""
Video ingestion for the benchmark — decode frames from a recorded clip, timing the decode so RTSP/file
decode load can be separated from inference. cv2 is imported lazily; importing this module never needs
OpenCV. This is the real-footage path (VideoFileSource-style); the sandbox self-test does not use it.

Yields (index, frame, ts, decode_seconds). ts is frame time = index / fps, so every event carries the
frame's own time, never wall-clock.
"""
from __future__ import annotations

import os
import time


class TimedVideoReader:
    def __init__(self, path: str, fps_hint: float = 25.0):
        self.path = path
        self.fps_hint = float(fps_hint)

    def frames(self):
        import cv2  # lazy

        if not os.path.exists(self.path):
            raise FileNotFoundError(f"clip not found: {self.path}")
        cap = cv2.VideoCapture(self.path)
        try:
            fps = cap.get(cv2.CAP_PROP_FPS) or self.fps_hint
            idx = 0
            while True:
                t0 = time.perf_counter()
                ok, frame = cap.read()
                decode_s = time.perf_counter() - t0
                if not ok:
                    break
                yield idx, frame, idx / fps, decode_s
                idx += 1
        finally:
            cap.release()
