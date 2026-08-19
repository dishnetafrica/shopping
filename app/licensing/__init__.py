"""
ShopBot Vision — device identity & licensing (edge side).

Primary identity is a DEVICE-GENERATED Ed25519 keypair (private key never leaves the box); the layered
hardware fingerprint is an ADDITIONAL binding signal, never the sole identity. Licenses are Ed25519
tokens signed by ShopBot's private key (which stays server-side); the box carries only ShopBot's PUBLIC
key and verifies offline — so a temporary internet outage never stops the warehouse (grace period).

This is a license GATE, not DRM: a root attacker can patch a local runtime. It does not try to stop
that. It makes a copied box fail to activate/authenticate against ShopBot, while the real value —
cloud API, reconciliation, ERP integrations, customer data, review, the model+training lifecycle —
stays server-side and off the box.

Reuses the cloud's existing `vision_boxes` registry (box_uid, claim_code, per-box webhook_token,
status pending→claimed→revoked). This package adds the device keypair, the signed license, offline
verification + grace, activation and heartbeat. It touches no reconciliation/movement/shelf/ERP code.
"""
