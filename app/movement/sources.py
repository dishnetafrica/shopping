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


class RtspFrameSource:
    """Live RTSP capture for the movement pipeline — the real Phase-1 input.

    Yields (frame, ts) where ts is the wall-clock capture time (a live stream has no file clock). cv2
    is imported lazily. RTSP-over-TCP is forced (UDP loses packets on warehouse Wi-Fi). On a read
    failure it reconnects with backoff rather than exiting, because a door camera must survive a blip.
    `max_fps` throttles CPU: cartons cross in ~1s, so 6-10 fps is plenty and a fraction of the load of
    decoding every frame. This is a capture seam only — no detection, no hardware assumptions.
    """

    def __init__(self, url: str, *, max_fps: float = 8.0, reconnect: bool = True,
                 reconnect_backoff_s: float = 2.0, transport: str = "tcp"):
        self.url = url
        self.max_fps = float(max_fps)
        self.reconnect = reconnect
        self.reconnect_backoff_s = float(reconnect_backoff_s)
        self.transport = transport

    def frames(self) -> Iterator[Tuple[object, float]]:
        import os
        import time

        import cv2

        os.environ.setdefault("OPENCV_FFMPEG_CAPTURE_OPTIONS",
                              f"rtsp_transport;{self.transport}")
        min_dt = (1.0 / self.max_fps) if self.max_fps > 0 else 0.0
        last = 0.0
        while True:
            cap = cv2.VideoCapture(self.url, cv2.CAP_FFMPEG)
            try:
                if not cap.isOpened():
                    if not self.reconnect:
                        raise RuntimeError(f"could not open RTSP stream: {self.url}")
                    time.sleep(self.reconnect_backoff_s)
                    continue
                while True:
                    ok, frame = cap.read()
                    now = time.time()
                    if not ok:
                        break                       # stream dropped → fall out to reconnect
                    if min_dt and (now - last) < min_dt:
                        continue                    # throttle: skip frames above max_fps
                    last = now
                    yield frame, now
            finally:
                cap.release()
            if not self.reconnect:
                return
            time.sleep(self.reconnect_backoff_s)
