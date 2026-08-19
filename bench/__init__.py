"""
ShopBot Vision — carton-crossing BENCHMARK harness (research layer, NOT deployed).

This package answers ONE question with real warehouse footage:

    Can a camera reliably observe a carton crossing one dispatch-door boundary,
    and produce EXACTLY ONE correct `carton.move` — often enough to be a product?

It lives OUTSIDE app/ on purpose: the Dockerfile copies app/ (+ config, database, …) into the
deployed edge image, but never bench/. So the zero-shot / open-vocabulary detectors used here for
*pre-labelling* can never leak into the commercial image. bench/ imports app.movement one way only;
production ships app/movement/ without bench/.

Hard rules (from the project owner):
  • The zero-shot detector is a RESEARCH / pre-label tool. It is NOT ground truth and NOT a
    production dependency. Never score accuracy against the zero-shot model's own predictions.
  • Ground truth is HUMAN-VERIFIED (see manifest.py). AI proposal → human correction → verified GT.
  • No metric printed here is "production accuracy" until it is computed over human-verified GT from
    real footage, with a held-out test set from a different session/day. The sandbox self-test
    (selftest.py) only proves the metric MATH is correct on scripted known-answer data.

Layers:
    ingest.py     video → frames (+ preserved capture metadata)
    detectors/    pluggable detector interface + zero-shot adapter (research) + trained-model adapter
    manifest.py   the clip + human-verified ground-truth schema
    metrics/      detection · tracking · movement · system, each reported SEPARATELY
    run.py        one command: manifest → app.movement pipeline → all metrics → report
    report.py     markdown + JSON benchmark report generator
    selftest.py   proves the metric math in the sandbox (no cv2, no model, no footage)
"""
