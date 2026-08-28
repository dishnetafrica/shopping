"""
Deterministic frame-stream perturbations. A "stream" is a list of steps; each step is
(ts: float, detections: list[ScriptedDetection]) — the frame's timestamp and what the detector sees.

Deterministic (every-Kth, not random) so a stress result is reproducible and a regression is a regression,
not luck. Each perturbation models a real adversity the operational invariant must survive:

  drop_frames       — dropped frames (network/decoder loss): remove every Kth step
  duplicate_frames  — duplicate frames (decoder re-emit): repeat every Kth step
  jitter_timestamps — timestamp irregularity: nudge ts by ±j (frame clock wobble)
  reorder           — out-of-order arrival: swap adjacent steps every Kth
  speed_scale       — replay speed 1×/2×/4×: multiply inter-frame dt (compresses ts)
  corrupt_frames    — corrupted frame: every Kth frame decodes to NO detections
"""
from __future__ import annotations


def drop_frames(stream, every: int = 5):
    return [s for i, s in enumerate(stream) if every <= 0 or (i + 1) % every != 0]


def duplicate_frames(stream, every: int = 5):
    out = []
    for i, s in enumerate(stream):
        out.append(s)
        if every > 0 and (i + 1) % every == 0:
            out.append((s[0], list(s[1])))     # same ts, same detections — a re-emitted frame
    return out


def jitter_timestamps(stream, amp: float = 0.02, every: int = 3):
    out = []
    for i, (ts, dets) in enumerate(stream):
        d = amp if (i % (2 * every)) < every else -amp     # deterministic ± wobble
        out.append((ts + d, dets))
    return out


def reorder(stream, every: int = 6):
    out = list(stream)
    i = every - 1
    while i + 1 < len(out):
        out[i], out[i + 1] = out[i + 1], out[i]            # swap an adjacent pair
        i += every
    return out


def speed_scale(stream, factor: float = 2.0):
    if not stream:
        return stream
    t0 = stream[0][0]
    return [(t0 + (ts - t0) / factor, dets) for ts, dets in stream]


def corrupt_frames(stream, every: int = 7):
    return [(ts, ([] if (every > 0 and (i + 1) % every == 0) else dets))
            for i, (ts, dets) in enumerate(stream)]


PERTURBATIONS = {
    "baseline":   lambda s: s,
    "drop_1in5":  lambda s: drop_frames(s, 5),
    "drop_1in3":  lambda s: drop_frames(s, 3),
    "dup_1in5":   lambda s: duplicate_frames(s, 5),
    "jitter":     lambda s: jitter_timestamps(s, 0.02, 3),
    "reorder":    lambda s: reorder(s, 6),
    "speed_2x":   lambda s: speed_scale(s, 2.0),
    "speed_4x":   lambda s: speed_scale(s, 4.0),
    "corrupt_1in7": lambda s: corrupt_frames(s, 7),
}
