# AI bot: voice, images & deterministic totals (DishNet-level)

The built-in AI bot (`bot_mode = ai`) now handles the three things that make it feel like DishNet:
voice notes, images, and order totals — with the total computed safely in code, not by the model.

## 1. Voice notes
If a customer sends a voice note, the AI path transcribes it (existing `VoiceTranscriber`, OpenAI
Whisper) and answers as if they'd typed it. Needs `OPENAI_API_KEY`; honours `feature_voice_orders`.

## 2. Images (vision)
Images are passed straight to the vision model (gpt-4o-mini) — so the bot can look at a product photo
and match it, read a payment receipt and note it for accounts, or see a damaged item as a complaint.
The image caption is used as the text when present. If the configured model isn't a 4o model, the bot
auto-switches to gpt-4o-mini for that image.

## 3. Deterministic order total (no Pal's-style math risk)
When a customer asks for a total ("what's my total", "how much for 5 cartons", "invoice"…):
- the **LLM only extracts** the items + quantities they mentioned (it's good at that),
- **PHP prices them from the catalogue and adds them up** (`OrderCalculator`) — exact arithmetic,
- the bot appends a clear, itemised block:
  ```
  🧮 Order total (from our price list — please confirm):
  • 5 × EuroPearl 300-sheet = UGX 225,000
  • 2 × Angel Soft napkins = UGX 60,000 (min order 3)
  ————
  Total: UGX 285,000
  ```
The model is explicitly told NOT to compute totals itself. Below-MOQ lines are flagged; items not on
the price list are shown as "team will confirm" (never invented).

## Files
- `app/Services/Bot/AiBrain.php` — media handling + total integration + prompt rules.
- `app/Services/Bot/OrderCalculator.php` — deterministic pricing/total (NEW).
- `app/Jobs/ProcessIncomingMessage.php` — AI branch transcribes voice + fetches image bytes.
- `qa/order_calc.php` — 11/11 (math, intent gate, MOQ flag, unmatched). `qa/ai_brain.php` — 17/17.

## Deploy
Pull → restart → `optimize:clear`. No migration. `OPENAI_API_KEY` must be set (vision + voice + chat
all use it). Set a tenant to Bot brain = AI and test:
- send a voice note "five cartons of 300 sheet" → transcribed + answered.
- send a product photo → identified from the catalogue.
- send a payment screenshot → noted, accounts alerted.
- "what's my total for 5 cartons 300-sheet and 2 napkins?" → exact PHP-computed total.

## Honest caveats
- Item *matching* for the total uses the catalogue matcher; if a name is ambiguous it may pick the
  wrong product — that's why the block says "please confirm". The *math* is always exact.
- Vision/voice quality depends on the image/audio and the model; first live messages are the real test.
