"""Pluggable detectors for the benchmark. All satisfy app.movement.detector.Detector
(detect(frame, ts) -> sv.Detections), so any of them drops straight into MovementPipeline."""
