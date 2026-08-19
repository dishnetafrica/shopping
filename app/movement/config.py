"""
Static configuration for one movement pipeline instance (one camera, one dispatch door).

Pure data — no camera, no network. `line` is the counting boundary in frame pixel coords, directed
so that its LEFT-hand side (by the signed cross product in geometry.side_of_line) is INSIDE the store
and the RIGHT-hand side is the exit; Supervision's LineZone reports `crossed_out` for a person/carton
moving from the anchor side of the segment to the other, which we map to the configured out/in labels.
"""
from __future__ import annotations

from dataclasses import dataclass


@dataclass(frozen=True)
class MovementConfig:
    store: str                       # e.g. "wh-juba-01" — the physical store/warehouse code ShopBot knows
    zone_uid: str                    # e.g. "D->EXIT" — the boundary id ShopBot maps to a location
    line: tuple                      # ((ax, ay), (bx, by)) counting line in frame pixels
    person_class_id: int = 0         # detector class id for a person
    carton_class_id: int = 1         # detector class id for a carton
    out_label: str = "out"           # label for a Supervision "crossed_out" event
    in_label: str = "in"             # label for a Supervision "crossed_in" event
    assoc_max_dist: float = 220.0    # px: carton↔person centre distance to attribute a mover
    cooldown_s: float = 2.0          # s: same-direction re-cross inside this window is oscillation
    min_crossing_frames: int = 1     # LineZone.minimum_crossing_threshold (anti-jitter)
