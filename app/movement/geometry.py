"""Tiny pure-Python geometry helpers for the movement pipeline. Boxes are (x0,y0,x1,y1)."""
from __future__ import annotations

import math

Box = tuple


def anchor(box: Box) -> tuple[float, float]:
    """Bottom-centre — where a person/carton meets the ground; the stable point for line crossing."""
    x0, y0, x1, y1 = box
    return ((x0 + x1) / 2.0, float(y1))


def centre(box: Box) -> tuple[float, float]:
    x0, y0, x1, y1 = box
    return ((x0 + x1) / 2.0, (y0 + y1) / 2.0)


def side_of_line(pt: tuple[float, float], line: tuple) -> float:
    """Signed side of `pt` relative to the directed segment line=((ax,ay),(bx,by)).
    >0 one side, <0 the other, 0 on the line (2-D cross product)."""
    (ax, ay), (bx, by) = line
    px, py = pt
    return (bx - ax) * (py - ay) - (by - ay) * (px - ax)


def distance(a: Box, b: Box) -> float:
    """Centre-to-centre distance — good enough for carton↔person proximity association."""
    ax, ay = centre(a)
    bx, by = centre(b)
    return math.hypot(ax - bx, ay - by)
