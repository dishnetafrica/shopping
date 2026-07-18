# CHAT-TRANSLATE-DEPLOY.md — Arabic in, English team: solved

## Reading (inbound)
Any inbound message containing Arabic script shows a small **🌐 Translate** under the
bubble. One tap → English appears beneath the original (both stay visible). The
translation is **cached on the message row forever** — one AI call per message EVER
(pinned by test), so cost stays pennies and once anyone on the team translates a
message, everyone sees it translated from then on.

## Replying (outbound)
A **🌐 toggle** in the composer = "reply in Arabic" mode. Type English → Send → the
draft is translated, shown to you in a confirm box (**you always see the exact Arabic
before it goes**) → OK sends the Arabic. Translation fails? It asks whether to send
your English instead — never blocks, never sends anything unseen.

## The plumbing (your patterns)
Uses the SAME OpenAI config your voice transcription and product enrichment already
run on — `OPENAI_API_KEY`, gpt-4o-mini, 8s timeout, never throws. No new keys, no
new packages. The translator prompt is Juba-aware ("often Juba Arabic, mixed with
English; keep prices and product names as-is").

## Files (6)
app/Services/Chat/ChatTranslator.php (NEW) · PanelApiController (translate — cached —
+ translate-out) · routes (2) · ChatCapability (msgs carry id + cached translation) ·
shell.blade.php (🌐 under Arabic bubbles + composer toggle + confirm flow) ·
ChatsWorkspaceTest (+2)

## Deploy
Over the public-files-fix stack (this shell supersedes) → Rebuild →
`php artisan test --filter=ChatsWorkspace` → open that Arabic conversation from your
screenshot → tap 🌐 under "لو في ضريق ممكن اجيب لي الان" → English appears.
Then toggle 🌐 in the composer, type "I will deliver it today", Send — read the
Arabic preview, OK.

Requires OPENAI_API_KEY set (it already is — your voice orders use it).
