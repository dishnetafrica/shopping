"""
MovementWorker — the (deliberately unglamorous) glue between a running MovementPipeline and the
existing outbox. It is NOT imported by main.py and NOT started anywhere yet: Phase-0 stops at the
replay pipeline + deterministic tests. This file exists only so the production seam is obvious.

The sink is a lazy `events.send` — imported inside start() so that importing this module (or running
the movement unit tests) never drags in cv2 via app.events/app.detector. When a real deployment wires
this up, carton.move / tracking.gap flow straight through the existing SQLite outbox → HTTPS →
ShopBot path, identical to the shelf events. No reconciliation happens here; ShopBot owns that.
"""
from __future__ import annotations

import threading

from .config import MovementConfig
from .pipeline import MovementPipeline


class MovementWorker:
    def __init__(self, config: MovementConfig, detector, source, *, sink=None):
        self.config = config
        self.source = source
        self._sink = sink
        self._pipeline = MovementPipeline(config, detector, self._emit)
        self._thread: threading.Thread | None = None
        self._stop = threading.Event()

    def _emit(self, payload: dict) -> None:
        sink = self._sink
        if sink is None:
            from app import events  # lazy — avoids importing cv2-coupled modules at import time
            sink = events.send
        sink(payload)

    def _loop(self) -> None:
        for frame, ts in self.source.frames():
            if self._stop.is_set():
                break
            self._pipeline.process(frame, ts)

    def start(self) -> None:
        if self._thread and self._thread.is_alive():
            return
        self._stop.clear()
        self._thread = threading.Thread(target=self._loop, name="movement-worker", daemon=True)
        self._thread.start()

    def stop(self, timeout: float = 5.0) -> None:
        self._stop.set()
        if self._thread:
            self._thread.join(timeout=timeout)
            self._thread = None
