# CloudBSS v4.18 — The bot shares your number (the Suk Libya fix)

**Date:** 2026-08-03 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.18.zip (cumulative — supersedes v4.17; 122 files) · **Migration:** same two guarded ones (skip if already run). Changed code: one file — `app/Services/Bot/AiBrain.php`.

## What happened

Suk Libya Market asked for "the contact for the salesperson" and the bot answered *"I'm sorry, but I can't share direct contacts."* Root cause: the bot's own privacy guardrail ("staff personal numbers are never shared") — the AI filed *salesperson's contact* under that rule, and the existing share-the-number rule only triggered on the word "call".

## What changed (three layers so it can't recur)

- **New explicit rule:** any ask for the salesperson / sales team / manager / owner / "someone I can talk to" / "give me a contact" = a request for the BUSINESS number → share the configured number immediately and warmly, never refuse, never offer only a callback instead of the number. If no number is configured, say the team will reach out from this same WhatsApp line.
- **The privacy guardrail now says it itself, in both places it appears:** staff members' PERSONAL phones stay private — the business Contact/WhatsApp number is not one of them and is always shareable.
- **The number the bot shares is guaranteed dialable:** same guard the catalogue got in v4.7.7 — a half-typed `public phone` ("+2567…") no longer beats the real connected WhatsApp line.

## Which number it gives

**Settings → Public phone** if set (make sure it's the number you want customers dialing — this was already on your checklist), otherwise the connected WhatsApp line.

## Deploy + test

Upload (delta = 2 files) → Rebuild. Then from any customer number, ask the bot: *"can you give me the salesperson contact"* — it should reply with your configured number, warmly, and offer to keep helping on WhatsApp. Also worth re-asking as "give me manager number" and "who can I talk to".

## Verified before ship

php -l · new rule + both guardrail clarifiers grep-confirmed in the prompt · phone fallback guard mirrors the proven v4.7.7 logic · zip md5-verified against the repo (122 files).
