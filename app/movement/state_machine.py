"""
MovementStateMachine — the pure decision core of the edge movement pipeline.

It consumes NORMALISED per-frame observations (which crossings happened, which tracks are present /
lost, where the people are) and decides what event, if any, to emit. It knows nothing about
Supervision, cameras or the network — exactly like ShopBot's reconciliation engine knows nothing about
HTTP. That is what makes the nine acceptance scenarios deterministic.

Rules:
  • exactly one carton.move per genuine crossing; same-direction re-triggers within a cooldown are
    oscillation and suppressed; the opposite direction is a legitimate new crossing.
  • a tracked carton that ends WITHOUT ever crossing → tracking.gap (never a fabricated movement).
  • association is soft: 0 people → no attribution; 1 clear person → attributed; 2+ comparable →
    candidates listed, assoc_conf low, track_id None (undetermined) — but the movement STILL emits.
  • occlusion during a crossing is flagged, not suppressed. A carton merely occluded (no crossing,
    still tracked) produces nothing.
"""
from __future__ import annotations

from dataclasses import dataclass, field
from typing import Callable

from . import contract
from .geometry import distance


@dataclass
class Crossing:
    track_id: int
    direction: str            # 'in' | 'out'
    occluded: bool = False
    confidence: float = 0.9
    qty_conf: float = 1.0


@dataclass
class Frame:
    ts: float
    crossings: list[Crossing] = field(default_factory=list)
    carton_boxes: dict = field(default_factory=dict)   # track_id -> box, cartons visible this frame
    person_boxes: dict = field(default_factory=dict)   # track_id -> box, people visible this frame
    lost_carton_ids: list = field(default_factory=list)  # carton tracks that ENDED this frame


class MovementStateMachine:
    def __init__(self, store: str, zone_uid: str, sink: Callable[[dict], None], *,
                 cooldown_s: float = 2.0, assoc_max_dist: float = 220.0,
                 assoc_clear_ratio: float = 0.6, emit_gap: bool = True):
        self.store = store
        self.zone_uid = zone_uid
        self.sink = sink
        self.cooldown_s = cooldown_s
        self.assoc_max_dist = assoc_max_dist
        self.assoc_clear_ratio = assoc_clear_ratio
        self.emit_gap = emit_gap
        self._last_emit: dict[int, tuple[str, float]] = {}   # track_id -> (direction, ts)
        self._crossed: set[int] = set()                      # carton tracks that have crossed at least once
        self._owed_gap: set[int] = set()                     # tracked cartons that have not (yet) crossed

    def observe(self, frame: Frame) -> None:
        for tid in frame.carton_boxes:
            if tid not in self._crossed:
                self._owed_gap.add(tid)
        for c in frame.crossings:
            self._handle_crossing(c, frame)
        for tid in frame.lost_carton_ids:
            self._handle_lost(tid, frame.ts)

    def _handle_crossing(self, c: Crossing, frame: Frame) -> None:
        last = self._last_emit.get(c.track_id)
        if last is not None and last[0] == c.direction and (frame.ts - last[1]) < self.cooldown_s:
            return   # oscillation: same-direction re-trigger inside the cooldown

        track, candidates, assoc_conf = self._associate(c.track_id, frame)
        payload = contract.carton_move(
            self.store, self.zone_uid, c.direction, frame.ts,
            track_id=track, candidates=candidates, assoc_conf=assoc_conf,
            confidence=c.confidence, occlusion=c.occluded, qty_conf=c.qty_conf,
        )
        self.sink(payload)
        self._last_emit[c.track_id] = (c.direction, frame.ts)
        self._crossed.add(c.track_id)
        self._owed_gap.discard(c.track_id)

    def _handle_lost(self, track_id: int, ts: float) -> None:
        # A carton track that ended without ever crossing is a SENSOR gap, not a movement.
        if track_id in self._owed_gap and track_id not in self._crossed:
            if self.emit_gap:
                self.sink(contract.tracking_gap(self.store, self.zone_uid, ts))
        self._owed_gap.discard(track_id)

    def _associate(self, carton_tid: int, frame: Frame):
        """Return (track_id | None, candidates[list], assoc_conf). Never gates the movement."""
        cbox = frame.carton_boxes.get(carton_tid)
        if not cbox or not frame.person_boxes:
            return None, [], 0.0
        ranked = sorted((distance(cbox, pbox), pid) for pid, pbox in frame.person_boxes.items())
        near = [(d, pid) for d, pid in ranked if d <= self.assoc_max_dist]
        if not near:
            return None, [], 0.0
        if len(near) == 1:
            return near[0][1], [near[0][1]], 0.9
        d0, _ = near[0]
        d1, _ = near[1]
        if d1 > 0 and (d0 / d1) < self.assoc_clear_ratio:
            return near[0][1], [near[0][1]], 0.8   # closest is clearly closest → attribute
        # two or more comparable → undetermined: list candidates, no single track, low confidence
        return None, [pid for _, pid in near[:3]], 0.3
