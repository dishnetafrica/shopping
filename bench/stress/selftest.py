"""
Sandbox self-test for the movement stress harness — asserts the invariants that must ALWAYS hold
(baseline correctness + zero-crossing + identity independence + survival of mild-to-aggressive stream
perturbations). The full matrix in run.py MEASURES everything; this locks the non-negotiable subset so a
regression fails loudly. Runs the REAL sv.ByteTrack + sv.LineZone pipeline. python3 bench/stress/selftest.py
"""
from __future__ import annotations

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))

from bench.stress.perturb import PERTURBATIONS
from bench.stress.run import run_one
from bench.stress.scenarios import SCENARIOS

_pass = _fail = 0


def check(name, cond, detail=""):
    global _pass, _fail
    if cond:
        _pass += 1; print(f"  PASS  {name}")
    else:
        _fail += 1; print(f"  FAIL  {name}  {detail}")


def test_baseline_invariants():
    for sname, (sfn, expect) in SCENARIOS.items():
        r = run_one(sfn, PERTURBATIONS["baseline"])
        check(f"{sname}: baseline → {expect} events, invariant holds",
              r["invariant_ok"] and r["events"] == expect, r)


def test_identity_independent_of_attribution():
    # mixed objects (one worker) must yield one DISTINCT uid per object, not per worker
    r = run_one(SCENARIOS["mixed_simultaneous"][0], PERTURBATIONS["baseline"])
    check("mixed: 3 events, 3 distinct uids (identity ≠ attribution)",
          r["events"] == 3 and r["unique_uids"] == 3 and r["duplicate_uids"] == 0, r)


def test_zero_crossings_zero_events_under_all_perturbations():
    for sname in ("empty_worker", "stationary_near_line"):
        for pname, pfn in PERTURBATIONS.items():
            r = run_one(SCENARIOS[sname][0], pfn)
            check(f"{sname} × {pname}: 0 crossings → 0 events", r["events"] == 0, r)


def test_single_crossing_survives_stream_adversity():
    for pname in ("drop_1in3", "dup_1in5", "jitter", "reorder", "speed_4x", "corrupt_1in7"):
        r = run_one(SCENARIOS["single_crossing"][0], PERTURBATIONS[pname])
        check(f"single_crossing × {pname}: exactly 1 event", r["events"] == 1 and r["invariant_ok"], r)


def test_long_running_no_drift():
    r = run_one(SCENARIOS["long_running_20"][0], PERTURBATIONS["baseline"])
    check("long_running_20: 20 crossings → 20 distinct events (no drift)",
          r["events"] == 20 and r["unique_uids"] == 20, r)


def main():
    print("MOVEMENT STRESS SELF-TEST (real pipeline, software replay)\n")
    for fn in (test_baseline_invariants, test_identity_independent_of_attribution,
               test_zero_crossings_zero_events_under_all_perturbations,
               test_single_crossing_survives_stream_adversity, test_long_running_no_drift):
        fn()
    print(f"\n{_pass} passed, {_fail} failed")
    return 1 if _fail else 0


if __name__ == "__main__":
    raise SystemExit(main())
