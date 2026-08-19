"""
Sandbox self-test for the Track-A pipeline logic that CAN run without a GPU or datasets:
the class-normalization map, the deterministic split, and the evaluation wiring.

It does NOT train or download anything (impossible here). It proves the parts that decide dataset
correctness are right, so the GPU/Colab run inherits correct logic. Run: python3 training/selftest.py
"""
from __future__ import annotations

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from classmap import PRIMARY, remap
from prepare import assign_split
from evaluate import evaluate

_pass = _fail = 0


def check(name, cond, detail=""):
    global _pass, _fail
    if cond:
        _pass += 1; print(f"  PASS  {name}")
    else:
        _fail += 1; print(f"  FAIL  {name}  {detail}")


def test_classmap():
    cases = {
        "carton": ("carton", ("stock_object", "carton")),
        "Cardboard Box": ("box→carton", ("stock_object", "carton")),
        "rice bag": ("sack", ("stock_object", "sack")),
        "sack": ("sack", ("stock_object", "sack")),
        "package": ("parcel", ("stock_object", "parcel")),
        "pallet": ("pallet", ("stock_object", "pallet")),
        "small load carrier": ("container", ("stock_object", "container")),
        "shrink-wrapped": ("wrapped", ("stock_object", "wrapped_package")),
        "closed carton": ("token-carton", ("stock_object", "carton")),
    }
    for raw, (label, expect) in cases.items():
        check(f"remap({raw!r}) → {label}", remap(raw) == expect, f"got {remap(raw)}")
    # negatives map to None (never a stock_object)
    for neg in ("person", "forklift", "pallet truck", "shelf", "backpack", "conveyor"):
        check(f"remap({neg!r}) is negative", remap(neg) is None, f"got {remap(neg)}")
    # unknown → None, never force-fit into a positive
    check("unknown class is dropped, not force-positive", remap("xyzzy widget") is None, remap("xyzzy widget"))
    check("primary label constant", PRIMARY == "stock_object")


def test_split_deterministic_and_distributed():
    ids = [f"img_{i}" for i in range(2000)]
    a = [assign_split(i) for i in ids]
    b = [assign_split(i) for i in ids]
    check("split is deterministic (same id → same split)", a == b)
    frac_test = a.count("test") / len(a)
    frac_val = a.count("val") / len(a)
    check("test split ≈10%", 0.06 <= frac_test <= 0.14, f"{frac_test:.3f}")
    check("val split ≈10%", 0.06 <= frac_val <= 0.14, f"{frac_val:.3f}")
    check("train is the majority", a.count("train") > len(a) * 0.7)


def test_eval_wiring():
    gt = {"i1": [[10, 10, 50, 50]], "i2": [[100, 100, 140, 140]]}
    perfect = {"i1": [[10, 10, 50, 50, 0.9]], "i2": [[100, 100, 140, 140, 0.8]]}
    r = evaluate(perfect, gt)
    check("eval: perfect preds → P=R=1", r["precision"] == 1.0 and r["recall"] == 1.0, r)
    missed = {"i1": [[10, 10, 50, 50, 0.9]], "i2": []}
    r = evaluate(missed, gt)
    check("eval: one miss → recall 0.5", r["recall"] == 0.5, r)


def main():
    print("TRACK-A PIPELINE SELF-TEST (classmap + split + eval wiring)\n")
    for fn in (test_classmap, test_split_deterministic_and_distributed, test_eval_wiring):
        fn()
    print(f"\n{_pass} passed, {_fail} failed")
    return 1 if _fail else 0


if __name__ == "__main__":
    raise SystemExit(main())
