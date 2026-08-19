"""
Benchmark report generator — turns per-clip metric dicts into a human-readable markdown report with
the four metric groups the owner asked for kept SEPARATE, plus the operational headline and an honest
provenance header. Also aggregates train vs held-out (test) splits so real generalisation is visible.

No metric here is labelled "production accuracy": the report states the detector, the clips, the
sessions, and whether a held-out split was present, so a reader can judge how far to trust it.
"""
from __future__ import annotations


def _fmt(v):
    return "—" if v is None else (f"{v:.3f}" if isinstance(v, float) else str(v))


def _agg(results, split):
    rows = [r for r in results if r.get("split") == split]
    if not rows:
        return None
    n = len(rows)

    def msum(path, key):
        vals = [r[path][key] for r in rows if r[path].get(key) is not None]
        return sum(vals) if vals else 0

    gt = msum("movement", "gt_crossings")
    return {
        "clips": n,
        "sessions": sorted({r["session"] for r in rows}),
        "gt_crossings": gt,
        "true": msum("movement", "true_crossings"),
        "missed": msum("movement", "missed_crossings"),
        "false": msum("movement", "false_crossings"),
        "duplicate": msum("movement", "duplicate_carton_moves"),
        "exactly_one_correct": msum("movement", "exactly_one_correct"),
        "eoc_rate": (msum("movement", "exactly_one_correct") / gt) if gt else None,
    }


def render(results: list) -> str:
    L = []
    L.append("# ShopBot Vision — carton-crossing benchmark report\n")
    if not results:
        L.append("_No clips scored._\n")
        return "\n".join(L)

    L.append("> **Not production accuracy.** These are measurements over the specific clips and "
             "human-verified ground truth listed below. Trust extends only as far as the held-out "
             "(test) split from a different session/day.\n")

    # ── headline ────────────────────────────────────────────────────────────────────────────
    for split in ("train", "test"):
        a = _agg(results, split)
        if not a:
            continue
        label = "HELD-OUT TEST (different session/day)" if split == "test" else "train/dev"
        L.append(f"## Operational headline — {label}\n")
        L.append(f"- clips: **{a['clips']}**  ·  sessions: {', '.join(a['sessions'])}")
        L.append(f"- GT crossings: **{a['gt_crossings']}**")
        L.append(f"- **carton crossing → exactly one correct `carton.move`: "
                 f"{a['exactly_one_correct']}/{a['gt_crossings']} "
                 f"({_fmt(a['eoc_rate'])})**  ← the metric that matters")
        L.append(f"- true {a['true']} · missed {a['missed']} · false {a['false']} · "
                 f"duplicate {a['duplicate']}\n")
    if not any(_agg(results, s) for s in ("test",)):
        L.append("> ⚠️ **No held-out test split present.** Add clips with `\"split\": \"test\"` from a "
                 "DIFFERENT recording session before trusting these numbers as generalisation.\n")

    # ── per-clip detail ─────────────────────────────────────────────────────────────────────
    for r in results:
        L.append(f"---\n\n## {r['clip_id']}  ({r['split']} · session {r['session']})")
        cap = r.get("capture", {})
        if cap:
            ctx = ", ".join(f"{k}={cap[k]}" for k in
                            ("camera_height_m", "lens", "lighting", "door_geometry", "datetime")
                            if k in cap)
            if ctx:
                L.append(f"_context: {ctx}_")

        d = r["detection"]
        L.append("\n**Detector** (over human-verified frame labels)\n")
        L.append("| precision | recall | mAP@0.5 | TP | FP | FN | GT boxes | frames |")
        L.append("|---|---|---|---|---|---|---|---|")
        L.append(f"| {_fmt(d['precision'])} | {_fmt(d['recall'])} | {_fmt(d['mAP50'])} | "
                 f"{d['true_positives']} | {d['false_positives']} | {d['false_negatives']} | "
                 f"{d['gt_boxes']} | {d['labelled_frames']} |")

        t = r["tracking"]
        L.append("\n**Tracking**\n")
        L.append("| distinct tracks | mean track len | fragmentation | loss events | ID switches | mean continuity |")
        L.append("|---|---|---|---|---|---|")
        L.append(f"| {t['distinct_carton_tracks']} | {_fmt(t['mean_track_length_frames'])} | "
                 f"{_fmt(t['fragmentation_ratio'])} | {t['tracking_loss_events']} | "
                 f"{t['id_switches_total']} | {_fmt(t['mean_continuity'])} |")
        if t.get("note"):
            L.append(f"\n_{t['note']}_")

        m = r["movement"]
        L.append("\n**Movement** (the operational truth)\n")
        L.append("| GT crossings | true | missed | false | duplicate | direction acc | exactly-one-correct |")
        L.append("|---|---|---|---|---|---|---|")
        L.append(f"| {m['gt_crossings']} | {m['true_crossings']} | {m['missed_crossings']} | "
                 f"{m['false_crossings']} | {m['duplicate_carton_moves']} | "
                 f"{_fmt(m['direction_accuracy'])} | {m['exactly_one_correct']}/{m['gt_crossings']} "
                 f"({_fmt(m['exactly_one_correct_rate'])}) |")

        s = r["system"]
        L.append("\n**System** (this host — re-run on the real edge box for a hardware verdict)\n")
        lat = s["latency_ms"]
        L.append("| FPS | p50 ms | p95 ms | max ms | peak RSS MB | GPU | decode share |")
        L.append("|---|---|---|---|---|---|---|")
        L.append(f"| {_fmt(s['fps'])} | {_fmt(lat['p50'])} | {_fmt(lat['p95'])} | {_fmt(lat['max'])} | "
                 f"{_fmt(s['peak_rss_mb'])} | {s['gpu'].get('backend')} | "
                 f"{_fmt(s['rtsp_decode_load']['decode_share_of_wall'])} |")
        L.append("")

    return "\n".join(L)
