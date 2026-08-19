"""
Built-in capture drivers.

  • demo — synthetic frames, no hardware. The escape hatch every test uses (driver='demo') and the
           way a box proves the pipeline before a lens is hung. cv2 is imported lazily so importing
           this module never needs OpenCV.
  • rtsp — a real IP camera. Grabs one JPEG via ffmpeg (subprocess, stdlib) with an RTSP-over-TCP
           transport and a hard timeout. ffmpeg (not cv2) is used for the single-frame grab because
           that is what the historical error strings show and what survives flaky RTSP most reliably.

Registered on import. The movement pipeline's live capture (RtspFrameSource) shares the same URL
resolution via `rtsp_url_for`, so there is one definition of "how we open this camera".
"""
from __future__ import annotations

import os
import subprocess

from .base import Capabilities, CameraDriver, CameraError, register

FFMPEG = os.environ.get("FFMPEG_BIN", "ffmpeg")
SNAP_TIMEOUT_S = float(os.environ.get("CAMERA_SNAP_TIMEOUT_S", "12"))


def rtsp_url_for(cam) -> str:
    """The stream URL for a camera. An explicit rtsp_url always wins; otherwise build a best-effort
    one from host/credentials. Brand-specific paths differ, so for Phase-1 an explicit rtsp_url is
    strongly preferred (the dashboard asks for it)."""
    explicit = (getattr(cam, "rtsp_url", "") or "").strip()
    if explicit:
        return explicit
    host = (getattr(cam, "host", "") or "").strip()
    if not host:
        raise CameraError("no rtsp_url and no host — cannot locate the stream")
    user = (getattr(cam, "username", "") or "").strip()
    pw = (getattr(cam, "password", "") or "").strip()
    cred = f"{user}:{pw}@" if user else ""
    port = int(getattr(cam, "port", 554) or 554)
    if port == 80:              # 80 is the ONVIF/HTTP port default; RTSP is 554
        port = 554
    return f"rtsp://{cred}{host}:{port}/"


class DemoDriver(CameraDriver):
    """Synthetic gondola frame — deterministic, no camera. Plumbing/tests only."""

    name = "demo"

    def probe(self, cam) -> Capabilities:
        return Capabilities(ok=True, detail="synthetic demo camera", driver=self.name,
                            width=640, height=480)

    def get_snapshot(self, cam) -> bytes:
        import cv2  # lazy
        import numpy as np

        w, h = 640, 480
        img = np.full((h, w, 3), 52, np.uint8)
        cv2.rectangle(img, (0, h - 70), (w, h), (96, 98, 104), -1)     # floor
        for row in range(3):                                           # three shelf rows of facings
            y0 = 90 + row * ((h - 90) // 3)
            for i in range(6):
                x0 = 20 + i * ((w - 40) // 6)
                col = (40 + 30 * i, 120, 60 + 20 * row)
                cv2.rectangle(img, (x0, y0), (x0 + (w - 40) // 6 - 6, y0 + 60), col, -1)
        ok, buf = cv2.imencode(".jpg", img, [cv2.IMWRITE_JPEG_QUALITY, 85])
        if not ok:
            raise CameraError("demo driver could not encode a frame")
        return buf.tobytes()


class RtspDriver(CameraDriver):
    """A real IP camera over RTSP. One JPEG per call via ffmpeg."""

    name = "rtsp"

    def probe(self, cam) -> Capabilities:
        # Build the URL for display (scrubbed by the caller) and a light reachability note. A real
        # open is what get_snapshot/Test does; probe stays cheap and never raises.
        try:
            url = rtsp_url_for(cam)
        except CameraError as e:
            return Capabilities(ok=False, detail=str(e), driver=self.name)
        return Capabilities(ok=True, detail="rtsp url resolved (use Capture to grab a frame)",
                            driver=self.name, rtsp_url=url)

    def get_snapshot(self, cam) -> bytes:
        url = rtsp_url_for(cam)
        cmd = [FFMPEG, "-nostdin", "-rtsp_transport", "tcp", "-i", url,
               "-frames:v", "1", "-q:v", "3", "-f", "image2", "-"]
        try:
            proc = subprocess.run(cmd, capture_output=True, timeout=SNAP_TIMEOUT_S)
        except FileNotFoundError:
            raise CameraError(f"{FFMPEG} not found — install ffmpeg in the image")
        except subprocess.TimeoutExpired:
            raise CameraError(f"ffmpeg timed out after {SNAP_TIMEOUT_S}s opening the stream")
        if proc.returncode != 0 or not proc.stdout:
            err = (proc.stderr or b"").decode("utf-8", "replace")[:300]
            raise CameraError(f"ffmpeg failed: {err}")
        return proc.stdout


def register_builtins() -> None:
    register(DemoDriver())
    register(RtspDriver())


register_builtins()
