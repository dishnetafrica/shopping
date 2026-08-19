"""
System metrics — FPS, latency, RAM, GPU/NPU utilisation, RTSP/decode load.

These decide the HARDWARE tier (do we need a Jetson, or does a cheap box suffice?). They are the one
metric group that is only meaningful on the REAL target machine with the REAL detector: sandbox numbers
would be a fabrication, so this module measures whatever host it runs on and labels every value with
where it came from. GPU/NPU utilisation is best-effort and honestly returns "not measured" when no
accelerator or sampling hook is present, rather than inventing a number.
"""
from __future__ import annotations

import time


class Stopwatch:
    """Accumulates per-stage wall time so decode load can be separated from inference + pipeline."""

    def __init__(self):
        self.decode_s = 0.0          # time pulling+decoding frames from the source (RTSP/file)
        self.infer_s = 0.0           # detector time
        self.pipeline_s = 0.0        # tracking + line-zone + state machine
        self.frames = 0
        self._t0 = None

    def start(self):
        self._t0 = time.perf_counter()
        return self

    def total_s(self):
        return time.perf_counter() - self._t0 if self._t0 is not None else 0.0


def _peak_rss_mb():
    try:
        import resource
        ru = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss
        # Linux reports KB, macOS reports bytes
        import sys
        return round(ru / 1024.0, 1) if sys.platform.startswith("linux") else round(ru / (1024.0 * 1024.0), 1)
    except Exception:
        return None


def _gpu_utilisation():
    """Best-effort. Returns a dict or a 'not measured' marker — never a fabricated number."""
    # torch/cuda memory, if a GPU inference stack is loaded
    try:
        import torch  # type: ignore
        if torch.cuda.is_available():
            return {
                "backend": "cuda",
                "device": torch.cuda.get_device_name(0),
                "mem_allocated_mb": round(torch.cuda.max_memory_allocated() / (1024 * 1024), 1),
                "note": "peak torch CUDA allocation; utilisation % needs nvidia-smi/tegrastats sampling",
            }
    except Exception:
        pass
    return {"backend": "none", "note": "no accelerator detected or not measured in this run"}


def summarise(sw: Stopwatch, latencies_ms) -> dict:
    total = sw.total_s()
    fps = (sw.frames / total) if total > 0 else None
    lat = sorted(latencies_ms)

    def pct(p):
        if not lat:
            return None
        k = min(len(lat) - 1, int(round((p / 100.0) * (len(lat) - 1))))
        return round(lat[k], 2)

    decode_share = round(sw.decode_s / total, 3) if total > 0 else None
    return {
        "frames": sw.frames,
        "wall_time_s": round(total, 3),
        "fps": round(fps, 2) if fps is not None else None,
        "latency_ms": {"p50": pct(50), "p95": pct(95), "max": pct(100),
                       "mean": round(sum(lat) / len(lat), 2) if lat else None},
        "stage_time_s": {"decode": round(sw.decode_s, 3), "infer": round(sw.infer_s, 3),
                         "pipeline": round(sw.pipeline_s, 3)},
        "rtsp_decode_load": {"decode_share_of_wall": decode_share,
                             "decode_ms_per_frame": round(1000 * sw.decode_s / sw.frames, 2) if sw.frames else None,
                             "note": "for a file source this is file-decode cost; measure true RTSP on the box"},
        "peak_rss_mb": _peak_rss_mb(),
        "gpu": _gpu_utilisation(),
        "measured_on": "this host — NOT a production target unless run on the real edge box",
    }
