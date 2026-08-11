"""A stand-in for the CloudBSS ingestion endpoint, built from CloudBSS's own source.

The rules this stub enforces are PARSED OUT OF the real Laravel controller and the real
migration — they are not retyped here. If either side of the contract moves, the parse
changes with it and the tests that depend on it fail. That is the point: the gateway and
CloudBSS are two repositories that must agree about eight field names and their limits,
and nothing else in the project checks that they still do.

What this does NOT prove: Laravel's own behaviour (middleware, tenancy, Eloquent casts).
Closing that needs `php artisan test` against the real endpoint on a machine with
composer installed — see tests/test_delivery.py::test_cloudbss_feature_test_is_shipped.
"""
from __future__ import annotations

import json
import os
import re
import sqlite3
import threading
from http.server import BaseHTTPRequestHandler, HTTPServer

CONTROLLER = "app/Http/Controllers/Api/VisionEventController.php"
MIGRATION = "database/migrations/2026_07_30_000001_create_inventory_trust_tables.php"


def cloudbss_src() -> str | None:
    """Where the CloudBSS tree is, if it is available to this test run."""
    for c in (os.environ.get("CLOUDBSS_SRC"),
              "../cloud/shopping-main", "../../cloud/shopping-main",
              "/home/claude/work/audit/cloud/shopping-main"):
        if c and os.path.isfile(os.path.join(c, CONTROLLER)):
            return c
    return None


# ── parse the contract out of CloudBSS ────────────────────────────────────────────

_RULE = re.compile(r"'(\w+)'\s*=>\s*'([^']+)'")


def validation_rules(src: str) -> dict[str, dict]:
    """{'event': {'required': True, 'max': 60, 'numeric': False}, ...} from the controller."""
    php = open(os.path.join(src, CONTROLLER), encoding="utf-8").read()
    m = re.search(r"\$request->validate\(\[(.*?)\]\);", php, re.S)
    if not m:
        raise AssertionError("could not find the validate([...]) block in " + CONTROLLER)
    out = {}
    for field, rule in _RULE.findall(m.group(1)):
        parts = rule.split("|")
        mx = next((p.split(":")[1] for p in parts if p.startswith("max:")), None)
        out[field] = {
            "required": "required" in parts,
            "numeric": "numeric" in parts,
            "max": int(mx) if mx else None,
        }
    return out


def vision_events_columns(src: str) -> dict[str, dict]:
    """{'event': {'type':'string','len':60,'nullable':False}, ...} from the migration."""
    php = open(os.path.join(src, MIGRATION), encoding="utf-8").read()
    m = re.search(r"Schema::create\('vision_events'.*?\n\s*\}\);", php, re.S)
    if not m:
        raise AssertionError("could not find the vision_events Schema::create block")
    block = m.group(0)
    cols: dict[str, dict] = {}
    for line in block.splitlines():
        sm = re.search(r"\$t->(string|decimal|json|timestamp)\('(\w+)'(?:,\s*(\d+))?", line)
        if not sm:
            continue
        kind, name, ln = sm.group(1), sm.group(2), sm.group(3)
        cols[name] = {"type": kind, "len": int(ln) if ln else None,
                      "nullable": "->nullable()" in line}
    return cols


# ── the stub server ───────────────────────────────────────────────────────────────

class StubCloudBSS:
    """Accepts POSTs the way CloudBSS does and stores them in a vision_events replica."""

    def __init__(self, token: str = "test-token", db_path: str = ":memory:"):
        self.token = token
        self.requests: list[dict] = []          # every request, valid or not
        self.rejections: list[tuple[int, str]] = []
        self.rules = None                       # set by with_contract()
        self._con = sqlite3.connect(db_path, check_same_thread=False)
        self._con.row_factory = sqlite3.Row
        self._con.execute(
            "CREATE TABLE vision_events (id INTEGER PRIMARY KEY AUTOINCREMENT,"
            " tenant_id INTEGER, event TEXT, store TEXT, camera TEXT, zone TEXT,"
            " from_state TEXT, to_state TEXT, fill REAL, payload TEXT, occurred_at TEXT)")
        self._con.commit()
        self._server = None
        self._thread = None

    def with_contract(self, rules: dict) -> "StubCloudBSS":
        self.rules = rules
        return self

    # -- lifecycle ----------------------------------------------------------------
    def __enter__(self) -> "StubCloudBSS":
        stub = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *a):        # keep pytest output clean
                pass

            def do_POST(self):
                n = int(self.headers.get("Content-Length", 0))
                raw = self.rfile.read(n)
                try:
                    body = json.loads(raw)
                except ValueError:
                    return stub._reply(self, 400, {"error": "bad json"}, None, raw)
                stub.requests.append({
                    "path": self.path,
                    "token": self.headers.get("X-Vision-Token"),
                    "content_type": self.headers.get("Content-Type"),
                    "body": body,
                    "raw_len": n,
                })
                # auth, exactly as the controller does it: no token -> 401
                if not self.headers.get("X-Vision-Token"):
                    return stub._reply(self, 401, {"error": "unauthorized"}, "no token", raw)
                if self.headers.get("X-Vision-Token") != stub.token:
                    return stub._reply(self, 401, {"error": "unauthorized"}, "bad token", raw)
                err = stub._validate(body)
                if err:
                    return stub._reply(self, 422, {"error": err}, err, raw)
                rid = stub._insert(body)
                stub._reply(self, 200, {"ok": True, "id": rid}, None, raw)

        self._server = HTTPServer(("127.0.0.1", 0), Handler)
        self._thread = threading.Thread(target=self._server.serve_forever, daemon=True)
        self._thread.start()
        return self

    def __exit__(self, *exc):
        self._server.shutdown()
        self._server.server_close()
        self._thread.join(timeout=5)

    @property
    def url(self) -> str:
        host, port = self._server.server_address
        return f"http://{host}:{port}/api/vision/events"

    # -- behaviour ----------------------------------------------------------------
    def _reply(self, handler, code, payload, err, raw):
        if err:
            self.rejections.append((code, err))
        data = json.dumps(payload).encode()
        handler.send_response(code)
        handler.send_header("Content-Type", "application/json")
        handler.send_header("Content-Length", str(len(data)))
        handler.end_headers()
        handler.wfile.write(data)

    def _validate(self, body: dict) -> str | None:
        if not self.rules:
            return None
        for field, r in self.rules.items():
            v = body.get(field)
            if r["required"] and (v is None or v == ""):
                return f"{field} is required"
            if v is None:
                continue
            if r["numeric"]:
                try:
                    float(v)
                except (TypeError, ValueError):
                    return f"{field} must be numeric"
                continue
            if not isinstance(v, str):
                return f"{field} must be a string, got {type(v).__name__}"
            if r["max"] is not None and len(v) > r["max"]:
                return f"{field} exceeds max:{r['max']} (was {len(v)})"
        return None

    def _insert(self, body: dict) -> int:
        payload = {k: v for k, v in body.items() if k != "thumbnail"}   # as the controller does
        cur = self._con.execute(
            "INSERT INTO vision_events (tenant_id,event,store,camera,zone,from_state,"
            "to_state,fill,payload,occurred_at) VALUES (?,?,?,?,?,?,?,?,?,?)",
            (17, body.get("event"), body.get("store"), body.get("camera"), body.get("zone"),
             body.get("from_state"), body.get("to_state"),
             float(body["fill"]) if body.get("fill") is not None else None,
             json.dumps(payload), body.get("timestamp")))
        self._con.commit()
        return cur.lastrowid

    def rows(self) -> list[sqlite3.Row]:
        return self._con.execute("SELECT * FROM vision_events ORDER BY id").fetchall()
