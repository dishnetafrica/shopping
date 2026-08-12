#!/usr/bin/env bash
# P0-1 mutants — break delivery on purpose and prove the suite notices.
#
# A green suite means nothing on its own. The bug this work exists for shipped past a
# green suite, a working dashboard and a healthy-looking event log. So: reintroduce the
# original defect and each of its neighbours, and require a failure every time.
#
#   bash tests/mutants.sh
set -u
cd "$(dirname "$0")/.."
SRC="$(pwd)"
survived=0; killed=0

mutate () { # name file find replace
  local name="$1" file="$2" find="$3" repl="$4"
  local tmp; tmp="$(mktemp -d)"
  cp -r "$SRC"/. "$tmp"/ 2>/dev/null
  rm -rf "$tmp/data" "$tmp/.venv" "$tmp/__pycache__" "$tmp/app/__pycache__" "$tmp/tests/__pycache__"
  if ! python3 - "$tmp/$file" "$find" "$repl" <<'PY'
import sys
path, find, repl = sys.argv[1], sys.argv[2], sys.argv[3]
s = open(path, encoding='utf-8').read()
if find not in s:
    sys.exit(2)
open(path, 'w', encoding='utf-8').write(s.replace(find, repl, 1))
PY
  then
    echo "  ⚠ stale mutant (pattern not found): $name"
    survived=$((survived+1)); rm -rf "$tmp"; return
  fi
  if (cd "$tmp" && python3 -m pytest ${MUT_TESTS:-tests/} -q -p no:warnings >/dev/null 2>&1); then
    echo "  ✗ SURVIVED: $name"
    survived=$((survived+1))
  else
    killed=$((killed+1))
  fi
  rm -rf "$tmp"
}

echo
echo "  P0-1 delivery + P0-2 security + P0-3 quality mutants"
echo "  $(printf '─%.0s' {1..58})"

# ── the original bug, put back exactly as it shipped ──────────────────────────────
mutate "the compose environment: block returns (THE bug)" docker-compose.yml \
  '    env_file: .env' \
  '    env_file: .env
    environment:
      - STORE_ID=family-shoppers-01
      - EVENT_WEBHOOK_URL=
      - DEMO=0'

mutate "compose stops loading .env at all" docker-compose.yml \
  '    env_file: .env' \
  '    # env_file removed'

mutate "the shipped .env pre-fills a customer name again" .env \
  'STORE_ID=' \
  'STORE_ID=family-shoppers-01'

# ── the refusal ───────────────────────────────────────────────────────────────────
mutate "startup stops refusing (runs with no webhook)" app/events.py \
  '    fatal: list[str] = []
    warn: list[str] = []' \
  '    return [], []
    fatal: list[str] = []
    warn: list[str] = []'

mutate "main.py logs the problem but starts anyway" app/main.py \
  '        raise RuntimeError(' \
  '        logging.error("(ignored)"); _unused = ('

mutate "a missing store id becomes acceptable" app/events.py \
  '    if not store_id():
        fatal.append(' \
  '    if False:
        fatal.append('

mutate "the old hardcoded store default comes back" app/events.py \
  '    return os.environ.get("STORE_ID", "").strip()' \
  '    return os.environ.get("STORE_ID", "family-shoppers-01").strip()'

mutate "a missing token stops being mentioned" app/events.py \
  '        if not token():
            warn.append(' \
  '        if False:
            warn.append('

# ── the wire ──────────────────────────────────────────────────────────────────────
mutate "the auth token stops being sent" app/events.py \
  '        headers = {"X-Vision-Token": token()} if token() else {}' \
  '        headers = {}'

mutate "the store id stops travelling in the payload" app/events.py \
  '        "store": store_id(),' \
  '        "store": "",'

mutate "events are marked delivered even when the POST fails" app/events.py \
  '        r.raise_for_status()' \
  '        pass'

mutate "delivery silently does nothing" app/events.py \
  '        r = requests.post(webhook_url(), json=payload, timeout=TIMEOUT, headers=headers)' \
  '        r = requests.Response(); r.status_code = 200'

mutate "the store-and-forward retry is dropped" app/events.py \
  '    rows = db.db().execute(' \
  '    return
    rows = db.db().execute('

mutate "hysteresis fires on the very first frame" app/detector.py \
  '    if pending_count >= CONFIRMATIONS or stored_state == "unknown":' \
  '    if True:'

# ── the installer ─────────────────────────────────────────────────────────────────
mutate "install.sh stops verifying the resolved config" install.sh \
  'RESOLVED="$(docker compose config 2>/dev/null || true)"' \
  'RESOLVED=""'

mutate "install.sh warns instead of failing" install.sh \
  '  exit 1
fi
echo "-- Config verified' \
  '  :
fi
echo "-- Config verified'

# ── re-running the installer on a configured box ──────────────────────────────────
mutate "a re-install stops reading the box's existing config" install.sh \
  'PREV_STORE="$(prev STORE_ID)"' \
  'PREV_STORE=""'

mutate "the previous store id is read but not used as the default" install.sh \
  'STORE_ID="${STORE_ID:-$PREV_STORE}"' \
  'STORE_ID="${STORE_ID:-}"'

mutate "the customer name is baked back into the installer" install.sh \
  'STORE_ID="${STORE_ID:-$PREV_STORE}"' \
  'STORE_ID="${STORE_ID:-family-shoppers-01}"'

mutate "an install with no store id proceeds anyway" install.sh \
  'if [ -z "$STORE_ID" ]; then' \
  'if false; then'

# ── the self-test (the only check a box with no camera can run) ───────────────────
mutate "the self-test always claims success" app/events.py \
  '    ok, detail = post_payload(payload)
    log.info("selftest:' \
  '    ok, detail = True, "faked"
    log.info("selftest:'

mutate "the self-test bypasses the real delivery path" app/events.py \
  '    ok, detail = post_payload(payload)
    log.info("selftest:' \
  '    ok, detail = (requests.post(webhook_url(), json=payload, timeout=TIMEOUT).ok, "direct")
    log.info("selftest:'

mutate "a rejected token stops being explained" app/events.py \
  '            if resp.status_code == 401:' \
  '            if False:'

mutate "the self-test event loses its identifiable name" app/events.py \
  '        "event": "shelf.selftest",' \
  '        "event": "shelf.out_of_stock",'

mutate "install.sh stops proving the uplink" install.sh \
  '  RESULT="$(curl -sf -u "$ADMIN_USER:$ADMIN_PASSWORD" -X POST localhost:8080/api/selftest || true)"' \
  '  RESULT=SKIPPED'

# ══ P0-2 · security ═══════════════════════════════════════════════════════════════
mutate "authentication is removed from the application" app/main.py \
  '              dependencies=[Depends(auth.require_auth)],' \
  ''

mutate "the guard waves everyone through" app/auth.py \
  '    if auth_disabled():
        return "anonymous"' \
  '    return "anonymous"
    if auth_disabled():
        return "anonymous"'

mutate "a missing password means no password is required" app/auth.py \
  '        raise HTTPException(status.HTTP_503_SERVICE_UNAVAILABLE, "gateway not configured")' \
  '        return "anonymous"'

mutate "the password is compared with ==" app/auth.py \
  '    pw_ok = secrets.compare_digest(creds.password or "", expected_pw)' \
  '    pw_ok = (creds.password or "") == expected_pw'

mutate "startup stops refusing an unprotected box" app/auth.py \
  '    pw = admin_password()
    if not pw:' \
  '    pw = admin_password()
    if False:'

mutate "DEMO=1 quietly disables authentication" app/auth.py \
  '    if auth_disabled():
        warn.append(' \
  '    if auth_disabled() or os.environ.get("DEMO") == "1":
        warn.append('

mutate "the docs and the API map are published again" app/main.py \
  '              docs_url=None, redoc_url=None, openapi_url=None)' \
  '              )'

# ── credential redaction ──────────────────────────────────────────────────────────
mutate "the probe hands back the credentialed RTSP URI again" app/main.py \
  '    return redact.scrub_row(dict(cap.__dict__), row["password"])' \
  '    return cap.__dict__'

mutate "the camera list stops scrubbing" app/db.py \
  '    return redact.scrub_row(d, secret)' \
  '    return d'

mutate "the camera list returns the username again" app/db.py \
  '    d.pop("username", None)' \
  '    pass'

mutate "ffmpeg errors are stored unscrubbed" app/scheduler.py \
  '        msg = redact.scrub_secret(str(e), cam.password)[:300]' \
  '        msg = str(e)[:300]'

mutate "the URL credential pattern stops matching" app/redact.py \
  "_URL_CREDS = re.compile(r\"(?P<scheme>[a-zA-Z][a-zA-Z0-9+.-]*://)(?P<user>[^/@\\s]*?):(?P<pw>[^@\\s]*)@\")" \
  '_URL_CREDS = re.compile(r"(?!x)x")'

mutate "a password outside a URL stops being masked" app/redact.py \
  '        out = out.replace(secret, MASK)' \
  '        pass'

# ── the box on the shop floor ─────────────────────────────────────────────────────
mutate "install.sh stops generating a password" install.sh \
  '  NEW_ADMIN_PW=1' \
  '  ADMIN_PASSWORD=""; NEW_ADMIN_PW=1'

mutate "a re-install locks the owner out" install.sh \
  'PREV_ADMIN="$(prev ADMIN_PASSWORD)"' \
  'PREV_ADMIN=""'

mutate ".env is left world-readable" install.sh \
  'chmod 600 "$DEST/.env"' \
  'true'

mutate "visionctl calls the API unauthenticated" install.sh \
  '  status)  docker compose ps; echo; curl -sf -u "$AU:$AP" localhost:8080/api/health' \
  '  status)  docker compose ps; echo; curl -sf localhost:8080/api/health'

# ══ P0-3 · the quality gate ═══════════════════════════════════════════════════════
mutate "the gate is removed — bad frames get classified again" app/scheduler.py \
  '    if not frame_q.ok:
        log.info("skipping %s: %s", cam_row["name"], frame_q.detail)
        return' \
  '    if False:
        return'

mutate "the zone obstruction check is skipped" app/scheduler.py \
  '            if not zone_q.ok:
                log.info("skipping zone %s: %s", z["name"], zone_q.detail)
                continue' \
  '            if False:
                continue'

mutate "an undecodable frame goes back to silence" app/scheduler.py \
  '        bad = quality.Verdict(False, "black", "the camera sent something that is not a picture", {})' \
  '        return
        bad = quality.Verdict(False, "black", "x", {})'

mutate "lighting is measured by brightness again (fooled by stock)" app/quality.py \
  '    return float(np.median(cur_gray[m].astype(np.float32) / base_gray[m].astype(np.float32)))' \
  '    return float(np.mean(cur_gray) / max(np.mean(base_gray), 1e-6))'

mutate "the lighting check is dropped" app/quality.py \
  '        if abs(gain - 1.0) > LIGHT_CHANGE:' \
  '        if False:'

mutate "the movement check is dropped" app/quality.py \
  '            if shift > MOVE_PX:' \
  '            if False:'

mutate "the blur check is dropped" app/quality.py \
  '        if ratio < BLUR_RATIO_BELOW:' \
  '        if False:'

mutate "the black-frame backstop is dropped" app/quality.py \
  '    if m["std"] < FLAT_BELOW:' \
  '    if False:'

mutate "the flat threshold rises and refuses an empty gondola" app/quality.py \
  'FLAT_BELOW = float(os.environ.get("Q_FLAT_BELOW", "8"))' \
  'FLAT_BELOW = float(os.environ.get("Q_FLAT_BELOW", "40"))'

mutate "the lighting threshold tightens and refuses an empty gondola" app/quality.py \
  'LIGHT_CHANGE = float(os.environ.get("Q_LIGHT_CHANGE", "0.18"))' \
  'LIGHT_CHANGE = float(os.environ.get("Q_LIGHT_CHANGE", "0.005"))'

mutate "obstruction fires on a plain empty shelf" app/quality.py \
  'NOVEL_ABOVE = float(os.environ.get("Q_NOVEL_ABOVE", "0.05"))' \
  'NOVEL_ABOVE = float(os.environ.get("Q_NOVEL_ABOVE", "0.001"))'

mutate "obstruction stops firing at all" app/quality.py \
  '    if blob > NOVEL_ABOVE:' \
  '    if False:'

mutate "the masked-out border counts as novel colour" app/quality.py \
  '    area = float(inside.sum())' \
  '    area = float(inside.size)'

mutate "obstruction decides on scattered novelty (inverts on a busy shelf)" app/quality.py \
  '    if blob > NOVEL_ABOVE:' \
  '    if scattered > NOVEL_ABOVE:'

mutate "the connected-component opening is dropped (JPEG fringes count)" app/quality.py \
  '    opened = cv2.morphologyEx(mask, cv2.MORPH_OPEN, np.ones((5, 5), np.uint8))' \
  '    opened = mask'

mutate "the largest blob becomes the total novel area" app/quality.py \
  '    blob = float(stats[1:, cv2.CC_STAT_AREA].max()) / area if count > 1 else 0.0' \
  '    blob = float(stats[1:, cv2.CC_STAT_AREA].sum()) / area if count > 1 else 0.0'

mutate "a bad frame resets a confirmation in progress" app/scheduler.py \
  '        log.info("skipping %s: %s", cam_row["name"], frame_q.detail)
        return' \
  '        con2 = db.db()
        con2.execute("UPDATE zones SET pending_count=0 WHERE camera_id=?", (cam_row["id"],))
        con2.commit()
        return'

mutate "image.bad is emitted on every frame, not on change" app/scheduler.py \
  '    if was == now:
        return' \
  '    if False:
        return'

# ── camera health ─────────────────────────────────────────────────────────────────
mutate "a dead camera never reports offline" app/scheduler.py \
  '    if fails >= OFFLINE_AFTER and (row["health_state"] or "unknown") != "offline":' \
  '    if False:'

mutate "offline is announced on every failed attempt" app/scheduler.py \
  '    if fails >= OFFLINE_AFTER and (row["health_state"] or "unknown") != "offline":' \
  '    if fails >= OFFLINE_AFTER:'

mutate "recovery is never announced" app/scheduler.py \
  '    if (row["health_state"] or "unknown") == "offline":' \
  '    if False:'

mutate "the backoff is removed (dead camera hammered every loop)" app/scheduler.py \
  '    delay = min(60 * (2 ** min(fails - 1, 6)), BACKOFF_CAP_S)' \
  '    delay = 0'

mutate "the loop ignores next_attempt_ts" app/scheduler.py \
  '    nxt = row["next_attempt_ts"]
    if nxt:' \
  '    nxt = None
    if nxt:'

mutate "enrolment stops capturing the baseline view" app/main.py \
  '    baseline = quality.baseline_signature(img)' \
  '    baseline = b""'

mutate "the baseline blob is served over the API" app/db.py \
  '    d.pop("baseline_png", None)' \
  '    pass'

# ── P0-4 mutants ──────────────────────────────────────────────────────────────────
# The failure this work exists for is SILENT. Every mutant below restores a version of
# that silence, and each one must be caught, or the alarm is decoration.

mutate "the suspect threshold is raised past every real error" app/detector.py \
  'SUSPECT_ABOVE = float(os.environ.get("D_SUSPECT_ABOVE", "1.25"))' \
  'SUSPECT_ABOVE = float(os.environ.get("D_SUSPECT_ABOVE", "9.99"))'

mutate "the suspect threshold is dropped into the lighting band" app/detector.py \
  'SUSPECT_ABOVE = float(os.environ.get("D_SUSPECT_ABOVE", "1.25"))' \
  'SUSPECT_ABOVE = float(os.environ.get("D_SUSPECT_ABOVE", "1.05"))'

mutate "one bad frame raises the alarm (hysteresis removed)" app/detector.py \
  'SUSPECT_CONFIRMATIONS = int(os.environ.get("D_SUSPECT_CONFIRMATIONS", "3"))' \
  'SUSPECT_CONFIRMATIONS = int(os.environ.get("D_SUSPECT_CONFIRMATIONS", "1"))'

mutate "a normal frame no longer resets the run" app/detector.py \
  '    else:
        count = 0' \
  '    else:
        pass'

mutate "the standing alarm re-fires every capture" app/detector.py \
  '    if should and not flagged:' \
  '    if should:'

mutate "the alarm never clears after re-enrolment" app/detector.py \
  '    if flagged and count == 0:' \
  '    if False:'

mutate "suspect_ratio takes the max, so one bad reference accuses the zone" app/detector.py \
  '    return min(edge_ratio_raw(current, r) for r in references)' \
  '    return max(edge_ratio_raw(current, r) for r in references)'

mutate "multi-reference averages instead of taking the best match" app/detector.py \
  '    return max(fill_score(current, r) for r in references)' \
  '    return sum(fill_score(current, r) for r in references) / len(references)'

mutate "the reference array is ignored (single reference only)" app/detector.py \
  '    if many:' \
  '    if False:'

mutate "a corrupt reference array blinds the zone instead of falling back" app/detector.py \
  '            out = []          # corrupt array: fall back rather than blind the zone' \
  '            raise'

mutate "enrolment accepts a frame it would refuse to score" app/main.py \
  '    if not verdict.ok and not force:' \
  '    if False:'

mutate "enrolment always replaces, losing the other lighting references" app/main.py \
  '    existing = [] if replace else detector.references_from_json(' \
  '    existing = [] if True else detector.references_from_json('

mutate "the reference cap is removed" app/main.py \
  '    refs = refs[-MAX_REFERENCES:]' \
  '    refs = refs'

mutate "re-enrolling no longer clears the suspect flag" app/main.py \
  'ref_suspect=0, ' \
  ''

mutate "a zone may cover the whole gondola" app/main.py \
  '    if frac > ZONE_MAX_FRAME_FRACTION:' \
  '    if False:'

mutate "a sliver zone is accepted" app/main.py \
  '    if frac < ZONE_MIN_FRAME_FRACTION:' \
  '    if False:'

mutate "the scheduler stops checking the reference at all" app/scheduler.py \
  '            _reference_check(z, cur_desc, refs, cam_row["name"])' \
  '            pass'

mutate "the scheduler scores against one reference again" app/scheduler.py \
  '            fill = detector.fill_score_multi(cur_desc, refs)' \
  '            fill = detector.fill_score(cur_desc, refs[0])'

# ── P0-5 mutants ──────────────────────────────────────────────────────────────────
# The audit's complaint was timeless, falsely-certain events. Each mutant restores one
# of those properties.

mutate "confidence goes back to 1 - fill (the audit's 0.987)" app/detector.py \
  '    if state == "empty":
        span = max(empty_below, EDGE_EPS)
        margin = (empty_below - fill) / span' \
  '    if state == "empty":
        margin = 1.0 - fill'

mutate "a marginal call reports full confidence" app/detector.py \
  '    margin = max(0.0, min(1.0, margin))' \
  '    margin = 1.0'

mutate "a suspect reference no longer caps confidence" app/detector.py \
  '    if ref_suspect:
        margin = min(margin, SUSPECT_CONFIDENCE_CAP)' \
  '    if False:
        margin = min(margin, SUSPECT_CONFIDENCE_CAP)'

mutate "LOW claims certainty at the edge of its band" app/detector.py \
  '        margin = 1.0 - abs(fill - centre) / half' \
  '        margin = 1.0'

mutate "the uid stops depending on the store" app/events.py \
  '    raw = f"{store_id()}|{zone_id}|{ts:.3f}|{to_state}"' \
  '    raw = f"{zone_id}|{ts:.3f}|{to_state}"'

mutate "the uid stops depending on the state" app/events.py \
  '    raw = f"{store_id()}|{zone_id}|{ts:.3f}|{to_state}"' \
  '    raw = f"{store_id()}|{zone_id}|{ts:.3f}"'

mutate "the uid becomes random (retries look like new events)" app/events.py \
  '    return hashlib.sha256(raw.encode()).hexdigest()[:32]' \
  '    import uuid; return uuid.uuid4().hex'

mutate "the payload is stamped with now() instead of the event time" app/events.py \
  '    return time.strftime("%Y-%m-%dT%H:%M:%S%z", time.localtime(ts or time.time()))' \
  '    return time.strftime("%Y-%m-%dT%H:%M:%S%z")'

mutate "retry rebuilds instead of re-sending what was stored" app/events.py \
  '        stored = _row_get(r, "payload")' \
  '        stored = None'

mutate "retry re-stamps a rebuilt event with the current time" app/events.py \
  '            "thumbnail": None, "timestamp": _iso(r["ts"]),' \
  '            "thumbnail": None, "timestamp": _iso(None),'

mutate "a pre-P0-5 event invents a confidence" app/events.py \
  '            "confidence": None, "confidence_basis": "unknown (pre-P0-5 event)",' \
  '            "confidence": r["fill"], "confidence_basis": "unknown (pre-P0-5 event)",'

mutate "the duration stops travelling" app/events.py \
  '        "duration_s": None if duration_s is None else round(float(duration_s), 1),' \
  '        "duration_s": None,'

mutate "the thresholds stop travelling (confidence unfalsifiable)" app/events.py \
  '        "empty_below": _row_get(zone_row, "empty_below", 0.35),' \
  '        "empty_below": None,'

mutate "a suspect reference is not flagged in the payload" app/events.py \
  '    suspect = bool(_row_get(zone_row, "ref_suspect", 0))' \
  '    suspect = False'

mutate "the payload is never stored, so retry cannot re-send it" app/scheduler.py \
  '            db.record_payload(ev_id, payload)
            db.record_decision(ev_id, decision)' \
  '            db.record_decision(ev_id, decision)'

mutate "state_since is never recorded" app/scheduler.py \
  '                    "last_fill=?, last_eval_ts=?, state_since=?, escalated_at=NULL "' \
  '                    "last_fill=?, last_eval_ts=?, state_since=state_since, escalated_at=NULL "'

mutate "the duration is measured from now (always zero)" app/scheduler.py \
  '        held_for = now - since if changed else None' \
  '        held_for = 0.0 if changed else None'

mutate "escalation never happens" app/scheduler.py \
  '            _escalate_if_stale(z, cam_row, new_state, fill, since, now, snap_id, img)' \
  '            pass'

mutate "escalation fires on every capture" app/scheduler.py \
  '    if _col(z, "escalated_at"):
        return' \
  '    if False:
        return'

mutate "escalation ignores how long the shelf has been empty" app/scheduler.py \
  '    if age < detector.ESCALATE_AFTER_S:
        return' \
  '    if False:
        return'

mutate "a stocked shelf can be escalated" app/scheduler.py \
  '    if state not in detector.ESCALATE_STATES:
        return' \
  '    if False:
        return'

mutate "restocking no longer re-arms the escalation" app/scheduler.py \
  'escalated_at=NULL ' \
  ''

# ── P0-6 mutants ──────────────────────────────────────────────────────────────────
# Identity failures are silent by construction: the events keep arriving, they just
# cannot be joined to anything. Each mutant restores a version of that silence.

mutate "zones stop getting a uid" app/main.py \
  '    uid = db.new_zone_uid()' \
  '    uid = ""'

mutate "every zone gets the SAME uid" app/db.py \
  '    return uuid.uuid4().hex[:16]' \
  '    return "0000000000000000"'

mutate "pre-P0-6 zones are never backfilled" app/db.py \
  '    _backfill_zone_uids(con)' \
  '    pass'

mutate "the backfill overwrites uids that already exist" app/db.py \
  '        rows = con.execute("SELECT id FROM zones WHERE zone_uid IS NULL' \
  '        rows = con.execute("SELECT id FROM zones WHERE 1 OR zone_uid IS NULL'

mutate "the identity stops travelling in shelf events" app/events.py \
  '        **zone_identity(zone_row),' \
  ''

mutate "a camera-level quality event invents a zone identity" app/scheduler.py \
  '        zone_row=row if table == "zones" else None))' \
  '        zone_row=row))'

mutate "the sku list stops travelling" app/events.py \
  '        "skus": skus,' \
  '        "skus": [],'

mutate "the named sku is taken from the end of the list" app/events.py \
  '        "sku": skus[0] if skus else "",' \
  '        "sku": skus[-1] if skus else "",'

mutate "corrupt sku json blows up instead of degrading" app/events.py \
  '    except ValueError:
        skus = []' \
  '    except ValueError:
        raise'

mutate "the zone patch endpoint is removed" app/main.py \
  '@app.patch("/api/zones/{zone_id}")' \
  '@app.get("/api/zones/{zone_id}/__disabled")'

mutate "patching lets the uid be changed" app/main.py \
  '    for col in ("name", "aisle", "shelf", "face"):' \
  '    for col in ("name", "aisle", "shelf", "face", "zone_uid"):'

mutate "an empty zone name is accepted" app/main.py \
  '            if col == "name" and not v:' \
  '            if False:'

mutate "thresholds may cross over" app/main.py \
  '    if lo is not None and hi is not None and lo >= hi:' \
  '    if False:'

mutate "thresholds may sit outside 0..1" app/main.py \
  '            if not 0.0 <= v <= 1.0:' \
  '            if False:'

mutate "skus are stored unclean (duplicates, blanks, whitespace)" app/main.py \
  '        args.append(json.dumps(_clean_skus(patch.skus)))' \
  '        args.append(json.dumps(patch.skus))'

mutate "the sku cap is removed" app/main.py \
  '    return out[:MAX_SKUS_PER_ZONE]' \
  '    return out'

# ── P0-7 mutants ──────────────────────────────────────────────────────────────────
# An alert loop fails in two silent ways: waking people for nothing until they mute it,
# or quietly not waking them at all. And a label loop fails by losing the frame.

mutate "everything becomes an alert" app/alerting.py \
  '    if reference_suspect:' \
  '    if False:'

mutate "an unreadable zone still alerts" app/alerting.py \
  '    if not zone_quality_ok:' \
  '    if False:'

mutate "a restock wakes somebody up" app/alerting.py \
  '    if to_state not in ALERTABLE_STATES:' \
  '    if False:'

mutate "marginal readings alert" app/alerting.py \
  '    if confidence is not None and confidence < MIN_CONFIDENCE:' \
  '    if False:'

mutate "the confidence floor is raised past everything" app/alerting.py \
  'MIN_CONFIDENCE = float(os.environ.get("A_MIN_CONFIDENCE", "0.15"))' \
  'MIN_CONFIDENCE = float(os.environ.get("A_MIN_CONFIDENCE", "0.99"))'

mutate "a missing confidence blocks the alert" app/alerting.py \
  '    if confidence is not None and confidence < MIN_CONFIDENCE:' \
  '    if confidence is None or confidence < MIN_CONFIDENCE:'

mutate "suspect reference is checked last instead of first" app/alerting.py \
  '    if reference_suspect:
        return Decision(False, "reference_suspect",' \
  '    if reference_suspect and to_state == "never":
        return Decision(False, "reference_suspect",'

mutate "any text counts as a yes" app/alerting.py \
  '    if s in ("true", "t", "yes", "y", "1", "correct", "confirmed", "real"):' \
  '    if s:'

mutate "an unusable verdict silently becomes false" app/alerting.py \
  '    return ""' \
  '    return "false"'

mutate "the decision never reaches the payload" app/events.py \
  '        **(decision.as_dict() if decision is not None' \
  '        **({} if decision is not None'

mutate "the decision is never stored on the row" app/scheduler.py \
  '            db.record_decision(ev_id, decision)' \
  '            pass'

mutate "escalations ignore the alert decision" app/scheduler.py \
  '        zone_quality_ok=(_col(z, "quality_state", "ok") != "bad"),
        escalation=True)' \
  '        zone_quality_ok=True,
        escalation=True)'

mutate "the ack endpoint accepts any verdict" app/main.py \
  '    if not verdict:' \
  '    if False:'

mutate "acknowledging an unknown event silently succeeds" app/main.py \
  '    if not row:
        raise HTTPException(404, "no event with that uid")' \
  '    if not row:
        return {"ok": True}'

mutate "a correction cannot overwrite the first answer" app/main.py \
  '        con.execute("UPDATE events SET ack_verdict=?, ack_at=?, ack_by=?, ack_note=? "' \
  '        con.execute("UPDATE events SET ack_verdict=COALESCE(NULLIF(ack_verdict,\"\"),?), ack_at=?, ack_by=?, ack_note=? "'

mutate "the label export includes unacknowledged events by default" app/main.py \
  '    if only_acked:' \
  '    if False:'

mutate "labels with a missing frame are reported as usable" app/main.py \
  '        d["frame_available"] = bool(d.get("snapshot_path"))' \
  '        d["frame_available"] = True'

mutate "pruning deletes the frame behind a label" app/db.py \
  '     AND length(ack_verdict) > 0) "' \
  '     AND 1=0) "'

mutate "pruning protects every event frame, so nothing is ever pruned" app/db.py \
  '     AND length(ack_verdict) > 0) "' \
  '     AND 1=1) "'

echo
echo "  $killed killed, $survived survived"
echo
[ "$survived" -eq 0 ] || exit 1
