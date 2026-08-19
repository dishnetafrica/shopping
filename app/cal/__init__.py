"""
Camera Abstraction Layer (CAL).

The single place the service opens cameras. `CameraConfig` is the driver-neutral description of one
camera; drivers (demo, rtsp) turn it into frames. ONVIF discovery is best-effort — if the optional
discovery dependency is absent, discover() returns [] rather than failing, so a box without it still
runs (you add the camera by RTSP URL by hand).

Importing this package registers the built-in drivers and needs only stdlib; cv2/ffmpeg load lazily
inside the drivers when a frame is actually grabbed.
"""
from __future__ import annotations

import logging
from dataclasses import dataclass

from .base import (  # noqa: F401  (re-exported API)
    Capabilities,
    CameraDriver,
    CameraError,
    available_drivers,
    get_driver,
    register,
)
from . import drivers as _drivers  # noqa: F401  (registers demo + rtsp on import)

log = logging.getLogger("cal")


@dataclass
class CameraConfig:
    """Driver-neutral description of one camera (mirrors the `cameras` table columns the scheduler
    reads). A driver decides what it needs — the rtsp driver uses rtsp_url/host/credentials; the demo
    driver ignores all of it."""
    id: int = 0
    name: str = ""
    driver: str = "rtsp"
    host: str = ""
    port: int = 554
    username: str = ""
    password: str = ""
    channel: int = 1
    rtsp_url: str = ""
    interval_s: int = 600
    enabled: bool = True


def discover(timeout: float = 3.0) -> list[dict]:
    """Best-effort ONVIF WS-Discovery on the LAN. Returns a list of {xaddr, ...} dicts, or [] if the
    optional discovery dependency isn't installed (the base image ships without it — add cameras by
    RTSP URL). Never raises: discovery failing must not take down the API."""
    try:
        from wsdiscovery.discovery import ThreadedWSDiscovery  # type: ignore
        from wsdiscovery import QName  # noqa: F401
    except Exception:
        log.info("ONVIF discovery dependency not installed — returning no devices")
        return []
    try:
        wsd = ThreadedWSDiscovery()
        wsd.start()
        try:
            services = wsd.searchServices(timeout=timeout)
            out = []
            for s in services:
                xaddrs = list(getattr(s, "getXAddrs", lambda: [])())
                out.append({"xaddr": xaddrs[0] if xaddrs else "",
                            "types": [str(t) for t in getattr(s, "getTypes", lambda: [])()]})
            return out
        finally:
            wsd.stop()
    except Exception as e:  # pragma: no cover - depends on live LAN + optional dep
        log.warning("ONVIF discovery failed: %s", e)
        return []
