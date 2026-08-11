import os
import sys

import pytest

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, ROOT)

DELIVERY_KEYS = ("STORE_ID", "EVENT_WEBHOOK_URL", "VISION_TOKEN", "DEMO", "ALLOW_NO_WEBHOOK")


@pytest.fixture
def gateway_root() -> str:
    return ROOT


@pytest.fixture
def clean_env(monkeypatch):
    """No delivery configuration at all — a box nobody has configured."""
    for k in DELIVERY_KEYS:
        monkeypatch.delenv(k, raising=False)
    return monkeypatch


@pytest.fixture
def fresh_box(tmp_path, monkeypatch):
    """An empty data directory: the state a box is in when it comes out of the box."""
    from app import db

    monkeypatch.setattr(db, "DB_PATH", str(tmp_path / "vision.db"))
    monkeypatch.setattr(db, "_con", None)
    yield tmp_path
    con = getattr(db, "_con", None)
    if con is not None:
        con.close()
    monkeypatch.setattr(db, "_con", None)
