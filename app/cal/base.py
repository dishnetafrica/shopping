"""
Camera Abstraction Layer (CAL) — the base contract every capture driver satisfies.

This is the seam the rest of the service talks to instead of ffmpeg/OpenCV/ONVIF directly, so a new
camera type (or a synthetic one for tests) is a driver registration, not a change to the scheduler.
Pure stdlib here — importing this module pulls in NO cv2, NO ffmpeg, NO network. Drivers import their
heavy deps lazily inside the methods that need them, so the whole layer stays importable anywhere.

Contract (relied on by app/main.py, app/scheduler.py and the existing tests):
  • CameraError            — the one exception a driver raises when it cannot produce a frame.
  • Capabilities           — what a probe() learned about a camera (ok/detail + optional stream facts).
  • CameraDriver           — base class; a driver sets `name` and implements probe() + get_snapshot().
  • register(driver)       — add a driver INSTANCE to the process-wide registry.
  • get_driver(name)       — look one up (raises CameraError if unknown).
  • available_drivers()    — the registered names, for /api/drivers and add-camera validation.
"""
from __future__ import annotations

from dataclasses import dataclass, field


class CameraError(Exception):
    """A driver could not produce a frame. Its message may contain the stream URL (and thus the
    recorder password), so every call site scrubs it (redact.scrub_secret) before it is stored,
    served or logged — see scheduler.capture_one."""


@dataclass
class Capabilities:
    """What probe() found. `ok`/`detail` are always present; the rest are filled when known.

    Constructed by drivers as e.g. Capabilities(ok=False, detail="dead"). test_camera returns
    dict(cap.__dict__) scrubbed — so anything secret (a built rtsp_url) is masked on the way out."""
    ok: bool = False
    detail: str = ""
    driver: str = ""
    rtsp_url: str = ""
    snapshot_url: str = ""
    width: int = 0
    height: int = 0
    extra: dict = field(default_factory=dict)


class CameraDriver:
    """Base class. A concrete driver sets a unique `name` and implements both methods."""

    name: str = "base"

    def probe(self, cam) -> Capabilities:
        """Cheap reachability/identity check for the 'Test' button. Never raises for a normal
        'camera unreachable' — it returns Capabilities(ok=False, detail=...)."""
        raise NotImplementedError

    def get_snapshot(self, cam) -> bytes:
        """Return ONE JPEG frame, or raise CameraError. Runs in the capture thread, so it must
        honour a timeout and never block indefinitely."""
        raise NotImplementedError


_REGISTRY: dict[str, CameraDriver] = {}


def register(driver: CameraDriver) -> CameraDriver:
    """Register a driver INSTANCE under driver.name (re-registration replaces — tests rely on this)."""
    if not getattr(driver, "name", ""):
        raise ValueError("driver has no name")
    _REGISTRY[driver.name] = driver
    return driver


def get_driver(name: str) -> CameraDriver:
    try:
        return _REGISTRY[name]
    except KeyError:
        raise CameraError(f"unknown camera driver {name!r}; have {sorted(_REGISTRY)}")


def available_drivers() -> list[str]:
    return sorted(_REGISTRY)
