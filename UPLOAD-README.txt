BOT VOICE ORDERS — transcribe voice notes + romanise, then run the normal pipeline

WHEN A CUSTOMER SENDS A VOICE NOTE:
  1. Webhook captures the audioMessage (was ignored before).
  2. Job fetches the audio and transcribes it with OpenAI/Whisper (auto-detects Gujarati/Hindi/English).
  3. NEW: if the transcript comes back in Gujarati/Devanagari script, a quick LLM pass romanises
     it to the Gujlish your dictionaries expect (થાળી -> thali, સેવ -> sev, ગાંઠિયા -> gathiya, ૨ -> 2),
     keeping product names + quantities. English/romanised transcripts skip this step.
  4. The (romanised) transcript is fed through the SAME flow as a typed message and ordered normally.
  5. If transcription/romanisation is off / no key / fails / silence: the bot acknowledges the note
     and alerts the owner, so the customer is never silently ignored.

  (Image/photo orders + text conversation were already handled — this makes the bot handle all
   three input types end to end, including Gujarati voice.)

FILES (exact repo paths):
  NEW      app/Services/Bot/VoiceTranscriber.php
  REPLACE  app/Services/WhatsApp/EvolutionGateway.php
  REPLACE  app/Jobs/ProcessIncomingMessage.php

REQUIREMENTS / TOGGLES
  * OPENAI_API_KEY (same key your image search uses — already set).
  * Optional env: OPENAI_TRANSCRIBE_MODEL (default whisper-1), OPENAI_TEXT_MODEL (default gpt-4o-mini).
  * Per-shop: tenant setting feature_voice_orders (default ON).

UPLOAD ON GITHUB
  Add file -> Upload files -> drag the "app" folder -> Commit -> EasyPanel Deploy.
  No migration / routes / config files to edit.

TEST
  Send a Gujarati voice note with an order. In BotTrace you'll see voice_received ->
  voice_transcribed (now showing romanised text) -> normal order flow.
