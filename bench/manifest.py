"""
The clip manifest + HUMAN-VERIFIED ground-truth schema.

One JSON file per recorded clip. Two kinds of truth live here, both produced by a human (a zero-shot
pass may PROPOSE boxes, but a person must correct and confirm them — see the footage guide):

  1. Frame-level detection truth  (`frames`)  — verified carton boxes on the frames you chose to
     label, for detector precision / recall / mAP. Sparse is fine: only labelled frames are scored.
  2. Event-level movement truth   (`crossings`) — the operational truth: each real carton crossing,
     its direction, timestamp/frame, count, and occlusion. This is what "exactly one correct
     carton.move" is scored against.

The `capture` block preserves the CONTEXT the owner asked to keep — camera height, lens, lighting,
door geometry, carton types, date/time, location — so the dataset stays valuable across Kampala /
Juba / Rwanda deployments, not just as a bag of frames. `session` groups clips by recording day so a
held-out test set can be drawn from a DIFFERENT session than training.

This module is pure stdlib (json) — it loads and VALIDATES manifests; it never touches cv2 or a model.
"""
from __future__ import annotations

import json
from dataclasses import dataclass, field


@dataclass
class GtBox:
    """One human-verified carton box on a labelled frame. xyxy in pixels."""
    box: tuple
    label: str = "carton"

    @staticmethod
    def from_json(d):
        return GtBox(box=tuple(float(v) for v in d["box"]), label=d.get("label", "carton"))


@dataclass
class GtFrame:
    """Human-verified state of ONE labelled frame."""
    index: int
    carton_present: bool
    boxes: list                       # list[GtBox]
    occluded: bool = False
    continuously_visible: bool = True

    @staticmethod
    def from_json(index, d):
        return GtFrame(
            index=int(index),
            carton_present=bool(d.get("carton_present", bool(d.get("boxes")))),
            boxes=[GtBox.from_json(b) for b in d.get("boxes", [])],
            occluded=bool(d.get("occluded", False)),
            continuously_visible=bool(d.get("continuously_visible", True)),
        )


@dataclass
class GtCrossing:
    """One human-verified carton crossing — the operational ground truth."""
    gt_id: str
    direction: str                    # 'in' | 'out'
    frame: int
    ts: float
    num_cartons: int = 1
    occluded: bool = False
    continuously_visible: bool = True

    @staticmethod
    def from_json(d):
        return GtCrossing(
            gt_id=str(d["gt_id"]),
            direction=str(d["direction"]),
            frame=int(d.get("frame", -1)),
            ts=float(d.get("ts", -1.0)),
            num_cartons=int(d.get("num_cartons", 1)),
            occluded=bool(d.get("occluded", False)),
            continuously_visible=bool(d.get("continuously_visible", True)),
        )


@dataclass
class Manifest:
    clip_id: str
    video: str
    store: str
    zone_uid: str
    line: tuple                       # ((ax,ay),(bx,by))
    fps: float
    session: str                      # recording-day/session id, for held-out splits
    split: str = "train"              # 'train' | 'test' (held-out) — set per clip, honoured by run.py
    capture: dict = field(default_factory=dict)   # preserved context metadata
    frames: dict = field(default_factory=dict)    # index -> GtFrame
    crossings: list = field(default_factory=list) # list[GtCrossing]

    @staticmethod
    def from_json(d):
        line = d["line"]
        m = Manifest(
            clip_id=str(d["clip_id"]),
            video=str(d["video"]),
            store=str(d["store"]),
            zone_uid=str(d["zone_uid"]),
            line=((float(line[0][0]), float(line[0][1])), (float(line[1][0]), float(line[1][1]))),
            fps=float(d.get("fps", 25.0)),
            session=str(d.get("session", "unknown")),
            split=str(d.get("split", "train")),
            capture=dict(d.get("capture", {})),
            frames={int(k): GtFrame.from_json(k, v) for k, v in d.get("frames", {}).items()},
            crossings=[GtCrossing.from_json(c) for c in d.get("crossings", [])],
        )
        return m

    @staticmethod
    def load(path: str) -> "Manifest":
        with open(path, "r", encoding="utf-8") as fh:
            return Manifest.from_json(json.load(fh))


# Fields whose presence the footage guide asks a human to preserve, so we can WARN when they are absent
# (a missing value is allowed — the dataset is still usable — but the warning keeps the corpus honest).
RECOMMENDED_CAPTURE_KEYS = (
    "camera_height_m", "lens", "resolution", "lighting", "door_geometry",
    "carton_types", "datetime", "location",
)


def validate(m: Manifest) -> list[str]:
    """Return a list of problems ([] == structurally valid). Not a judgement on label quality.
    'note:' lines are advisories (missing recommended context), not hard errors."""
    problems: list[str] = []
    for c in m.crossings:
        if c.direction not in ("in", "out"):
            problems.append(f"crossing {c.gt_id}: direction must be 'in'/'out', got {c.direction!r}")
    if not m.crossings and not m.frames:
        problems.append("manifest has neither frame labels nor crossing labels — nothing to score")
    if m.split not in ("train", "test"):
        problems.append(f"split must be 'train' or 'test', got {m.split!r}")
    for k in RECOMMENDED_CAPTURE_KEYS:
        if k not in m.capture:
            problems.append(f"note: capture.{k} missing (recommended context, not required)")
    return problems


def hard_errors(problems: list[str]) -> list[str]:
    """The subset of validate() problems that block scoring (drops 'note:' advisories)."""
    return [p for p in problems if not p.startswith("note:")]


EXAMPLE_MANIFEST = {
    "clip_id": "juba_door2_2026-08-20_0007",
    "video": "clips/juba_door2_2026-08-20_0007.mp4",
    "store": "wh-juba-01",
    "zone_uid": "D->EXIT",
    "line": [[0, 540], [1920, 540]],
    "fps": 25.0,
    "session": "juba-2026-08-20-am",
    "split": "train",
    "capture": {
        "camera_height_m": 3.2,
        "lens": "2.8mm",
        "resolution": [1920, 1080],
        "lighting": "mixed daylight + overhead fluorescent",
        "door_geometry": "roll-up door, ~3m wide, camera above and inside",
        "carton_types": ["standard 40x30x30 brown", "taped mixed sizes"],
        "datetime": "2026-08-20T09:14:00+03:00",
        "location": "Juba warehouse — dispatch door 2",
    },
    "frames": {
        "120": {"carton_present": True, "occluded": False, "continuously_visible": True,
                "boxes": [{"box": [900, 480, 1040, 620], "label": "carton"}]},
        "121": {"carton_present": True, "occluded": True, "continuously_visible": True,
                "boxes": [{"box": [905, 490, 1045, 630], "label": "carton"}]},
    },
    "crossings": [
        {"gt_id": "A", "direction": "out", "frame": 132, "ts": 5.28,
         "num_cartons": 1, "occluded": False, "continuously_visible": True},
    ],
}
