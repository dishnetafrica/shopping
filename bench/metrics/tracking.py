"""
Tracking metrics — track continuity, ID switches, tracking loss.

Computed from the per-frame TRACE the MovementPipeline emits (its optional `tracer` hook), i.e. from
exactly the tracker output that drove the state machine — not a separate re-run. `trace` is an ordered
list, one dict per processed frame: {ts, cartons:{tid:box}, persons:{tid:box}, crossings:[...], lost:[...]}.
Trace position == frame index (run.py feeds frames in order).

Two tiers:
  • aggregate (always): distinct carton tracks, mean track length, fragmentation vs #crossings,
    tracking-loss events.
  • per-crossing (when the manifest has verified boxes near a crossing): how many distinct track ids
    covered that one carton (ID switches = distinct − 1) and continuity over the window.
"""
from __future__ import annotations

from .detection import iou


def _aggregate(trace):
    lengths: dict = {}
    loss_events = 0
    for fr in trace:
        for tid in fr["cartons"]:
            lengths[tid] = lengths.get(tid, 0) + 1
        loss_events += len(fr.get("lost", []))
    distinct = len(lengths)
    mean_len = (sum(lengths.values()) / distinct) if distinct else 0.0
    return distinct, mean_len, loss_events


def evaluate(trace, crossings, gt_frames: dict, window: int = 15) -> dict:
    """
    trace:      list of per-frame tracer dicts (position == frame index)
    crossings:  list[GtCrossing]
    gt_frames:  {frame_index: GtFrame} (for per-crossing track↔carton association; may be sparse/empty)
    window:     ± frames around a crossing to inspect for continuity / switches
    """
    distinct, mean_len, loss_events = _aggregate(trace)
    n_cross = max(1, len(crossings))

    per_crossing = []
    switches_total = 0
    continuity_vals = []
    for c in crossings:
        seq = []                       # matched carton track id per labelled frame in the window
        labelled = 0
        for idx in range(max(0, c.frame - window), min(len(trace), c.frame + window + 1)):
            gf = gt_frames.get(idx)
            if gf is None or not gf.boxes:
                continue
            labelled += 1
            gbox = gf.boxes[0].box     # primary carton box on that frame
            best_tid, best_iou = None, 0.3
            for tid, tbox in trace[idx]["cartons"].items():
                v = iou(gbox, tbox)
                if v >= best_iou:
                    best_iou, best_tid = v, tid
            if best_tid is not None:
                seq.append(best_tid)
        distinct_ids = len(set(seq))
        switches = max(0, len(_dedupe_runs(seq)) - 1)
        switches_total += switches
        continuity = (len(seq) / labelled) if labelled else None
        if continuity is not None:
            continuity_vals.append(continuity)
        per_crossing.append({
            "gt_id": c.gt_id, "labelled_frames_in_window": labelled,
            "matched_frames": len(seq), "distinct_track_ids": distinct_ids,
            "id_switches": switches,
            "continuity": round(continuity, 3) if continuity is not None else None,
        })

    mean_continuity = round(sum(continuity_vals) / len(continuity_vals), 3) if continuity_vals else None
    return {
        "distinct_carton_tracks": distinct,
        "mean_track_length_frames": round(mean_len, 2),
        "fragmentation_ratio": round(distinct / n_cross, 3),   # ideal ≈ 1.0 (one track per crossing)
        "tracking_loss_events": loss_events,
        "id_switches_total": switches_total,
        "mean_continuity": mean_continuity,                    # None if no verified boxes near crossings
        "per_crossing": per_crossing,
        "note": None if any(p["labelled_frames_in_window"] for p in per_crossing) or not crossings
        else "no verified boxes near any crossing → per-crossing continuity/switches not measurable; "
             "label a few frames around each crossing to enable them",
    }


def _dedupe_runs(seq):
    """Collapse consecutive identical ids: [7,7,8,8,7] -> [7,8,7] (each change = one switch)."""
    out = []
    for x in seq:
        if not out or out[-1] != x:
            out.append(x)
    return out
