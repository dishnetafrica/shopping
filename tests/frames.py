"""Synthetic camera views, shared by the quality tests and the calibration run.

The audit's probe images were a single shelf filling the frame. That is not what a
camera sees, and calibrating against it produced a gate that refused genuinely empty
shelves — a whole-frame flatness check is meaningless when the whole frame IS one shelf.

A real aisle camera sees a gondola with several rows, the shelf boards, price rails, the
floor and some background. When one row empties, the frame keeps most of its structure.
That difference is the whole reason frame-level checks are safe to apply at all, so the
fixtures have to reflect it.
"""
from __future__ import annotations

import cv2
import numpy as np

W, H = 640, 480
ROWS = 3
FACINGS = 6
PRODUCT_COLORS = [(200, 76, 60), (230, 140, 40), (80, 170, 60),
                  (40, 120, 180), (160, 60, 150), (220, 90, 30)]

# The middle row is the one under test; ZONE covers its shelf face.
ROW_H = (H - 90) // ROWS
ZONE = [[20, 90 + ROW_H], [W - 20, 90 + ROW_H], [W - 20, 90 + 2 * ROW_H - 18], [20, 90 + 2 * ROW_H - 18]]


def aisle(facings=(FACINGS, FACINGS, FACINGS), seed=1, bright=1.0, blur=0,
          shift=0, occluder=None) -> np.ndarray:
    """A gondola seen by a fixed camera.

    facings  per-row product count (0 = that row is empty)
    bright   multiplies every pixel (store lights, camera auto-gain)
    blur     gaussian radius (dirty lens, defocus)
    shift    horizontal pixels the WHOLE VIEW moves (the camera was nudged)
    occluder (x0, x1, bgr) — a shopper standing in front
    """
    rng = np.random.default_rng(seed)
    img = np.full((H, W, 3), 52, np.uint8)                       # background wall
    cv2.rectangle(img, (0, H - 70), (W, H), (96, 98, 104), -1)   # floor
    for x in range(0, W, 64):                                     # floor tile seams
        cv2.line(img, (x, H - 70), (x - 30, H), (78, 80, 86), 2)
    cv2.rectangle(img, (0, 0), (W, 26), (150, 152, 158), -1)      # ceiling light strip

    for r in range(ROWS):
        y0 = 90 + r * ROW_H
        y1 = y0 + ROW_H - 18
        cv2.rectangle(img, (20, y0), (W - 20, y1), (74, 78, 82), -1)        # back panel
        cv2.rectangle(img, (20, y1), (W - 20, y1 + 14), (126, 132, 138), -1)  # shelf board
        cv2.rectangle(img, (20, y1 + 3), (W - 20, y1 + 9), (198, 200, 204), -1)  # price rail
        for t in range(0, W - 60, 90):                                       # rail ticket marks
            cv2.rectangle(img, (34 + t, y1 + 3), (74 + t, y1 + 9), (240, 240, 240), -1)

        n = facings[r] if r < len(facings) else 0
        fw = (W - 60) // FACINGS
        for i in range(n):
            x0 = 30 + i * fw
            if x0 < 20 or x0 + fw - 16 > W - 20:
                continue
            ph = int((y1 - y0) * 0.74)
            cv2.rectangle(img, (x0, y1 - ph), (x0 + fw - 16, y1 - 2),
                          PRODUCT_COLORS[(i + r) % 6], -1)
            cv2.rectangle(img, (x0 + 8, y1 - ph + ph // 3),
                          (x0 + fw - 24, y1 - ph + ph // 3 + 14), (238, 238, 238), -1)

    if occluder:
        x0, x1, bgr = occluder
        cv2.rectangle(img, (x0, 40), (x1, H - 10), bgr, -1)

    if shift:
        # A camera that moves takes the whole scene with it — boards, rails, floor and
        # ceiling — which is precisely what phase correlation locks on to. Offsetting
        # only the products simulated stock sliding along a shelf, not a nudged camera,
        # and made the movement tests pass for the wrong reason.
        M = np.float32([[1, 0, shift], [0, 1, 0]])
        img = cv2.warpAffine(img, M, (W, H), borderMode=cv2.BORDER_REPLICATE)

    img = cv2.add(img, rng.integers(0, 7, img.shape, dtype=np.uint8))
    if blur:
        img = cv2.GaussianBlur(img, (blur * 2 + 1, blur * 2 + 1), 0)
    if bright != 1.0:
        img = np.clip(img.astype(np.float32) * bright, 0, 255).astype(np.uint8)
    return img


FULL = (FACINGS, FACINGS, FACINGS)
MIDDLE_EMPTY = (FACINGS, 0, FACINGS)
MIDDLE_HALF = (FACINGS, FACINGS // 2, FACINGS)
ALL_EMPTY = (0, 0, 0)


def as_jpeg(img: np.ndarray, quality: int = 88) -> np.ndarray:
    """Round-trip through JPEG, because the real pipeline always does.

    Every driver returns encoded bytes and the scheduler decodes them, so compression
    artefacts are present in every frame the detector and the quality gate ever see.
    Testing against raw arrays measures a cleaner world than the product lives in — the
    obstruction threshold was set from raw frames and then fired on an ordinary full
    shelf the first time it met a real JPEG.
    """
    ok, buf = cv2.imencode(".jpg", img, [cv2.IMWRITE_JPEG_QUALITY, quality])
    return cv2.imdecode(buf, cv2.IMREAD_COLOR)
