"""Benchmark metric groups, each reported SEPARATELY: detection · tracking · movement · system.
Pure-Python (stdlib + optional numpy); the movement/tracking math needs no model or camera, so it is
fully self-testable in the sandbox against scripted known-answer data (see bench/selftest.py)."""
