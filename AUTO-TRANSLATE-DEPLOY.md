# AUTO-TRANSLATE-DEPLOY.md — Arabic → English, automatic, no tapping

## What changes for the team
Nothing to click anymore. Every Arabic message shows its English underneath by itself:

1. **New messages** — translated AT ARRIVAL (same inline pipeline that already runs
   voice transcription), so by the time the notification buzzes, the English is
   already on the row.
2. **Older messages** — opening a chat auto-backfills up to 4 untranslated Arabic
   messages per view (newest first). Work through the inbox normally and history
   heals itself.

Everything stays cached on the message row — **one AI call per message, EVER** (a
second open costs zero, pinned by test). English messages are never sent to the AI.
The 🌐 button remains only as a fallback for anything beyond the backfill window.

## Controls & safety
- Per-tenant switch: `chat_auto_translate` (default **ON**). Turn off any time:
  `Tenant::find(17)->putSetting('chat_auto_translate', false)` via tinker.
- Fail-soft at both hooks — translation can never break the webhook or block a
  thread view; failures just leave the button.
- Cost reality: gpt-4o-mini ≈ $0.0001/message. A thousand Arabic messages ≈ ten cents.

## Files (4)
ChatTranslator (hasArabic + translateAndCache) · ProcessIncomingMessage (at-arrival
hook) · ChatCapability (on-open backfill ×4) · ChatsWorkspaceTest (+2: auto-translate
with cache proof; tenant switch respected)

## Deploy
Upload → Rebuild → `php artisan test --filter=ChatsWorkspace` → open any Arabic chat:
translations appear on their own. New Arabic messages arrive pre-translated.
