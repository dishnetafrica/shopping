# Multilingual support

## What's now multilingual
- **Conversational replies** — the bot detects the customer's language and replies in it. The persona
  and core rules now say "reply in the same language the customer writes in" (English, Swahili,
  Luganda, French, Arabic, and anything else the model knows). This is the big one.
- **Deterministic detection** — the Signal Engine (lead / payment / complaint / distributor / order)
  and the total + quotation triggers now recognise keywords in **English, Swahili, Luganda, French
  and Arabic**, so a "bei gani?" or "je veux un devis" still alerts staff and fires the total/PDF —
  not just English. `qa/multilingual.php` proves 13 cases across the 5 languages.

## What stays English (by design — and why)
- **Staff alerts** (the 🔥/💰/⚠️ messages to your team) are in English. They're staff-facing, so this
  is intentional.
- **The appended total block and the PDF quotation labels** ("Total", "please confirm", column
  headers) are English; the numbers are universal. The *conversation* around them is in the
  customer's language, but those structured labels aren't translated yet.
- **Fallback / "couldn't read that"** messages default to English but are per-tenant settings
  (`bot_fallback_text`, `bot_unreadable_text`) — set them in your preferred language in admin.

## Honest limits
- Deterministic *triggers* cover the 5 regional languages above. For a language outside that list,
  the bot will still **converse** in it (the LLM handles that), but a pre-AI alert/total/quote trigger
  may not fire from a keyword it doesn't have — the AI conversation still works, you just might not get
  the instant staff alert for that specific phrase. Add more keywords anytime.
- Want the total block + PDF fully localized per customer language? That's a follow-up — say the word.

## Files
- `app/Support/BrandDefaults.php` — persona replies in the customer's language.
- `app/Services/Bot/AiBrain.php` — language core rule + multilingual detection/triggers.
- `qa/multilingual.php` — 13/13.

## Deploy
Part of the Krishna bundle — pull → restart → `optimize:clear`. No migration. Test: message the bot in
Swahili ("Habari, bei ya tishu ya 300 sheets?") and in French ("Bonjour, je veux un devis pour 5
cartons") — it should reply in that language and alert sales.
