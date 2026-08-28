"""
Detector metrics — precision, recall, mAP@0.5, false positives — over HUMAN-VERIFIED frame labels.

Scored per labelled frame by greedy IoU matching (highest-confidence detection first), carton class.
Only frames present in the manifest's `frames` map are scored; unlabelled frames are ignored, so a
sparse label set is fine. This is pure geometry + counting — no model, no cv2 — so the math is proven
in the sandbox; only the detector's real boxes need real footage.
"""
from __future__ import annotations


def iou(a, b) -> float:
    ax0, ay0, ax1, ay1 = a
    bx0, by0, bx1, by1 = b
    ix0, iy0 = max(ax0, bx0), max(ay0, by0)
    ix1, iy1 = min(ax1, bx1), min(ay1, by1)
    iw, ih = max(0.0, ix1 - ix0), max(0.0, iy1 - iy0)
    inter = iw * ih
    if inter <= 0:
        return 0.0
    area_a = max(0.0, ax1 - ax0) * max(0.0, ay1 - ay0)
    area_b = max(0.0, bx1 - bx0) * max(0.0, by1 - by0)
    union = area_a + area_b - inter
    return inter / union if union > 0 else 0.0


def _match_frame(dets, gts, iou_thr):
    """Greedy match one frame. dets: list[(box, conf)] sorted desc by conf. gts: list[box].
    Returns (tp_flags aligned to dets, matched_gt_count)."""
    used = [False] * len(gts)
    tp = [False] * len(dets)
    for di, (dbox, _conf) in enumerate(dets):
        best_j, best_iou = -1, iou_thr
        for j, gbox in enumerate(gts):
            if used[j]:
                continue
            v = iou(dbox, gbox)
            if v >= best_iou:
                best_iou, best_j = v, j
        if best_j >= 0:
            used[best_j] = True
            tp[di] = True
    return tp, sum(used)


def evaluate(per_frame_dets: dict, per_frame_gts: dict, iou_thr: float = 0.5) -> dict:
    """
    per_frame_dets: {frame_index: [(box, confidence), ...]}  — detector output on labelled frames
    per_frame_gts:  {frame_index: [box, ...]}                — human-verified carton boxes

    Returns precision, recall, ap50 (single carton class), and raw TP/FP/FN counts.
    Detections on frames that have no GT entry are NOT scored (we only trust labelled frames).
    """
    # Global ranked list for AP: every detection across labelled frames, tagged TP/FP, ranked by conf.
    scored = []           # (confidence, is_tp)
    total_gt = 0
    tp_total = fp_total = 0
    for idx, gts in per_frame_gts.items():
        total_gt += len(gts)
        dets = sorted(per_frame_dets.get(idx, []), key=lambda r: -r[1])
        tp_flags, _matched = _match_frame(dets, gts, iou_thr)
        for (dbox, conf), is_tp in zip(dets, tp_flags):
            scored.append((conf, is_tp))
            if is_tp:
                tp_total += 1
            else:
                fp_total += 1
    fn_total = total_gt - tp_total

    precision = tp_total / (tp_total + fp_total) if (tp_total + fp_total) else 0.0
    recall = tp_total / total_gt if total_gt else 0.0
    ap50 = _average_precision(scored, total_gt)

    return {
        "iou_threshold": iou_thr,
        "precision": round(precision, 4),
        "recall": round(recall, 4),
        "ap50": round(ap50, 4),
        "mAP50": round(ap50, 4),        # single class → mAP == AP
        "true_positives": tp_total,
        "false_positives": fp_total,
        "false_negatives": fn_total,
        "gt_boxes": total_gt,
        "labelled_frames": len(per_frame_gts),
    }


def _average_precision(scored, total_gt) -> float:
    """All-point-interpolation AP (VOC-style) from a confidence-ranked TP/FP list."""
    if total_gt == 0:
        return 0.0
    scored = sorted(scored, key=lambda r: -r[0])
    tp = fp = 0
    points = []           # (recall, precision)
    for _conf, is_tp in scored:
        if is_tp:
            tp += 1
        else:
            fp += 1
        points.append((tp / total_gt, tp / (tp + fp)))
    if not points:
        return 0.0
    # monotonic-decreasing precision envelope, then integrate over recall
    recalls = [0.0] + [r for r, _ in points]
    precs = [0.0] + [p for _, p in points]
    for i in range(len(precs) - 2, -1, -1):
        precs[i] = max(precs[i], precs[i + 1])
    ap = 0.0
    for i in range(1, len(recalls)):
        ap += (recalls[i] - recalls[i - 1]) * precs[i]
    return ap
