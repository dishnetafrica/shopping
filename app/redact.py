"""Keep camera credentials out of everything the box can emit.

A gateway holds the NVR's admin password — the key to every camera in the shop, and on
most sites the same password the owner uses elsewhere. It leaked four ways: the probe
response handed back `rtsp://admin:PASSWORD@nvr/...`, the camera list returned the
stored RTSP URL and username verbatim, ffmpeg echoed the URL into an error that was
stored and rendered in the dashboard, and the same string went into the log that
`visionctl logs` prints.

Rather than trust four call sites to remember, everything that leaves the box goes
through scrub() and one test greps every response body, the whole database file and the
captured log stream for a known password.

The drivers are deliberately untouched: they still need real credentials to talk to a
recorder. This is the boundary, not the source.
"""
from __future__ import annotations

import re

MASK = "***"

# user:pass@host in any URL scheme. The password is anything up to the last '@' before
# the host, so passwords containing ':' or '/' are still caught.
_URL_CREDS = re.compile(r"(?P<scheme>[a-zA-Z][a-zA-Z0-9+.-]*://)(?P<user>[^/@\s]*?):(?P<pw>[^@\s]*)@")


def scrub(value):
    """Mask credentials in a string. Non-strings pass through untouched.

    >>> scrub("rtsp://admin:hunter2@10.0.0.5:554/Streaming/Channels/101")
    'rtsp://***:***@10.0.0.5:554/Streaming/Channels/101'
    """
    if not isinstance(value, str) or not value:
        return value
    return _URL_CREDS.sub(lambda m: f"{m.group('scheme')}{MASK}:{MASK}@", value)


def scrub_secret(value, secret: str | None):
    """Mask a specific secret wherever it appears, even outside a URL.

    ffmpeg and some device firmwares print the password on its own — in a query string,
    a WWW-Authenticate echo, or plain prose ("authentication failed for user admin
    password hunter2"). scrub() cannot see those, so when the caller knows which secret
    is in play it is masked directly as well.
    """
    if not isinstance(value, str) or not value:
        return value
    out = scrub(value)
    if secret and len(secret) >= 3:
        out = out.replace(secret, MASK)
        # Truncation can cut a password in half. rtsp.py caps ffmpeg's stderr at 200
        # characters, and whether the credential falls inside that window depends on the
        # length of a pointer address ffmpeg printed two lines earlier — so the leak is
        # intermittent, and sometimes what survives is a PREFIX of the password with no
        # closing '@' for the URL rule to find. A partial password is still a large gift
        # to anyone guessing, so the longest surviving prefix is masked too.
        for n in range(len(secret) - 1, 3, -1):
            prefix = secret[:n]
            if prefix in out:
                out = out.replace(prefix, MASK)
                break
    return out


def scrub_row(d: dict, secret: str | None = None) -> dict:
    """Scrub every string field of an outgoing row in place-safe fashion."""
    return {k: scrub_secret(v, secret) if isinstance(v, str) else v for k, v in d.items()}
