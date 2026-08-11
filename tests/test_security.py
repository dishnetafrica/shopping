"""P0-2 — the box must not hand the shop's cameras, or its recorder password, to the LAN.

Before this milestone all 15 endpoints were open on a host-networked box, and three of
them returned the NVR's admin password in clear text. The two tests that carry the most
weight here are deliberately not written per-endpoint and per-field:

  * test_every_route_refuses_without_credentials walks the LIVE route table, so a route
    added next year is covered by a test written today.
  * test_no_credential_escapes_by_any_route drives a camera with a distinctive password
    through failure and success, then greps every response body, the entire database
    file and the captured log stream for it.

Enumerating known leaks would have passed before this work started — the camera list
leaked the same password by a route nobody had thought to check.
"""
from __future__ import annotations

import base64
import json
import os
import sqlite3
import subprocess
import threading
from http.server import BaseHTTPRequestHandler, HTTPServer

import pytest
from fastapi.testclient import TestClient

from app import auth, db, redact

USER, PASS = "admin", "correct-horse-battery-staple"
NVR_PASSWORD = "Zx9-nvr-SECRET-pw"          # distinctive: greppable, appears nowhere else


class FakeRecorder:
    """An NVR that answers ISAPI, so probe() succeeds and builds a credentialed URI."""

    def __enter__(self):
        class H(BaseHTTPRequestHandler):
            def log_message(self, *a):
                pass

            def do_GET(self):
                body = b"<DeviceInfo><model>DS-7608</model></DeviceInfo>"
                self.send_response(200)
                self.send_header("Content-Type", "application/xml")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)

        self._s = HTTPServer(("127.0.0.1", 0), H)
        self._t = threading.Thread(target=self._s.serve_forever, daemon=True)
        self._t.start()
        self.host, self.port = self._s.server_address
        return self

    def __exit__(self, *e):
        self._s.shutdown()
        self._s.server_close()
        self._t.join(timeout=5)


def _register_leaky_driver():
    """A driver that fails the way real ffmpeg does — deterministically."""
    from app.cal.base import CameraDriver, CameraError, Capabilities, register

    class LeakyDriver(CameraDriver):
        name = "leaky"

        def probe(self, cam):
            return Capabilities(ok=False, detail="stub")

        def get_snapshot(self, cam):
            raise CameraError(
                "ffmpeg failed: [tcp @ 0x55948bcf8540] Connection to tcp://10.0.0.5:554 "
                "failed: Connection refused\nError opening input file "
                f"rtsp://admin:{NVR_PASSWORD}@10.0.0.5:554/Streaming/Channels/101.")

    register(LeakyDriver())


_register_leaky_driver()


@pytest.fixture
def secured(clean_env, fresh_box, monkeypatch):
    """A properly configured, authenticated gateway with delivery switched off."""
    monkeypatch.setenv("ADMIN_USER", USER)
    monkeypatch.setenv("ADMIN_PASSWORD", PASS)
    monkeypatch.setenv("STORE_ID", "juba-main-01")
    monkeypatch.setenv("ALLOW_NO_WEBHOOK", "1")
    monkeypatch.setenv("SNAP_DIR", str(fresh_box / "snapshots"))
    monkeypatch.delenv("ALLOW_NO_AUTH", raising=False)
    from app.main import app
    with TestClient(app) as c:
        yield c


def basic(user=USER, password=PASS) -> dict:
    raw = base64.b64encode(f"{user}:{password}".encode()).decode()
    return {"Authorization": f"Basic {raw}"}


# ══ authentication ════════════════════════════════════════════════════════════════

def test_every_route_refuses_without_credentials(secured):
    """Walk the real route table. No exceptions, no allow-list, nothing exempt."""
    from app.main import app

    checked = 0
    for route in app.routes:
        path, methods = getattr(route, "path", None), getattr(route, "methods", None)
        if not path or not methods:
            continue
        # substitute something concrete for path parameters
        concrete = path.replace("{cam_id}", "1").replace("{zone_id}", "1").replace("{snap_id}", "1")
        for method in sorted(methods - {"HEAD", "OPTIONS"}):
            r = secured.request(method, concrete)
            assert r.status_code in (401, 403), (
                f"{method} {concrete} answered {r.status_code} with NO credentials — "
                f"every route on this box must require authentication")
            checked += 1
    assert checked >= 15, f"only {checked} routes checked; the route table looks wrong"


def test_the_dashboard_itself_is_behind_the_password(secured):
    assert secured.get("/").status_code == 401
    assert secured.get("/", headers=basic()).status_code == 200


def test_a_401_tells_the_browser_to_ask(secured):
    r = secured.get("/")
    assert "www-authenticate" in {k.lower() for k in r.headers}, (
        "without WWW-Authenticate a browser shows a bare error instead of a login prompt")


def test_wrong_password_and_wrong_user_are_both_refused(secured):
    assert secured.get("/api/cameras", headers=basic(password="wrong")).status_code == 401
    assert secured.get("/api/cameras", headers=basic(user="root")).status_code == 401
    assert secured.get("/api/cameras", headers=basic()).status_code == 200


def test_the_api_map_is_not_published(secured):
    for path in ("/openapi.json", "/docs", "/redoc"):
        assert secured.get(path).status_code in (401, 403, 404), f"{path} is reachable"


def test_credentials_are_compared_in_constant_time():
    """Pin BOTH assignments. A count survives replacing one of them with ==, because the
    docstring above mentions compare_digest too — which is exactly how a mutant lived."""
    import inspect
    src = inspect.getsource(auth.require_auth)
    assert "user_ok = secrets.compare_digest(" in src, "the username is compared unsafely"
    assert "pw_ok = secrets.compare_digest(" in src, (
        "the password is compared with == — that leaks it one character at a time")


# ══ the refusal to run unprotected ════════════════════════════════════════════════

def test_refuses_to_start_with_no_password(clean_env, monkeypatch):
    monkeypatch.delenv("ADMIN_PASSWORD", raising=False)
    monkeypatch.delenv("ALLOW_NO_AUTH", raising=False)
    fatal, _ = auth.config_problems()
    assert fatal, "a box with no password must not run on a shop network"

    from app import main
    with pytest.raises(RuntimeError, match="unprotected"):
        main.check_security_config()


def test_demo_alone_does_not_disable_authentication(clean_env, monkeypatch):
    """DEMO=1 is how a demo box ends up sitting in a real shop. It must not open the door."""
    monkeypatch.setenv("DEMO", "1")
    monkeypatch.delenv("ADMIN_PASSWORD", raising=False)
    monkeypatch.delenv("ALLOW_NO_AUTH", raising=False)
    fatal, _ = auth.config_problems()
    assert fatal, "DEMO=1 disabled authentication"


def test_the_no_auth_hatch_works_and_is_loud(clean_env, monkeypatch):
    monkeypatch.setenv("ALLOW_NO_AUTH", "1")
    fatal, warn = auth.config_problems()
    assert fatal == []
    assert any("EVERY endpoint is open" in w for w in warn)


def test_a_short_password_is_called_out(clean_env, monkeypatch):
    monkeypatch.setenv("ADMIN_PASSWORD", "vision")
    monkeypatch.delenv("ALLOW_NO_AUTH", raising=False)
    fatal, warn = auth.config_problems()
    assert fatal == [] and any("characters" in w for w in warn)


def test_an_unconfigured_password_fails_closed_not_open():
    """If startup is ever bypassed, no password must mean no entry — not free entry."""
    import inspect
    src = inspect.getsource(auth.require_auth)
    assert "503" in src or "SERVICE_UNAVAILABLE" in src, (
        "with no password configured the guard must refuse, not wave callers through")


# ══ credential redaction ══════════════════════════════════════════════════════════

def test_scrub_masks_credentials_in_any_url():
    assert redact.scrub("rtsp://admin:hunter2@10.0.0.5:554/x") == "rtsp://***:***@10.0.0.5:554/x"
    assert redact.scrub("http://u:p@host/cgi-bin/snapshot.cgi") == "http://***:***@host/cgi-bin/snapshot.cgi"
    # passwords containing awkward characters must still be caught
    assert "p:a/ss" not in redact.scrub("rtsp://admin:p:a/ss@10.0.0.5/x")
    # nothing to mask, nothing changed
    assert redact.scrub("rtsp://10.0.0.5:554/Streaming") == "rtsp://10.0.0.5:554/Streaming"
    assert redact.scrub(None) is None and redact.scrub("") == ""


def test_scrub_secret_catches_a_password_outside_a_url():
    """ffmpeg and some firmwares print the password on its own."""
    msg = "authentication failed for user admin password Zx9-nvr-SECRET-pw"
    # Assert the EXACT result, not merely that the password is absent. The prefix
    # fallback (for truncated credentials) can mask all but the final character, which
    # satisfies a "not in" check while quietly leaving a fragment behind — a mutant that
    # deleted the exact-match line survived on precisely that.
    assert redact.scrub_secret(msg, NVR_PASSWORD) == (
        "authentication failed for user admin password ***")
    # a trivially short 'secret' must not blank out unrelated text
    assert redact.scrub_secret("a camera at aisle 3", "a") == "a camera at aisle 3"


def _add_camera_with_credentials(client, driver="leaky", host="10.0.0.5", port=554):
    r = client.post("/api/cameras", headers=basic(), json={
        "name": "Aisle 3", "driver": driver, "host": host, "port": port,
        "username": "admin", "password": NVR_PASSWORD, "channel": 1,
        "rtsp_url": f"rtsp://admin:{NVR_PASSWORD}@{host}:554/Streaming/Channels/101",
        "interval_s": 600, "enabled": True})
    assert r.status_code == 200, r.text
    return r.json()["id"]


def test_the_camera_list_does_not_carry_the_password(secured):
    _add_camera_with_credentials(secured)
    body = secured.get("/api/cameras", headers=basic()).text
    assert NVR_PASSWORD not in body, "GET /api/cameras leaked the recorder password"
    assert "password" not in json.loads(body)[0]
    assert "username" not in json.loads(body)[0], "half a credential pair is still half a credential"


def test_the_probe_response_does_not_carry_the_password(secured):
    """This endpoint used to return rtsp://admin:PASSWORD@host verbatim.

    The probe must SUCCEED for this to prove anything: an unreachable device returns
    Capabilities(ok=False) with an empty rtsp_uri, so pointing at a dead host passes
    whether or not the leak is fixed. So a fake recorder answers here.
    """
    with FakeRecorder() as nvr:
        cam = _add_camera_with_credentials(secured, driver="hikvision",
                                           host=nvr.host, port=nvr.port)
        r = secured.post(f"/api/cameras/{cam}/test", headers=basic())
        body = r.json()

    assert body["ok"] is True, f"the fake recorder did not answer: {body}"
    assert body["rtsp_uri"], "probe returned no URI, so this test proves nothing"
    assert NVR_PASSWORD not in r.text, "POST /api/cameras/{id}/test leaked the recorder password"
    assert "***" in body["rtsp_uri"], f"the URI was not masked: {body['rtsp_uri']}"
    assert "10.0.0" in body["rtsp_uri"] or nvr.host in body["rtsp_uri"], (
        "the host was masked away too — the installer still needs to read this")


def test_a_capture_failure_does_not_carry_the_password(secured, caplog):
    """ffmpeg echoes the stream URL, credentials and all, when it cannot connect.

    Driven through a stub driver rather than real ffmpeg. Real ffmpeg's message is
    truncated to 200 characters by rtsp.py, and whether the credential survives that cut
    depends on the length of a pointer address printed two lines earlier — so a test
    against real ffmpeg passes or fails at random. The exact string below is what real
    ffmpeg emitted when this was checked.
    """
    import logging
    caplog.set_level(logging.DEBUG)

    cam = _add_camera_with_credentials(secured, driver="leaky", host="10.0.0.5")
    r = secured.post(f"/api/cameras/{cam}/capture", headers=basic())
    assert r.status_code == 502, f"expected this camera to fail, got {r.status_code}"
    assert NVR_PASSWORD not in r.text, "the capture error leaked the recorder password"

    listed = secured.get("/api/cameras", headers=basic()).text
    assert NVR_PASSWORD not in listed, "the stored last_error leaked it on the next list"
    assert NVR_PASSWORD not in caplog.text, "the capture error logged the recorder password"

    stored = db.db().execute("SELECT last_error FROM cameras WHERE id=?", (cam,)).fetchone()
    assert NVR_PASSWORD not in (stored["last_error"] or ""), "the password was stored in last_error"
    assert "***" in (stored["last_error"] or ""), "nothing was masked — is the error what we think?"


def test_a_password_cut_in_half_by_truncation_is_still_masked():
    """rtsp.py caps ffmpeg's stderr at 200 chars, which can slice a password in two."""
    half = NVR_PASSWORD[:11]
    msg = f"ffmpeg failed: Error opening input file rtsp://admin:{half}"
    out = redact.scrub_secret(msg, NVR_PASSWORD)
    assert half not in out, f"a truncated password survived: {out}"


def test_no_credential_escapes_by_any_route(secured, fresh_box, caplog):
    """The wide net: drive a camera to failure, then grep everything the box can emit.

    Enumerating the leaks we know about is what let the camera-list leak survive the
    audit. This asserts the negative across every response body, the database file on
    disk, and the log stream.
    """
    import logging
    caplog.set_level(logging.DEBUG)

    cam = _add_camera_with_credentials(secured, host="127.0.0.1")
    secured.post(f"/api/cameras/{cam}/capture", headers=basic())     # provoke a real error
    secured.post(f"/api/cameras/{cam}/test", headers=basic())

    bodies = []
    for path in ("/api/cameras", "/api/zones", "/api/events", "/api/health", "/api/drivers"):
        bodies.append(secured.get(path, headers=basic()).text)
    bodies.append(secured.post(f"/api/cameras/{cam}/capture", headers=basic()).text)
    bodies.append(secured.post(f"/api/cameras/{cam}/test", headers=basic()).text)

    for i, body in enumerate(bodies):
        assert NVR_PASSWORD not in body, f"response {i} leaked the recorder password: {body[:300]}"

    assert NVR_PASSWORD not in caplog.text, (
        "the recorder password reached the log — `visionctl logs` prints this")

    # The database is allowed to hold it (the drivers need it) but only in the password
    # column: not smeared into last_error or anywhere else a response reads from.
    con = sqlite3.connect(db.DB_PATH)
    row = con.execute("SELECT password, rtsp_url, last_error, name FROM cameras WHERE id=?",
                      (cam,)).fetchone()
    con.close()
    assert row[0] == NVR_PASSWORD, "the driver still needs the real password"
    assert NVR_PASSWORD not in (row[2] or ""), "last_error stored the password"


# ══ the box on the shop floor ═════════════════════════════════════════════════════

def test_installer_generates_locks_down_and_reveals_the_password(gateway_root, tmp_path):
    sh = open(os.path.join(gateway_root, "install.sh"), encoding="utf-8").read()
    assert "ADMIN_PASSWORD=$ADMIN_PASSWORD" in sh, "install.sh never writes a password"
    assert 'chmod 600 "$DEST/.env"' in sh, ".env holds the token and the password"
    assert "Login:      $ADMIN_USER" in sh, "the installer is never told the password"

    # A re-install must not lock the owner out. Pin the ASSIGNMENT: `PREV_ADMIN=""`
    # satisfies a name check while discarding the box's password.
    assert 'PREV_ADMIN="$(prev ADMIN_PASSWORD)"' in sh, "the existing password is not read"
    assert 'ADMIN_PASSWORD="${ADMIN_PASSWORD:-$PREV_ADMIN}"' in sh, (
        "the existing password is read but not reused — a return visit locks the owner out")

    # RUN the generation block rather than trusting that it mentions urandom.
    block = sh.split('if [ -z "$ADMIN_PASSWORD" ]; then', 1)[1].split("\nfi\n", 1)[0]
    script = tmp_path / "gen.sh"
    script.write_text('set -u\nADMIN_PASSWORD=""\nNEW_ADMIN_PW=0\n'
                      + 'if [ -z "$ADMIN_PASSWORD" ]; then' + block + "\nfi\n"
                      + 'echo "$ADMIN_PASSWORD"\necho "$NEW_ADMIN_PW"\n')
    out = subprocess.run(["bash", str(script)], capture_output=True, text=True, timeout=30)
    assert out.returncode == 0, out.stderr
    generated, flagged = out.stdout.split()
    assert len(generated) >= 16, f"the generated password is only {len(generated)} chars: {generated!r}"
    assert flagged == "1", "the installer does not know it generated a new password"

    # …and twice in a row must not produce the same one.
    out2 = subprocess.run(["bash", str(script)], capture_output=True, text=True, timeout=30)
    assert out2.stdout.split()[0] != generated, "the generated password is not random"


def test_visionctl_authenticates_rather_than_leaving_a_hole(gateway_root):
    sh = open(os.path.join(gateway_root, "install.sh"), encoding="utf-8").read()
    ctl = sh.split("cat > /usr/local/bin/visionctl", 1)[1]
    assert 'ADMIN_PASSWORD=//p' in ctl, "visionctl does not read the credentials"
    for verb in ("status)", "selftest)"):
        line = [ln for ln in ctl.splitlines() if ln.strip().startswith(verb)][0]
        assert '-u "$AU:$AP"' in line, f"visionctl {verb} calls the API unauthenticated"


def test_shipped_dotenv_ships_no_password_and_no_open_door(gateway_root):
    dotenv = open(os.path.join(gateway_root, ".env"), encoding="utf-8").read()
    assert "\nADMIN_PASSWORD=\n" in dotenv, "a shipped password would be the same on every box"
    assert "\nALLOW_NO_AUTH=0\n" in dotenv, "the packaged default must be authenticated"


def test_compose_still_declares_no_environment_overrides(gateway_root):
    """P0-1's guarantee, re-asserted now that .env carries a password too."""
    compose = open(os.path.join(gateway_root, "docker-compose.yml"), encoding="utf-8").read()
    live = [ln for ln in compose.splitlines() if not ln.lstrip().startswith("#")]
    assert not any(ln.strip().startswith("environment:") for ln in live)
