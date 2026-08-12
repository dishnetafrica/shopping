"""P0-7 — deciding which events deserve a human, and recording what the human said.

Two jobs, and the second is the one that matters most in the long run.

**Worth telling somebody?** Until now every state change was posted to the webhook and
CloudBSS had no way to tell an alert from a log line. A shelf that went empty while the
zone was under P0-4 suspicion is not evidence of anything, and waking a shopkeeper for
it is how the whole system gets muted. So the gateway now says, for every event, whether
it believes a person should be told and — when it does not — exactly why. Anyone asking
"why wasn't I warned?" gets an answer instead of a shrug.

**What the human said back.** An acknowledgement is the only ground truth this system
will ever have. Every threshold in P0-3, P0-4 and P0-5 was calibrated on synthetic
fixtures; the confidence in P0-5 is explicitly an uncalibrated heuristic; P0-8 cannot
build an evaluation framework out of nothing. One person tapping "yes that shelf was
empty" or "no it wasn't" is worth more than any amount of further tuning against
made-up aisles.

WHAT THIS DELIBERATELY DOES NOT DO: decide *when* to send. Quiet hours, staff rosters
and the shop's timezone live in CloudBSS, which knows them. The gateway decides
worthiness; CloudBSS decides timing. Splitting it the other way would mean teaching a
camera box about shift patterns.
"""
from __future__ import annotations

import os
from dataclasses import dataclass

# A state has to be actionable to be an alert. "Restocked" is good news and belongs in
# the record, not on somebody's phone at 6am.
ALERTABLE_STATES = ("empty", "low")

# Below this, the reading is too close to its threshold to act on. P0-5's confidence is
# margin-from-threshold, so 0.15 means "within 15% of the boundary that decided this" —
# a call that would have gone the other way on a slightly different frame.
MIN_CONFIDENCE = float(os.environ.get("A_MIN_CONFIDENCE", "0.15"))

VERDICTS = ("true", "false")


@dataclass(frozen=True)
class Decision:
    alert: bool
    reason: str
    detail: str = ""

    def as_dict(self) -> dict:
        return {"alertable": self.alert, "alert_reason": self.reason,
                "alert_detail": self.detail}


def should_alert(to_state: str, confidence: float | None, reference_suspect: bool,
                 zone_quality_ok: bool = True, escalation: bool = False) -> Decision:
    """Is this worth a person's attention?

    Order matters. The reference check comes first because it is the only one that says
    the reading itself cannot be trusted — a zone whose reference was captured on a
    half-empty shelf produces confident-looking numbers about nothing, and P0-4 exists
    because that failure is invisible. Alerting on it would put a real-sounding
    out-of-stock in front of a shopkeeper who would walk to a full shelf and stop
    believing the next one.
    """
    if reference_suspect:
        return Decision(False, "reference_suspect",
                        "this zone may not be watching its shelf — re-enrol it before "
                        "trusting anything it reports")
    if not zone_quality_ok:
        return Decision(False, "zone_quality",
                        "the last frames of this zone could not be read")
    if to_state not in ALERTABLE_STATES:
        return Decision(False, "not_actionable",
                        f"{to_state!r} is a state change worth recording, not a call-out")
    if confidence is not None and confidence < MIN_CONFIDENCE:
        return Decision(False, "low_confidence",
                        f"confidence {confidence:.2f} is within {MIN_CONFIDENCE:.2f} of "
                        f"the threshold that decided it — too close to act on")
    if escalation:
        return Decision(True, "still_out",
                        "this shelf was reported and nobody has restocked it")
    return Decision(True, "actionable", "")


def normalise_verdict(v: str) -> str:
    """Accept what a person or a chatbot would actually send.

    A WhatsApp reply arrives as "yes", "y", "correct", "true", "1" — refusing anything
    but the canonical token would throw away labels, and labels are the scarcest thing
    this project has.
    """
    s = str(v or "").strip().lower()
    if s in ("true", "t", "yes", "y", "1", "correct", "confirmed", "real"):
        return "true"
    if s in ("false", "f", "no", "n", "0", "wrong", "incorrect", "not empty"):
        return "false"
    return ""
