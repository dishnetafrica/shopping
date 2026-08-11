"""P0-1 — the gateway must actually deliver what it detects.

The defect these tests exist for: docker-compose.yml declared STORE_ID and
EVENT_WEBHOOK_URL in an `environment:` block, install.sh wrote the installer's answers
to .env, and Compose gives `environment:` precedence over `env_file:` — including empty
values. Every box therefore reported store "family-shoppers-01" and POSTed nothing,
while the dashboard, the detector and the local event log all looked healthy.

Four layers, in order of how directly they pin the bug:

  1. the configuration          — the override can never be reintroduced
  2. the refusal                — a box that cannot deliver does not run
  3. the wire                   — a real POST, with the real headers, over real TCP
  4. the contract + end to end  — the payload satisfies CloudBSS's own parsed rules,
                                  and a fresh box gets a row into a vision_events replica
"""
from __future__ import annotations

import json
import os
import shutil
import subprocess

import pytest

from app import db, detector, events, scheduler
from tests.cloudbss_stub import (StubCloudBSS, cloudbss_src, validation_rules,
                                 vision_events_columns)

INSTALLER_KEYS = ("STORE_ID", "EVENT_WEBHOOK_URL", "VISION_TOKEN", "DEMO", "ALLOW_NO_WEBHOOK")
DOCKER = shutil.which("docker") is not None


# ══ 1. the configuration ══════════════════════════════════════════════════════════

def test_compose_declares_no_environment_overrides(gateway_root):
    """THE regression test. An `environment:` block silently beats `env_file:`."""
    compose = open(os.path.join(gateway_root, "docker-compose.yml"), encoding="utf-8").read()
    live = [ln for ln in compose.splitlines() if not ln.lstrip().startswith("#")]
    assert not any(ln.strip().startswith("environment:") for ln in live), (
        "docker-compose.yml declares an `environment:` block. Compose gives it precedence "
        "over env_file — including empty values — which is exactly how the installer's "
        "webhook URL was thrown away. Put configuration in .env only."
    )
    assert any("env_file" in ln for ln in live), "compose must load .env"


def test_compose_loads_dotenv_and_shipped_dotenv_has_every_key(gateway_root):
    dotenv = open(os.path.join(gateway_root, ".env"), encoding="utf-8").read()
    for key in INSTALLER_KEYS:
        assert f"\n{key}=" in "\n" + dotenv, f".env is missing {key}"


def test_shipped_dotenv_has_no_customer_baked_in(gateway_root):
    """A packaged default of a real customer's name is how boxes mis-file their events."""
    dotenv = open(os.path.join(gateway_root, ".env"), encoding="utf-8").read()
    for ln in dotenv.splitlines():
        if ln.startswith("STORE_ID=") or ln.startswith("EVENT_WEBHOOK_URL="):
            assert ln.split("=", 1)[1].strip() == "", f"shipped .env pre-fills {ln!r}"


@pytest.mark.skipif(not DOCKER, reason="docker CLI not available")
def test_docker_compose_config_preserves_installer_values(gateway_root, tmp_path):
    """Ask Docker itself. This is the assertion that would have caught the original bug."""
    shutil.copy(os.path.join(gateway_root, "docker-compose.yml"), tmp_path)
    (tmp_path / ".env").write_text(
        "STORE_ID=juba-main-01\n"
        "EVENT_WEBHOOK_URL=https://cloudbss.example/api/vision/events\n"
        "VISION_TOKEN=tok-abc123\nDEMO=0\nALLOW_NO_WEBHOOK=0\n")
    out = subprocess.run(["docker", "compose", "config"], cwd=tmp_path,
                         capture_output=True, text=True, timeout=60)
    assert out.returncode == 0, out.stderr
    assert "juba-main-01" in out.stdout, "the store id from .env did not survive"
    assert "https://cloudbss.example/api/vision/events" in out.stdout, (
        "the webhook URL from .env did not survive — the override is back")
    assert "family-shoppers-01" not in out.stdout, "a hardcoded store id is still in the compose file"


def test_installer_writes_every_key_and_verifies_delivery(gateway_root):
    sh = open(os.path.join(gateway_root, "install.sh"), encoding="utf-8").read()
    for key in INSTALLER_KEYS:
        assert f"{key}=" in sh, f"install.sh never writes {key}"
    assert "docker compose config" in sh, (
        "install.sh must verify the config actually reached the container before "
        "reporting success — reporting Done while delivering nothing is the whole bug")
    assert 'grep -qF -- "$EVENT_WEBHOOK_URL"' in sh
    # Pin the abort to the verification block itself. A bare `"exit 1" in sh` passes on
    # the unrelated root check at the top of the script, which let a mutant that turned
    # the abort into a warning survive — the exact "reported Done, delivered nothing"
    # failure this whole task exists to remove.
    assert 'if [ "$fail" = 1 ]; then' in sh, "there is no verification block to fail on"
    block = sh.split('if [ "$fail" = 1 ]; then', 1)[1].split("fi", 1)[0]
    assert "exit 1" in block, (
        "the verification warns but does not abort — an install that cannot deliver "
        "must not be able to report success")


def test_reinstalling_preserves_an_already_configured_box(gateway_root, tmp_path):
    """Re-running the installer must not silently wipe a working box's settings.

    rsync replaces .env with the packaged (empty) one, so the previous values have to be
    read BEFORE that happens. This runs the shipped reader against a real .env rather
    than trusting that it looks right.
    """
    sh = open(os.path.join(gateway_root, "install.sh"), encoding="utf-8").read()

    assert "prev ()" in sh, "install.sh does not read the box's existing configuration"
    # Assert the ASSIGNMENTS, not just that the names appear: `PREV_STORE=""` would
    # satisfy a looser check while quietly discarding the box's settings.
    for var, key in (("PREV_STORE", "STORE_ID"), ("PREV_HOOK", "EVENT_WEBHOOK_URL"),
                     ("PREV_TOKEN", "VISION_TOKEN")):
        assert f'{var}="$(prev {key})"' in sh, f"{var} is not actually read from the box"
        assert f":-${var}}}" in sh, f"{var} is read but never used as a default"
    assert sh.index('PREV_STORE="$(prev') < sh.index("rsync -a --delete"), (
        "the previous configuration is read AFTER rsync has already overwritten .env")

    # An install that cannot name its store must stop, not guess.
    assert 'if [ -z "$STORE_ID" ]; then' in sh, "nothing stops an install with no store id"
    guard = sh.split('if [ -z "$STORE_ID" ]; then', 1)[1].split("fi", 1)[0]
    assert "exit 1" in guard, "the missing-store-id guard does not abort"

    # execute the shipped reader against a configured box
    fn = sh[sh.index("prev ()"):].split("\n")[0]
    box = tmp_path / "opt"
    box.mkdir()
    (box / ".env").write_text(
        "STORE_ID=juba-main-01\n"
        "EVENT_WEBHOOK_URL=https://cloudbss.example/api/vision/events\n"
        "VISION_TOKEN=tok-live\nDEMO=0\n")
    script = tmp_path / "probe.sh"
    script.write_text(f'set -u\nDEST="{box}"\n{fn}\nprev STORE_ID\nprev EVENT_WEBHOOK_URL\nprev VISION_TOKEN\n')
    out = subprocess.run(["bash", str(script)], capture_output=True, text=True, timeout=30)
    assert out.returncode == 0, out.stderr
    assert out.stdout.split() == ["juba-main-01",
                                  "https://cloudbss.example/api/vision/events",
                                  "tok-live"], out.stdout

    # and on a box that has never been configured it must return nothing, not crash
    empty = tmp_path / "empty"
    empty.mkdir()
    script.write_text(f'set -u\nDEST="{empty}"\n{fn}\necho "[$(prev STORE_ID)]"\n')
    out = subprocess.run(["bash", str(script)], capture_output=True, text=True, timeout=30)
    assert out.returncode == 0 and out.stdout.strip() == "[]", out.stdout + out.stderr


def test_installer_bakes_in_no_customer_name(gateway_root):
    """Pressing Enter must never file a second store's shelves under the first one."""
    sh = open(os.path.join(gateway_root, "install.sh"), encoding="utf-8").read()
    live = "\n".join(ln for ln in sh.splitlines() if not ln.lstrip().startswith("#"))
    assert "family-shoppers-01" not in live, (
        "install.sh still defaults to a real customer's store id")


def test_installer_no_longer_patches_compose(gateway_root):
    """The old sed added env_file while leaving the override in place — it never worked."""
    sh = open(os.path.join(gateway_root, "install.sh"), encoding="utf-8").read()
    assert "sed -i 's/^    environment:" not in sh


# ══ 2. the refusal ════════════════════════════════════════════════════════════════

def test_refuses_to_start_with_no_configuration(clean_env):
    fatal, _ = events.config_problems()
    assert fatal, "an unconfigured box must not be allowed to run"
    assert any("EVENT_WEBHOOK_URL" in f for f in fatal)
    assert any("STORE_ID" in f for f in fatal)


def test_refuses_when_only_the_webhook_is_missing(clean_env):
    clean_env.setenv("STORE_ID", "juba-main-01")
    fatal, _ = events.config_problems()
    assert any("EVENT_WEBHOOK_URL" in f for f in fatal)


def test_refuses_when_only_the_store_is_missing(clean_env):
    clean_env.setenv("EVENT_WEBHOOK_URL", "https://c.example/api/vision/events")
    fatal, _ = events.config_problems()
    assert any("STORE_ID" in f for f in fatal), (
        "a box with no store id files its events under nobody — or worse, under the "
        "packaged default, which used to be a real customer")


def test_refuses_a_webhook_that_is_not_a_url(clean_env):
    clean_env.setenv("STORE_ID", "juba-main-01")
    clean_env.setenv("EVENT_WEBHOOK_URL", "cloudbss.example/api/vision/events")
    fatal, _ = events.config_problems()
    assert any("http" in f for f in fatal)


def test_a_properly_configured_box_starts(clean_env):
    clean_env.setenv("STORE_ID", "juba-main-01")
    clean_env.setenv("EVENT_WEBHOOK_URL", "https://c.example/api/vision/events")
    clean_env.setenv("VISION_TOKEN", "tok")
    fatal, warn = events.config_problems()
    assert fatal == [] and warn == []


def test_demo_and_offline_escape_hatches_are_allowed_but_loud(clean_env):
    clean_env.setenv("DEMO", "1")
    fatal, warn = events.config_problems()
    assert fatal == [] and warn, "DEMO must run, and must say so"

    clean_env.delenv("DEMO")
    clean_env.setenv("STORE_ID", "juba-main-01")
    clean_env.setenv("ALLOW_NO_WEBHOOK", "1")
    fatal, warn = events.config_problems()
    assert fatal == [], "a deliberate offline pilot must still run"
    assert any("NOBODY" in w for w in warn), "…and must say nobody is being told"


def test_missing_token_warns_but_does_not_block(clean_env):
    clean_env.setenv("STORE_ID", "juba-main-01")
    clean_env.setenv("EVENT_WEBHOOK_URL", "https://c.example/api/vision/events")
    fatal, warn = events.config_problems()
    assert fatal == []
    assert any("401" in w for w in warn), (
        "CloudBSS 401s an untokened event and the gateway retries forever — say so")


def test_app_startup_actually_refuses(clean_env):
    """Not just the helper: the real startup path must raise."""
    from app import main
    with pytest.raises(RuntimeError, match="refusing to start"):
        main.check_delivery_config()


# ══ 3. the wire ═══════════════════════════════════════════════════════════════════

def _one_zone_with_an_event(fill=0.12, to_state="empty", from_state="stocked"):
    """A camera, a zone and one undelivered event — the state after a real detection."""
    with db.tx() as con:
        cam = con.execute("INSERT INTO cameras (name, driver) VALUES ('Aisle 3','demo')").lastrowid
        zone = con.execute(
            "INSERT INTO zones (camera_id,name,polygon,state) VALUES (?,?,?,?)",
            (cam, "Sugar level 2", json.dumps([[0, 0], [10, 0], [10, 10]]), from_state)).lastrowid
    ev = db.insert_event(zone, from_state, to_state, fill, None)
    cam_row = db.db().execute("SELECT * FROM cameras WHERE id=?", (cam,)).fetchone()
    zone_row = db.db().execute("SELECT * FROM zones WHERE id=?", (zone,)).fetchone()
    return ev, zone_row, cam_row


def test_event_is_delivered_over_a_real_socket(clean_env, fresh_box):
    with StubCloudBSS(token="tok-abc") as cloud:
        clean_env.setenv("STORE_ID", "juba-main-01")
        clean_env.setenv("EVENT_WEBHOOK_URL", cloud.url)
        clean_env.setenv("VISION_TOKEN", "tok-abc")

        ev, zone_row, cam_row = _one_zone_with_an_event()
        payload = events.build_payload(zone_row, cam_row, "empty", "stocked", 0.12, b"\xff\xd8jpeg")
        events.deliver(ev, payload)

        assert len(cloud.requests) == 1, "nothing was POSTed"
        req = cloud.requests[0]
        assert req["token"] == "tok-abc", "the X-Vision-Token header did not travel"
        assert req["content_type"] == "application/json"
        assert req["body"]["store"] == "juba-main-01", "the store id did not travel"
        assert req["body"]["event"] == "shelf.out_of_stock"
        assert req["body"]["zone"] == "Sugar level 2"
        assert cloud.rejections == []

        row = db.db().execute("SELECT delivered FROM events WHERE id=?", (ev,)).fetchone()
        assert row["delivered"] == 1, "a delivered event was not marked delivered"


def test_no_webhook_means_the_event_is_kept_not_lost(clean_env, fresh_box):
    clean_env.setenv("STORE_ID", "juba-main-01")
    clean_env.setenv("ALLOW_NO_WEBHOOK", "1")
    ev, zone_row, cam_row = _one_zone_with_an_event()
    events.deliver(ev, events.build_payload(zone_row, cam_row, "empty", "stocked", 0.1, None))
    row = db.db().execute("SELECT delivered FROM events WHERE id=?", (ev,)).fetchone()
    assert row["delivered"] == 0, "an undeliverable event must stay queued, not be dropped"


def test_undelivered_events_are_retried_when_the_uplink_returns(clean_env, fresh_box):
    """Store-and-forward: the shop's DSL drops, then comes back."""
    clean_env.setenv("STORE_ID", "juba-main-01")
    clean_env.setenv("VISION_TOKEN", "tok-abc")
    clean_env.setenv("EVENT_WEBHOOK_URL", "http://127.0.0.1:9/dead")   # discard port

    ev, zone_row, cam_row = _one_zone_with_an_event()
    events.deliver(ev, events.build_payload(zone_row, cam_row, "empty", "stocked", 0.1, None))
    assert db.db().execute("SELECT delivered FROM events WHERE id=?",
                           (ev,)).fetchone()["delivered"] == 0

    with StubCloudBSS(token="tok-abc") as cloud:
        clean_env.setenv("EVENT_WEBHOOK_URL", cloud.url)
        events.retry_undelivered()
        assert len(cloud.requests) == 1, "the queued event was never retried"
        assert db.db().execute("SELECT delivered FROM events WHERE id=?",
                               (ev,)).fetchone()["delivered"] == 1


def test_a_401_does_not_mark_the_event_delivered(clean_env, fresh_box):
    """A wrong token must leave the event queued, not silently consume it."""
    with StubCloudBSS(token="the-right-one") as cloud:
        clean_env.setenv("STORE_ID", "juba-main-01")
        clean_env.setenv("EVENT_WEBHOOK_URL", cloud.url)
        clean_env.setenv("VISION_TOKEN", "the-wrong-one")
        ev, zone_row, cam_row = _one_zone_with_an_event()
        events.deliver(ev, events.build_payload(zone_row, cam_row, "empty", "stocked", 0.1, None))
        assert cloud.rejections and cloud.rejections[0][0] == 401
        assert db.db().execute("SELECT delivered FROM events WHERE id=?",
                               (ev,)).fetchone()["delivered"] == 0


# ══ 4. the CloudBSS contract, and end to end ══════════════════════════════════════

def test_selftest_delivers_without_any_camera(clean_env, fresh_box):
    """The check for a box that has no camera, no zone and no shelf — most boxes, on day one."""
    with StubCloudBSS(token="tok-abc") as cloud:
        clean_env.setenv("STORE_ID", "juba-main-01")
        clean_env.setenv("EVENT_WEBHOOK_URL", cloud.url)
        clean_env.setenv("VISION_TOKEN", "tok-abc")

        res = events.selftest()

        assert res["ok"] is True, res
        assert res["store"] == "juba-main-01"
        assert len(cloud.requests) == 1
        body = cloud.requests[0]["body"]
        assert body["event"] == "shelf.selftest", "the test event must be identifiable in the table"
        assert body["store"] == "juba-main-01"
        assert cloud.requests[0]["token"] == "tok-abc"
        assert db.db().execute("SELECT COUNT(*) c FROM events").fetchone()["c"] == 0, (
            "the self-test must not litter the local events table")


def test_selftest_reports_a_rejected_token_in_plain_words(clean_env, fresh_box):
    """A wrong token is invisible until something is refused. Say which failure it is."""
    with StubCloudBSS(token="the-right-one") as cloud:
        clean_env.setenv("STORE_ID", "juba-main-01")
        clean_env.setenv("EVENT_WEBHOOK_URL", cloud.url)
        clean_env.setenv("VISION_TOKEN", "the-wrong-one")

        res = events.selftest()

        assert res["ok"] is False
        assert "401" in res["detail"]
        assert "token" in res["detail"].lower(), (
            "a 401 must name the token as the thing to fix, not just echo a status code")


def test_selftest_says_so_when_there_is_no_webhook(clean_env, fresh_box):
    clean_env.setenv("STORE_ID", "juba-main-01")
    res = events.selftest()
    assert res["ok"] is False and "EVENT_WEBHOOK_URL" in res["detail"]


def test_selftest_uses_the_same_path_as_a_real_event(clean_env, fresh_box):
    """If the self-test and real delivery drift apart, the self-test stops being evidence."""
    import inspect
    src = inspect.getsource(events)
    assert src.count("requests.post(") == 1, (
        "there must be exactly one place an event crosses the wire; the self-test and "
        "deliver() must both go through post_payload()")
    assert "ok, detail = post_payload(payload)" in inspect.getsource(events.deliver)
    assert "ok, detail = post_payload(payload)" in inspect.getsource(events.selftest)


def test_installer_proves_the_uplink_before_finishing(gateway_root):
    sh = open(os.path.join(gateway_root, "install.sh"), encoding="utf-8").read()
    assert "selftest)" in sh, "visionctl has no selftest verb for a return visit"
    assert "Delivering one test event" in sh, "install.sh never proves CloudBSS accepts anything"
    tail = sh.split("Delivering one test event", 1)[1]
    # Pin the actual call, not merely the word: /api/selftest also appears in the
    # visionctl verb further down, so a looser check passes with the call removed.
    call = [ln for ln in tail.splitlines() if "/api/selftest" in ln]
    assert call, "the install does not actually call the self-test endpoint"
    assert "-X POST localhost:8080/api/selftest" in call[0], call[0]
    # P0-2: the endpoint is authenticated now, so the install must present credentials
    # or its own proof-of-delivery step would fail on a correctly secured box.
    assert '-u "$ADMIN_USER:$ADMIN_PASSWORD"' in call[0], (
        "the install calls the self-test without credentials — it would 401 on every box")
    assert "exit 1" in tail, (
        "an install whose test event is refused must fail — a box that cannot report "
        "is the exact thing this milestone exists to make impossible")


SRC = cloudbss_src()
needs_cloud = pytest.mark.skipif(SRC is None, reason="CloudBSS source not available (set CLOUDBSS_SRC)")


@needs_cloud
def test_gateway_payload_satisfies_cloudbss_validation(clean_env, fresh_box):
    """Field names and limits are parsed from the real controller, not retyped here."""
    rules = validation_rules(SRC)
    assert set(rules) == {"event", "store", "camera", "zone", "from_state",
                          "to_state", "fill", "timestamp"}, rules

    with StubCloudBSS(token="tok").with_contract(rules) as cloud:
        clean_env.setenv("STORE_ID", "juba-main-01")
        clean_env.setenv("EVENT_WEBHOOK_URL", cloud.url)
        clean_env.setenv("VISION_TOKEN", "tok")
        ev, zone_row, cam_row = _one_zone_with_an_event()
        payload = events.build_payload(zone_row, cam_row, "empty", "stocked", 0.12, b"\xff\xd8jpeg")
        events.deliver(ev, payload)
        assert cloud.rejections == [], f"CloudBSS would reject this payload: {cloud.rejections}"
        assert len(cloud.rows()) == 1


@needs_cloud
def test_every_field_the_gateway_sends_fits_its_column(clean_env, fresh_box):
    """A 130-character camera name is a 422 in the shop and a silent gap in the data."""
    rules, cols = validation_rules(SRC), vision_events_columns(SRC)
    for field, rule in rules.items():
        if rule["max"] and field in cols and cols[field]["len"]:
            assert rule["max"] <= cols[field]["len"], (
                f"{field}: validation allows {rule['max']} chars but the column holds "
                f"{cols[field]['len']} — a valid event would fail on insert")


@needs_cloud
def test_thumbnail_is_not_stored_in_the_row(clean_env, fresh_box):
    rules = validation_rules(SRC)
    with StubCloudBSS(token="tok").with_contract(rules) as cloud:
        clean_env.setenv("STORE_ID", "juba-main-01")
        clean_env.setenv("EVENT_WEBHOOK_URL", cloud.url)
        clean_env.setenv("VISION_TOKEN", "tok")
        ev, zone_row, cam_row = _one_zone_with_an_event()
        events.deliver(ev, events.build_payload(zone_row, cam_row, "empty", "stocked",
                                                0.12, b"\xff\xd8" + b"x" * 4000))
        stored = json.loads(cloud.rows()[0]["payload"])
        assert "thumbnail" not in stored, "the controller strips it; the row must not carry it"


@needs_cloud
def test_fresh_install_delivers_its_first_real_event(clean_env, fresh_box):
    """The whole point of P0-1, from an empty data directory.

    A box out of the factory: no database, no cameras. Add a camera and a zone, enrol
    the zone on a stocked shelf, then show it an empty one twice (the hysteresis needs
    two confirmations). A row must appear in the vision_events replica.
    """
    import numpy as np

    rules = validation_rules(SRC)
    with StubCloudBSS(token="tok-live").with_contract(rules) as cloud:
        clean_env.setenv("STORE_ID", "family-shoppers-01")
        clean_env.setenv("EVENT_WEBHOOK_URL", cloud.url)
        clean_env.setenv("VISION_TOKEN", "tok-live")
        clean_env.setenv("SNAP_DIR", str(fresh_box / "snapshots"))

        assert db.list_cameras() == [], "the box should start with nothing"

        # a stocked shelf and the same shelf emptied
        def shelf(n):
            img = np.full((240, 320, 3), 70, np.uint8)
            for i in range(n):
                x = i * 50 + 6
                img[60:220, x:x + 40] = (200, 76, 60)
                img[100:116, x + 6:x + 34] = (240, 240, 240)
            return img

        poly = [[4, 4], [316, 4], [316, 236], [4, 236]]
        with db.tx() as con:
            cam = con.execute(
                "INSERT INTO cameras (name, driver, interval_s) VALUES ('Aisle 3','demo',600)"
            ).lastrowid
            ref = detector.describe(shelf(6), poly)
            con.execute(
                "INSERT INTO zones (camera_id,name,polygon,ref_descriptor,state) "
                "VALUES (?,?,?,?,'stocked')",
                (cam, "Sugar level 2", json.dumps(poly), ref.to_json()))

        cam_row = db.db().execute("SELECT * FROM cameras WHERE id=?", (cam,)).fetchone()
        import cv2
        empty_jpeg = cv2.imencode(".jpg", shelf(0))[1].tobytes()

        scheduler.evaluate_zones(cam_row, None, empty_jpeg)      # 1st: pending
        assert cloud.requests == [], "one frame must not raise an alert"
        scheduler.evaluate_zones(cam_row, None, empty_jpeg)      # 2nd: confirmed

        assert len(cloud.requests) == 1, "a confirmed empty shelf was not delivered"
        rows = cloud.rows()
        assert len(rows) == 1
        assert rows[0]["event"] == "shelf.out_of_stock"
        assert rows[0]["store"] == "family-shoppers-01"
        assert rows[0]["zone"] == "Sugar level 2"
        assert rows[0]["to_state"] == "empty"
        assert cloud.rejections == []


@needs_cloud
def test_cloudbss_feature_test_is_shipped(gateway_root):
    """The one leg this suite cannot prove is Laravel itself — ship the test for it."""
    php = os.path.join(gateway_root, "cloudbss-test", "VisionIngestTest.php")
    assert os.path.isfile(php), (
        "the stub proves the contract, not Laravel. A php artisan test must ship "
        "alongside it so the real endpoint can be verified where composer is available.")
    body = open(php, encoding="utf-8").read()
    for needle, why in [("/api/vision/events", "it must exercise the real route"),
                        ("VisionEvent", "it must assert on the row that comes out"),
                        ("X-Vision-Token", "it must cover the auth header"),
                        ("assertStatus(401)", "it must prove a bad token is refused")]:
        assert needle in body, f"the shipped Laravel test does not mention {needle}: {why}"
