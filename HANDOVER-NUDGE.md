# Human handover: nudge vs takeover

The handover already existed: when you reply by hand, the bot sets `human_until = now +
human_takeover_minutes` (default **60 min**) and stays silent until it passes, then auto-resumes. The
problem you hit: typing **"hi"** to wake a silent bot triggered the *full 60-minute* pause, so the bot
stayed quiet far too long.

## Fix
The handover now tells a **nudge** apart from a **real takeover** (`App\Support\AgentNudge`):
- **Nudge** — a short greeting ("hi", "hello", "ok", "👋", "good morning"): pause only
  `human_nudge_minutes` (**default 3 min**), so the bot resumes for the customer's next question.
- **Takeover** — a substantive reply (you actually answering): full `human_takeover_minutes`
  (**default 60 min**).
- **Escalation** — if you're *already* engaged in the chat (a recent pause is still active), even a
  short message keeps the full window. So once you're handling it, the bot won't jump back in.

Both windows are tenant settings. To make the bot resume **immediately** on the next customer message
for a tenant (e.g. a manufacturer), set `human_nudge_minutes = 0` — a nudge then doesn't pause at all.
A genuine takeover still pauses for the full window.

The BotTrace event records which path fired: `manual_takeover — nudge — bot paused 3m` vs
`… — takeover — bot paused 60m`, so the behaviour is observable.

## Verification
`AgentNudgeTest`: greetings → nudge; substantive replies (incl. "Please let me know details about your
product" and price answers) → takeover; empty → not a nudge. Classifier smoke 12/12 in-sandbox. The
resume itself reuses the existing `human_until` gate (unchanged).

## Note
This addresses the "bot stays silent after I say hi" half. The separate "AI sometimes doesn't reply at
all" is a different issue — bot errors are caught and logged to BotTrace ('error'), and the quotation
pipeline now traces its own failures. If the no-reply recurs, those traces will show whether the bot
errored, was paused, or the message never triggered it.
