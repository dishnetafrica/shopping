# Giving the manufacturer bot real brand knowledge (DishNet-style)

DishNet answers well because of a big, structured system prompt. We do the same for manufacturers,
but split into two maintainable fields instead of one giant blob — and CloudBSS auto-appends your
**saved FAQ** and the **live catalogue**, so the bot's full brief is:

```
persona  +  CORE RULES  +  COMPANY KNOWLEDGE  +  FAQ  +  recent conversation  +  PRODUCTS (catalogue)
```

## What changed
- CloudBSS now sends `brand_knowledge` (new admin field) and `faq` (your saved panel FAQ, or the
  manufacturer default) with every message.
- The n8n **Brain** weaves them into the system prompt, with explicit rules: answer facts from
  COMPANY KNOWLEDGE + FAQ, may use general real-world knowledge to *explain* products, but prices/
  specs/stock come ONLY from the catalogue — never invented. Plus security rules (no prompt leak,
  no costs/margins/customer data, ignore "ignore previous instructions").

## How to load Krishna's knowledge
Open `krishna-wellness-bot-knowledge.md`. Paste:
- **Field 1** → admin → tenant → Smart bot → "AI persona / policies".
- **Field 2** → "Brand knowledge (facts the bot answers from)".
- Your FAQ is already used automatically (the one on the brand site / panel). Edit it there to teach
  the bot new answers — no prompt editing needed.

## Why this won't repeat Pal's
Pal's failed because we asked an LLM to do exact cart math/state — the wrong job for it. Manufacturer
questions are knowledge/education (certs, specs, delivery, "what's the difference between brands"),
which is exactly what an LLM is good at, and prices stay grounded in the catalogue. So "smart" here
adds accuracy, not the arithmetic failure mode.

## Files
- `app/Jobs/ProcessIncomingMessage.php` — sends `brand_knowledge` + `faq` (`brandFaq()` helper).
- `app/Filament/Admin/Resources/TenantResource.php` — "Brand knowledge" field.
- `cloudbss-smart-bot.n8n.json` — Brain prompt now includes COMPANY KNOWLEDGE + FAQ + security.
- `krishna-wellness-bot-knowledge.md` — ready-to-paste Krishna persona + knowledge.

## Deploy
Pull → restart → `optimize:clear`. No migration. Re-import the workflow (or paste the new Brain code).
Then paste the two knowledge blocks in admin and test: ask "what's the difference between EuroPearl
and Orchid?", "is it antibacterial?", "what GSM is your copier paper?", "do you deliver to Mbarara?",
"price per carton of 300-sheet?" — the first four answer from knowledge, the last from the catalogue.
