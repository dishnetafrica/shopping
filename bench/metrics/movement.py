"""
Movement metrics — the OPERATIONAL truth of the whole project.

    true crossings · missed crossings · false crossings · duplicate carton.move · direction accuracy
    and the headline:  carton crossing → EXACTLY ONE correct carton.move.

Each emitted carton.move (tagged with the frame time it fired at) is assigned to the nearest
human-verified GT crossing within a time tolerance. From that single assignment everything else falls
out. Pure counting — no model, no camera — so it is fully proven in the sandbox; only the events and
the GT need real footage.
"""
from __future__ import annotations

from ..contract_ref import CARTON_MOVE


def _gt_time(c, fps):
    if c.ts is not None and c.ts >= 0:
        return float(c.ts)
    if c.frame is not None and c.frame >= 0 and fps:
        return c.frame / float(fps)
    return None


def evaluate(events, crossings, tol_s: float = 1.0, fps: float = 25.0) -> dict:
    """
    events:    list of dicts, each {'ts': float (frame time at emission), 'direction': 'in'|'out', ...}
               (only carton.move events; tracking.gap is excluded before calling)
    crossings: list[GtCrossing]
    tol_s:     max |event_time − crossing_time| to count as the same physical crossing
    """
    moves = [e for e in events if e.get("event", CARTON_MOVE) == CARTON_MOVE]
    gts = [(c, _gt_time(c, fps)) for c in crossings]

    # assign each event to the nearest crossing within tolerance (regardless of direction)
    assigned: dict = {i: [] for i in range(len(gts))}
    false_events = []
    for e in moves:
        et = float(e.get("ts", -1))
        best_i, best_dt = None, tol_s
        for i, (c, ct) in enumerate(gts):
            if ct is None:
                continue
            dt = abs(et - ct)
            if dt <= best_dt:
                best_dt, best_i = dt, i
        if best_i is None:
            false_events.append(e)
        else:
            assigned[best_i].append(e)

    true_crossings = missed = duplicates = exactly_one_correct = 0
    matched_events = correct_dir_events = 0
    per_crossing = []
    for i, (c, ct) in enumerate(gts):
        evs = assigned[i]
        n = len(evs)
        correct = [e for e in evs if e.get("direction") == c.direction]
        matched_events += n
        correct_dir_events += len(correct)
        if n == 0:
            missed += 1
        else:
            true_crossings += 1
            if n > 1:
                duplicates += (n - 1)
        one_correct = (n == 1 and evs[0].get("direction") == c.direction)
        if one_correct:
            exactly_one_correct += 1
        per_crossing.append({
            "gt_id": c.gt_id, "gt_direction": c.direction, "events_matched": n,
            "correct_direction": len(correct), "exactly_one_correct": one_correct,
            "occluded": c.occluded,
        })

    total = len(gts)
    direction_accuracy = (correct_dir_events / matched_events) if matched_events else None
    return {
        "gt_crossings": total,
        "true_crossings": true_crossings,
        "missed_crossings": missed,
        "false_crossings": len(false_events),
        "duplicate_carton_moves": duplicates,
        "direction_accuracy": round(direction_accuracy, 4) if direction_accuracy is not None else None,
        # THE operational headline — one correct carton.move per real crossing, nothing more, nothing less
        "exactly_one_correct": exactly_one_correct,
        "exactly_one_correct_rate": round(exactly_one_correct / total, 4) if total else None,
        "tolerance_s": tol_s,
        "per_crossing": per_crossing,
    }
