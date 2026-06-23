# Built-in AI bot (bot_mode = ai) — DishNet-style, no n8n needed

A native Laravel brain that does exactly what the n8n smart bot does, inside CloudBSS. Pick it per
tenant in Admin → tenant → Smart bot → **Bot brain = "AI smart bot — built-in (recommended)"**.

## What it does (same as n8n)
1. **Deterministic Signal Engine runs first** and fires staff alerts (buying / distributor / payment /
   complaint / order) BEFORE the AI — so a lead survives even if the AI call fails. Fire-once per
   signal per customer per hour (same Cache dedupe as the bridge).
2. **The AI answers** from the same big prompt: persona + brand knowledge + FAQ + grounded catalogue,
   with the real conversation as memory. Prices come ONLY from the catalogue — the model is told never
   to invent one. It can use general knowledge to *explain* products (GSM, ply, etc).
3. **CloudBSS sends + logs** the reply like any other bot message.

Greetings and general questions are handled like DishNet, because it's the same LLM + big prompt —
just running in Laravel instead of n8n.

## Editing the prompt
Exactly the fields you already use — no code, no redeploy:
- **AI persona / policies** — who the bot is, tone, security rules.
- **Brand knowledge** — the facts.
- **FAQ** (brand site / seller panel) — pulled in automatically.
Paste Krishna's `krishna-wellness-bot-knowledge.md` into the first two; the FAQ is already used.

## OpenAI key
Uses CloudBSS's existing `OPENAI_API_KEY` (the same one product-enrichment / vision already use), and
`OPENAI_MODEL` (default `gpt-4o-mini`). Optional per-tenant overrides: settings `openai_api_key` and
`ai_model`. So "share the same key" is the default; set a per-tenant key only if you want separate billing.

## n8n vs built-in AI
- **ai** (this): everything in CloudBSS, one less system to host. Recommended.
- **n8n**: same behaviour, brain lives in n8n (use if you want to edit the flow visually).
- **auto**: inbuilt grocery cart bot (Family Shoppers / Pal's) — unchanged.
- **off**: staff only.
Watchdog + daily digest now cover both `ai` and `n8n` tenants.

## Files
- `app/Services/Bot/AiBrain.php` — the native brain (NEW).
- `app/Jobs/ProcessIncomingMessage.php` — `ai` branch.
- `app/Http/Controllers/Panel/PanelApiController.php` — on/off toggle preserves ai/n8n.
- `app/Filament/Admin/Resources/TenantResource.php` — "AI smart bot" option.
- `app/Console/Commands/BotWatchdogCommand.php`, `BotDigestCommand.php` — also serve ai tenants.
- `qa/ai_brain.php` — 17/17 (signals, mode resolution, toggle, routing).

## Deploy
Pull → restart → `php artisan optimize:clear`. **No migration.** Ensure `OPENAI_API_KEY` is set in the
app environment. Then set a tenant to Bot brain = AI, paste the knowledge, and message its WhatsApp:
"hi" → friendly greeting; "difference between EuroPearl and Orchid?" → answered from knowledge;
"price per carton of 300-sheet?" → from the catalogue; "I want to be a distributor" → sales alerted.
No n8n, no SerpApi — only the OpenAI key.
