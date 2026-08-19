"""
Single seam where bench/ reaches into the production movement package.

Putting the repo root on sys.path here (once) lets every bench module do `from app.movement...`
without repeating the bootstrap, and keeps the dependency direction explicit and one-way:
bench → app.movement, never the reverse. app.movement.contract is pure stdlib (hashlib, time), so
importing it costs nothing and pulls in no cv2 / numpy / supervision.
"""
from __future__ import annotations

import os
import sys

_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _ROOT not in sys.path:
    sys.path.insert(0, _ROOT)

from app.movement import contract  # noqa: E402

CARTON_MOVE = contract.CARTON_MOVE
TRACKING_GAP = contract.TRACKING_GAP
validate_carton_move = contract.validate_carton_move
