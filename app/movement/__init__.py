"""
ShopBot Vision — edge MOVEMENT pipeline (Phase-0 single dispatch door).

Additive sibling to the classical shelf detector — it never imports or touches it. Layers, each
swappable and tested in isolation:

    CameraSource (sources.py)  →  Detector (detector.py)  →  Supervision ByteTrack + LineZone
        →  MovementStateMachine (state_machine.py)  →  carton.move (contract.py)  →  events.send()

The state machine (the decision logic) is PURE — no Supervision, no camera, no network — so the nine
acceptance scenarios are deterministic. Supervision is isolated inside pipeline.py. Reconciliation
(carton.move → ledger → MATCH/PENDING/UNRECONCILED) lives ONLY in ShopBot, never here.
"""
