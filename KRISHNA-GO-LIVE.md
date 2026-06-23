# Krishna Wellness — production go-live (AI bot)

## What's now automatic (no pasting)
For a manufacturer tenant, if the AI persona / brand knowledge fields are **blank**, the bot uses the
built-in Krishna/manufacturer defaults — identity, tone, security rules, the 3 brands, certifications,
products, services, delivery, distributor programme and product education. The FAQ and live catalogue
are injected automatically too. So Krishna answers greetings, general questions, product questions,
totals and quotations **out of the box**. You only paste into those fields if you want to *override*
the defaults.

## Never leaves a customer hanging (the "no embarrassment" part)
- **AI down / no key / empty answer** → the bot sends a polite holding message ("Thanks for your
  message 🙏 Our team will get right back to you") AND alerts staff (once per 10 min per customer) to
  take over. No silence, ever.
- **Unreadable input** (sticker / contact card / empty) → a friendly "please type your question".
- **Loop guard** → it won't reply to an exact echo of its own last message.
- **Voice / image** → transcribed / seen; if that fails, the holding message kicks in.
- All of this in addition to the deterministic alerts that fire on buying/payment/complaint signals
  *before* the AI, so a lead is never lost.

## Go-live steps (do these once)
1. **Deploy the full bot stack** (in order): native-ai-bot → ai-bot-media-total → ai-quotation-pdf →
   this ZIP. Or just pull the repo once after pushing them all.
2. `composer require dompdf/dompdf` (for PDF quotations) and `php artisan storage:link`.
3. `php artisan optimize:clear`.
4. Set **`OPENAI_API_KEY`** in the app environment (powers chat + voice + vision). Confirm it works.
5. **Queue worker must be running** — `ProcessIncomingMessage` is queued. On EasyPanel run a worker
   (`php artisan queue:work --tries=2 --timeout=120`) or Horizon. Without it, NO messages are handled.
6. **Scheduler** (for watchdog + daily digest) — cron `* * * * * php artisan schedule:run`.
7. In admin → Krishna Wellness → **Smart bot (n8n)**: Bot brain = **AI smart bot — built-in**; fill
   **Alert routing** (sales / accounts / dispatch / quality / management numbers). Leave persona /
   brand knowledge blank to use the defaults (or paste to customise). Save.
8. Make sure Krishna's **products are in the catalogue** (prices) — that's the source of truth.

## Smoke test before announcing
- "hi" → warm Krishna greeting.
- "what do you sell?" / "difference between EuroPearl and Orchid?" → answered from knowledge.
- "is it antibacterial?" / "what GSM is your copier paper?" → answered.
- "do you deliver to Mbarara?" → yes, from knowledge.
- "price per carton of 300-sheet?" → from the catalogue (never invented).
- "what's my total for 5 cartons 300-sheet and 2 napkins?" → exact PHP total.
- "send me a quotation for that" → branded PDF on WhatsApp.
- voice note + product photo → handled.
- "I want to be a distributor" / "I paid via MoMo" → sales / accounts alerted.

## Capacity
100+ messages/day is comfortable: each message is a queued job calling OpenAI once (plus one extra
small call only when a total/quote is requested). Cost is per-message OpenAI usage on gpt-4o-mini.
Scale the queue worker count if volume grows.

## Files in this ZIP
- `app/Support/BrandDefaults.php` — persona() + knowledge() defaults.
- `app/Services/Bot/AiBrain.php` — defaults when blank, never-hang fallback, empty + echo guards.
- `app/Filament/Admin/Resources/TenantResource.php` — fallback-message field.
- `qa/ai_production.php` — 12/12.

## Honest caveats
- I can't run OpenAI or WhatsApp from here, so the smoke test above is the real proof — run it before
  you announce.
- Verify the baked facts (phone, certs, delivery areas) are correct for Krishna; edit Brand knowledge
  if anything's off — the bot repeats them verbatim.
