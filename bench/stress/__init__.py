"""
Video stress-test track — MOVEMENT-pipeline robustness under adverse frame streams.

What this CAN test in software (no real camera/codec/GPU): whether the pipeline still yields the
operational invariant — 1 real stock-object crossing → exactly 1 movement event, and 0 crossings → 0
events — when the frame STREAM is degraded: dropped frames, duplicate frames, timestamp jitter/reorder,
replay-speed scaling, "corrupted" frames (a frame that decodes to nothing), long-running replay, mixed
object types, multiple simultaneous objects, multiple workers, workers carrying nothing, stationary
objects near the line. It runs the REAL sv.ByteTrack + sv.LineZone + MovementStateMachine.

What this CANNOT test here (physical edge box only — never fabricated): real H.264/H.265 decode of
720p/1080p/4MP/5MP, real motion-blur pixels, real decoder failures/reconnects, detector latency, and
CPU/RAM/GPU/NPU under real load. Those are reported as a template to fill on hardware.

Two report axes, kept separate (the owner's A/B split):
  A. Vision performance  — did we DETECT the objects?           (needs a real detector + footage → Track B)
  B. Movement performance — given detections, did we turn them into correct movements?  (THIS harness)
"""
