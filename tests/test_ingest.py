"""P0-9 — ingest hardening: the retry queue, and bounding what the box keeps.

The audit listed "throttling and retention" as absent. Reading the code found three
concrete bugs behind that heading, and the first is the kind that takes a box down:

  1. **retry_undelivered() runs in the CAPTURE thread with no time budget.** limit=20
     at a 10-second timeout is up to 200 SECONDS of blocking on a 15-second loop. Every
     camera starved — and precisely when the uplink is down and events are piling up.
     The same failure P0-3 fixed for dead cameras, wearing a different hat.

  2. **Head-of-line blocking.** The queue was `ORDER BY ts LIMIT 20`, so one
     permanently-undeliverable event — a payload CloudBSS answers 400 to — was retried
     every loop for ever and blocked every newer event behind it. The shop silently
     stops getting alerts and nothing says why.

  3. **The events table was never pruned.** snapshots have been since v1. Since P0-5
     each event row also carries the payload as sent, so they are not small.
"""
from __future__ import annotations

import base64
import json
import time

import pytest

from app import db, events
from tests import frames as F


# ── 1. the time budget ────────────────────────────────────────────────────────────

def _queue(n, delivered=0, ts_base=None, quarantined=0):
    ts_base = ts_base or (time.time() - 3600)
    with db.tx() as con:
        cam = con.execute(
            "INSERT INTO cameras (name, driver) VALUES ('Aisle 3','demo')").lastrowid
        zid = con.execute("INSERT INTO zones (camera_id,name,polygon) VALUES (?,?,?)",
                          (cam, "Sugar", json.dumps(F.ZONE))).lastrowid
        ids = []
        for i in range(n):
            ids.append(con.execute(
                "INSERT INTO events (zone_id, ts, from_state, to_state, fill, "
                "delivered, quarantined) VALUES (?,?, 'stocked','empty', 0.1, ?, ?)",
                (zid, ts_base + i, delivered, quarantined)).lastrowid)
    return ids


def test_a_dead_uplink_costs_one_attempt_not_twenty(fresh_box, monkeypatch):
    """The bug: 20 x 10s = 200 seconds of blocked capture on a 15-second loop."""
    monkeypatch.setenv("EVENT_WEBHOOK_URL", "http://example.invalid/hook")
    _queue(20)
    calls = []
    monkeypatch.setattr(events, "post_payload",
                        lambda p: (calls.append(p), (False, "connection refused"))[1])
    out = events.retry_undelivered()
    assert len(calls) == 1, f"kept hammering a dead uplink {len(calls)} times"
    assert out["failed"] == 1
    assert "uplink" in out["stopped"]


def test_a_slow_uplink_stops_at_the_time_budget(fresh_box, monkeypatch):
    monkeypatch.setenv("EVENT_WEBHOOK_URL", "http://example.invalid/hook")
    _queue(20)
    calls = []

    def slow(p):
        calls.append(p)
        time.sleep(0.05)
        return (True, "ok")

    monkeypatch.setattr(events, "post_payload", slow)
    out = events.retry_undelivered(budget_s=0.12)
    assert out["stopped"] == "time budget spent"
    assert 1 <= len(calls) < 20


def test_a_working_uplink_drains_the_batch(fresh_box, monkeypatch):
    monkeypatch.setenv("EVENT_WEBHOOK_URL", "http://example.invalid/hook")
    _queue(5)
    monkeypatch.setattr(events, "post_payload", lambda p: (True, "ok"))
    out = events.retry_undelivered()
    assert out["delivered"] == 5
    assert db.db().execute(
        "SELECT COUNT(*) c FROM events WHERE delivered=0").fetchone()["c"] == 0


def test_no_webhook_does_no_work_at_all(fresh_box, monkeypatch):
    monkeypatch.delenv("EVENT_WEBHOOK_URL", raising=False)
    _queue(5)
    out = events.retry_undelivered()
    assert out["attempted"] == 0 and "no webhook" in out["stopped"]


# ── 2. head-of-line blocking ──────────────────────────────────────────────────────

def test_a_failing_event_moves_behind_fresher_ones(fresh_box, monkeypatch):
    """The core of the bug. With `ORDER BY ts` a poison event is always first, always
    fails, and every newer alert waits behind it for ever."""
    monkeypatch.setenv("EVENT_WEBHOOK_URL", "http://example.invalid/hook")
    ids = _queue(3)
    poison = ids[0]
    with db.tx() as con:
        con.execute("UPDATE events SET attempts=4, next_retry_ts=? WHERE id=?",
                    (time.time() + 600, poison))
    seen = []
    monkeypatch.setattr(events, "post_payload", lambda p: (True, "ok"))
    monkeypatch.setattr(events, "deliver",
                        lambda eid, p: (seen.append(eid), True)[1])
    events.retry_undelivered()
    assert seen and seen[0] != poison, "the poison event was still at the head"
    assert poison in seen, "…but it must still get its turn, at the back"


def test_backoff_governs_order_not_eligibility(fresh_box, monkeypatch):
    """Filtering on next_retry_ts looked right and was wrong: after a long outage every
    queued event carries a long delay, so the queue would sit idle for up to an hour
    AFTER the link came back. This is the store-and-forward promise."""
    monkeypatch.setenv("EVENT_WEBHOOK_URL", "http://example.invalid/hook")
    _queue(3)
    with db.tx() as con:
        con.execute("UPDATE events SET attempts=9, next_retry_ts=?",
                    (time.time() + 3600,))
    monkeypatch.setattr(events, "post_payload", lambda p: (True, "ok"))
    out = events.retry_undelivered()
    assert out["delivered"] == 3, "a recovered uplink had to wait out the backoff"


def test_each_failure_backs_the_event_off_further(fresh_box, monkeypatch):
    monkeypatch.setenv("EVENT_WEBHOOK_URL", "http://example.invalid/hook")
    ev = _queue(1)[0]
    monkeypatch.setattr(events, "post_payload", lambda p: (False, "boom"))
    prev = 0.0
    for i in range(1, 4):
        events.retry_undelivered()
        row = db.db().execute("SELECT attempts, next_retry_ts FROM events WHERE id=?",
                              (ev,)).fetchone()
        assert row["attempts"] == i
        assert row["next_retry_ts"] > prev
        prev = row["next_retry_ts"]


def test_the_backoff_is_capped():
    assert events.retry_delay(99) == events.RETRY_BACKOFF_CAP_S
    assert events.retry_delay(1) == events.RETRY_BACKOFF_BASE_S
    assert events.retry_delay(2) > events.retry_delay(1)


# ── 3. giving up loudly ───────────────────────────────────────────────────────────

def test_an_undeliverable_event_is_quarantined_not_retried_for_ever(fresh_box, monkeypatch):
    monkeypatch.setenv("EVENT_WEBHOOK_URL", "http://example.invalid/hook")
    ev = _queue(1)[0]
    monkeypatch.setattr(events, "post_payload", lambda p: (False, "400 bad payload"))
    for _ in range(events.MAX_ATTEMPTS):
        events.retry_undelivered()
    row = db.db().execute("SELECT quarantined, attempts FROM events WHERE id=?",
                          (ev,)).fetchone()
    assert row["quarantined"] == 1
    assert row["attempts"] == events.MAX_ATTEMPTS


def test_a_quarantined_event_stops_consuming_the_queue(fresh_box, monkeypatch):
    monkeypatch.setenv("EVENT_WEBHOOK_URL", "http://example.invalid/hook")
    _queue(1, quarantined=1)
    calls = []
    monkeypatch.setattr(events, "post_payload",
                        lambda p: (calls.append(p), (True, "ok"))[1])
    events.retry_undelivered()
    assert calls == []


def test_giving_up_is_counted_where_a_person_will_see_it(fresh_box, monkeypatch):
    """Silently dropping an alert is the failure. Quarantine is only defensible
    because the count is surfaced."""
    monkeypatch.setenv("EVENT_WEBHOOK_URL", "http://example.invalid/hook")
    _queue(2, quarantined=1)
    _queue(3)
    h = events.queue_health()
    assert h["quarantined"] == 2 and h["pending"] == 3
    assert h["oldest_pending_ts"] is not None


def test_queue_health_on_an_empty_box(fresh_box):
    assert events.queue_health() == {
        "pending": 0, "quarantined": 0, "oldest_pending_ts": None,
        # P0-10 added the outbox; an empty box must report it as empty rather than
        # omitting it, or a dashboard reading the key gets None instead of zero.
        "notices_pending": 0, "notices_quarantined": 0}


# ── 4. retention that never destroys evidence ─────────────────────────────────────

def _aged(delivered=1, ack="", quarantined=0, age_days=200):
    with db.tx() as con:
        cam = con.execute(
            "INSERT INTO cameras (name, driver) VALUES ('A','demo')").lastrowid
        zid = con.execute("INSERT INTO zones (camera_id,name,polygon) VALUES (?,?,?)",
                          (cam, "Z", json.dumps(F.ZONE))).lastrowid
        return con.execute(
            "INSERT INTO events (zone_id, ts, to_state, delivered, ack_verdict, "
            "quarantined) VALUES (?,?, 'empty', ?, ?, ?)",
            (zid, time.time() - age_days * 86400, delivered, ack, quarantined)).lastrowid


def test_old_delivered_events_are_pruned(fresh_box):
    _aged()
    assert db.prune_events(90) == 1
    assert db.db().execute("SELECT COUNT(*) c FROM events").fetchone()["c"] == 0


def test_recent_events_are_kept(fresh_box):
    _aged(age_days=5)
    assert db.prune_events(90) == 0


def test_an_acknowledged_event_is_never_pruned(fresh_box):
    """It is a LABEL. P0-7 protects its frame; deleting the answer would be absurd."""
    _aged(ack="true")
    assert db.prune_events(90) == 0
    assert db.db().execute("SELECT COUNT(*) c FROM events").fetchone()["c"] == 1


def test_an_undelivered_event_is_never_pruned(fresh_box):
    """Still owed to CloudBSS. Deleting it is losing an alert quietly."""
    _aged(delivered=0)
    assert db.prune_events(90) == 0


def test_a_quarantined_event_is_never_pruned(fresh_box):
    """It is the only evidence an alert was ever lost."""
    _aged(delivered=0, quarantined=1)
    assert db.prune_events(90) == 0


def test_pruning_is_selective_not_wholesale(fresh_box):
    _aged()
    _aged(ack="false")
    _aged(delivered=0)
    _aged(age_days=1)
    assert db.prune_events(90) == 1
    assert db.db().execute("SELECT COUNT(*) c FROM events").fetchone()["c"] == 3


# ── 5. the loop calls it, and not too often ───────────────────────────────────────

def test_the_scheduler_prunes_on_a_timer_not_every_loop(fresh_box, monkeypatch):
    """A DELETE across a large table, in the capture thread, every fifteen seconds,
    would be its own version of the bug this milestone is fixing."""
    from app import scheduler
    calls = []
    monkeypatch.setattr(scheduler.db, "prune_events", lambda d: calls.append(d) or 0)
    monkeypatch.setattr(scheduler, "_last_prune", 0.0)
    now = time.time()
    scheduler._prune_due(now)
    scheduler._prune_due(now + 1)
    scheduler._prune_due(now + 5)
    assert len(calls) == 1, "pruned more than once inside the interval"
    scheduler._prune_due(now + scheduler.PRUNE_EVERY_S + 1)
    assert len(calls) == 2


def test_the_retention_window_is_what_the_scheduler_passes(fresh_box, monkeypatch):
    from app import scheduler
    calls = []
    monkeypatch.setattr(scheduler.db, "prune_events", lambda d: calls.append(d) or 0)
    monkeypatch.setattr(scheduler, "_last_prune", 0.0)
    scheduler._prune_due(time.time())
    assert calls == [scheduler.EVENT_KEEP_DAYS]


# ── 6. through the API ────────────────────────────────────────────────────────────

USER, PASS = "vision", "a-long-enough-password"


def _auth() -> dict:
    return {"Authorization": "Basic " + base64.b64encode(f"{USER}:{PASS}".encode()).decode()}


@pytest.fixture
def box(clean_env, fresh_box, monkeypatch):
    monkeypatch.setenv("ADMIN_USER", USER)
    monkeypatch.setenv("ADMIN_PASSWORD", PASS)
    monkeypatch.setenv("STORE_ID", "juba-main-01")
    monkeypatch.setenv("ALLOW_NO_WEBHOOK", "1")
    monkeypatch.setenv("SNAP_DIR", str(fresh_box / "snapshots"))
    monkeypatch.delenv("ALLOW_NO_AUTH", raising=False)
    from fastapi.testclient import TestClient
    from app.main import app
    with TestClient(app) as c:
        yield c


def test_health_reports_a_jammed_queue(box):
    _queue(4)
    _queue(2, quarantined=1)
    q = box.get("/api/health", headers=_auth()).json()["queue"]
    assert q["pending"] == 4 and q["quarantined"] == 2


def test_health_is_quiet_on_a_healthy_box(box):
    q = box.get("/api/health", headers=_auth()).json()["queue"]
    assert q["pending"] == 0 and q["quarantined"] == 0


# ── 7. gaps the mutants found ─────────────────────────────────────────────────────

def test_the_default_budget_fits_inside_the_scheduler_loop():
    """The whole bug was a retry pass outlasting the loop that calls it. Testing the
    budget only with an explicit argument left the DEFAULT unchecked, so a mutant
    raising it to 600s survived — reintroducing the original starvation."""
    from app import scheduler
    assert events.RETRY_BUDGET_S < scheduler.LOOP_S, (
        f"a retry pass may run {events.RETRY_BUDGET_S}s inside a {scheduler.LOOP_S}s "
        f"loop — capture would be starved exactly as it was before P0-9")


def test_a_quarantined_row_is_protected_even_if_marked_delivered(fresh_box):
    """Defence in depth, made testable.

    `quarantined = 0` in the prune predicate is redundant TODAY, because quarantine
    implies delivered = 0 and the DELETE already requires delivered = 1. It is kept
    because a future replay tool marking a quarantined event delivered would otherwise
    silently erase the only record that an alert was ever lost. This test constructs
    exactly that row, so the guard cannot be removed as dead code.
    """
    _aged(delivered=1, quarantined=1)
    assert db.prune_events(90) == 0
    assert db.db().execute("SELECT COUNT(*) c FROM events").fetchone()["c"] == 1


def test_the_scheduler_loop_really_calls_the_pruner(fresh_box, monkeypatch):
    """Testing _prune_due directly proved the timer, not that anything invokes it —
    a mutant deleting the call from loop() sailed through.

    Run in a daemon thread with the stop set from OUTSIDE. The first version let the
    fake pruner set the stop flag, which meant that under the very mutant this test
    exists to kill, nothing ever set it and the loop ran for ever. A test that hangs
    when the code is broken is worse than no test: it reports a timeout, not a failure.
    """
    import threading
    from app import scheduler
    stop = threading.Event()
    called = []
    monkeypatch.setattr(scheduler, "_prune_due", lambda now: called.append(now))
    monkeypatch.setattr(scheduler, "_drain", lambda started: {})

    t = threading.Thread(target=scheduler.loop, args=(stop,), daemon=True)
    t.start()
    time.sleep(0.3)
    stop.set()
    t.join(timeout=5)
    assert not t.is_alive(), "the scheduler loop did not stop"
    assert called, "the scheduler loop never prunes"


# ── 8. P0-10: no HTTP in the capture thread, at all ───────────────────────────────
#
# Three times now the same bug has appeared in a different place: dead cameras (P0-3),
# the retry queue (P0-9), and first-attempt delivery (here). The fix this time is
# structural rather than another budget — the capture path does not touch the network,
# so there is nothing left to starve it.

def test_send_enqueues_and_does_not_touch_the_network(fresh_box, monkeypatch):
    monkeypatch.setenv("EVENT_WEBHOOK_URL", "http://example.invalid/hook")
    posted = []
    monkeypatch.setattr(events, "post_payload",
                        lambda p: (posted.append(p), (True, "ok"))[1])
    assert events.send({"event": "camera.offline", "camera": "Aisle 3"}) is True
    assert posted == [], "send() posted from the capture thread"
    assert db.db().execute("SELECT COUNT(*) c FROM outbox").fetchone()["c"] == 1


def test_send_without_a_webhook_queues_nothing(fresh_box, monkeypatch):
    monkeypatch.delenv("EVENT_WEBHOOK_URL", raising=False)
    assert events.send({"event": "camera.offline"}) is False
    assert db.db().execute("SELECT COUNT(*) c FROM outbox").fetchone()["c"] == 0


def test_evaluating_a_zone_never_posts(fresh_box, monkeypatch):
    """The bug: five zones changing state with a slow uplink was fifty seconds of
    blocked capture inside a fifteen-second loop."""
    import cv2
    from app import detector, quality, scheduler
    monkeypatch.setenv("EVENT_WEBHOOK_URL", "http://example.invalid/hook")
    posted = []
    monkeypatch.setattr(events, "post_payload",
                        lambda p: (posted.append(p), (True, "ok"))[1])

    full = F.as_jpeg(F.aisle(facings=F.FULL))
    with db.tx() as con:
        cam = con.execute(
            "INSERT INTO cameras (name, driver, interval_s) VALUES ('A','demo',600)").lastrowid
        con.execute("INSERT INTO zones (camera_id,name,polygon,ref_descriptor,state) "
                    "VALUES (?,?,?,?,'stocked')",
                    (cam, "Z", json.dumps(F.ZONE),
                     detector.describe(full, F.ZONE).to_json()))
        con.execute("UPDATE cameras SET baseline_png=? WHERE id=?",
                    (quality.baseline_signature(full), cam))
    row = db.db().execute("SELECT * FROM cameras WHERE id=?", (cam,)).fetchone()
    empty = cv2.imencode(".jpg", F.as_jpeg(F.aisle(facings=(6, 0, 6))))[1].tobytes()
    for _ in range(4):
        scheduler.evaluate_zones(row, None, empty)

    assert posted == [], "the capture path crossed the wire"
    assert db.db().execute(
        "SELECT COUNT(*) c FROM events WHERE delivered=0").fetchone()["c"] >= 1


def test_the_drain_sends_what_capture_queued(fresh_box, monkeypatch):
    from app import scheduler
    monkeypatch.setenv("EVENT_WEBHOOK_URL", "http://example.invalid/hook")
    posted = []
    monkeypatch.setattr(events, "post_payload",
                        lambda p: (posted.append(p), (True, "ok"))[1])
    _queue(2)
    events.send({"event": "camera.offline", "camera": "A"})
    out = scheduler._drain(time.time())
    assert out["events"]["delivered"] == 2
    assert out["outbox"]["delivered"] == 1
    assert len(posted) == 3


def test_the_two_queues_share_one_budget(fresh_box, monkeypatch):
    """Separate budgets would let a stalled outbox spend the event queue's time — the
    exact coupling this milestone removes."""
    from app import scheduler
    monkeypatch.setenv("EVENT_WEBHOOK_URL", "http://example.invalid/hook")
    monkeypatch.setattr(events, "RETRY_BUDGET_S", 0.0)
    _queue(3)
    events.send({"event": "camera.offline", "camera": "A"})
    out = scheduler._drain(time.time() - 5)
    assert out["outbox"]["stopped"] == "no budget left"
    assert out["outbox"]["attempted"] == 0


def test_a_dead_uplink_costs_the_outbox_one_attempt_too(fresh_box, monkeypatch):
    monkeypatch.setenv("EVENT_WEBHOOK_URL", "http://example.invalid/hook")
    for _ in range(5):
        events.send({"event": "camera.offline", "camera": "A"})
    calls = []
    monkeypatch.setattr(events, "post_payload",
                        lambda p: (calls.append(p), (False, "refused"))[1])
    out = events.drain_outbox()
    assert len(calls) == 1 and "uplink" in out["stopped"]


def test_an_unreadable_outbox_row_is_quarantined_not_retried(fresh_box, monkeypatch):
    monkeypatch.setenv("EVENT_WEBHOOK_URL", "http://example.invalid/hook")
    with db.tx() as con:
        con.execute("INSERT INTO outbox (ts, payload) VALUES (?,?)",
                    (time.time(), "{not json"))
    monkeypatch.setattr(events, "post_payload", lambda p: (True, "ok"))
    events.drain_outbox()
    assert db.db().execute(
        "SELECT quarantined FROM outbox").fetchone()["quarantined"] == 1


def test_an_undeliverable_notice_is_eventually_given_up_on(fresh_box, monkeypatch):
    monkeypatch.setenv("EVENT_WEBHOOK_URL", "http://example.invalid/hook")
    events.send({"event": "camera.offline", "camera": "A"})
    monkeypatch.setattr(events, "post_payload", lambda p: (False, "400"))
    for _ in range(events.MAX_ATTEMPTS):
        events.drain_outbox()
    row = db.db().execute("SELECT quarantined, attempts FROM outbox").fetchone()
    assert row["quarantined"] == 1 and row["attempts"] == events.MAX_ATTEMPTS


def test_health_reports_stuck_notices_separately_from_stuck_alerts(fresh_box, monkeypatch):
    monkeypatch.setenv("EVENT_WEBHOOK_URL", "http://example.invalid/hook")
    _queue(2)
    events.send({"event": "camera.offline", "camera": "A"})
    with db.tx() as con:
        con.execute("INSERT INTO outbox (ts, payload, quarantined) VALUES (?,?,1)",
                    (time.time(), "{}"))
    h = events.queue_health()
    assert h["pending"] == 2
    assert h["notices_pending"] == 1 and h["notices_quarantined"] == 1


def test_delivered_notices_age_out_but_pending_ones_never_do(fresh_box):
    old = time.time() - 200 * 86400
    with db.tx() as con:
        con.execute("INSERT INTO outbox (ts, payload, delivered) VALUES (?,?,1)", (old, "{}"))
        con.execute("INSERT INTO outbox (ts, payload, delivered) VALUES (?,?,0)", (old, "{}"))
        con.execute("INSERT INTO outbox (ts, payload, delivered, quarantined) "
                    "VALUES (?,?,1,1)", (old, "{}"))
    assert db.prune_outbox(90) == 1
    assert db.db().execute("SELECT COUNT(*) c FROM outbox").fetchone()["c"] == 2


# ── 9. gaps the P0-10 mutants found ───────────────────────────────────────────────

def test_the_outbox_drain_stops_at_its_own_time_budget(fresh_box, monkeypatch):
    """My shared-budget test set RETRY_BUDGET_S to zero, so drain_outbox was never
    entered and its own budget check went untested."""
    monkeypatch.setenv("EVENT_WEBHOOK_URL", "http://example.invalid/hook")
    for _ in range(10):
        events.send({"event": "camera.offline", "camera": "A"})
    calls = []

    def slow(p):
        calls.append(p)
        time.sleep(0.05)
        return (True, "ok")

    monkeypatch.setattr(events, "post_payload", slow)
    out = events.drain_outbox(budget_s=0.12)
    assert out["stopped"] == "time budget spent"
    assert 1 <= len(calls) < 10


def test_a_quarantined_notice_is_skipped(fresh_box, monkeypatch):
    monkeypatch.setenv("EVENT_WEBHOOK_URL", "http://example.invalid/hook")
    with db.tx() as con:
        con.execute("INSERT INTO outbox (ts, payload, quarantined) VALUES (?,?,1)",
                    (time.time() - 10, json.dumps({"event": "dead"})))
    events.send({"event": "camera.offline", "camera": "A"})
    posted = []
    monkeypatch.setattr(events, "post_payload",
                        lambda p: (posted.append(p), (True, "ok"))[1])
    events.drain_outbox()
    assert [p["event"] for p in posted] == ["camera.offline"]


def test_an_escalation_never_posts_from_the_capture_thread(fresh_box, monkeypatch):
    """The escalation path builds and records its own payload, and had its own inline
    deliver() call — which nothing checked."""
    import cv2
    from app import detector, quality, scheduler
    monkeypatch.setenv("EVENT_WEBHOOK_URL", "http://example.invalid/hook")
    posted = []
    monkeypatch.setattr(events, "post_payload",
                        lambda p: (posted.append(p), (True, "ok"))[1])

    full = F.as_jpeg(F.aisle(facings=F.FULL))
    with db.tx() as con:
        cam = con.execute(
            "INSERT INTO cameras (name, driver, interval_s) VALUES ('A','demo',600)").lastrowid
        con.execute("INSERT INTO zones (camera_id,name,polygon,ref_descriptor,state,"
                    "state_since) VALUES (?,?,?,?,'empty',?)",
                    (cam, "Z", json.dumps(F.ZONE),
                     detector.describe(full, F.ZONE).to_json(),
                     time.time() - detector.ESCALATE_AFTER_S - 60))
        con.execute("UPDATE cameras SET baseline_png=? WHERE id=?",
                    (quality.baseline_signature(full), cam))
    row = db.db().execute("SELECT * FROM cameras WHERE id=?", (cam,)).fetchone()
    empty = cv2.imencode(".jpg", F.as_jpeg(F.aisle(facings=(6, 0, 6))))[1].tobytes()
    scheduler.evaluate_zones(row, None, empty)

    assert posted == [], "the escalation path crossed the wire from capture"
    assert db.db().execute(
        "SELECT COUNT(*) c FROM events WHERE delivered=0").fetchone()["c"] >= 1


def test_the_outbox_only_gets_the_budget_the_events_left_over(fresh_box, monkeypatch):
    """`left = RETRY_BUDGET_S` instead of `RETRY_BUDGET_S - spent` reads almost the
    same and doubles the worst-case block — which is the entire bug."""
    from app import scheduler
    monkeypatch.setenv("EVENT_WEBHOOK_URL", "http://example.invalid/hook")
    monkeypatch.setattr(events, "RETRY_BUDGET_S", 0.3)
    _queue(3)
    for _ in range(3):
        events.send({"event": "camera.offline", "camera": "A"})

    monkeypatch.setattr(events, "post_payload",
                        lambda p: (time.sleep(0.1), (True, "ok"))[1])
    out = scheduler._drain(time.time())
    assert out["events"]["delivered"] == 3
    assert out["outbox"]["attempted"] == 0, (
        "the events used the whole budget, so the outbox should have had none — "
        f"it attempted {out['outbox']['attempted']}")


def test_the_scheduler_loop_really_drains(fresh_box, monkeypatch):
    """Companion to the pruner test, and for the same reason: the drain is the ONLY
    place this process talks to the network, so a loop that stopped calling it would
    leave every alert queued for ever while the dashboard looked healthy."""
    import threading
    from app import scheduler
    stop = threading.Event()
    called = []
    monkeypatch.setattr(scheduler, "_drain", lambda started: called.append(started) or {})
    monkeypatch.setattr(scheduler, "_prune_due", lambda now: None)

    t = threading.Thread(target=scheduler.loop, args=(stop,), daemon=True)
    t.start()
    time.sleep(0.3)
    stop.set()
    t.join(timeout=5)
    assert not t.is_alive(), "the scheduler loop did not stop"
    assert called, "the scheduler loop never drains the queues"
