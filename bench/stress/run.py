"""
Run the movement stress matrix: every scenario × every perturbation through the REAL pipeline
(sv.ByteTrack + sv.LineZone + MovementStateMachine), scoring the operational invariant and measuring
software throughput/latency. Emits a report that SEPARATES software-replay results (measured here) from
physical edge-box results (a template to fill on hardware — never fabricated).

  python bench/stress/run.py --out out/stress

Invariant scored (count-based, so it is robust to replay-speed ts scaling):
  N real crossings → N movement events, each with a DISTINCT uid (identity independent of attribution);
  0 crossings → 0 events.
Under aggressive perturbation the pipeline may legitimately miss/duplicate — that is MEASURED and
reported, not hidden. Only the baseline + zero-crossing invariants are asserted (in selftest.py).
"""
from __future__ import annotations

import json
import os
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))

from app.movement.config import MovementConfig
from app.movement.detector import ScriptedDetector
from app.movement.pipeline import MovementPipeline
from bench.contract_ref import CARTON_MOVE
from bench.stress.perturb import PERTURBATIONS
from bench.stress.scenarios import SCENARIOS


def run_one(scenario_fn, perturb_fn) -> dict:
    stream, gt = scenario_fn()
    stream = perturb_fn(stream)
    script = {i: dets for i, (ts, dets) in enumerate(stream)}
    cfg = MovementConfig(store="stress", zone_uid="D->EXIT", line=((0, 300), (640, 300)))
    events, latencies = [], []
    cur = {"ts": 0.0}

    def sink(p):
        p = dict(p)
        p["ts"] = cur["ts"]
        events.append(p)

    pipe = MovementPipeline(cfg, ScriptedDetector(script), sink)
    t0 = time.perf_counter()
    for i, (ts, _dets) in enumerate(stream):
        cur["ts"] = ts
        s = time.perf_counter()
        pipe.process(i, ts)
        latencies.append((time.perf_counter() - s) * 1000.0)
    elapsed = time.perf_counter() - t0

    moves = [e for e in events if e["event"] == CARTON_MOVE]
    uids = {e["uid"] for e in moves}
    gt_n = len(gt)
    ev_n = len(moves)
    lat = sorted(latencies)

    def pct(p):
        return round(lat[min(len(lat) - 1, int(p / 100 * (len(lat) - 1)))], 3) if lat else None

    invariant = (ev_n == gt_n and len(uids) == ev_n) if gt_n else (ev_n == 0)
    return {
        "gt_crossings": gt_n, "events": ev_n, "unique_uids": len(uids),
        "duplicate_uids": ev_n - len(uids), "missed": max(0, gt_n - ev_n),
        "extra": max(0, ev_n - gt_n), "invariant_ok": invariant,
        "frames": len(stream), "elapsed_s": round(elapsed, 4),
        "processed_fps": round(len(stream) / elapsed, 1) if elapsed else None,
        "latency_ms": {"p50": pct(50), "p95": pct(95), "max": pct(100)},
    }


def run_matrix() -> list[dict]:
    out = []
    for sname, (sfn, _expect) in SCENARIOS.items():
        for pname, pfn in PERTURBATIONS.items():
            r = run_one(sfn, pfn)
            r["scenario"], r["perturbation"] = sname, pname
            out.append(r)
    return out


def render(results: list[dict]) -> str:
    L = ["# Movement-pipeline video stress test — SOFTWARE replay\n",
         "> Report **B (movement)** only: given detections, does the pipeline produce correct movements? "
         "Report **A (vision/detection)** needs a real detector + footage (Track B, hardware). "
         "These numbers use a scripted detector — they say nothing about detecting real pixels.\n",
         "\n## Operational invariant per scenario × perturbation\n",
         "| scenario | perturbation | gt | events | uids | invariant | fps | p95 ms |",
         "|---|---|---|---|---|---|---|---|"]
    for r in results:
        ok = "✅" if r["invariant_ok"] else f"⚠️ {r['missed']}miss/{r['extra']}extra/{r['duplicate_uids']}dup"
        L.append(f"| {r['scenario']} | {r['perturbation']} | {r['gt_crossings']} | {r['events']} | "
                 f"{r['unique_uids']} | {ok} | {r['processed_fps']} | {r['latency_ms']['p95']} |")
    passed = sum(1 for r in results if r["invariant_ok"])
    L.append(f"\n**{passed}/{len(results)} scenario×perturbation combinations hold the invariant.** "
             "⚠️ rows are MEASURED degradations under adverse streams — real findings, not hidden.\n")
    L.append(_physical_template())
    return "\n".join(L)


def _physical_template() -> str:
    return (
        "## Physical edge-box results (fill on hardware — NOT measured here, never fabricated)\n\n"
        "The sandbox has no camera/codec/GPU, so these are unmeasured. Capture on the real box:\n\n"
        "| metric | how | value |\n|---|---|---|\n"
        "| resolution matrix | 720p/1080p/4MP/5MP real decode | |\n"
        "| codec | H.264 / H.265 | |\n"
        "| input vs processed FPS | 10/15/20/25/30 | |\n"
        "| decode latency | ffprobe / decode timer | |\n"
        "| detector latency | real model on the box | |\n"
        "| total latency | frame→carton.move | |\n"
        "| CPU / RAM | docker stats | |\n"
        "| GPU / NPU | tegrastats / RKNN load | |\n"
        "| decoder failures / reconnects | RtspFrameSource reconnect count | |\n"
        "| motion blur / real occlusion | real footage | |\n"
        "| long-running stability | hours of replay | |\n\n"
        "Report A (detection precision/recall/mAP on real pixels) is produced by the `bench/` detector "
        "eval, not this harness.\n"
    )


def main(argv):
    import argparse
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", default="out/stress")
    args = ap.parse_args(argv)
    results = run_matrix()
    os.makedirs(os.path.dirname(args.out) or ".", exist_ok=True)
    with open(args.out + ".json", "w") as fh:
        json.dump(results, fh, indent=2)
    with open(args.out + ".md", "w") as fh:
        fh.write(render(results))
    passed = sum(1 for r in results if r["invariant_ok"])
    print(f"stress matrix: {passed}/{len(results)} invariant-holding; wrote {args.out}.md/.json")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
