BOT VOICE ORDERS — transcribe voice notes, then run the normal pipeline

WHAT THIS DOES
  When a customer sends a WhatsApp VOICE NOTE:
    1. The webhook now captures the audioMessage (it was being ignored before).
    2. The job fetches the audio and transcribes it with OpenAI (Whisper), auto-detecting
       Gujarati / Hindi / English, biased toward the shop's product words.
    3. The transcript is fed through the SAME flow as a typed message — so a spoken
       "2 thali, sev 250gm, 7 vage" gets understood and ordered like normal text.
    4. If transcription is off / no API key / fails / silence: the bot ACKNOWLEDGES the note
       ("Got your voice note, the shop will reply shortly...") and alerts the owner, so the
       customer is never silently ignored.

  (Image/photo orders and text conversation were already handled — this closes the voice gap,
   so the bot now handles all three input types end to end.)

FILES (exact repo paths):
  NEW      app/Services/Bot/VoiceTranscriber.php
  REPLACE  app/Services/WhatsApp/EvolutionGateway.php
  REPLACE  app/Jobs/ProcessIncomingMessage.php

REQUIREMENTS
  * OPENAI_API_KEY must be set (same key your image/vision search uses — already configured).
  * Optional env: OPENAI_TRANSCRIBE_MODEL (default "whisper-1").
  * Per-shop toggle: tenant setting feature_voice_orders (default ON). Set it false to disable
    transcription for a shop (it then just acknowledges + routes voice notes to a human).

UPLOAD ON GITHUB
  Add file -> Upload files -> drag the "app" folder -> Commit -> EasyPanel Deploy.
  No migration, routes or config files to edit.

TEST IT
  Send a voice note to the shop saying an order out loud. Watch BotTrace: you'll see
  voice_received -> voice_transcribed (with the text) -> then the normal order flow.

KNOWN LIMITATION (v1)
  Whisper returns Gujarati speech in Gujarati SCRIPT. English/Hindi/romanised voice orders
  flow straight through; pure Gujarati-script transcripts may route to a human via the normal
  fallback rather than auto-matching the romanised dictionaries. If that turns out common in
  the logs, the next step is a one-line LLM romanise/normalise pass on the transcript.
