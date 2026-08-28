"""
The FROZEN carton.move event contract — the interface between the edge service and ShopBot's
(already frozen, already tested) VisionReconciler. Two systems evolve independently on either side of
this dict shape, so it lives here explicitly and is regression-tested, not hand-built ad hoc.

Mirrors what ShopBot's VisionReconciler.buildEvent() reads:
  event, uid, zone_uid, direction, object{class,qty,qty_conf}, assoc{track_id,candidates,assoc_conf},
  confidence, occlusion, timestamp   (+ store, used by the ingest controller)
"""
from __future__ import annotations

import hashlib
import time

CARTON_MOVE = "carton.move"
TRACKING_GAP = "tracking.gap"

REQUIRED_KEYS = ("event", "uid", "store", "zone_uid", "direction", "object", "assoc",
                 "confidence", "occlusion", "timestamp")


def event_uid(store: str, zone_uid: str, track_id, direction: str, ts: float) -> str:
    """Stable 32-char id for one crossing — same crossing in → same uid (idempotency on the ShopBot side)."""
    raw = f"{store}|{zone_uid}|{track_id}|{direction}|{ts:.3f}"
    return hashlib.sha256(raw.encode("utf-8")).hexdigest()[:32]


def iso(ts: float) -> str:
    """Local-timezone ISO-8601, matching the existing service's events._iso()."""
    return time.strftime("%Y-%m-%dT%H:%M:%S%z", time.localtime(ts))


def carton_move(store: str, zone_uid: str, direction: str, ts: float, *,
                object_track_id, track_id, candidates, assoc_conf: float, confidence: float,
                occlusion: bool, qty: int = 1, qty_conf: float = 1.0, object_class: str = "carton") -> dict:
    # WHO carried it (track_id, a person) ≠ WHAT moved (object_track_id, the stock object). The event
    # IDENTITY — and therefore the idempotency uid — is the stock object's own track, never the person's.
    # Keying the uid on the person collides when one worker carries several objects, and ShopBot's
    # unique(tenant,event_uid) index would then drop real crossings as duplicates. assoc.track_id keeps
    # the person purely as attribution.
    return {
        "event": CARTON_MOVE,
        "uid": event_uid(store, zone_uid,
                         object_track_id if object_track_id is not None else "?", direction, ts),
        "store": store,
        "zone_uid": zone_uid,
        "direction": direction,
        "object": {"class": object_class, "qty": int(qty), "qty_conf": round(float(qty_conf), 3)},
        "assoc": {
            "track_id": (int(track_id) if track_id is not None else None),
            "candidates": [int(c) for c in (candidates or [])],
            "assoc_conf": round(float(assoc_conf), 3),
        },
        "confidence": round(float(confidence), 3),
        "occlusion": bool(occlusion),
        "timestamp": iso(ts),
    }


def tracking_gap(store: str, zone_uid: str, ts: float) -> dict:
    """A tracked carton vanished without crossing — the edge reports a sensor gap, NEVER a movement.
    ShopBot maps this to COVERAGE_GAP, never a red."""
    return {
        "event": TRACKING_GAP,
        "uid": event_uid(store, zone_uid, "gap", "none", ts),
        "store": store,
        "zone_uid": zone_uid,
        "timestamp": iso(ts),
    }


def validate_carton_move(p: dict) -> list[str]:
    """Return a list of contract violations ([] == valid). Regression guard on the frozen shape."""
    problems: list[str] = []
    if not isinstance(p, dict):
        return ["payload is not a dict"]
    for k in REQUIRED_KEYS:
        if k not in p:
            problems.append(f"missing key: {k}")
    if p.get("event") != CARTON_MOVE:
        problems.append("event must be 'carton.move'")
    if p.get("direction") not in ("in", "out"):
        problems.append("direction must be 'in' or 'out'")
    obj = p.get("object")
    if not isinstance(obj, dict) or "class" not in obj or "qty" not in obj or "qty_conf" not in obj:
        problems.append("object must be {class, qty, qty_conf}")
    a = p.get("assoc")
    if not isinstance(a, dict) or "track_id" not in a or "candidates" not in a or "assoc_conf" not in a:
        problems.append("assoc must be {track_id, candidates, assoc_conf}")
    elif not isinstance(a.get("candidates"), list):
        problems.append("assoc.candidates must be a list")
    if not isinstance(p.get("occlusion"), bool):
        problems.append("occlusion must be a bool")
    if not isinstance(p.get("uid"), str) or len(p.get("uid", "")) != 32:
        problems.append("uid must be a 32-char string")
    return problems


# A frozen, canonical example — the schema fixture both sides can point at.
# uid is keyed on the STOCK OBJECT track (23); the person who carried it (5) lives only in assoc.
EXAMPLE_CARTON_MOVE = {
    "event": "carton.move",
    "uid": event_uid("wh-juba-01", "D->EXIT", 23, "out", 1_723_987_654.0),
    "store": "wh-juba-01",
    "zone_uid": "D->EXIT",
    "direction": "out",
    "object": {"class": "carton", "qty": 1, "qty_conf": 0.94},
    "assoc": {"track_id": 5, "candidates": [5], "assoc_conf": 0.88},   # person = attribution only
    "confidence": 0.86,
    "occlusion": False,
    "timestamp": iso(1_723_987_654.0),
}
