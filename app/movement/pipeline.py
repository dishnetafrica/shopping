"""
MovementPipeline — the ONE place that touches Supervision.

Wiring:   Detector → sv.ByteTrack (assign tracker_id) → split person/carton by class_id
          → sv.LineZone.trigger (carton crossings) → build a normalised Frame → MovementStateMachine.

Everything Supervision-specific is confined here. The state machine downstream sees only plain
`Frame`/`Crossing` objects, which is why its nine scenarios are testable without a tracker or a
camera. Supervision (`supervision`, `numpy`) is imported lazily so pure callers never pull it in.

ByteTrack is DEPRECATED in Supervision ≥0.28 and slated for removal; it is isolated behind this
class precisely so a replacement tracker is a one-file swap. Reconciliation is NOT here — the sink
just forwards carton.move / tracking.gap to ShopBot.
"""
from __future__ import annotations

from typing import Callable

from .config import MovementConfig
from .state_machine import Crossing, Frame, MovementStateMachine


class MovementPipeline:
    def __init__(self, config: MovementConfig, detector, sink: Callable[[dict], None], *,
                 emit_gap: bool = True, lost_grace_frames: int = 8, tracer: Callable[[dict], None] = None):
        self.config = config
        self.detector = detector
        self.emit_gap = emit_gap
        self.lost_grace_frames = int(lost_grace_frames)
        # Optional, off by default: a per-frame observer for the benchmark to score tracking against
        # exactly what drove the state machine. Never affects behaviour — production leaves it None.
        self.tracer = tracer
        self.machine = MovementStateMachine(
            config.store, config.zone_uid, sink,
            cooldown_s=config.cooldown_s, assoc_max_dist=config.assoc_max_dist,
            emit_gap=emit_gap,
        )
        self._tracker = None
        self._line = None
        self._absent: dict[int, int] = {}   # carton tracker_id -> consecutive frames missing
        self._seen: set[int] = set()        # carton tracker_ids ever seen (not yet declared lost)

    # -- lazy Supervision objects -------------------------------------------------------------
    def _ensure(self):
        if self._tracker is not None:
            return
        import supervision as sv

        self._tracker = sv.ByteTrack()
        (ax, ay), (bx, by) = self.config.line
        # One stable anchor (bottom-centre = ground contact), matching geometry.anchor's rationale,
        # so a crossing is unambiguous rather than requiring all four box corners to agree.
        self._line = sv.LineZone(
            start=sv.Point(ax, ay), end=sv.Point(bx, by),
            triggering_anchors=(sv.Position.BOTTOM_CENTER,),
            minimum_crossing_threshold=self.config.min_crossing_frames,
        )

    # -- streaming API ------------------------------------------------------------------------
    def run(self, source):
        for frame, ts in source.frames():
            self.process(frame, ts)

    def process(self, frame, ts: float):
        self._ensure()
        import supervision as sv

        detections = self.detector.detect(frame, ts)
        tracked = self._tracker.update_with_detections(detections)

        cid = tracked.class_id
        person_mask = cid == self.config.person_class_id
        carton_mask = cid == self.config.carton_class_id
        persons = tracked[person_mask]
        cartons = tracked[carton_mask]

        # LineZone counts crossings for whatever it is fed; feed cartons only.
        crossed_in, crossed_out = self._line.trigger(cartons)

        carton_boxes: dict = {}
        crossings: list = []
        for i in range(len(cartons)):
            tid = _tid(cartons, i)
            if tid is None:
                continue
            carton_boxes[tid] = tuple(float(v) for v in cartons.xyxy[i])
            conf = float(cartons.confidence[i]) if cartons.confidence is not None else 0.9
            if bool(crossed_out[i]):
                crossings.append(Crossing(tid, self.config.out_label, confidence=conf))
            elif bool(crossed_in[i]):
                crossings.append(Crossing(tid, self.config.in_label, confidence=conf))

        person_boxes = {}
        for i in range(len(persons)):
            tid = _tid(persons, i)
            if tid is not None:
                person_boxes[tid] = tuple(float(v) for v in persons.xyxy[i])

        lost = self._track_absence(set(carton_boxes.keys()))

        if self.tracer is not None:
            self.tracer({
                "ts": ts,
                "cartons": dict(carton_boxes),
                "persons": dict(person_boxes),
                "crossings": [(c.track_id, c.direction) for c in crossings],
                "lost": list(lost),
            })

        self.machine.observe(Frame(
            ts=ts, crossings=crossings, carton_boxes=carton_boxes,
            person_boxes=person_boxes, lost_carton_ids=lost,
        ))

    def _track_absence(self, present: set) -> list:
        """Occlusion-tolerant loss detection: a carton is 'lost' only after it has been missing for
        more than `lost_grace_frames` consecutive frames — a brief occlusion never fabricates a gap."""
        for tid in present:
            self._seen.add(tid)
            self._absent.pop(tid, None)
        lost = []
        for tid in list(self._seen):
            if tid in present:
                continue
            self._absent[tid] = self._absent.get(tid, 0) + 1
            if self._absent[tid] > self.lost_grace_frames:
                lost.append(tid)
                self._seen.discard(tid)
                self._absent.pop(tid, None)
        return lost


def _tid(dets, i):
    """tracker_id for detection i, or None if the tracker hasn't confirmed it yet."""
    if dets.tracker_id is None:
        return None
    v = dets.tracker_id[i]
    return None if v is None else int(v)
