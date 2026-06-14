# CloudBSS bot — LATEST (fixes the live "everything becomes a product search" disaster)

Your live bot was running OLD code. This is the complete current bot — deploy ALL of
app/Services/Bot/*.php (overwrite), then `php artisan optimize:clear`. No migration.

## What this fixes (seen in the real WhatsApp log)
- "Check out" -> CHECKOUT (was: searched "out").
- A bare search NEVER auto-adds (P1) -> "Give me soft copy receit" won't silently add a wrong item.
- Questions/sentences that merely contain a catalogue word are NO LONGER product searches:
    "How do I identify ur delivery guy"  -> order-status answer (rider name+number on dispatch)
    "How fast will I receive my goods"   -> order-status answer
    "Hope no scums with you" / "Your not serious" -> polite reply, no catalogue dump
  Root cause fixed in IntentClassifier: a catalogue-word only counts as a strong search
  signal when the message reads like a product request (not a question/sentence). Genuine
  requests still search via qty+unit and shop-verbs ("do you have rice", "i want rice", "2kg sugar").
- New "status" business reply for "where is my order / who is delivering / when will it arrive".

## Note on the OpenAI NLU
In production the LLM NLU (BotNlu) appears OFF, so the deterministic keyword classifier is what
runs — which is exactly what these fixes harden. (If you later set OPENAI_API_KEY, NLU runs first
and this stays the safety net.)

## Tests — 550 assertions, 0 failures
incl. a new qa/chatnoise.php that locks in the exact phrases from your live log.
Run any suite standalone, e.g.  php qa/chatnoise.php

## Still genuinely hard (honest)
"Give me a soft-copy receipt" is a SERVICE request (a digital receipt), not a product — the bot
treats "give me X" as an add and will try/!find it rather than email a receipt. Real receipts are
a separate feature. Flagging it rather than pretending it's handled.
