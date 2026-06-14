# CloudBSS — Future Commerce Input Channels (ROADMAP, not for immediate build)

Status: PARKED. Do not start until the gating conditions below are met.

## Core principle (already satisfied in the codebase)
The Shopping Engine is channel-independent: `ShoppingEngine::handle($text, $products, $cart, $state)`
takes plain input and returns cart actions; it does not know or care about the source. Therefore
each new channel is an **input adapter** that converts the channel into engine input — NOT a change
to the engine. The WhatsApp location-pin handler (`handleLocationPin`) is the first working example
of a non-text adapter; voice/image/PDF follow the same shape.

```
Text       ───────────────────────────┐
Voice note → STT → transcript ─────────┤
Image      → OCR / Vision → text lines ─┼─→ ShoppingParser → CatalogueMatcher → ShoppingEngine → Cart
PDF        → text extract → text lines ─┘
```

Cart editing, clarification, checkout, delivery and all confirmations are downstream of the engine,
so they work unchanged for every channel.

## Gating conditions (ALL must hold before any of this starts)
1. Product matching stable (no more wrong/cross matches reported from live use).
2. Cart editing stable (remove/clear/quantity by number and name).
3. Checkout flow stable (order created, visible in panel, delivery fee/zone correct, owner alert).
4. Intent layer stable (greetings/business/price/shop-start/decline all behaving live).
Plus the open infra item: the silent-bot incident resolved (queue worker + WhatsApp session healthy).

## Phase V1 — Voice Orders
Customer sends a WhatsApp voice note. Flow: voice → speech-to-text → ShoppingEngine → cart.
Languages: English, Luganda, Swahili, Hindi, Gujarati, Juba Arabic.

Adapter work:
- Webhook/parser already drops non-text; extend it to capture `audioMessage` (like the pin work)
  and download the media via Evolution, then enqueue for transcription.
- STT step produces a transcript string -> feed into the existing text path (BotBrain::respond).

Honest dependencies / risks (decide at build time, not now):
- STT quality varies a lot by language. English/Swahili/Hindi are well supported (e.g. Whisper).
  Luganda, Gujarati and especially Juba Arabic are low-resource — accuracy will be lower; plan a
  confirm-back step ("I heard: 2kg sugar, 1 bread — correct?") before adding to cart.
- Latency + cost: transcription is an external API call per note; needs a queue + budget cap.
- Always echo the transcript so the customer can correct mishearings, never silently add.

## Phase V2 — Image Orders
Customer sends an image: handwritten shopping list, supplier order sheet, invoice, WhatsApp
screenshot, or a product shelf photo. Flow: image → OCR / vision extraction → ShoppingEngine → cart.

Adapter work:
- Capture `imageMessage` in the parser, download media, run OCR/vision to produce text lines,
  feed the existing text path (one item per line via ShoppingParser).

Honest dependencies / risks:
- Printed text (invoices, order sheets, screenshots) is reliable with OCR/vision.
- Handwriting is hard and error-prone; shelf photos need product recognition, not OCR. Treat these
  as best-effort with a confirm-back step; never auto-checkout from an image.
- Multi-item lists should surface as a reviewable draft cart ("I read these 6 items — reply OK or
  edit"), reusing the Cart Management Engine.

## PDF Orders (rides with V2 or its own small phase)
Customer sends a PDF order list. Flow: PDF → extract text → ShoppingEngine → cart.
- Capture `documentMessage` (application/pdf) in the parser; extract text (text-layer PDFs are
  trivial; scanned PDFs need OCR like images); feed line-by-line into the engine.

## What stays unchanged across all channels
- ShoppingParser, CatalogueMatcher, ShoppingEngine, CartEditor, FollowUp, IntentClassifier,
  GreetingDictionary, session/expiry, checkout, delivery zones, owner alerts.
- The only new code per channel: media capture in the webhook/parser + a transcription/extraction
  step + a confirm-back wrapper. The engine contract (text in, cart actions out) does not change.

## Sequencing
core commerce stable -> Phase V1 (Voice) -> Phase V2 (Image, incl. PDF).
