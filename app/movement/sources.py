"""
CameraSource layer — yields (frame, ts) pairs. The pipeline never cares where frames come from.

  • FrameSource       — the Protocol (iterate frames, each with a timestamp).
  • ListFrameSource   — an in-memory list of (frame, ts). Used by scripted tests: `frame` is just the
                        integer frame index the ScriptedDetector keys on; no pixels needed.
  • VideoFileSource   — PoC-only replay of a recorded file via cv2 (imported lazily). This is the
                        single seam where real pixels enter; it is NOT exercised by the Layer-1 unit
                        tests (they never touch cv2 or a file). It exists so the same pipeline can be
                        pointed at recorded warehouse footage for the benchmark, before any hardware.

Live RTSP / ONVIF are deliberately absent (Phase-0 scope: replay only).
"""
from __future__ import annotations

from typing import Iterator, Protocol, Tuple, runtime_checkable


@runtime_checkable
class FrameSource(Protocol):
    def frames(self) -> Iterator[Tuple[object, float]]:
        ...


class ListFrameSource:
    """Deterministic in-memory source. Each item is (frame, ts); ts is the frame's own timestamp,
    so events carry the frame time, never wall-clock. For scripted tests, frame == frame index."""

    def __init__(self, items):
        self._items = list(items)

    def frames(self) -> Iterator[Tuple[object, float]]:
        for frame, ts in self._items:
            yield frame, float(ts)


class VideoFileSource:
    """Replay a recorded video file at its real frame timestamps. PoC / benchmark only — cv2 is
    imported lazily so importing this module never requires OpenCV. `frame` yielded here is the
    decoded BGR ndarray (a real Detector, not the scripted one, consumes it)."""

    def __init__(self, path: str, start_ts: float = 0.0):
        self.path = path
        self.start_ts = float(start_ts)

    def frames(self) -> Iterator[Tuple[object, float]]:
        import cv2  # lazy: only when someone actually replays a file

        cap = cv2.VideoCapture(self.path)
        try:
            fps = cap.get(cv2.CAP_PROP_FPS) or 25.0
            idx = 0
            while True:
                ok, frame = cap.read()
                if not ok:
                    break
                yield frame, self.start_ts + (idx / fps)
                idx += 1
        finally:
            cap.release()
